<?php
/**
 * The front-end building blocks of the search.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Search\AnswerService;
use PrstSearch\Search\SearchResults;
use PrstSearch\Search\SearchService;
use PrstSearch\Settings\Settings;

/**
 * Renders the search form, the result list and the answer region.
 *
 * Block, shortcodes and template tags are thin wrappers around this class.
 */
final class SearchUi {

	/**
	 * Creates the UI.
	 */
	public function __construct(
		private readonly SearchService $search,
		private readonly AnswerService $answers,
		private readonly QueryIntegration $integration,
		private readonly ResultRenderer $renderer,
		private readonly TemplateLoader $templates,
		private readonly FrontendAssets $assets,
		private readonly Settings $settings
	) {}

	/**
	 * Renders the search form.
	 *
	 * @param array{label?: string, placeholder?: string, button_label?: string} $options Texts of the form.
	 */
	public function form( array $options = array() ): string {
		$this->assets->enqueue();

		return $this->templates->render(
			'search-form',
			array(
				'id'           => wp_unique_id( 'prstsearch-input-' ),
				'action'       => home_url( '/' ),
				'query'        => get_search_query( false ),
				'label'        => self::text( $options, 'label', __( 'Search', 'smart-search' ) ),
				'placeholder'  => self::text( $options, 'placeholder', '' ),
				'button_label' => self::text( $options, 'button_label', __( 'Search', 'smart-search' ) ),
				'suggestions'  => $this->settings->bool( 'suggestions' ),
			)
		);
	}

	/**
	 * Renders the results of the current search, or nothing if the request is not a search.
	 */
	public function results(): string {
		$results = $this->current_results();

		if ( null === $results ) {
			return '';
		}

		$this->assets->enqueue();
		$this->assets->track( $results );

		return $this->renderer->render_results( $results );
	}

	/**
	 * Renders the region for the AI answer to the current search.
	 *
	 * Returns nothing if answers are off, the search found nothing to base one
	 * on, or a later page of the results is shown.
	 */
	public function answer(): string {
		$results = $this->current_results();

		if ( null === $results || 0 === $results->total || $results->page > 1 || ! $this->answers->is_enabled() ) {
			return '';
		}

		$this->assets->enqueue();

		return $this->renderer->render_answer_placeholder( $results->query, $results->log_token );
	}

	/**
	 * Renders the filter by content type for the current search, for themes that list the results themselves.
	 */
	public function type_filter(): string {
		$results = $this->current_results();

		if ( null === $results ) {
			return '';
		}

		$this->assets->enqueue();

		return $this->renderer->render_types( $results );
	}

	/**
	 * Renders a self-contained search: form, answer and results that update without a page load.
	 *
	 * @param array{label?: string, placeholder?: string, button_label?: string, show_answer?: bool} $options Options of the search.
	 */
	public function live_search( array $options = array() ): string {
		$show_answer = (bool) ( $options['show_answer'] ?? true );

		return sprintf(
			// The status element exists before any search and is only ever filled with text. That
			// is what makes screen readers announce new results; inserted markup would stay silent.
			'<div class="prstsearch" data-prstsearch-live data-show-answer="%1$s">%2$s<p class="screen-reader-text" role="status" data-prstsearch-live-status></p><div class="prstsearch__output" data-prstsearch-output>%3$s%4$s</div></div>',
			$show_answer ? 'true' : 'false',
			$this->form( $options ),
			$show_answer ? $this->answer() : '',
			$this->results()
		);
	}

	/**
	 * Returns the results of the search this request is about.
	 */
	private function current_results(): ?SearchResults {
		$from_main_query = $this->integration->results();

		if ( null !== $from_main_query ) {
			return $from_main_query;
		}

		$query = get_search_query( false );

		if ( '' === trim( $query ) ) {
			return null;
		}

		// The filter by content type links to the search page with the type in the address.
		$post_type = get_query_var( 'post_type' );
		$is_chosen = is_string( $post_type ) && '' !== $post_type && 'any' !== $post_type;

		return $this->search->search( $query, max( 1, (int) get_query_var( 'paged' ) ), null, $is_chosen ? array( $post_type ) : array() );
	}

	/**
	 * Returns an option as text, or the fallback if it is missing or empty.
	 *
	 * @param array<string, mixed> $options Options.
	 */
	private static function text( array $options, string $key, string $fallback ): string {
		$value = $options[ $key ] ?? '';

		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}
}
