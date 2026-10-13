<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Frontend\ResultRenderer;
use PrstSearch\Search\Answer;
use PrstSearch\Search\SearchResults;
use PrstSearch\Search\SearchService;

/**
 * The markup the templates print: results, filter and AI answer.
 *
 * The scripts insert this markup into the page as it is, so everything that
 * comes from the website, from a visitor or from the language model must
 * arrive escaped.
 */
final class RenderingTest extends RenderingTestCase {

	public function test_text_of_the_language_model_is_never_markup_and_citations_become_links(): void {
		$this->add_page( 10, 'Shipping "fast" & <b>free</b>', 'Text.' );

		$html = $this->renderer()->render_answer( new Answer( "<script>alert(1)</script> We ship [1], not [9].\n\nSecond paragraph.", array( 10 ) ) );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringContainsString( 'href="https://example.org/?p=10"', $html );
		self::assertStringContainsString( '>[1]</a>', $html );
		self::assertStringContainsString( 'not [9].', $html, 'A number without a source stays text.' );
		self::assertSame( 2, substr_count( $html, '<p>' ), 'A line break in the answer starts a new paragraph.' );
		self::assertStringNotContainsString( '<b>free</b>', $html, 'The title of the source is escaped where it is printed.' );
	}

	public function test_sources_are_listed_unless_that_is_switched_off(): void {
		$this->add_page( 10, 'Shipping', 'Text.' );

		self::assertStringContainsString( 'prstsearch-answer__sources', $this->renderer()->render_answer( new Answer( 'We ship [1].', array( 10 ) ) ) );
	}

	public function test_a_result_shows_title_type_excerpt_and_a_link_escaped(): void {
		$this->add_page( 10, 'Shipping <img src=x onerror=alert(1)>', 'We offer shipping & handling <today>.' );

		$html = $this->renderer()->render_results( $this->search( 'shipping' ) );

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( 'Shipping &lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringContainsString( '<mark>shipping</mark> &amp; handling &lt;today&gt;', $html );
		self::assertStringContainsString( 'data-prstsearch-list', $html );
		self::assertStringContainsString( '1 result for “shipping”', $html );
	}

	public function test_a_page_without_a_title_is_still_a_link_one_can_see_and_name(): void {
		$this->add_page( 10, '', 'We offer shipping.' );

		$html = $this->renderer()->render_results( $this->search( 'shipping' ) );

		self::assertMatchesRegularExpression( '/<a href="[^"]+">\(no title\)<\/a>/', $html );
	}

	public function test_a_compound_found_for_a_short_word_is_marked_as_a_whole(): void {
		$this->add_page( 10, 'Eingang', 'Die Haustür am Haus klemmt, sagt die Natur.' );

		$html = $this->renderer()->render_results( $this->search( 'Tür' ) );

		self::assertStringContainsString( '<mark>Haustür</mark>', $html );
		self::assertStringNotContainsString( '<mark>Natur</mark>', $html );
		self::assertStringNotContainsString( 'Na<mark>', $html );
	}

	public function test_text_found_inside_a_word_is_marked_where_it_stands(): void {
		$this->add_page( 10, 'Anfahrt', 'Wir sind in der Bahnhofstraße 5.' );

		self::assertStringContainsString( 'Bahn<mark>hof</mark>straße', $this->renderer()->render_results( $this->search( 'hof' ) ) );
	}

	public function test_a_search_without_results_shows_a_hint_instead_of_a_list(): void {
		$this->add_page( 10, 'Shipping', 'Text.' );

		$html = $this->renderer()->render_results( $this->search( 'nothing' ) );

		self::assertStringNotContainsString( 'data-prstsearch-list', $html );
		self::assertStringNotContainsString( 'data-prstsearch-more', $html );
		self::assertStringContainsString( 'prstsearch-results__hint', $html );
	}

	public function test_further_results_are_reached_through_a_link_that_works_without_a_script(): void {
		foreach ( range( 1, 5 ) as $number ) {
			$this->add_page( $number, 'Shipping ' . $number, 'Text.' );
		}

		$service = $this->container->get( SearchService::class );
		$first   = $this->renderer()->render_results( $service->search( 'shipping', 1, 2 ) );
		$last    = $this->renderer()->render_results( $service->search( 'shipping', 3, 2 ) );

		self::assertMatchesRegularExpression( '/<a [^>]*href="https:\/\/example\.org\/\?s=shipping&(?:amp;|#038;)?paged=2"[^>]*data-prstsearch-more[^>]*data-page="2"/', $first );
		self::assertStringNotContainsString( '<button', $first, 'A button would do nothing without the script.' );
		self::assertStringNotContainsString( 'data-prstsearch-more', $last, 'The last page has nothing to continue with.' );
	}

	public function test_the_link_to_further_results_keeps_the_chosen_content_type(): void {
		foreach ( range( 1, 3 ) as $number ) {
			$this->add_page( $number, 'Shipping ' . $number, 'Text.', null, array( 'post_type' => 'post' ) );
		}

		$this->add_page( 9, 'Shipping page', 'Text.' );

		$html = $this->renderer()->render_results( $this->container->get( SearchService::class )->search( 'shipping', 1, 2, array( 'post' ) ) );

		self::assertMatchesRegularExpression( '/href="[^"]*post_type=post[^"]*paged=2"/', $html );
		self::assertMatchesRegularExpression( '/data-prstsearch-type="post"\s+aria-current="true"/', $html, 'The chosen type is marked for those who cannot see the colour.' );
		self::assertSame( 1, substr_count( $html, 'aria-current="true"' ) );
	}

	public function test_a_search_for_zero_is_a_search(): void {
		$this->add_page( 1, 'Zimmer 0', 'Text.' );
		$this->add_page( 2, 'Beitrag 0', 'Text.', null, array( 'post_type' => 'post' ) );

		$urls = array_column( $this->renderer()->types( $this->search( '0' ) ), 'url' );

		self::assertSame( 'https://example.org/?s=0', $urls[0] );
		self::assertStringContainsString( 's=0&post_type=', $urls[1] );
	}

	public function test_a_phrase_with_special_characters_is_encoded_once_in_the_links(): void {
		$this->add_page( 1, 'Tom & Jerry', 'Text.' );
		$this->add_page( 2, 'Tom & Jerry again', 'Text.', null, array( 'post_type' => 'post' ) );

		$urls = array_column( $this->renderer()->types( $this->search( 'Tom & Jerry' ) ), 'url' );

		self::assertSame( 'https://example.org/?s=Tom%20%26%20Jerry', $urls[0] );
	}

	public function test_the_placeholder_for_the_answer_carries_query_and_token_escaped(): void {
		$html = $this->renderer()->render_answer_placeholder( 'shipping "abroad"', '7.abc' );

		self::assertStringContainsString( 'data-query="shipping &quot;abroad&quot;"', $html );
		self::assertStringContainsString( 'data-token="7.abc"', $html );
		self::assertStringContainsString( 'aria-busy="true"', $html );
	}

	private function search( string $query ): SearchResults {
		return $this->container->get( SearchService::class )->search( $query );
	}

	private function renderer(): ResultRenderer {
		return $this->container->get( ResultRenderer::class );
	}
}
