<?php
use WPLC\Infrastructure\Database\Schema;

final class SchemaMigrationIntegrationTest extends WP_UnitTestCase {
	public function test_schema_migration_creates_every_table_and_is_repeatable(): void {
		global $wpdb;

		// The WordPress test case wraps each test in a transaction and rewrites
		// CREATE TABLE statements to CREATE TEMPORARY TABLE. Schema migrations
		// must be exercised with permanent tables so information_schema can verify them.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

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
		$diagnostic = 'prefix=' . $wpdb->prefix
			. '; database=' . (string) $wpdb->get_var( 'SELECT DATABASE()' )
			. '; last_error=' . $wpdb->last_error
			. '; last_query=' . $wpdb->last_query
			. '; dbDelta=' . ( function_exists( 'dbDelta' ) ? 'yes' : 'no' )
			. '; upgrade_file=' . ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ? 'yes' : 'no' )
			. '; tables=' . implode( ',', (array) $wpdb->get_col( 'SHOW TABLES' ) );
		$this->assertTrue( $migrated, 'Schema migration failed; missing: ' . implode( ', ', $missing ) . '; ' . $diagnostic );
		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( Schema::VERSION, (string) get_option( 'wplc_schema_version' ) );

		// Schema 13 (T-132): the purchased product of a license.
		$tables = Schema::table_names();
		$this->assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
					$tables['licenses'],
					'renewal_product_id'
				)
			)
		);

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
