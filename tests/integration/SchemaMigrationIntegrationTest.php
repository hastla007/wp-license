<?php
use WPLC\Infrastructure\Database\Schema;

final class SchemaMigrationIntegrationTest extends WP_UnitTestCase {
	public function test_schema_migration_creates_every_table_and_is_repeatable(): void {
		global $wpdb;

		$migrated = Schema::migrate();
		$missing = array();
		foreach ( Schema::table_names() as $table_name ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table_name
				)
			);
			if ( 1 !== (int) $count ) {
				$missing[] = $table_name . ' (' . (string) $count . ')';
			}
		}
		$this->assertTrue( $migrated, 'Schema migration failed; missing: ' . implode( ', ', $missing ) );
		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( Schema::VERSION, (string) get_option( 'wplc_schema_version' ) );

		foreach ( Schema::table_names() as $table_name ) {
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table_name
				)
			);
			$this->assertSame( '1', (string) $table_exists, 'Missing WPLC schema table: ' . $table_name );
		}
	}
}
