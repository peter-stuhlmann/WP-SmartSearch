<?php
/**
 * The words of a passage in the form the database searches them.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Prepares text for the FULLTEXT index, and queries to match it.
 *
 * A FULLTEXT index finds whole words and word beginnings. Two things are
 * missing for a search that feels right in German: other forms of a word,
 * and the last part of a compound ("Kosten" in "Versandkosten"). So the index
 * does not hold the text itself but, for each word, its stem, and the stem
 * written backwards with a marker in front. The end of a word is then the
 * beginning of its reversed form, which the index can find.
 */
final class IndexTerms {

	/**
	 * Marks a reversed word. No word begins like this, so a reversed word is
	 * never mistaken for an ordinary one ("Regal" backwards is "Lager").
	 */
	private const REVERSED = 'qz';

	/**
	 * Words the database never indexes, whatever is done: its built-in list of English stop words.
	 *
	 * A stem can be one of them by accident: "Foren" becomes "for". Such a
	 * stem is stored with a few letters added, so it is indexed after all.
	 * A query looks for beginnings of words and finds it as before.
	 */
	private const DATABASE_STOP_WORDS = array(
		'a',
		'about',
		'an',
		'are',
		'as',
		'at',
		'be',
		'by',
		'com',
		'de',
		'en',
		'for',
		'from',
		'how',
		'i',
		'in',
		'is',
		'it',
		'la',
		'of',
		'on',
		'or',
		'that',
		'the',
		'this',
		'to',
		'was',
		'what',
		'when',
		'where',
		'who',
		'will',
		'with',
		'und',
		'www',
	);

	private const KEPT_ANYWAY = 'qx';

	/**
	 * Stored for a passage without a single word, so it can be told from one that was never processed.
	 */
	private const NO_WORDS = 'qznone';

	/**
	 * The database does not index shorter words.
	 */
	public const MIN_LENGTH = 3;

	/**
	 * From this length on a stem is also looked for at the end of words.
	 *
	 * Shorter ones end too many unrelated words: "ei" ends "Partei".
	 */
	public const MIN_ENDING_LENGTH = 4;

	/**
	 * Returns what is stored for a passage.
	 */
	public static function of( string $title, string $content ): string {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', Unicode::composed( $title . ' ' . $content ), -1, PREG_SPLIT_NO_EMPTY );
		$terms = array();
		$seen  = array();

		foreach ( $words ? $words : array() as $word ) {
			$stem = Stemmer::stem( $word );

			if ( mb_strlen( $stem ) < self::MIN_LENGTH || isset( $seen[ $stem ] ) ) {
				continue;
			}

			$seen[ $stem ] = true;

			$terms[ in_array( $stem, self::DATABASE_STOP_WORDS, true ) ? $stem . self::KEPT_ANYWAY : $stem ] = true;

			// Only a word longer than the shortest ending can end with one.
			if ( mb_strlen( $stem ) > self::MIN_ENDING_LENGTH ) {
				$terms[ self::reversed( $stem ) ] = true;
			}
		}

		return array() === $terms ? self::NO_WORDS : implode( ' ', array_map( 'strval', array_keys( $terms ) ) );
	}

	/**
	 * Builds the boolean query that finds passages containing forms of the given words.
	 *
	 * "versand ausland" becomes "versand* ausland* qzdnasrev* qzdnalsua*":
	 * each word as the beginning of a word, and as the end of one.
	 *
	 * @param string[] $words Lower-case search words.
	 * @return string Empty if no word is long enough for the index.
	 */
	public static function query( array $words ): string {
		$parts = array();

		foreach ( $words as $word ) {
			$stem = Stemmer::stem( $word );

			if ( mb_strlen( $stem ) < self::MIN_LENGTH || 1 !== preg_match( '/^[\p{L}\p{N}]+$/u', $stem ) ) {
				continue;
			}

			$parts[] = $stem . '*';

			if ( mb_strlen( $stem ) >= self::MIN_ENDING_LENGTH ) {
				$parts[] = self::reversed( $stem ) . '*';
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Writes a stem backwards, with the marker in front.
	 */
	private static function reversed( string $stem ): string {
		return self::REVERSED . implode( '', array_reverse( mb_str_split( $stem ) ) );
	}
}
