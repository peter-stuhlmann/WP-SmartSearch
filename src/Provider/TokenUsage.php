<?php
/**
 * Token counts of provider calls.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Immutable token usage, split by how the tokens are billed.
 */
final class TokenUsage {

	/**
	 * Creates the value object.
	 */
	public function __construct(
		public readonly int $embedding = 0,
		public readonly int $input = 0,
		public readonly int $output = 0
	) {}

	/**
	 * Returns what this usage has more than another, e.g. what a later step added to an earlier total.
	 */
	public function minus( self $other ): self {
		return new self(
			max( 0, $this->embedding - $other->embedding ),
			max( 0, $this->input - $other->input ),
			max( 0, $this->output - $other->output )
		);
	}

	/**
	 * Returns the sum of this and another usage.
	 */
	public function plus( self $other ): self {
		return new self(
			$this->embedding + $other->embedding,
			$this->input + $other->input,
			$this->output + $other->output
		);
	}

	/**
	 * Tells whether no tokens were used.
	 */
	public function is_empty(): bool {
		return 0 === $this->embedding + $this->input + $this->output;
	}
}
