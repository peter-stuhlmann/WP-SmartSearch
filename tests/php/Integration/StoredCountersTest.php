<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

use PrstSearch\Support\StoredCounters;
use wpdb;

/**
 * The counters behind the limits, against the database that keeps them.
 */
final class StoredCountersTest extends DatabaseTestCase {

	private wpdb $site;

	protected function setUp(): void {
		parent::setUp();

		// The connection of the site: the counters live in its options table, which the test prefix does not have.
		$this->site = $GLOBALS['wpdb'];
		$this->remove_rows();
	}

	protected function tearDown(): void {
		if ( isset( $this->site ) ) {
			$this->remove_rows();
		}

		parent::tearDown();
	}

	public function test_a_counter_counts_up_from_one(): void {
		$counters = new StoredCounters( $this->site );
		$expires  = time() + 60;

		self::assertSame( 1, $counters->increment( 'test_counter', $expires ) );
		self::assertSame( 2, $counters->increment( 'test_counter', $expires ) );
		self::assertSame( 3, ( new StoredCounters( $this->site ) )->increment( 'test_counter', $expires ), 'Another request counts on.' );
		self::assertSame( 1, $counters->increment( 'another_test_counter', $expires ) );
	}

	public function test_requests_on_separate_connections_never_see_the_same_value(): void {
		$expires = time() + 60;
		$seen    = array();

		// Each of these is a connection of its own, as concurrent requests have.
		for ( $request = 0; $request < 5; $request++ ) {
			$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

			$connection->set_prefix( $this->site->prefix );

			$seen[] = ( new StoredCounters( $connection ) )->increment( 'test_counter', $expires );
		}

		self::assertSame( array( 1, 2, 3, 4, 5 ), $seen );
	}

	public function test_expired_counters_are_removed_when_a_new_one_begins(): void {
		$counters = new StoredCounters( $this->site );

		$counters->increment( 'test_counter', time() - 10 );
		$counters->increment( 'test_counter', time() + 60 );

		self::assertSame( 1, $this->rows(), 'Only the counter that is still needed remains.' );
	}

	private function rows(): int {
		return (int) $this->site->get_var( $this->site->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $this->site->options, 'prstsearch\_count\_%' ) );
	}

	private function remove_rows(): void {
		$this->site->query( $this->site->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $this->site->options, 'prstsearch\_count\_%' ) );
	}
}
