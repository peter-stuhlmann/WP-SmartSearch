<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Index\IndexVersion;
use PrstSearch\Support\Cache;
use PrstSearch\Support\Lock;
use wpdb;

/**
 * Values derived from the whole index: who builds them, and when.
 */
final class CacheTest extends WordPressTestCase {

	private const KEY = 'prstsearch_index_vocabulary';

	private wpdb $database;

	/**
	 * How often the value was built.
	 */
	private int $builds = 0;

	/**
	 * What WordPress was told to forget.
	 *
	 * @var string[]
	 */
	private array $forgotten = array();

	protected function setUp(): void {
		parent::setUp();

		$this->builds    = 0;
		$this->forgotten = array();
		$this->database  = new wpdb();

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key ): bool {
				$this->forgotten[] = $key;

				return true;
			}
		);
	}

	public function test_a_value_is_built_once_and_then_served(): void {
		self::assertSame( 'built 1', $this->value() );
		self::assertSame( 'built 1', $this->value() );
	}

	public function test_a_request_waits_for_a_value_another_one_is_building(): void {
		// Somebody else has the lock, and still has it at the first look.
		$this->database->affected = 0;
		$this->database->vars     = array( (string) time(), (string) time(), null );

		/*
		 * WordPress remembers within a request that a transient was missing and does not look again.
		 * The value the other request stored is only seen once WordPress was told to forget that.
		 */
		Functions\when( 'get_transient' )->alias(
			fn ( string $name ): mixed => self::KEY === $name && in_array( 'notoptions', $this->forgotten, true )
				? array(
					'variant' => '',
					'stamp'   => '0|',
					'value'   => 'built elsewhere',
				)
				: false
		);

		self::assertSame( 'built elsewhere', $this->value() );
		self::assertSame( 0, $this->builds );
		self::assertContains( '_transient_' . self::KEY, $this->forgotten );
	}

	public function test_a_request_builds_the_value_itself_if_the_other_one_never_delivers(): void {
		$this->database->affected = 0;
		// The lock is gone at the first look, but no value was stored: the other request died.
		$this->database->vars = array( (string) time(), null );

		self::assertSame( 'built 1', $this->value() );
	}

	public function test_a_value_for_another_variant_is_built_anew(): void {
		$cache = $this->cache();

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
	}

	private function cache(): Cache {
		return new Cache( new IndexVersion(), new Lock( $this->database ) );
	}

	private function value(): string {
		return $this->cache()->for_index(
			'vocabulary',
			'',
			function (): string {
				++$this->builds;

				return 'built ' . $this->builds;
			}
		);
	}
}
