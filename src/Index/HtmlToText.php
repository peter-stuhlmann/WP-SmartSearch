<?php
/**
 * Converts rendered HTML to searchable plain text.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

/**
 * Reduces HTML to the text a visitor reads, keeping paragraph breaks.
 */
final class HtmlToText {

	private const IGNORED_ELEMENTS = 'script|style|noscript|template|svg|iframe|form|nav|select|button';

	private const BLOCK_ELEMENTS = 'p|div|section|article|aside|header|footer|main|h[1-6]|li|ul|ol|dl|dt|dd|tr|table|blockquote|pre|figure|figcaption|details|summary|hr';

	/**
	 * Converts HTML to plain text with one paragraph per line.
	 */
	public function convert( string $html ): string {
		// Elements whose content is never part of the readable text.
		$text = (string) preg_replace( '#<(' . self::IGNORED_ELEMENTS . ')\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$text = (string) preg_replace( '/<!--.*?-->/s', ' ', $text );

		// Block boundaries become line breaks so sentences do not run together.
		$text = (string) preg_replace( '#<br\s*/?>|</?(' . self::BLOCK_ELEMENTS . ')\b[^>]*>#i', "\n", $text );

		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Keeps the class free of WordPress.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		/*
		 * Leftover shortcodes of deactivated plugins are noise. Only closing tags
		 * and tags with attributes are removed: a bare [word] may just as well
		 * be an editorial note such as [sic].
		 */
		$text = (string) preg_replace( '/\[\/[a-zA-Z][\w-]*\]|\[[a-zA-Z][\w-]*\s[^\]]*=[^\]]*\]/', ' ', $text );

		$lines = preg_split( '/\R/u', $text );
		$lines = array_map(
			static fn ( string $line ): string => trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $line ) ),
			$lines ? $lines : array()
		);

		return implode( "\n", array_filter( $lines, static fn ( string $line ): bool => '' !== $line ) );
	}
}
