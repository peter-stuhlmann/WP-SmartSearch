<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Support\Counters;

/**
 * Test double: counters in memory.
 */
final class MemoryCounters implements Counters {

	/**
	 * Values by name.
	 *
	 * @var array<string, int>
	 */
	public array $values = array();

	public function increment( string $key, int $expires_at ): int {
		$this->values[ $key ] = ( $this->values[ $key ] ?? 0 ) + 1;

		return $this->values[ $key ];
	}
}
