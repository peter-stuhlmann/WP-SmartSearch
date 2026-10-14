<?php
/**
 * Counters that requests running at the same time share.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

/**
 * Counts something across requests, such as the searches of a visitor in a minute.
 */
interface Counters {

	/**
	 * Adds one to a counter and returns its new value.
	 *
	 * Two requests at the same moment must not both see the same value: a
	 * script that sends many requests at once would otherwise slip through.
	 *
	 * @param string $key        Name of the counter. Whatever identifies the period belongs into it.
	 * @param int    $expires_at Unix time after which the counter is no longer needed.
	 */
	public function increment( string $key, int $expires_at ): int;
}
