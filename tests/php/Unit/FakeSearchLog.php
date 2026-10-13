<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Database\SearchLog;

/**
 * Test double: remembers what was logged.
 */
final class FakeSearchLog implements SearchLog {

	/**
	 * Logged searches, keyed by their ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $entries = array();

	public function insert( array $entry ): int {
		$id = count( $this->entries ) + 1;

		$this->entries[ $id ] = $entry;

		return $id;
	}

	public function add_answer_usage( int $id, int $input_tokens, int $output_tokens, float $input_cost, float $output_cost ): void {
		$this->entries[ $id ]['input_tokens']  += $input_tokens;
		$this->entries[ $id ]['output_tokens'] += $output_tokens;
	}

	public function set_clicked_post( int $id, int $post_id ): void {}

	public function delete_before( string $utc_datetime ): void {}

	public function truncate(): void {
		$this->entries = array();
	}

	public function totals( string $from, string $to ): array {
		return array();
	}

	public function hourly( string $from, string $to ): array {
		return array();
	}

	public function top_queries( string $from, string $to, int $limit, bool $only_without_results = false ): array {
		return array();
	}

	public function entries( string $from, string $to, int $limit, int $offset = 0, array $filter = array() ): array {
		return array();
	}

	public function count_entries( string $from, string $to, array $filter = array() ): int {
		return count( $this->entries );
	}
}
