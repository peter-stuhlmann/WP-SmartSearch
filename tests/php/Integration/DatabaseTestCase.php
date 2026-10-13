<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrstSearch\Database\Installer;
use wpdb;

/**
 * Base for tests that run SQL.
 *
 * Every test gets freshly created tables under a prefix of its own, so the
 * real tables of the plugin are never read or written.
 */
abstract class DatabaseTestCase extends TestCase {

	private const PREFIX = 'prstsearch_test_';

	protected wpdb $wpdb;

	protected Installer $installer;

	protected function setUp(): void {
		parent::setUp();

		if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof wpdb ) {
			self::markTestSkipped( 'Needs a WordPress installation: set WP_LOAD_PATH to its wp-load.php.' );
		}

		$this->wpdb      = self::connection();
		$this->installer = new Installer( $this->wpdb );

		$this->drop_tables();
		$this->installer->install();
	}

	protected function tearDown(): void {
		if ( isset( $this->installer ) ) {
			$this->drop_tables();
		}

		parent::tearDown();
	}

	/**
	 * Returns a connection of its own to the database of the site, with a prefix that names other tables.
	 */
	private static function connection(): wpdb {
		static $connection = null;

		if ( null === $connection ) {
			$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$connection->set_prefix( $GLOBALS['wpdb']->prefix . self::PREFIX );
		}

		return $connection;
	}

	/**
	 * Not Installer::drop(): that also forgets the schema version of the real tables.
	 */
	private function drop_tables(): void {
		foreach ( array( $this->installer->chunks_table(), $this->installer->log_table() ) as $table ) {
			$this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
