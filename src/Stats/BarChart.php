<?php
/**
 * Renders bar charts as SVG.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

/**
 * A stacked bar chart without any JavaScript.
 *
 * Colours come from CSS: every series gets a class, so the chart follows the
 * admin theme and forced-colours mode. Each bar carries a title element,
 * which browsers show as a tooltip. The chart as a whole is one image to
 * assistive technology; the values are available in the table the view
 * prints next to it.
 */
final class BarChart {

	private const WIDTH  = 720;
	private const HEIGHT = 240;

	private const MARGIN_TOP    = 12;
	private const MARGIN_RIGHT  = 8;
	private const MARGIN_BOTTOM = 28;
	private const MARGIN_LEFT   = 56;

	private const MAX_BAR_WIDTH = 28;
	private const SEGMENT_GAP   = 2;
	private const MAX_X_LABELS  = 8;

	/**
	 * Renders the chart.
	 *
	 * @param string                                                                       $title    Accessible name of the chart.
	 * @param string[]                                                                     $labels   One label per bar.
	 * @param array<int, array{name: string, slug: string, values: array<int, int|float>}> $series   Series, stacked bottom to top.
	 * @param callable(float): string                                                      $format   Formats a value for the axis.
	 * @param bool                                                                         $integers Whether the values are counts.
	 * @param (callable(float): string)|null                                               $tooltip_format Formats a value for the tooltip of a bar, if it should differ from the axis, e.g. be unrounded.
	 */
	public function render( string $title, array $labels, array $series, callable $format, bool $integers = false, ?callable $tooltip_format = null ): string {
		$tooltip_format ??= $format;
		$count            = count( $labels );
		$plot_width       = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
		$plot_height      = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;
		$totals           = self::totals( $series, $count );
		$ticks            = self::ticks( array() === $totals ? 0.0 : (float) max( $totals ), $integers );
		// Never zero: it is the divisor of every bar height.
		$maximum   = max( PHP_FLOAT_MIN, (float) end( $ticks ) );
		$band      = $count > 0 ? $plot_width / $count : $plot_width;
		$bar_width = min( self::MAX_BAR_WIDTH, max( 2, $band * 0.7 ) );

		$svg = sprintf(
			'<svg class="prstsearch-chart__svg" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
			self::WIDTH,
			self::HEIGHT,
			self::escape( $title )
		);

		foreach ( $ticks as $tick ) {
			$y    = self::MARGIN_TOP + $plot_height - ( $tick / $maximum ) * $plot_height;
			$svg .= sprintf( '<line class="prstsearch-chart__grid" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', self::MARGIN_LEFT, self::WIDTH - self::MARGIN_RIGHT, $y, $y );
			$svg .= sprintf( '<text class="prstsearch-chart__tick" x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle">%s</text>', self::MARGIN_LEFT - 8, $y, self::escape( $format( (float) $tick ) ) );
		}

		$label_step = max( 1, (int) ceil( $count / self::MAX_X_LABELS ) );

		foreach ( $labels as $index => $label ) {
			$center = self::MARGIN_LEFT + $band * ( $index + 0.5 );
			$bottom = self::MARGIN_TOP + $plot_height;

			foreach ( $series as $one ) {
				$value = (float) ( $one['values'][ $index ] ?? 0 );

				if ( $value <= 0 ) {
					continue;
				}

				$height = ( $value / $maximum ) * $plot_height;
				$drawn  = max( 1, $height - self::SEGMENT_GAP );

				$svg .= sprintf(
					'<rect class="prstsearch-chart__bar prstsearch-chart__bar--%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2"><title>%s</title></rect>',
					self::escape( $one['slug'] ),
					$center - $bar_width / 2,
					$bottom - $height,
					$bar_width,
					$drawn,
					self::escape( $label . ' – ' . $one['name'] . ': ' . $tooltip_format( $value ) )
				);

				$bottom -= $height;
			}

			if ( 0 === $index % $label_step ) {
				$svg .= sprintf(
					'<text class="prstsearch-chart__tick" x="%.1f" y="%d" text-anchor="middle">%s</text>',
					$center,
					self::HEIGHT - 8,
					self::escape( $label )
				);
			}
		}

		return $svg . '</svg>';
	}

	/**
	 * Returns the axis ticks for a maximum value, ending on a round number.
	 *
	 * @return array<int, int|float> Ascending, starting at 0.
	 */
	public static function ticks( float $maximum, bool $integers = false ): array {
		if ( $maximum <= 0 ) {
			return array( 0, 1 );
		}

		if ( $integers && $maximum <= 4 ) {
			return range( 0, (int) ceil( $maximum ) );
		}

		$magnitude = 10 ** floor( log10( $maximum ) );
		$mantissa  = $maximum / $magnitude;

		// The axis ends at 1, 2, 5 or 10 times a power of ten.
		$nice = match ( true ) {
			$mantissa <= 1 => 1,
			$mantissa <= 2 => 2,
			$mantissa <= 5 => 5,
			default        => 10,
		};
		$steps = 2 === $nice ? 4 : 5;
		$top   = $nice * $magnitude;

		// Rounding only removes floating point noise. The precision follows the magnitude,
		// so an axis for fractions of a cent keeps its values instead of collapsing to zero.
		$precision = max( 6, 3 - (int) floor( log10( $top ) ) );

		return array_map(
			static fn ( int $step ): float => round( $top * $step / $steps, $precision ),
			range( 0, $steps )
		);
	}

	/**
	 * Sums the series per bar.
	 *
	 * @param array<int, array{name: string, slug: string, values: array<int, int|float>}> $series Series.
	 * @return float[]
	 */
	private static function totals( array $series, int $count ): array {
		$totals = array_fill( 0, $count, 0.0 );

		foreach ( $series as $one ) {
			foreach ( $totals as $index => $total ) {
				$totals[ $index ] = $total + max( 0, (float) ( $one['values'][ $index ] ?? 0 ) );
			}
		}

		return $totals;
	}

	/**
	 * Escapes text for SVG.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8' );
	}
}
