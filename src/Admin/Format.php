<?php
/**
 * Formats numbers for the admin UI.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Settings\Settings;

/**
 * Localised money and numbers, rounded for display with the exact value kept alongside.
 */
final class Format {

	/**
	 * Decimals shown for money.
	 */
	private const MONEY_DECIMALS = 2;

	/**
	 * Decimals of the exact value. A single search costs fractions of a cent,
	 * so this is where the interesting digits are.
	 */
	private const EXACT_DECIMALS = 6;

	/**
	 * Creates the formatter.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderConfig $config
	) {}

	/**
	 * Formats an amount of money in the configured currency.
	 */
	public function money( float $amount ): Figure {
		return new Figure(
			$this->with_currency( number_format_i18n( $amount, self::MONEY_DECIMALS ) ),
			$this->with_currency( self::unrounded( $amount, self::MONEY_DECIMALS ) )
		);
	}

	/**
	 * Formats a number with a fixed number of decimals.
	 *
	 * @param float  $value    The number.
	 * @param int    $decimals Decimals shown.
	 * @param string $suffix   Text appended to both forms, e.g. a unit.
	 */
	public function number( float $value, int $decimals = 0, string $suffix = '' ): Figure {
		return new Figure(
			number_format_i18n( $value, $decimals ) . $suffix,
			self::unrounded( $value, $decimals ) . $suffix
		);
	}

	/**
	 * Returns the sentence that explains how costs are estimated.
	 */
	public function price_note(): string {
		$prices = $this->config->prices();
		$date   = $this->config->has_custom_prices() ? $this->settings->string( 'prices_updated' ) : $this->config->definition()->prices_as_of;

		return sprintf(
			/* translators: 1: price for embeddings, 2: price for input, 3: price for output, 4: date. */
			__( 'Estimated with %1$s (embeddings), %2$s (input) and %3$s (output) per 1 million tokens. Prices as of %4$s.', 'smart-search' ),
			$this->money( $prices->embedding )->exact_or_text(),
			$this->money( $prices->input )->exact_or_text(),
			$this->money( $prices->output )->exact_or_text(),
			'' === $date ? '–' : date_i18n( get_option( 'date_format' ), (int) strtotime( $date ) )
		);
	}

	/**
	 * Formats a number with as many decimals as it takes to show it unrounded, within limits.
	 *
	 * @param float $value        The number.
	 * @param int   $min_decimals Decimals shown even if they are zero.
	 */
	private static function unrounded( float $value, int $min_decimals ): string {
		$decimals = $min_decimals;

		while ( $decimals < self::EXACT_DECIMALS && round( $value, $decimals ) !== round( $value, self::EXACT_DECIMALS ) ) {
			++$decimals;
		}

		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Adds the currency symbol in the position that is common for it.
	 */
	private function with_currency( string $number ): string {
		return 'EUR' === $this->settings->string( 'currency' ) ? $number . "\u{00A0}€" : '$' . $number;
	}
}
