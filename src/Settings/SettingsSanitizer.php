<?php
/**
 * Validates submitted settings against the schema.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Settings;

/**
 * Turns untrusted input into a complete, valid settings array.
 *
 * The class is free of WordPress calls so it can be unit tested in isolation.
 */
final class SettingsSanitizer {

	/**
	 * Creates the sanitizer.
	 *
	 * @param array<string, array<string, mixed>> $fields       Field definitions from SettingsSchema.
	 * @param array<string, string[]>             $list_choices Allowed values for list fields, keyed by setting name.
	 */
	public function __construct(
		private readonly array $fields,
		private readonly array $list_choices = array()
	) {}

	/**
	 * Merges the fields of one tab into the stored settings.
	 *
	 * Only fields of the submitted tab are touched. That keeps the other tabs
	 * intact and lets unchecked checkboxes of the current tab become false.
	 *
	 * @param array<string, mixed> $input   Raw submitted values.
	 * @param array<string, mixed> $current Currently stored settings.
	 * @param string|null          $tab     Submitted tab, or null to sanitize every field (import).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input, array $current, ?string $tab = null ): array {
		$clean = array();

		foreach ( $this->fields as $key => $field ) {
			$stored = array_key_exists( $key, $current ) ? $current[ $key ] : $field['default'];

			if ( null !== $tab && $field['tab'] !== $tab ) {
				$clean[ $key ] = $this->sanitize_value( $key, $field, $stored );
				continue;
			}

			$submitted     = $this->submitted_value( $input, $key, $field, $stored, null !== $tab );
			$clean[ $key ] = $this->sanitize_value( $key, $field, $submitted );
		}

		return $clean;
	}

	/**
	 * Picks the value to sanitize for a field that belongs to the submitted tab.
	 *
	 * @param array<string, mixed> $input     Raw submitted values.
	 * @param array<string, mixed> $field     Field definition.
	 * @param mixed                $stored    Stored value.
	 * @param bool                 $from_form Whether the input comes from an HTML form.
	 */
	private function submitted_value( array $input, string $key, array $field, mixed $stored, bool $from_form ): mixed {
		if ( array_key_exists( $key, $input ) ) {
			return $input[ $key ];
		}

		// Browsers omit unchecked checkboxes and empty multi-selects.
		if ( $from_form && SettingsSchema::TYPE_BOOL === $field['type'] ) {
			return false;
		}

		if ( $from_form && SettingsSchema::TYPE_LIST === $field['type'] ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Sanitizes one value according to its field type.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function sanitize_value( string $key, array $field, mixed $value ): mixed {
		return match ( $field['type'] ) {
			SettingsSchema::TYPE_BOOL   => $this->to_bool( $value ),
			SettingsSchema::TYPE_INT    => (int) $this->clamp( $this->to_number( $value, $field['default'] ), $field ),
			SettingsSchema::TYPE_FLOAT  => (float) $this->clamp( $this->to_number( $value, $field['default'] ), $field ),
			SettingsSchema::TYPE_CHOICE => in_array( $value, $field['choices'], true ) ? $value : $field['default'],
			SettingsSchema::TYPE_LIST   => $this->to_list( $key, $value ),
			SettingsSchema::TYPE_URL    => $this->to_url( $value ),
			SettingsSchema::TYPE_TEXT   => $this->to_text( $value, true ),
			default                     => $this->to_text( $value, false ),
		};
	}

	/**
	 * Converts form values such as "1", "on" or "true" to a boolean.
	 */
	private function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'on', 'true', 'yes' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Converts a value to a number and accepts a decimal comma.
	 */
	private function to_number( mixed $value, int|float $fallback ): float {
		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
		}

		return is_numeric( $value ) ? (float) $value : (float) $fallback;
	}

	/**
	 * Limits a number to the bounds of its field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function clamp( float $value, array $field ): float {
		return max( (float) $field['min'], min( (float) $field['max'], $value ) );
	}

	/**
	 * Keeps the unique, allowed entries of a list.
	 *
	 * @return string[]
	 */
	private function to_list( string $key, mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array_values( array_unique( array_filter( $value, 'is_string' ) ) );

		if ( ! array_key_exists( $key, $this->list_choices ) ) {
			return $items;
		}

		return array_values( array_intersect( $items, $this->list_choices[ $key ] ) );
	}

	/**
	 * Accepts plain http and https URLs, without query, fragment or credentials, and strips a trailing slash.
	 */
	private function to_url( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$parts = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Keeps the class free of WordPress.

		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		// Paths are appended to the address. A query or fragment would swallow them, and
		// credentials in a URL end up in logs.
		foreach ( array( 'query', 'fragment', 'user', 'pass' ) as $unwanted ) {
			if ( isset( $parts[ $unwanted ] ) ) {
				return '';
			}
		}

		return rtrim( $value, '/' );
	}

	/**
	 * Removes markup and control characters from text.
	 */
	private function to_text( mixed $value, bool $multiline ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text    = strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Keeps the class free of WordPress.
		$pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

		return trim( (string) preg_replace( $pattern, '', $text ) );
	}
}
