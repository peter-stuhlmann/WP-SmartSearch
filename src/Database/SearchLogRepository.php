<?php
/**
 * Persistence for the search log.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Database;

use wpdb;

/**
 * Reads and writes logged searches. All SQL for the log table lives here.
 *
 * Times are stored in UTC. Range parameters are UTC date-times in MySQL
 * format, with an inclusive start and an exclusive end.
 */
final class SearchLogRepository implements SearchLog {

	/**
	 * Decimals of the cost columns, see Installer.
	 */
	private const COST_DECIMALS = 10;

	/**
	 * Creates the repository.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly string $table
	) {}

	/**
	 * Stores a search.
	 *
	 * Costs are stored as they were at the time of the search, so later price
	 * changes do not rewrite history.
	 *
	 * @param array{query: string, mode: string, results: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float, duration_ms: int|null} $entry Log entry.
	 * @return int ID of the entry, 0 on failure.
	 */
	public function insert( array $entry ): int {
		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'searched_at'      => gmdate( 'Y-m-d H:i:s' ),
				'query'            => mb_substr( $entry['query'], 0, 191 ),
				'mode'             => $entry['mode'],
				'results'          => $entry['results'],
				'embedding_tokens' => $entry['embedding_tokens'],
				'input_tokens'     => $entry['input_tokens'],
				'output_tokens'    => $entry['output_tokens'],
				'input_cost'       => self::decimal( $entry['input_cost'] ),
				'output_cost'      => self::decimal( $entry['output_cost'] ),
				'duration_ms'      => $entry['duration_ms'],
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Adds the tokens and cost of a generated answer to a logged search.
	 */
	public function add_answer_usage( int $id, int $input_tokens, int $output_tokens, float $input_cost, float $output_cost ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET input_tokens = input_tokens + %d, output_tokens = output_tokens + %d,
					input_cost = input_cost + %s, output_cost = output_cost + %s
				WHERE id = %d',
				$this->table,
				$input_tokens,
				$output_tokens,
				self::decimal( $input_cost ),
				self::decimal( $output_cost ),
				$id
			)
		);
	}

	/**
	 * Stores which result was clicked. Only the first click counts.
	 */
	public function set_clicked_post( int $id, int $post_id ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET clicked_post_id = %d WHERE id = %d AND clicked_post_id IS NULL',
				$this->table,
				$post_id,
				$id
			)
		);
	}

	/**
	 * Deletes entries older than the given UTC date-time.
	 */
	public function delete_before( string $utc_datetime ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE searched_at < %s', $this->table, $utc_datetime )
		);
	}

	/**
	 * Deletes all entries.
	 */
	public function truncate(): void {
		$this->wpdb->query( $this->wpdb->prepare( 'TRUNCATE TABLE %i', $this->table ) );
	}

	/**
	 * Returns the key figures of a period.
	 *
	 * @return array{searches: int, without_results: int, clicked: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float, avg_duration_ms: float}
	 */
	public function totals( string $from, string $to ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT COUNT(*) AS searches,
					COALESCE(SUM(results = 0), 0) AS without_results,
					COUNT(clicked_post_id) AS clicked,
					COALESCE(SUM(embedding_tokens), 0) AS embedding_tokens,
					COALESCE(SUM(input_tokens), 0) AS input_tokens,
					COALESCE(SUM(output_tokens), 0) AS output_tokens,
					COALESCE(SUM(input_cost), 0) AS input_cost,
					COALESCE(SUM(output_cost), 0) AS output_cost,
					COALESCE(AVG(duration_ms), 0) AS avg_duration_ms
				FROM %i WHERE searched_at >= %s AND searched_at < %s',
				$this->table,
				$from,
				$to
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'searches'         => (int) ( $row['searches'] ?? 0 ),
			'without_results'  => (int) ( $row['without_results'] ?? 0 ),
			'clicked'          => (int) ( $row['clicked'] ?? 0 ),
			'embedding_tokens' => (int) ( $row['embedding_tokens'] ?? 0 ),
			'input_tokens'     => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens'    => (int) ( $row['output_tokens'] ?? 0 ),
			'input_cost'       => (float) ( $row['input_cost'] ?? 0 ),
			'output_cost'      => (float) ( $row['output_cost'] ?? 0 ),
			'avg_duration_ms'  => (float) ( $row['avg_duration_ms'] ?? 0 ),
		);
	}

	/**
	 * Returns per-hour figures of a period.
	 *
	 * Hours, not days: which day a search belongs to depends on the site's
	 * time zone and on daylight saving time, and only PHP knows both. The
	 * caller sorts the hours into local days.
	 *
	 * @return array<string, array{searches: int, input_cost: float, output_cost: float}> Keyed by UTC hour ("Y-m-d H"). Hours without searches are missing.
	 */
	public function hourly( string $from, string $to ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT DATE_FORMAT(searched_at, %s) AS hour,
					COUNT(*) AS searches,
					SUM(input_cost) AS input_cost,
					SUM(output_cost) AS output_cost
				FROM %i WHERE searched_at >= %s AND searched_at < %s
				GROUP BY hour ORDER BY hour ASC',
				'%Y-%m-%d %H',
				$this->table,
				$from,
				$to
			),
			ARRAY_A
		);

		$hours = array();

		foreach ( $rows ? $rows : array() as $row ) {
			$hours[ (string) $row['hour'] ] = array(
				'searches'    => (int) $row['searches'],
				'input_cost'  => (float) $row['input_cost'],
				'output_cost' => (float) $row['output_cost'],
			);
		}

		return $hours;
	}

	/**
	 * Returns the most frequent queries of a period.
	 *
	 * @param bool $only_without_results Limit to queries that found nothing.
	 * @return array<int, array{query: string, searches: int, results: int}>
	 */
	public function top_queries( string $from, string $to, int $limit, bool $only_without_results = false ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT LOWER(query) AS query, COUNT(*) AS searches, ROUND(AVG(results)) AS results
				FROM %i WHERE searched_at >= %s AND searched_at < %s AND (%d = 0 OR results = 0)
				GROUP BY LOWER(query) ORDER BY searches DESC, query ASC LIMIT %d',
				$this->table,
				$from,
				$to,
				(int) $only_without_results,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => array(
				'query'    => (string) $row['query'],
				'searches' => (int) $row['searches'],
				'results'  => (int) $row['results'],
			),
			$rows ? $rows : array()
		);
	}

	/**
	 * Returns logged searches of a period, newest first.
	 *
	 * @param array{search?: string, mode?: string} $filter Optional filter: text contained in the query, and search mode.
	 * @return array<int, array{searched_at: string, query: string, mode: string, results: int, embedding_tokens: int, input_tokens: int, output_tokens: int, input_cost: float, output_cost: float}>
	 */
	public function entries( string $from, string $to, int $limit, int $offset = 0, array $filter = array() ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT searched_at, query, mode, results, embedding_tokens, input_tokens, output_tokens, input_cost, output_cost FROM %i
				WHERE searched_at >= %s AND searched_at < %s AND query LIKE %s AND (%s = \'\' OR mode = %s)
				ORDER BY searched_at DESC, id DESC LIMIT %d OFFSET %d',
				$this->table,
				$from,
				$to,
				$this->contains( $filter['search'] ?? '' ),
				$filter['mode'] ?? '',
				$filter['mode'] ?? '',
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => array(
				'searched_at'      => (string) $row['searched_at'],
				'query'            => (string) $row['query'],
				'mode'             => (string) $row['mode'],
				'results'          => (int) $row['results'],
				'embedding_tokens' => (int) $row['embedding_tokens'],
				'input_tokens'     => (int) $row['input_tokens'],
				'output_tokens'    => (int) $row['output_tokens'],
				'input_cost'       => (float) $row['input_cost'],
				'output_cost'      => (float) $row['output_cost'],
			),
			$rows ? $rows : array()
		);
	}

	/**
	 * Counts the logged searches of a period that match a filter.
	 *
	 * @param array{search?: string, mode?: string} $filter See entries().
	 */
	public function count_entries( string $from, string $to, array $filter = array() ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE searched_at >= %s AND searched_at < %s AND query LIKE %s AND (%s = \'\' OR mode = %s)',
				$this->table,
				$from,
				$to,
				$this->contains( $filter['search'] ?? '' ),
				$filter['mode'] ?? '',
				$filter['mode'] ?? ''
			)
		);
	}

	/**
	 * Writes an amount the way the cost columns store it.
	 *
	 * The %f placeholder of wpdb keeps six decimals. A single search often costs less than that,
	 * and thousands of searches rounded to zero would add up to a wrong total.
	 */
	private static function decimal( float $amount ): string {
		return number_format( $amount, self::COST_DECIMALS, '.', '' );
	}

	/**
	 * Builds a LIKE pattern for "contains". An empty text matches everything.
	 */
	private function contains( string $text ): string {
		return '%' . $this->wpdb->esc_like( $text ) . '%';
	}
}
