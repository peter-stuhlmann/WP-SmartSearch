<?php
/**
 * Shows the AI answer above the theme's search results.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Settings\Settings;
use WP_Query;

/**
 * Places the answer region before the first result of the main search loop.
 *
 * Only active for the "replace" integration: with a block or own templates
 * the site owner decides where the answer goes.
 */
final class AnswerInjector implements Hookable {

	/**
	 * Whether the answer was already printed in this request.
	 */
	private bool $is_printed = false;

	/**
	 * Creates the injector.
	 */
	public function __construct(
		private readonly SearchUi $ui,
		private readonly Settings $settings,
		private readonly QueryIntegration $integration
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'loop_start', array( $this, 'print_answer' ) );
	}

	/**
	 * Prints the answer region and the filter by content type at the start of the main search loop.
	 */
	public function print_answer( WP_Query $query ): void {
		if ( $this->is_printed || ! $query->is_main_query() || ! $query->is_search() || is_admin() ) {
			return;
		}

		// Both belong to results of this plugin. If WordPress searched itself, for example
		// because replacing the search is switched off, they would not fit what is listed.
		if ( 'replace' !== $this->settings->string( 'integration' ) || null === $this->integration->results() ) {
			return;
		}

		$this->is_printed = true;

		if ( $this->settings->bool( 'auto_answer' ) ) {
			echo $this->ui->answer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates that escape.
		}

		// The theme lists the results, so it has no place of its own for the filter.
		echo $this->ui->type_filter(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates that escape.
	}
}
