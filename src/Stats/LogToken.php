<?php
/**
 * Signed references to log entries.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

/**
 * Lets the browser refer to "its" search without being able to touch other entries.
 */
final class LogToken {

	/**
	 * Creates the token for a log entry.
	 */
	public static function issue( int $log_id ): string {
		return $log_id > 0 ? $log_id . '.' . self::signature( $log_id ) : '';
	}

	/**
	 * Returns the log entry ID of a valid token, or 0.
	 */
	public static function verify( string $token ): int {
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 0;
		}

		$log_id = (int) $parts[0];

		return hash_equals( self::signature( $log_id ), $parts[1] ) ? $log_id : 0;
	}

	/**
	 * Signs a log entry ID with the site's secret.
	 */
	private static function signature( int $log_id ): string {
		return substr( wp_hash( 'prstsearch_log_' . $log_id ), 0, 20 );
	}
}
