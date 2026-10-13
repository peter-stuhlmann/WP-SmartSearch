<?php
/**
 * Reduces words to a common form.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Makes "Rezepte" and "Rezept", "Häuser" and "Haus", "shipping" and "ships" the same word.
 *
 * It cuts the common endings of German and English and writes umlauts as
 * their base vowel. That is no grammar: the result need not be a word, and
 * irregular forms stay apart. What matters is that the index and the query
 * are treated alike, so the forms of a word meet in the same stem.
 */
final class Stemmer {

	/**
	 * Endings that are cut, longest first.
	 */
	private const ENDINGS = array( 'ern', 'ing', 'em', 'en', 'er', 'es', 'ed', 'e', 's' );

	/**
	 * How much of a word must remain.
	 */
	private const MIN_STEM = 3;

	/**
	 * After these letters a single "e" or "s" belongs to the word: "Tee", "Haus", "Auto-s".
	 *
	 * The rule must not depend on the length of the word, or "Haus" would keep
	 * its "s" and "Krankenhaus" would lose it, and the two would never meet.
	 */
	private const VOWELS = 'aeiouy';

	/**
	 * How many endings are cut one after another, as in "Preis-e-n".
	 */
	private const MAX_ENDINGS = 2;

	/**
	 * Consonants English doubles before "ing" and "ed".
	 */
	private const DOUBLED = 'bdgmnprt';

	private const FOLDED = array(
		'ä' => 'a',
		'ö' => 'o',
		'ü' => 'u',
		'ß' => 'ss',
	);

	/**
	 * Returns the stem of a word.
	 */
	public static function stem( string $word ): string {
		$stem = self::fold( $word );

		for ( $cut = 0; $cut < self::MAX_ENDINGS; $cut++ ) {
			$shorter = self::without_ending( $stem );

			if ( $shorter === $stem ) {
				break;
			}

			$stem = $shorter;
		}

		return $stem;
	}

	/**
	 * Writes a word in lower case and without umlauts.
	 */
	public static function fold( string $word ): string {
		return strtr( mb_strtolower( $word ), self::FOLDED );
	}

	/**
	 * Returns a regular expression fragment that matches a stem in running text.
	 *
	 * The text is not folded, so the fragment accepts an umlaut wherever the
	 * stem has the base vowel.
	 */
	public static function pattern( string $stem ): string {
		$pattern = '';

		foreach ( mb_str_split( str_replace( 'ss', 'ß', $stem ) ) as $letter ) {
			$pattern .= match ( $letter ) {
				'a'     => '[aä]',
				'o'     => '[oö]',
				'u'     => '[uü]',
				'ß'     => '(?:ss|ß)',
				default => preg_quote( $letter, '/' ),
			};
		}

		return $pattern;
	}

	/**
	 * Returns a regular expression fragment that matches every form of a word with the given stem.
	 */
	public static function forms_pattern( string $stem ): string {
		// "ship" is also the stem of "shipping", with its doubled consonant.
		$doubled = 1 === preg_match( '/[' . self::DOUBLED . ']$/', $stem ) ? '(?:' . substr( $stem, -1 ) . ')?' : '';

		return self::pattern( $stem ) . $doubled . '(?:' . implode( '|', self::ENDINGS ) . '){0,' . self::MAX_ENDINGS . '}';
	}

	/**
	 * Cuts one ending, if enough of the word remains.
	 */
	private static function without_ending( string $word ): string {
		foreach ( self::ENDINGS as $ending ) {
			if ( ! str_ends_with( $word, $ending ) || mb_strlen( $word ) - strlen( $ending ) < self::MIN_STEM ) {
				continue;
			}

			$stem = substr( $word, 0, -strlen( $ending ) );

			if ( 1 === strlen( $ending ) && str_contains( self::VOWELS, substr( $stem, -1 ) ) ) {
				continue;
			}

			return self::undoubled( $stem, $ending );
		}

		return $word;
	}

	/**
	 * Undoes the doubled consonant English adds before some endings: "shipp-ing" is "ship".
	 */
	private static function undoubled( string $stem, string $ending ): string {
		$is_doubled = in_array( $ending, array( 'ing', 'ed' ), true )
			&& strlen( $stem ) > self::MIN_STEM
			&& 1 === preg_match( '/([' . self::DOUBLED . '])\1$/', $stem );

		return $is_doubled ? substr( $stem, 0, -1 ) : $stem;
	}
}
