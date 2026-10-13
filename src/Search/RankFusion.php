<?php
/**
 * Combines several rankings into one.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Reciprocal rank fusion.
 *
 * Keyword and vector scores live on different scales and cannot be added.
 * Fusing by rank needs no calibration and rewards results both methods agree on.
 */
final class RankFusion {

	/**
	 * Dampens the influence of top ranks. 60 is the value from the original paper.
	 */
	private const K = 60;

	/**
	 * Fuses rankings of IDs.
	 *
	 * @param array<int, int[]> $rankings Lists of IDs, each ordered best first.
	 * @return int[] IDs ordered by fused score, best first.
	 */
	public static function fuse( array $rankings ): array {
		$scores = array();

		foreach ( $rankings as $ranking ) {
			foreach ( array_values( $ranking ) as $rank => $id ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + 1 / ( self::K + $rank + 1 );
			}
		}

		// Stable sort: on equal score the ID seen first stays in front.
		$order = array_flip( array_keys( $scores ) );

		uksort(
			$scores,
			static fn ( int $a, int $b ): int => array( $scores[ $b ], $order[ $a ] ) <=> array( $scores[ $a ], $order[ $b ] )
		);

		return array_keys( $scores );
	}
}
