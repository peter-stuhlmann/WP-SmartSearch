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
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $title . ' ' . $content, -1, PREG_SPLIT_NO_EMPTY );
		$terms = array();

		foreach ( $words ? $words : array() as $word ) {
			$stem = Stemmer::stem( $word );

			if ( mb_strlen( $stem ) < self::MIN_LENGTH || isset( $terms[ $stem ] ) ) {
				continue;
			}

			$terms[ $stem ] = true;

			// Only a word longer than the shortest ending can end with one.
			if ( mb_strlen( $stem ) > self::MIN_ENDING_LENGTH ) {
				$terms[ self::reversed( $stem ) ] = true;
			}
		}

		return implode( ' ', array_map( 'strval', array_keys( $terms ) ) );
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
