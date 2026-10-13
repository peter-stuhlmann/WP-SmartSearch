<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Index\IndexVersion;
use PrstSearch\Support\Cache;

/**
 * Values derived from the whole index: who builds them, and when.
 */
final class CacheTest extends WordPressTestCase {

	private const KEY = 'prstsearch_index_vocabulary';

	private const LOCK = 'prstsearch_index_vocabulary_rebuilding';

	/**
	 * How often the value was built.
	 */
	private int $builds = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->builds = 0;
	}

	public function test_a_value_is_built_once_and_then_served(): void {
		self::assertSame( 'built 1', $this->value() );
		self::assertSame( 'built 1', $this->value() );
		self::assertArrayNotHasKey( self::LOCK, $this->transients, 'Nobody is kept waiting once the value is there.' );
	}

	public function test_a_request_waits_for_a_value_another_one_is_building(): void {
		$this->transients[ self::LOCK ] = 1;

		$looked = 0;

		// The other request finishes while this one waits.
		Functions\when( 'get_transient' )->alias(
			function ( string $name ) use ( &$looked ): mixed {
				if ( self::KEY === $name && ++$looked > 1 ) {
					return array(
						'variant' => '',
						'stamp'   => '0|',
						'value'   => 'built elsewhere',
					);
				}

				return $this->transients[ $name ] ?? false;
			}
		);

		self::assertSame( 'built elsewhere', $this->value() );
		self::assertSame( 0, $this->builds );
	}

	public function test_a_value_for_another_variant_is_built_anew(): void {
		$cache = new Cache( new IndexVersion() );

		self::assertSame( 'for a', $cache->for_index( 'vocabulary', 'a', static fn (): string => 'for a' ) );
		self::assertSame( 'for b', $cache->for_index( 'vocabulary', 'b', static fn (): string => 'for b' ) );
	}

	public function test_after_the_index_changed_the_previous_value_serves_until_the_request_is_over(): void {
		$rebuild = null;

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$rebuild ): void {
				$rebuild = $callback;
			}
		);

		$this->value();

		$this->options[ IndexVersion::OPTION ] = '2';

		self::assertSame( 'built 1', $this->value(), 'The visitor does not wait for the new value.' );
		self::assertSame( 1, $this->builds );

		$rebuild();

		self::assertSame( 'built 2', $this->value() );
		self::assertArrayNotHasKey( self::LOCK, $this->transients );
	}

	private function value(): string {
		return ( new Cache( new IndexVersion() ) )->for_index(
			'vocabulary',
			'',
			function (): string {
				++$this->builds;

				return 'built ' . $this->builds;
			}
		);
	}
}
