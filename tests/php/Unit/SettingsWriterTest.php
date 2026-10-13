<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Index\Eligibility;
use PrstSearch\Settings\Settings;
use PrstSearch\Settings\SettingsSchema;
use PrstSearch\Settings\SettingsWriter;

/**
 * Saving the settings forms.
 */
final class SettingsWriterTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The site has three public content types; "book" was registered after the last save.
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => self::post_type( 'Posts' ),
				'page' => self::post_type( 'Pages' ),
				'book' => self::post_type( 'Books' ),
			)
		);

		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				/**
				 * @return array<string, string>
				 */
				public function get_names(): array {
					return array(
						'administrator' => 'Administrator',
						'editor'        => 'Editor',
					);
				}
			}
		);

		Functions\when( 'wp_date' )->justReturn( '2026-10-12' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->options[ Settings::OPTION ] = array(
			'post_types'       => array( 'post', 'page' ),
			'known_post_types' => array( 'post', 'page' ),
			'provider'         => 'openai',
			'roles_manage'     => array(),
		);
	}

	public function test_saving_another_tab_does_not_turn_a_new_content_type_into_a_deselected_one(): void {
		$this->writer()->save_tab( array( 'mode' => 'keyword' ), SettingsSchema::TAB_SEARCH, true );

		self::assertSame( array( 'post', 'page' ), $this->stored( 'known_post_types' ) );
	}

	public function test_saving_the_choice_of_content_types_records_which_ones_were_offered(): void {
		$this->writer()->save_tab( array( 'post_types' => array( 'post' ) ), SettingsSchema::TAB_CONTENT, true );

		self::assertSame( array( 'post' ), $this->stored( 'post_types' ) );
		self::assertSame( array( 'post', 'page', 'book' ), $this->stored( 'known_post_types' ) );
	}

	public function test_an_import_records_the_offered_content_types_too(): void {
		$this->writer()->import( array( 'post_types' => array( 'page' ) ) );

		self::assertSame( array( 'post', 'page', 'book' ), $this->stored( 'known_post_types' ) );
	}

	public function test_only_administrators_can_change_where_the_content_is_sent(): void {
		$input = array(
			'provider'     => 'mistral',
			'roles_manage' => array( 'editor' ),
		);

		$this->writer()->save_tab( $input, SettingsSchema::TAB_PROVIDER, false );

		self::assertSame( 'openai', $this->stored( 'provider' ) );
		self::assertSame( array(), $this->stored( 'roles_manage' ) );

		$this->writer()->save_tab( $input, SettingsSchema::TAB_PROVIDER, true );

		self::assertSame( 'mistral', $this->stored( 'provider' ) );
	}

	public function test_a_changed_price_is_dated(): void {
		$this->writer()->save_tab( array( 'price_input' => '0.5' ), SettingsSchema::TAB_PROVIDER, true );

		self::assertSame( '2026-10-12', $this->stored( 'prices_updated' ) );
	}

	private function writer(): SettingsWriter {
		$settings = new Settings();

		return new SettingsWriter( $settings, new Eligibility( $settings ) );
	}

	private function stored( string $key ): mixed {
		return $this->options[ Settings::OPTION ][ $key ];
	}

	private static function post_type( string $name ): object {
		return (object) array( 'labels' => (object) array( 'name' => $name ) );
	}
}
