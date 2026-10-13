<?php
/**
 * Stores the index status of posts.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use WP_Post;
use wpdb;

/**
 * Keeps the index state in post meta, so list tables and the editor can read it cheaply.
 */
final class IndexStatusStore {

	public const STATE_META      = '_prstsearch_state';
	public const INDEXED_AT_META = '_prstsearch_indexed_at';
	public const ERROR_META      = '_prstsearch_error';

	/**
	 * Text and embeddings are up to date.
	 */
	private const STATE_INDEXED = 'indexed';

	/**
	 * Text is searchable, embeddings are still being created.
	 */
	private const STATE_PENDING = 'pending';

	private const STATE_ERROR = 'error';

	/**
	 * Creates the store.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly Eligibility $eligibility
	) {}

	/**
	 * Returns the status shown for a post.
	 */
	public function status( WP_Post $post ): IndexStatus {
		if ( ! $this->eligibility->is_indexable( $post ) ) {
			return IndexStatus::Excluded;
		}

		return match ( get_post_meta( $post->ID, self::STATE_META, true ) ) {
			self::STATE_INDEXED => IndexStatus::Indexed,
			self::STATE_ERROR   => IndexStatus::Error,
			default             => IndexStatus::Stale,
		};
	}

	/**
	 * Tells whether the indexer has processed the post before.
	 */
	public function has_state( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::STATE_META, true );
	}

	/**
	 * Returns the stored error message of a post.
	 */
	public function error_message( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::ERROR_META, true );
	}

	/**
	 * Returns when the post was last written to the index, as a Unix timestamp, or 0.
	 */
	public function indexed_at( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::INDEXED_AT_META, true );
	}

	/**
	 * Marks a post as completely indexed.
	 */
	public function mark_indexed( int $post_id ): void {
		$this->set_state( $post_id, self::STATE_INDEXED );
	}

	/**
	 * Marks a post as searchable by keyword while its embeddings are pending.
	 */
	public function mark_pending( int $post_id ): void {
		$this->set_state( $post_id, self::STATE_PENDING );
	}

	/**
	 * Marks a post as failed.
	 */
	public function mark_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, self::STATE_META, self::STATE_ERROR );
		update_post_meta( $post_id, self::ERROR_META, $message );
	}

	/**
	 * Removes all index state of a post.
	 */
	public function clear( int $post_id ): void {
		delete_post_meta( $post_id, self::STATE_META );
		delete_post_meta( $post_id, self::INDEXED_AT_META );
		delete_post_meta( $post_id, self::ERROR_META );
	}

	/**
	 * Removes the index state of all posts.
	 */
	public function clear_all(): void {
		foreach ( array( self::STATE_META, self::INDEXED_AT_META, self::ERROR_META ) as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}
	}

	/**
	 * Removes the index state of all posts whose type is not among the given ones.
	 *
	 * Without this, posts of a content type that was removed from the search
	 * would still read "indexed" and be skipped when the type is added again.
	 *
	 * @param string[] $post_types Post types to keep.
	 */
	public function clear_other_post_types( array $post_types ): void {
		if ( array() === $post_types ) {
			$this->clear_all();
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$post_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT meta.post_id FROM %i AS meta
				INNER JOIN %i AS posts ON posts.ID = meta.post_id
				WHERE meta.meta_key = %s AND posts.post_type NOT IN ({$placeholders})",
				array_merge( array( $this->wpdb->postmeta, $this->wpdb->posts, self::STATE_META ), array_values( $post_types ) )
			)
		);
		// phpcs:enable

		array_map( array( $this, 'clear' ), array_map( 'intval', $post_ids ) );
	}

	/**
	 * Moves every pending post to "indexed", e.g. after switching to keyword search.
	 */
	public function mark_all_indexed(): void {
		$this->replace_state( self::STATE_PENDING, self::STATE_INDEXED );
	}

	/**
	 * Counts posts with an error.
	 */
	public function error_count(): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s',
				$this->wpdb->postmeta,
				self::STATE_META,
				self::STATE_ERROR
			)
		);
	}

	/**
	 * Returns posts that could not be indexed.
	 *
	 * @return int[] Post IDs.
	 */
	public function error_post_ids( int $limit ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT %d',
				$this->wpdb->postmeta,
				self::STATE_META,
				self::STATE_ERROR,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns published posts of the given types that were never indexed.
	 *
	 * @param string[] $post_types Post types to look at.
	 * @param int      $after_id   Return posts with a higher ID than this.
	 * @return int[] Post IDs in ascending order.
	 */
	public function posts_without_state( array $post_types, int $after_id, int $limit ): array {
		if ( array() === $post_types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT posts.ID FROM %i AS posts
				LEFT JOIN %i AS state ON state.post_id = posts.ID AND state.meta_key = %s
				WHERE posts.ID > %d AND posts.post_status = 'publish' AND posts.post_password = ''
					AND posts.post_type IN ({$placeholders}) AND state.meta_id IS NULL
				ORDER BY posts.ID ASC LIMIT %d",
				array_merge( array( $this->wpdb->posts, $this->wpdb->postmeta, self::STATE_META, $after_id ), array_values( $post_types ), array( $limit ) )
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns posts an editor excluded from the search by hand.
	 *
	 * @return int[] Post IDs.
	 */
	public function manually_excluded_post_ids( int $limit ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT %d',
				$this->wpdb->postmeta,
				Eligibility::EXCLUDE_META,
				Eligibility::EXCLUDED,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Writes the state and the time of indexing.
	 */
	private function set_state( int $post_id, string $state ): void {
		update_post_meta( $post_id, self::STATE_META, $state );
		update_post_meta( $post_id, self::INDEXED_AT_META, time() );
		delete_post_meta( $post_id, self::ERROR_META );
	}

	/**
	 * Replaces one state by another for all posts.
	 */
	private function replace_state( string $from, string $to ): void {
		$post_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s',
				$this->wpdb->postmeta,
				self::STATE_META,
				$from
			)
		);

		$this->wpdb->update(
			$this->wpdb->postmeta,
			array( 'meta_value' => $to ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bulk state change.
			array(
				'meta_key'   => self::STATE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bulk state change.
				'meta_value' => $from, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bulk state change.
			)
		);

		// The meta cache still holds the old values of exactly these posts.
		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
	}
}
