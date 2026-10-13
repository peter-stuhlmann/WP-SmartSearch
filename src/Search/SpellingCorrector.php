<?php
/**
 * Corrects misspelled search words.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Replaces search words the website does not contain by the most similar word it does contain.
 *
 * The dictionary is the website itself. That makes the correction fit the
 * site: product names and technical terms are known, and a word is never
 * "corrected" into something that could not be found anyway.
 */
final class SpellingCorrector {

	/**
	 * From this length two wrong letters are tolerated instead of one.
	 */
	private const LONG_WORD_LENGTH = 7;

	/**
	 * Letters that are commonly typed without their accent or as two letters.
	 */
	private const FOLDED_LETTERS = array(
		'ä' => 'a',
		'ö' => 'o',
		'ü' => 'u',
		'ß' => 'ss',
		'é' => 'e',
		'è' => 'e',
		'ê' => 'e',
		'á' => 'a',
		'à' => 'a',
		'â' => 'a',
		'ç' => 'c',
		'ñ' => 'n',
		'ó' => 'o',
		'ô' => 'o',
		'ú' => 'u',
		'û' => 'u',
		'í' => 'i',
		'î' => 'i',
	);

	/**
	 * The words of the website, grouped by length of their folded form.
	 *
	 * @var array<int, array<string, array{word: string, frequency: int}>>
	 */
	private array $by_length = array();

	/**
	 * Creates the corrector.
	 *
	 * @param array<string, int> $vocabulary Lower-case words of the website with how often they occur.
	 */
	public function __construct( array $vocabulary ) {
		foreach ( $vocabulary as $word => $frequency ) {
			$folded = self::fold( (string) $word );
			$known  = $this->by_length[ strlen( $folded ) ][ $folded ] ?? null;

			// "hähnchen" and "hahnchen" fold to the same form; the more frequent spelling wins.
			if ( null === $known || $frequency > $known['frequency'] ) {
				$this->by_length[ strlen( $folded ) ][ $folded ] = array(
					'word'      => (string) $word,
					'frequency' => $frequency,
				);
			}
		}
	}

	/**
	 * Corrects the terms of a query.
	 *
	 * @param string[] $terms Lower-case search terms.
	 * @return string[]|null The terms with corrections applied, or null if nothing was corrected.
	 */
	public function correct( array $terms ): ?array {
		$corrected   = array();
		$has_changes = false;

		foreach ( $terms as $term ) {
			$replacement = $this->closest_word( $term );
			$corrected[] = $replacement ?? $term;
			$has_changes = $has_changes || null !== $replacement;
		}

		return $has_changes ? $corrected : null;
	}

	/**
	 * Returns the word of the website that a term most likely stands for.
	 *
	 * @return string|null Null if the term is fine as it is or nothing is close enough.
	 */
	private function closest_word( string $term ): ?string {
		$folded = self::fold( $term );
		$length = strlen( $folded );

		// Shorter words have too many neighbours one letter away to be corrected safely.
		if ( mb_strlen( $term ) < Vocabulary::MIN_WORD_LENGTH ) {
			return null;
		}

		$known = $this->by_length[ $length ][ $folded ] ?? null;

		if ( null !== $known ) {
			// Only the accents differ, as in "hahnchen": use the spelling of the website.
			return $known['word'] === $term ? null : $known['word'];
		}

		$tolerance = $length >= self::LONG_WORD_LENGTH ? 2 : 1;
		$best      = null;

		// A word within the tolerance cannot be more than that many letters longer or shorter.
		for ( $candidate_length = $length - $tolerance; $candidate_length <= $length + $tolerance; $candidate_length++ ) {
			foreach ( $this->by_length[ $candidate_length ] ?? array() as $candidate => $entry ) {
				$distance = levenshtein( $folded, (string) $candidate );

				if ( $distance > $tolerance ) {
					continue;
				}

				// Closer wins; at the same distance the word the website uses more often.
				if ( null === $best || array( $distance, -$entry['frequency'] ) < array( $best['distance'], -$best['frequency'] ) ) {
					$best = $entry + array( 'distance' => $distance );
				}
			}
		}

		return $best['word'] ?? null;
	}

	/**
	 * Reduces a word to plain lower-case ASCII letters where a common spelling variant exists.
	 */
	private static function fold( string $word ): string {
		return strtr( mb_strtolower( $word ), self::FOLDED_LETTERS );
	}
}
