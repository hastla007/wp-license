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

		// Schema 14 (T-154, T-137): reason and start of a review case.
		foreach ( array( 'review_reason', 'review_at' ) as $column ) {
			$this->assertSame(
				'1',
				(string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
						$tables['renewals'],
						$column
					)
				),
				'Missing renewals column ' . $column
			);
		}
		$this->assertTrue( Schema::run_registered_step( '14' ), 'Step 14 is repeatable.' );

		// Step 14 backfills cases that existed before the columns.
		$now  = gmdate( 'Y-m-d H:i:s' );
		$then = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$ids  = array();
		foreach ( array( 'review' => 'review_required', 'rejected' => 'manual', 'paid' => 'paid' ) as $name => $status ) {
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => 999000,
					'period_start'    => $now,
					'period_end'      => $now,
					'idempotency_key' => hash( 'sha256', 'schema14-' . $name . wp_rand() ),
					'status'          => $status,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$ids[ $name ] = (int) $wpdb->insert_id;
		}
		$audit = array(
			array( 'renewal_review_required', $ids['review'], 'result_unknown_timeout' ),
			array( 'manual_renewal_rejected', $ids['rejected'], 'manual_renewal_not_allowed' ),
			array( 'manual_renewal_rejected', $ids['paid'], 'manual_period_changed' ),
		);
		foreach ( $audit as $entry ) {
			$wpdb->insert( $tables['audit_log'], array( 'actor_user_id' => 0, 'action' => $entry[0], 'object_type' => 'renewal', 'object_id' => $entry[1], 'reason' => $entry[2], 'created_at' => $then ) );
		}
		$this->assertTrue( Schema::run_registered_step( '14' ) );
		$row = static function ( int $id ) use ( $wpdb, $tables ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT status, review_reason, review_at FROM {$tables['renewals']} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		};
		$this->assertSame( array( 'status' => 'review_required', 'review_reason' => 'result_unknown_timeout', 'review_at' => $then ), $row( $ids['review'] ) );
		$this->assertSame( array( 'status' => 'review_required', 'review_reason' => 'manual_renewal_not_allowed', 'review_at' => $then ), $row( $ids['rejected'] ), 'A rejected manual payment becomes a case.' );
		$this->assertSame( array( 'status' => 'paid', 'review_reason' => '', 'review_at' => null ), $row( $ids['paid'] ), 'A period paid since then stays as it is.' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['renewals']} WHERE license_id = %d", 999000 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

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
