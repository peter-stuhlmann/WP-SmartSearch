<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PrstSearch\Rest\SearchRoutes;
use WP_REST_Request;

/**
 * The public REST routes: search in two steps, suggestions and clicks.
 */
final class SearchRoutesTest extends RenderingTestCase {

	private const ABOUT_FOOD = array( 1.0, 0.0 );

	/**
	 * Whether the current user may manage the plugin.
	 */
	private bool $can_manage = false;

	protected function setUp(): void {
		parent::setUp();

		$this->can_manage = false;

		Functions\when( 'current_user_can' )->alias( fn (): bool => $this->can_manage );

		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );
		$this->add_page( 20, 'Shipping abroad', 'Customs and duties.' );
		$this->add_page( 30, 'Shipping costs', 'What a parcel costs.' );
	}

	public function test_the_first_page_comes_as_data_and_as_markup(): void {
		$data = $this->search(
			array(
				'q'        => 'shipping',
				'per_page' => 2,
			)
		);

		self::assertSame( 3, $data['total'] );
		self::assertSame( 2, $data['pages'] );
		self::assertCount( 2, $data['results'] );
		self::assertStringContainsString( 'data-prstsearch-list', $data['html'] );
		self::assertNotSame( '', $data['token'] );
		self::assertFalse( $data['pending'] );
		self::assertSame( '3 results for “shipping”', $data['summary'] );
	}

	public function test_a_further_page_brings_only_its_items_and_is_not_logged_again(): void {
		$first  = $this->search(
			array(
				'q'        => 'shipping',
				'per_page' => 2,
			)
		);
		$second = $this->search(
			array(
				'q'        => 'shipping',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		self::assertSame( '', $second['html'] );
		self::assertStringContainsString( 'prstsearch-result', $second['items_html'] );
		self::assertCount( 1, $second['results'] );
		self::assertSame( array(), array_intersect( array_column( $first['results'], 'id' ), array_column( $second['results'], 'id' ) ) );
		self::assertCount( 1, $this->log->entries );
	}

	public function test_results_can_be_limited_to_a_content_type(): void {
		$this->add_page( 40, 'Shipping news', 'A blog post.', null, array( 'post_type' => 'post' ) );

		$data = $this->search(
			array(
				'q'    => 'shipping',
				'type' => 'post',
			)
		);

		self::assertSame( array( 40 ), array_column( $data['results'], 'id' ) );
		self::assertSame(
			array(),
			$this->search(
				array(
					'q'    => 'shipping',
					'type' => 'no-such-type',
				)
			)['results']
		);
	}

	public function test_the_answer_is_only_offered_with_the_final_results(): void {
		$this->use_ai();
		$this->configure( array( 'mode' => 'ai' ) );
		$this->add_page( 50, 'Chicken with asparagus', 'A recipe for the pan.', self::ABOUT_FOOD );

		$this->provider->vectors['poultry'] = array( 0.3, 0.3, 0.9 );
		$this->provider->chat_answer        = '1';

		// Fixed here, so the test does not depend on the tuning of a provider.
		Filters\expectApplied( 'prstsearch_similarity_thresholds' )->andReturn(
			array(
				'high'   => 0.8,
				'medium' => 0.6,
			)
		);

		$first = $this->search( array( 'q' => 'poultry' ) );

		self::assertTrue( $first['pending'] );
		self::assertSame( '', $first['answer_html'], 'Asking for the answer now would consult the language model twice.' );
		self::assertSame( array(), $this->provider->chat_requests );

		$refined = $this->search(
			array(
				'q'      => 'poultry',
				'refine' => true,
				'token'  => $first['token'],
			)
		);

		self::assertFalse( $refined['pending'] );
		self::assertSame( $first['token'], $refined['token'], 'It is still the same search.' );
		self::assertSame( array( 50 ), array_column( $refined['results'], 'id' ) );
		self::assertStringContainsString( 'data-prstsearch-answer', $refined['answer_html'] );
		self::assertCount( 1, $this->log->entries );
	}

	public function test_a_test_search_is_only_unlogged_for_those_who_may_manage_the_plugin(): void {
		$this->search(
			array(
				'q'       => 'shipping',
				'preview' => true,
			)
		);

		self::assertCount( 1, $this->log->entries, 'A visitor cannot search without leaving a trace by adding a parameter.' );

		$this->can_manage = true;

		$this->search(
			array(
				'q'       => 'shipping abroad',
				'preview' => true,
			)
		);

		self::assertCount( 1, $this->log->entries );
	}

	public function test_suggestions_are_titles_of_pages_the_visitor_may_see(): void {
		$this->add_page( 60, 'Shipping draft', 'Not ready.', null, array( 'post_status' => 'draft' ) );

		$response = $this->routes()->suggestions( new WP_REST_Request( array( 'q' => 'shipp' ) ) );

		self::assertSame( array( 'Shipping', 'Shipping abroad', 'Shipping costs' ), array_column( $response->get_data(), 'title' ) );
		self::assertSame( 'private, max-age=300', $response->get_headers()['Cache-Control'] );
	}

	public function test_suggestions_are_looked_up_once_and_nothing_is_kept_for_text_that_matches_nothing(): void {
		$routes = $this->routes();

		$routes->suggestions( new WP_REST_Request( array( 'q' => 'shipp' ) ) );
		$routes->suggestions( new WP_REST_Request( array( 'q' => 'SHIPP' ) ) );

		self::assertSame( 1, count( array_keys( $this->store->calls, 'suggest', true ) ) );

		$stored = count( $this->transients );

		$routes->suggestions( new WP_REST_Request( array( 'q' => 'qqqq' ) ) );
		$routes->suggestions( new WP_REST_Request( array( 'q' => 'wwww' ) ) );

		self::assertSame( $stored, count( $this->transients ), 'Random text must not fill the cache.' );
	}

	public function test_suggestions_can_be_switched_off(): void {
		$this->configure( array( 'suggestions' => false ) );

		self::assertSame( array(), $this->routes()->suggestions( new WP_REST_Request( array( 'q' => 'shipp' ) ) )->get_data() );
	}

	public function test_a_click_is_recorded_only_with_the_token_of_a_search(): void {
		$this->configure( array( 'track_clicks' => true ) );

		$token  = $this->search( array( 'q' => 'shipping' ) )['token'];
		$routes = $this->routes();

		self::assertSame(
			204,
			$routes->click(
				new WP_REST_Request(
					array(
						'token'   => '1.forged',
						'post_id' => 10,
					)
				)
			)->get_status()
		);
		self::assertSame( array(), $this->log->clicks );

		$routes->click(
			new WP_REST_Request(
				array(
					'token'   => $token,
					'post_id' => 10,
				)
			)
		);

		self::assertSame( array( 1 => 10 ), $this->log->clicks );
	}

	public function test_clicks_are_not_recorded_when_that_is_switched_off(): void {
		$token = $this->search( array( 'q' => 'shipping' ) )['token'];

		$this->routes()->click(
			new WP_REST_Request(
				array(
					'token'   => $token,
					'post_id' => 10,
				)
			)
		);

		self::assertSame( array(), $this->log->clicks );
	}

	public function test_a_visitor_searching_faster_than_a_person_can_does_not_fill_the_statistics(): void {
		foreach ( range( 1, 40 ) as $number ) {
			$this->search( array( 'q' => 'shipping ' . $number ) );
		}

		self::assertCount( 30, $this->log->entries );
	}

	/**
	 * @param array<string, mixed> $params Parameters of the request.
	 * @return array<string, mixed>
	 */
	private function search( array $params ): array {
		return $this->routes()->search( new WP_REST_Request( $params + array( 'page' => 1 ) ) )->get_data();
	}

	private function routes(): SearchRoutes {
		return $this->container->get( SearchRoutes::class );
	}
}
