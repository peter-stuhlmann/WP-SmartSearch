<?php
/**
 * Tracks what the AI provider costs per month.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

use PrstSearch\Settings\Settings;

/**
 * Sums up estimated costs per month and enforces the monthly budget.
 *
 * Unlike the search log this also covers indexing and works when the
 * statistics are switched off.
 */
final class UsageLedger {

	public const OPTION = 'prstsearch_usage';

	public const PURPOSE_INDEX  = 'index';
	public const PURPOSE_SEARCH = 'search';

	private const MONTHS_KEPT = 13;

	/**
	 * Creates the ledger.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderConfig $config
	) {}

	/**
	 * Adds a provider call to the current month.
	 *
	 * @param string     $purpose One of the PURPOSE_* constants.
	 * @param TokenUsage $usage   Tokens used by the call.
	 * @return float Estimated cost of the call.
	 */
	public function record( string $purpose, TokenUsage $usage ): float {
		$cost = $this->config->prices()->cost_of( $usage );

		if ( $cost <= 0.0 ) {
			return 0.0;
		}

		$months = $this->months();
		$month  = $this->current_month();

		$months[ $month ][ $purpose ] = ( $months[ $month ][ $purpose ] ?? 0.0 ) + $cost;

		krsort( $months );

		update_option( self::OPTION, array_slice( $months, 0, self::MONTHS_KEPT, true ), false );

		return $cost;
	}

	/**
	 * Returns the estimated cost of the current month, for indexing and searching together.
	 */
	public function month_cost(): float {
		return (float) array_sum( $this->months()[ $this->current_month() ] ?? array() );
	}

	/**
	 * Tells whether the monthly budget is used up. No budget means no limit.
	 */
	public function is_budget_exhausted(): bool {
		$budget = $this->settings->float( 'monthly_budget' );

		return $budget > 0 && $this->month_cost() >= $budget;
	}

	/**
	 * Returns the stored months.
	 *
	 * @return array<string, array<string, float>>
	 */
	private function months(): array {
		$months = get_option( self::OPTION, array() );

		return is_array( $months ) ? $months : array();
	}

	/**
	 * Returns the key of the current month in the site's time zone.
	 */
	private function current_month(): string {
		return wp_date( 'Y-m' );
	}
}
