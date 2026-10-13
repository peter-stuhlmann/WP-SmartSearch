<?php
/**
 * A reporting period.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A range of whole days in the site's time zone.
 */
final class DateRange {

	public const TODAY   = 'today';
	public const WEEK    = '7d';
	public const MONTH   = '30d';
	public const QUARTER = '90d';
	public const YEAR    = 'year';
	public const CUSTOM  = 'custom';

	private const DEFAULT_PRESET = self::MONTH;

	private const MAX_DAYS = 366;

	private const PRESET_DAYS = array(
		self::TODAY   => 1,
		self::WEEK    => 7,
		self::MONTH   => 30,
		self::QUARTER => 90,
	);

	/**
	 * Creates the range.
	 *
	 * @param string            $preset One of the class constants.
	 * @param DateTimeImmutable $start  First day at midnight, inclusive.
	 * @param DateTimeImmutable $end    Midnight after the last day, exclusive.
	 */
	private function __construct(
		public readonly string $preset,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end
	) {}

	/**
	 * Builds a range from user input.
	 *
	 * Anything invalid falls back to the last 30 days, so the statistics page always shows something sensible.
	 *
	 * @param string            $preset Requested preset.
	 * @param string            $from   First day (Y-m-d), only used for the custom preset.
	 * @param string            $to     Last day (Y-m-d), only used for the custom preset.
	 * @param DateTimeImmutable $now    Current time in the site's time zone.
	 */
	public static function from_input( string $preset, string $from, string $to, DateTimeImmutable $now ): self {
		$today = $now->setTime( 0, 0 );

		if ( self::CUSTOM === $preset ) {
			$first = self::parse_day( $from, $now->getTimezone() );
			$last  = self::parse_day( $to, $now->getTimezone() );

			if ( null !== $first && null !== $last ) {
				return self::custom( min( $first, $last ), max( $first, $last ), $today );
			}
		}

		if ( self::YEAR === $preset ) {
			return new self( $preset, $today->setDate( (int) $today->format( 'Y' ), 1, 1 ), $today->modify( '+1 day' ) );
		}

		$preset = isset( self::PRESET_DAYS[ $preset ] ) ? $preset : self::DEFAULT_PRESET;
		$days   = self::PRESET_DAYS[ $preset ];

		return new self( $preset, $today->modify( '-' . ( $days - 1 ) . ' days' ), $today->modify( '+1 day' ) );
	}

	/**
	 * Returns the start as a UTC date-time in MySQL format.
	 */
	public function utc_start(): string {
		return self::to_utc( $this->start );
	}

	/**
	 * Returns the exclusive end as a UTC date-time in MySQL format.
	 */
	public function utc_end(): string {
		return self::to_utc( $this->end );
	}

	/**
	 * Returns the time zone the days of the range are cut in.
	 */
	public function timezone(): DateTimeZone {
		return $this->start->getTimezone();
	}

	/**
	 * Returns the last day of the range.
	 */
	public function last_day(): DateTimeImmutable {
		return $this->end->modify( '-1 day' );
	}

	/**
	 * Returns every day of the range.
	 *
	 * @return string[] Dates as Y-m-d.
	 */
	public function days(): array {
		$days = array();

		for ( $day = $this->start; $day < $this->end; $day = $day->modify( '+1 day' ) ) {
			$days[] = $day->format( 'Y-m-d' );
		}

		return $days;
	}

	/**
	 * Builds a custom range, limited to today and to the maximum length.
	 */
	private static function custom( DateTimeImmutable $first, DateTimeImmutable $last, DateTimeImmutable $today ): self {
		$last  = min( $last, $today );
		$first = min( $first, $last );
		$first = max( $first, $last->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' ) );

		return new self( self::CUSTOM, $first, $last->modify( '+1 day' ) );
	}

	/**
	 * Parses a Y-m-d date strictly.
	 */
	private static function parse_day( string $value, DateTimeZone $timezone ): ?DateTimeImmutable {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );

		return false !== $day && $day->format( 'Y-m-d' ) === $value ? $day : null;
	}

	/**
	 * Formats a date-time in UTC.
	 */
	private static function to_utc( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
