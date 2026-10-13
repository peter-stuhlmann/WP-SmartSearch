<?php
/**
 * A number as it is displayed, together with its unrounded value.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use Stringable;

/**
 * A rounded figure that remembers what it was rounded from.
 *
 * The admin UI shows short numbers and reveals the exact value on hover.
 * Used as a string, a figure is its rounded text, so it can be placed in
 * sentences like any other value.
 */
final class Figure implements Stringable {

	/**
	 * Creates the figure.
	 *
	 * @param string $text  The rounded text that is displayed.
	 * @param string $exact The unrounded text. Empty if nothing was rounded away.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $exact = ''
	) {}

	/**
	 * Builds a sentence that contains figures, keeping an exact version of the whole sentence.
	 *
	 * @param string        $pattern A sprintf() pattern with one placeholder per value.
	 * @param string|Figure ...$values Values for the placeholders.
	 */
	public static function sentence( string $pattern, string|self ...$values ): self {
		$exact = array_map(
			static fn ( string|self $value ): string => $value instanceof self ? $value->exact_or_text() : $value,
			$values
		);

		return new self(
			vsprintf( $pattern, array_map( 'strval', $values ) ),
			vsprintf( $pattern, $exact )
		);
	}

	/**
	 * Returns the unrounded text, or the displayed text if nothing was rounded away.
	 */
	public function exact_or_text(): string {
		return '' === $this->exact ? $this->text : $this->exact;
	}

	/**
	 * Returns the figure as HTML. If it was rounded, the exact value is offered as a tooltip.
	 *
	 * @return string Safe HTML.
	 */
	public function html(): string {
		if ( '' === $this->exact || $this->exact === $this->text ) {
			return esc_html( $this->text );
		}

		return sprintf( '<span class="prstsearch-figure" title="%s">%s</span>', esc_attr( $this->exact ), esc_html( $this->text ) );
	}

	/**
	 * Returns the rounded text.
	 */
	public function __toString(): string {
		return $this->text;
	}
}
