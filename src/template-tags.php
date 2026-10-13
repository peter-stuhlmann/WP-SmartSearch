<?php
/**
 * Template tags for themes.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

use PrstSearch\Frontend\QueryIntegration;
use PrstSearch\Frontend\SearchUi;
use PrstSearch\Plugin;
use PrstSearch\Search\SearchResults;

/**
 * Prints the search form.
 *
 * @param array{label?: string, placeholder?: string, button_label?: string} $options Texts of the form.
 */
function prstsearch_form( array $options = array() ): void {
	echo Plugin::instance()->get( SearchUi::class )->form( $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates that escape.
}

/**
 * Prints the results of the current search with the plugin's markup.
 */
function prstsearch_results(): void {
	echo Plugin::instance()->get( SearchUi::class )->results(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates that escape.
}

/**
 * Prints the AI answer region for the current search.
 */
function prstsearch_answer(): void {
	echo Plugin::instance()->get( SearchUi::class )->answer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates that escape.
}

/**
 * Returns the raw results of the main search query, for fully custom templates.
 *
 * @return SearchResults|null Null if the plugin did not answer the main query, e.g. because the request is not a search.
 */
function prstsearch_get_results(): ?SearchResults {
	return Plugin::instance()->get( QueryIntegration::class )->results();
}
