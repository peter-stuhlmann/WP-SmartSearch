<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Index\HtmlToText;

final class HtmlToTextTest extends TestCase {

	private HtmlToText $converter;

	protected function setUp(): void {
		$this->converter = new HtmlToText();
	}

	public function test_blocks_become_lines(): void {
		$text = $this->converter->convert( '<h2>Shipping</h2><p>We ship <strong>worldwide</strong>.</p><ul><li>Fast</li><li>Tracked</li></ul>' );

		self::assertSame( "Shipping\nWe ship worldwide.\nFast\nTracked", $text );
	}

	public function test_scripts_styles_and_forms_are_removed(): void {
		$html = '<p>Visible</p><script>alert("x")</script><style>p{color:red}</style><form><input value="hidden"></form><nav><a href="#">Menu</a></nav>';

		self::assertSame( 'Visible', $this->converter->convert( $html ) );
	}

	public function test_entities_are_decoded_and_whitespace_collapsed(): void {
		$text = $this->converter->convert( "<p>Caf&eacute;&nbsp;&amp;   Bar\n\t</p>" );

		self::assertSame( 'Café & Bar', $text );
	}

	public function test_comments_and_leftover_shortcodes_are_removed(): void {
		$text = $this->converter->convert( '<!-- wp:paragraph --><p>Text [gallery ids="1,2"] more</p><!-- /wp:paragraph -->' );

		self::assertSame( 'Text more', $text );
	}

	public function test_line_breaks_separate_lines(): void {
		self::assertSame( "Line one\nLine two", $this->converter->convert( 'Line one<br />Line two' ) );
	}

	public function test_square_brackets_in_ordinary_text_are_kept(): void {
		self::assertSame(
			'He wrote "recieve [sic]" and cited [1] and [Smith 2020].',
			$this->converter->convert( '<p>He wrote "recieve [sic]" and cited [1] and [Smith 2020].</p>' )
		);
	}

	public function test_shortcodes_that_were_not_rendered_are_removed(): void {
		self::assertSame(
			'Before Caption after',
			$this->converter->convert( '<p>Before [gallery ids="1,2"] [caption id="x"]Caption[/caption] after</p>' )
		);
	}
}
