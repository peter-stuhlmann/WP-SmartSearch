<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Settings\SettingsSanitizer;
use PrstSearch\Settings\SettingsSchema;

final class SettingsSanitizerTest extends TestCase {

	private SettingsSanitizer $sanitizer;

	protected function setUp(): void {
		$this->sanitizer = new SettingsSanitizer(
			SettingsSchema::fields(),
			array( 'post_types' => array( 'post', 'page', 'product' ) )
		);
	}

	public function test_result_always_contains_every_setting(): void {
		$clean = $this->sanitizer->sanitize( array(), array() );

		self::assertSame( array_keys( SettingsSchema::fields() ), array_keys( $clean ) );
		self::assertSame( SettingsSchema::defaults(), $clean );
	}

	public function test_only_the_submitted_tab_is_changed(): void {
		$current = array_merge( SettingsSchema::defaults(), array( 'provider' => 'mistral' ) );

		$clean = $this->sanitizer->sanitize(
			array(
				'mode'     => 'semantic',
				'provider' => 'gemini',
			),
			$current,
			SettingsSchema::TAB_SEARCH
		);

		self::assertSame( 'semantic', $clean['mode'] );
		self::assertSame( 'mistral', $clean['provider'] );
	}

	public function test_unchecked_checkbox_of_the_submitted_tab_becomes_false(): void {
		$current = array_merge( SettingsSchema::defaults(), array( 'boost_title' => true ) );
		$clean   = $this->sanitizer->sanitize( array(), $current, SettingsSchema::TAB_SEARCH );

		self::assertFalse( $clean['boost_title'] );
		// A checkbox of another tab keeps its value.
		self::assertTrue( $clean['tracking_enabled'] );
	}

	public function test_empty_list_of_the_submitted_tab_is_stored_as_empty(): void {
		$clean = $this->sanitizer->sanitize( array(), SettingsSchema::defaults(), SettingsSchema::TAB_CONTENT );

		self::assertSame( array(), $clean['post_types'] );
	}

	public function test_lists_only_keep_allowed_values(): void {
		$clean = $this->sanitizer->sanitize(
			array( 'post_types' => array( 'page', 'attachment', 'page', 42, 'product' ) ),
			SettingsSchema::defaults(),
			SettingsSchema::TAB_CONTENT
		);

		self::assertSame( array( 'page', 'product' ), $clean['post_types'] );
	}

	public function test_invalid_choice_falls_back_to_the_default(): void {
		$clean = $this->sanitizer->sanitize( array( 'mode' => 'telepathy' ), array(), SettingsSchema::TAB_SEARCH );

		self::assertSame( 'keyword', $clean['mode'] );
	}

	public function test_numbers_are_clamped_and_accept_a_decimal_comma(): void {
		$search   = $this->sanitizer->sanitize( array( 'results_per_page' => '5000' ), array(), SettingsSchema::TAB_SEARCH );
		$provider = $this->sanitizer->sanitize(
			array(
				'monthly_budget' => '12,50',
				'price_input'    => '',
			),
			array(),
			SettingsSchema::TAB_PROVIDER
		);

		self::assertSame( 100, $search['results_per_page'] );
		self::assertSame( 12.5, $provider['monthly_budget'] );
		// An empty price means "use the list price".
		self::assertSame( -1.0, $provider['price_input'] );
	}

	public function test_only_http_urls_are_accepted(): void {
		$sanitize = fn ( string $url ): string => $this->sanitizer->sanitize( array( 'base_url' => $url ), array(), SettingsSchema::TAB_PROVIDER )['base_url'];

		self::assertSame( 'http://localhost:11434/v1', $sanitize( ' http://localhost:11434/v1/ ' ) );
		self::assertSame( '', $sanitize( 'javascript:alert(1)' ) );
		self::assertSame( '', $sanitize( 'file:///etc/passwd' ) );
		self::assertSame( '', $sanitize( 'not a url' ) );
	}

	public function test_text_is_stripped_of_markup(): void {
		$clean = $this->sanitizer->sanitize(
			array(
				'embedding_model'     => "<b>model</b>\n-x",
				'answer_instructions' => "Be <i>brief</i>.\nUse du.",
			),
			array(),
			SettingsSchema::TAB_PROVIDER
		);

		self::assertSame( 'model-x', $clean['embedding_model'] );
		self::assertSame( "Be brief.\nUse du.", $clean['answer_instructions'] );
	}

	public function test_import_sanitizes_every_field_and_ignores_unknown_keys(): void {
		$clean = $this->sanitizer->sanitize(
			array(
				'mode'             => 'ai',
				'tracking_enabled' => 'false',
				'unknown'          => 'value',
			),
			SettingsSchema::defaults()
		);

		self::assertSame( 'ai', $clean['mode'] );
		self::assertFalse( $clean['tracking_enabled'] );
		self::assertArrayNotHasKey( 'unknown', $clean );
	}
}
