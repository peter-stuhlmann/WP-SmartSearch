<?php
/**
 * Writes posts to the search index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ProviderConfig;
use Throwable;
use WP_Post;
use WP_Query;

/**
 * Keeps the index in sync with the content.
 *
 * The text of a post is stored right away, so keyword search is always
 * current. Embeddings cost a provider call and are created in the background.
 */
final class Indexer {

	/**
	 * Creates the indexer.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly ContentRenderer $renderer,
		private readonly Chunker $chunker,
		private readonly Eligibility $eligibility,
		private readonly IndexStatusStore $status,
		private readonly ProviderConfig $config,
		private readonly Scheduler $scheduler,
		private readonly IndexVersion $version
	) {}

	/**
	 * Indexes a post, or removes it from the index if it is not searchable.
	 */
	public function index_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->eligibility->is_indexable( $post ) ) {
			$this->remove_post( $post_id );
			return;
		}

		try {
			$this->store( $post );
		} catch ( Throwable $error ) {
			// A broken shortcode or builder module must not take the editor down with it.
			$this->status->mark_error( $post_id, $error->getMessage() );
		}
	}

	/**
	 * Removes a post from the index.
	 */
	public function remove_post( int $post_id ): void {
		if ( $this->chunks->delete_post( $post_id ) ) {
			$this->version->bump();
		}

		$this->status->clear( $post_id );
	}

	/**
	 * Removes posts from the index that no longer belong there.
	 *
	 * Saving a post keeps the index in sync while the plugin is active. This
	 * catches what happened in between: posts unpublished, protected or
	 * deleted while the plugin was inactive, and content types that vanished.
	 *
	 * @param int $after_id Continue after this post ID.
	 * @param int $limit    Number of indexed posts to check.
	 * @return int The last post ID checked, or 0 when the end of the index was reached.
	 */
	public function prune( int $after_id, int $limit ): int {
		$post_ids = $this->chunks->post_ids_after( $after_id, $limit );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->eligibility->is_indexable( $post ) ) {
				$this->remove_post( $post_id );
			}
		}

		return count( $post_ids ) < $limit ? 0 : (int) end( $post_ids );
	}

	/**
	 * Empties the index.
	 */
	public function clear(): void {
		$this->chunks->truncate();
		$this->status->clear_all();
		$this->version->bump();
	}

	/**
	 * Indexes one page of all searchable posts. Used to rebuild the index step by step.
	 *
	 * @return int Number of posts processed. Fewer than $limit means the end was reached.
	 */
	public function index_page( int $limit, int $offset ): int {
		$post_ids = $this->query_ids(
			array(
				'posts_per_page' => $limit,
				'offset'         => $offset,
			)
		);

		array_map( array( $this, 'index_post' ), $post_ids );

		return count( $post_ids );
	}

	/**
	 * Walks one page of all searchable posts and indexes those never indexed before.
	 *
	 * Used after activation and whenever settings changed what has to be indexed.
	 *
	 * @return int Number of posts looked at. Fewer than $limit means the end was reached.
	 */
	public function backfill_page( int $limit, int $offset ): int {
		$post_ids = $this->query_ids(
			array(
				'posts_per_page' => $limit,
				'offset'         => $offset,
			)
		);

		foreach ( $post_ids as $post_id ) {
			if ( ! $this->status->has_state( $post_id ) ) {
				$this->index_post( $post_id );
			}
		}

		return count( $post_ids );
	}

	/**
	 * Counts the posts that belong in the index.
	 *
	 * Posts excluded by hand do not count. Otherwise a single excluded post would
	 * make the index look incomplete for good. Posts rejected by a custom rule
	 * cannot be told apart without loading each of them, and are counted.
	 */
	public function searchable_count(): int {
		$query = $this->query(
			array(
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only run for the overview in the admin area.
				'meta_query'     => array(
					array(
						'key'     => Eligibility::EXCLUDE_META,
						'compare' => 'NOT EXISTS',
					),
				),
			),
			true
		);

		return null === $query ? 0 : (int) $query->found_posts;
	}

	/**
	 * Counts the posts index_page() and backfill_page() walk through, including those excluded by hand.
	 */
	public function candidate_count(): int {
		$query = $this->query( array( 'posts_per_page' => 1 ), true );

		return null === $query ? 0 : (int) $query->found_posts;
	}

	/**
	 * Renders, chunks and stores one post.
	 */
	private function store( WP_Post $post ): void {
		$title  = wp_strip_all_tags( get_the_title( $post ) );
		$chunks = $this->chunker->split( $this->renderer->render( $post ) );

		// A page without body text is still found by its title.
		if ( array() === $chunks ) {
			$chunks = array( $title );
		}

		// Unchanged text leaves the version alone, so caches survive saves that did not touch the content.
		if ( $this->chunks->replace_post_chunks( $post->ID, $post->post_type, $title, $chunks ) ) {
			$this->version->bump();
		}

		if ( $this->config->embeddings_enabled() && $this->chunks->has_missing_embeddings( $post->ID, $this->config->embedding_model() ) ) {
			$this->status->mark_pending( $post->ID );
			$this->scheduler->embeddings();
			return;
		}

		$this->status->mark_indexed( $post->ID );
	}

	/**
	 * Returns IDs of searchable posts.
	 *
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return int[]
	 */
	private function query_ids( array $args ): array {
		$query = $this->query( $args, false );

		return null === $query ? array() : array_map( 'intval', $query->posts );
	}

	/**
	 * Queries searchable posts.
	 *
	 * Manually excluded posts are part of the result and are sorted out by
	 * index_post(), which keeps this query free of a meta condition.
	 *
	 * @param array<string, mixed> $args  Additional query arguments.
	 * @param bool                 $count Whether the total is needed.
	 */
	private function query( array $args, bool $count ): ?WP_Query {
		$post_types = $this->eligibility->enabled_post_types();

		if ( array() === $post_types ) {
			return null;
		}

		return new WP_Query(
			$args + array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => ! $count,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				// Polylang would otherwise limit the query to the language of the current request.
				'lang'                   => '',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}
}
