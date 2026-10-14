<?php
/**
 * Counters in the object cache or the database.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

use wpdb;

/**
 * Counts atomically, wherever WordPress keeps its data.
 *
 * With a persistent object cache such as Redis the cache counts, and nothing
 * reaches the database. Without one every counter is a row of the options
 * table, raised by a single statement: reading a value and writing it back
 * in two steps would let requests that arrive together all read the same
 * value, and a script sending many searches at once would never be stopped.
 *
 * A counter's row carries the time it expires in its name, so that rows
 * no longer needed can be removed by name, with the index of the table.
 */
final class StoredCounters implements Counters {

	private const PREFIX = 'prstsearch_count_';

	private const CACHE_GROUP = 'prstsearch_counters';

	/**
	 * Creates the counters.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * {@inheritDoc}
	 */
	public function increment( string $key, int $expires_at ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::CACHE_GROUP, max( 1, $expires_at - time() ) );

			return (int) wp_cache_incr( $key, 1, self::CACHE_GROUP );
		}

		$name = self::PREFIX . $expires_at . '_' . substr( md5( $key ), 0, 16 );

		// One statement creates the row or raises it. LAST_INSERT_ID() keeps the raised value for this connection.
		$changed = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no')
				ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
				$this->wpdb->options,
				$name
			)
		);

		// One changed row: the counter was created, so it stands at one and the old ones can go.
		if ( 1 === $changed ) {
			$this->remove_expired();

			return 1;
		}

		return (int) $this->wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Removes the rows of counters that have expired.
	 */
	public function remove_expired(): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name < %s',
				$this->wpdb->options,
				$this->wpdb->esc_like( self::PREFIX ) . '%',
				self::PREFIX . time()
			)
		);
	}
}
