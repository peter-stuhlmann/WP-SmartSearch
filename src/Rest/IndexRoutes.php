<?php
/**
 * REST routes for managing the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Rest;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Index\Eligibility;
use PrstSearch\Index\EmbeddingQueue;
use PrstSearch\Index\Indexer;
use PrstSearch\Index\IndexReport;
use PrstSearch\Security\Capabilities;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lets the admin UI rebuild the index step by step and the editor refresh a single post.
 */
final class IndexRoutes implements Hookable {

	/**
	 * Posts indexed per rebuild request. Small enough to stay well below PHP time limits.
	 */
	private const REBUILD_PAGE_SIZE = 10;

	/**
	 * Passages of one post embedded right away when an editor asks for it.
	 */
	private const EDITOR_BATCH = 40;

	/**
	 * Indexed posts checked for removal per rebuild request.
	 */
	private const PRUNE_PAGE_SIZE = 500;

	/**
	 * Creates the routes.
	 */
	public function __construct(
		private readonly Indexer $indexer,
		private readonly EmbeddingQueue $embeddings,
		private readonly IndexReport $report,
		private readonly ChunkStore $chunks,
		private readonly Eligibility $eligibility
	) {}

	/**
	 * How many pages are offered for a recommendation.
	 */
	private const PAGE_CHOICES = 12;

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$can_manage = static fn (): bool => current_user_can( Capabilities::MANAGE );

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/index',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'overview' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/index/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/index/embeddings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'embed' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/index/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/index/posts/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array( 'type' => 'integer' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'post_status' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reindex_post' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'exclude' => array(
							'type'        => 'boolean',
							'description' => 'Set to exclude the post from the search or to include it again. Omit to keep the current choice.',
						),
					),
				),
			)
		);
	}

	/**
	 * Returns indexed pages whose title contains the given text, for choosing a recommended result.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$text  = trim( (string) $request->get_param( 'q' ) );
		$pages = array();

		foreach ( '' === $text ? array() : $this->chunks->suggest( $text, self::PAGE_CHOICES ) as $post_id ) {
			$type = get_post_type_object( (string) get_post_type( $post_id ) );

			$pages[] = array(
				'id'    => $post_id,
				'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'type'  => null === $type ? '' : $type->labels->singular_name,
			);
		}

		return new WP_REST_Response( $pages );
	}

	/**
	 * Tells whether the current user may edit the requested post.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_edit_post( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * Returns the totals of the index.
	 */
	public function overview(): WP_REST_Response {
		return new WP_REST_Response( $this->report->overview() );
	}

	/**
	 * Empties the index.
	 */
	public function clear(): WP_REST_Response {
		$this->indexer->clear();

		return $this->overview();
	}

	/**
	 * Indexes the next page of posts.
	 *
	 * The client calls this repeatedly with the returned offset until "done" is true.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function rebuild( WP_REST_Request $request ): WP_REST_Response {
		$offset = (int) $request->get_param( 'offset' );

		if ( 0 === $offset ) {
			// Drop what no longer belongs to the search before starting over: content types
			// that are not searched any more, and posts that were unpublished or deleted.
			$this->chunks->delete_other_post_types( $this->eligibility->enabled_post_types() );

			$after_id = 0;

			do {
				$after_id = $this->indexer->prune( $after_id, self::PRUNE_PAGE_SIZE );
			} while ( $after_id > 0 );
		}

		$processed = $this->indexer->index_page( self::REBUILD_PAGE_SIZE, $offset );

		return new WP_REST_Response(
			array(
				'offset' => $offset + $processed,
				'total'  => $this->indexer->candidate_count(),
				'done'   => $processed < self::REBUILD_PAGE_SIZE,
			)
		);
	}

	/**
	 * Creates the next batch of missing embeddings.
	 */
	public function embed(): WP_REST_Response {
		return new WP_REST_Response( $this->embeddings->process() );
	}

	/**
	 * Returns the index status of a post.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_status( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'prstsearch_not_found', __( 'This content does not exist.', 'smart-search' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->report->for_post( $post ) );
	}

	/**
	 * Indexes a post right away, including its embeddings, and optionally changes its manual exclusion.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reindex_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$exclude = $request->get_param( 'exclude' );

		if ( is_bool( $exclude ) ) {
			$this->eligibility->set_excluded_manually( $post_id, $exclude );
		}

		$this->indexer->index_post( $post_id );

		// The editor asked for it explicitly, so this post does not wait for its turn in the queue.
		$this->embeddings->process( self::EDITOR_BATCH, $post_id );

		return $this->post_status( $request );
	}
}
