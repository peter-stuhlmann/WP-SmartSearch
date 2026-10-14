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
	 * The same for counting searches over longer periods. Over an hour or a day
	 * many more visitors come, and two of them must rarely share a count:
	 * about a million groups, still some thousand IPv4 addresses each.
	 */
	private const SEARCH_GROUP_DIGITS = 5;

	private const SEARCH_TRANSIENT = 'prstsearch_rs_';

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
		return $this->is_below( $minute, $kind . '_' . self::visitor_group( $minute, self::GROUP_DIGITS ), (int) ( $limits['visitor'] ?? 0 ) )
			&& $this->is_below( $minute, $kind . '_site', (int) ( $limits['site'] ?? 0 ) );
	}

	/**
	 * Counts a search and tells whether it may proceed.
	 *
	 * @param int $per_visitor     Searches one visitor may start in the period. 0 for no limit.
	 * @param int $period          Length of the period in seconds: a minute, an hour or a day.
	 * @param int $site_per_minute Searches the whole site may start in a minute. 0 for no limit.
	 */
	public function allow_searches( int $per_visitor, int $period, int $site_per_minute ): bool {
		$period = max( MINUTE_IN_SECONDS, $period );
		$bucket = (int) floor( time() / $period );
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );

		// The visitor is checked first: a blocked visitor must not use up the site's allowance.
		return $this->is_below_in_period( 'search_' . $bucket . '_' . self::visitor_group( $bucket, self::SEARCH_GROUP_DIGITS ), $per_visitor, $period )
			&& $this->is_below( $minute, 'search_site', $site_per_minute );
	}

	/**
	 * Increments a counter that lives for one period and tells whether it is still within the limit.
	 *
	 * Each counter is a row of its own. Over an hour or a day a single row for
	 * all of them would grow with every visitor and be written in full on every search.
	 */
	private function is_below_in_period( string $counter, int $limit, int $period ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $counter, 0, self::CACHE_GROUP, $period );

			return (int) wp_cache_incr( $counter, 1, self::CACHE_GROUP ) <= $limit;
		}

		$name  = self::SEARCH_TRANSIENT . substr( md5( $counter ), 0, 16 );
		$count = (int) get_transient( $name );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $name, $count + 1, $period );

		return true;
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
	 * Returns the group the current visitor belongs to in the given period.
	 *
	 * @param int $period Number of the period, so that the group changes with it.
	 * @param int $digits Hexadecimal digits of the group number.
	 */
	private static function visitor_group( int $period, int $digits ): string {
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

		return substr( wp_hash( 'prstsearch_visitor|' . $period . '|' . $address ), 0, $digits );
	}
}
