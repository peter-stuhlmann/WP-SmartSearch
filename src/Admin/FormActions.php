<?php
/**
 * Handles the forms of the settings page.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\SearchLog;
use PrstSearch\Index\Eligibility;
use PrstSearch\Index\Indexer;
use PrstSearch\Search\Recommendations;
use PrstSearch\Security\Capabilities;
use PrstSearch\Security\SecretStore;
use PrstSearch\Settings\SettingsSchema;
use PrstSearch\Settings\SettingsWriter;
use PrstSearch\Support\Csv;

/**
 * Receives form submissions via admin-post.php.
 *
 * Every handler checks the capability and the nonce first, does one thing and
 * then either sends a file or redirects back to a tab of the settings page.
 */
final class FormActions implements Hookable {

	public const SAVE         = 'prstsearch_save';
	public const EXPORT       = 'prstsearch_export';
	public const IMPORT       = 'prstsearch_import';
	public const CLEAR_STATS  = 'prstsearch_clear_stats';
	public const EXPORT_CSV   = 'prstsearch_export_csv';
	public const INCLUDE_POST = 'prstsearch_include_post';

	private const MAX_IMPORT_SIZE = 100 * KB_IN_BYTES;

	/**
	 * Creates the handlers.
	 */
	public function __construct(
		private readonly SettingsWriter $writer,
		private readonly SecretStore $secrets,
		private readonly SearchLog $log,
		private readonly Indexer $indexer,
		private readonly StatsViewModel $stats,
		private readonly Eligibility $eligibility,
		private readonly Recommendations $recommendations
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$handlers = array(
			self::SAVE         => 'save',
			self::EXPORT       => 'export',
			self::IMPORT       => 'import',
			self::CLEAR_STATS  => 'clear_stats',
			self::EXPORT_CSV   => 'export_csv',
			self::INCLUDE_POST => 'include_post',
		);

		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Saves the settings of one tab.
	 */
	public function save(): void {
		$this->authorize( self::SAVE, Capabilities::MANAGE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$tab = isset( $_POST['prstsearch_tab'] ) ? sanitize_key( wp_unslash( $_POST['prstsearch_tab'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in SettingsSanitizer.
		$input = isset( $_POST[ Fields::INPUT_NAME ] ) && is_array( $_POST[ Fields::INPUT_NAME ] ) ? wp_unslash( $_POST[ Fields::INPUT_NAME ] ) : array();
		// phpcs:enable

		$is_administrator = current_user_can( 'manage_options' );
		$address_before   = $this->provider_address();
		$notice           = 'saved';

		$this->writer->save_tab( $input, $tab, $is_administrator );

		if ( SettingsSchema::TAB_SEARCH === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in authorize(), sanitized row by row in Recommendations.
			$this->recommendations->save_input( isset( $_POST['prstsearch_recommendations'] ) ? wp_unslash( $_POST['prstsearch_recommendations'] ) : array() );
		}

		if ( SettingsSchema::TAB_PROVIDER === $tab && $is_administrator ) {
			$notice = $this->save_api_key( $address_before !== $this->provider_address() );
		}

		$this->redirect( $tab, $notice );
	}

	/**
	 * Sends the settings and the recommended results as a JSON file. The API key is stored separately and never part of it.
	 */
	public function export(): void {
		$this->authorize( self::EXPORT, Capabilities::MANAGE );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smart-search-settings-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo wp_json_encode(
			array(
				'plugin'          => 'smart-search',
				'version'         => PRSTSEARCH_VERSION,
				'settings'        => $this->writer->export(),
				'recommendations' => $this->recommendations->export(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
		);

		exit;
	}

	/**
	 * Imports settings from an uploaded export file.
	 */
	public function import(): void {
		// An import replaces every setting, including who may manage the plugin.
		$this->authorize( self::IMPORT, 'manage_options' );

		$export = $this->uploaded_export();

		if ( null === $export ) {
			$this->redirect( SettingsSchema::TAB_TOOLS, 'import_failed' );
		}

		$this->writer->import( $export['settings'] );

		// A file from before recommendations were exported says nothing about them. Then they stay as they are.
		$skipped = array_key_exists( 'recommendations', $export ) ? $this->recommendations->import( $export['recommendations'] ) : 0;

		$this->redirect( SettingsSchema::TAB_TOOLS, $skipped > 0 ? 'imported_partly' : 'imported' );
	}

	/**
	 * Deletes all logged searches.
	 */
	public function clear_stats(): void {
		$this->authorize( self::CLEAR_STATS, Capabilities::MANAGE );

		$this->log->truncate();
		$this->redirect( SettingsSchema::TAB_STATS, 'stats_cleared' );
	}

	/**
	 * Sends the searches of the selected period as a CSV file.
	 */
	public function export_csv(): void {
		$this->authorize( self::EXPORT_CSV, Capabilities::VIEW_STATS );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
		$range = $this->stats->range( $_GET );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smart-search-' . $range->start->format( 'Y-m-d' ) . '-' . $range->last_day()->format( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			exit;
		}

		// The byte order mark makes Excel read the file as UTF-8.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming a download.
		// No escape character: with the default backslash, a quote after a backslash would not be
		// doubled, and a crafted search phrase could end its cell early and start a formula.
		fputcsv( $output, array_values( $this->stats->table_headers() ), ';', '"', '' );

		foreach ( $this->stats->export_rows( $range ) as $row ) {
			fputcsv( $output, array_map( array( Csv::class, 'cell' ), array_values( $row ) ), ';', '"', '' );
		}

		exit;
	}

	/**
	 * Removes the manual exclusion of a post and indexes it.
	 */
	public function include_post(): void {
		$this->authorize( self::INCLUDE_POST, Capabilities::MANAGE );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().

		if ( $post_id > 0 ) {
			$this->eligibility->set_excluded_manually( $post_id, false );
			$this->indexer->index_post( $post_id );
		}

		$this->redirect( SettingsSchema::TAB_CONTENT, 'post_included' );
	}

	/**
	 * Stops the request unless the user has the capability and sent a valid nonce.
	 */
	private function authorize( string $action, string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smart-search' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores or removes the API key submitted with the provider form.
	 *
	 * @param bool $address_changed Whether provider or endpoint address were changed with this save.
	 * @return string Code of the notice to show.
	 */
	private function save_api_key( bool $address_changed ): string {
		if ( $this->secrets->is_defined_in_config() ) {
			return 'saved';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		if ( ! empty( $_POST['prstsearch_remove_api_key'] ) ) {
			$this->secrets->set( '' );
			return 'saved';
		}

		// An API key must be stored exactly as entered, so it is only trimmed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key = isset( $_POST['prstsearch_api_key'] ) && is_string( $_POST['prstsearch_api_key'] ) ? trim( wp_unslash( $_POST['prstsearch_api_key'] ) ) : '';
		// phpcs:enable

		if ( '' !== $api_key ) {
			$this->secrets->set( $api_key );
			return 'saved';
		}

		// An empty field means "keep the stored key": the form never prints it. But a key
		// belongs to the service it was issued by and must not follow to another address.
		if ( $address_changed && '' !== $this->secrets->get() ) {
			$this->secrets->set( '' );
			return 'saved_key_removed';
		}

		return 'saved';
	}

	/**
	 * Returns what identifies the service the API key is sent to.
	 */
	private function provider_address(): string {
		$settings = $this->writer->export();

		return $settings['provider'] . '|' . $settings['base_url'];
	}

	/**
	 * Reads the uploaded export file.
	 *
	 * @return array<string, mixed>|null The decoded file, with its "settings" checked to be a list. Null if the upload is not a valid export.
	 */
	private function uploaded_export(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Verified in authorize(); the content is validated by the sanitizer.
		$file = $_FILES['prstsearch_file'] ?? null;
		// phpcs:enable

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return null;
		}

		if ( (int) $file['size'] > self::MAX_IMPORT_SIZE ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary upload.
		$data = json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true );

		if ( ! is_array( $data ) || 'smart-search' !== ( $data['plugin'] ?? null ) || ! is_array( $data['settings'] ?? null ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Redirects back to a tab of the settings page and ends the request.
	 */
	private function redirect( string $tab, string $notice ): never {
		wp_safe_redirect( AdminPage::url( $tab, array( 'prstsearch_notice' => $notice ) ) );
		exit;
	}
}
