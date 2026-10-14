<?php
/**
 * Privacy policy text.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Privacy;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Frontend\VisitorText;

/**
 * Suggests a paragraph for the site's privacy policy (Settings → Privacy → Policy guide).
 */
final class PrivacyPolicy implements Hookable {

	/**
	 * Creates the service.
	 */
	public function __construct( private readonly VisitorText $texts ) {}

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
		$paragraphs = array( $this->texts->get( 'privacy_log' ), $this->texts->get( 'privacy_ai' ) );

		wp_add_privacy_policy_content(
			__( 'Smart Search', 'smart-search' ),
			wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' )
		);
	}
}
