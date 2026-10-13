<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

use PrstSearch\Database\SearchLogRepository;

/**
 * The SQL of the search log and its statistics.
 */
final class SearchLogRepositoryTest extends DatabaseTestCase {

	private SearchLogRepository $log;

	private string $from;

	private string $to;

	protected function setUp(): void {
		parent::setUp();

		$this->log  = new SearchLogRepository( $this->wpdb, $this->installer->log_table() );
		$this->from = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$this->to   = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
	}

	public function test_the_totals_add_up_searches_tokens_and_costs(): void {
		$this->insert(
			'versand',
			3,
			array(
				'input_tokens' => 100,
				'input_cost'   => 0.000125,
				'output_cost'  => 0.0005,
			)
		);
		$this->insert(
			'kontakt',
			0,
			array(
				'input_tokens' => 50,
				'input_cost'   => 0.000001,
			)
		);

		// The embedding of a search phrase: a few tokens at two cents per million.
		$this->insert( 'huhn', 1, array( 'input_cost' => 0.00000006 ) );

		$totals = $this->log->totals( $this->from, $this->to );

		self::assertSame( 3, $totals['searches'] );
		self::assertSame( 1, $totals['without_results'] );
		self::assertSame( 150, $totals['input_tokens'] );
		self::assertEqualsWithDelta( 0.00012606, $totals['input_cost'], 0.000000001, 'Even the smallest costs are kept.' );
		self::assertEqualsWithDelta( 0.0005, $totals['output_cost'], 0.0000001 );
		self::assertEqualsWithDelta( 120.0, $totals['avg_duration_ms'], 0.001 );
	}

	public function test_a_period_without_searches_gives_zeros(): void {
		$totals = $this->log->totals( $this->from, $this->to );

		self::assertSame( 0, $totals['searches'] );
		self::assertSame( 0.0, $totals['input_cost'] );
		self::assertSame( array(), $this->log->hourly( $this->from, $this->to ) );
		self::assertSame( array(), $this->log->entries( $this->from, $this->to, 10 ) );
	}

	public function test_the_end_of_a_period_is_exclusive(): void {
		$this->insert( 'versand', 1 );

		self::assertSame( 0, $this->log->totals( $this->from, gmdate( 'Y-m-d H:i:s', time() - 60 ) )['searches'] );
	}

	public function test_the_answer_is_added_to_the_search_it_belongs_to(): void {
		$id = $this->insert(
			'versand',
			3,
			array(
				'input_tokens' => 10,
				'input_cost'   => 0.01,
			)
		);

		$this->log->add_answer_usage( $id, 200, 80, 0.02, 0.04 );

		$entry = $this->log->entries( $this->from, $this->to, 10 )[0];

		self::assertSame( 210, $entry['input_tokens'] );
		self::assertSame( 80, $entry['output_tokens'] );
		self::assertEqualsWithDelta( 0.03, $entry['input_cost'], 0.0000001 );
		self::assertEqualsWithDelta( 0.04, $entry['output_cost'], 0.0000001 );
	}

	public function test_only_the_first_click_counts(): void {
		$id = $this->insert( 'versand', 3 );

		$this->log->set_clicked_post( $id, 11 );
		$this->log->set_clicked_post( $id, 22 );

		self::assertSame( 1, $this->log->totals( $this->from, $this->to )['clicked'] );
		self::assertSame( '11', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT clicked_post_id FROM %i WHERE id = %d', $this->installer->log_table(), $id ) ) );
	}

	public function test_frequent_queries_are_counted_regardless_of_case(): void {
		$this->insert( 'Versand', 4 );
		$this->insert( 'versand', 2 );
		$this->insert( 'kontakt', 0 );

		self::assertSame(
			array(
				array(
					'query'    => 'versand',
					'searches' => 2,
					'results'  => 3,
				),
				array(
					'query'    => 'kontakt',
					'searches' => 1,
					'results'  => 0,
				),
			),
			$this->log->top_queries( $this->from, $this->to, 10 )
		);

		self::assertSame( array( 'kontakt' ), array_column( $this->log->top_queries( $this->from, $this->to, 10, true ), 'query' ) );
	}

	public function test_entries_can_be_filtered_and_counted(): void {
		$this->insert( 'versand nach österreich', 3, array( 'mode' => 'ai' ) );
		$this->insert( 'versandkosten', 1, array( 'mode' => 'keyword' ) );
		$this->insert( '100% baumwolle', 1, array( 'mode' => 'keyword' ) );

		self::assertSame( 3, $this->log->count_entries( $this->from, $this->to ) );
		self::assertSame( 2, $this->log->count_entries( $this->from, $this->to, array( 'search' => 'versand' ) ) );
		self::assertSame(
			1,
			$this->log->count_entries(
				$this->from,
				$this->to,
				array(
					'search' => 'versand',
					'mode'   => 'ai',
				)
			)
		);
		self::assertSame( 1, $this->log->count_entries( $this->from, $this->to, array( 'search' => '0%' ) ), 'The percent sign is text, not a wildcard.' );
		self::assertSame( 0, $this->log->count_entries( $this->from, $this->to, array( 'search' => 'v%d' ) ) );

		$page = $this->log->entries( $this->from, $this->to, 2, 1 );

		self::assertSame( array( 'versandkosten', 'versand nach österreich' ), array_column( $page, 'query' ), 'Newest first, without the newest.' );
	}

	public function test_searches_are_grouped_by_utc_hour(): void {
		$this->insert( 'versand', 1, array( 'input_cost' => 0.01 ) );
		$this->insert( 'kontakt', 1, array( 'output_cost' => 0.02 ) );

		$hours = $this->log->hourly( $this->from, $this->to );

		// Both were stored a moment ago; around a full hour they can fall into two hours.
		self::assertSame( 2, array_sum( array_column( $hours, 'searches' ) ) );
		self::assertEqualsWithDelta( 0.01, array_sum( array_column( $hours, 'input_cost' ) ), 0.0000001 );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}$/', (string) array_key_first( $hours ) );
	}

	public function test_old_entries_can_be_deleted(): void {
		$this->insert( 'versand', 1 );

		$this->log->delete_before( $this->from );
		self::assertSame( 1, $this->log->count_entries( $this->from, $this->to ) );

		$this->log->delete_before( $this->to );
		self::assertSame( 0, $this->log->count_entries( $this->from, $this->to ) );
	}

	public function test_a_query_longer_than_the_column_is_cut_not_rejected(): void {
		self::assertGreaterThan( 0, $this->insert( str_repeat( 'ä', 300 ), 0 ) );
		self::assertSame( 191, mb_strlen( $this->log->entries( $this->from, $this->to, 1 )[0]['query'] ) );
	}

	/**
	 * @param array<string, mixed> $values Values that differ from an ordinary keyword search.
	 */
	private function insert( string $query, int $results, array $values = array() ): int {
		return $this->log->insert(
			array_merge(
				array(
					'query'            => $query,
					'mode'             => 'keyword',
					'results'          => $results,
					'embedding_tokens' => 0,
					'input_tokens'     => 0,
					'output_tokens'    => 0,
					'input_cost'       => 0.0,
					'output_cost'      => 0.0,
					'duration_ms'      => 120,
				),
				$values
			)
		);
	}
}
