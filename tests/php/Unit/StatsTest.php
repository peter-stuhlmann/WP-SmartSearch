<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PrstSearch\Stats\BarChart;
use PrstSearch\Stats\DateRange;
use PrstSearch\Stats\LogToken;

/**
 * Reporting periods, chart geometry and log tokens.
 */
final class StatsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_preset_covers_whole_days_up_to_today(): void {
		$range = DateRange::from_input( DateRange::WEEK, '', '', $this->now() );

		self::assertSame( '2026-10-06', $range->start->format( 'Y-m-d' ) );
		self::assertSame( '2026-10-12', $range->last_day()->format( 'Y-m-d' ) );
		self::assertCount( 7, $range->days() );
	}

	public function test_range_is_converted_to_utc_at_local_midnight(): void {
		$range = DateRange::from_input( DateRange::TODAY, '', '', $this->now() );

		// Berlin is two hours ahead of UTC in October before the clock change.
		self::assertSame( '2026-10-11 22:00:00', $range->utc_start() );
		self::assertSame( '2026-10-12 22:00:00', $range->utc_end() );
		self::assertSame( 'Europe/Berlin', $range->timezone()->getName() );
	}

	public function test_year_preset_starts_on_january_first(): void {
		$range = DateRange::from_input( DateRange::YEAR, '', '', $this->now() );

		self::assertSame( '2026-01-01', $range->start->format( 'Y-m-d' ) );
		self::assertSame( '2026-10-12', $range->last_day()->format( 'Y-m-d' ) );
	}

	public function test_custom_range_is_used_and_put_in_order(): void {
		$range = DateRange::from_input( DateRange::CUSTOM, '2026-09-20', '2026-09-01', $this->now() );

		self::assertSame( DateRange::CUSTOM, $range->preset );
		self::assertSame( '2026-09-01', $range->start->format( 'Y-m-d' ) );
		self::assertSame( '2026-09-20', $range->last_day()->format( 'Y-m-d' ) );
	}

	public function test_custom_range_cannot_reach_into_the_future_or_exceed_a_year(): void {
		$range = DateRange::from_input( DateRange::CUSTOM, '2020-01-01', '2030-01-01', $this->now() );

		self::assertSame( '2026-10-12', $range->last_day()->format( 'Y-m-d' ) );
		self::assertCount( 366, $range->days() );
	}

	public function test_invalid_input_falls_back_to_thirty_days(): void {
		foreach ( array( array( 'nonsense', '', '' ), array( DateRange::CUSTOM, '2026-02-31', 'yesterday' ) ) as $input ) {
			$range = DateRange::from_input( $input[0], $input[1], $input[2], $this->now() );

			self::assertSame( DateRange::MONTH, $range->preset );
			self::assertCount( 30, $range->days() );
		}
	}

	public function test_axis_ends_on_a_round_number(): void {
		self::assertSame( array( 0.0, 50.0, 100.0, 150.0, 200.0 ), BarChart::ticks( 187 ) );
		self::assertSame( array( 0.0, 5.0, 10.0, 15.0, 20.0 ), BarChart::ticks( 12 ) );
		self::assertSame( array( 0.0, 0.01, 0.02, 0.03, 0.04, 0.05 ), BarChart::ticks( 0.042 ) );
	}

	public function test_small_counts_get_one_tick_per_unit(): void {
		self::assertSame( array( 0, 1, 2, 3 ), BarChart::ticks( 3, true ) );
		self::assertSame( array( 0, 1 ), BarChart::ticks( 0 ) );
	}

	public function test_chart_draws_one_bar_per_value_and_escapes_text(): void {
		$svg = ( new BarChart() )->render(
			'Searches & "more"',
			array( '01.10.', '02.10.', '03.10.' ),
			array(
				array(
					'name'   => 'Searches',
					'slug'   => 'primary',
					'values' => array( 4, 0, 9 ),
				),
			),
			static fn ( float $value ): string => (string) $value,
			true
		);

		self::assertSame( 2, substr_count( $svg, '<rect ' ), 'A zero value draws no bar.' );
		self::assertStringContainsString( 'aria-label="Searches &amp; &quot;more&quot;"', $svg );
		self::assertStringContainsString( '<title>03.10. – Searches: 9</title>', $svg );
		self::assertStringContainsString( 'role="img"', $svg );
	}

	public function test_token_is_only_valid_for_its_own_entry(): void {
		Functions\when( 'wp_hash' )->alias( static fn ( string $data ): string => hash_hmac( 'sha256', $data, 'test-salt' ) );

		$token = LogToken::issue( 42 );

		self::assertSame( 42, LogToken::verify( $token ) );
		self::assertSame( 0, LogToken::verify( '43.' . explode( '.', $token )[1] ) );
		self::assertSame( 0, LogToken::verify( '42' ) );
		self::assertSame( 0, LogToken::verify( 'abc.def' ) );
		self::assertSame( '', LogToken::issue( 0 ) );
	}

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-12 15:30:00', new DateTimeZone( 'Europe/Berlin' ) );
	}

	public function test_very_small_amounts_can_be_charted(): void {
		// A handful of searches costs a fraction of a cent. That must not end in a division by zero.
		$svg = ( new BarChart() )->render(
			'Cost',
			array( '01.10.', '02.10.' ),
			array(
				array(
					'name'   => 'Cost',
					'slug'   => 'primary',
					'values' => array( 0.0000004, 0.0000012 ),
				),
			),
			static fn ( float $value ): string => (string) $value
		);

		self::assertSame( 2, substr_count( $svg, '<rect ' ) );
		self::assertDoesNotMatchRegularExpression( '/="-?(NAN|INF)/i', $svg, 'Every coordinate is a number.' );
	}

	public function test_a_chart_without_any_value_is_drawn_empty(): void {
		$svg = ( new BarChart() )->render(
			'Cost',
			array( '01.10.' ),
			array(
				array(
					'name'   => 'Cost',
					'slug'   => 'primary',
					'values' => array( 0.0 ),
				),
			),
			static fn ( float $value ): string => (string) $value
		);

		self::assertSame( 0, substr_count( $svg, '<rect ' ) );
	}
}
