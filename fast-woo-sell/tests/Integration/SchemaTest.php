<?php
/**
 * Step 4 acceptance: every table exists after install, and installing twice is
 * harmless (idempotent migrator).
 *
 * @package FWS
 */

namespace FWS\Tests\Integration;

use FWS\Install\Installer;
use FWS\Install\Schema;
use WP_UnitTestCase;

/**
 * @covers \FWS\Install\Schema
 * @covers \FWS\Install\Installer
 */
final class SchemaTest extends WP_UnitTestCase {

	public function test_all_tables_exist_after_install(): void {
		global $wpdb;

		foreach ( Schema::names() as $name ) {
			$table = $wpdb->prefix . $name;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( $table, $found, "Table {$table} should exist after activation." );
		}
	}

	public function test_install_is_idempotent(): void {
		Installer::install_site();
		Installer::install_site();

		$this->assertSame( array(), Schema::missing(), 'Second install must not report missing tables.' );
	}

	public function test_schema_has_thirteen_tables(): void {
		$this->assertCount( 13, Schema::names() );
	}
}
