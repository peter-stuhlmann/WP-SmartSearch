<?php
/**
 * The complete, ordered result of a query.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Provider\TokenUsage;

/**
 * All hits of a query before pagination, plus how they were found.
 */
final class Ranking {

	/**
	 * Creates the ranking.
	 *
	 * @param SearchHit[] $hits     Hits, best first.
	 * @param QueryTerms  $terms    The words of the query.
	 * @param SearchMode  $mode     The mode that actually produced the hits.
	 * @param bool        $degraded Whether semantic search was wanted but not available.
	 * @param TokenUsage  $usage    Tokens used to answer the query.
	 * @param string      $corrected_query The query that was searched instead because the original one looked misspelled, or an empty string.
	 */
	public function __construct(
		public readonly array $hits,
		public readonly QueryTerms $terms,
		public readonly SearchMode $mode,
		public readonly bool $degraded,
		public readonly TokenUsage $usage,
		public readonly string $corrected_query = ''
	) {}

	/**
	 * Tells whether at least one hit reaches the given relevance level.
	 */
	public function has_hit_of( Relevance $level ): bool {
		foreach ( $this->hits as $hit ) {
			if ( $hit->relevance->weight() >= $level->weight() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a copy with other hits and additional usage.
	 *
	 * @param SearchHit[] $hits  Hits, best first.
	 * @param TokenUsage  $extra Tokens used on top of what this ranking already cost.
	 */
	public function with( array $hits, TokenUsage $extra ): self {
		return new self( $hits, $this->terms, $this->mode, $this->degraded, $this->usage->plus( $extra ), $this->corrected_query );
	}

	/**
	 * Orders hits by relevance level. Within a level the given order is kept.
	 *
	 * @param SearchHit[] $hits Hits.
	 * @return SearchHit[]
	 */
	public static function by_relevance( array $hits ): array {
		$hits = array_values( $hits );

		// usort() is stable since PHP 8.0.
		usort( $hits, static fn ( SearchHit $a, SearchHit $b ): int => $b->relevance->weight() <=> $a->relevance->weight() );

		return $hits;
	}
}
