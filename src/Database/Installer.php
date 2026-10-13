<?php
/**
 * Creates and upgrades the plugin tables.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Database;

use PrstSearch\Search\Vector;
use wpdb;

/**
 * Owns the database schema.
 */
final class Installer {

	public const VERSION_OPTION = 'prstsearch_db_version';

	/**
	 * Version 2 split the cost of a search into input and output. Version 3 stores both with ten
	 * decimals, because the embedding of a search phrase costs less than a millionth of a dollar,
	 * and removes the single cost column of version 1. Version 4 searches word stems instead
	 * of the text itself and stores a signature of every embedding, see IndexTerms and Vector.
	 */
	private const SCHEMA_VERSION = '4';

	/**
	 * Creates the installer.
	 *
	 * @param wpdb   $wpdb           Database connection. Its prefix decides which tables are meant.
	 * @param string $version_option Option the schema version of those tables is noted in.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly string $version_option = self::VERSION_OPTION
	) {}

	/**
	 * Returns the name of the index table.
	 */
	public function chunks_table(): string {
		return $this->wpdb->prefix . 'prstsearch_chunks';
	}

	/**
	 * Returns the name of the search log table.
	 */
	public function log_table(): string {
		return $this->wpdb->prefix . 'prstsearch_log';
	}

	/**
	 * Tells whether the tables have to be created or upgraded.
	 */
	public function is_outdated(): bool {
		return self::SCHEMA_VERSION !== get_option( $this->version_option );
	}

	/**
	 * Creates or upgrades the tables.
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $this->wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$this->chunks_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
				post_type varchar(20) NOT NULL DEFAULT '',
				title text NOT NULL,
				content longtext NOT NULL,
				content_hash char(32) NOT NULL DEFAULT '',
				terms longtext NOT NULL,
				embedding longblob NULL,
				embedding_model varchar(100) NOT NULL DEFAULT '',
				{$this->signature_columns()},
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY first_chunks (chunk_index,post_id),
				KEY signature (embedding_model,{$this->signature_names()},post_id),
				FULLTEXT KEY search_terms (terms)
			) ENGINE=InnoDB {$collate};"
		);

		$this->drop_index( $this->chunks_table(), 'search_text' );
		$this->drop_index( $this->chunks_table(), 'embedding_model' );

		dbDelta(
			"CREATE TABLE {$this->log_table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				searched_at datetime NOT NULL,
				query varchar(191) NOT NULL DEFAULT '',
				mode varchar(20) NOT NULL DEFAULT '',
				results int(10) unsigned NOT NULL DEFAULT 0,
				embedding_tokens int(10) unsigned NOT NULL DEFAULT 0,
				input_tokens int(10) unsigned NOT NULL DEFAULT 0,
				output_tokens int(10) unsigned NOT NULL DEFAULT 0,
				input_cost decimal(16,10) NOT NULL DEFAULT 0,
				output_cost decimal(16,10) NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NULL,
				clicked_post_id bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				KEY searched_at (searched_at)
			) {$collate};"
		);

		$this->remove_total_cost_column();

		update_option( $this->version_option, self::SCHEMA_VERSION );
	}

	/**
	 * Returns the definitions of the columns that hold the signature of an embedding.
	 *
	 * Without a line break at the end: dbDelta() reads the definition line by line
	 * and takes an empty line for a broken index.
	 */
	private function signature_columns(): string {
		$columns = array();

		for ( $word = 0; $word < Vector::SIGNATURE_WORDS; $word++ ) {
			$columns[] = "sig_{$word} bigint(20) unsigned NOT NULL DEFAULT 0";
		}

		return implode( ",\n", $columns );
	}

	/**
	 * Returns the names of the signature columns, separated by commas.
	 */
	private function signature_names(): string {
		return implode( ',', array_map( static fn ( int $word ): string => 'sig_' . $word, range( 0, Vector::SIGNATURE_WORDS - 1 ) ) );
	}

	/**
	 * Removes an index earlier versions used. dbDelta() adds indexes but never removes one.
	 */
	private function drop_index( string $table, string $index ): void {
		$exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );

		if ( null !== $exists ) {
			$this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index ) );
		}
	}

	/**
	 * Removes the column of version 1, which stored one cost per search.
	 *
	 * Needed because dbDelta() adds columns but never removes one. The old total
	 * cannot be split into input and output any more; it is kept as input cost,
	 * so the sums of the statistics stay what they were.
	 */
	private function remove_total_cost_column(): void {
		$column = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->log_table(), 'cost' ) );

		if ( null === $column ) {
			return;
		}

		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE %i SET input_cost = cost WHERE input_cost = 0 AND output_cost = 0', $this->log_table() )
		);
		$this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i DROP COLUMN cost', $this->log_table() ) );
	}

	/**
	 * Removes both tables. Used on uninstall.
	 */
	public function drop(): void {
		$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->chunks_table() ) );
		$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->log_table() ) );
		delete_option( $this->version_option );
	}
}
