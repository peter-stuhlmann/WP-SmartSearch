<?php
/**
 * Relevance levels shown to visitors.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * A coarse relevance level.
 *
 * Similarity scores are not percentages: 0.45 can be an excellent match.
 * Three levels are honest about that, the raw value stays available for
 * people who want to tune the search.
 */
enum Relevance: string {

	case High   = 'high';
	case Medium = 'medium';
	case Low    = 'low';

	/**
	 * Thresholds for the share of search words a post contains, see TermCoverage.
	 *
	 * A single search word that only matches the beginning of a longer word
	 * (0.6) is still "relevant"; two of three words found exactly (0.67) are too.
	 */
	private const KEYWORD_HIGH   = 0.75;
	private const KEYWORD_MEDIUM = 0.55;

	/**
	 * Classifies a keyword match by the share of search words the post contains.
	 */
	public static function from_keyword_coverage( float $coverage ): self {
		return self::from_thresholds( $coverage, self::KEYWORD_HIGH, self::KEYWORD_MEDIUM );
	}

	/**
	 * Returns the better of two levels.
	 */
	public function or_better( self $other ): self {
		return $this->weight() >= $other->weight() ? $this : $other;
	}

	/**
	 * Returns a numeric weight for comparisons, higher is better.
	 */
	public function weight(): int {
		return match ( $this ) {
			self::High   => 3,
			self::Medium => 2,
			self::Low    => 1,
		};
	}

	/**
	 * Returns the translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::High   => __( 'Very relevant', 'smart-search' ),
			self::Medium => __( 'Relevant', 'smart-search' ),
			self::Low    => __( 'Weak match', 'smart-search' ),
		};
	}

	/**
	 * Maps a value to a level.
	 */
	public static function from_thresholds( float $value, float $high, float $medium ): self {
		if ( $value >= $high ) {
			return self::High;
		}

		return $value >= $medium ? self::Medium : self::Low;
	}
}
