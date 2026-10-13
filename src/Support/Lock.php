<?php
/**
 * A cross-request lock.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

use wpdb;

/**
 * Makes sure a job runs once at a time, even if cron and an admin request start it together.
 *
 * The database decides who gets the lock. The row is written with a single
 * statement that fails if it exists, and an abandoned lock is taken over
 * with a statement that only succeeds for the one who still sees the old
 * holder. The options API is not used for this: it reads before it writes,
 * and two requests can both pass the reading.
 */
final class Lock {

	private const PREFIX = 'prstsearch_lock_';

	/**
	 * Creates the lock.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * Tries to take the lock.
	 *
	 * @param string $name     Name of the job.
	 * @param int    $lifetime Seconds after which an unreleased lock counts as abandoned.
	 */
	public function acquire( string $name, int $lifetime ): bool {
		$option = self::PREFIX . $name;
		$now    = (string) time();

		// option_name is unique, so of several requests exactly one inserts the row.
		$inserted = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$this->wpdb->options,
				$option,
				$now
			)
		);

		if ( 1 === $inserted ) {
			return true;
		}

		$taken_at = (string) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, $option )
		);

		if ( time() - (int) $taken_at < $lifetime ) {
			return false;
		}

		// The previous holder never released it. Whoever replaces exactly that holder's entry has the lock.
		$taken_over = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$this->wpdb->options,
				$now,
				$option,
				$taken_at
			)
		);

		return 1 === $taken_over;
	}

	/**
	 * Releases the lock.
	 */
	public function release( string $name ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $this->wpdb->options, self::PREFIX . $name )
		);
	}
}
