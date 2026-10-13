<?php
/**
 * Limits how often visitors can trigger costly work.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Caps what visitors can set off per minute, for the whole site and per visitor.
 *
 * The site-wide cap protects the budget. The per-visitor cap keeps a single
 * client from using up the site-wide one, which would switch the AI features
 * off for everybody else.
 *
 * Visitors are told apart without anything being kept that identifies them.
 * The IP address is hashed together with the current minute and cut down to
 * a group number: a few thousand groups for all addresses there are. Many
 * addresses share a group, so a number cannot be traced back to one, and it
 * is another number a minute later. What is stored is a count per group. The
 * address is never stored, and nothing of this reaches the statistics.
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

	/**
	 * An entry in the search statistics. Costs nothing, but nobody should be able to fill the table.
	 */
	public const LOG = 'log';

	private const CACHE_GROUP = 'prstsearch_throttle';

	private const TRANSIENT = 'prstsearch_rate_';

	/**
	 * Number of groups visitors are sorted into: 16 to the power of this many hexadecimal digits.
	 * Enough that two visitors rarely share one within a minute, few enough that no address stands alone.
	 */
	private const GROUP_DIGITS = 3;

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
		self::LOG       => array(
			'site'    => 600,
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
		 * Filters how many requests of a kind visitors may trigger per minute. 0 disables a limit.
		 *
		 * @param array{site: int, visitor: int} $limits Requests per minute for the whole site and per visitor.
		 * @param string                         $kind   "chat", "embedding" or "log".
		 */
		$limits = (array) apply_filters( 'prstsearch_requests_per_minute', self::LIMITS[ $kind ] ?? self::LIMITS[ self::CHAT ], $kind );
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );

		// The visitor is checked first: a blocked visitor must not use up the site's allowance.
		return $this->is_below( $minute, $kind . '_' . self::visitor_group( $minute ), (int) ( $limits['visitor'] ?? 0 ) )
			&& $this->is_below( $minute, $kind . '_site', (int) ( $limits['site'] ?? 0 ) );
	}

	/**
	 * Increments a counter of the given minute and tells whether it is still within the limit.
	 */
	private function is_below( int $minute, string $counter, int $limit ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		// With a persistent object cache the increment is atomic, and nothing reaches the database.
		if ( wp_using_ext_object_cache() ) {
			$key = $counter . '_' . $minute;

			wp_cache_add( $key, 0, self::CACHE_GROUP, 2 * MINUTE_IN_SECONDS );

			return (int) wp_cache_incr( $key, 1, self::CACHE_GROUP ) <= $limit;
		}

		/*
		 * Without one, all counters of a minute share a single database row,
		 * and the row of the minute before is removed as soon as a new one
		 * begins. Under heavy parallel load a count can be off by a few, which
		 * is acceptable for a limit that only has to hold approximately.
		 */
		$counts = get_transient( self::TRANSIENT . $minute );
		$counts = is_array( $counts ) ? $counts : array();

		if ( array() === $counts ) {
			delete_transient( self::TRANSIENT . ( $minute - 1 ) );
		}

		$count = (int) ( $counts[ $counter ] ?? 0 );

		if ( $count >= $limit ) {
			return false;
		}

		$counts[ $counter ] = $count + 1;

		set_transient( self::TRANSIENT . $minute, $counts, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Returns the group the current visitor belongs to in the given minute.
	 */
	private static function visitor_group( int $minute ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only hashed, never stored or output.
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		/**
		 * Filters the address visitors are told apart by.
		 *
		 * Behind a reverse proxy or a CDN every request seems to come from the
		 * proxy, and all visitors would share one allowance. A website in that
		 * situation returns the address the proxy passes on here. It is not read
		 * from a header by default, because a header can be forged where no
		 * proxy sets it.
		 *
		 * @param string $address The address of the client, as the web server reports it.
		 */
		$address = (string) apply_filters( 'prstsearch_visitor_address', $address );

		return substr( wp_hash( 'prstsearch_visitor|' . $minute . '|' . $address ), 0, self::GROUP_DIGITS );
	}
}
