<?php
/**
 * Decides which hits may be shown.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Index\Eligibility;
use WP_Post;

/**
 * The one place hits pass before they are returned, wherever they came from.
 *
 * Two things happen here. First, every hit is checked against the rules for
 * what is searchable. The index is normally in sync with them, but a post
 * that was unpublished while the plugin was inactive, or by code that
 * bypasses the usual hooks, would otherwise still be found. Second, other
 * code gets its say, for example to limit results to a language.
 */
final class HitFilter {

	/**
	 * Creates the filter.
	 */
	public function __construct( private readonly Eligibility $eligibility ) {}

	/**
	 * Returns the hits that may be shown to the current visitor.
	 *
	 * @param SearchHit[] $hits Hits, best first.
	 * @return SearchHit[]
	 */
	public function apply( array $hits ): array {
		// One query each for all posts, their meta and their terms instead of one per hit. The terms are
		// what a multilingual plugin reads the language of a post from, and it does so for every single hit.
		_prime_post_caches( array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $hits ), true, true );

		$searchable = array_values(
			array_filter(
				$hits,
				function ( SearchHit $hit ): bool {
					$post = get_post( $hit->post_id );

					return $post instanceof WP_Post && $this->eligibility->is_indexable( $post );
				}
			)
		);

		/**
		 * Filters the hits of a query, e.g. to limit them to a language or to hide content from certain visitors.
		 *
		 * Applies to results, suggestions and the sources of AI answers alike.
		 *
		 * @param SearchHit[] $hits Hits, best first.
		 */
		$filtered = apply_filters( 'prstsearch_hits', $searchable );

		if ( ! is_array( $filtered ) ) {
			return $searchable;
		}

		return array_values( array_filter( $filtered, static fn ( $hit ): bool => $hit instanceof SearchHit ) );
	}

	/**
	 * Returns the posts among the given ones that may be shown to the current visitor.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int[] In the given order.
	 */
	public function allowed_posts( array $post_ids ): array {
		$as_hits = array_map(
			static fn ( int $post_id ): SearchHit => new SearchHit( $post_id, 0, Relevance::Medium, 0.0, false, false, false ),
			$post_ids
		);

		return array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $this->apply( $as_hits ) );
	}
}
