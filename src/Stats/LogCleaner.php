<?php
/**
 * Enforces the retention period of the search log.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\SearchLog;
use PrstSearch\Index\Scheduler;
use PrstSearch\Settings\Settings;

/**
 * Deletes log entries older than the configured number of days, once a day.
 */
final class LogCleaner implements Hookable {

	/**
	 * Creates the cleaner.
	 */
	public function __construct(
		private readonly SearchLog $log,
		private readonly Settings $settings,
		private readonly Scheduler $scheduler
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this->scheduler, 'daily_cleanup' ) );
		add_action( Scheduler::CLEANUP_HOOK, array( $this, 'clean' ) );
	}

	/**
	 * Deletes expired entries.
	 */
	public function clean(): void {
		$days = max( 1, $this->settings->int( 'retention_days' ) );

		$this->log->delete_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
	}
}
