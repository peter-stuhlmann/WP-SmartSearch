<?php
/**
 * Failure of an AI provider call.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

use RuntimeException;

/**
 * Thrown when a provider cannot be reached, rejects a request or is not configured.
 *
 * Callers catch it to fall back to keyword search.
 */
final class ProviderException extends RuntimeException {

	/**
	 * What the failed call used up before it failed.
	 *
	 * A provider bills what it has written, whether or not the answer was
	 * finished. Empty for a call that never got that far.
	 */
	private TokenUsage $usage;

	/**
	 * Creates the exception for a call that failed after the provider had begun to answer.
	 */
	public static function after( string $message, TokenUsage $usage ): self {
		$exception        = new self( $message );
		$exception->usage = $usage;

		return $exception;
	}

	/**
	 * Returns what the failed call used up.
	 */
	public function usage(): TokenUsage {
		return $this->usage ?? new TokenUsage();
	}
}
