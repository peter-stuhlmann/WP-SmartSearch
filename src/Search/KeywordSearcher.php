<?php
/**
 * Keyword search on the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Settings\Settings;

/**
 * Finds posts by the words they contain.
 *
 * The database finds and pre-sorts the candidates. How good a candidate is,
 * is then judged by how many of the search words it really contains.
 *
 * What is judged is the post, not the passage. The index stores long pages in
 * passages, but somebody searching for "chicken recipe" expects the page that
 * has both words, wherever on the page they are.
 *
 * The order follows what a searcher would call the better match: first the
 * pages with more of the words, among those the ones where the words stand
 * together, then the score of the database, and at last the newer page.
 */
final class KeywordSearcher {

	/**
	 * Factor applied to the score of a passage whose post title contains a search term.
	 */
	private const TITLE_BOOST = 1.5;

	/**
	 * For how many of the best matches the whole text is read to judge them.
	 *
	 * Reading it costs a query and memory, so the long tail of a result list
	 * is judged by the passages the database returned for it.
	 */
	private const POSTS_READ_IN_FULL = 50;

	/**
	 * Creates the searcher.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly Settings $settings
	) {}

	/**
	 * Searches the index for the given terms.
	 *
	 * @return array<int, array{chunk_id: int, coverage: float, in_title: bool, proximity: float, score: float}> Best passage per post, keyed
	 *         by post ID, best first. The coverage is the share of search words the post contains, in whichever of its passages.
	 */
	public function search( QueryTerms $terms, int $limit ): array {
		if ( $terms->is_empty() ) {
			return array();
		}

		$boolean_query = $terms->to_boolean_query();

		// Several passages of one post can match, so more rows than posts are needed.
		$rows = '' === $boolean_query ? array() : $this->chunks->fulltext_search( $boolean_query, $limit * 3 );

		if ( array() === $rows ) {
			// Short words and stop words are not in the FULLTEXT index.
			$rows = $this->chunks->like_search( $terms->all(), $limit * 3 );
		}

		$matches = array();
		$found   = array();

		foreach ( $this->judge( $rows, $terms ) as $row ) {
			$known = $matches[ $row['post_id'] ] ?? null;

			$found[ $row['post_id'] ] = ( $found[ $row['post_id'] ] ?? $row['title'] ) . "\n" . $row['content'];

			// The first passage of a post is its best by rank. A later one only wins if it has more of
			// the words, or has them closer together. It is the passage the excerpt is taken from.
			if ( null !== $known && array( $row['coverage'], $row['proximity'] ) <= array( $known['coverage'], $known['proximity'] ) ) {
				continue;
			}

			$matches[ $row['post_id'] ] = array(
				'chunk_id'  => $row['id'],
				'coverage'  => $row['coverage'],
				'in_title'  => $row['in_title'] || ( $known['in_title'] ?? false ),
				'proximity' => $row['proximity'],
				// The best score of the post, which is that of its first passage.
				'score'     => $known['score'] ?? $row['score'],
			);
		}

		$matches = array_slice( $matches, 0, $limit, true );

		foreach ( $this->post_coverage( $matches, $found, $terms ) as $post_id => $coverage ) {
			$matches[ $post_id ]['coverage'] = $coverage;
		}

		return self::best_first( $matches );
	}

	/**
	 * Puts the matches in the order a searcher expects.
	 *
	 * @param array<int, array{chunk_id: int, coverage: float, in_title: bool, proximity: float, score: float}> $matches Matches by post ID.
	 * @return array<int, array{chunk_id: int, coverage: float, in_title: bool, proximity: float, score: float}>
	 */
	private static function best_first( array $matches ): array {
		$post_ids = array_keys( $matches );

		// One query for all posts instead of one per post.
		_prime_post_caches( $post_ids, false, false );

		$dates = array();

		foreach ( $post_ids as $post_id ) {
			$dates[ $post_id ] = (string) ( get_post( $post_id )->post_date_gmt ?? '' );
		}

		uksort(
			$matches,
			static fn ( int $a, int $b ): int => array( $matches[ $b ]['coverage'], $matches[ $b ]['proximity'], $matches[ $b ]['score'], $dates[ $b ] )
				<=> array( $matches[ $a ]['coverage'], $matches[ $a ]['proximity'], $matches[ $a ]['score'], $dates[ $a ] )
		);

		return $matches;
	}

	/**
	 * Returns the share of search words each post contains, across all of its passages.
	 *
	 * The passages the database returned are the ones that matched. Words too
	 * short for the FULLTEXT index, such as "ei", are in none of them unless
	 * they happen to share a passage with a longer word. So for the best
	 * matches that still lack a word, the whole text of the post is read.
	 *
	 * @param array<int, array<string, mixed>> $matches Matches by post ID, best first.
	 * @param array<int, string>               $found   Title and matching passages by post ID.
	 * @return array<int, float> Coverage by post ID.
	 */
	private function post_coverage( array $matches, array $found, QueryTerms $terms ): array {
		$coverage = array();

		foreach ( array_keys( $matches ) as $post_id ) {
			$coverage[ $post_id ] = TermCoverage::of( $found[ $post_id ], $terms->all() );
		}

		$incomplete = array_keys(
			array_filter(
				array_slice( $coverage, 0, self::POSTS_READ_IN_FULL, true ),
				static fn ( float $share ): bool => $share < 1.0
			)
		);

		foreach ( $this->chunks->post_texts( $incomplete ) as $post_id => $text ) {
			$coverage[ $post_id ] = max( $coverage[ $post_id ], TermCoverage::of( $text, $terms->all() ) );
		}

		return $coverage;
	}

	/**
	 * Adds coverage, proximity and title information to the rows and ranks titles higher, if configured.
	 *
	 * @param array<int, array{id: int, post_id: int, title: string, content: string, score: float}> $rows Matches, best first.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, coverage: float, proximity: float, in_title: bool, score: float}> Matches, best first.
	 */
	private function judge( array $rows, QueryTerms $terms ): array {
		$boost  = $this->settings->bool( 'boost_title' ) ? self::TITLE_BOOST : 1.0;
		$judged = array();

		foreach ( $rows as $row ) {
			$in_title = TermCoverage::of( $row['title'], $terms->all() ) > 0;
			// Title and text are two blocks of the page: a word in each does not make them neighbours.
			$text = $row['title'] . "\n\n" . $row['content'];

			$judged[] = array(
				'id'        => $row['id'],
				'post_id'   => $row['post_id'],
				'title'     => $row['title'],
				'content'   => $row['content'],
				'coverage'  => TermCoverage::of( $text, $terms->all() ),
				'proximity' => max( Proximity::of( $row['title'], $terms->all() ), Proximity::of( $row['content'], $terms->all() ) ),
				'in_title'  => $in_title,
				'score'     => $in_title ? $row['score'] * $boost : $row['score'],
			);
		}

		// usort() is stable, so equal scores keep the order of the database.
		usort( $judged, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $judged;
	}
}
