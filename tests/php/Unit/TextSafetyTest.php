<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\Excerpt;
use PrstSearch\Support\Csv;

/**
 * Text from the website or from visitors on its way into HTML and CSV.
 */
final class TextSafetyTest extends TestCase {

	public function test_search_words_are_marked_whatever_their_case(): void {
		self::assertSame(
			'Our <mark>Shipping</mark> is fast, <mark>shipping</mark> is free.',
			Excerpt::highlight( 'Our Shipping is fast, shipping is free.', array( 'shipping' ) )
		);
	}

	public function test_markup_in_the_text_is_escaped(): void {
		self::assertSame(
			'&lt;script&gt;<mark>alert</mark>(&quot;xy&quot;)&lt;/script&gt;',
			Excerpt::highlight( '<script>alert("xy")</script>', array( 'alert', 'not-there' ) )
		);
	}

	public function test_a_search_word_that_looks_like_part_of_an_entity_does_not_break_it(): void {
		self::assertSame( 'Rock &amp; Roll', Excerpt::highlight( 'Rock & Roll', array( 'amp' ) ) );
		self::assertSame( 'a &lt; b', Excerpt::highlight( 'a < b', array( 'lt' ) ) );
		self::assertSame( '&quot;<mark>quoted</mark>&quot;', Excerpt::highlight( '"quoted"', array( 'quot' ) ) );
	}

	public function test_a_search_word_with_special_characters_is_marked_and_escaped(): void {
		self::assertSame( 'Price: <mark>5 &lt; 10</mark> (.*)', Excerpt::highlight( 'Price: 5 < 10 (.*)', array( '5 < 10' ) ) );
		self::assertSame( 'Price <mark>(.*)</mark>', Excerpt::highlight( 'Price (.*)', array( '(.*)' ) ) );
	}

	public function test_the_longer_of_two_overlapping_words_is_marked(): void {
		self::assertSame(
			'<mark>Versandkosten</mark> und <mark>Versand</mark>',
			Excerpt::highlight( 'Versandkosten und Versand', array( 'versand', 'versandkosten' ) )
		);
	}

	public function test_text_without_search_words_is_only_escaped(): void {
		self::assertSame( 'Tom &amp; Jerry', Excerpt::highlight( 'Tom & Jerry', array() ) );
		self::assertSame( 'Tom &amp; Jerry', Excerpt::highlight( 'Tom & Jerry', array( 'a' ) ), 'Single letters are not marked.' );
	}

	public function test_cells_a_spreadsheet_would_run_as_a_formula_are_neutralised(): void {
		foreach ( array( '=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1" ) as $dangerous ) {
			self::assertSame( "'" . $dangerous, Csv::cell( $dangerous ) );
		}
	}

	public function test_ordinary_cells_are_left_alone(): void {
		foreach ( array( 'shipping costs', '12', 'a=b', '' ) as $harmless ) {
			self::assertSame( $harmless, Csv::cell( $harmless ) );
		}
	}
}
