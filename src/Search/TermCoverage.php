<?php
/**
 * How much of a query a text covers.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * The share of search words found in a text.
 *
 * A database score only says which of the found passages is best, not
 * whether any of them is good: the best of three poor matches would still
 * look like a perfect one. Coverage is an absolute measure. A page that
 * contains every search word, in whatever form, covers the query completely.
 * A page that only contains a longer word made with one of them covers it partly.
 */
final class TermCoverage {

	/**
	 * What a compound counts that ends with the search word, as "Versandkosten" does for "Kosten".
	 *
	 * In German the last part says what a thing is, so this is close to a full match.
	 */
	private const ENDING_WEIGHT = 0.7;

	/**
	 * What a word counts that merely begins with the search word, as "automatic" does for "auto".
	 *
	 * More than half, because for compounds this is what is wanted: a search
	 * for "Versand" should find "Versandkosten".
	 */
	private const BEGINNING_WEIGHT = 0.6;

	/**
	 * Returns the covered share of the terms.
	 *
	 * @param string   $text  Text to look in.
	 * @param string[] $terms Lower-case search terms.
	 * @return float Between 0 and 1.
	 */
	public static function of( string $text, array $terms ): float {
		if ( array() === $terms ) {
			return 0.0;
		}

		$text    = mb_strtolower( $text );
		$covered = 0.0;

		foreach ( $terms as $term ) {
			$covered += self::weight( $text, $term );
		}

		return $covered / count( $terms );
	}

	/**
	 * Returns the patterns that find a term in running text, best kind of match first.
	 *
	 * Short terms only count as a word of their own. As a part of other
	 * words they are everywhere: "ei" (egg) begins "ein" and ends "Partei".
	 *
	 * @param string $term Lower-case search term.
	 * @return array{word: string, ending: string|null, beginning: string|null} Regular expressions without delimiters.
	 */
	public static function patterns( string $term ): array {
		$stem     = Stemmer::stem( $term );
		$length   = mb_strlen( $stem );
		$form     = $length >= IndexTerms::MIN_LENGTH ? Stemmer::forms_pattern( $stem ) : Stemmer::pattern( $stem );
		$no_start = '(?<![\p{L}\p{N}])';
		$no_end   = '(?![\p{L}\p{N}])';

		return array(
			'word'      => $no_start . $form . $no_end,
			'ending'    => $length >= IndexTerms::MIN_ENDING_LENGTH ? '[\p{L}\p{N}]+' . $form . $no_end : null,
			'beginning' => $length >= IndexTerms::MIN_LENGTH ? $no_start . Stemmer::pattern( $stem ) . '[\p{L}\p{N}]*' : null,
		);
	}

	/**
	 * Returns what one term counts in a text.
	 */
	private static function weight( string $text, string $term ): float {
		$patterns = self::patterns( $term );

		return match ( true ) {
			self::has( $text, $patterns['word'] )      => 1.0,
			self::has( $text, $patterns['ending'] )    => self::ENDING_WEIGHT,
			self::has( $text, $patterns['beginning'] ) => self::BEGINNING_WEIGHT,
			default                                    => 0.0,
		};
	}

	/**
	 * Tells whether a pattern is found in a text.
	 */
	private static function has( string $text, ?string $pattern ): bool {
		return null !== $pattern && 1 === preg_match( '/' . $pattern . '/u', $text );
	}
}
