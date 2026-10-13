<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Index\Chunker;

final class ChunkerTest extends TestCase {

	public function test_short_text_stays_one_chunk(): void {
		$chunks = ( new Chunker( 100 ) )->split( 'One sentence. Another one.' );

		self::assertSame( array( 'One sentence. Another one.' ), $chunks );
	}

	public function test_empty_text_gives_no_chunks(): void {
		self::assertSame( array(), ( new Chunker() )->split( " \n " ) );
	}

	public function test_no_chunk_exceeds_the_maximum_length(): void {
		$text   = str_repeat( 'This is a sentence about shipping. ', 40 );
		$chunks = ( new Chunker( 120 ) )->split( $text );

		self::assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			self::assertLessThanOrEqual( 120, mb_strlen( $chunk ) );
		}
	}

	public function test_consecutive_chunks_overlap_by_one_sentence(): void {
		$chunks = ( new Chunker( 70 ) )->split( 'Alpha is first. Beta is second. Gamma is third. Delta is fourth. Epsilon is fifth. Zeta is sixth.' );

		self::assertSame( 'Alpha is first. Beta is second. Gamma is third. Delta is fourth.', $chunks[0] );
		self::assertSame( 'Delta is fourth. Epsilon is fifth. Zeta is sixth.', $chunks[1] );
	}

	public function test_long_sentences_are_not_repeated(): void {
		// A sentence longer than a quarter of a chunk would crowd out new text.
		$chunks = ( new Chunker( 40 ) )->split( 'Alpha is first. Beta is second. Gamma is third. Delta is last.' );

		self::assertSame( array( 'Alpha is first. Beta is second.', 'Gamma is third. Delta is last.' ), $chunks );
	}

	public function test_overlong_sentence_is_wrapped_at_word_boundaries(): void {
		$chunks = ( new Chunker( 20 ) )->split( 'word word word word word word word word' );

		self::assertSame( array( 'word word word word', 'word word word word' ), $chunks );
	}

	public function test_a_word_longer_than_a_chunk_is_cut(): void {
		$chunks = ( new Chunker( 10 ) )->split( str_repeat( 'x', 25 ) );

		self::assertSame( array( 'xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx' ), $chunks );
	}

	public function test_no_text_is_lost(): void {
		$sentences = array_map( static fn ( int $number ): string => "Sentence number {$number}.", range( 1, 30 ) );
		$chunks    = ( new Chunker( 80 ) )->split( implode( ' ', $sentences ) );
		$joined    = implode( ' ', $chunks );

		foreach ( $sentences as $sentence ) {
			self::assertStringContainsString( $sentence, $joined );
		}
	}

	public function test_the_sentence_carried_over_never_pushes_a_chunk_over_the_maximum(): void {
		// Sentences of very different lengths: a long one carried over plus the next long one would not fit.
		$sentences = array();

		foreach ( array( 700, 40, 650, 680, 30, 690, 500, 640 ) as $length ) {
			$sentences[] = rtrim( str_repeat( 'word ', intdiv( $length, 5 ) ) ) . '.';
		}

		$chunks = ( new Chunker( 1200 ) )->split( implode( ' ', $sentences ) );

		self::assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			self::assertLessThanOrEqual( 1200, mb_strlen( $chunk ) );
		}
	}

	public function test_no_text_is_lost_between_chunks(): void {
		$sentences = array();

		for ( $number = 1; $number <= 60; $number++ ) {
			$sentences[] = 'Sentence number ' . $number . ' talks about item ' . $number . '.';
		}

		$joined = implode( ' ', ( new Chunker( 200 ) )->split( implode( ' ', $sentences ) ) );

		foreach ( $sentences as $sentence ) {
			self::assertStringContainsString( $sentence, $joined );
		}
	}
}
