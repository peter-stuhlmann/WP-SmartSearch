<?php
/**
 * Keeps search results in the visitor's language on multilingual sites.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Search\SearchHit;

/**
 * Supports Polylang and WPML without depending on either.
 *
 * The index holds all languages. At search time, hits in another language
 * than the current one are dropped; content without a language is kept.
 */
final class LanguageFilter implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'prstsearch_hits', array( $this, 'keep_current_language' ) );
	}

	/**
	 * Removes hits that are in a different language than the current one.
	 *
	 * @param SearchHit[] $hits Hits, best first.
	 * @return SearchHit[]
	 */
	public function keep_current_language( array $hits ): array {
		$current = $this->current_language();

		if ( '' === $current ) {
			return $hits;
		}

		return array_values(
			array_filter(
				$hits,
				fn ( SearchHit $hit ): bool => in_array( $this->post_language( $hit->post_id ), array( '', $current ), true )
			)
		);
	}

	/**
	 * Returns the language code of the current request, or an empty string on single-language sites.
	 */
	private function current_language(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$language = pll_current_language();

			// REST requests have no language of their own; the front-end script passes the page language.
			return is_string( $language ) && '' !== $language ? $language : $this->requested_language();
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of WPML.
			$language = apply_filters( 'wpml_current_language', null );

			return is_string( $language ) && 'all' !== $language ? $language : '';
		}

		return '';
	}

	/**
	 * Returns the language passed with the request, reduced to its primary subtag ("de-DE" becomes "de").
	 */
	private function requested_language(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, public search parameter.
		$language = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';

		return strtok( $language, '-_' ) ?: '';
	}

	/**
	 * Returns the language code of a post, or an empty string if it has none.
	 */
	private function post_language( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$language = pll_get_post_language( $post_id );

			return is_string( $language ) ? $language : '';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of WPML.
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );

		return is_array( $details ) && is_string( $details['language_code'] ?? null ) ? $details['language_code'] : '';
	}
}
