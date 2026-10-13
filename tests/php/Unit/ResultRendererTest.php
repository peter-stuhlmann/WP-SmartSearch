<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Frontend\ResultRenderer;
use PrstSearch\Frontend\TemplateLoader;
use PrstSearch\Search\SearchResults;
use PrstSearch\Search\SearchService;
use PrstSearch\Support\Cache;
use PrstSearch\Index\IndexVersion;

/**
 * What a result list is made of: links to passages, the filter by content type, and the caches behind it.
 */
final class ResultRendererTest extends SearchTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->container->set( TemplateLoader::class, static fn (): TemplateLoader => new TemplateLoader( dirname( __DIR__, 3 ) . '/templates/' ) );

		Functions\when( 'get_the_title' )->alias( static fn ( object $post ): string => $post->post_title );
		Functions\when( 'get_permalink' )->alias( static fn ( object $post ): string => 'https://example.org/?p=' . $post->ID );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.org/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( int $number ): string => (string) $number );
		Functions\when( 'get_post_type_object' )->alias(
			static fn ( string $type ): object => (object) array(
				'labels' => (object) array(
					'name'          => ucfirst( $type ) . 's',
					'singular_name' => ucfirst( $type ),
				),
			)
		);

		$this->add_page( 10, 'Our kitchen', "Opening hours\nWe cook fresh chicken with asparagus every day of the week." );
		$this->add_page( 20, 'Chicken news', 'A post about chicken.', null, array( 'post_type' => 'post' ) );
		$this->add_page( 30, 'More chicken', 'Another post about chicken.', null, array( 'post_type' => 'post' ) );
	}

	public function test_a_result_links_to_the_passage_that_was_found(): void {
		$items = $this->renderer()->items( $this->search( 'chicken' ) );
		$urls  = array_column( $items, 'url', 'id' );

		self::assertSame( 'https://example.org/?p=10#:~:text=cook%20fresh%20chicken%20with%20asparagus%20every%20day', $urls[10] );
		self::assertStringContainsString( '<mark>chicken</mark>', $items[0]['excerpt'] );
	}

	public function test_a_hyphen_in_the_passage_does_not_break_the_link(): void {
		$this->add_page( 40, 'Menu', 'Our gluten-free chicken burger.' );

		$urls = array_column( $this->renderer()->items( $this->search( 'burger' ) ), 'url', 'id' );

		self::assertStringContainsString( '#:~:text=gluten%2Dfree%20chicken%20burger.', $urls[40] );
	}

	public function test_the_link_to_the_passage_can_be_switched_off(): void {
		$this->configure( array( 'jump_to_passage' => false ) );

		$urls = array_column( $this->renderer()->items( $this->search( 'chicken' ) ), 'url', 'id' );

		self::assertSame( 'https://example.org/?p=10', $urls[10] );
	}

	public function test_the_filter_lists_the_content_types_of_all_results(): void {
		$types = $this->renderer()->types( $this->search( 'chicken' ) );

		self::assertSame( array( '', 'post', 'page' ), array_column( $types, 'type' ), '"All" first, then the type with most results.' );
		self::assertSame( array( 3, 2, 1 ), array_column( $types, 'count' ) );
		self::assertSame( array( true, false, false ), array_column( $types, 'is_current' ) );
		self::assertSame( 'https://example.org/?s=chicken&post_type=post', $types[1]['url'] );
		self::assertSame( 'https://example.org/?s=chicken', $types[0]['url'] );
	}

	public function test_the_chosen_type_is_marked_and_the_counts_stay_those_of_the_whole_search(): void {
		$results = $this->container->get( SearchService::class )->search( 'chicken', 1, null, array( 'page' ) );
		$types   = $this->renderer()->types( $results );

		self::assertSame( array( 10 ), $results->post_ids() );
		self::assertSame( array( 3, 2, 1 ), array_column( $types, 'count' ) );
		self::assertSame( array( false, false, true ), array_column( $types, 'is_current' ) );
	}

	public function test_no_filter_is_offered_without_a_choice(): void {
		self::assertSame( array(), $this->renderer()->types( $this->search( 'asparagus' ) ), 'All results are pages.' );
	}

	public function test_the_filter_can_be_switched_off(): void {
		$this->configure( array( 'type_filter' => false ) );

		self::assertSame( array(), $this->renderer()->types( $this->search( 'chicken' ) ) );
	}

	public function test_a_value_of_the_whole_index_is_not_rebuilt_while_a_visitor_waits(): void {
		$version = new IndexVersion();
		$cache   = new Cache( $version, new \PrstSearch\Support\Lock( new \wpdb() ) );
		$builds  = 0;
		$build   = static function () use ( &$builds ): string {
			return 'built ' . ( ++$builds );
		};

		self::assertSame( 'built 1', $cache->for_index( 'words', 'model-a', $build ), 'The first request builds it.' );
		self::assertSame( 'built 1', $cache->for_index( 'words', 'model-a', $build ) );

		$version->bump();

		self::assertSame( 'built 1', $cache->for_index( 'words', 'model-a', $build ), 'After a change the previous value serves until the new one is ready.' );
		self::assertSame( 1, $builds );

		self::assertSame( 'built 2', $cache->for_index( 'words', 'model-b', $build ), 'A value for another model is useless and built at once.' );
	}

	private function search( string $query ): SearchResults {
		return $this->container->get( SearchService::class )->search( $query );
	}

	private function renderer(): ResultRenderer {
		return $this->container->get( ResultRenderer::class );
	}
}
