<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PrstSearch\Provider\RequestThrottle;

/**
 * The cap on provider calls that visitors can trigger.
 */
final class RequestThrottleTest extends WordPressTestCase {

	private const ADDRESS = '203.0.113.7';

	private MemoryCounters $counters;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$_SERVER['REMOTE_ADDR'] = self::ADDRESS;
		$this->counters         = new MemoryCounters();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	public function test_a_visitor_is_stopped_at_their_own_limit(): void {
		$this->limit( 100, 2 );

		$throttle = new RequestThrottle( $this->counters );

		self::assertTrue( $throttle->allow( RequestThrottle::CHAT ) );
		self::assertTrue( $throttle->allow( RequestThrottle::CHAT ) );
		self::assertFalse( $throttle->allow( RequestThrottle::CHAT ) );
	}

	public function test_a_stopped_visitor_does_not_use_up_the_allowance_of_the_site(): void {
		$this->limit( 3, 2 );

		$throttle = new RequestThrottle( $this->counters );

		$throttle->allow( RequestThrottle::CHAT );
		$throttle->allow( RequestThrottle::CHAT );

		// Stopped five more times. None of these may count towards the site.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			self::assertFalse( $throttle->allow( RequestThrottle::CHAT ) );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';

		self::assertTrue( $throttle->allow( RequestThrottle::CHAT ), 'The third request of the site is still free.' );
		self::assertFalse( $throttle->allow( RequestThrottle::CHAT ), 'The fourth is over the limit of the site.' );
	}

	public function test_the_kinds_of_request_are_counted_separately(): void {
		$this->limit( 100, 1 );

		$throttle = new RequestThrottle( $this->counters );

		self::assertTrue( $throttle->allow( RequestThrottle::CHAT ) );
		self::assertTrue( $throttle->allow( RequestThrottle::EMBEDDING ) );
		self::assertFalse( $throttle->allow( RequestThrottle::CHAT ) );
	}

	public function test_zero_switches_a_limit_off(): void {
		$this->limit( 0, 0 );

		$throttle = new RequestThrottle( $this->counters );

		for ( $attempt = 0; $attempt < 500; $attempt++ ) {
			self::assertTrue( $throttle->allow( RequestThrottle::EMBEDDING ) );
		}

		self::assertSame( array(), $this->counters->values, 'Nothing is counted either.' );
	}

	public function test_the_ip_address_is_never_stored(): void {
		$this->limit( 100, 100 );

		( new RequestThrottle( $this->counters ) )->allow( RequestThrottle::CHAT );

		self::assertNotSame( array(), $this->counters->values );
		self::assertStringNotContainsString( self::ADDRESS, (string) json_encode( $this->counters->values ) );
	}

	private function limit( int $site, int $visitor ): void {
		Filters\expectApplied( 'prstsearch_requests_per_minute' )->andReturn(
			array(
				'site'    => $site,
				'visitor' => $visitor,
			)
		);
	}
}
