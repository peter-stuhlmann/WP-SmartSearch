<?php
/**
 * One spelling for every character.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use Normalizer;

/**
 * Unicode knows two ways to write "ä": as one character, or as "a" followed
 * by two dots that combine with it. They look the same and are different
 * text. Content pasted from a PDF or typed on some keyboards uses the second
 * way, and a search that splits text into words would cut such a word in
 * two at the dots. So text is brought into the first form wherever it enters
 * the search: when it is indexed and when it is searched for.
 */
final class Unicode {

	/**
	 * Returns the text with every character in its composed form.
	 *
	 * Without PHP's intl extension the text is returned as it is.
	 */
	public static function composed( string $text ): string {
		if ( ! class_exists( Normalizer::class ) ) {
			return $text;
		}

		$composed = Normalizer::normalize( $text, Normalizer::FORM_C );

		// False for text that is not valid UTF-8, which is then left alone.
		return false === $composed ? $text : $composed;
	}
}
