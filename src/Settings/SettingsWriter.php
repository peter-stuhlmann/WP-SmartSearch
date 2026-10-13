<?php
/**
 * Validates and stores settings.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Settings;

use PrstSearch\Index\Eligibility;

/**
 * The only way settings from forms and imports reach the database.
 */
final class SettingsWriter {

	private const PRICE_KEYS = array( 'price_embedding', 'price_input', 'price_output', 'currency' );

	/**
	 * Settings that decide where the API key is sent and who may use the plugin.
	 *
	 * A role that was merely allowed to manage the search must not be able to
	 * point the stored key at a server of its own, or to hand out access.
	 */
	private const ADMINISTRATOR_KEYS = array( 'provider', 'base_url', 'roles_manage', 'roles_stats' );

	/**
	 * Creates the writer.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Eligibility $eligibility
	) {}

	/**
	 * Stores the fields of one settings tab.
	 *
	 * @param array<string, mixed> $input            Raw form values.
	 * @param string               $tab              Submitted tab.
	 * @param bool                 $is_administrator Whether the user may change the settings reserved for administrators.
	 */
	public function save_tab( array $input, string $tab, bool $is_administrator ): void {
		$current = $this->settings->all();
		$values  = $this->sanitizer()->sanitize( $input, $current, $tab );

		if ( ! $is_administrator ) {
			$values = array_merge( $values, array_intersect_key( $current, array_flip( self::ADMINISTRATOR_KEYS ) ) );
		}

		if ( SettingsSchema::TAB_CONTENT === $tab ) {
			$values = $this->with_known_post_types( $values );
		}

		$this->store( $values );
	}

	/**
	 * Replaces all settings with imported ones. Missing or invalid values fall back to defaults.
	 *
	 * @param array<string, mixed> $data Decoded export file.
	 */
	public function import( array $data ): void {
		$this->store( $this->with_known_post_types( $this->sanitizer()->sanitize( $data, SettingsSchema::defaults() ) ) );
	}

	/**
	 * Returns the settings as they are written to an export file.
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		return $this->settings->all();
	}

	/**
	 * Stores sanitized settings, with the bookkeeping values no form sends.
	 *
	 * @param array<string, mixed> $values Sanitized settings.
	 */
	private function store( array $values ): void {
		$current = $this->settings->all();

		foreach ( self::PRICE_KEYS as $key ) {
			if ( $values[ $key ] !== $current[ $key ] ) {
				$values['prices_updated'] = wp_date( 'Y-m-d' );
				break;
			}
		}

		$this->settings->replace( $values );
	}

	/**
	 * Notes which content types existed when the choice of types was made.
	 *
	 * A type registered later is then recognisably new, and can be searched
	 * automatically, while a type that was offered and not ticked stays out.
	 * This must only happen together with the choice itself: recording the
	 * types on any other save would turn "new" into "deselected".
	 *
	 * @param array<string, mixed> $values Sanitized settings.
	 * @return array<string, mixed>
	 */
	private function with_known_post_types( array $values ): array {
		$values['known_post_types'] = array_keys( $this->eligibility->available_post_types() );

		return $values;
	}

	/**
	 * Builds a sanitizer that knows the content types and roles of this site.
	 */
	private function sanitizer(): SettingsSanitizer {
		$roles = array_keys( wp_roles()->get_names() );

		return new SettingsSanitizer(
			SettingsSchema::fields(),
			array(
				'post_types'   => array_keys( $this->eligibility->available_post_types() ),
				'roles_manage' => $roles,
				'roles_stats'  => $roles,
			)
		);
	}
}
