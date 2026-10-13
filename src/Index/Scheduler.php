<?php
/**
 * Schedules background index work.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

/**
 * Queues the cron events handled by BackgroundJobs.
 */
final class Scheduler {

	public const EMBEDDINGS_HOOK = 'prstsearch_process_embeddings';
	public const BACKFILL_HOOK   = 'prstsearch_backfill_index';
	public const CLEANUP_HOOK    = 'prstsearch_cleanup_log';
	public const COMPLETE_HOOK   = 'prstsearch_complete_index';

	/**
	 * Requests that missing embeddings are created.
	 *
	 * @param int $delay Seconds to wait before the job runs. 0 runs it as soon as possible.
	 */
	public function embeddings( int $delay = 0 ): void {
		$this->once( self::EMBEDDINGS_HOOK, $delay );
	}

	/**
	 * Requests that posts which were never indexed are indexed soon.
	 */
	public function backfill(): void {
		$this->once( self::BACKFILL_HOOK, 0 );
	}

	/**
	 * Schedules the run that brings passages stored by an older version up to date.
	 */
	public function completion(): void {
		$this->once( self::COMPLETE_HOOK, 0 );
	}

	/**
	 * Makes sure the daily maintenance is scheduled.
	 */
	public function daily_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Removes all events of the plugin.
	 */
	public function clear(): void {
		foreach ( array( self::EMBEDDINGS_HOOK, self::BACKFILL_HOOK, self::CLEANUP_HOOK, self::COMPLETE_HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Schedules a single event unless one is already waiting.
	 */
	private function once( string $hook, int $delay ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + $delay, $hook );
		}
	}
}
