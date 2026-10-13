<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\ResultJudge;

/**
 * Reading the language model's answer. The model is free text; the plugin must not trust its form.
 */
final class ResultJudgeTest extends TestCase {

	private const OFFERED = array( 101, 202, 303, 404 );

	public function test_positions_are_mapped_to_posts(): void {
		self::assertSame( array( 101, 303 ), ResultJudge::chosen( '1, 3', self::OFFERED ) );
	}

	public function test_none_means_nothing_fits(): void {
		self::assertSame( array(), ResultJudge::chosen( 'none', self::OFFERED ) );
		self::assertSame( array(), ResultJudge::chosen( '', self::OFFERED ) );
	}

	public function test_chatty_answers_are_understood(): void {
		self::assertSame( array( 202, 404 ), ResultJudge::chosen( "Pages 2 and 4 fit the query.\n", self::OFFERED ) );
	}

	public function test_positions_that_were_never_offered_are_dropped(): void {
		self::assertSame( array( 101 ), ResultJudge::chosen( '0, 1, 5, 99', self::OFFERED ) );
	}

	public function test_a_position_named_twice_counts_once(): void {
		self::assertSame( array( 202 ), ResultJudge::chosen( '2, 2, 2', self::OFFERED ) );
	}
}
