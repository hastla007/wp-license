<?php
use WPLC\Infrastructure\Database\Schema;
use WPLC\Maintenance\Retention;

/**
 * T-92: the retention delete queries run against a real database.
 */
final class RetentionIntegrationTest extends WP_UnitTestCase {
	/** @var string */
	private $old;
	/** @var string */
	private $recent;

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		global $wpdb;
		foreach ( array( 'audit_log', 'event_inbox', 'reminder_log', 'request_nonces' ) as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table_names()[ $table ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test cleanup, table name from Schema.
		}
		// Older and younger than the longest default period (730 days).
		$this->old    = gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS );
		$this->recent = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
	}

	public function test_old_audit_entries_are_deleted_and_recent_ones_kept(): void {
		$old    = $this->insert( 'audit_log', array( 'action' => 'old', 'object_type' => 'license', 'reason' => 'x', 'created_at' => $this->old ) );
		$recent = $this->insert( 'audit_log', array( 'action' => 'recent', 'object_type' => 'license', 'reason' => 'x', 'created_at' => $this->recent ) );

		Retention::run();

		$this->assertFalse( $this->exists( 'audit_log', $old ) );
		$this->assertTrue( $this->exists( 'audit_log', $recent ) );
	}

	public function test_processed_inbox_events_are_deleted_but_open_ones_never(): void {
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS );
		$done     = $this->inbox( 'done', $old_date, 'processed' );
		$review   = $this->inbox( 'review', $old_date, 'manual_review' );
		$pending  = $this->inbox( 'pending', $old_date, 'pending_reconciliation' );
		$unproc   = $this->inbox( 'unprocessed', null, 'received' );
		$young    = $this->inbox( 'young', $this->recent, 'processed' );

		Retention::run();

		$this->assertFalse( $this->exists( 'event_inbox', $done ) );
		$this->assertTrue( $this->exists( 'event_inbox', $review ) );
		$this->assertTrue( $this->exists( 'event_inbox', $pending ) );
		$this->assertTrue( $this->exists( 'event_inbox', $unproc ) );
		$this->assertTrue( $this->exists( 'event_inbox', $young ) );
	}

	public function test_old_reminder_log_rows_are_deleted(): void {
		$old    = $this->insert( 'reminder_log', array( 'license_id' => 1, 'reminder_type' => 'expiry_30', 'period_end' => $this->old, 'sent_at' => $this->old ) );
		$recent = $this->insert( 'reminder_log', array( 'license_id' => 1, 'reminder_type' => 'expiry_7', 'period_end' => $this->recent, 'sent_at' => $this->recent ) );

		Retention::run();

		$this->assertFalse( $this->exists( 'reminder_log', $old ) );
		$this->assertTrue( $this->exists( 'reminder_log', $recent ) );
	}

	public function test_only_old_deactivated_installations_of_ended_licenses_are_deleted(): void {
		$deactivated_long_ago = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );

		$expired = $this->license( 'expired' );
		$gone    = $this->activation( $expired, 'inactive', $deactivated_long_ago );
		$young   = $this->activation( $expired, 'inactive', $this->recent );
		$this->insert( 'request_nonces', array( 'activation_id' => $gone, 'nonce_hash' => str_repeat( 'a', 64 ), 'expires_at' => $this->old, 'created_at' => $this->old ) );

		$active_license = $this->license( 'active' );
		$kept_active_license = $this->activation( $active_license, 'inactive', $deactivated_long_ago );

		// A deactivated live site whose staging site is still active stays.
		$revoked = $this->license( 'revoked' );
		$parent  = $this->activation( $revoked, 'inactive', $deactivated_long_ago );
		$this->activation( $revoked, 'active', null, $parent );

		Retention::run();

		$this->assertFalse( $this->exists( 'activations', $gone ) );
		$this->assertSame( 0, $this->count_where( 'request_nonces', 'activation_id', $gone ), 'replay rows of the deleted installation' );
		$this->assertTrue( $this->exists( 'activations', $young ) );
		$this->assertTrue( $this->exists( 'activations', $kept_active_license ) );
		$this->assertTrue( $this->exists( 'activations', $parent ) );
	}

	public function test_a_disabled_rule_deletes_nothing_and_batches_are_bounded(): void {
		$old = $this->insert( 'audit_log', array( 'action' => 'old', 'object_type' => 'license', 'reason' => 'x', 'created_at' => $this->old ) );
		$filter = static function ( $days ) {
			$days['audit_log'] = 0;
			return $days;
		};
		add_filter( 'wplc_retention_days', $filter );
		Retention::run();
		remove_filter( 'wplc_retention_days', $filter );
		$this->assertTrue( $this->exists( 'audit_log', $old ) );

		for ( $i = 0; $i < Retention::BATCH_SIZE + 5; $i++ ) {
			$this->insert( 'audit_log', array( 'action' => 'bulk', 'object_type' => 'license', 'reason' => 'x', 'created_at' => $this->old ) );
		}
		Retention::run();
		$this->assertSame( 6, $this->count_where( 'audit_log', 'created_at', $this->old ), 'one batch per run' );
		Retention::run();
		$this->assertSame( 0, $this->count_where( 'audit_log', 'created_at', $this->old ) );
	}

	/** @param array<string,mixed> $row */
	private function insert( string $table, array $row ): int {
		global $wpdb;
		$this->assertNotFalse( $wpdb->insert( Schema::table_names()[ $table ], $row ), $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	private function inbox( string $id, ?string $processed_at, string $outcome ): int {
		return $this->insert(
			'event_inbox',
			array(
				'source'          => 'stripe',
				'source_event_id' => 'evt_' . $id . wp_generate_password( 6, false ),
				'event_type'      => 'payment_intent.succeeded',
				'payload_hash'    => str_repeat( 'b', 64 ),
				'payload_json'    => '{}',
				'received_at'     => $processed_at ?? $this->old,
				'processed_at'    => $processed_at,
				'outcome'         => $outcome,
			)
		);
	}

	private function license( string $status ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->insert(
			'licenses',
			array(
				'license_uuid'          => wp_generate_uuid4(),
				'license_key_hash'      => hash( 'sha256', wp_generate_uuid4() ),
				'license_key_secret_id' => 'integration-2026',
				'key_prefix'            => 'WPLC-RETENTION',
				'customer_id'           => 1,
				'product_key'           => 'retention-product',
				'plan_key'              => 'retention-plan',
				'status'                => $status,
				'billing_interval'      => 'year',
				'starts_at'             => $this->old,
				'paid_until'            => $now,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
	}

	private function activation( int $license_id, string $status, ?string $deactivated_at, int $staging_parent = 0 ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'license_id'                    => $license_id,
			'installation_uuid'             => wp_generate_uuid4(),
			'installation_secret_hash'      => hash( 'sha256', wp_generate_uuid4() ),
			'installation_secret_encrypted' => 'x',
			'site_url'                      => 'https://' . wp_generate_password( 8, false ) . '.example.test',
			'environment'                   => $staging_parent ? 'staging' : 'production',
			'product_version'               => '1.0.0',
			'wp_version'                    => '6.8',
			'php_version'                   => PHP_VERSION,
			'status'                        => $status,
			'activated_at'                  => $this->old,
			'last_seen_at'                  => $now,
			'deactivated_at'                => $deactivated_at,
		);
		if ( $staging_parent ) {
			$row['staging_parent_id'] = $staging_parent;
		}
		return $this->insert( 'activations', $row );
	}

	private function exists( string $table, int $id ): bool {
		return 1 === $this->count_where( $table, 'id', $id );
	}

	/** @param int|string $value */
	private function count_where( string $table, string $column, $value ): int {
		global $wpdb;
		$name = Schema::table_names()[ $table ];
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$name} WHERE {$column} = %s", (string) $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test helper, fixed names.
	}
}
