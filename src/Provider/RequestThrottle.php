<?php
/**
 * Limits how often visitors can trigger paid provider calls.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Caps provider calls per minute, for the whole site and per visitor.
 *
 * The site-wide cap protects the budget. The per-visitor cap keeps a single
 * client from using up the site-wide one, which would switch the AI features
 * off for everybody else.
 *
 * To tell visitors apart, a hash of the IP address is used as a counter name
 * for two minutes. The address itself is never stored, and nothing of this
 * reaches the statistics.
 */
final class RequestThrottle {

	/**
	 * A chat completion: an AI answer or a second opinion on results.
	 */
	public const CHAT = 'chat';

	/**
	 * The embedding of a search phrase.
	 */
	public const EMBEDDING = 'embedding';

	private const CACHE_GROUP = 'prstsearch_throttle';

	/**
	 * Requests per minute: for the whole site, and for one visitor.
	 */
	private const LIMITS = array(
		self::CHAT      => array(
			'site'    => 30,
			'visitor' => 10,
		),
		self::EMBEDDING => array(
			'site'    => 120,
			'visitor' => 30,
		),
	);

	/**
	 * Counts a request and tells whether it may proceed.
	 *
	 * @param string $kind One of the class constants.
	 */
	public function allow( string $kind ): bool {
		/**
		 * Filters how many visitor-triggered provider requests are allowed per minute. 0 disables a limit.
		 *
		 * @param array{site: int, visitor: int} $limits Requests per minute for the whole site and per visitor.
		 * @param string                         $kind   "chat" or "embedding".
		 */
		$limits = (array) apply_filters( 'prstsearch_requests_per_minute', self::LIMITS[ $kind ] ?? self::LIMITS[ self::CHAT ], $kind );
		$window = (int) floor( time() / MINUTE_IN_SECONDS );

		// The visitor is checked first: a blocked visitor must not use up the site's allowance.
		return $this->is_below( $kind . '_' . self::visitor() . '_' . $window, (int) ( $limits['visitor'] ?? 0 ) )
			&& $this->is_below( $kind . '_site_' . $window, (int) ( $limits['site'] ?? 0 ) );
	}

	/**
	 * Increments a counter and tells whether it is still within the limit.
	 */
	private function is_below( string $counter, int $limit ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		// With a persistent object cache the increment is atomic. Without one the
		// counter can be off by a few under heavy parallel load, which is acceptable
		// for a limit that only has to hold approximately.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $counter, 0, self::CACHE_GROUP, 2 * MINUTE_IN_SECONDS );

			return (int) wp_cache_incr( $counter, 1, self::CACHE_GROUP ) <= $limit;
		}

		$count = (int) get_transient( 'prstsearch_rate_' . $counter );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( 'prstsearch_rate_' . $counter, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Returns a short-lived, non-reversible identifier of the current visitor.
	 */
	private static function visitor(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only hashed, never stored or output.
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return substr( wp_hash( 'prstsearch_visitor|' . $address ), 0, 16 );
	}
}
