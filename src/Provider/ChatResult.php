<?php
/**
 * Result of a chat call.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Generated text and what it cost.
 */
final class ChatResult {

	/**
	 * Creates the result.
	 */
	public function __construct(
		public readonly string $text,
		public readonly TokenUsage $usage
	) {}
}
