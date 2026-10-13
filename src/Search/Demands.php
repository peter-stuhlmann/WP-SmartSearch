<?php
/**
 * What a search phrase demands of its results.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Database\ChunkStore;

/**
 * Enforces exact phrases and excluded words, see QueryTerms.
 *
 * These are conditions, not preferences: a page without the quoted phrase is
 * no result, however well it matches otherwise. They apply to the page as a
 * whole, so the whole text of each hit is read. That is only done for
 * queries that use the notation.
 */
final class Demands {

	/**
	 * Creates the filter.
	 */
	public function __construct( private readonly ChunkStore $chunks ) {}

	/**
	 * Returns the hits that meet the demands of the query.
	 *
	 * @param SearchHit[] $hits Hits, best first.
	 * @return SearchHit[]
	 */
	public function met_by( array $hits, QueryTerms $terms ): array {
		if ( ! $terms->has_demands() || array() === $hits ) {
			return $hits;
		}

		$texts = $this->chunks->post_texts( array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $hits ) );

		return array_values(
			array_filter(
				$hits,
				static fn ( SearchHit $hit ): bool => self::meets( $texts[ $hit->post_id ] ?? '', $terms )
			)
		);
	}

	/**
	 * Tells whether a text contains every demanded phrase and none of the excluded words.
	 */
	private static function meets( string $text, QueryTerms $terms ): bool {
		// A phrase may run across a line break of the indexed text.
		$text = mb_strtolower( (string) preg_replace( '/\s+/u', ' ', $text ) );

		foreach ( $terms->phrases() as $phrase ) {
			if ( ! str_contains( $text, $phrase ) ) {
				return false;
			}
		}

		foreach ( $terms->excluded_phrases() as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return false;
			}
		}

		foreach ( $terms->excluded() as $word ) {
			// In any of its forms: "-vegan" also excludes "vegane".
			if ( 1 === preg_match( '/' . TermCoverage::patterns( $word )['word'] . '/u', $text ) ) {
				return false;
			}
		}

		return true;
	}
}
