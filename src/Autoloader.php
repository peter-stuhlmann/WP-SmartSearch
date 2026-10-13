<?php
/**
 * PSR-4 autoloader so the plugin runs without Composer in production.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch;

/**
 * Maps a namespace prefix to a base directory.
 */
final class Autoloader {

	/**
	 * Registers the autoloader for one namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix including the trailing backslash.
	 * @param string $base_dir Directory containing the classes, with trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
