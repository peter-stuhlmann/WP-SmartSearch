<?php
/**
 * Result of an embedding call.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Embedding vectors and what they cost.
 */
final class EmbeddingResult {

	/**
	 * Creates the result.
	 *
	 * @param array<int, float[]> $vectors Vectors in the order of the input texts.
	 * @param TokenUsage          $usage   Tokens billed for the call.
	 */
	public function __construct(
		public readonly array $vectors,
		public readonly TokenUsage $usage
	) {}
}
