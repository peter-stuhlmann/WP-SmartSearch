<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Search\Recommendations;
use PrstSearch\Search\Relevance;
use PrstSearch\Search\SearchService;

/**
 * Pages the website recommends for certain searches.
 */
final class RecommendationsTest extends SearchTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( strip_tags( $text ) ) );

		$this->add_page( 10, 'Contact', 'Find us in the old town. Monday to Friday, nine to five.' );
		$this->add_page( 20, 'Opening hours of the museum', 'An article about another place.' );
		$this->add_page( 30, 'Jobs', 'We are hiring.' );
	}

	public function test_rules_are_read_from_the_form(): void {
		$this->rules()->save_input(
			array(
				array(
					'terms' => ' Opening hours,  WHEN open , <b>open</b>,, ',
					'page'  => 'Contact #10',
				),
				array(
					'terms' => 'jobs',
					'page'  => '30',
				),
				// Incomplete rows, as the empty row at the end of the form always is.
				array(
					'terms' => '',
					'page'  => '',
				),
				array(
					'terms' => 'career',
					'page'  => 'no number here',
				),
				'not a row',
			)
		);

		self::assertSame(
			array(
				array(
					'terms'   => array( 'opening hours', 'when open', 'open' ),
					'post_id' => 10,
				),
				array(
					'terms'   => array( 'jobs' ),
					'post_id' => 30,
				),
			),
			$this->rules()->all()
		);
	}

	public function test_a_rule_applies_to_every_form_and_to_longer_searches(): void {
		$this->recommend( 'opening hours, when open', 10 );

		foreach ( array( 'opening hours', 'Opening hour', 'your opening hours today', 'hours opening', 'When are you open?' ) as $query ) {
			self::assertSame( array( 10 ), $this->rules()->posts_for( $query ), $query );
		}

		foreach ( array( 'opening', 'open', 'hours', 'closing hours', '' ) as $query ) {
			self::assertSame( array(), $this->rules()->posts_for( $query ), $query );
		}
	}

	public function test_the_recommended_page_is_listed_first_and_marked(): void {
		$this->recommend( 'opening hours', 10 );

		$results = $this->search( 'opening hours' );

		self::assertSame( array( 10, 20 ), $results->post_ids(), 'Before the page that has the words in its title.' );
		self::assertTrue( $results->hits[0]->is_recommended );
		self::assertSame( Relevance::High, $results->hits[0]->relevance );
		self::assertFalse( $results->hits[1]->is_recommended );
	}

	public function test_a_page_the_search_did_not_find_is_shown_with_its_beginning(): void {
		$this->recommend( 'vacancies', 30 );

		$results = $this->search( 'vacancies' );

		self::assertSame( array( 30 ), $results->post_ids() );
		self::assertSame( 30, $results->hits[0]->chunk_id );
		self::assertSame( '', $results->ranking->corrected_query );
	}

	public function test_a_page_found_anyway_is_listed_once(): void {
		$this->recommend( 'contact', 10 );

		self::assertSame( array( 10 ), $this->search( 'contact' )->post_ids() );
	}

	public function test_a_recommendation_never_shows_a_page_the_visitor_may_not_see(): void {
		$this->add_page( 40, 'Internal plan', 'Not public.', null, array( 'post_status' => 'draft' ) );
		$this->recommend( 'plan', 40 );
		$this->recommend( 'ghost', 999 );

		self::assertSame( 0, $this->search( 'plan' )->total );
		self::assertSame( 0, $this->search( 'ghost' )->total );
	}

	public function test_a_precise_phrase_gets_exactly_what_it_asks_for(): void {
		$this->recommend( 'opening hours', 10 );

		self::assertSame( array( 20 ), $this->search( '"opening hours"' )->post_ids() );
	}

	public function test_the_language_model_is_not_asked_when_the_website_has_an_answer(): void {
		$this->use_ai();
		$this->recommend( 'vacancies', 30 );

		$results = $this->container->get( SearchService::class )->search( 'vacancies', 1, null, array(), true );

		self::assertSame( array( 30 ), $results->post_ids() );
		self::assertFalse( $results->is_provisional );
	}

	public function test_exported_rules_name_their_page_by_address_and_title(): void {
		$this->stub_pages();
		$this->recommend( 'opening hours', 10 );

		self::assertSame(
			array(
				array(
					'terms'   => array( 'opening hours' ),
					'post_id' => 10,
					'path'    => '/contact/',
					'title'   => 'Contact',
				),
			),
			$this->rules()->export()
		);
	}

	public function test_an_import_finds_the_page_on_this_website(): void {
		$this->stub_pages();

		$skipped = $this->rules()->import(
			array(
				// On the other website the contact page had the ID 77.
				array(
					'terms'   => array( 'Opening Hours', 'open' ),
					'post_id' => 77,
					'path'    => '/contact/',
					'title'   => 'Contact',
				),
				// No such address here, but the page with this ID has this title: the same website, restored.
				array(
					'terms'   => array( 'vacancies' ),
					'post_id' => 30,
					'path'    => '/careers/',
					'title'   => 'Jobs',
				),
				// The same number, but another page: must not be recommended by accident.
				array(
					'terms'   => array( 'imprint' ),
					'post_id' => 20,
					'path'    => '/imprint/',
					'title'   => 'Imprint',
				),
				array( 'terms' => 'broken' ),
				'not a rule',
			)
		);

		self::assertSame( 3, $skipped );
		self::assertSame(
			array(
				array(
					'terms'   => array( 'opening hours', 'open' ),
					'post_id' => 10,
				),
				array(
					'terms'   => array( 'vacancies' ),
					'post_id' => 30,
				),
			),
			$this->rules()->all()
		);
	}

	public function test_an_import_replaces_the_rules_that_were_there(): void {
		$this->stub_pages();
		$this->recommend( 'jobs', 30 );

		self::assertSame( 0, $this->rules()->import( array() ) );
		self::assertSame( array(), $this->rules()->all() );
	}

	/**
	 * Gives the pages of the test website addresses.
	 */
	private function stub_pages(): void {
		$paths = array(
			10 => '/contact/',
			20 => '/museum/',
			30 => '/jobs/',
		);

		// The website lives in a subfolder. The folder is not part of what identifies a page.
		$home = 'https://example.org/shop';

		Functions\when( 'get_permalink' )->alias( static fn ( int $post_id ): string => $home . ( $paths[ $post_id ] ?? '/' ) );
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => $home . $path );
		Functions\when( 'url_to_postid' )->alias( static fn ( string $url ): int => (int) array_search( str_replace( $home, '', $url ), $paths, true ) );
		Functions\when( 'get_the_title' )->alias( static fn ( int $post_id ): string => (string) get_post( $post_id )?->post_title );
	}

	private function recommend( string $terms, int $post_id ): void {
		$rules   = $this->rules()->all();
		$rules[] = array(
			'terms'   => array_map( 'trim', explode( ',', $terms ) ),
			'post_id' => $post_id,
		);

		$this->options[ Recommendations::OPTION ] = $rules;
	}

	private function rules(): Recommendations {
		return $this->container->get( Recommendations::class );
	}

	private function search( string $query ): \PrstSearch\Search\SearchResults {
		return $this->container->get( SearchService::class )->search( $query );
	}
}
