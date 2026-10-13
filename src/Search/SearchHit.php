<?php
/**
 * One search result.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * A post found by the search, with the passage that matched best.
 */
final class SearchHit {

	/**
	 * Creates the hit.
	 *
	 * @param int       $post_id    The post.
	 * @param int       $chunk_id   The best matching passage.
	 * @param Relevance $relevance  Relevance level shown to visitors.
	 * @param float     $score      Raw value behind the level: the similarity for semantic hits, otherwise the share of search words found.
	 * @param bool      $by_keyword Whether the post contains the search words.
	 * @param bool      $by_meaning Whether the post was found by semantic similarity.
	 * @param bool      $in_title   Whether a search word appears in the title.
	 * @param bool      $by_ai      Whether the language model named the post as a fit, see SecondOpinion.
	 * @param bool      $is_recommended Whether the website recommends the post for this search, see Recommendations.
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly int $chunk_id,
		public readonly Relevance $relevance,
		public readonly float $score,
		public readonly bool $by_keyword,
		public readonly bool $by_meaning,
		public readonly bool $in_title,
		public readonly bool $by_ai = false,
		public readonly bool $is_recommended = false
	) {}

	/**
	 * Explains in one sentence why the post was found.
	 */
	public function explanation(): string {
		return match ( true ) {
			$this->is_recommended                    => __( 'Recommended for this search in the settings.', 'smart-search' ),
			$this->by_ai                             => __( 'The language model considers this page a fit for the search.', 'smart-search' ),
			$this->in_title                          => __( 'A search word is in the title.', 'smart-search' ),
			$this->by_keyword && $this->by_meaning   => __( 'Contains the search words and matches the meaning.', 'smart-search' ),
			$this->by_keyword                        => __( 'Contains the search words.', 'smart-search' ),
			default                                  => __( 'Matches the meaning; the search words do not appear literally.', 'smart-search' ),
		};
	}
}
