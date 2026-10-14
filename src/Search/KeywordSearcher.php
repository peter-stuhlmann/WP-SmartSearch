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
use PrstSearch\Support\Cache;

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
 *
 * A word counts as a word, in any of its forms, or as the beginning or the
 * end of a longer one. If no page has the search words that way, and the
 * setting allows it, pages are returned that have them anywhere, inside words.
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
	 * How many compounds that end with a short search word are searched for, the most frequent ones.
	 */
	private const MAX_COMPOUNDS = 50;

	/**
	 * How many of the search words are looked for inside words, the longest ones.
	 */
	private const MAX_PARTS = 3;

	/**
	 * For how long it is remembered that a search found nothing inside words either.
	 */
	private const NOTHING_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Creates the searcher.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly Settings $settings,
		private readonly CompoundEndings $endings,
		private readonly Cache $cache
	) {}

	/**
	 * Searches the index for the given terms.
	 *
	 * @param bool $may_read_all Whether text inside words may be looked for when nothing else is found, which reads the whole index.
	 * @return array<int, array{chunk_id: int, coverage: float, in_title: bool, proximity: float, score: float}> Best passage per post, keyed
	 *         by post ID, best first. The coverage is the share of search words the post contains, in whichever of its passages.
	 */
	public function search( QueryTerms $terms, int $limit, bool $may_read_all = true ): array {
		if ( $terms->is_empty() ) {
			return array();
		}

		// A short word is also searched for in the compounds the website makes with it: "Haustür" for "Tür".
		$compounds     = array_slice( $this->endings->of_website( $terms->all() ), 0, self::MAX_COMPOUNDS );
		$boolean_query = trim( $terms->to_boolean_query() . ' ' . IndexTerms::beginning_query( $compounds ) );

		/*
		 * Several passages of one post can match, so more rows than posts are needed.
		 *
		 * A query of nothing but very short words, such as "ei", has no words the
		 * index knows, so every passage is read for them.
		 */
		$rows = '' === $boolean_query
			? $this->chunks->word_search( array_map( array( Stemmer::class, 'spellings' ), $terms->all() ), $limit * 3 )
			: $this->chunks->fulltext_search( $boolean_query, $limit * 3 );

		$matches = $this->ranked( $rows, $terms, $limit, false );

		if ( array() !== $matches || ! $may_read_all || ! $this->settings->bool( 'match_inside_words' ) ) {
			return $matches;
		}

		/*
		 * Nothing has the words as words. What remains is text inside words, which no index
		 * finds: every passage has to be read, as the search of WordPress itself always does.
		 * That is also what a typing error costs, which is why it can be switched off.
		 */
		$parts = array_values( array_filter( $terms->all(), static fn ( string $term ): bool => null !== TermCoverage::inside_pattern( $term ) ) );

		usort( $parts, static fn ( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		$parts = array_slice( $parts, 0, self::MAX_PARTS );
		// The same fruitless search, by the next visitor or the next step of this one, does not read the table again.
		$nothing = $this->cache->index_key( 'nothing_inside', implode( ' ', $parts ) );

		if ( array() === $parts || null !== $this->cache->get( $nothing ) ) {
			return array();
		}

		$matches = $this->ranked( $this->chunks->part_search( $parts, $limit * 3 ), $terms, $limit, true );

		if ( array() === $matches ) {
			$this->cache->set( $nothing, 1, self::NOTHING_TTL );
		}

		return $matches;
	}

	/**
	 * Judges the rows the database returned and puts the posts they belong to in order.
	 *
	 * @param array<int, array{id: int, post_id: int, title: string, content: string, score: float}> $rows Rows, best first.
	 * @param bool                                                                                   $anywhere Whether a term also counts where it stands inside a word.
	 * @return array<int, array{chunk_id: int, coverage: float, in_title: bool, proximity: float, score: float}> Matches by post ID, best first.
	 */
	private function ranked( array $rows, QueryTerms $terms, int $limit, bool $anywhere ): array {
		$compounds = $this->compounds( array_merge( array_column( $rows, 'title' ), array_column( $rows, 'content' ) ), $terms );

		$matches = array();
		$found   = array();

		foreach ( $this->judge( $rows, $terms, $compounds, $anywhere ) as $row ) {
			// The database decides roughly what a word is. A row without a single real match is none.
			if ( 0.0 === $row['coverage'] ) {
				continue;
			}

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

		foreach ( $this->post_coverage( $matches, $found, $terms, $compounds, $anywhere ) as $post_id => $coverage ) {
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
	 * @param array<int, array<string, mixed>> $matches   Matches by post ID, best first.
	 * @param array<int, string>               $found     Title and matching passages by post ID.
	 * @param array<string, string[]>          $compounds Compounds of the matching passages that end with a short term.
	 * @param bool                             $anywhere  Whether a term also counts where it stands inside a word.
	 * @return array<int, float> Coverage by post ID.
	 */
	private function post_coverage( array $matches, array $found, QueryTerms $terms, array $compounds, bool $anywhere ): array {
		$coverage = array();

		foreach ( array_keys( $matches ) as $post_id ) {
			$coverage[ $post_id ] = TermCoverage::of( $found[ $post_id ], $terms->all(), $compounds, $anywhere );
		}

		$incomplete = array_keys(
			array_filter(
				array_slice( $coverage, 0, self::POSTS_READ_IN_FULL, true ),
				static fn ( float $share ): bool => $share < 1.0
			)
		);

		$texts = $this->chunks->post_texts( $incomplete );
		// The rest of a page can hold compounds its matching passages do not.
		foreach ( $this->compounds( $texts, $terms ) as $term => $words ) {
			$compounds[ $term ] = array_values( array_unique( array_merge( $compounds[ $term ] ?? array(), $words ) ) );
		}

		foreach ( $texts as $post_id => $text ) {
			$coverage[ $post_id ] = max( $coverage[ $post_id ], TermCoverage::of( $text, $terms->all(), $compounds, $anywhere ) );
		}

		return $coverage;
	}

	/**
	 * Finds the compounds in the given texts that count for a short search word, as "Haustür" does for "Tür".
	 *
	 * @param string[] $texts Texts of the candidates.
	 * @return array<string, string[]> Compounds by term.
	 */
	private function compounds( array $texts, QueryTerms $terms ): array {
		return array() === $texts ? array() : $this->endings->in( implode( "\n", $texts ), $terms->all() );
	}

	/**
	 * Adds coverage, proximity and title information to the rows and ranks titles higher, if configured.
	 *
	 * @param array<int, array{id: int, post_id: int, title: string, content: string, score: float}> $rows Matches, best first.
	 * @param array<string, string[]>                                                                $compounds Compounds that end with a short term.
	 * @param bool                                                                                   $anywhere Whether a term also counts where it stands inside a word.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, coverage: float, proximity: float, in_title: bool, score: float}> Matches, best first.
	 */
	private function judge( array $rows, QueryTerms $terms, array $compounds, bool $anywhere ): array {
		$boost  = $this->settings->bool( 'boost_title' ) ? self::TITLE_BOOST : 1.0;
		$judged = array();

		foreach ( $rows as $row ) {
			$in_title = TermCoverage::of( $row['title'], $terms->all(), $compounds, $anywhere ) > 0;
			// Title and text are two blocks of the page: a word in each does not make them neighbours.
			$text = $row['title'] . "\n\n" . $row['content'];

			$judged[] = array(
				'id'        => $row['id'],
				'post_id'   => $row['post_id'],
				'title'     => $row['title'],
				'content'   => $row['content'],
				'coverage'  => TermCoverage::of( $text, $terms->all(), $compounds, $anywhere ),
				'proximity' => max( Proximity::of( $row['title'], $terms->all(), $compounds ), Proximity::of( $row['content'], $terms->all(), $compounds ) ),
				'in_title'  => $in_title,
				'score'     => $in_title ? $row['score'] * $boost : $row['score'],
			);
		}

		// usort() is stable, so equal scores keep the order of the database.
		usort( $judged, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $judged;
	}
}
