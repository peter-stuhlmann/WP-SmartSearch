<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Admin\FormActions;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\WpHttpTransport;
use PrstSearch\Search\Recommendations;
use PrstSearch\Security\Capabilities;
use PrstSearch\Settings\Settings;
use RuntimeException;
use wpdb;

/**
 * The forms of the settings page, and the transport that talks to the provider.
 *
 * Both end where WordPress begins: a form handler redirects and exits, the
 * transport hands the request to the HTTP API. The tests stop them at that edge.
 */
final class AdminAndTransportTest extends SearchTestCase {

	/**
	 * Capabilities of the current user.
	 *
	 * @var string[]
	 */
	private array $capabilities = array();

	/**
	 * Nonce actions that were checked.
	 *
	 * @var string[]
	 */
	private array $checked = array();

	protected function setUp(): void {
		parent::setUp();

		$this->capabilities = array();
		$this->checked      = array();

		$_POST  = array();
		$_FILES = array();

		$this->container->set( wpdb::class, static fn (): wpdb => new wpdb() );

		Functions\when( 'current_user_can' )->alias( fn ( string $capability ): bool => in_array( $capability, $this->capabilities, true ) );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action ): bool {
				$this->checked[] = $action;

				return true;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, int $status ): never {
				throw new RuntimeException( 'died with ' . $status );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): never {
				// The handler would exit after this line. The test leaves it here instead.
				throw new RuntimeException( $url );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_date' )->justReturn( '2026-10-13' );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				/**
				 * @return array<string, string>
				 */
				public function get_names(): array {
					return array( 'administrator' => 'Administrator' );
				}
			}
		);

		$this->add_page( 10, 'Contact', 'Find us in the old town.' );
	}

	protected function tearDown(): void {
		$_POST  = array();
		$_FILES = array();

		parent::tearDown();
	}

	public function test_a_form_is_refused_without_the_capability_and_before_anything_is_read(): void {
		$_POST = array(
			'prstsearch_tab' => 'search',
			'prstsearch'     => array( 'mode' => 'ai' ),
		);

		self::assertSame( 'died with 403', $this->run_action( 'save' ) );
		self::assertSame( array(), $this->checked );
		self::assertSame( 'keyword', $this->container->get( Settings::class )->string( 'mode' ) );
	}

	public function test_saving_checks_the_nonce_stores_the_tab_and_returns_to_it(): void {
		$this->capabilities = array( Capabilities::MANAGE );

		$_POST = array(
			'prstsearch_tab'             => 'search',
			'prstsearch'                 => array( 'hide_weak_results' => '1' ),
			'prstsearch_recommendations' => array(
				array(
					'terms' => 'opening hours, open',
					'page'  => 'Contact #10',
				),
				array(
					'terms' => '',
					'page'  => '',
				),
			),
		);

		$redirect = $this->run_action( 'save' );

		self::assertSame( array( FormActions::SAVE ), $this->checked );
		self::assertStringContainsString( 'tab=search', $redirect );
		self::assertStringContainsString( 'prstsearch_notice=saved', $redirect );
		self::assertTrue( $this->options[ Settings::OPTION ]['hide_weak_results'] );
		self::assertSame(
			array(
				array(
					'terms'   => array( 'opening hours', 'open' ),
					'post_id' => 10,
				),
			),
			$this->options[ Recommendations::OPTION ]
		);
	}

	public function test_saving_another_tab_leaves_the_recommendations_alone(): void {
		$this->capabilities = array( Capabilities::MANAGE );

		$this->options[ Recommendations::OPTION ] = array(
			array(
				'terms'   => array( 'open' ),
				'post_id' => 10,
			),
		);

		$_POST = array(
			'prstsearch_tab' => 'display',
			'prstsearch'     => array(),
		);

		$this->run_action( 'save' );

		self::assertCount( 1, $this->options[ Recommendations::OPTION ] );
	}

	public function test_a_manager_who_is_no_administrator_cannot_import_or_point_the_plugin_elsewhere(): void {
		$this->capabilities = array( Capabilities::MANAGE );

		self::assertSame( 'died with 403', $this->run_action( 'import' ), 'An import replaces everything, including who may manage the plugin.' );

		$_POST = array(
			'prstsearch_tab' => 'provider',
			'prstsearch'     => array(
				'provider' => 'custom',
				'base_url' => 'https://attacker.example/v1',
			),
		);

		$this->run_action( 'save' );

		self::assertNotSame( 'custom', $this->options[ Settings::OPTION ]['provider'] );
		self::assertSame( '', $this->options[ Settings::OPTION ]['base_url'] );
	}

	public function test_deleting_the_statistics_empties_the_log(): void {
		$this->capabilities = array( Capabilities::MANAGE );
		$this->log->insert( array( 'query' => 'shipping' ) );

		$redirect = $this->run_action( 'clear_stats' );

		self::assertSame( array(), $this->log->entries );
		self::assertSame( array( FormActions::CLEAR_STATS ), $this->checked );
		self::assertStringContainsString( 'stats_cleared', $redirect );
	}

	public function test_a_response_that_arrives_in_one_piece_is_handed_over_once(): void {
		$this->respond_with( 200, 'data: {"choices":[]}' );

		$received = array();
		$result   = ( new WpHttpTransport() )->post_json_stream( 'https://ai.example/v1/chat', array(), array( 'stream' => true ), 30, $this->collect( $received ) );

		self::assertSame( array( 'data: {"choices":[]}' ), $received );
		self::assertSame( 200, $result['status'] );
		self::assertFalse( has_action( 'requests-curl.before_send' ), 'The hook that reads the stream is gone again.' );
	}

	public function test_an_error_response_is_returned_but_never_handed_over_as_part_of_an_answer(): void {
		$this->respond_with( 500, '{"error":{"message":"Overloaded"}}' );

		$received = array();
		$result   = ( new WpHttpTransport() )->post_json_stream( 'https://ai.example/v1/chat', array(), array(), 30, $this->collect( $received ) );

		self::assertSame( array(), $received );
		self::assertSame( 500, $result['status'] );
		self::assertSame( '{"error":{"message":"Overloaded"}}', $result['body'] );
	}

	public function test_a_request_that_cannot_be_sent_is_a_provider_error_and_leaves_no_hook_behind(): void {
		Functions\when( 'wp_remote_post' )->justReturn( new \stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$error = new class() {
			public function get_error_message(): string {
				return 'cURL error 28: Operation timed out';
			}
		};

		Functions\when( 'wp_remote_post' )->justReturn( $error );
		Functions\when( 'esc_html' )->returnArg();

		$received = array();

		try {
			( new WpHttpTransport() )->post_json_stream( 'https://ai.example/v1/chat', array(), array(), 30, $this->collect( $received ) );
			self::fail( 'A timeout has to be reported.' );
		} catch ( ProviderException $exception ) {
			self::assertStringContainsString( 'timed out', $exception->getMessage() );
		}

		self::assertFalse( has_action( 'requests-curl.before_send' ) );
		self::assertSame( array(), $received );
	}

	public function test_requests_carry_the_payload_as_json_and_follow_no_redirect(): void {
		$sent = array();

		Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$sent ): array {
				$sent = $args + array( 'url' => $url );

				return array();
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		( new WpHttpTransport() )->post_json( 'https://ai.example/v1/embeddings', array( 'Authorization' => 'Bearer key' ), array( 'input' => array( 'a' ) ), 30 );

		self::assertSame( '{"input":["a"]}', $sent['body'] );
		self::assertSame( 0, $sent['redirection'], 'The request carries the API key, which must not travel to another host.' );
		self::assertSame( 'application/json', $sent['headers']['Content-Type'] );
		self::assertSame( 'Bearer key', $sent['headers']['Authorization'] );
	}

	/**
	 * Runs a form handler up to the point where it would leave PHP, and returns where it went.
	 *
	 * @return string The address it redirected to, or "died with <status>".
	 */
	private function run_action( string $method ): string {
		try {
			$this->container->get( FormActions::class )->$method();
		} catch ( RuntimeException $left ) {
			return $left->getMessage();
		}

		self::fail( 'The handler did not finish with a redirect.' );
	}

	private function respond_with( int $status, string $body ): void {
		Functions\when( 'wp_remote_post' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * @param string[] $received Filled with what is handed over.
	 * @return callable(string): void
	 */
	private function collect( array &$received ): callable {
		return static function ( string $data ) use ( &$received ): void {
			$received[] = $data;
		};
	}
}
