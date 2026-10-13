<?php
/**
 * A simple cross-request lock.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

/**
 * Makes sure a job runs once at a time, even if cron and an admin request start it together.
 *
 * Built on add_option(), which fails if the row exists: the database decides
 * who gets the lock. A lock whose holder died is taken over after its lifetime.
 */
final class Lock {

	private const PREFIX = 'prstsearch_lock_';

	/**
	 * Tries to take the lock.
	 *
	 * @param string $name     Name of the job.
	 * @param int    $lifetime Seconds after which an unreleased lock counts as abandoned.
	 */
	public function acquire( string $name, int $lifetime ): bool {
		$option = self::PREFIX . $name;

		if ( add_option( $option, (string) time(), '', false ) ) {
			return true;
		}

		$taken_at = (int) get_option( $option, 0 );

		if ( time() - $taken_at < $lifetime ) {
			return false;
		}

		// The previous holder never released it.
		update_option( $option, (string) time(), false );

		return true;
	}

	/**
	 * Releases the lock.
	 */
	public function release( string $name ): void {
		delete_option( self::PREFIX . $name );
	}
}
