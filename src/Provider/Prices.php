<?php
/**
 * Token prices of a provider.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Prices per one million tokens.
 */
final class Prices {

	private const TOKENS_PER_UNIT = 1_000_000;

	/**
	 * Creates the value object.
	 */
	public function __construct(
		public readonly float $embedding = 0.0,
		public readonly float $input = 0.0,
		public readonly float $output = 0.0
	) {}

	/**
	 * Calculates what a usage costs at these prices.
	 */
	public function cost_of( TokenUsage $usage ): float {
		$cost = $usage->embedding * $this->embedding
			+ $usage->input * $this->input
			+ $usage->output * $this->output;

		return $cost / self::TOKENS_PER_UNIT;
	}
}
