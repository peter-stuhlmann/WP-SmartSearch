<?php
/**
 * The settings page.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Rest\SearchRoutes;
use PrstSearch\Security\Capabilities;
use PrstSearch\Settings\SettingsSchema;
use PrstSearch\Support\Assets;

/**
 * Registers the page under "Settings" and renders its frame: header, tabs and the active tab.
 */
final class AdminPage implements Hookable {

	public const SLUG = 'smart-search';

	private const HANDLE = 'prstsearch-admin';

	/**
	 * Hook suffix of the page, set when the menu is registered.
	 */
	private string $hook_suffix = '';

	/**
	 * Creates the page.
	 *
	 * @param Assets         $assets    Registers the built assets.
	 * @param ViewModel      $view      Data for the common views.
	 * @param StatsViewModel $stats     Data for the statistics view.
	 * @param Fields         $fields    Form controls.
	 * @param Format         $format    Number formatting.
	 * @param HelpTabs       $help      Contextual help.
	 * @param string         $views_dir Directory of the view files, with trailing slash.
	 */
	public function __construct(
		private readonly Assets $assets,
		private readonly ViewModel $view,
		private readonly StatsViewModel $stats,
		private readonly Fields $fields,
		private readonly Format $format,
		private readonly HelpTabs $help,
		private readonly string $views_dir
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PRSTSEARCH_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Adds the page to the Settings menu.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'Smart Search', 'smart-search' ),
			__( 'Smart Search', 'smart-search' ),
			Capabilities::VIEW_STATS,
			self::SLUG,
			array( $this, 'render' )
		);

		add_action( 'load-' . $this->hook_suffix, array( $this->help, 'add' ) );
	}

	/**
	 * Adds a "Settings" link to the plugin row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'smart-search' ) )
		);

		return $links;
	}

	/**
	 * Loads style and script on the settings page only.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->assets->register_script( self::HANDLE, 'admin' );
		$this->assets->register_style( self::HANDLE, 'admin' );

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );

		wp_add_inline_script(
			self::HANDLE,
			'window.prstsearchAdmin = ' . wp_json_encode(
				array(
					'restPath'         => '/' . SearchRoutes::ROUTE_NAMESPACE,
					'providerDefaults' => $this->view->provider()['defaults'],
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		$tabs = $this->tabs();

		if ( array() === $tabs ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'smart-search' ), 403 );
		}

		$current = $this->current_tab( $tabs );
		$hero    = $this->view->hero();

		$this->include_view(
			'page',
			array(
				'tabs'     => $tabs,
				'current'  => $current,
				'hero'     => $hero,
				'notice'   => $this->notice(),
				'tab_data' => $this->tab_data( $current, $hero ),
			)
		);
	}

	/**
	 * Collects the data of the tab that is displayed.
	 *
	 * @param string               $tab  Tab slug.
	 * @param array<string, mixed> $hero Data of the page header, shared with the tabs.
	 * @return array<string, mixed>
	 */
	private function tab_data( string $tab, array $hero ): array {
		$data = array(
			'hero'       => $hero,
			'can_manage' => current_user_can( Capabilities::MANAGE ),
			'is_admin'   => current_user_can( 'manage_options' ),
		);

		if ( SettingsSchema::TAB_SEARCH === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only fills in a form field, which the user then saves or not.
			$suggested = isset( $_GET['recommend'] ) ? sanitize_text_field( wp_unslash( $_GET['recommend'] ) ) : '';

			$data['recommendations'] = $this->view->recommendation_rows( $suggested );
		}

		if ( SettingsSchema::TAB_STATS === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters of a report.
			$data['stats'] = $this->stats->page( $_GET );
		}

		return $data;
	}

	/**
	 * Includes a view file.
	 *
	 * Views read their data from $data and use $fields, $format, $view and $stats;
	 * $this lets a view include another view.
	 *
	 * @param string               $name View name without extension.
	 * @param array<string, mixed> $data Data of the view.
	 */
	public function include_view( string $name, array $data = array() ): void {
		$fields = $this->fields;
		$format = $this->format;
		$view   = $this->view;
		$stats  = $this->stats;

		require $this->views_dir . sanitize_file_name( $name ) . '.php';
	}

	/**
	 * Returns the URL of the page.
	 *
	 * @param string               $tab  Tab to open, empty for the default.
	 * @param array<string, mixed> $args Additional query arguments.
	 */
	public static function url( string $tab = '', array $args = array() ): string {
		$args = array( 'page' => self::SLUG ) + ( '' === $tab ? array() : array( 'tab' => $tab ) ) + $args;

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Returns the tabs the current user may open.
	 *
	 * @return array<string, string> Labels keyed by tab slug.
	 */
	private function tabs(): array {
		$tabs = array();

		if ( current_user_can( Capabilities::MANAGE ) ) {
			$tabs = array(
				SettingsSchema::TAB_CONTENT  => __( 'Content', 'smart-search' ),
				SettingsSchema::TAB_SEARCH   => __( 'Search', 'smart-search' ),
				SettingsSchema::TAB_PROVIDER => __( 'AI provider', 'smart-search' ),
				SettingsSchema::TAB_DISPLAY  => __( 'Display', 'smart-search' ),
			);
		}

		if ( current_user_can( Capabilities::VIEW_STATS ) ) {
			$tabs[ SettingsSchema::TAB_STATS ] = __( 'Statistics', 'smart-search' );
		}

		if ( current_user_can( Capabilities::MANAGE ) ) {
			$tabs[ SettingsSchema::TAB_SECURITY ] = __( 'Security', 'smart-search' );
			$tabs[ SettingsSchema::TAB_TOOLS ]    = __( 'Tools', 'smart-search' );
		}

		return $tabs;
	}

	/**
	 * Returns the requested tab, or the first one the user may open.
	 *
	 * @param array<string, string> $tabs Available tabs.
	 */
	private function current_tab( array $tabs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which tab is displayed.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( $tabs[ $requested ] ) ? $requested : (string) array_key_first( $tabs );
	}

	/**
	 * Returns the notice to show after an action, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a predefined message.
		$code = isset( $_GET['prstsearch_notice'] ) ? sanitize_key( wp_unslash( $_GET['prstsearch_notice'] ) ) : '';

		$notices = array(
			'saved'             => array( 'success', __( 'Settings saved.', 'smart-search' ) ),
			'saved_key_removed' => array( 'success', __( 'Settings saved. The stored API key was removed because the provider or its address changed. Please enter the key for the new service.', 'smart-search' ) ),
			'imported'          => array( 'success', __( 'Settings imported.', 'smart-search' ) ),
			'imported_partly'   => array( 'success', __( 'Settings imported. Some recommended results were left out because their page does not exist on this website.', 'smart-search' ) ),
			'import_failed'     => array( 'error', __( 'The file could not be read. Please choose a file exported by Smart Search.', 'smart-search' ) ),
			'stats_cleared'     => array( 'success', __( 'Statistics deleted.', 'smart-search' ) ),
			'post_included'     => array( 'success', __( 'The content is included in the search again.', 'smart-search' ) ),
		);

		if ( ! isset( $notices[ $code ] ) ) {
			return null;
		}

		return array(
			'type'    => $notices[ $code ][0],
			'message' => $notices[ $code ][1],
		);
	}
}
