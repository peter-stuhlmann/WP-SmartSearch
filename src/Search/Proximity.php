<?php
/**
 * How close together the search words stand in a text.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Measures whether the words of a query appear as a group.
 *
 * Somebody searching for "chicken recipe" means the two together. A page
 * that says "chicken recipe" answers that better than a page that mentions
 * chicken at the top and a recipe for bread at the bottom, although both
 * contain both words.
 */
final class Proximity {

	/**
	 * Characters between the words up to which they still count as standing together.
	 * Roughly a sentence: beyond that they are just two words on the same page.
	 */
	private const REACH = 100;

	/**
	 * Characters allowed between two neighbouring words, for a space or a hyphen.
	 */
	private const JOINT = 2;

	/**
	 * Returns how close together the terms stand.
	 *
	 * @param string                  $text      Text to look in.
	 * @param string[]                $terms     Lower-case search terms.
	 * @param array<string, string[]> $compounds Compounds that count as ending with a short term, see CompoundEndings.
	 * @return float 1 if the terms found stand next to each other, in whatever order, falling to 0 the further
	 *               they are apart. 0 if fewer than two of the terms occur: one word alone is not a group.
	 */
	public static function of( string $text, array $terms, array $compounds = array() ): float {
		$occurrences = array();
		$text        = mb_strtolower( $text );

		$found = 0;

		foreach ( $terms as $term ) {
			$places = self::occurrences( $text, $term, $compounds[ $term ] ?? array() );

			// The terms that occur are numbered without gaps, whichever of the query they are.
			foreach ( $places as $start => $end ) {
				$occurrences[] = array( $start, $end, $found );
			}

			$found += array() === $places ? 0 : 1;
		}

		if ( $found < 2 ) {
			return 0.0;
		}

		$gap = self::smallest_gap( $occurrences, $found );

		return max( 0.0, 1.0 - max( 0, $gap - self::JOINT * ( $found - 1 ) ) / self::REACH );
	}

	/**
	 * Finds where a term occurs, in any of the forms that count as a match.
	 *
	 * @param string[] $compounds Words that count as ending with the term.
	 * @return array<int, int> End positions keyed by start position, in characters.
	 */
	private static function occurrences( string $text, string $term, array $compounds ): array {
		$pattern = implode( '|', array_filter( TermCoverage::patterns( $term, $compounds ) ) );

		if ( false === preg_match_all( '/' . $pattern . '/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$occurrences = array();

		foreach ( $matches[0] as $match ) {
			// The offset is in bytes. An umlaut has two of them and must not count as a wider gap.
			$start = mb_strlen( substr( $text, 0, $match[1] ) );

			$occurrences[ $start ] = $start + mb_strlen( $match[0] );
		}

		return $occurrences;
	}

	/**
	 * Returns the fewest characters that lie between the words of a group containing every found term once.
	 *
	 * Moves a window over the occurrences in reading order and narrows it
	 * from the left as long as it still holds all terms.
	 *
	 * @param array<int, array{0: int, 1: int, 2: int}> $occurrences Start, end and number of the term.
	 * @param int                                       $terms       Number of different terms among them.
	 */
	private static function smallest_gap( array $occurrences, int $terms ): int {
		usort( $occurrences, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );

		$smallest = PHP_INT_MAX;
		$left     = 0;
		// How often each term is in the window, and how many different terms that makes.
		$inside   = array_fill( 0, $terms, 0 );
		$distinct = 0;

		foreach ( $occurrences as $right => $occurrence ) {
			$distinct += 0 === $inside[ $occurrence[2] ]++ ? 1 : 0;

			while ( $distinct === $terms ) {
				$words    = array_slice( $occurrences, $left, $right - $left + 1 );
				$span     = max( array_column( $words, 1 ) ) - $words[0][0];
				$letters  = array_sum( array_map( static fn ( array $word ): int => $word[1] - $word[0], self::one_per_term( $words ) ) );
				$smallest = min( $smallest, max( 0, $span - $letters ) );

				$distinct -= 0 === --$inside[ $occurrences[ $left ][2] ] ? 1 : 0;

				++$left;
			}
		}

		return $smallest;
	}

	/**
	 * Keeps the last occurrence of each term in a window, which is the one nearest to its right edge.
	 *
	 * @param array<int, array{0: int, 1: int, 2: int}> $words Occurrences in reading order.
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private static function one_per_term( array $words ): array {
		$kept = array();

		foreach ( $words as $word ) {
			$kept[ $word[2] ] = $word;
		}

		return $kept;
	}
}
