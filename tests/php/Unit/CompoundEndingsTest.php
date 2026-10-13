<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Search\CompoundEndings;
use PrstSearch\Search\SearchService;

/**
 * Short words as the last part of a compound, and the words of two letters.
 */
final class CompoundEndingsTest extends SearchTestCase {

	public function test_a_short_word_is_found_as_the_last_part_of_a_compound(): void {
		$this->add_page( 1, 'Eingang', 'Die Haustür klemmt seit Tagen.' );
		$this->add_page( 2, 'Wohnen', 'Ein Haus am See.' );
		$this->add_page( 3, 'Umwelt', 'Natur und Kultur in der Region.' );

		self::assertSame( array( 1 ), $this->found( 'Tür' ), 'Not "Natur" or "Kultur": "Na" and "Kul" are no words.' );
		self::assertSame( array( 1 ), $this->found( 'Türen' ) );
	}

	public function test_a_first_part_the_website_does_not_use_makes_a_likely_compound_that_counts_less(): void {
		$this->add_page( 1, 'Fuhrpark', 'Ein Mietwagen steht bereit.' );
		$this->add_page( 2, 'Garage', 'Der Wagenheber fehlt.' );
		$this->add_page( 3, 'Hof', 'Der Hofwagen und der Hof.' );
		$this->add_page( 4, 'Werkstatt', 'Ein Wagen wird repariert.' );

		// Sure matches first: the word, then the compound whose first part is a word of the website.
		self::assertSame( array( 4, 3, 2, 1 ), $this->found( 'Wagen' ) );

		$found = $this->container->get( CompoundEndings::class )->in( 'Ein Mietwagen und ein Hofwagen.', array( 'wagen' ) );

		self::assertSame( array( 'hofwagen' ), $found['wagen'] );
		self::assertSame( array( 'mietwagen' ), $found[ CompoundEndings::LIKELY . 'wagen' ] );
	}

	public function test_a_word_that_merely_ends_alike_is_no_compound(): void {
		$this->add_page( 1, 'Umwelt', 'Natur und Kultur, Struktur und Architektur.' );
		$this->add_page( 2, 'Kontakt', 'Der Prozess der Anmeldung und alle Adressen.' );

		self::assertSame( array(), $this->found( 'Tür' ), 'None of them is written with "ü". Searching inside words does not bring them back either.' );
		self::assertSame( array(), $this->found( 'Essen' ), '"Adr" is too short for a word, and "Prozess" does not end in what was typed.' );
	}

	public function test_every_compound_is_judged_however_many_there_are(): void {
		foreach ( range( 1, 45 ) as $number ) {
			$name = 'Zimak' . chr( 97 + intdiv( $number, 26 ) ) . chr( 97 + $number % 26 );

			$this->add_page( $number, 'Raum ' . $number, 'Der ' . $name . ' und die ' . $name . 'tür.' );
		}

		$this->configure( array( 'match_inside_words' => false ) );

		self::assertSame( 45, $this->container->get( SearchService::class )->search( 'Tür' )->total );
	}

	public function test_compounds_are_found_among_many_words_that_merely_end_alike(): void {
		foreach ( range( 1, 320 ) as $number ) {
			$this->add_page( $number, 'Umwelt ' . $number, 'Natur und Kultur, Struktur und Architektur.' );
		}

		$this->add_page( 400, 'Keller', 'Die Kellertür im Keller klemmt.' );
		$this->add_page( 401, 'Eingang', 'Eine Tür aus Holz.' );

		self::assertSame( array( 401, 400 ), $this->found( 'Tür' ), 'Not crowded out by the 320 pages the index returns for the ending.' );
	}

	public function test_a_number_is_no_last_part_of_a_compound(): void {
		$this->configure( array( 'match_inside_words' => false ) );
		$this->add_page( 1, 'Teile', 'Nummer 456123 und Nummer 456.' );

		self::assertSame( array(), $this->found( '123' ) );
	}

	public function test_a_search_returns_no_more_results_than_the_setting_allows(): void {
		foreach ( range( 1, 15 ) as $number ) {
			$this->add_page( $number, 'Versand ' . $number, 'Text.' );
		}

		$this->configure( array( 'max_results' => 12 ) );

		self::assertSame( 12, $this->container->get( SearchService::class )->search( 'Versand' )->total );
	}

	public function test_the_first_part_may_carry_a_linking_letter_or_an_ending(): void {
		$this->add_page( 1, 'Büro', 'Ein langer Arbeitstag geht zu Ende.' );
		$this->add_page( 2, 'Stellen', 'Arbeit für alle.' );
		$this->add_page( 3, 'Küche', 'Das Abendessen steht bereit, und die Adressen der Gäste liegen daneben.' );
		$this->add_page( 4, 'Zeiten', 'Am Abend geschlossen.' );
		$this->add_page( 5, 'Kontakt', 'Alle Adressen und der Prozess der Anmeldung.' );

		self::assertSame( array( 1 ), $this->found( 'Tag' ) );
		self::assertSame( array( 3 ), $this->found( 'Essen' ), 'Not the page that only has "Adressen" and "Prozess".' );
	}

	public function test_the_word_itself_comes_before_a_compound_made_with_it(): void {
		$this->add_page( 1, 'Eingang', 'Die Haustür am Haus klemmt.' );
		$this->add_page( 2, 'Werkstatt', 'Eine Tür aus Holz.' );

		self::assertSame( array( 2, 1 ), $this->found( 'Tür' ) );
	}

	public function test_the_index_is_asked_once_for_the_same_first_part(): void {
		$this->add_page( 1, 'Eingang', 'Die Haustür am Haus. Noch eine Haustür.' );

		$endings = $this->container->get( CompoundEndings::class );

		self::assertSame( array( 'tür' => array( 'haustür' ) ), $endings->in( 'Die Haustür. Noch eine Haustür.', array( 'tür' ) ) );

		$endings->in( 'Die Haustür.', array( 'tür' ) );

		self::assertSame( array( 'known terms' ), $this->store->calls );
	}

	public function test_a_longer_word_needs_no_such_check(): void {
		$this->add_page( 1, 'Preise', 'Die Versandkosten im Überblick.' );

		self::assertSame( array( 1 ), $this->found( 'Kosten' ) );
		self::assertNotContains( 'known terms', $this->store->calls );
	}

	public function test_the_words_of_two_letters_are_found_in_their_forms(): void {
		$this->add_page( 1, 'Frühstück', 'Ein Ei zum Brot.' );
		$this->add_page( 2, 'Omelett', 'Drei Eier und etwas Öl.' );
		$this->add_page( 3, 'Verein', 'Eine Feier, ein Verein und eine Partei.' );

		self::assertEqualsCanonicalizing( array( 1, 2 ), $this->found( 'Eier' ) );
		self::assertEqualsCanonicalizing( array( 1, 2 ), $this->found( 'Ei' ) );
		self::assertSame( array( 2 ), $this->found( 'Öle' ) );
	}

	public function test_a_plural_that_doubles_its_s_is_the_same_word(): void {
		$this->add_page( 1, 'Schule', 'Das Ergebnis der Prüfung.' );
		$this->add_page( 2, 'Verkehr', 'Zwei Busse fahren morgens.' );
		$this->add_page( 3, 'Reisen', 'Die Reisebusse stehen bereit.' );

		self::assertSame( array( 1 ), $this->found( 'Ergebnisse' ) );
		self::assertSame( array( 2, 3 ), $this->found( 'Bus' ), 'The word itself first, then the compound: "Reise" is a word of the website.' );
		self::assertSame( array( 2, 3 ), $this->found( 'Busse' ) );
	}

	/**
	 * @return int[] IDs of the posts found, best first.
	 */
	private function found( string $query ): array {
		return $this->container->get( SearchService::class )->search( $query )->post_ids();
	}
}
