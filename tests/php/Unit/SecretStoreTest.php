<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Security\SecretStore;

/**
 * Storage of the API key.
 */
final class SecretStoreTest extends WordPressTestCase {

	private const KEY = 'sk-test-1234567890abcd';

	private string $salt = 'salt-of-the-site';

	protected function setUp(): void {
		parent::setUp();

		// WordPress brings a fallback for servers without the extension; these tests run without WordPress.
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			self::markTestSkipped( 'Needs the sodium extension: php -d extension=sodium vendor/bin/phpunit' );
		}

		Functions\when( 'wp_salt' )->alias( fn (): string => $this->salt );
	}

	public function test_the_key_is_returned_as_it_was_stored(): void {
		$store = new SecretStore();

		$store->set( '  ' . self::KEY . ' ' );

		self::assertSame( self::KEY, $store->get() );
	}

	public function test_the_database_never_holds_the_key_in_plain_text(): void {
		( new SecretStore() )->set( self::KEY );

		$stored = (string) $this->options[ SecretStore::OPTION ];

		self::assertStringNotContainsString( self::KEY, $stored );
		self::assertStringNotContainsString( self::KEY, (string) base64_decode( $stored, true ) );
	}

	public function test_the_same_key_is_stored_differently_each_time(): void {
		$store = new SecretStore();

		$store->set( self::KEY );
		$first = $this->options[ SecretStore::OPTION ];
		$store->set( self::KEY );

		self::assertNotSame( $first, $this->options[ SecretStore::OPTION ] );
	}

	public function test_a_key_stored_by_version_1_0_0_is_read_and_moved_to_the_current_key(): void {
		// What version 1.0.0 wrote: the plain hash of the salt as key, no version mark.
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key   = sodium_crypto_generichash( $this->salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		$this->options[ SecretStore::OPTION ] = base64_encode( $nonce . sodium_crypto_secretbox( self::KEY, $nonce, $key ) );

		self::assertSame( self::KEY, ( new SecretStore() )->get() );
		self::assertStringStartsWith( 'v2:', $this->options[ SecretStore::OPTION ], 'Stored again under the current key.' );
		self::assertSame( self::KEY, ( new SecretStore() )->get() );
	}

	public function test_the_key_is_not_encrypted_with_the_plain_hash_of_the_salt(): void {
		( new SecretStore() )->set( self::KEY );

		$raw   = (string) base64_decode( substr( (string) $this->options[ SecretStore::OPTION ], 3 ), true );
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, sodium_crypto_generichash( $this->salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

		self::assertFalse( $plain, 'The key is derived for this one purpose, not shared with anything else hashing the salt.' );
	}

	public function test_it_tells_where_the_key_comes_from(): void {
		$store = new SecretStore();

		self::assertSame( 'none', $store->source() );

		$store->set( self::KEY );

		self::assertSame( 'database', $store->source() );
	}

	public function test_a_key_is_unreadable_after_the_salts_of_the_site_changed(): void {
		$store = new SecretStore();

		$store->set( self::KEY );
		$this->salt = 'new-salt';

		self::assertSame( '', $store->get() );
		self::assertTrue( $store->is_unreadable() );
	}

	public function test_a_tampered_value_gives_no_key(): void {
		$store = new SecretStore();

		foreach ( array( 'not base64 !', base64_encode( 'short' ), base64_encode( random_bytes( 80 ) ) ) as $stored ) {
			$this->options[ SecretStore::OPTION ] = $stored;

			self::assertSame( '', $store->get() );
		}
	}

	public function test_an_empty_key_removes_the_stored_one(): void {
		$store = new SecretStore();

		$store->set( self::KEY );
		$store->set( '   ' );

		self::assertArrayNotHasKey( SecretStore::OPTION, $this->options );
		self::assertSame( '', $store->get() );
		self::assertFalse( $store->is_unreadable() );
	}

	public function test_only_the_last_four_characters_are_shown(): void {
		$store = new SecretStore();

		self::assertSame( '', $store->masked() );

		$store->set( self::KEY );

		self::assertSame( str_repeat( '•', 12 ) . 'abcd', $store->masked() );
	}
}
