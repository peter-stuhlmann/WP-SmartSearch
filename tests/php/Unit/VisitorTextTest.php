<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Frontend\VisitorText;
use PrstSearch\Plugin;
use PrstSearch\Search\AnswerService;
use PrstSearch\Security\SearchGuard;
use PrstSearch\Settings\Settings;

/**
 * How the texts of the search speak to visitors.
 */
final class VisitorTextTest extends SearchTestCase {

	private string $locale = 'de_DE';

	protected function setUp(): void {
		parent::setUp();

		$this->locale = 'de_DE';

		Functions\when( 'get_locale' )->alias( fn (): string => $this->locale );
		// The formal form is the same English text with a context; marked here to tell them apart.
		Functions\when( '_x' )->alias( static fn ( string $text, string $context ): string => '[' . $context . '] ' . $text );
	}

	public function test_automatic_follows_the_language_of_the_website(): void {
		self::assertStringStartsNotWith( '[formal address]', $this->texts()->get( 'too_many' ) );

		$this->locale = 'de_DE_formal';

		self::assertStringStartsWith( '[formal address]', $this->texts()->get( 'too_many' ) );
	}

	public function test_the_setting_decides_over_the_language(): void {
		$this->locale = 'de_DE_formal';
		$this->configure( array( 'visitor_address' => 'informal' ) );

		self::assertFalse( $this->texts()->is_formal() );

		$this->locale = 'de_DE';
		$this->configure( array( 'visitor_address' => 'formal' ) );

		self::assertTrue( $this->texts()->is_formal() );
	}

	public function test_every_text_for_visitors_exists_in_both_forms(): void {
		$this->configure( array( 'visitor_address' => 'formal' ) );

		foreach ( array( 'error', 'more_error', 'too_many', 'answer_note', 'corrected', 'no_results', 'privacy_log', 'privacy_ai' ) as $key ) {
			self::assertStringStartsWith( '[formal address] ', $this->texts()->get( $key ), $key );
		}
	}

	public function test_the_message_for_too_many_searches_can_be_written_in_the_settings(): void {
		self::assertStringContainsString( 'searched very often', $this->guard()->message(), 'Empty: the standard text.' );

		$this->configure( array( 'search_limit_message' => 'Bitte versuchen Sie es in einer Minute erneut.' ) );

		self::assertSame( 'Bitte versuchen Sie es in einer Minute erneut.', $this->guard()->message() );
	}

	public function test_a_multilingual_plugin_translates_the_written_message(): void {
		$this->configure( array( 'search_limit_message' => 'Bitte versuchen Sie es später erneut.' ) );

		Functions\when( 'pll__' )->alias( static fn ( string $text ): string => 'Please try again later.' );

		self::assertSame( 'Please try again later.', $this->guard()->message() );
	}

	public function test_the_ai_answer_is_told_to_address_the_reader_formally(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );

		$this->use_ai();
		$this->configure(
			array(
				'mode'            => 'ai',
				'visitor_address' => 'formal',
			)
		);
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$this->container->get( AnswerService::class )->answer( 'shipping' );

		self::assertStringContainsString( 'Address the reader formally', $this->provider->chat_requests[0]['messages'][0]['content'] );
	}

	public function test_a_formal_locale_without_a_translation_of_its_own_loads_the_informal_one(): void {
		if ( ! defined( 'PRSTSEARCH_FILE' ) ) {
			define( 'PRSTSEARCH_FILE', dirname( __DIR__, 3 ) . '/smart-search.php' );
		}

		self::assertSame( 'de_DE', Plugin::informal_locale( 'de_DE_formal', 'smart-search' ) );
		self::assertSame( 'de_DE_formal', Plugin::informal_locale( 'de_DE_formal', 'other-plugin' ) );
		self::assertSame( 'en_US', Plugin::informal_locale( 'en_US', 'smart-search' ) );
		self::assertSame( '/languages/smart-search-de_DE-prstsearch-admin.json', Plugin::informal_script_translations( '/languages/smart-search-de_DE_formal-prstsearch-admin.json', 'prstsearch-admin', 'smart-search' ) );
	}

	/**
	 * Changes settings the way saving them does: what was read before is forgotten.
	 *
	 * @param array<string, mixed> $settings Settings that differ.
	 */
	protected function configure( array $settings ): void {
		parent::configure( $settings );

		if ( isset( $this->container ) ) {
			$this->container->get( Settings::class )->forget();
		}
	}

	private function texts(): VisitorText {
		return $this->container->get( VisitorText::class );
	}

	private function guard(): SearchGuard {
		return $this->container->get( SearchGuard::class );
	}
}
