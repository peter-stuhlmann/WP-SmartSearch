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
final class ProviderException extends RuntimeException {}
