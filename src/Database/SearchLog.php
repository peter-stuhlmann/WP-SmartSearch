<?php
/**
 * Storage of logged searches.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Database;

/**
 * What the rest of the plugin needs from the search log table.
 */
interface SearchLog {

	/**
	 * Stores a search.
	 *
	 * Costs are stored as they were at the time of the search, so later price
	 * changes do not rewrite history.
	 *
	 * @param array{query: string, mode: string, results: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float, duration_ms: int|null} $entry Log entry.
	 * @return int ID of the entry, 0 on failure.
	 */
	public function insert( array $entry ): int;

	/**
	 * Adds the tokens and cost of a generated answer to a logged search.
	 */
	public function add_answer_usage( int $id, int $input_tokens, int $output_tokens, float $input_cost, float $output_cost ): void;

	/**
	 * Stores which result was clicked. Only the first click counts.
	 */
	public function set_clicked_post( int $id, int $post_id ): void;

	/**
	 * Deletes entries older than the given UTC date-time.
	 */
	public function delete_before( string $utc_datetime ): void;

	/**
	 * Deletes all entries.
	 */
	public function truncate(): void;

	/**
	 * Returns the key figures of a period.
	 *
	 * @return array{searches: int, without_results: int, clicked: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float, avg_duration_ms: float}
	 */
	public function totals( string $from, string $to ): array;

	/**
	 * Returns per-hour figures of a period.
	 *
	 * Hours, not days: which day a search belongs to depends on the site's
	 * time zone and on daylight saving time, and only PHP knows both. The
	 * caller sorts the hours into local days.
	 *
	 * @return array<string, array{searches: int, input_cost: float, output_cost: float}> Keyed by UTC hour ("Y-m-d H"). Hours without searches are missing.
	 */
	public function hourly( string $from, string $to ): array;

	/**
	 * Returns the most frequent queries of a period.
	 *
	 * @param bool $only_without_results Limit to queries that found nothing.
	 * @return array<int, array{query: string, searches: int, results: int}>
	 */
	public function top_queries( string $from, string $to, int $limit, bool $only_without_results = false ): array;

	/**
	 * Returns logged searches of a period, newest first.
	 *
	 * @param array{search?: string, mode?: string} $filter Optional filter: text contained in the query, and search mode.
	 * @return array<int, array{searched_at: string, query: string, mode: string, results: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float}>
	 */
	public function entries( string $from, string $to, int $limit, int $offset = 0, array $filter = array() ): array;

	/**
	 * Counts the logged searches of a period that match a filter.
	 *
	 * @param array{search?: string, mode?: string} $filter See entries().
	 */
	public function count_entries( string $from, string $to, array $filter = array() ): int;
}
