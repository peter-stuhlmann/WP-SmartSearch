<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

/**
 * Creating and upgrading the tables.
 */
final class InstallerTest extends DatabaseTestCase {

	public function test_installing_twice_changes_nothing(): void {
		$columns = $this->columns();

		$this->installer->install();

		self::assertSame( $columns, $this->columns() );
		self::assertContains( 'input_cost', $columns );
		self::assertContains( 'output_cost', $columns );
	}

	public function test_the_total_cost_of_the_first_schema_is_carried_over_and_its_column_removed(): void {
		$table = $this->installer->log_table();

		// A table as schema 1 left it: one cost per search.
		$this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ADD COLUMN cost decimal(12,6) NOT NULL DEFAULT 0', $table ) );
		$this->wpdb->query( $this->wpdb->prepare( 'INSERT INTO %i (searched_at, query, cost) VALUES (%s, %s, %f)', $table, '2026-10-01 10:00:00', 'old search', 0.0123 ) );
		$this->wpdb->query( $this->wpdb->prepare( 'INSERT INTO %i (searched_at, query, cost, input_cost, output_cost) VALUES (%s, %s, %f, %f, %f)', $table, '2026-10-02 10:00:00', 'new search', 0.03, 0.01, 0.02 ) );

		$this->installer->install();

		self::assertNotContains( 'cost', $this->columns() );
		self::assertSame(
			array(
				array(
					'query'       => 'old search',
					'input_cost'  => '0.0123000000',
					'output_cost' => '0.0000000000',
				),
				array(
					'query'       => 'new search',
					'input_cost'  => '0.0100000000',
					'output_cost' => '0.0200000000',
				),
			),
			$this->wpdb->get_results( $this->wpdb->prepare( 'SELECT query, input_cost, output_cost FROM %i ORDER BY id', $table ), ARRAY_A )
		);
	}

	public function test_the_index_of_an_earlier_version_is_replaced(): void {
		$table = $this->installer->chunks_table();

		$this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY search_text (title, content)', $table ) );

		$this->installer->install();

		$indexes = array_unique( $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 ) );

		self::assertNotContains( 'search_text', $indexes );
		self::assertContains( 'search_terms', $indexes );
		self::assertContains( 'signature', $indexes );
	}

	/**
	 * @return string[]
	 */
	private function columns(): array {
		return $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->installer->log_table() ) );
	}
}
