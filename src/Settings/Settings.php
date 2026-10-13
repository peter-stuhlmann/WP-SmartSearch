<?php
/**
 * Typed access to the stored plugin settings.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Settings;

/**
 * Reads and writes the settings option.
 */
final class Settings {

	public const OPTION = 'prstsearch_settings';

	/**
	 * Settings merged with defaults, loaded on first access.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Returns all settings, with defaults for anything not stored yet.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( SettingsSchema::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->values;
	}

	/**
	 * Returns one setting.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Returns a boolean setting.
	 */
	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/**
	 * Returns an integer setting.
	 */
	public function int( string $key ): int {
		return (int) $this->get( $key );
	}

	/**
	 * Returns a float setting.
	 */
	public function float( string $key ): float {
		return (float) $this->get( $key );
	}

	/**
	 * Returns a string setting.
	 */
	public function string( string $key ): string {
		return (string) $this->get( $key );
	}

	/**
	 * Returns a list setting.
	 *
	 * @return string[]
	 */
	public function list( string $key ): array {
		$value = $this->get( $key );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Stores a complete, already sanitized settings array.
	 *
	 * @param array<string, mixed> $values Sanitized settings.
	 */
	public function replace( array $values ): void {
		update_option( self::OPTION, $values );
		$this->forget();
	}

	/**
	 * Drops the in-memory copy so the next read hits the option again.
	 */
	public function forget(): void {
		$this->values = null;
	}
}
