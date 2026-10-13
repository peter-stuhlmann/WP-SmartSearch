<?php
/**
 * Data for the statistics view.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use DateTimeImmutable;
use Generator;
use PrstSearch\Search\SearchMode;
use PrstSearch\Stats\BarChart;
use PrstSearch\Stats\DateRange;
use PrstSearch\Stats\StatsReport;

/**
 * Turns the statistics of a period into what the view prints.
 */
final class StatsViewModel {

	/**
	 * Columns of the table that hold numbers and are right-aligned.
	 */
	private const NUMERIC_COLUMNS = array( 'results', 'input_tokens', 'output_tokens', 'input_cost', 'output_cost', 'cost' );

	/**
	 * Creates the view model.
	 */
	public function __construct(
		private readonly StatsReport $report,
		private readonly BarChart $chart,
		private readonly Format $format
	) {}

	/**
	 * Returns everything the statistics tab shows for a request.
	 *
	 * @param array<string, mixed> $request Query parameters of the page request.
	 * @return array<string, mixed>
	 */
	public function page( array $request ): array {
		$range = $this->range( $request );

		return array(
			'range'           => $range,
			'range_args'      => self::range_args( $range ),
			'presets'         => $this->presets(),
			'tiles'           => $this->tiles( $range ),
			'charts'          => $this->charts( $range ),
			'rankings'        => array(
				'top'             => $this->report->top_queries( $range ),
				'without_results' => $this->report->queries_without_results( $range ),
			),
			'table'           => $this->table( $range, $request ),
			'headers'         => $this->table_headers(),
			'numeric_columns' => self::NUMERIC_COLUMNS,
			'price_note'      => $this->format->price_note(),
		);
	}

	/**
	 * Builds the date range from request parameters.
	 *
	 * @param array<string, mixed> $request Query parameters.
	 */
	public function range( array $request ): DateRange {
		return DateRange::from_input(
			self::text( $request, 'range' ),
			self::text( $request, 'from' ),
			self::text( $request, 'to' ),
			new DateTimeImmutable( 'now', wp_timezone() )
		);
	}

	/**
	 * Returns the column headers of the table and the CSV export.
	 *
	 * @return array<string, string> Labels keyed by row key.
	 */
	public function table_headers(): array {
		return array(
			'time'          => __( 'Time', 'smart-search' ),
			'query'         => __( 'Search phrase', 'smart-search' ),
			'mode'          => __( 'Mode', 'smart-search' ),
			'results'       => __( 'Results', 'smart-search' ),
			'input_tokens'  => __( 'Input tokens', 'smart-search' ),
			'output_tokens' => __( 'Output tokens', 'smart-search' ),
			'input_cost'    => __( 'Input cost', 'smart-search' ),
			'output_cost'   => __( 'Output cost', 'smart-search' ),
			'cost'          => __( 'Total', 'smart-search' ),
		);
	}

	/**
	 * Returns all searches of a range for export, with unrounded values.
	 *
	 * @return Generator<int, array<string, string>>
	 */
	public function export_rows( DateRange $range ): Generator {
		foreach ( $this->report->all_entries( $range ) as $entry ) {
			yield array_map( static fn ( Figure $cell ): string => $cell->exact_or_text(), $this->table_row( $entry ) );
		}
	}

	/**
	 * Returns the query arguments that keep the chosen period when the table is filtered or paged.
	 *
	 * @return array<string, string>
	 */
	private static function range_args( DateRange $range ): array {
		if ( DateRange::CUSTOM !== $range->preset ) {
			return array( 'range' => $range->preset );
		}

		return array(
			'range' => $range->preset,
			'from'  => $range->start->format( 'Y-m-d' ),
			'to'    => $range->last_day()->format( 'Y-m-d' ),
		);
	}

	/**
	 * Returns the range presets for the period switcher.
	 *
	 * @return array<string, string> Labels keyed by preset.
	 */
	private function presets(): array {
		return array(
			DateRange::TODAY   => __( 'Today', 'smart-search' ),
			DateRange::WEEK    => __( '7 days', 'smart-search' ),
			DateRange::MONTH   => __( '30 days', 'smart-search' ),
			DateRange::QUARTER => __( '90 days', 'smart-search' ),
			DateRange::YEAR    => __( 'This year', 'smart-search' ),
		);
	}

	/**
	 * Returns the key figure tiles.
	 *
	 * @return array<int, array{label: string, value: Figure, note: Figure}>
	 */
	private function tiles( DateRange $range ): array {
		$totals   = $this->report->totals( $range );
		$searches = $totals['searches'];
		$days     = max( 1, count( $range->days() ) );
		$cost     = $totals['input_cost'] + $totals['output_cost'];
		$percent  = "\u{00A0}%";

		$tiles = array(
			array(
				'label' => __( 'Searches', 'smart-search' ),
				'value' => $this->format->number( $searches ),
				/* translators: %s: average number of searches. */
				'note'  => Figure::sentence( __( 'Ø %s per day', 'smart-search' ), $this->format->number( $searches / $days, 1 ) ),
			),
			array(
				'label' => __( 'Without results', 'smart-search' ),
				'value' => $this->format->number( $searches > 0 ? 100 * $totals['without_results'] / $searches : 0.0, 1, $percent ),
				/* translators: 1: searches without results, 2: all searches. */
				'note'  => new Figure( sprintf( __( '%1$s of %2$s searches', 'smart-search' ), number_format_i18n( $totals['without_results'] ), number_format_i18n( $searches ) ) ),
			),
			array(
				'label' => __( 'AI costs', 'smart-search' ),
				'value' => $this->format->money( $cost ),
				/* translators: 1: input costs, 2: output costs. */
				'note'  => Figure::sentence( __( 'Input %1$s · Output %2$s', 'smart-search' ), $this->format->money( $totals['input_cost'] ), $this->format->money( $totals['output_cost'] ) ),
			),
			array(
				'label' => __( 'Ø cost per search', 'smart-search' ),
				'value' => $this->format->money( $searches > 0 ? $cost / $searches : 0.0 ),
				/* translators: %s: number of searches. */
				'note'  => new Figure( sprintf( __( 'across %s searches', 'smart-search' ), number_format_i18n( $searches ) ) ),
			),
		);

		if ( $totals['clicked'] > 0 ) {
			$tiles[] = array(
				'label' => __( 'Clicked a result', 'smart-search' ),
				'value' => $this->format->number( 100 * $totals['clicked'] / max( 1, $searches ), 1, $percent ),
				/* translators: %s: number of searches. */
				'note'  => new Figure( sprintf( __( '%s searches led to a click', 'smart-search' ), number_format_i18n( $totals['clicked'] ) ) ),
			);
		}

		if ( $totals['avg_duration_ms'] > 0 ) {
			$tiles[] = array(
				'label' => __( 'Ø response time', 'smart-search' ),
				/* translators: %s: number of milliseconds. */
				'value' => Figure::sentence( __( '%s ms', 'smart-search' ), $this->format->number( $totals['avg_duration_ms'] ) ),
				'note'  => new Figure( __( 'for the result list, without the AI answer', 'smart-search' ) ),
			);
		}

		return $tiles;
	}

	/**
	 * Returns the two charts with their table alternative.
	 *
	 * @return array{searches: array{svg: string, rows: array<int, string[]>, headers: string[]}, costs: array{svg: string, rows: array<int, string[]>, headers: string[], legend: array<string, string>}}
	 */
	private function charts( DateRange $range ): array {
		$daily  = $this->report->daily( $range );
		$labels = array_map(
			static fn ( string $day ): string => date_i18n( _x( 'd.m.', 'short date in charts', 'smart-search' ), (int) strtotime( $day ) ),
			array_keys( $daily )
		);

		$searches = array_column( $daily, 'searches' );
		$input    = array_column( $daily, 'input_cost' );
		$output   = array_column( $daily, 'output_cost' );

		$number      = static fn ( float $value ): string => number_format_i18n( $value );
		$money       = fn ( float $value ): string => $this->format->money( $value )->text;
		$exact_money = fn ( float $value ): string => $this->format->money( $value )->exact_or_text();

		$input_name  = __( 'Input', 'smart-search' );
		$output_name = __( 'Output', 'smart-search' );

		return array(
			'searches' => array(
				'svg'     => $this->chart->render(
					__( 'Searches per day', 'smart-search' ),
					$labels,
					array(
						array(
							'name'   => __( 'Searches', 'smart-search' ),
							'slug'   => 'primary',
							'values' => $searches,
						),
					),
					$number,
					true
				),
				'headers' => array( __( 'Day', 'smart-search' ), __( 'Searches', 'smart-search' ) ),
				'rows'    => array_map( null, $labels, array_map( 'number_format_i18n', $searches ) ),
			),
			'costs'    => array(
				'svg'     => $this->chart->render(
					__( 'AI costs per day', 'smart-search' ),
					$labels,
					array(
						array(
							'name'   => $input_name,
							'slug'   => 'input',
							'values' => $input,
						),
						array(
							'name'   => $output_name,
							'slug'   => 'output',
							'values' => $output,
						),
					),
					$money,
					false,
					$exact_money
				),
				'legend'  => array(
					'input'  => $input_name,
					'output' => $output_name,
				),
				'headers' => array( __( 'Day', 'smart-search' ), $input_name, $output_name ),
				'rows'    => array_map( null, $labels, array_map( $exact_money, $input ), array_map( $exact_money, $output ) ),
			),
		);
	}

	/**
	 * Returns one page of the table of individual searches.
	 *
	 * @param array<string, mixed> $request Query parameters.
	 * @return array{rows: array<int, array<string, Figure>>, total: int, page: int, pages: int, page_args: array<string, string>, filter: array{search: string, mode: string}, modes: array<string, string>}
	 */
	private function table( DateRange $range, array $request ): array {
		$mode   = SearchMode::tryFrom( self::text( $request, 'mode' ) );
		$filter = array(
			'search' => self::text( $request, 'q' ),
			'mode'   => null === $mode ? '' : $mode->value,
		);
		$total  = $this->report->entry_count( $range, $filter );
		$pages  = max( 1, (int) ceil( $total / StatsReport::ENTRIES_PER_PAGE ) );
		$page   = min( $pages, max( 1, absint( $request['paged'] ?? 1 ) ) );

		$modes = array();

		foreach ( SearchMode::cases() as $case ) {
			$modes[ $case->value ] = $case->label();
		}

		return array(
			'rows'      => array_map( array( $this, 'table_row' ), $this->report->entries( $range, $page, $filter ) ),
			'total'     => $total,
			'page'      => $page,
			'pages'     => $pages,
			// What a link to another page of the table has to carry along.
			'page_args' => self::range_args( $range ) + array_filter(
				array(
					'q'    => $filter['search'],
					'mode' => $filter['mode'],
				)
			),
			'filter'    => $filter,
			'modes'     => $modes,
		);
	}

	/**
	 * Formats one logged search for the table and the CSV export.
	 *
	 * @param array{searched_at: string, query: string, mode: string, results: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float} $entry Log entry.
	 * @return array<string, Figure> Cells keyed like table_headers().
	 */
	private function table_row( array $entry ): array {
		$mode         = SearchMode::tryFrom( $entry['mode'] );
		$input_tokens = $entry['embedding_tokens'] + $entry['input_tokens'];
		$cost         = $entry['input_cost'] + $entry['output_cost'];
		$none         = new Figure( '–' );

		return array(
			'time'          => new Figure( get_date_from_gmt( $entry['searched_at'], get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ) ),
			'query'         => new Figure( $entry['query'] ),
			'mode'          => new Figure( null === $mode ? $entry['mode'] : $mode->label() ),
			'results'       => $this->format->number( $entry['results'] ),
			'input_tokens'  => $input_tokens > 0 ? $this->format->number( $input_tokens ) : $none,
			'output_tokens' => $entry['output_tokens'] > 0 ? $this->format->number( $entry['output_tokens'] ) : $none,
			'input_cost'    => $entry['input_cost'] > 0 ? $this->format->money( $entry['input_cost'] ) : $none,
			'output_cost'   => $entry['output_cost'] > 0 ? $this->format->money( $entry['output_cost'] ) : $none,
			'cost'          => $cost > 0 ? $this->format->money( $cost ) : $none,
		);
	}

	/**
	 * Reads a request parameter as plain text.
	 *
	 * @param array<string, mixed> $request Query parameters.
	 */
	private static function text( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}
}
