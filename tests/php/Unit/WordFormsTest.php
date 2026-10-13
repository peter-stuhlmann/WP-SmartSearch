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
use PrstSearch\Search\Unicode;

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
				array( 'Straße', 'Straßen', 'Strasse', 'Strassen' ),
				array( 'groß', 'große', 'großen', 'großes' ),
				array( 'Fuß', 'Füße', 'Füßen' ),
				array( 'Kategorie', 'Kategorien' ),
				array( 'Familie', 'Familien' ),
				array( 'Idee', 'Ideen' ),
				array( 'cookie', 'cookies' ),
				array( 'Auto', 'Autos' ),
				array( 'Video', 'Videos' ),
				array( 'category', 'categories' ),
				array( 'delivery', 'deliveries' ),
				array( 'Wasser', 'Wassers' ),
				array( 'Kasse', 'Kassen' ),
				array( 'Frau', 'Frauen' ),
				array( 'Tee', 'Tees' ),
				array( 'glass', 'glasses' ),
				array( 'Ergebnis', 'Ergebnisse', 'Ergebnissen', 'Ergebnisses' ),
				array( 'Bus', 'Busse', 'Bussen' ),
				array( 'Ei', 'Eier', 'Eiern' ),
				array( 'Öl', 'Öle', 'Ölen', 'Öls' ),
			) as $forms
		) {
			$stems = array_unique( array_map( array( Stemmer::class, 'stem' ), $forms ) );

			self::assertCount( 1, $stems, implode( ', ', $forms ) . ' → ' . implode( ', ', $stems ) );
		}
	}

	public function test_every_form_of_a_word_covers_every_other_in_running_text(): void {
		foreach (
			array(
				array( 'Straße', 'Straßen', 'Strasse' ),
				array( 'groß', 'große', 'großes' ),
				array( 'Fuß', 'Füße' ),
				array( 'Kategorie', 'Kategorien' ),
				array( 'cookie', 'cookies' ),
				array( 'Auto', 'Autos' ),
				array( 'category', 'categories' ),
				array( 'Haus', 'Häuser', 'Hauses' ),
				array( 'ship', 'shipping', 'shipped' ),
			) as $forms
		) {
			foreach ( $forms as $searched ) {
				foreach ( $forms as $written ) {
					self::assertSame( 1.0, TermCoverage::of( 'Das ' . $written . ' hier.', array( mb_strtolower( $searched ) ) ), $searched . ' → ' . $written );
				}
			}
		}
	}

	public function test_words_with_a_sharp_s_are_marked_and_excluded_like_any_other(): void {
		self::assertSame(
			'<mark>Fußball</mark> und <mark>Spaß</mark> in der <mark>Hauptstraße</mark>',
			Excerpt::highlight( 'Fußball und Spaß in der Hauptstraße', array( 'fußball', 'spaß', 'straße' ) )
		);
		self::assertSame( 0.7, TermCoverage::of( 'In der Hauptstraße', array( 'straße' ) ) );
	}

	public function test_the_last_part_of_a_compound_is_found_for_words_ending_in_a_double_s(): void {
		foreach ( array(
			'Wasser' => 'Mineralwasser',
			'Kasse'  => 'Krankenkasse',
			'Messe'  => 'Fachmesse',
			'Pass'   => 'Reisepass',
			'Straße' => 'Hauptstraße',
		) as $word => $compound ) {
			$reversed = explode( ' ', QueryTerms::from_phrase( $word )->to_boolean_query() )[1] ?? 'no reversed form';

			self::assertStringContainsString( ' ' . rtrim( $reversed, '*' ), ' ' . IndexTerms::of( '', $compound ), $word . ' in ' . $compound );
			self::assertSame( 0.7, TermCoverage::of( $compound, array( mb_strtolower( $word ) ) ), $word . ' in ' . $compound );
		}
	}

	public function test_a_stem_the_database_would_ignore_is_stored_so_that_it_is_indexed(): void {
		// "Foren" becomes "for", which is on the stop word list of the database.
		$stored = explode( ' ', IndexTerms::of( '', 'Unsere Foren' ) );

		self::assertNotContains( 'for', $stored );
		self::assertContains( 'forqx', $stored );
		self::assertSame( 'for*', QueryTerms::from_phrase( 'Foren' )->to_boolean_query(), 'The query still finds it by its beginning.' );
	}

	public function test_a_passage_without_words_is_told_apart_from_one_that_was_never_processed(): void {
		self::assertNotSame( '', IndexTerms::of( '', '— 12 % —' ) );
		self::assertSame( IndexTerms::of( '', '' ), IndexTerms::of( '', '?!' ) );
	}

	public function test_letters_written_as_two_characters_are_read_as_one(): void {
		if ( ! class_exists( \Normalizer::class ) ) {
			self::markTestSkipped( 'Needs the intl extension.' );
		}

		// "Käse" with the dots as a separate, combining character, as a PDF or a Mac writes it.
		$decomposed = "Ka\u{0308}se";

		self::assertSame( 'Käse', Unicode::composed( $decomposed ) );
		self::assertSame( IndexTerms::of( '', 'Käse' ), IndexTerms::of( '', $decomposed ) );
		self::assertSame( "\xff", Unicode::composed( "\xff" ), 'Text that is not valid UTF-8 is left alone.' );
	}

	public function test_short_words_are_left_alone(): void {
		self::assertSame( 'ei', Stemmer::stem( 'Ei' ) );
		self::assertSame( 'eis', Stemmer::stem( 'Eis' ), 'Ice is no egg.' );
		self::assertSame( 'reisebus', Stemmer::stem( 'Reisebusse' ), 'A compound ends in the stem of its last part.' );
		self::assertSame( 'fluss', Stemmer::stem( 'Flüsse' ), 'Only where the singular has one "s".' );
		self::assertSame( 'buss', Stemmer::stem( 'Buße' ), 'Penance is no bus.' );
		self::assertSame( 'ole', Stemmer::stem( 'Ole' ), 'A name is no oil.' );
		self::assertSame( 1.0, TermCoverage::of( 'Ole Einar gewinnt.', array( 'ole' ) ) );
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
		self::assertSame( 'tee*', QueryTerms::from_phrase( 'Tee' )->to_boolean_query() );
		self::assertNull( TermCoverage::patterns( 'tee' )['ending'], 'Not the end of just any word.' );
		self::assertSame( 0.7, TermCoverage::of( 'Ein Kräutertee.', array( 'tee' ), array( 'tee' => array( 'kräutertee' ) ) ) );
		self::assertSame( 0.0, TermCoverage::of( 'Das Komitee.', array( 'tee' ), array( 'tee' => array( 'kräutertee' ) ) ) );
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

	public function test_the_link_to_a_passage_prefers_the_word_and_never_starts_inside_one(): void {
		$text = "Zur Startseite und mehr davon hier\nUnsere Art zu arbeiten ist gut";

		self::assertSame( 'Unsere Art zu arbeiten ist gut', Excerpt::fragment( $text, array( 'art' ) ), 'The line with the word, not the one with "Startseite".' );
		self::assertSame( 'in der Bahnhofstraße 5.', Excerpt::fragment( 'Wir sind in der Bahnhofstraße 5.', array( 'hof' ) ), 'A browser looks for whole words.' );
		self::assertSame( '', Excerpt::fragment( 'Nichts davon steht hier.', array( 'hof' ) ) );
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

	public function test_a_minus_inside_quotation_marks_is_part_of_the_phrase(): void {
		$terms = QueryTerms::from_phrase( '"-5 Grad" Wetter' );

		self::assertSame( array( '-5 grad' ), $terms->phrases() );
		self::assertSame( array(), $terms->excluded() );
	}

	public function test_a_minus_before_quotation_marks_excludes_the_phrase(): void {
		$terms = QueryTerms::from_phrase( 'Rezept -"ohne Fleisch" schnell' );

		self::assertSame( array( 'ohne fleisch' ), $terms->excluded_phrases() );
		self::assertSame( array(), $terms->phrases() );
		self::assertSame( array( 'rezept', 'schnell' ), $terms->all() );
		self::assertTrue( $terms->has_demands() );
	}

	public function test_a_query_cannot_make_demands_without_end(): void {
		$terms = QueryTerms::from_phrase( 'x ' . implode( ' ', array_map( static fn ( int $number ): string => '-wort' . $number, range( 1, 500 ) ) ) );

		self::assertLessThanOrEqual( 8, count( $terms->excluded() ) );
	}

	public function test_distance_is_measured_in_letters_not_in_bytes(): void {
		$terms = array( 'chicken', 'recipe' );

		self::assertSame(
			Proximity::of( 'chicken ' . str_repeat( 'a', 40 ) . ' recipe', $terms ),
			Proximity::of( 'chicken ' . str_repeat( 'ä', 40 ) . ' recipe', $terms )
		);
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
