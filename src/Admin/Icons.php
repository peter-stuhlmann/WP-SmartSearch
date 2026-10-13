<?php
/**
 * Inline SVG icons of the admin UI.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

/**
 * A small icon set. Icons are decorative and always hidden from assistive technology.
 */
final class Icons {

	private const PATHS = array(
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'semantic' => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.5 6h7M7.2 8.2l3.6 7.6M16.8 8.2l-3.6 7.6"/>',
		'sparkle'  => '<path d="M11 4l1.8 5.2L18 11l-5.2 1.8L11 18l-1.8-5.2L4 11l5.2-1.8z"/><path d="M19 3v4M17 5h4"/>',
		'cloud'    => '<path d="M7 18a4 4 0 0 1-.6-7.96A6 6 0 0 1 18 9.5a4.25 4.25 0 0 1-.5 8.5z"/>',
		'shield'   => '<path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="m9 12 2 2 4-4"/>',
		'server'   => '<rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="16" height="6" rx="1.5"/><path d="M8 7h.01M8 17h.01"/>',
		'swap'     => '<path d="M5 8h13l-3-3M19 16H6l3 3"/>',
		'block'    => '<rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="7" height="6" rx="1.5"/><rect x="13" y="14" width="7" height="6" rx="1.5"/>',
		'code'     => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14"/>',
		'document' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/>',
		'layout'   => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 9h16M9 9v11"/>',
		'bag'      => '<path d="M6 8h12l-1 12H7z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
	);

	/**
	 * Returns the markup of an icon.
	 *
	 * @return string Safe SVG markup, empty for unknown names.
	 */
	public static function svg( string $name ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return '<svg class="prstsearch-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. self::PATHS[ $name ]
			. '</svg>';
	}

	/**
	 * Returns the name of the icon that represents a post type.
	 */
	public static function for_post_type( string $post_type ): string {
		return match ( $post_type ) {
			'page'    => 'layout',
			'product' => 'bag',
			default   => 'document',
		};
	}
}
