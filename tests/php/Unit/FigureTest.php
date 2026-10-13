<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use PrstSearch\Admin\Figure;

final class FigureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_rounded_figure_offers_its_exact_value_as_a_tooltip(): void {
		$figure = new Figure( '$0.00', '$0.004213' );

		self::assertSame( '<span class="prstsearch-figure" title="$0.004213">$0.00</span>', $figure->html() );
	}

	public function test_a_figure_that_lost_nothing_is_plain_text(): void {
		self::assertSame( '12', ( new Figure( '12' ) )->html() );
		self::assertSame( '$1.50', ( new Figure( '$1.50', '$1.50' ) )->html() );
	}

	public function test_text_is_escaped_in_both_places(): void {
		$figure = new Figure( '<b>1</b>', '"><script>' );

		self::assertSame(
			'<span class="prstsearch-figure" title="&quot;&gt;&lt;script&gt;">&lt;b&gt;1&lt;/b&gt;</span>',
			$figure->html()
		);
	}

	public function test_used_as_a_string_it_is_the_rounded_text(): void {
		self::assertSame( 'Costs: $0.00', 'Costs: ' . new Figure( '$0.00', '$0.004213' ) );
	}

	public function test_a_sentence_keeps_an_exact_version_of_itself(): void {
		$sentence = Figure::sentence(
			'Input %1$s · Output %2$s',
			new Figure( '$0.00', '$0.0007' ),
			new Figure( '$0.01', '$0.0131' )
		);

		self::assertSame( 'Input $0.00 · Output $0.01', $sentence->text );
		self::assertSame( 'Input $0.0007 · Output $0.0131', $sentence->exact );
	}

	public function test_a_sentence_of_unrounded_values_needs_no_tooltip(): void {
		$sentence = Figure::sentence( '%1$s of %2$s', new Figure( '3' ), '10' );

		self::assertSame( '3 of 10', $sentence->html() );
	}
}
