<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests of code that reads and writes options and transients.
 *
 * Both are kept in arrays, so a test can look at what was stored.
 */
abstract class WordPressTestCase extends TestCase {

	/**
	 * Stored options.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	/**
	 * Stored transients.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options    = array();
		$this->transients = array();

		Functions\when( 'get_option' )->alias( fn ( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
		Functions\when( 'add_option' )->alias(
			function ( string $name, mixed $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);

		Functions\when( 'get_transient' )->alias( fn ( string $name ): mixed => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->transients[ $name ] = $value;

				return true;
			}
		);

		Functions\when( 'wp_hash' )->alias( static fn ( string $data ): string => hash_hmac( 'sha256', $data, 'test-salt' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
