<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\Excerpt;
use PrstSearch\Search\IndexTerms;
use PrstSearch\Search\Proximity;
use PrstSearch\Search\QueryTerms;
use PrstSearch\Search\Stemmer;
use PrstSearch\Search\TermCoverage;

/**
 * Word forms, compounds and the notation of a search phrase.
 */
final class WordFormsTest extends TestCase {

	public function test_the_forms_of_a_word_share_a_stem(): void {
		foreach (
			array(
				array( 'Rezept', 'Rezepte', 'Rezepten', 'rezepts' ),
				array( 'Haus', 'Häuser', 'Hauses', 'Häusern' ),
				array( 'Kind', 'Kinder', 'Kindern' ),
				array( 'Preis', 'Preise', 'Preisen' ),
				array( 'ship', 'ships', 'shipping', 'shipped' ),
				array( 'Straße', 'Strassen' ),
			) as $forms
		) {
			$stems = array_unique( array_map( array( Stemmer::class, 'stem' ), $forms ) );

			self::assertCount( 1, $stems, implode( ', ', $forms ) . ' → ' . implode( ', ', $stems ) );
		}
	}

	public function test_short_words_are_left_alone(): void {
		self::assertSame( 'ei', Stemmer::stem( 'Ei' ) );
		self::assertSame( 'tee', Stemmer::stem( 'Tee' ) );
		self::assertSame( 'bus', Stemmer::stem( 'Bus' ) );
		self::assertSame( 'haus', Stemmer::stem( 'Haus' ), 'Without its "s" it would no longer meet "Häuser".' );
		self::assertSame( 'krankenhaus', Stemmer::stem( 'Krankenhaus' ), 'A long word is treated like a short one.' );
	}

	public function test_a_page_covers_a_word_in_any_of_its_forms(): void {
		self::assertSame( 1.0, TermCoverage::of( 'Unsere besten Rezepte', array( 'rezept' ) ) );
		self::assertSame( 1.0, TermCoverage::of( 'Ein Rezept für den Abend', array( 'rezepte' ) ) );
		self::assertSame( 1.0, TermCoverage::of( 'Ein Haus am See', array( 'häuser' ) ) );
		self::assertSame( 1.0, TermCoverage::of( 'Schöne Häuser', array( 'haus' ) ) );
		self::assertSame( 1.0, TermCoverage::of( 'We are shipping today', array( 'ship' ) ) );
	}

	public function test_the_last_part_of_a_compound_counts_more_than_the_first(): void {
		self::assertSame( 0.7, TermCoverage::of( 'Die Versandkosten', array( 'kosten' ) ) );
		self::assertSame( 0.7, TermCoverage::of( 'Im Krankenhaus', array( 'haus' ) ) );
		self::assertSame( 0.6, TermCoverage::of( 'Die Versandkosten', array( 'versand' ) ) );
		self::assertSame( 0.0, TermCoverage::of( 'Die Versandkosten', array( 'sand' ) ), 'The middle of a word is no match.' );
	}

	public function test_very_short_words_only_count_as_a_word_of_their_own(): void {
		self::assertSame( 1.0, TermCoverage::of( 'Ein Ei zum Frühstück', array( 'ei' ) ) );
		self::assertSame( 0.0, TermCoverage::of( 'Eine Partei, ein Verein', array( 'ei' ) ) );
	}

	public function test_the_index_holds_stems_and_their_reversed_forms(): void {
		$terms = explode( ' ', IndexTerms::of( 'Versandkosten', 'Die Häuser, das Haus und ein Ei.' ) );

		self::assertContains( 'versandkost', $terms );
		self::assertContains( 'qztsokdnasrev', $terms, 'Backwards, so the index can find the end of the word.' );
		self::assertContains( 'haus', $terms );
		self::assertSame( 1, count( array_keys( $terms, 'haus', true ) ), 'Each stem once.' );
		self::assertNotContains( 'ei', $terms, 'Too short for the index.' );
	}

	public function test_the_query_looks_for_stems_at_the_beginning_and_at_the_end_of_words(): void {
		self::assertSame( 'kost* qztsok*', QueryTerms::from_phrase( 'Kosten' )->to_boolean_query() );
		self::assertSame( 'tee*', QueryTerms::from_phrase( 'Tee' )->to_boolean_query(), 'Too short to be the end of a compound.' );
		self::assertSame( '', QueryTerms::from_phrase( 'Ei' )->to_boolean_query() );
	}

	public function test_what_is_stored_for_a_compound_is_found_by_its_last_part(): void {
		$stored = ' ' . IndexTerms::of( '', 'Versandkosten Krankenhaus' ) . ' ';

		foreach ( array( 'Kosten', 'Haus', 'Häuser' ) as $word ) {
			$reversed = explode( ' ', QueryTerms::from_phrase( $word )->to_boolean_query() )[1];

			self::assertStringContainsString( ' ' . rtrim( $reversed, '*' ), $stored, $word );
		}
	}

	public function test_words_next_to_each_other_stand_closest_whatever_their_order_and_form(): void {
		$terms = array( 'chicken', 'recipe' );

		self::assertSame( 1.0, Proximity::of( 'A quick chicken recipe.', $terms ) );
		self::assertSame( 1.0, Proximity::of( 'Recipes: chicken', $terms ) );
		self::assertSame( 1.0, Proximity::of( 'Hähnchen-Rezept', array( 'hähnchen', 'rezepte' ) ) );
	}

	public function test_the_further_apart_the_words_the_less_they_belong_together(): void {
		$terms = array( 'chicken', 'recipe' );
		$near  = Proximity::of( 'Chicken, and with it a simple recipe.', $terms );
		$far   = Proximity::of( 'Chicken. ' . str_repeat( 'x ', 30 ) . 'Recipe.', $terms );

		self::assertGreaterThan( 0.5, $near );
		self::assertLessThan( 1.0, $near );
		self::assertGreaterThan( $far, $near );
		self::assertSame( 0.0, Proximity::of( 'Chicken. ' . str_repeat( 'x ', 80 ) . 'Recipe.', $terms ) );
	}

	public function test_the_closest_pair_counts_when_words_occur_several_times(): void {
		self::assertSame(
			1.0,
			Proximity::of( 'Chicken soup. ' . str_repeat( 'x ', 80 ) . 'A recipe. ' . str_repeat( 'x ', 80 ) . 'The chicken recipe.', array( 'chicken', 'recipe' ) )
		);
	}

	public function test_a_single_word_is_no_group(): void {
		self::assertSame( 0.0, Proximity::of( 'Chicken chicken chicken.', array( 'chicken', 'recipe' ) ) );
		self::assertSame( 0.0, Proximity::of( 'A chicken recipe.', array( 'chicken' ) ) );
		self::assertSame( 0.0, Proximity::of( '', array( 'chicken', 'recipe' ) ) );
	}

	public function test_quotation_marks_demand_an_exact_phrase(): void {
		$terms = QueryTerms::from_phrase( 'Rezept „Hähnchen mit  Spargel“ schnell' );

		self::assertSame( array( 'hähnchen mit spargel' ), $terms->phrases() );
		self::assertSame( array( 'rezept', 'hähnchen', 'spargel', 'schnell' ), $terms->all(), 'The words of the phrase are searched for as well.' );
		self::assertSame( 'rezept hähnchen mit spargel schnell', $terms->text() );
		self::assertTrue( $terms->has_demands() );
	}

	public function test_a_minus_excludes_a_word(): void {
		$terms = QueryTerms::from_phrase( 'Rezept -vegan E-Mail' );

		self::assertSame( array( 'vegan' ), $terms->excluded() );
		self::assertSame( array( 'rezept', 'e', 'mail' ), $terms->all(), 'A hyphen inside a word excludes nothing.' );
		self::assertSame( 'rezept e-mail', $terms->text() );
	}

	public function test_an_ordinary_phrase_makes_no_demands(): void {
		$terms = QueryTerms::from_phrase( 'Hähnchen Rezept' );

		self::assertFalse( $terms->has_demands() );
		self::assertSame( 'hähnchen rezept', $terms->text() );
	}

	public function test_all_forms_of_a_word_are_marked_in_the_excerpt(): void {
		self::assertSame(
			'Zwei <mark>Rezepte</mark>, ein <mark>Rezeptbuch</mark> und die <mark>Versandkosten</mark>.',
			Excerpt::highlight( 'Zwei Rezepte, ein Rezeptbuch und die Versandkosten.', array( 'rezept', 'kosten' ) )
		);
		self::assertSame( 'Schöne <mark>Häuser</mark>', Excerpt::highlight( 'Schöne Häuser', array( 'haus' ) ) );
	}

	public function test_the_link_to_a_passage_uses_a_few_words_of_one_line(): void {
		$text = "Unsere Küche\nWir kochen jeden Tag frisches Hähnchen mit Spargel aus der Region und backen Brot.\nReservierung per Telefon.";

		self::assertSame( 'Tag frisches Hähnchen mit Spargel aus der', Excerpt::fragment( $text, array( 'hähnchen' ) ) );
		self::assertSame( 'Reservierung per Telefon.', Excerpt::fragment( $text, array( 'telefon' ) ), 'Never across the end of a line.' );
		self::assertSame( 'Unsere Küche', Excerpt::fragment( $text, array( 'pizza' ), true ), 'A passage found by its meaning is the target itself.' );
		self::assertSame( '', Excerpt::fragment( $text, array( 'pizza' ) ), 'Found by a word in the title: the page opens at the top.' );
		self::assertSame( '', Excerpt::fragment( '', array( 'pizza' ), true ) );
	}
}
