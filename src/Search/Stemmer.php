<?php
/**
 * Reduces words to a common form.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Makes "Rezepte" and "Rezept", "Häuser" and "Haus", "categories" and "category" the same word.
 *
 * It cuts the common endings of German and English and writes umlauts as
 * their base vowel. That is no grammar: the result need not be a word, and
 * irregular forms stay apart. What matters is that the index and the query
 * are treated alike, so the forms of a word meet in the same stem.
 *
 * Every rule looks at the end of the word only, never at its length. That
 * is what keeps compounds findable: "Krankenhaus" must end in the stem of
 * "Haus", which it would not if a long word lost a letter that a short one keeps.
 */
final class Stemmer {

	/**
	 * How much of a word must remain. Protects the shortest words from being
	 * cut down to nothing; no ordinary word is affected.
	 */
	private const MIN_STEM = 3;

	/**
	 * How many endings are cut one after another, as in "Preis-e-n".
	 */
	private const MAX_ENDINGS = 2;

	private const VOWELS = 'aeiouy';

	/**
	 * After these letters a final "s" belongs to the word: "Haus", "Preis", "Pass".
	 * After any other it is a plural or a genitive: "Autos", "Rezepts".
	 */
	private const KEEPS_S = 'uis';

	/**
	 * Consonants English doubles before "ing" and "ed".
	 */
	private const DOUBLED = 'bdgmnprt';

	/**
	 * Endings a word with a given stem can carry in running text.
	 */
	private const FORM_ENDINGS = 'ern|ing|em|en|er|es|ed|e|n|s';

	/**
	 * The words of two letters, with their forms as they are written.
	 *
	 * The rules leave them alone, because nothing of the word would remain,
	 * and no rule makes "Eier" from "Ei". They are few enough to be listed.
	 * "Eis" is not among them: that is another word.
	 */
	private const SHORT_WORDS = array(
		'ei' => array( 'eiern', 'eier', 'ei' ),
		'ol' => array( 'ölen', 'öle', 'öls', 'öl' ),
	);

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

		foreach ( self::SHORT_WORDS as $short => $forms ) {
			if ( in_array( $stem, array_map( array( self::class, 'fold' ), $forms ), true ) ) {
				return $short;
			}
		}

		for ( $cut = 0; $cut < self::MAX_ENDINGS; $cut++ ) {
			$shorter = self::without_ending( $stem );

			if ( $shorter === $stem ) {
				break;
			}

			$stem = $shorter;
		}

		// "Ergebnis" and "Bus" double their "s" before an ending: "Ergebnisse", "Busse".
		$stem = (string) preg_replace( '/(ni|bu)ss$/', '$1s', $stem );

		// One spelling for the German and the English ending, so "Kategorie" meets "category".
		return str_ends_with( $stem, 'ie' ) && strlen( $stem ) > self::MIN_STEM ? substr( $stem, 0, -2 ) . 'y' : $stem;
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
	 * The text is not folded, so the fragment accepts every spelling the stem
	 * stands for: an umlaut for its base vowel, "ß" for "ss", "ie" for a final "y".
	 */
	public static function pattern( string $stem ): string {
		$letters = mb_str_split( str_replace( 'ss', 'ß', $stem ) );
		$last    = count( $letters ) - 1;
		$pattern = '';

		foreach ( $letters as $position => $letter ) {
			$pattern .= match ( true ) {
				'a' === $letter                        => '[aä]',
				'o' === $letter                        => '[oö]',
				'u' === $letter                        => '[uü]',
				'ß' === $letter                        => '(?:ss|ß)',
				'y' === $letter && $position === $last => '(?:y|ie)',
				default                                => preg_quote( $letter, '/' ),
			};
		}

		return $pattern;
	}

	/**
	 * Returns a regular expression fragment that matches a word too short for forms_pattern().
	 *
	 * For most of them that is the word as it stands: with endings added, "ei" would be "ein" and "eine".
	 */
	public static function short_word_pattern( string $stem ): string {
		return isset( self::SHORT_WORDS[ $stem ] ) ? '(?:' . implode( '|', self::SHORT_WORDS[ $stem ] ) . ')' : self::pattern( $stem );
	}

	/**
	 * Returns the spellings to look for when a word has to be found in the text itself, not by its stem.
	 *
	 * @return string[] The forms of a listed short word, otherwise the word alone.
	 */
	public static function spellings( string $word ): array {
		return self::SHORT_WORDS[ self::stem( $word ) ] ?? array( $word );
	}

	/**
	 * Returns a regular expression fragment that matches every form of a word with the given stem.
	 */
	public static function forms_pattern( string $stem ): string {
		// "ship" is also the stem of "shipping", with its doubled consonant.
		$doubled = 1 === preg_match( '/[' . self::DOUBLED . ']$/', $stem ) ? '(?:' . substr( $stem, -1 ) . ')?' : '';

		return self::pattern( $stem ) . $doubled . '(?:' . self::FORM_ENDINGS . '){0,' . self::MAX_ENDINGS . '}';
	}

	/**
	 * Cuts one ending, if there is one and enough of the word remains.
	 */
	private static function without_ending( string $word ): string {
		$shorter = self::cut( $word );

		return strlen( $shorter ) >= self::MIN_STEM ? $shorter : $word;
	}

	/**
	 * Applies the first rule that fits the end of the word.
	 */
	private static function cut( string $word ): string {
		$before = static fn ( int $ending ): string => substr( $word, -$ending - 1, 1 );
		$vowel  = static fn ( string $letter ): bool => '' !== $letter && str_contains( self::VOWELS, $letter );
		$keeps  = static fn ( string $letter ): bool => '' !== $letter && str_contains( self::KEEPS_S, $letter );

		return match ( true ) {
			str_ends_with( $word, 'ern' ) => substr( $word, 0, -3 ),
			str_ends_with( $word, 'ing' ) => self::undoubled( substr( $word, 0, -3 ) ),
			str_ends_with( $word, 'em' ),
			str_ends_with( $word, 'er' )  => substr( $word, 0, -2 ),
			// After "i" or "e" only the last letter is the ending: "Kategorie-n", "cookie-s", "Idee-n".
			str_ends_with( $word, 'en' ),
			str_ends_with( $word, 'es' )  => substr( $word, 0, in_array( $before( 2 ), array( 'i', 'e' ), true ) ? -1 : -2 ),
			str_ends_with( $word, 'ed' )  => self::undoubled( substr( $word, 0, -2 ) ),
			// "Tee", "Idee", "Knie" end in their own "e".
			str_ends_with( $word, 'e' )   => $vowel( $before( 1 ) ) ? $word : substr( $word, 0, -1 ),
			str_ends_with( $word, 's' )   => $keeps( $before( 1 ) ) ? $word : substr( $word, 0, -1 ),
			default                       => $word,
		};
	}

	/**
	 * Undoes the doubled consonant English adds before some endings: "shipp-ing" is "ship".
	 */
	private static function undoubled( string $stem ): string {
		return 1 === preg_match( '/([' . self::DOUBLED . '])\1$/', $stem ) ? substr( $stem, 0, -1 ) : $stem;
	}
}
