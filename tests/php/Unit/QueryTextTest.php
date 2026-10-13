<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\Excerpt;
use PrstSearch\Search\QueryTerms;
use PrstSearch\Search\TermCoverage;

/**
 * Parsing of search phrases and building of result excerpts.
 */
final class QueryTextTest extends TestCase {

	public function test_phrase_is_split_into_unique_lowercase_words(): void {
		$terms = QueryTerms::from_phrase( 'Versand, VERSAND Ausland!' );

		self::assertSame( array( 'versand', 'ausland' ), $terms->all() );
	}

	public function test_function_words_are_ignored(): void {
		$terms = QueryTerms::from_phrase( 'Wie kann ich den Newsletter abonnieren?' );

		self::assertSame( array( 'newsletter', 'abonnieren' ), $terms->all() );
	}

	public function test_a_query_of_function_words_only_is_kept(): void {
		self::assertSame( array( 'wer', 'sind', 'wir' ), QueryTerms::from_phrase( 'Wer sind wir?' )->all() );
	}

	public function test_boolean_query_matches_word_beginnings_and_skips_short_words(): void {
		$terms = QueryTerms::from_phrase( 'Versand USA EU' );

		self::assertSame( 'versand* qzdnasrev* usa*', $terms->to_boolean_query() );
	}

	public function test_fulltext_operators_cannot_be_injected(): void {
		$terms = QueryTerms::from_phrase( '+geheim -"alles" (sonst) ~nichts* @5' );

		self::assertSame( 'geheim* qzmieheg* sonst* qztsnos* nicht* qzthcin*', $terms->to_boolean_query() );
	}

	public function test_phrase_without_words_is_empty(): void {
		self::assertTrue( QueryTerms::from_phrase( ' ?!… ' )->is_empty() );
		self::assertSame( '', QueryTerms::from_phrase( 'EU' )->to_boolean_query() );
	}

	public function test_umlauts_are_part_of_words(): void {
		self::assertSame( array( 'öffnungszeiten', 'größe' ), QueryTerms::from_phrase( 'Öffnungszeiten Größe' )->all() );
	}

	public function test_number_of_terms_is_limited(): void {
		$phrase = implode( ' ', array_map( static fn ( int $number ): string => "word{$number}", range( 1, 40 ) ) );

		self::assertCount( 12, QueryTerms::from_phrase( $phrase )->all() );
	}

	public function test_coverage_counts_whole_words_fully_and_word_beginnings_partly(): void {
		$text = 'Der Versand ins Ausland dauert länger. Versandkosten berechnen wir automatisch.';

		self::assertSame( 1.0, TermCoverage::of( $text, array( 'versand', 'ausland' ) ) );
		self::assertSame( 0.5, TermCoverage::of( $text, array( 'versand', 'rückgabe' ) ) );
		// "auto" only begins "automatisch": a weaker sign than the word itself.
		self::assertSame( 0.6, TermCoverage::of( $text, array( 'auto' ) ) );
		// The middle of a word is no match at all.
		self::assertSame( 0.7, TermCoverage::of( $text, array( 'kosten' ) ), 'The last part of a compound.' );
		self::assertSame( 0.0, TermCoverage::of( $text, array() ) );
		self::assertSame( 0.0, TermCoverage::of( 'Ein Beispiel', array( 'ei' ) ), 'Two letters are too few to count as the beginning of a word.' );
		self::assertSame( 1.0, TermCoverage::of( 'Ein Ei', array( 'ei' ) ) );
		self::assertSame( 0.6, TermCoverage::of( 'Teekanne', array( 'tee' ) ) );
	}

	public function test_coverage_treats_search_words_as_text_not_as_patterns(): void {
		self::assertSame( 1.0, TermCoverage::of( 'Preis: 4.90 (inkl.)', array( '4', '90' ) ) );
		self::assertSame( 0.0, TermCoverage::of( 'abc', array( '.*' ) ) );
	}

	public function test_short_text_is_returned_unchanged(): void {
		self::assertSame( 'Short text.', Excerpt::around( "Short \n text.", array( 'text' ) ) );
	}

	public function test_excerpt_is_cut_around_the_first_match(): void {
		$text    = str_repeat( 'Lorem ipsum dolor sit amet. ', 20 ) . 'Die Versandkosten betragen 4,90 Euro. ' . str_repeat( 'Consectetur adipiscing elit. ', 20 );
		$excerpt = Excerpt::around( $text, array( 'versandkosten' ), 120 );

		self::assertStringContainsString( 'Versandkosten', $excerpt );
		self::assertStringStartsWith( '…', $excerpt );
		self::assertStringEndsWith( '…', $excerpt );
		self::assertLessThanOrEqual( 122, mb_strlen( $excerpt ) );
	}

	public function test_excerpt_without_match_starts_at_the_beginning(): void {
		$excerpt = Excerpt::around( str_repeat( 'Lorem ipsum dolor sit amet. ', 20 ), array( 'missing' ), 60 );

		self::assertStringStartsWith( 'Lorem ipsum', $excerpt );
		self::assertStringEndsWith( '…', $excerpt );
	}

	public function test_highlight_marks_terms_case_insensitively(): void {
		self::assertSame(
			'Die <mark>Versandkosten</mark> für den <mark>versand</mark>',
			Excerpt::highlight( 'Die Versandkosten für den versand', array( 'versand' ) )
		);
	}

	public function test_highlight_escapes_html(): void {
		self::assertSame(
			'&lt;script&gt;alert(1)&lt;/script&gt; <mark>Text</mark>',
			Excerpt::highlight( '<script>alert(1)</script> Text', array( 'text' ) )
		);
	}

	public function test_highlight_prefers_the_longer_term(): void {
		self::assertSame(
			'<mark>Versandkosten</mark>',
			Excerpt::highlight( 'Versandkosten', array( 'versand', 'versandkosten' ) )
		);
	}
}
