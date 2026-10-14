<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Rest\SearchRoutes;
use PrstSearch\Security\SearchGuard;
use RuntimeException;
use WP_Error;
use WP_Query;
use WP_REST_Request;

/**
 * The limit on searches and the trap for bots.
 */
final class SearchGuardTest extends RenderingTestCase {

	/**
	 * Whether the current user may manage the plugin.
	 */
	private bool $can_manage = false;

	/**
	 * What the current request submitted.
	 *
	 * @var array<string, mixed>
	 */
	private array $submitted = array();

	protected function setUp(): void {
		parent::setUp();

		$this->can_manage = false;
		$this->submitted  = array();
		$_GET             = array();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		Functions\when( 'current_user_can' )->alias( fn (): bool => $this->can_manage );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->configure(
			array(
				'search_limit'        => 2,
				'search_limit_window' => 'hour',
				'honeypot'            => true,
			)
		);

		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	public function test_the_trap_goes_into_any_search_form(): void {
		$theme_form = '<form role="search" method="get" action="/"><input type="search" name="s" /><button>Go</button></form>';

		$html = $this->guard()->with_trap( $theme_form );

		self::assertStringContainsString( 'name="prstsearch_hp"', $html );
		self::assertStringContainsString( 'tabindex="-1"', $html );
		self::assertStringContainsString( 'aria-hidden="true"', $html );
		self::assertMatchesRegularExpression( '/name="prstsearch_hp".*<\/form>$/s', $html, 'Inside the form, before its end.' );
		self::assertSame( $html, $this->guard()->with_trap( $html ), 'Never twice.' );
		self::assertSame( '<div>no form</div>', $this->guard()->with_trap( '<div>no form</div>' ) );
	}

	public function test_without_the_setting_no_trap_is_laid_and_none_is_sprung(): void {
		$this->configure( array( 'honeypot' => false ) );

		$form = '<form><input name="s" /></form>';

		self::assertSame( $form, $this->guard()->with_trap( $form ) );
		self::assertFalse( $this->guard()->is_trap_filled( array( SearchGuard::TRAP => 'https://spam.example' ) ) );
	}

	public function test_a_bot_that_fills_the_trap_gets_an_empty_result_and_is_not_recorded(): void {
		$_GET = array( SearchGuard::TRAP => 'https://spam.example' );

		self::assertSame( array(), $this->guard()->guard_main_search( null, $this->main_search() ) );

		$data = $this->rest_search( array( SearchGuard::TRAP => 'https://spam.example' ) )->get_data();

		self::assertSame( 0, $data['total'] );
		self::assertSame( array(), $this->log->entries );
	}

	public function test_a_person_leaves_the_trap_empty(): void {
		$_GET = array( SearchGuard::TRAP => '' );

		self::assertNull( $this->guard()->guard_main_search( null, $this->main_search() ) );
		self::assertSame( 1, $this->rest_search()->get_data()['total'] );
	}

	public function test_a_visitor_over_the_limit_goes_on_with_the_basic_search(): void {
		$this->use_ai();
		$this->configure(
			array(
				'mode'                => 'ai',
				'search_limit'        => 2,
				'search_limit_window' => 'hour',
				'match_inside_words'  => true,
			)
		);
		$this->container->get( \PrstSearch\Settings\Settings::class )->forget();

		$this->rest_search();
		$this->rest_search( array( 'q' => 'parcels' ) );

		$requests = count( $this->provider->embedded ) + count( $this->provider->chat_requests );
		$logged   = count( $this->log->entries );
		$basic    = $this->rest_search( array( 'q' => 'worldwide' ) );

		self::assertNotInstanceOf( WP_Error::class, $basic, 'Visitors can always search.' );
		self::assertSame( 1, $basic->get_data()['total'] );
		self::assertTrue( $basic->get_data()['degraded'] );
		self::assertSame( '', $basic->get_data()['answer_html'], 'No AI answer.' );
		self::assertSame( $requests, count( $this->provider->embedded ) + count( $this->provider->chat_requests ), 'The provider was not asked.' );
		self::assertCount( $logged, $this->log->entries, 'Not recorded.' );

		// Nothing found: the whole index is not read for text inside words.
		$this->rest_search( array( 'q' => 'qwertz' ) );

		self::assertNotContains( 'parts', $this->store->calls );
	}

	public function test_the_theme_search_goes_on_with_the_basic_search_too(): void {
		$guard = $this->guard();

		$guard->guard_main_search( null, $this->main_search() );
		$guard->guard_main_search( null, $this->main_search() );

		self::assertFalse( $guard->is_basic() );
		self::assertNull( $guard->guard_main_search( null, $this->main_search() ), 'The search runs.' );
		self::assertTrue( $guard->is_basic(), 'In its basic form.' );

		$guard->refuse();
	}

	public function test_a_visitor_over_the_limit_gets_429_and_the_search_does_not_run(): void {
		$this->block_over_the_limit();

		$this->rest_search();
		$this->rest_search( array( 'q' => 'parcels' ) );

		$refused = $this->rest_search( array( 'q' => 'worldwide' ) );

		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 429, $refused->get_error_data()['status'] );
		self::assertStringContainsString( 'wait a moment', $refused->get_error_message() );
		self::assertCount( 2, $this->log->entries, 'The refused search was not recorded.' );
	}

	public function test_paging_and_refining_do_not_count_as_new_searches(): void {
		$this->rest_search();
		$this->rest_search( array( 'page' => 2 ) );
		$this->rest_search( array( 'refine' => '1' ) );

		self::assertNotInstanceOf( WP_Error::class, $this->rest_search() );
	}

	public function test_the_theme_search_is_limited_too_and_answered_with_429(): void {
		$this->block_over_the_limit();

		$guard = $this->guard();

		self::assertNull( $guard->guard_main_search( null, $this->main_search() ) );
		self::assertNull( $guard->guard_main_search( null, $this->main_search() ) );
		self::assertSame( array(), $guard->guard_main_search( null, $this->main_search() ), 'Stopped before the search runs.' );

		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title, array $args ): void {
				throw new RuntimeException( $args['response'] . ' ' . $message );
			}
		);

		$this->expectExceptionMessage( '429 You have searched very often' );

		$guard->refuse();
	}

	public function test_further_pages_of_the_theme_search_are_not_counted(): void {
		$guard = $this->guard();

		for ( $page = 2; $page < 6; $page++ ) {
			self::assertNull( $guard->guard_main_search( null, $this->main_search( $page ) ) );
		}

		self::assertNull( $guard->guard_main_search( null, $this->main_search() ) );
	}

	public function test_whoever_manages_the_plugin_is_never_limited(): void {
		$this->block_over_the_limit();
		$this->can_manage = true;

		for ( $search = 0; $search < 5; $search++ ) {
			self::assertNotInstanceOf( WP_Error::class, $this->rest_search() );
		}
	}

	public function test_zero_means_no_limit(): void {
		$this->block_over_the_limit();
		$this->configure( array( 'search_limit' => 0 ) );
		$this->container->get( \PrstSearch\Settings\Settings::class )->forget();

		for ( $search = 0; $search < 5; $search++ ) {
			self::assertNotInstanceOf( WP_Error::class, $this->rest_search() );
		}
	}

	public function test_the_limit_of_the_whole_site_applies_to_all_visitors_together(): void {
		$this->configure(
			array(
				'search_limit'        => 0,
				'search_limit_site'   => 2,
				'search_limit_action' => 'block',
			)
		);

		$this->rest_search();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->rest_search();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

		self::assertInstanceOf( WP_Error::class, $this->rest_search() );
	}

	/**
	 * Chooses to refuse searches over the limit instead of the basic search.
	 */
	private function block_over_the_limit(): void {
		$this->configure( array( 'search_limit_action' => 'block' ) );
		$this->container->get( \PrstSearch\Settings\Settings::class )->forget();
	}

	private function guard(): SearchGuard {
		return $this->container->get( SearchGuard::class );
	}

	/**
	 * @param array<string, mixed> $params Parameters besides the query.
	 */
	private function rest_search( array $params = array() ): mixed {
		$response = $this->container->get( SearchRoutes::class )->search(
			new WP_REST_Request(
				$params + array(
					'q'    => 'shipping',
					'page' => 1,
				)
			)
		);

		return $response;
	}

	private function main_search( int $page = 1 ): WP_Query {
		return new WP_Query(
			array(
				's'     => 'shipping',
				'paged' => $page,
			)
		);
	}
}
