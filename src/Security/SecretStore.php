<?php
/**
 * Stores the provider API key.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Security;

/**
 * Keeps the API key encrypted at rest.
 *
 * A key defined as PRSTSEARCH_API_KEY in wp-config.php always wins, so the
 * secret can stay out of the database entirely.
 */
final class SecretStore {

	public const OPTION = 'prstsearch_api_key';

	private const CONSTANT = 'PRSTSEARCH_API_KEY';

	/**
	 * Returns the API key in plain text, or an empty string.
	 */
	public function get(): string {
		if ( $this->is_defined_in_config() ) {
			return (string) constant( self::CONSTANT );
		}

		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->decrypt( $stored ) : '';
	}

	/**
	 * Encrypts and stores the API key. An empty value removes it.
	 */
	public function set( string $api_key ): void {
		$api_key = trim( $api_key );

		if ( '' === $api_key ) {
			delete_option( self::OPTION );
			return;
		}

		// Not autoloaded: the key is only needed when a provider is called.
		update_option( self::OPTION, $this->encrypt( $api_key ), false );
	}

	/**
	 * Tells whether a key is stored but can no longer be decrypted, e.g. after the site's salts changed.
	 */
	public function is_unreadable(): bool {
		return ! $this->is_defined_in_config() && '' !== (string) get_option( self::OPTION, '' ) && '' === $this->get();
	}

	/**
	 * Tells whether the key comes from wp-config.php and cannot be edited in the UI.
	 */
	public function is_defined_in_config(): bool {
		return defined( self::CONSTANT ) && '' !== (string) constant( self::CONSTANT );
	}

	/**
	 * Returns the key with everything but the last four characters hidden.
	 */
	public function masked(): string {
		$key = $this->get();

		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '•', 12 ) . substr( $key, -4 );
	}

	/**
	 * Encrypts a value with a key derived from the site salts.
	 */
	private function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->encryption_key() );

		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of ciphertext.
	}

	/**
	 * Decrypts a stored value. Returns an empty string if the salts changed.
	 */
	private function decrypt( string $stored ): string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of ciphertext.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->encryption_key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derives a 32 byte key from the WordPress salts.
	 */
	private function encryption_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
