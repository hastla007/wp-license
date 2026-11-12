<?php
use WPLC\Infrastructure\Database\Schema;

final class SchemaMigrationIntegrationTest extends WP_UnitTestCase {
	public function test_schema_migration_creates_every_table_and_is_repeatable(): void {
		global $wpdb;

		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( Schema::VERSION, (string) get_option( 'wplc_schema_version' ) );

		$existing_tables = $wpdb->get_col( 'SHOW TABLES' );
		foreach ( Schema::table_names() as $table_name ) {
			$this->assertContains( $table_name, $existing_tables, 'Missing WPLC schema table: ' . $table_name );
		}
	}
}
