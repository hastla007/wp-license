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

		// Schema 15 (T-89): indexes for the license list, the account page and diagnostics.
		$indexes = array(
			'licenses'    => array( 'customer_created', 'status_created', 'product_created', 'product_plan', 'created_at', 'paid_until', 'key_prefix' ),
			'event_inbox' => array( 'outcome_processed' ),
		);
		foreach ( $indexes as $table => $names ) {
			foreach ( $names as $name ) {
				$this->assertSame(
					'1',
					(string) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
							$tables[ $table ],
							$name
						)
					),
					'Missing index ' . $table . '.' . $name
				);
			}
		}
		$this->assertTrue( Schema::run_registered_step( '15' ), 'Step 15 finds every index.' );
		$wpdb->query( "ALTER TABLE {$tables['licenses']} DROP INDEX key_prefix" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Schema.
		$this->assertFalse( Schema::run_registered_step( '15' ), 'Step 15 reports a missing index.' );
		$this->assertTrue( Schema::migrate(), 'dbDelta adds the index again.' );
		$this->assertTrue( Schema::run_registered_step( '15' ) );

		// Schema 16 (T-108): the installation an update grant was issued to.
		$this->assertTrue( Schema::run_registered_step( '16' ), 'Step 16 finds download_tokens.activation_id.' );
		$wpdb->query( "ALTER TABLE {$tables['download_tokens']} DROP COLUMN activation_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Schema.
		$this->assertFalse( Schema::run_registered_step( '16' ), 'Step 16 reports the missing column.' );
		// Schema 17 (T-110): plan change columns.
		$this->assertTrue( Schema::run_registered_step( '17' ), 'Step 17 finds the plan change columns.' );
		$wpdb->query( "ALTER TABLE {$tables['licenses']} DROP COLUMN plan_order_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Schema.
		$this->assertFalse( Schema::run_registered_step( '17' ), 'Step 17 reports a missing column.' );
		$this->assertTrue( Schema::migrate(), 'dbDelta adds the columns again.' );
		$this->assertTrue( Schema::run_registered_step( '16' ) );
		$this->assertTrue( Schema::run_registered_step( '17' ) );

		foreach ( Schema::table_names() as $table_name ) {
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table_name
				)
			);
			$this->assertSame( '1', (string) $table_exists, 'Missing WPLC schema table: ' . $table_name );
		}

		// A-105 (schema 18 and 19): the indexes the order lookups, the admin list, retention and diagnostics rely on.
		$tables  = Schema::table_names();
		$indexes = array(
			'licenses'    => array( 'source_order', 'created_at', 'status_paid_until' ),
			'activations' => array( 'status_deactivated' ),
			'event_inbox' => array( 'outcome_processed' ),
		);
		foreach ( $indexes as $table => $names ) {
			$present = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s', $tables[ $table ] ) );
			foreach ( $names as $name ) {
				$this->assertContains( $name, $present, $table . '.' . $name );
			}
		}

		// Schema 21 (merge of both lines): line B's review status moves to review_required, with its reason.
		$now = gmdate( 'Y-m-d H:i:s' );
		$ids = array();
		foreach ( array( 'with_error' => 'license_changed_during_charge', 'without_error' => '' ) as $name => $error ) {
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => 999021,
					'period_start'    => $now,
					'period_end'      => $now,
					'idempotency_key' => hash( 'sha256', 'schema21-' . $name . wp_rand() ),
					'status'          => 'payment_review',
					'retry_at'        => $now,
					'last_error'      => $error,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$ids[ $name ] = (int) $wpdb->insert_id;
		}
		$this->assertTrue( Schema::run_registered_step( '21' ) );
		$this->assertTrue( Schema::run_registered_step( '21' ), 'Step 21 is repeatable.' );
		$moved = static function ( int $id ) use ( $wpdb, $tables ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT status, retry_at, review_reason, review_at FROM {$tables['renewals']} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		};
		$this->assertSame( array( 'status' => 'review_required', 'retry_at' => null, 'review_reason' => 'license_changed_during_charge', 'review_at' => $now ), $moved( $ids['with_error'] ) );
		$this->assertSame( array( 'status' => 'review_required', 'retry_at' => null, 'review_reason' => 'payment_review', 'review_at' => $now ), $moved( $ids['without_error'] ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['renewals']} WHERE license_id = %d", 999021 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		// Schema 22 (P-31, P-53): payment token and last event per source; the structure check sees a lost index.
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Schema::run_registered_step( '22' ), 'Step 22 finds both indexes.' );
		$this->assertTrue( Schema::run_registered_step( '22' ), 'Step 22 is repeatable.' );
		$this->assertSame( array(), Schema::verify() );
		$wpdb->query( "ALTER TABLE {$tables['licenses']} DROP INDEX payment_token" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Schema.
		$this->assertFalse( Schema::run_registered_step( '22' ), 'Step 22 reports the missing index.' );
		$this->assertSame( array( $tables['licenses'] . ': index payment_token is missing' ), Schema::verify(), 'The daily structure check reports a lost index.' );
		$this->assertContains( $tables['licenses'] . ': index payment_token is missing', Schema::status()['problems'] );
		$this->assertTrue( Schema::migrate(), 'dbDelta adds the index again.' );
		$this->assertTrue( Schema::run_registered_step( '22' ) );
		$this->assertSame( array(), Schema::verify() );
		$plan = $wpdb->get_results( $wpdb->prepare( "EXPLAIN SELECT id FROM {$tables['licenses']} WHERE payment_token_id = %d", 42 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$this->assertSame( 'payment_token', (string) $plan[0]['key'], 'The token lookup uses the index.' );
	}
}
