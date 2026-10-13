<?php
/**
 * Public REST routes of the search.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Rest;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Frontend\ResultRenderer;
use PrstSearch\Search\AnswerService;
use PrstSearch\Search\HitFilter;
use PrstSearch\Search\SearchService;
use PrstSearch\Security\Capabilities;
use PrstSearch\Settings\Settings;
use PrstSearch\Stats\SearchLogger;
use PrstSearch\Support\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Search, suggestions, AI answer and click tracking for the front end.
 *
 * These routes are public by design: they expose nothing a visitor could not
 * read on the published site.
 */
final class SearchRoutes implements Hookable {

	public const ROUTE_NAMESPACE = 'prstsearch/v1';


	private const SUGGESTION_LIMIT = 6;

	private const SUGGESTION_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Creates the routes.
	 */
	public function __construct(
		private readonly SearchService $search,
		private readonly AnswerService $answers,
		private readonly ResultRenderer $renderer,
		private readonly ChunkStore $chunks,
		private readonly SearchLogger $logger,
		private readonly Settings $settings,
		private readonly HitFilter $filter,
		private readonly Cache $cache
	) {}

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
		$query_arg = array(
			'type'              => 'string',
			'required'          => true,
			'minLength'         => 1,
			'maxLength'         => SearchService::MAX_QUERY_LENGTH,
			'sanitize_callback' => 'sanitize_text_field',
			// Named explicitly: with a custom sanitize callback WordPress would otherwise skip
			// the checks declared above, and the length limit would not hold.
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => $query_arg,
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
					'preview'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'type'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'refine'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'token'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suggestions' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'q' => $query_arg ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/answer',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => $query_arg,
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/answer/stream',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'answer_stream' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => $query_arg,
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/click',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'click' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * Returns one page of results, as data and as rendered HTML.
	 *
	 * The first page comes at once with what the search finds by itself. If
	 * the language model could add to it, the response says so ("pending"),
	 * and a second request with "refine" returns the page after it was asked.
	 * A visitor sees results immediately and never waits for the model in vain.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$per_page = $request->get_param( 'per_page' );
		$per_page = null === $per_page ? null : (int) $per_page;
		$query    = (string) $request->get_param( 'q' );
		$page     = (int) $request->get_param( 'page' );
		$type     = (string) $request->get_param( 'type' );
		$types    = '' === $type ? array() : array( $type );

		$results = match ( true ) {
			// Test searches from the settings page must not show up in the statistics.
			$request->get_param( 'preview' ) && current_user_can( Capabilities::MANAGE ) => $this->search->preview( $query ),
			(bool) $request->get_param( 'refine' ) => $this->search->refined( $query, (string) $request->get_param( 'token' ), $per_page, $types ),
			// Further pages belong to the list after the model was asked, which by then is what the visitor sees.
			default => $this->search->search( $query, $page, $per_page, $types, 1 === $page ),
		};

		$is_first_page = 1 === $results->page;
		// Not with provisional results: the answer is written from the final list, which the next
		// request returns. Asking for it now would have the language model consulted twice.
		$has_answer = $is_first_page && $results->total > 0 && ! $results->is_provisional && $this->answers->is_enabled();

		return new WP_REST_Response(
			array(
				'query'       => $results->query,
				'total'       => $results->total,
				'page'        => $results->page,
				'pages'       => $results->page_count(),
				'mode'        => $results->ranking->mode->value,
				'degraded'    => $results->ranking->degraded,
				'corrected'   => $results->ranking->corrected_query,
				'token'       => $results->log_token,
				'pending'     => $results->is_provisional,
				'summary'     => $this->renderer->summary( $results ),
				'results'     => $this->renderer->items( $results ),
				'html'        => $is_first_page ? $this->renderer->render_results( $results ) : '',
				'items_html'  => $this->renderer->render_items( $results ),
				'answer_html' => $has_answer ? $this->renderer->render_answer_placeholder( $results->query, $results->log_token ) : '',
			)
		);
	}

	/**
	 * Returns page titles matching what the visitor has typed so far.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function suggestions( WP_REST_Request $request ): WP_REST_Response {
		$suggestions = array();

		$text = SearchService::normalize( (string) $request->get_param( 'q' ) );

		if ( '' !== $text && $this->settings->bool( 'suggestions' ) ) {
			$post_ids = $this->filter->allowed_posts( $this->suggested_posts( $text ) );

			foreach ( array_slice( $post_ids, 0, self::SUGGESTION_LIMIT ) as $post_id ) {
				$suggestions[] = array(
					'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => esc_url_raw( (string) get_permalink( $post_id ) ),
				);
			}
		}

		$response = new WP_REST_Response( $suggestions );

		// Typing "ver", "vers", "ver" again asks the same thing twice. The browser may answer that itself.
		// "private", because what a visitor may see can differ from the next one.
		$response->header( 'Cache-Control', 'private, max-age=' . self::SUGGESTION_CACHE_TTL );

		return $response;
	}

	/**
	 * Returns the posts whose title matches what was typed, before it is decided who may see them.
	 *
	 * Every visitor types the same beginnings of the same words, so the
	 * answers of the database are kept for a while.
	 *
	 * @return int[]
	 */
	private function suggested_posts( string $text ): array {
		$key    = $this->cache->index_key( 'suggest', mb_strtolower( $text ) );
		$cached = $this->cache->get( $key );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		// A few more than needed, because some may not be shown to this visitor.
		$post_ids = $this->chunks->suggest( $text, self::SUGGESTION_LIMIT * 2 );

		// Only what was found: text that matches nothing is cheap to look up again, and
		// remembering it would let anybody fill the cache by sending random letters.
		if ( array() !== $post_ids ) {
			$this->cache->set( $key, $post_ids, self::SUGGESTION_CACHE_TTL );
		}

		return $post_ids;
	}

	/**
	 * Generates the AI answer for a query.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function answer( WP_REST_Request $request ): WP_REST_Response {
		$answer = $this->answers->answer( (string) $request->get_param( 'q' ), (string) $request->get_param( 'token' ) );

		return new WP_REST_Response(
			array( 'html' => null === $answer ? '' : $this->renderer->render_answer( $answer ) )
		);
	}

	/**
	 * Sends the AI answer for a query while it is written, as server-sent events.
	 *
	 * "text" events carry the pieces of the answer as plain text. The closing
	 * "done" event carries the finished answer as the same markup the answer
	 * route returns, with its links and sources, or nothing if there is no answer.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function answer_stream( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->settings->bool( 'stream_answers' ) ) {
			return new WP_REST_Response( null, 404 );
		}

		$stream = new EventStream();
		$stream->open();

		$answer = $this->answers->answer(
			(string) $request->get_param( 'q' ),
			(string) $request->get_param( 'token' ),
			static fn ( string $piece ) => $stream->send( 'text', $piece )
		);

		$stream->send( 'done', array( 'html' => null === $answer ? '' : $this->renderer->render_answer( $answer ) ) );

		// The response is complete. Returning would let WordPress append a JSON body to the stream.
		exit;
	}

	/**
	 * Records a click on a result.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function click( WP_REST_Request $request ): WP_REST_Response {
		$this->logger->log_click( (string) $request->get_param( 'token' ), (int) $request->get_param( 'post_id' ) );

		return new WP_REST_Response( null, 204 );
	}
}
