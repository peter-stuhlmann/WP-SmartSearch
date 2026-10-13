<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Index\IndexVersion;
use PrstSearch\Search\CompoundEndings;
use PrstSearch\Search\KeywordSearcher;
use PrstSearch\Search\QueryTerms;
use PrstSearch\Search\Vocabulary;
use PrstSearch\Settings\Settings;
use PrstSearch\Support\Cache;
use PrstSearch\Support\Lock;
use wpdb;

/**
 * What the keyword search makes of the rows the database returns.
 */
final class KeywordSearcherTest extends WordPressTestCase {

	private FakeChunkStore $store;

	/**
	 * Publication dates of posts, by ID.
	 *
	 * @var array<int, string>
	 */
	private array $dates = array();

	protected function setUp(): void {
		parent::setUp();

		$this->store = new FakeChunkStore();
		$this->dates = array();

		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post' )->alias(
			fn ( int $post_id ): object => (object) array( 'post_date_gmt' => $this->dates[ $post_id ] ?? '2026-01-01 00:00:00' )
		);
	}

	public function test_pages_where_the_words_stand_together_come_first(): void {
		// All three have both words, and the database prefers the first one.
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'Kitchen', 'We cook chicken. ' . str_repeat( 'Much later. ', 20 ) . 'A recipe for bread.', 3.0 ),
			self::row( 2, 20, 'Dinner', 'Our chicken, and next to it a simple recipe.', 2.0 ),
			self::row( 3, 30, 'Lunch', 'A quick chicken recipe for two.', 1.0 ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'chicken recipe' ), 10 );

		self::assertSame( array( 30, 20, 10 ), array_keys( $matches ) );
		self::assertSame( 1.0, $matches[30]['proximity'] );
		self::assertSame( 0.0, $matches[10]['proximity'] );
	}

	public function test_more_of_the_words_counts_more_than_having_them_together(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'Lunch', 'A quick chicken recipe.', 1.0 ),
			self::row( 2, 20, 'Dinner', 'Chicken here. ' . str_repeat( 'Far away. ', 20 ) . 'A recipe, and at the very end asparagus.', 1.0 ),
		);

		self::assertSame( array( 20, 10 ), array_keys( $this->searcher()->search( QueryTerms::from_phrase( 'chicken recipe asparagus' ), 10 ) ) );
	}

	public function test_the_passage_shown_is_the_one_that_has_the_words_together(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'Kitchen', 'Chicken first. ' . str_repeat( 'Much later. ', 20 ) . 'Then a recipe.', 2.0 ),
			self::row( 2, 10, 'Kitchen', 'Here is the chicken recipe.', 1.0 ),
		);

		self::assertSame( 2, $this->searcher()->search( QueryTerms::from_phrase( 'chicken recipe' ), 10 )[10]['chunk_id'] );
	}

	public function test_of_two_equal_matches_the_newer_page_comes_first(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'Old news', 'About shipping.', 1.0 ),
			self::row( 2, 20, 'New news', 'About shipping.', 1.0 ),
			self::row( 3, 30, 'Better match', 'About shipping.', 2.0 ),
		);

		$this->dates = array(
			10 => '2024-05-01 10:00:00',
			20 => '2026-09-01 10:00:00',
			30 => '2020-01-01 10:00:00',
		);

		self::assertSame(
			array( 30, 20, 10 ),
			array_keys( $this->searcher()->search( QueryTerms::from_phrase( 'shipping' ), 10 ) ),
			'The date only decides between matches that are otherwise alike.'
		);
	}

	public function test_an_empty_query_does_not_reach_the_database(): void {
		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( ' ?! ' ), 10 ) );
		self::assertSame( array(), $this->store->calls );
	}

	public function test_only_a_query_of_very_short_words_makes_the_database_read_every_passage(): void {
		$this->store->passages = array(
			self::passage( 1, 10, 'Frühstück', 'Ein Ei und Brot.' ),
			self::passage( 2, 20, 'Verein', 'Eine Partei, ein Verein.' ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'Ei' ), 10 );

		self::assertSame( array( 'words' ), $this->store->calls );
		self::assertSame( array( 10 ), array_keys( $matches ) );
	}

	public function test_a_word_the_index_does_not_have_is_not_looked_for_by_reading_the_whole_table_if_that_is_switched_off(): void {
		$this->options[ Settings::OPTION ] = array( 'match_inside_words' => false );

		$this->store->passages = array( self::passage( 1, 10, 'Delivery', 'We deliver worldwide.' ) );

		// What a typing error looks like to the index. The spelling correction takes it from here.
		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( 'delivry' ), 10 ) );
		self::assertSame( array( 'fulltext' ), $this->store->calls );
	}

	public function test_text_inside_words_is_found_when_nothing_has_the_word_itself(): void {
		$this->store->passages = array(
			self::passage( 1, 10, 'Anfahrt', 'Wir sind in der Bahnhofstraße 5.' ),
			self::passage( 2, 20, 'Ersatzteile', 'Bestellnummer AB-12345 für die Pumpe.' ),
			self::passage( 3, 30, 'Garten', 'Rosen und Tulpen.' ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'hof' ), 10 );

		self::assertSame( array( 10 ), array_keys( $matches ) );
		self::assertSame( 0.56, $matches[10]['coverage'], 'Less than any match as a word, and just enough to be shown.' );
		self::assertSame( array( 20 ), array_keys( $this->searcher()->search( QueryTerms::from_phrase( '234' ), 10 ) ) );
		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( 'xyz' ), 10 ) );
	}

	public function test_text_inside_words_is_not_looked_for_while_a_page_has_the_word(): void {
		$this->store->passages = array(
			self::passage( 1, 10, 'Anfahrt', 'Wir sind in der Bahnhofstraße 5.' ),
			self::passage( 2, 20, 'Gasthaus', 'Der Hof ist abends geöffnet.' ),
		);

		self::assertSame( array( 20 ), array_keys( $this->searcher()->search( QueryTerms::from_phrase( 'hof' ), 10 ) ) );
		self::assertNotContains( 'parts', $this->store->calls );
	}

	public function test_a_fruitless_search_inside_words_is_not_repeated(): void {
		$this->store->passages = array( self::passage( 1, 10, 'Garten', 'Rosen und Tulpen.' ) );

		$this->searcher()->search( QueryTerms::from_phrase( 'qwertz' ), 10 );
		$this->searcher()->search( QueryTerms::from_phrase( 'qwertz' ), 10 );

		self::assertSame( array( 'fulltext', 'parts', 'fulltext' ), $this->store->calls );
	}

	public function test_only_the_longest_words_are_looked_for_inside_words(): void {
		$this->store->passages = array( self::passage( 1, 10, 'Garten', 'Rosen und Tulpen.' ) );

		$this->searcher()->search( QueryTerms::from_phrase( 'abcdefg abcdef abcde abcd' ), 10 );

		self::assertSame( array( 'abcdefg', 'abcdef', 'abcde' ), $this->store->parts );
	}

	public function test_a_short_word_that_only_ends_other_words_stays_unfound_inside_words(): void {
		$this->store->passages = array( self::passage( 1, 10, 'Umwelt', 'Natur und Kultur. Naturen.' ) );

		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( 'Tür' ), 10 ), 'What the rule for compounds rejected does not come back this way.' );
	}

	public function test_many_matches_inside_words_average_to_a_relevant_result(): void {
		$this->store->passages = array( self::passage( 1, 10, 'Codes', 'xaaaay xbbbby xccccy xddddy xeeeey xffffy' ) );

		// Six words, each found inside a word only.
		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'aaaa bbbb cccc dddd eeee ffff' ), 10 );

		self::assertGreaterThanOrEqual( 0.55, $matches[10]['coverage'] );
	}

	public function test_two_letters_are_never_looked_for_inside_words(): void {
		$this->store->passages = array( self::passage( 1, 10, 'Verein', 'Eine Partei, ein Verein.' ) );

		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( 'ei' ), 10 ) );
	}

	public function test_a_row_the_database_returned_without_a_real_match_is_dropped(): void {
		// MariaDB takes the "ä" of "Käse" for the end of a word, and so finds the word "se" in it.
		$this->store->word_rows = array( self::row( 1, 10, 'Käse', 'Ein Stück Käse.' ) );

		self::assertSame( array(), $this->searcher()->search( QueryTerms::from_phrase( 'se' ), 10 ) );
	}

	public function test_each_post_is_listed_once_with_the_passage_that_covers_most_words(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'Delivery', 'Our shipping is fast.', 3.0 ),
			self::row( 2, 10, 'Delivery', 'Shipping costs depend on the weight.', 2.0 ),
			self::row( 3, 10, 'Delivery', 'Shipping takes two days.', 1.0 ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'shipping costs' ), 10 );

		self::assertCount( 1, $matches );
		self::assertSame( 2, $matches[10]['chunk_id'] );
		self::assertSame( 1.0, $matches[10]['coverage'] );
		self::assertFalse( $matches[10]['in_title'] );
	}

	public function test_a_page_is_judged_as_a_whole_wherever_on_it_the_words_are(): void {
		// A long page, stored in three passages. No single passage has both words.
		$this->store->passages = array(
			self::passage( 1, 10, 'Our kitchen', 'We cook with chicken from the region.' ),
			self::passage( 2, 10, 'Our kitchen', 'The dining room seats forty guests.' ),
			self::passage( 3, 10, 'Our kitchen', 'Every recipe is written down by hand.' ),
			self::passage( 4, 20, 'Blog', 'A recipe for bread.' ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'chicken recipe' ), 10 );

		self::assertSame( 1.0, $matches[10]['coverage'], 'The page has both words.' );
		self::assertSame( 0.5, $matches[20]['coverage'] );
	}

	public function test_short_words_the_database_cannot_search_for_still_count(): void {
		// "ei" is too short for the fulltext index, so only the passage with "rezept" is returned.
		$this->store->passages = array(
			self::passage( 1, 10, 'Frühstück', 'Das Rezept für den Morgen.' ),
			self::passage( 2, 10, 'Frühstück', 'Dazu gibt es Ei und frisches Brot.' ),
			self::passage( 3, 20, 'Abendessen', 'Ein Rezept mit Arbeit und Freizeit, für einen Abend.' ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'ei rezept' ), 10 );

		self::assertSame( 1.0, $matches[10]['coverage'] );
		self::assertSame( 0.5, $matches[20]['coverage'], '"Ein", "Arbeit" and "Freizeit" contain the letters, not the word.' );
		self::assertSame( 1, $matches[10]['chunk_id'], 'The excerpt comes from a passage that matched.' );
	}

	public function test_a_word_in_the_title_ranks_a_post_higher(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'About us', 'We explain shipping in detail.', 1.2 ),
			self::row( 2, 20, 'Shipping', 'How we send parcels.', 1.0 ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'shipping' ), 10 );

		self::assertSame( array( 20, 10 ), array_keys( $matches ) );
		self::assertTrue( $matches[20]['in_title'] );
	}

	public function test_titles_are_not_preferred_when_that_is_switched_off(): void {
		$this->options[ Settings::OPTION ] = array( 'boost_title' => false );

		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'About us', 'We explain shipping in detail.', 1.2 ),
			self::row( 2, 20, 'Shipping', 'How we send parcels.', 1.0 ),
		);

		$matches = $this->searcher()->search( QueryTerms::from_phrase( 'shipping' ), 10 );

		self::assertSame( array( 10, 20 ), array_keys( $matches ) );
	}

	public function test_no_more_posts_than_asked_for_are_returned(): void {
		$this->store->fulltext_rows = array(
			self::row( 1, 10, 'One', 'shipping', 3.0 ),
			self::row( 2, 20, 'Two', 'shipping', 2.0 ),
			self::row( 3, 30, 'Three', 'shipping', 1.0 ),
		);

		self::assertSame( array( 10, 20 ), array_keys( $this->searcher()->search( QueryTerms::from_phrase( 'shipping' ), 2 ) ) );
	}

	private function searcher(): KeywordSearcher {
		return new KeywordSearcher( $this->store, new Settings(), new CompoundEndings( $this->store, new Vocabulary( $this->store, $this->cache() ) ), $this->cache() );
	}

	private function cache(): Cache {
		return new Cache( new IndexVersion(), new Lock( new wpdb() ) );
	}

	/**
	 * @return array{id: int, post_id: int, title: string, content: string}
	 */
	private static function passage( int $id, int $post_id, string $title, string $content ): array {
		return array(
			'id'      => $id,
			'post_id' => $post_id,
			'title'   => $title,
			'content' => $content,
		);
	}

	/**
	 * @return array{id: int, post_id: int, title: string, content: string, score: float}
	 */
	private static function row( int $id, int $post_id, string $title, string $content, float $score = 1.0 ): array {
		return array(
			'id'      => $id,
			'post_id' => $post_id,
			'title'   => $title,
			'content' => $content,
			'score'   => $score,
		);
	}
}
