<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Generator;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Search\IndexTerms;
use PrstSearch\Search\Vector;

/**
 * Test double: an index held in memory.
 *
 * It finds passages by the words they contain, without ranking them the way
 * a database does. Tests that depend on an order set the rows themselves.
 */
final class FakeChunkStore implements ChunkStore {

	/**
	 * The passages of the index. "embedding" is optional.
	 *
	 * @var array<int, array{id: int, post_id: int, title: string, content: string, embedding?: float[]}>
	 */
	public array $passages = array();

	/**
	 * If set, what fulltext_search() returns whatever the query.
	 *
	 * @var array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	public array $fulltext_rows = array();

	/**
	 * If set, what like_search() returns whatever the terms.
	 *
	 * @var array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	public array $like_rows = array();

	/**
	 * Names of the search methods that were called, in order.
	 *
	 * @var string[]
	 */
	public array $calls = array();

	public function replace_post_chunks( int $post_id, string $post_type, string $title, array $chunks ): bool {
		return true;
	}

	public function delete_post( int $post_id ): bool {
		return false;
	}

	public function post_ids_after( int $after_id, int $limit ): array {
		return array();
	}

	public function has_chunks( int $post_id ): bool {
		return false;
	}

	public function delete_other_post_types( array $post_types ): void {}

	public function posts_missing_embeddings( string $embedding_model ): array {
		return array();
	}

	public function truncate(): void {}

	public function counts( string $embedding_model ): array {
		$embedded = array_filter( $this->passages, static fn ( array $passage ): bool => isset( $passage['embedding'] ) );

		return array(
			'chunks'  => count( $this->passages ),
			'posts'   => count( array_unique( array_column( $this->passages, 'post_id' ) ) ),
			'missing' => count( $this->passages ) - count( $embedded ),
		);
	}

	public function posts_by_type(): array {
		return array();
	}

	public function missing_embeddings( string $embedding_model, int $limit, int $post_id = 0 ): array {
		return array();
	}

	public function has_missing_embeddings( int $post_id, string $embedding_model ): bool {
		return false;
	}

	public function save_embedding( int $chunk_id, string $embedding, string $embedding_model ): void {}

	public function fulltext_search( string $boolean_query, int $limit ): array {
		$this->calls[] = 'fulltext';

		if ( array() !== $this->fulltext_rows ) {
			return $this->fulltext_rows;
		}

		// The way the database does it: a passage is found by the beginning of one of its stored terms.
		$wanted = array_map( static fn ( string $part ): string => rtrim( $part, '*' ), explode( ' ', $boolean_query ) );
		$rows   = array();

		foreach ( $this->passages as $passage ) {
			$stored = explode( ' ', IndexTerms::of( $passage['title'], $passage['content'] ) );
			$score  = 0;

			foreach ( $wanted as $beginning ) {
				$score += array() === array_filter( $stored, static fn ( string $term ): bool => str_starts_with( $term, $beginning ) ) ? 0 : 1;
			}

			if ( $score > 0 ) {
				$rows[] = self::row( $passage, (float) $score );
			}
		}

		usort( $rows, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $rows;
	}

	public function like_search( array $terms, int $limit ): array {
		$this->calls[] = 'like';

		return array() !== $this->like_rows ? $this->like_rows : $this->containing( $terms );
	}

	public function embeddings( string $embedding_model, int $step = 1 ): Generator {
		foreach ( $this->passages as $passage ) {
			if ( isset( $passage['embedding'] ) && 0 === $passage['id'] % $step ) {
				yield self::embedding( $passage );
			}
		}
	}

	public function embeddings_of( array $chunk_ids ): array {
		$this->calls[] = 'embeddings_of';

		$rows = array();

		foreach ( $this->passages as $passage ) {
			if ( isset( $passage['embedding'] ) && in_array( $passage['id'], $chunk_ids, true ) ) {
				$rows[] = self::embedding( $passage );
			}
		}

		return $rows;
	}

	public function nearest( string $embedding_model, array $signature, int $limit ): array {
		$this->calls[] = 'nearest';

		$distances = array();

		foreach ( $this->passages as $passage ) {
			if ( ! isset( $passage['embedding'] ) ) {
				continue;
			}

			$distance = 0;

			foreach ( Vector::signature( $passage['embedding'] ) as $word => $bits ) {
				$distance += substr_count( decbin( $bits ^ $signature[ $word ] ), '1' );
			}

			$distances[ $passage['id'] ] = $distance;
		}

		asort( $distances );

		return array_slice( array_keys( $distances ), 0, $limit );
	}

	public function complete_passages( int $limit ): int {
		return 0;
	}

	public function texts(): Generator {
		foreach ( $this->passages as $passage ) {
			yield $passage['title'];
			yield $passage['content'];
		}
	}

	public function find( array $chunk_ids ): array {
		$found = array();

		foreach ( $this->passages as $passage ) {
			if ( in_array( $passage['id'], $chunk_ids, true ) ) {
				$found[ $passage['id'] ] = array(
					'post_id' => $passage['post_id'],
					'title'   => $passage['title'],
					'content' => $passage['content'],
				);
			}
		}

		return $found;
	}

	public function post_texts( array $post_ids ): array {
		$texts = array();

		foreach ( $this->passages as $passage ) {
			if ( in_array( $passage['post_id'], $post_ids, true ) ) {
				$texts[ $passage['post_id'] ] = ( $texts[ $passage['post_id'] ] ?? $passage['title'] ) . "\n" . $passage['content'];
			}
		}

		return $texts;
	}

	public function first_passages( array $post_ids ): array {
		$passages = array();

		foreach ( $this->passages as $passage ) {
			if ( in_array( $passage['post_id'], $post_ids, true ) ) {
				$passages[ $passage['post_id'] ] ??= $passage['id'];
			}
		}

		return $passages;
	}

	public function suggest( string $text, int $limit ): array {
		return array();
	}

	/**
	 * Returns the passages that contain at least one of the words, those with more of them first.
	 *
	 * @param string[] $words Words to look for.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	private function containing( array $words ): array {
		$rows = array();

		foreach ( $this->passages as $passage ) {
			$text  = mb_strtolower( $passage['title'] . ' ' . $passage['content'] );
			$score = count( array_filter( $words, static fn ( string $word ): bool => str_contains( $text, mb_strtolower( $word ) ) ) );

			if ( $score > 0 ) {
				$rows[] = self::row( $passage, (float) $score );
			}
		}

		usort( $rows, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $rows;
	}

	/**
	 * @param array{id: int, post_id: int, title: string, content: string} $passage
	 * @return array{id: int, post_id: int, title: string, content: string, score: float}
	 */
	private static function row( array $passage, float $score ): array {
		return array(
			'id'      => $passage['id'],
			'post_id' => $passage['post_id'],
			'title'   => $passage['title'],
			'content' => $passage['content'],
			'score'   => $score,
		);
	}

	/**
	 * @param array{id: int, post_id: int, content: string, embedding: float[]} $passage
	 * @return array{id: int, post_id: int, embedding: string, length: int}
	 */
	private static function embedding( array $passage ): array {
		return array(
			'id'        => $passage['id'],
			'post_id'   => $passage['post_id'],
			'embedding' => Vector::pack( Vector::normalize( $passage['embedding'] ) ),
			'length'    => mb_strlen( $passage['content'] ),
		);
	}
}
