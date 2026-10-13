<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

use PrstSearch\Support\Lock;

/**
 * The lock that keeps cron and admin requests from doing the same job twice.
 *
 * Tested against the database, because it is the database that decides who gets the lock.
 */
final class LockTest extends DatabaseTestCase {

	private const JOB = 'test_job';

	private Lock $lock;

	protected function setUp(): void {
		parent::setUp();

		// The connection of the site: the lock lives in its options table, which the test prefix does not have.
		$this->lock = new Lock( $GLOBALS['wpdb'] );
		$this->lock->release( self::JOB );
		$this->lock->release( 'another_test_job' );
	}

	protected function tearDown(): void {
		if ( isset( $this->lock ) ) {
			$this->lock->release( self::JOB );
			$this->lock->release( 'another_test_job' );
		}

		parent::tearDown();
	}

	public function test_only_one_holder_gets_the_lock(): void {
		self::assertTrue( $this->lock->acquire( self::JOB, 60 ) );
		self::assertFalse( $this->lock->acquire( self::JOB, 60 ) );
		self::assertFalse( ( new Lock( $GLOBALS['wpdb'] ) )->acquire( self::JOB, 60 ), 'Nor does another request get it.' );
	}

	public function test_locks_of_different_jobs_do_not_block_each_other(): void {
		self::assertTrue( $this->lock->acquire( self::JOB, 60 ) );
		self::assertTrue( $this->lock->acquire( 'another_test_job', 60 ) );
	}

	public function test_a_released_lock_can_be_taken_again(): void {
		$this->lock->acquire( self::JOB, 60 );
		$this->lock->release( self::JOB );

		self::assertTrue( $this->lock->acquire( self::JOB, 60 ) );
	}

	public function test_a_lock_whose_holder_died_is_taken_over_by_exactly_one(): void {
		$this->lock->acquire( self::JOB, 60 );

		// The holder took it two minutes ago and never released it.
		$GLOBALS['wpdb']->query(
			$GLOBALS['wpdb']->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s',
				$GLOBALS['wpdb']->options,
				(string) ( time() - 120 ),
				'prstsearch_lock_' . self::JOB
			)
		);

		self::assertTrue( $this->lock->acquire( self::JOB, 60 ) );
		self::assertFalse( $this->lock->acquire( self::JOB, 60 ), 'The new holder is protected again.' );
	}

	public function test_the_lock_does_not_go_through_the_options_cache(): void {
		$this->lock->acquire( self::JOB, 60 );

		// WordPress remembers options it has looked up. A lock read from that memory would never be seen as taken, or as free.
		self::assertFalse( get_option( 'prstsearch_lock_' . self::JOB ) === false && $this->lock->acquire( self::JOB, 60 ) );

		$this->lock->release( self::JOB );

		self::assertTrue( $this->lock->acquire( self::JOB, 60 ) );
	}
}
