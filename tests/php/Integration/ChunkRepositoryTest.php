<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

use PrstSearch\Database\ChunkRepository;
use PrstSearch\Search\QueryTerms;
use PrstSearch\Search\Vector;

/**
 * The SQL of the search index.
 */
final class ChunkRepositoryTest extends DatabaseTestCase {

	private const MODEL = 'test-embed';

	private ChunkRepository $chunks;

	protected function setUp(): void {
		parent::setUp();

		$this->chunks = new ChunkRepository( $this->wpdb, $this->installer->chunks_table() );
	}

	public function test_storing_the_same_text_again_changes_nothing(): void {
		self::assertTrue( $this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Wir liefern weltweit.' ) ) );
		self::assertFalse( $this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Wir liefern weltweit.' ) ) );
		self::assertTrue( $this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Wir liefern europaweit.' ) ) );
		self::assertTrue( $this->chunks->replace_post_chunks( 1, 'post', 'Lieferung', array( 'Wir liefern europaweit.' ) ), 'A new title is a change.' );
		self::assertTrue( $this->chunks->replace_post_chunks( 1, 'page', 'Lieferung', array( 'Wir liefern europaweit.' ) ), 'A new post type is a change.' );

		self::assertSame( 1, $this->chunks->counts( self::MODEL )['chunks'] );
	}

	public function test_an_embedding_is_kept_for_a_passage_whose_text_did_not_change(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Erster Absatz.', 'Zweiter Absatz.' ) );

		foreach ( $this->chunks->missing_embeddings( self::MODEL, 10 ) as $row ) {
			$this->chunks->save_embedding( $row['id'], 'packed-vector', self::MODEL );
		}

		self::assertSame( 0, $this->chunks->counts( self::MODEL )['missing'] );

		// The second passage is rewritten, the first stays as it is.
		$this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Erster Absatz.', 'Neuer zweiter Absatz.' ) );

		$missing = $this->chunks->missing_embeddings( self::MODEL, 10 );

		self::assertCount( 1, $missing, 'Only the changed passage has to be paid for again.' );
		self::assertSame( 'Neuer zweiter Absatz.', $missing[0]['content'] );
		self::assertTrue( $this->chunks->has_missing_embeddings( 1, self::MODEL ) );
	}

	public function test_embeddings_of_another_model_count_as_missing(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Versand', array( 'Text.' ) );
		$this->chunks->save_embedding( $this->chunks->missing_embeddings( self::MODEL, 1 )[0]['id'], 'packed-vector', 'old-model' );

		self::assertSame( 1, $this->chunks->counts( self::MODEL )['missing'] );
		self::assertSame( array( 1 ), $this->chunks->posts_missing_embeddings( self::MODEL ) );
		self::assertSame( array(), iterator_to_array( $this->chunks->embeddings( self::MODEL ), false ) );
	}

	public function test_missing_embeddings_can_be_limited_to_one_post(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Eins', array( 'Text eins.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'Zwei', array( 'Text zwei.' ) );

		$missing = $this->chunks->missing_embeddings( self::MODEL, 10, 2 );

		self::assertCount( 1, $missing );
		self::assertSame( 2, $missing[0]['post_id'] );
	}

	public function test_the_fulltext_search_finds_words_in_title_and_content(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Versandkosten', array( 'Die Lieferung ist ab 50 Euro kostenlos.' ) );
		$this->chunks->replace_post_chunks( 2, 'page', 'Kontakt', array( 'Fragen zu den Versandkosten beantworten wir gern.' ) );
		$this->chunks->replace_post_chunks( 3, 'page', 'Impressum', array( 'Angaben zum Unternehmen.' ) );

		$rows = $this->chunks->fulltext_search( QueryTerms::from_phrase( 'Versandkosten' )->to_boolean_query(), 10 );

		self::assertEqualsCanonicalizing( array( 1, 2 ), array_column( $rows, 'post_id' ) );
		self::assertIsFloat( $rows[0]['score'] );
		self::assertIsInt( $rows[0]['id'] );
	}

	public function test_short_words_are_found_as_words_of_their_own(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Frühstück', array( 'Ein Ei, Öl und Brot.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'Verein', array( 'Eine Partei, ein Verein und viel Arbeit.' ) );
		$this->chunks->replace_post_chunks( 3, 'post', 'Ei', array( 'Nur im Titel.' ) );
		$this->chunks->replace_post_chunks( 4, 'post', 'Zwei Teile', array( 'Ein Ei hier.', 'Und Öl erst im zweiten Absatz.' ) );

		$found = fn ( string ...$terms ): array => array_column( $this->chunks->word_search( $terms, 10 ), 'post_id' );

		self::assertEqualsCanonicalizing( array( 1, 3, 4 ), $found( 'ei' ), 'Not "ein", "Partei" or "Arbeit".' );
		self::assertSame( array( 1 ), $found( 'ei', 'öl' ), 'Every term, in the same passage.' );
		self::assertSame( array(), $found( 'zz' ) );
		self::assertSame( array(), $found() );
		self::assertCount( 1, $this->chunks->word_search( array( 'ei' ), 1 ) );
	}

	public function test_a_stem_on_the_stop_word_list_of_the_database_is_found(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Gemeinschaft', array( 'Unsere Foren sind offen.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'Format', array( 'Ein anderes Wort mit demselben Anfang.' ) );

		$rows = $this->chunks->fulltext_search( QueryTerms::from_phrase( 'Foren' )->to_boolean_query(), 10 );

		self::assertContains( 1, array_column( $rows, 'post_id' ) );
	}

	public function test_a_passage_without_words_does_not_keep_the_upgrade_busy(): void {
		$this->chunks->replace_post_chunks( 1, 'post', '?', array( '— — —' ) );

		self::assertSame( 0, $this->chunks->complete_passages( 10 ), 'Stored complete, although it has no words.' );
	}

	public function test_suggestions_list_titles_that_start_with_the_text_first(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Unser Versand', array( 'Text.', 'Mehr Text.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'Versandkosten', array( 'Text.' ) );
		$this->chunks->replace_post_chunks( 3, 'post', 'Kontakt', array( 'Versand steht hier nur im Text.' ) );

		self::assertSame( array( 2, 1 ), $this->chunks->suggest( 'Versand', 10 ) );
		self::assertSame( array(), $this->chunks->suggest( '100%', 10 ) );
	}

	public function test_posts_and_whole_post_types_can_be_removed(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Eins', array( 'Text.' ) );
		$this->chunks->replace_post_chunks( 2, 'page', 'Zwei', array( 'Text.' ) );
		$this->chunks->replace_post_chunks( 3, 'book', 'Drei', array( 'Text.' ) );

		self::assertTrue( $this->chunks->delete_post( 1 ) );
		self::assertFalse( $this->chunks->delete_post( 1 ), 'Nothing left to delete.' );
		self::assertFalse( $this->chunks->has_chunks( 1 ) );

		$this->chunks->delete_other_post_types( array( 'page' ) );

		self::assertSame( array( 'page' => 1 ), $this->chunks->posts_by_type() );
		self::assertSame( array( 2 ), $this->chunks->post_ids_after( 0, 10 ) );
		self::assertSame( array(), $this->chunks->post_ids_after( 2, 10 ) );
	}

	public function test_the_whole_text_of_posts_is_returned_in_reading_order(): void {
		$this->chunks->replace_post_chunks( 7, 'post', 'Titel', array( 'Erster Absatz.', 'Zweiter Absatz.' ) );
		$this->chunks->replace_post_chunks( 8, 'post', 'Anderer', array( 'Text.' ) );
		$this->chunks->replace_post_chunks( 9, 'post', 'Nicht gefragt', array( 'Text.' ) );

		self::assertSame(
			array(
				7 => "Titel\nErster Absatz.\nZweiter Absatz.",
				8 => "Anderer\nText.",
			),
			$this->chunks->post_texts( array( 8, 7, 7, 12345 ) )
		);
		self::assertSame( array(), $this->chunks->post_texts( array() ) );
	}

	public function test_the_database_finds_other_forms_of_a_word_and_the_last_part_of_a_compound(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Lieferung', array( 'Die Versandkosten hängen vom Gewicht ab.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'Küche', array( 'Unsere besten Rezepte und zwei Häuser.' ) );
		$this->chunks->replace_post_chunks( 3, 'post', 'Möbel', array( 'Ein Regal aus Holz.' ) );

		$found = fn ( string $phrase ): array => array_column(
			$this->chunks->fulltext_search( QueryTerms::from_phrase( $phrase )->to_boolean_query(), 10 ),
			'post_id'
		);

		self::assertSame( array( 2 ), $found( 'Rezept' ) );
		self::assertSame( array( 2 ), $found( 'Rezepten' ) );
		self::assertSame( array( 2 ), $found( 'Haus' ) );
		self::assertSame( array( 1 ), $found( 'Kosten' ), 'The end of "Versandkosten".' );
		self::assertSame( array( 1 ), $found( 'Versand' ), 'Its beginning.' );
		self::assertSame( array(), $found( 'Lager' ), '"Regal" backwards must not be found as a word.' );
	}

	public function test_passages_of_an_older_version_are_brought_up_to_date(): void {
		$this->chunks->replace_post_chunks( 1, 'post', 'Küche', array( 'Unsere besten Rezepte.', 'Noch ein Absatz.' ) );

		$first = $this->chunks->missing_embeddings( self::MODEL, 1 )[0]['id'];
		$this->chunks->save_embedding( $first, Vector::pack( array( 1.0, -1.0, 1.0 ) ), self::MODEL );

		// What an upgrade finds: neither terms nor signatures.
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE %i SET terms = '', sig_0 = 0", $this->installer->chunks_table() ) );

		$query = QueryTerms::from_phrase( 'Rezept' )->to_boolean_query();

		self::assertSame( array(), $this->chunks->fulltext_search( $query, 10 ) );
		self::assertSame( 2, $this->chunks->complete_passages( 10 ) );
		self::assertSame( 0, $this->chunks->complete_passages( 10 ), 'Nothing is left to do.' );
		self::assertCount( 1, $this->chunks->fulltext_search( $query, 10 ) );
		self::assertSame( array( $first ), $this->chunks->nearest( self::MODEL, Vector::signature( array( 1.0, -1.0, 1.0 ) ), 1 ) );
	}

	public function test_the_first_selection_returns_the_passages_whose_embedding_points_the_same_way(): void {
		$vectors = array(
			'same'     => array( 0.9, -0.2, 0.4, -0.7, 0.1, 0.3 ),
			'close'    => array( 0.8, -0.1, 0.5, 0.6, 0.2, 0.3 ),
			'opposite' => array( -0.9, 0.2, -0.4, 0.7, -0.1, -0.3 ),
		);

		$ids = array();

		foreach ( array_keys( $vectors ) as $position => $name ) {
			$this->chunks->replace_post_chunks( $position + 1, 'post', $name, array( 'Text ' . $name ) );
		}

		foreach ( $this->chunks->missing_embeddings( self::MODEL, 10 ) as $row ) {
			$ids[ $row['title'] ] = $row['id'];
			$this->chunks->save_embedding( $row['id'], Vector::pack( $vectors[ $row['title'] ] ), self::MODEL );
		}

		// A passage without an embedding, and one of another model, are never selected.
		$this->chunks->replace_post_chunks( 9, 'post', 'none', array( 'Text.' ) );

		$signature = Vector::signature( array( 1.0, -1.0, 1.0, -1.0, 1.0, 1.0 ) );

		self::assertSame( array( $ids['same'], $ids['close'], $ids['opposite'] ), $this->chunks->nearest( self::MODEL, $signature, 10 ) );
		self::assertSame( array( $ids['same'] ), $this->chunks->nearest( self::MODEL, $signature, 1 ) );
		self::assertSame( array(), $this->chunks->nearest( 'another-model', $signature, 10 ) );

		$loaded = $this->chunks->embeddings_of( array( $ids['close'], 999999 ) );

		self::assertCount( 1, $loaded );
		self::assertEqualsWithDelta( $vectors['close'], Vector::unpack( $loaded[0]['embedding'] ), 0.000001 );
	}

	public function test_the_highest_bit_of_a_signature_survives_the_database(): void {
		// Dimension 63 is the sign bit of a 64-bit integer, which is where an unsigned column and PHP disagree.
		$vector     = array_fill( 0, 64, -1.0 );
		$vector[63] = 1.0;
		$other      = array_fill( 0, 64, -1.0 );
		$other[0]   = 1.0;

		$this->chunks->replace_post_chunks( 1, 'post', 'high', array( 'Text.' ) );
		$this->chunks->replace_post_chunks( 2, 'post', 'low', array( 'Text.' ) );

		foreach ( $this->chunks->missing_embeddings( self::MODEL, 10 ) as $row ) {
			$this->chunks->save_embedding( $row['id'], Vector::pack( 'high' === $row['title'] ? $vector : $other ), self::MODEL );
		}

		$nearest = $this->chunks->nearest( self::MODEL, Vector::signature( $vector ), 1 );

		self::assertSame( 'high', $this->chunks->find( $nearest )[ $nearest[0] ]['title'] );
	}

	public function test_a_large_index_can_be_sampled_evenly(): void {
		for ( $post_id = 1; $post_id <= 6; $post_id++ ) {
			$this->chunks->replace_post_chunks( $post_id, 'post', 'Titel ' . $post_id, array( 'Text.' ) );
		}

		foreach ( $this->chunks->missing_embeddings( self::MODEL, 10 ) as $row ) {
			$this->chunks->save_embedding( $row['id'], Vector::pack( array( 1.0, 0.0 ) ), self::MODEL );
		}

		self::assertCount( 6, iterator_to_array( $this->chunks->embeddings( self::MODEL ), false ) );
		self::assertCount( 2, iterator_to_array( $this->chunks->embeddings( self::MODEL, 3 ), false ) );
	}

	public function test_the_first_passage_of_posts_is_found(): void {
		$this->chunks->replace_post_chunks( 7, 'post', 'Titel', array( 'Erster Absatz.', 'Zweiter Absatz.' ) );
		$this->chunks->replace_post_chunks( 8, 'post', 'Anderer', array( 'Text.' ) );

		$first = $this->chunks->first_passages( array( 7, 8, 12345 ) );

		self::assertSame( array( 7, 8 ), array_keys( $first ) );
		self::assertSame( 'Erster Absatz.', $this->chunks->find( array( $first[7] ) )[ $first[7] ]['content'] );
		self::assertSame( array(), $this->chunks->first_passages( array() ) );
	}

	public function test_passages_are_found_by_their_ids(): void {
		$this->chunks->replace_post_chunks( 7, 'post', 'Titel', array( 'Inhalt.' ) );

		$id = $this->chunks->missing_embeddings( self::MODEL, 1 )[0]['id'];

		self::assertSame(
			array(
				$id => array(
					'post_id' => 7,
					'title'   => 'Titel',
					'content' => 'Inhalt.',
				),
			),
			$this->chunks->find( array( $id, 999999 ) )
		);
		self::assertSame( array(), $this->chunks->find( array() ) );
	}
}
