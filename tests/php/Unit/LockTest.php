<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Support\Lock;

/**
 * The lock that keeps cron and admin requests from doing the same job twice.
 */
final class LockTest extends WordPressTestCase {

	public function test_only_one_holder_gets_the_lock(): void {
		$lock = new Lock();

		self::assertTrue( $lock->acquire( 'job', 60 ) );
		self::assertFalse( $lock->acquire( 'job', 60 ) );
	}

	public function test_locks_of_different_jobs_do_not_block_each_other(): void {
		$lock = new Lock();

		self::assertTrue( $lock->acquire( 'one', 60 ) );
		self::assertTrue( $lock->acquire( 'two', 60 ) );
	}

	public function test_a_released_lock_can_be_taken_again(): void {
		$lock = new Lock();

		$lock->acquire( 'job', 60 );
		$lock->release( 'job' );

		self::assertTrue( $lock->acquire( 'job', 60 ) );
	}

	public function test_a_lock_whose_holder_died_is_taken_over_after_its_lifetime(): void {
		$lock = new Lock();

		$lock->acquire( 'job', 60 );

		// The holder took it two minutes ago and never released it.
		$name                   = array_key_first( $this->options );
		$this->options[ $name ] = (string) ( time() - 120 );

		self::assertTrue( $lock->acquire( 'job', 60 ) );
		self::assertFalse( $lock->acquire( 'job', 60 ), 'The new holder is protected again.' );
	}
}
