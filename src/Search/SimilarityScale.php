<?php
/**
 * Interprets similarities between a query and passages.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Turns a similarity into a relevance level.
 *
 * Embedding models spread their scores differently: one rates unrelated
 * texts at 0.1, another at 0.6. The scale carries the thresholds that fit
 * the scores it is given: those of the model in use for raw similarities,
 * or one common set for similarities measured relative to the index, see
 * CenteredSimilarity.
 */
final class SimilarityScale {

	/**
	 * Creates the scale.
	 *
	 * @param float      $high    Similarity from which a match is very relevant.
	 * @param float      $medium  Similarity from which a match is relevant.
	 * @param float|null $minimum Similarity below which a passage is no result at all. By default one step below "relevant".
	 */
	public function __construct(
		private readonly float $high,
		private readonly float $medium,
		private readonly ?float $minimum = null
	) {}

	/**
	 * Classifies a similarity.
	 */
	public function relevance( float $similarity ): Relevance {
		return Relevance::from_thresholds( $similarity, $this->high, $this->medium );
	}

	/**
	 * Tells whether a similarity is too low to be a result at all.
	 *
	 * Without the cut-off a vector search would return every document for every query.
	 */
	public function is_noise( float $similarity ): bool {
		return $similarity < ( $this->minimum ?? $this->medium - ( $this->high - $this->medium ) );
	}
}
