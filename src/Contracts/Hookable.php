<?php
/**
 * Contract for classes that attach themselves to WordPress hooks.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Contracts;

/**
 * A service that registers actions and filters.
 */
interface Hookable {

	/**
	 * Registers all hooks of the service.
	 */
	public function register(): void;
}
