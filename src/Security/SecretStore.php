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
 * The key is encrypted with XSalsa20-Poly1305 (libsodium's secretbox) under a
 * key derived from the site's authentication salt. Someone who only gets hold
 * of the database, through a backup or an SQL injection, cannot read it. Who
 * can also read wp-config.php can: whatever decrypts the key on every request
 * has to be on the server. That is unavoidable for a key that must be sent to
 * the provider, and the reason a key defined as PRSTSEARCH_API_KEY in
 * wp-config.php always wins, so the secret can stay out of the database entirely.
 */
final class SecretStore {

	public const OPTION = 'prstsearch_api_key';

	private const CONSTANT = 'PRSTSEARCH_API_KEY';

	/**
	 * Marks a value encrypted with the current key derivation.
	 */
	private const VERSION = 'v2:';

	/**
	 * What the derived key is for. A key derived for this purpose is used for nothing else.
	 */
	private const CONTEXT = 'prstsearch|api-key|v2';

	/**
	 * The text wp-config-sample.php puts where the salts belong.
	 */
	private const SAMPLE_SALT = 'put your unique phrase here';

	/**
	 * Returns the API key in plain text, or an empty string.
	 */
	public function get(): string {
		if ( $this->is_defined_in_config() ) {
			return (string) constant( self::CONSTANT );
		}

		$stored = get_option( self::OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		if ( str_starts_with( $stored, self::VERSION ) ) {
			return $this->decrypt( substr( $stored, strlen( self::VERSION ) ), $this->encryption_key() );
		}

		// Stored by version 1.0.0, under a key that was the plain hash of the salt. It is moved to the current one.
		$plain = $this->decrypt( $stored, $this->legacy_key() );

		if ( '' !== $plain ) {
			$this->set( $plain );
		}

		return $plain;
	}

	/**
	 * Tells where the API key comes from.
	 *
	 * @return string "config" for wp-config.php, "database" for the encrypted option, "none" if there is no key.
	 */
	public function source(): string {
		return match ( true ) {
			$this->is_defined_in_config()                  => 'config',
			'' !== (string) get_option( self::OPTION, '' ) => 'database',
			default                                        => 'none',
		};
	}

	/**
	 * Tells whether the salts the key is derived from are defined in wp-config.php.
	 *
	 * Where they are not, WordPress generates them and keeps them in the
	 * database, next to what they protect. Then a copy of the database alone
	 * is enough to decrypt the API key.
	 */
	public static function salts_are_in_config(): bool {
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			$value = defined( $name ) ? (string) constant( $name ) : '';

			if ( strlen( $value ) < 32 || self::SAMPLE_SALT === $value ) {
				return false;
			}
		}

		return true;
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
		update_option( self::OPTION, self::VERSION . $this->encrypt( $api_key ), false );
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
	 * Encrypts a value with the key derived from the site salts. A new random nonce makes every result different.
	 */
	private function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->encryption_key() );

		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of ciphertext.
	}

	/**
	 * Decrypts a stored value. Returns an empty string if the salts changed or the value was tampered with.
	 */
	private function decrypt( string $stored, string $key ): string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of ciphertext.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derives the 32 byte key from the WordPress salts.
	 *
	 * The salt is the key of a keyed BLAKE2b hash over the purpose. The result
	 * is bound to this one use: it is not what any other code derives from the
	 * same salt, and it reveals nothing about the salt.
	 */
	private function encryption_key(): string {
		$salt = sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX );

		return sodium_crypto_generichash( self::CONTEXT, $salt, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Returns the key of version 1.0.0, to read what it stored.
	 */
	private function legacy_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
