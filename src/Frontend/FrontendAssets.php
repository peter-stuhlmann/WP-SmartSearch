<?php
/**
 * Front-end styles and scripts.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Rest\SearchRoutes;
use PrstSearch\Search\SearchResults;
use PrstSearch\Settings\Settings;
use PrstSearch\Support\Assets;

/**
 * Registers the front-end assets and loads them only where the search is used.
 */
final class FrontendAssets implements Hookable {

	public const HANDLE = 'prstsearch-frontend';

	/**
	 * Results rendered by the plugin's own components in this request.
	 */
	private ?SearchResults $rendered = null;

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly Assets $assets,
		private readonly QueryIntegration $integration,
		private readonly Settings $settings
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// On init, so the block can refer to the style handle in the editor as well.
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_search' ) );

		// Late, when everything that renders results has done so, and before footer scripts are printed.
		add_action( 'wp_footer', array( $this, 'print_settings' ), 1 );
	}

	/**
	 * Registers style and script without loading them.
	 */
	public function register_assets(): void {
		$this->assets->register_script( self::HANDLE, 'frontend' );
		$this->assets->register_style( self::HANDLE, 'frontend' );
	}

	/**
	 * Loads the assets on search result pages, for the answer region and click tracking.
	 */
	public function enqueue_on_search(): void {
		if ( is_search() ) {
			$this->enqueue();
		}
	}

	/**
	 * Loads the assets. Safe to call several times and after wp_head.
	 */
	public function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Remembers results rendered on the page, so clicks on them can be attributed to their search.
	 */
	public function track( SearchResults $results ): void {
		$this->rendered ??= $results;
	}

	/**
	 * Hands the script its settings.
	 */
	public function print_settings(): void {
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.prstsearchSettings = ' . wp_json_encode( $this->script_settings() ) . ';',
			'before'
		);
	}

	/**
	 * Returns the data the script needs.
	 *
	 * @return array<string, mixed>
	 */
	private function script_settings(): array {
		$track_clicks = $this->settings->bool( 'tracking_enabled' ) && $this->settings->bool( 'track_clicks' );

		// Results rendered by the block or a template tag, or else those the theme's loop shows.
		$results = $this->rendered ?? $this->integration->results();
		$clicks  = null;

		if ( $track_clicks && null !== $results && '' !== $results->log_token ) {
			$clicks = array(
				'token' => $results->log_token,
				'posts' => array_combine(
					array_map( static fn ( int $post_id ): string => (string) get_permalink( $post_id ), $results->post_ids() ),
					$results->post_ids()
				),
			);
		}

		return array(
			'restUrl'       => esc_url_raw( rest_url( SearchRoutes::ROUTE_NAMESPACE ) ),
			'trackClicks'   => $track_clicks,
			'clicks'        => $clicks,
			'streamAnswers' => $this->settings->bool( 'stream_answers' ),
			'strings'       => array(
				/* translators: %d: number of suggestions. */
				'suggestions' => __( '%d suggestions available. Use the arrow keys to choose one.', 'smart-search' ),
				'suggestion'  => __( '1 suggestion available. Use the arrow keys to choose it.', 'smart-search' ),
				'error'       => __( 'The search is not available at the moment. Please try again.', 'smart-search' ),
				'moreError'   => __( 'More results could not be loaded. Please try again.', 'smart-search' ),
				'searching'   => __( 'Searching …', 'smart-search' ),
				'refining'    => __( 'Looking for further matching pages …', 'smart-search' ),
			),
		);
	}
}
