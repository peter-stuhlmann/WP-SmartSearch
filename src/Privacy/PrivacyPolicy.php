<?php
/**
 * Privacy policy text.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Privacy;

use PrstSearch\Contracts\Hookable;

/**
 * Suggests a paragraph for the site's privacy policy (Settings → Privacy → Policy guide).
 */
final class PrivacyPolicy implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_suggestion' ) );
	}

	/**
	 * Adds the suggested text.
	 */
	public function add_suggestion(): void {
		$paragraphs = array(
			__( 'When you use the search on this website, we store the search phrase, the time, the number of results and, where applicable, which result was opened. We use this to improve the search and our content. We do not store your IP address or any other data that identifies you.', 'smart-search' ),
			__( 'If semantic search or AI answers are enabled, your search phrase and excerpts of our public content are transmitted to the AI provider we use, in order to find matching content and to write an answer.', 'smart-search' ),
		);

		wp_add_privacy_policy_content(
			__( 'Smart Search', 'smart-search' ),
			wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' )
		);
	}
}
