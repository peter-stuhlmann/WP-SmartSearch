<?php
/**
 * Builds the statistics of a period.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

use DateTimeImmutable;
use DateTimeZone;
use PrstSearch\Database\SearchLog;

/**
 * Collects everything the statistics page shows for a date range.
 */
final class StatsReport {

	private const TOP_QUERIES = 10;

	public const ENTRIES_PER_PAGE = 25;

	/**
	 * Creates the report.
	 */
	public function __construct( private readonly SearchLog $log ) {}

	/**
	 * Returns the key figures of the range.
	 *
	 * @return array{searches: int, without_results: int, clicked: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float, avg_duration_ms: float}
	 */
	public function totals( DateRange $range ): array {
		return $this->log->totals( $range->utc_start(), $range->utc_end() );
	}

	/**
	 * Returns per-day figures with a row for every day of the range.
	 *
	 * @return array<string, array{searches: int, input_cost: float, output_cost: float}> Keyed by local date (Y-m-d).
	 */
	public function daily( DateRange $range ): array {
		$days = array_fill_keys(
			$range->days(),
			array(
				'searches'    => 0,
				'input_cost'  => 0.0,
				'output_cost' => 0.0,
			)
		);

		$utc = new DateTimeZone( 'UTC' );

		foreach ( $this->log->hourly( $range->utc_start(), $range->utc_end() ) as $utc_hour => $figures ) {
			// Converting each hour on its own gets daylight saving time right: the offset
			// that applied at that hour is used, not the one of the first day.
			$hour = DateTimeImmutable::createFromFormat( '!Y-m-d H', $utc_hour, $utc );
			$day  = false === $hour ? '' : $hour->setTimezone( $range->timezone() )->format( 'Y-m-d' );

			if ( ! isset( $days[ $day ] ) ) {
				continue;
			}

			foreach ( $figures as $name => $value ) {
				$days[ $day ][ $name ] += $value;
			}
		}

		return $days;
	}

	/**
	 * Returns the most frequent queries.
	 *
	 * @return array<int, array{query: string, searches: int, results: int}>
	 */
	public function top_queries( DateRange $range ): array {
		return $this->log->top_queries( $range->utc_start(), $range->utc_end(), self::TOP_QUERIES );
	}

	/**
	 * Returns the most frequent queries that found nothing: the content gaps of the site.
	 *
	 * @return array<int, array{query: string, searches: int, results: int}>
	 */
	public function queries_without_results( DateRange $range ): array {
		return $this->log->top_queries( $range->utc_start(), $range->utc_end(), self::TOP_QUERIES, true );
	}

	/**
	 * Returns one page of individual searches, newest first.
	 *
	 * @param array{search?: string, mode?: string} $filter Optional filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function entries( DateRange $range, int $page, array $filter = array() ): array {
		return $this->log->entries(
			$range->utc_start(),
			$range->utc_end(),
			self::ENTRIES_PER_PAGE,
			( max( 1, $page ) - 1 ) * self::ENTRIES_PER_PAGE,
			$filter
		);
	}

	/**
	 * Counts the individual searches that match a filter.
	 *
	 * @param array{search?: string, mode?: string} $filter Optional filter.
	 */
	public function entry_count( DateRange $range, array $filter = array() ): int {
		return $this->log->count_entries( $range->utc_start(), $range->utc_end(), $filter );
	}

	/**
	 * Returns all searches of the range for export, in chunks.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function all_entries( DateRange $range ): \Generator {
		$offset = 0;
		$limit  = 500;

		do {
			$rows  = $this->log->entries( $range->utc_start(), $range->utc_end(), $limit, $offset );
			$count = count( $rows );

			yield from $rows;

			$offset += $limit;
		} while ( $count === $limit );
	}
}
