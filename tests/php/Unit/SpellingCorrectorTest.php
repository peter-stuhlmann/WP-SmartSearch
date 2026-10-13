<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\SpellingCorrector;
use PrstSearch\Search\Vocabulary;

final class SpellingCorrectorTest extends TestCase {

	private SpellingCorrector $corrector;

	protected function setUp(): void {
		$this->corrector = new SpellingCorrector(
			array(
				'hähnchen'      => 12,
				'versandkosten' => 8,
				'versand'       => 20,
				'datenschutz'   => 5,
				'rezept'        => 9,
				'rezepte'       => 3,
				'spargel'       => 2,
				'café'          => 4,
			)
		);
	}

	public function test_a_missing_umlaut_is_restored(): void {
		self::assertSame( array( 'hähnchen' ), $this->corrector->correct( array( 'hahnchen' ) ) );
	}

	public function test_a_missing_letter_is_restored(): void {
		// The example that started it: "hanchen" lacks the umlaut and an h.
		self::assertSame( array( 'hähnchen' ), $this->corrector->correct( array( 'hanchen' ) ) );
	}

	public function test_swapped_and_wrong_letters_are_corrected_in_long_words(): void {
		self::assertSame( array( 'datenschutz' ), $this->corrector->correct( array( 'datneschutz' ) ) );
		self::assertSame( array( 'versandkosten' ), $this->corrector->correct( array( 'versantkosden' ) ) );
	}

	public function test_only_the_misspelled_words_of_a_query_change(): void {
		self::assertSame( array( 'hähnchen', 'rezept' ), $this->corrector->correct( array( 'hanchen', 'rezept' ) ) );
	}

	public function test_correct_words_are_left_alone(): void {
		self::assertNull( $this->corrector->correct( array( 'versand', 'spargel' ) ) );
	}

	public function test_words_far_from_everything_are_left_alone(): void {
		self::assertNull( $this->corrector->correct( array( 'xyzzy', 'qwertzuiop' ) ) );
	}

	public function test_short_words_are_never_corrected(): void {
		// "caf" is one letter from "café", but three-letter words have too many neighbours.
		self::assertNull( $this->corrector->correct( array( 'caf' ) ) );
	}

	public function test_short_words_tolerate_one_wrong_letter_only(): void {
		self::assertSame( array( 'rezept' ), $this->corrector->correct( array( 'rezebt' ) ) );
		self::assertNull( $this->corrector->correct( array( 'resebt' ) ) );
	}

	public function test_the_more_frequent_word_wins_a_tie(): void {
		// "rezepd" is one letter from "rezept" (9 times) and from "rezepte" (3 times).
		self::assertSame( array( 'rezept' ), $this->corrector->correct( array( 'rezepd' ) ) );
	}

	public function test_vocabulary_counts_words_of_four_letters_and_more(): void {
		$words = Vocabulary::count_words( array( 'Hähnchen mit Ei. Das Hähnchen!', 'Spargel und HÄHNCHEN, 2026' ) );

		self::assertSame(
			array(
				'hähnchen' => 3,
				'spargel'  => 1,
			),
			$words
		);
	}
}
