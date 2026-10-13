<?php
/**
 * Improves a ranking that lacks a clear match.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Provider\ProviderException;

/**
 * Lets the language model have a look when the search itself is unsure.
 *
 * It is only consulted if the search found nothing relevant. A visitor who
 * already has good results should not wait for the model, and a visitor who
 * has none gains most from it. Pages it names are added as "relevant", and
 * weak hits it confirms move up to "relevant". Hits the search already found
 * are never removed or downgraded.
 */
final class SecondOpinion {

	/**
	 * How many of the nearest pages the model gets to choose from.
	 */
	private const CANDIDATES = 10;

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly SemanticSearcher $semantic,
		private readonly ResultJudge $judge,
		private readonly HitFilter $filter
	) {}

	/**
	 * Returns the ranking, extended by what the language model considers a fit.
	 *
	 * @param Ranking $ranking The ranking of the search.
	 * @param string  $query   The phrase that was searched for.
	 */
	public function refine( Ranking $ranking, string $query ): Ranking {
		if ( ! $this->is_wanted( $ranking ) ) {
			return $ranking;
		}

		try {
			$candidates = $this->allowed( $this->semantic->candidates( $query, self::CANDIDATES * 3 ) );
		} catch ( ProviderException ) {
			return $ranking;
		}

		$fitting = $this->judge->fitting_posts(
			$query,
			array_map( static fn ( array $candidate ): int => $candidate['chunk_id'], $candidates )
		);

		if ( array() === $fitting ) {
			return $ranking;
		}

		return $ranking->with( self::merge( $ranking->hits, $fitting, $candidates ), $this->judge->last_usage() );
	}

	/**
	 * Tells whether the model would be asked about a ranking.
	 */
	public function is_wanted( Ranking $ranking ): bool {
		// Without a working semantic search there are no candidates to offer. And pages the model
		// names are not checked against quoted phrases or excluded words, so then it is not asked.
		return SearchMode::Keyword !== $ranking->mode
			&& ! $ranking->has_hit_of( Relevance::Medium )
			&& ! $ranking->terms->is_empty()
			&& ! $ranking->terms->has_demands()
			&& $this->judge->is_enabled();
	}

	/**
	 * Keeps the candidates that may be shown to this visitor, e.g. those in the current language.
	 *
	 * Filtering before the model is asked keeps pages out of the prompt that could not become results anyway.
	 *
	 * @param array<int, array{chunk_id: int, similarity: float}> $candidates Candidates keyed by post ID, best first.
	 * @return array<int, array{chunk_id: int, similarity: float}> At most as many as the model is offered.
	 */
	private function allowed( array $candidates ): array {
		$as_hits = array();

		foreach ( $candidates as $post_id => $candidate ) {
			$as_hits[] = new SearchHit( $post_id, $candidate['chunk_id'], Relevance::Medium, $candidate['similarity'], false, true, false, true );
		}

		$allowed = array();

		foreach ( array_slice( $this->filter->apply( $as_hits ), 0, self::CANDIDATES ) as $hit ) {
			$allowed[ $hit->post_id ] = $candidates[ $hit->post_id ];
		}

		return $allowed;
	}

	/**
	 * Adds the fitting posts to the hits and lifts weak hits the model confirmed.
	 *
	 * @param SearchHit[]                                         $hits       Hits of the search, best first.
	 * @param int[]                                               $fitting    Posts the model named.
	 * @param array<int, array{chunk_id: int, similarity: float}> $candidates Candidates keyed by post ID.
	 * @return SearchHit[] Best first.
	 */
	private static function merge( array $hits, array $fitting, array $candidates ): array {
		$merged = array();

		foreach ( $hits as $hit ) {
			$is_confirmed_weak_hit = Relevance::Low === $hit->relevance && in_array( $hit->post_id, $fitting, true );

			$merged[ $hit->post_id ] = $is_confirmed_weak_hit
				? new SearchHit( $hit->post_id, $hit->chunk_id, Relevance::Medium, $hit->score, $hit->by_keyword, $hit->by_meaning, $hit->in_title, true )
				: $hit;
		}

		foreach ( $fitting as $post_id ) {
			if ( ! isset( $merged[ $post_id ] ) ) {
				$merged[ $post_id ] = new SearchHit( $post_id, $candidates[ $post_id ]['chunk_id'], Relevance::Medium, $candidates[ $post_id ]['similarity'], false, true, false, true );
			}
		}

		// Within a level the existing order is kept, new hits follow.
		return Ranking::by_relevance( $merged );
	}
}
