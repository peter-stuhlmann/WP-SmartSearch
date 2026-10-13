<?php
/**
 * Persistence for the search index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Database;

use Generator;
use PrstSearch\Search\IndexTerms;
use PrstSearch\Search\Vector;
use wpdb;

/**
 * Reads and writes index chunks. All SQL for the index table lives here.
 */
final class ChunkRepository implements ChunkStore {

	private const BATCH = 500;

	/**
	 * Creates the repository.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly string $table
	) {}

	/**
	 * Replaces all chunks of a post.
	 *
	 * Embeddings of chunks whose text did not change are carried over, so an
	 * edit only costs tokens for the paragraphs that were actually touched.
	 *
	 * @param string[] $chunks Chunk texts in reading order.
	 * @return bool Whether the stored text changed. False if the post was already stored exactly like this.
	 */
	public function replace_post_chunks( int $post_id, string $post_type, string $title, array $chunks ): bool {
		$chunks = array_values( $chunks );
		$hashes = array_map( static fn ( string $content ): string => self::hash( $title, $content ), $chunks );

		if ( $hashes === $this->stored_hashes( $post_id, $post_type ) ) {
			return false;
		}

		$reusable = $this->embeddings_by_hash( $post_id );

		$this->delete_post( $post_id );

		foreach ( $chunks as $index => $content ) {
			$hash  = $hashes[ $index ];
			$known = $reusable[ $hash ] ?? null;

			$this->wpdb->insert(
				$this->table,
				array(
					'post_id'         => $post_id,
					'chunk_index'     => $index,
					'post_type'       => $post_type,
					'title'           => $title,
					'content'         => $content,
					'content_hash'    => $hash,
					'terms'           => IndexTerms::of( $title, $content ),
					'embedding'       => $known['embedding'] ?? null,
					'embedding_model' => $known['embedding_model'] ?? '',
				) + self::signature_values( $known['embedding'] ?? null )
			);
		}

		return true;
	}

	/**
	 * Removes all chunks of a post.
	 *
	 * @return bool Whether the post had chunks.
	 */
	public function delete_post( int $post_id ): bool {
		return (int) $this->wpdb->delete( $this->table, array( 'post_id' => $post_id ), array( '%d' ) ) > 0;
	}

	/**
	 * Returns the IDs of indexed posts in ascending order, for walking the whole index in steps.
	 *
	 * @param int $after_id Return posts with a higher ID than this.
	 * @return int[]
	 */
	public function post_ids_after( int $after_id, int $limit ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT post_id FROM %i WHERE post_id > %d ORDER BY post_id ASC LIMIT %d',
				$this->table,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Tells whether a post has any chunk in the index.
	 */
	public function has_chunks( int $post_id ): bool {
		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT 1 FROM %i WHERE post_id = %d LIMIT 1', $this->table, $post_id )
		);
	}

	/**
	 * Removes the chunks of all post types except the given ones.
	 *
	 * @param string[] $post_types Post types to keep.
	 */
	public function delete_other_post_types( array $post_types ): void {
		if ( array() === $post_types ) {
			$this->truncate();
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE post_type NOT IN ({$placeholders})",
				array_merge( array( $this->table ), array_values( $post_types ) )
			)
		);
		// phpcs:enable
	}

	/**
	 * Returns the posts that have chunks without an embedding for the model.
	 *
	 * @return int[] Post IDs.
	 */
	public function posts_missing_embeddings( string $embedding_model ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT post_id FROM %i WHERE embedding IS NULL OR embedding_model <> %s',
				$this->table,
				$embedding_model
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Empties the index.
	 */
	public function truncate(): void {
		$this->wpdb->query( $this->wpdb->prepare( 'TRUNCATE TABLE %i', $this->table ) );
	}

	/**
	 * Returns index totals.
	 *
	 * @return array{chunks: int, posts: int, missing: int}
	 */
	public function counts( string $embedding_model ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT COUNT(*) AS chunks, COUNT(DISTINCT post_id) AS posts,
					COALESCE(SUM(embedding IS NULL OR embedding_model <> %s), 0) AS missing
				FROM %i',
				$embedding_model,
				$this->table
			),
			ARRAY_A
		);

		return array(
			'chunks'  => (int) ( $row['chunks'] ?? 0 ),
			'posts'   => (int) ( $row['posts'] ?? 0 ),
			'missing' => (int) ( $row['missing'] ?? 0 ),
		);
	}

	/**
	 * Returns the number of indexed posts per post type, largest first.
	 *
	 * @return array<string, int>
	 */
	public function posts_by_type(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT post_type, COUNT(DISTINCT post_id) AS posts FROM %i GROUP BY post_type ORDER BY posts DESC',
				$this->table
			),
			ARRAY_A
		);

		$by_type = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$by_type[ (string) $row['post_type'] ] = (int) $row['posts'];
		}

		return $by_type;
	}

	/**
	 * Returns chunks that have no embedding for the given model yet.
	 *
	 * @param int $post_id Limit to the chunks of one post, or 0 for the oldest chunks of all posts.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string}>
	 */
	public function missing_embeddings( string $embedding_model, int $limit, int $post_id = 0 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, post_id, title, content FROM %i
				WHERE (embedding IS NULL OR embedding_model <> %s) AND (%d = 0 OR post_id = %d)
				ORDER BY id ASC LIMIT %d',
				$this->table,
				$embedding_model,
				$post_id,
				$post_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => array(
				'id'      => (int) $row['id'],
				'post_id' => (int) $row['post_id'],
				'title'   => (string) $row['title'],
				'content' => (string) $row['content'],
			),
			$rows ? $rows : array()
		);
	}

	/**
	 * Tells whether a post still has chunks without an embedding for the model.
	 */
	public function has_missing_embeddings( int $post_id, string $embedding_model ): bool {
		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT 1 FROM %i WHERE post_id = %d AND (embedding IS NULL OR embedding_model <> %s) LIMIT 1',
				$this->table,
				$post_id,
				$embedding_model
			)
		);
	}

	/**
	 * Stores the embedding of one chunk.
	 *
	 * @param string $embedding Packed vector, see Vector::pack().
	 */
	public function save_embedding( int $chunk_id, string $embedding, string $embedding_model ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'embedding'       => $embedding,
				'embedding_model' => $embedding_model,
			) + self::signature_values( $embedding ),
			array( 'id' => $chunk_id )
		);
	}

	/**
	 * Fills in what newer versions of the plugin store for a passage, for passages stored by an older one.
	 *
	 * @param int $limit Number of passages to work on.
	 * @return int Number of passages completed. Fewer than $limit means none are left.
	 */
	public function complete_passages( int $limit ): int {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, title, content, embedding FROM %i WHERE terms = '' ORDER BY id ASC LIMIT %d",
				$this->table,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows ? $rows : array() as $row ) {
			$terms = IndexTerms::of( (string) $row['title'], (string) $row['content'] );

			$this->wpdb->update(
				$this->table,
				array( 'terms' => $terms ) + self::signature_values( $row['embedding'] ),
				array( 'id' => (int) $row['id'] )
			);
		}

		return count( $rows ? $rows : array() );
	}

	/**
	 * Returns the passages whose embedding is most like the given signature.
	 *
	 * A rough first selection, see Vector::signature(). The database compares
	 * every passage, but only reads an index to do so.
	 *
	 * @param int[] $signature Signature of the vector to compare with.
	 * @return int[] Chunk IDs, most alike first.
	 */
	public function nearest( string $embedding_model, array $signature, int $limit ): array {
		$distance = array();

		foreach ( array_values( $signature ) as $word => $bits ) {
			// Written into the query as a number: as a quoted value the database would treat it as a float and lose bits.
			$distance[] = sprintf( 'BIT_COUNT(sig_%d ^ %s)', $word, sprintf( '%u', (int) $bits ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The interpolated string only contains column names and integers.
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE embedding_model = %s ORDER BY ' . implode( ' + ', $distance ) . ' ASC, id ASC LIMIT %d',
				$this->table,
				$embedding_model,
				$limit
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Loads the embeddings of the given passages.
	 *
	 * @param int[] $chunk_ids Chunk IDs.
	 * @return array<int, array{id: int, post_id: int, embedding: string, length: int}> In no particular order.
	 */
	public function embeddings_of( array $chunk_ids ): array {
		$chunk_ids = array_values( array_unique( array_map( 'intval', $chunk_ids ) ) );

		if ( array() === $chunk_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $chunk_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, post_id, embedding, CHAR_LENGTH(content) AS length FROM %i WHERE embedding IS NOT NULL AND id IN ({$placeholders})",
				array_merge( array( $this->table ), $chunk_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( array( self::class, 'to_embedding' ), $rows ? $rows : array() );
	}

	/**
	 * Runs a FULLTEXT query in boolean mode on the stored terms of the passages, see IndexTerms.
	 *
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}> Best matches first.
	 */
	public function fulltext_search( string $boolean_query, int $limit ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, post_id, title, content, MATCH(terms) AGAINST (%s IN BOOLEAN MODE) AS score
				FROM %i
				WHERE MATCH(terms) AGAINST (%s IN BOOLEAN MODE)
				ORDER BY score DESC, id ASC LIMIT %d',
				$boolean_query,
				$this->table,
				$boolean_query,
				$limit
			),
			ARRAY_A
		);

		return self::to_matches( $rows );
	}

	/**
	 * Tells which of the given stems the index holds as a word of their own.
	 *
	 * @param string[] $stems Stems, letters and digits only.
	 * @return string[] Those that some passage contains.
	 */
	public function known_terms( array $stems ): array {
		if ( array() === $stems ) {
			return array();
		}

		$queries = array();
		$values  = array();

		// Each is one look into the index, and all of them are one round trip.
		foreach ( $stems as $stem ) {
			$queries[] = '(SELECT %s AS stem FROM %i WHERE MATCH(terms) AGAINST (%s IN BOOLEAN MODE) LIMIT 1)';
			array_push( $values, $stem, $this->table, $stem );
		}

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The queries only contain placeholders.
		$found = $this->wpdb->get_col( $this->wpdb->prepare( implode( ' UNION ALL ', $queries ), $values ) );

		return array_map( 'strval', $found );
	}

	/**
	 * Finds chunks containing every term as a word of its own.
	 *
	 * For terms FULLTEXT cannot index, such as two-letter words. It reads
	 * every passage, so it is only used when there is no other way. What a
	 * word is, the database judges roughly: letters outside ASCII can count as
	 * its end. The caller checks the matches.
	 *
	 * @param array<int, string[]> $words The words to find, each in the spellings that count as that
	 *                                    word. Letters and digits only.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	public function word_search( array $words, int $limit ): array {
		$words = array_filter( $words );

		if ( array() === $words ) {
			return array();
		}

		$conditions = array();
		$values     = array( $this->table );

		foreach ( $words as $spellings ) {
			$quoted = array_map( static fn ( string $spelling ): string => preg_quote( $spelling, '/' ), $spellings );
			// POSIX classes, which MySQL and MariaDB both understand; their own word boundaries differ.
			$word         = '(^|[^[:alnum:]])(' . implode( '|', $quoted ) . ')([^[:alnum:]]|$)';
			$conditions[] = '(title REGEXP %s OR content REGEXP %s)';
			array_push( $values, $word, $word );
		}

		$values[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The conditions only contain placeholders.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, post_id, title, content, 1 AS score FROM %i WHERE ' . implode( ' AND ', $conditions ) . ' ORDER BY chunk_index ASC, id ASC LIMIT %d',
				$values
			),
			ARRAY_A
		);
		// phpcs:enable

		return self::to_matches( $rows );
	}

	/**
	 * Iterates over the stored embeddings of a model without loading them at once.
	 *
	 * @param int $step Return every n-th embedding only, for an even sample of a large index.
	 * @return Generator<int, array{id: int, post_id: int, embedding: string, length: int}>
	 */
	public function embeddings( string $embedding_model, int $step = 1 ): Generator {
		$last_id = 0;

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT id, post_id, embedding, CHAR_LENGTH(content) AS length FROM %i
					WHERE id > %d AND embedding_model = %s AND embedding IS NOT NULL AND MOD(id, %d) = 0
					ORDER BY id ASC LIMIT %d',
					$this->table,
					$last_id,
					$embedding_model,
					max( 1, $step ),
					self::BATCH
				),
				ARRAY_A
			);

			$rows       = $rows ? $rows : array();
			$batch_size = count( $rows );

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];

				yield self::to_embedding( $row );
			}
		} while ( self::BATCH === $batch_size );
	}

	/**
	 * Iterates over the text of all chunks, title included, without loading them at once.
	 *
	 * @return Generator<int, string>
	 */
	public function texts(): Generator {
		$last_id = 0;

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT id, title, content FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
					$this->table,
					$last_id,
					self::BATCH
				),
				ARRAY_A
			);

			$rows       = $rows ? $rows : array();
			$batch_size = count( $rows );

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];

				yield $row['title'] . ' ' . $row['content'];
			}
		} while ( self::BATCH === $batch_size );
	}

	/**
	 * Returns the whole indexed text of posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, string> Title and all passages of each post, keyed by post ID. Posts without passages are missing.
	 */
	public function post_texts( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( array() === $post_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT post_id, title, content FROM %i WHERE post_id IN ({$placeholders}) ORDER BY post_id ASC, chunk_index ASC",
				array_merge( array( $this->table ), $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$texts = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$post_id = (int) $row['post_id'];

			// Every passage carries the title; it is needed once.
			$texts[ $post_id ] = ( $texts[ $post_id ] ?? (string) $row['title'] ) . "\n" . $row['content'];
		}

		return $texts;
	}

	/**
	 * Returns the first passage of each of the given posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, int> Chunk IDs keyed by post ID. Posts without passages are missing.
	 */
	public function first_passages( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( array() === $post_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT post_id, MIN(id) AS id FROM %i WHERE chunk_index = 0 AND post_id IN ({$placeholders}) GROUP BY post_id",
				array_merge( array( $this->table ), $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$passages = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$passages[ (int) $row['post_id'] ] = (int) $row['id'];
		}

		return $passages;
	}

	/**
	 * Loads chunk texts by ID.
	 *
	 * @param int[] $chunk_ids Chunk IDs.
	 * @return array<int, array{post_id: int, title: string, content: string}> Keyed by chunk ID.
	 */
	public function find( array $chunk_ids ): array {
		$chunk_ids = array_values( array_unique( array_map( 'intval', $chunk_ids ) ) );

		if ( array() === $chunk_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $chunk_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The interpolated string only contains placeholders.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, post_id, title, content FROM %i WHERE id IN ({$placeholders})",
				array_merge( array( $this->table ), $chunk_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$chunks = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$chunks[ (int) $row['id'] ] = array(
				'post_id' => (int) $row['post_id'],
				'title'   => (string) $row['title'],
				'content' => (string) $row['content'],
			);
		}

		return $chunks;
	}

	/**
	 * Finds indexed posts whose title contains the given text.
	 *
	 * Titles starting with the text are listed first.
	 *
	 * @return int[] Post IDs.
	 */
	public function suggest( string $text, int $limit ): array {
		$escaped = $this->wpdb->esc_like( $text );

		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT post_id FROM %i
				WHERE chunk_index = 0 AND title LIKE %s
				ORDER BY (title LIKE %s) DESC, title ASC LIMIT %d',
				$this->table,
				'%' . $escaped . '%',
				$escaped . '%',
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Builds the hash that identifies the text a chunk embedding was made from.
	 */
	public static function hash( string $title, string $content ): string {
		return md5( $title . "\n" . $content );
	}

	/**
	 * Returns the content hashes a post is stored with, in reading order.
	 *
	 * @param string $post_type Only chunks stored under this post type count as the same.
	 * @return string[]
	 */
	private function stored_hashes( int $post_id, string $post_type ): array {
		return $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT content_hash FROM %i WHERE post_id = %d AND post_type = %s ORDER BY chunk_index ASC',
				$this->table,
				$post_id,
				$post_type
			)
		);
	}

	/**
	 * Returns the stored embeddings of a post keyed by content hash.
	 *
	 * @return array<string, array{embedding: string, embedding_model: string}>
	 */
	private function embeddings_by_hash( int $post_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT content_hash, embedding, embedding_model FROM %i WHERE post_id = %d AND embedding IS NOT NULL',
				$this->table,
				$post_id
			),
			ARRAY_A
		);

		$by_hash = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$by_hash[ (string) $row['content_hash'] ] = array(
				'embedding'       => (string) $row['embedding'],
				'embedding_model' => (string) $row['embedding_model'],
			);
		}

		return $by_hash;
	}

	/**
	 * Casts a raw embedding row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array{id: int, post_id: int, embedding: string, length: int}
	 */
	private static function to_embedding( array $row ): array {
		return array(
			'id'        => (int) $row['id'],
			'post_id'   => (int) $row['post_id'],
			'embedding' => (string) $row['embedding'],
			'length'    => (int) $row['length'],
		);
	}

	/**
	 * Returns the values of the signature columns for an embedding.
	 *
	 * @param string|null $embedding Packed vector, or null if the passage has none.
	 * @return array<string, string> Unsigned integers as strings, keyed by column.
	 */
	private static function signature_values( ?string $embedding ): array {
		$signature = Vector::signature( null === $embedding ? array() : Vector::unpack( $embedding ) );
		$values    = array();

		foreach ( $signature as $word => $bits ) {
			// The columns are unsigned. PHP has no such type, so the bits travel as digits.
			$values[ 'sig_' . $word ] = sprintf( '%u', $bits );
		}

		return $values;
	}

	/**
	 * Casts raw match rows.
	 *
	 * @param array<int, array<string, mixed>>|null $rows Database rows.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	private static function to_matches( ?array $rows ): array {
		return array_map(
			static fn ( array $row ): array => array(
				'id'      => (int) $row['id'],
				'post_id' => (int) $row['post_id'],
				'title'   => (string) $row['title'],
				'content' => (string) $row['content'],
				'score'   => (float) $row['score'],
			),
			$rows ? $rows : array()
		);
	}
}
