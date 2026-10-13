<?php
/**
 * Shows the index status of content where editors work.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Index\Eligibility;
use PrstSearch\Index\IndexReport;
use PrstSearch\Rest\SearchRoutes;
use PrstSearch\Support\Assets;
use WP_Admin_Bar;
use WP_Post;

/**
 * Makes the status visible without opening anything: in the editor header,
 * in the admin bar on the front end, in post lists and in the classic editor.
 */
final class PostIndicators implements Hookable {

	private const STYLE_HANDLE  = 'prstsearch-indicators';
	private const EDITOR_HANDLE = 'prstsearch-editor';
	private const COLUMN        = 'prstsearch_status';

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly IndexReport $report,
		private readonly Eligibility $eligibility,
		private readonly Assets $assets
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 90 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_script' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'print_classic_editor_status' ) );
		add_action( 'admin_init', array( $this, 'register_list_columns' ) );
	}

	/**
	 * Loads the small stylesheet for the status dot in the admin area and, on the front end, wherever the admin bar is shown.
	 */
	public function enqueue_style(): void {
		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}

		$this->assets->register_style( self::STYLE_HANDLE, 'indicators' );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Loads the editor plugin that shows the status next to the publish button.
	 */
	public function enqueue_editor_script(): void {
		$screen = get_current_screen();

		if ( null === $screen || ! $this->eligibility->is_enabled_post_type( (string) $screen->post_type ) ) {
			return;
		}

		$this->assets->register_script( self::EDITOR_HANDLE, 'editor' );
		$this->assets->register_style( self::EDITOR_HANDLE, 'editor' );

		wp_enqueue_script( self::EDITOR_HANDLE );
		wp_enqueue_style( self::EDITOR_HANDLE );

		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.prstsearchEditor = ' . wp_json_encode( array( 'restPath' => '/' . SearchRoutes::ROUTE_NAMESPACE ) ) . ';',
			'before'
		);
	}

	/**
	 * Adds "Search: Indexed" to the admin bar when a single post is viewed on the front end.
	 */
	public function add_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! $this->eligibility->is_enabled_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$status = $this->report->for_post( $post );

		$admin_bar->add_node(
			array(
				'id'    => 'prstsearch-status',
				'title' => $this->status_markup(
					$status,
					/* translators: %s: index status, e.g. "Indexed". */
					sprintf( __( 'Search: %s', 'smart-search' ), $status['label'] )
				),
				'href'  => (string) get_edit_post_link( $post, 'raw' ),
				'meta'  => array( 'title' => trim( $status['description'] . ' ' . $status['detail'] ) ),
			)
		);
	}

	/**
	 * Adds the status column to the list tables of all searchable content types.
	 */
	public function register_list_columns(): void {
		foreach ( $this->eligibility->enabled_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_list_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'print_list_column' ), 10, 2 );
		}
	}

	/**
	 * Adds the "Search" column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_list_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Search', 'smart-search' );

		return $columns;
	}

	/**
	 * Prints the status of one row.
	 */
	public function print_list_column( string $column, int $post_id ): void {
		$post = get_post( $post_id );

		if ( self::COLUMN !== $column || ! $post instanceof WP_Post ) {
			return;
		}

		$status = $this->report->for_post( $post );

		echo wp_kses_post( $this->status_markup( $status, $status['label'] ) );

		if ( '' !== $status['detail'] ) {
			echo '<p class="prstsearch-status__detail">' . esc_html( $status['detail'] ) . '</p>';
		}
	}

	/**
	 * Prints the status in the "Publish" box of the classic editor.
	 */
	public function print_classic_editor_status( WP_Post $post ): void {
		if ( ! $this->eligibility->is_enabled_post_type( $post->post_type ) ) {
			return;
		}

		$status = $this->report->for_post( $post );

		echo '<div class="misc-pub-section">';
		echo wp_kses_post(
			$this->status_markup(
				$status,
				/* translators: %s: index status, e.g. "Indexed". */
				sprintf( __( 'Search: %s', 'smart-search' ), $status['label'] )
			)
		);
		echo '<p class="prstsearch-status__detail">' . esc_html( trim( $status['description'] . ' ' . $status['detail'] ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * Builds the dot-and-label markup. The colour is never the only signal: the label names the status.
	 *
	 * @param array<string, mixed> $status Status data from IndexReport.
	 * @param string               $text   Visible text.
	 */
	private function status_markup( array $status, string $text ): string {
		return sprintf(
			'<span class="prstsearch-status prstsearch-status--%s"><span class="prstsearch-status__dot" aria-hidden="true"></span>%s</span>',
			esc_attr( $status['status'] ),
			esc_html( $text )
		);
	}
}
