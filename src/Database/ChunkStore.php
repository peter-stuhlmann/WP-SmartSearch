<?php
/**
 * Storage of the search index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Database;

use Generator;
use PrstSearch\Search\IndexTerms;
use PrstSearch\Search\Vector;

/**
 * What the rest of the plugin needs from the index table. Having it as an interface
 * lets the search and index logic be tested without a database.
 */
interface ChunkStore {

	/**
	 * Replaces all chunks of a post.
	 *
	 * Embeddings of chunks whose text did not change are carried over, so an
	 * edit only costs tokens for the paragraphs that were actually touched.
	 *
	 * @param string[] $chunks Chunk texts in reading order.
	 * @return bool Whether the stored text changed. False if the post was already stored exactly like this.
	 */
	public function replace_post_chunks( int $post_id, string $post_type, string $title, array $chunks ): bool;

	/**
	 * Removes all chunks of a post.
	 *
	 * @return bool Whether the post had chunks.
	 */
	public function delete_post( int $post_id ): bool;

	/**
	 * Returns the IDs of indexed posts in ascending order, for walking the whole index in steps.
	 *
	 * @param int $after_id Return posts with a higher ID than this.
	 * @return int[]
	 */
	public function post_ids_after( int $after_id, int $limit ): array;

	/**
	 * Tells whether a post has any chunk in the index.
	 */
	public function has_chunks( int $post_id ): bool;

	/**
	 * Removes the chunks of all post types except the given ones.
	 *
	 * @param string[] $post_types Post types to keep.
	 */
	public function delete_other_post_types( array $post_types ): void;

	/**
	 * Returns the posts that have chunks without an embedding for the model.
	 *
	 * @return int[] Post IDs.
	 */
	public function posts_missing_embeddings( string $embedding_model ): array;

	/**
	 * Empties the index.
	 */
	public function truncate(): void;

	/**
	 * Returns index totals.
	 *
	 * @return array{chunks: int, posts: int, missing: int}
	 */
	public function counts( string $embedding_model ): array;

	/**
	 * Returns the number of indexed posts per post type, largest first.
	 *
	 * @return array<string, int>
	 */
	public function posts_by_type(): array;

	/**
	 * Returns chunks that have no embedding for the given model yet.
	 *
	 * @param int $post_id Limit to the chunks of one post, or 0 for the oldest chunks of all posts.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string}>
	 */
	public function missing_embeddings( string $embedding_model, int $limit, int $post_id = 0 ): array;

	/**
	 * Tells whether a post still has chunks without an embedding for the model.
	 */
	public function has_missing_embeddings( int $post_id, string $embedding_model ): bool;

	/**
	 * Stores the embedding of one chunk.
	 *
	 * @param string $embedding Packed vector, see Vector::pack().
	 */
	public function save_embedding( int $chunk_id, string $embedding, string $embedding_model ): void;

	/**
	 * Fills in what newer versions of the plugin store for a passage, for passages stored by an older one.
	 *
	 * @param int $limit Number of passages to work on.
	 * @return int Number of passages completed. Fewer than $limit means none are left.
	 */
	public function complete_passages( int $limit ): int;

	/**
	 * Returns the passages whose embedding is most like the given signature.
	 *
	 * A rough first selection, see Vector::signature(). The database compares
	 * every passage, but only reads an index to do so.
	 *
	 * @param int[] $signature Signature of the vector to compare with.
	 * @return int[] Chunk IDs, most alike first.
	 */
	public function nearest( string $embedding_model, array $signature, int $limit ): array;

	/**
	 * Loads the embeddings of the given passages.
	 *
	 * @param int[] $chunk_ids Chunk IDs.
	 * @return array<int, array{id: int, post_id: int, embedding: string, length: int}> In no particular order.
	 */
	public function embeddings_of( array $chunk_ids ): array;

	/**
	 * Runs a FULLTEXT query in boolean mode on the stored terms of the passages, see IndexTerms.
	 *
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}> Best matches first.
	 */
	public function fulltext_search( string $boolean_query, int $limit ): array;

	/**
	 * Finds chunks containing every term as a substring.
	 *
	 * Fallback for terms FULLTEXT cannot index, such as two-letter words.
	 *
	 * @param string[] $terms Search terms.
	 * @return array<int, array{id: int, post_id: int, title: string, content: string, score: float}>
	 */
	public function like_search( array $terms, int $limit ): array;

	/**
	 * Iterates over the stored embeddings of a model without loading them at once.
	 *
	 * @param int $step Return every n-th embedding only, for an even sample of a large index.
	 * @return Generator<int, array{id: int, post_id: int, embedding: string, length: int}>
	 */
	public function embeddings( string $embedding_model, int $step = 1 ): Generator;

	/**
	 * Iterates over the text of all chunks, title included, without loading them at once.
	 *
	 * @return Generator<int, string>
	 */
	public function texts(): Generator;

	/**
	 * Returns the whole indexed text of posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, string> Title and all passages of each post, keyed by post ID. Posts without passages are missing.
	 */
	public function post_texts( array $post_ids ): array;

	/**
	 * Returns the first passage of each of the given posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, int> Chunk IDs keyed by post ID. Posts without passages are missing.
	 */
	public function first_passages( array $post_ids ): array;

	/**
	 * Loads chunk texts by ID.
	 *
	 * @param int[] $chunk_ids Chunk IDs.
	 * @return array<int, array{post_id: int, title: string, content: string}> Keyed by chunk ID.
	 */
	public function find( array $chunk_ids ): array;

	/**
	 * Finds indexed posts whose title contains the given text.
	 *
	 * Titles starting with the text are listed first.
	 *
	 * @return int[] Post IDs.
	 */
	public function suggest( string $text, int $limit ): array;
}
