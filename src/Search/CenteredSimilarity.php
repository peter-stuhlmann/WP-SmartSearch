<?php
/**
 * Similarity between a query and passages, relative to the whole index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Cosine similarity after removing what all passages have in common.
 *
 * Embedding models place every text in a narrow cone: two unrelated texts
 * still score 0.4 with one model and 0.7 with another, and real matches sit
 * only slightly above that. Subtracting the mean vector of the index from
 * query and passage removes the shared part. What remains is close to 0 for
 * unrelated texts and clearly positive for related ones, whatever the model.
 *
 * The score is computed without building the centred vectors: with unit
 * vectors q (query) and d (passage) and the mean m,
 * (q−m)·(d−m) = q·d − q·m − d·m + m·m and |d−m|² = 1 − 2·d·m + m·m.
 */
final class CenteredSimilarity {

	/**
	 * Passages shorter than this many characters count less, see length_weight().
	 */
	private const FULL_WEIGHT_LENGTH = 60;

	/**
	 * Dot product of query and mean.
	 */
	private float $query_dot_mean;

	/**
	 * Squared length of the mean.
	 */
	private float $mean_dot_mean;

	/**
	 * Length of the centred query.
	 */
	private float $query_length;

	/**
	 * Creates the calculation for one query.
	 *
	 * @param float[] $query Unit vector of the query.
	 * @param float[] $mean  Mean of all passage vectors of the index.
	 */
	public function __construct(
		private readonly array $query,
		private readonly array $mean
	) {
		$this->query_dot_mean = Vector::dot( $query, $mean );
		$this->mean_dot_mean  = Vector::dot( $mean, $mean );
		$this->query_length   = sqrt( max( 0.0, 1 - 2 * $this->query_dot_mean + $this->mean_dot_mean ) );
	}

	/**
	 * Returns the centred cosine similarity of the query and a passage.
	 *
	 * @param float[] $passage Unit vector of the passage.
	 */
	public function score( array $passage ): float {
		$query_dot_passage = 0.0;
		$passage_dot_mean  = 0.0;

		// One loop for both dot products: this runs for every passage of the index.
		foreach ( $passage as $index => $value ) {
			$query_dot_passage += $value * ( $this->query[ $index ] ?? 0.0 );
			$passage_dot_mean  += $value * ( $this->mean[ $index ] ?? 0.0 );
		}

		$passage_length = sqrt( max( 0.0, 1 - 2 * $passage_dot_mean + $this->mean_dot_mean ) );
		$denominator    = $this->query_length * $passage_length;

		// A text identical to the mean has no direction of its own.
		if ( $denominator < 1e-9 ) {
			return 0.0;
		}

		return ( $query_dot_passage - $this->query_dot_mean - $passage_dot_mean + $this->mean_dot_mean ) / $denominator;
	}

	/**
	 * Returns the factor by which the score of a short passage is reduced.
	 *
	 * A page that consists of little more than its title says almost nothing,
	 * so its vector resembles every query a little. Without the weight such
	 * pages would rank at the top for queries nothing really matches.
	 *
	 * @param int $length Number of characters of the passage.
	 * @return float Between 0 and 1.
	 */
	public static function length_weight( int $length ): float {
		return min( 1.0, sqrt( max( 0, $length ) / self::FULL_WEIGHT_LENGTH ) );
	}

	/**
	 * Returns the mean of a set of vectors.
	 *
	 * @param iterable<float[]> $vectors Vectors of equal length.
	 * @return float[] Empty if there are no vectors.
	 */
	public static function mean( iterable $vectors ): array {
		$sum   = array();
		$count = 0;

		foreach ( $vectors as $vector ) {
			foreach ( $vector as $index => $value ) {
				$sum[ $index ] = ( $sum[ $index ] ?? 0.0 ) + $value;
			}

			++$count;
		}

		return array_map( static fn ( float $value ): float => $value / $count, $sum );
	}
}
