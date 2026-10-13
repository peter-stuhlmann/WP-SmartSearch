<?php
/**
 * Builds result excerpts.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Cuts a passage around the first search term and marks the terms.
 *
 * A term is found in all the forms the search finds it in: "Rezept" marks
 * "Rezepte", "Kosten" marks "Versandkosten".
 */
final class Excerpt {

	private const ELLIPSIS = '…';

	/**
	 * Words taken before and after a match for the link to the passage.
	 */
	private const FRAGMENT_WORDS_BEFORE = 2;
	private const FRAGMENT_WORDS_AFTER  = 4;

	/**
	 * Returns a plain text passage of roughly the given length.
	 *
	 * @param string[] $terms Search terms.
	 */
	public static function around( string $text, array $terms, int $length = 220 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$position = self::first_match( $text, $terms ) ?? 0;
		$start    = max( 0, $position - (int) ( $length / 3 ) );
		$start    = min( $start, mb_strlen( $text ) - $length );
		$passage  = mb_substr( $text, $start, $length );

		// Do not start or end in the middle of a word.
		if ( $start > 0 ) {
			$passage = self::ELLIPSIS . ltrim( (string) preg_replace( '/^\S*\s/u', '', $passage, 1 ) );
		}

		if ( $start + $length < mb_strlen( $text ) ) {
			$passage = rtrim( (string) preg_replace( '/\s\S*$/u', '', $passage, 1 ) ) . self::ELLIPSIS;
		}

		return $passage;
	}

	/**
	 * Escapes a text for HTML and wraps the search terms in mark elements.
	 *
	 * @param string[] $terms Search terms.
	 * @return string Safe HTML.
	 */
	public static function highlight( string $text, array $terms ): string {
		$escape  = static fn ( string $piece ): string => htmlspecialchars( $piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$pattern = self::pattern( $terms, $text );

		if ( null === $pattern ) {
			return $escape( $text );
		}

		/*
		 * The raw text is split at the terms and each piece is escaped on its own.
		 * Searching in already escaped text would find terms inside entities:
		 * "amp" in "&amp;" would be marked and break the ampersand.
		 */
		$pieces = preg_split( '/(' . $pattern . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $pieces ) {
			return $escape( $text );
		}

		$html = '';

		// With one capture group the matches are the pieces at odd positions.
		foreach ( $pieces as $position => $piece ) {
			$html .= 1 === $position % 2 ? '<mark>' . $escape( $piece ) . '</mark>' : $escape( $piece );
		}

		return $html;
	}

	/**
	 * Returns a few consecutive words of a passage, by which a browser can find the passage on the page.
	 *
	 * The words are taken from one line, because a line of the indexed text
	 * is one block of the page, and a browser only finds text within a block.
	 *
	 * @param string   $text               Indexed text of the passage, one block per line.
	 * @param string[] $terms              Search terms.
	 * @param bool     $is_passage_the_hit Whether the passage itself is what was found, as it is for a match by
	 *                                     meaning. Then its beginning is returned if no term occurs in it. For a
	 *                                     match by a word in the title the passage is arbitrary, and nothing is returned.
	 */
	public static function fragment( string $text, array $terms, bool $is_passage_the_hit = false ): string {
		$lines = preg_split( '/\R/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = $lines ? $lines : array();

		foreach ( $lines as $line ) {
			$position = self::first_match( $line, $terms );

			if ( null === $position ) {
				continue;
			}

			$before = preg_split( '/\s+/u', mb_substr( $line, 0, $position ), -1, PREG_SPLIT_NO_EMPTY );
			$after  = preg_split( '/\s+/u', mb_substr( $line, $position ), -1, PREG_SPLIT_NO_EMPTY );

			return implode(
				' ',
				array_merge(
					array_slice( $before ? $before : array(), -self::FRAGMENT_WORDS_BEFORE ),
					array_slice( $after ? $after : array(), 0, self::FRAGMENT_WORDS_AFTER + 1 )
				)
			);
		}

		if ( ! $is_passage_the_hit ) {
			return '';
		}

		$words = preg_split( '/\s+/u', $lines[0] ?? '', -1, PREG_SPLIT_NO_EMPTY );

		return implode( ' ', array_slice( $words ? $words : array(), 0, self::FRAGMENT_WORDS_BEFORE + self::FRAGMENT_WORDS_AFTER + 1 ) );
	}

	/**
	 * Finds the position of the first term in the text, in characters.
	 *
	 * @param string[] $terms Search terms.
	 */
	private static function first_match( string $text, array $terms ): ?int {
		$pattern = self::pattern( $terms, $text );

		if ( null === $pattern || 1 !== preg_match( '/' . $pattern . '/iu', $text, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		// The offset is in bytes.
		return mb_strlen( substr( $text, 0, $match[0][1] ) );
	}

	/**
	 * Builds one pattern that finds any of the terms in a text, in any of the forms that count as a match.
	 *
	 * If the text has none of them in such a form, the terms are looked for
	 * wherever they stand: a page found for "hof" by its "Bahnhofstraße" should show where.
	 *
	 * @param string[] $terms Search terms.
	 * @return string|null Regular expression without delimiters, null if there is nothing to look for.
	 */
	private static function pattern( array $terms, string $text ): ?string {
		$terms = array_filter( $terms, static fn ( string $term ): bool => mb_strlen( $term ) > 1 );

		if ( array() === $terms ) {
			return null;
		}

		// Longest first, so "versandkosten" wins over "versand".
		usort( $terms, static fn ( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		$alternatives = array();

		foreach ( $terms as $term ) {
			$alternatives = array_merge( $alternatives, array_values( array_filter( TermCoverage::patterns( mb_strtolower( $term ) ) ) ) );
		}

		$pattern = implode( '|', $alternatives );

		if ( 1 === preg_match( '/' . $pattern . '/iu', $text ) ) {
			return $pattern;
		}

		$inside = array_filter( array_map( static fn ( string $term ): ?string => TermCoverage::inside_pattern( mb_strtolower( $term ) ), $terms ) );

		return array() === $inside ? $pattern : implode( '|', $inside );
	}
}
