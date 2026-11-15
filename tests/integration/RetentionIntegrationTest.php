<?php
/**
 * Retention rules against a real database (T-92, B-27, B-45, A-25, A-36, A-40).
 *
 * @package WPLC
 */

use WPLC\Maintenance\Orphans;
use WPLC\Maintenance\RenewalSwitch;
use WPLC\Maintenance\Retention;

final class RetentionIntegrationTest extends WPLC_Integration_TestCase {

	private function past( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	private function count_rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		$tables = $this->tables();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables[ $table ]} WHERE {$where}" );
	}

	private function audit( string $created_at, int $actor = 0 ): int {
		global $wpdb;
		$wpdb->insert(
			$this->tables()['audit_log'],
			array(
				'actor_user_id' => $actor,
				'action'        => 'fixture',
				'object_type'   => 'license',
				'object_id'     => 1,
				'reason'        => '',
				'created_at'    => $created_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function inbox( string $outcome, string $received_at, ?string $processed_at ): int {
		global $wpdb;
		$wpdb->insert(
			$this->tables()['event_inbox'],
			array(
				'source'          => 'stripe',
				'source_event_id' => 'evt_' . wp_generate_password( 16, false ),
				'event_type'      => 'payment_intent.succeeded',
				'payload_hash'    => hash( 'sha256', wp_generate_password( 8, false ) ),
				'payload_json'    => '{}',
				'received_at'     => $received_at,
				'processed_at'    => $processed_at,
				'outcome'         => $outcome,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function exists( string $table, int $id ): bool {
		return 1 === $this->count_rows( $table, 'id = ' . $id );
	}

	// T-92: every delete query runs against MySQL and keeps what is inside the period.
	public function test_each_rule_deletes_only_what_is_older_than_its_period(): void {
		global $wpdb;
		$tables = $this->tables();

		$old_audit = $this->audit( $this->past( 731 ) );
		$new_audit = $this->audit( $this->past( 729 ) );

		$old_event        = $this->inbox( 'processed', $this->past( 200 ), $this->past( 181 ) );
		$new_event        = $this->inbox( 'processed', $this->past( 200 ), $this->past( 179 ) );
		$open_event       = $this->inbox( 'manual_review', $this->past( 400 ), $this->past( 400 ) );
		$very_old_open    = $this->inbox( 'manual_review', $this->past( 731 ), $this->past( 731 ) );
		$very_old_pending = $this->inbox( 'received', $this->past( 800 ), null );

		$ended  = $this->seed_license( array( 'status' => 'expired', 'paid_until' => $this->past( 500 ), 'next_payment_at' => null ) );
		$active = $this->seed_license();
		$gone   = array( 'status' => 'deactivated', 'deactivated_at' => $this->past( 366 ) );
		$recent = array( 'status' => 'deactivated', 'deactivated_at' => $this->past( 364 ) );

		$ended_old     = $this->seed_activation( $ended['id'], $gone );
		$ended_recent  = $this->seed_activation( $ended['id'], $recent );
		$active_old    = $this->seed_activation( $active['id'], $gone );
		$active_recent = $this->seed_activation( $active['id'], $recent );
		$active_live   = $this->seed_activation( $active['id'] );
		$parent        = $this->seed_activation( $active['id'], $gone );
		$child         = $this->seed_activation( $active['id'], array( 'environment' => 'staging', 'staging_parent_id' => $parent['id'] ) );
		$wpdb->insert(
			$tables['request_nonces'],
			array(
				'activation_id' => $ended_old['id'],
				'nonce_hash'    => hash( 'sha256', 'n1' ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$wpdb->insert( $tables['reminder_log'], array( 'license_id' => $active['id'], 'reminder_type' => 'expiry_30', 'period_end' => $this->past( 700 ), 'sent_at' => $this->past( 731 ) ) );
		$old_mail = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['reminder_log'], array( 'license_id' => $active['id'], 'reminder_type' => 'expiry_7', 'period_end' => $this->past( 700 ), 'sent_at' => $this->past( 10 ) ) );
		$new_mail = (int) $wpdb->insert_id;

		$processed = Retention::run();

		$this->assertFalse( $this->exists( 'audit_log', $old_audit ) );
		$this->assertTrue( $this->exists( 'audit_log', $new_audit ) );
		$this->assertFalse( $this->exists( 'event_inbox', $old_event ) );
		$this->assertTrue( $this->exists( 'event_inbox', $new_event ) );
		$this->assertTrue( $this->exists( 'event_inbox', $open_event ), 'Unresolved events outlive the normal period.' );
		$this->assertFalse( $this->exists( 'event_inbox', $very_old_open ), 'B-27: but not forever.' );
		$this->assertFalse( $this->exists( 'event_inbox', $very_old_pending ) );
		$this->assertFalse( $this->exists( 'activations', $ended_old['id'] ) );
		$this->assertTrue( $this->exists( 'activations', $ended_recent['id'] ) );
		$this->assertFalse( $this->exists( 'activations', $active_old['id'] ), 'B-27: deactivated installations of licenses in force are deleted after the period.' );
		$this->assertTrue( $this->exists( 'activations', $active_recent['id'] ) );
		$this->assertTrue( $this->exists( 'activations', $active_live['id'] ) );
		$this->assertTrue( $this->exists( 'activations', $parent['id'] ), 'A deactivated parent with an active staging child is kept.' );
		$this->assertTrue( $this->exists( 'activations', $child['id'] ) );
		$this->assertSame( 0, $this->count_rows( 'request_nonces', 'activation_id = ' . $ended_old['id'] ) );
		$this->assertFalse( $this->exists( 'reminder_log', $old_mail ) );
		$this->assertTrue( $this->exists( 'reminder_log', $new_mail ) );
		$this->assertSame( 1, $processed['audit_log'] );
		$this->assertSame( 1, $processed['event_inbox'] );
		$this->assertSame( 2, $processed['event_inbox_open'] );

		$this->assertSame( array(), Retention::run(), 'A second run finds nothing.' );
	}

	// B-27: a backlog larger than one batch is removed in one run, not over several days.
	public function test_a_backlog_larger_than_one_batch_is_deleted_in_one_run(): void {
		global $wpdb;
		$tables = $this->tables();
		$old    = $this->past( 800 );
		$values = array();
		for ( $i = 0; $i < 2300; $i++ ) {
			$values[] = "(0,'fixture','license',1,'','{$old}')";
		}
		$wpdb->query( "INSERT INTO {$tables['audit_log']} (actor_user_id,action,object_type,object_id,reason,created_at) VALUES " . implode( ',', $values ) );
		$keep = $this->audit( $this->past( 1 ) );

		$processed = Retention::run();

		$this->assertSame( 2300, $processed['audit_log'] );
		$this->assertSame( 0, $this->count_rows( 'audit_log', "created_at < '" . $this->past( 730 ) . "'" ) );
		$this->assertTrue( $this->exists( 'audit_log', $keep ) );
	}

	// B-27: the time budget stops the loop; the rest follows with the next run.
	public function test_time_budget_and_disabled_rules_are_respected(): void {
		$old = $this->audit( $this->past( 800 ) );
		add_filter(
			'wplc_retention_days',
			static function ( $days ) {
				$days['audit_log'] = 0;
				return $days;
			}
		);

		Retention::run();
		$this->assertTrue( $this->exists( 'audit_log', $old ), 'A rule with period 0 deletes nothing.' );
	}

	// B-45: installations of ended licenses are deactivated; revoked at once, expired after the renewal window.
	public function test_active_installations_of_ended_licenses_are_deactivated(): void {
		$revoked        = $this->seed_license( array( 'status' => 'revoked' ) );
		$expired_long   = $this->seed_license( array( 'status' => 'expired', 'paid_until' => $this->past( 31 ), 'next_payment_at' => null ) );
		$expired_recent = $this->seed_license( array( 'status' => 'expired', 'paid_until' => $this->past( 5 ), 'next_payment_at' => null ) );
		$active         = $this->seed_license();
		$a_revoked      = $this->seed_activation( $revoked['id'] );
		$a_long         = $this->seed_activation( $expired_long['id'] );
		$a_long_staging = $this->seed_activation( $expired_long['id'], array( 'environment' => 'staging', 'staging_parent_id' => $a_long['id'] ) );
		$a_recent       = $this->seed_activation( $expired_recent['id'] );
		$a_active       = $this->seed_activation( $active['id'] );

		$processed = Retention::run();

		$this->assertSame( 'deactivated', $this->activation( $a_revoked['id'] )['status'] );
		$this->assertSame( 'deactivated', $this->activation( $a_long['id'] )['status'] );
		$this->assertSame( 'deactivated', $this->activation( $a_long_staging['id'] )['status'] );
		$this->assertSame( 'active', $this->activation( $a_recent['id'] )['status'], 'A late renewal payment can still reactivate this license; its sites keep working.' );
		$this->assertSame( 'active', $this->activation( $a_active['id'] )['status'] );
		$this->assertGreaterThanOrEqual( 2, $processed['ended_license_activations'] );
		$this->assertSame( 2, $this->count_rows( 'audit_log', "action = 'site_deactivated_license_ended'" ) );
	}

	// B-45: the status hook deactivates the installations of a license the moment it is revoked.
	public function test_revoking_a_license_deactivates_its_installations_at_once(): void {
		$license    = $this->seed_license();
		$activation = $this->seed_activation( $license['id'] );

		$this->assertTrue( \WPLC\License\LicenseLifecycle::admin_set_status( $license['id'], 'revoked', 'Test', 0 ) );

		$this->assertSame( 'revoked', $this->license( $license['id'] )['status'] );
		$this->assertSame( 'deactivated', $this->activation( $activation['id'] )['status'] );
	}

	// B-45: anonymisation is off by default and removes owner, payment reference and installations when configured.
	public function test_anonymisation_of_licenses_that_ended_long_ago(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$long_ago = array(
			'customer_id'      => $customer,
			'status'           => 'expired',
			'paid_until'       => $this->past( 1200 ),
			'next_payment_at'  => null,
			'payment_token_id' => 55,
			'updated_at'       => $this->past( 1200 ),
		);
		$old      = $this->seed_license( $long_ago );
		$recent   = $this->seed_license( array_merge( $long_ago, array( 'paid_until' => $this->past( 100 ), 'updated_at' => $this->past( 100 ) ) ) );
		$active   = $this->seed_license( array( 'customer_id' => $customer, 'payment_token_id' => 55 ) );
		$old_site = $this->seed_activation( $old['id'], array( 'status' => 'deactivated', 'deactivated_at' => $this->past( 10 ) ) );

		Retention::run();
		$this->assertSame( $customer, (int) $this->license( $old['id'] )['customer_id'], 'Off by default.' );

		add_filter(
			'wplc_retention_days',
			static function ( $days ) {
				$days['anonymize_licenses'] = 1095;
				return $days;
			}
		);
		$processed = Retention::run();

		$this->assertSame( 1, $processed['anonymize_licenses'] );
		$row = $this->license( $old['id'] );
		$this->assertSame( 0, (int) $row['customer_id'] );
		$this->assertSame( 0, (int) $row['payment_token_id'] );
		$this->assertSame( 'expired', $row['status'], 'The license row stays as a business record.' );
		$this->assertFalse( $this->exists( 'activations', $old_site['id'] ) );
		$this->assertSame( $customer, (int) $this->license( $recent['id'] )['customer_id'] );
		$this->assertSame( $customer, (int) $this->license( $active['id'] )['customer_id'] );
		$this->assertSame( 55, (int) $this->license( $active['id'] )['payment_token_id'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'license_anonymized', $old['id'] ) ) );
		$this->assertArrayNotHasKey( 'anonymize_licenses', Retention::run(), 'An anonymised license is not selected again.' );
	}

	// A-36, A-40: the hourly sweep removes all expired short-lived rows, not 1000 per hour.
	public function test_hourly_sweep_removes_all_expired_security_rows(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$expired = $this->past( 2 );
		$future  = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$now     = gmdate( 'Y-m-d H:i:s' );

		$nonces = array();
		$limits = array();
		for ( $i = 0; $i < 2500; $i++ ) {
			$hash     = hash( 'sha256', 'row-' . $i );
			$nonces[] = "(1,'{$hash}','{$expired}','{$expired}')";
			$limits[] = "('{$hash}','{$expired}',1,'{$expired}')";
		}
		$wpdb->query( "INSERT INTO {$tables['request_nonces']} (activation_id,nonce_hash,expires_at,created_at) VALUES " . implode( ',', $nonces ) );
		$wpdb->query( "INSERT INTO {$tables['request_limits']} (limiter_hash,bucket_start,hits,created_at) VALUES " . implode( ',', $limits ) );
		$wpdb->insert( $tables['request_nonces'], array( 'activation_id' => 1, 'nonce_hash' => hash( 'sha256', 'valid' ), 'expires_at' => $future, 'created_at' => $now ) );
		$wpdb->insert( $tables['request_limits'], array( 'limiter_hash' => hash( 'sha256', 'valid' ), 'bucket_start' => $now, 'hits' => 1, 'created_at' => $now ) );
		$wpdb->insert( $tables['download_tokens'], array( 'license_id' => $license['id'], 'token_hash' => hash( 'sha256', 'old' ), 'package_url' => 'https://example.test/a.zip', 'expires_at' => $this->past( 8 ), 'created_at' => $this->past( 8 ) ) );
		$wpdb->insert( $tables['download_tokens'], array( 'license_id' => $license['id'], 'token_hash' => hash( 'sha256', 'new' ), 'package_url' => 'https://example.test/a.zip', 'expires_at' => $this->past( 1 ), 'created_at' => $this->past( 1 ) ) );
		$wpdb->insert( $tables['package_streams'], array( 'token_hash' => hash( 'sha256', 'old' ), 'release_id' => 1, 'license_id' => $license['id'], 'expires_at' => $this->past( 2 ), 'created_at' => $this->past( 2 ) ) );
		$wpdb->insert( $tables['package_streams'], array( 'token_hash' => hash( 'sha256', 'new' ), 'release_id' => 1, 'license_id' => $license['id'], 'expires_at' => $future, 'created_at' => $now ) );

		do_action( 'wplc_cleanup_security_rows' );

		$this->assertSame( 1, $this->count_rows( 'request_nonces' ) );
		$this->assertSame( 1, $this->count_rows( 'request_limits' ) );
		$this->assertSame( 1, $this->count_rows( 'download_tokens' ) );
		$this->assertSame( 1, $this->count_rows( 'package_streams' ) );
	}

	// A-25, B-45: a deleted account detaches its licenses and stops automatic renewal, nothing is revoked.
	public function test_deleted_user_detaches_licenses_without_revoking(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer, 'renewal_enabled' => 1, 'payment_token_id' => 9, 'payment_gateway_id' => 'stripe' ) );
		$foreign  = $this->seed_license( array( 'customer_id' => $other, 'renewal_enabled' => 1, 'payment_token_id' => 10 ) );
		$live     = $this->seed_activation( $license['id'] );
		$old_site = $this->seed_activation( $license['id'], array( 'status' => 'deactivated', 'deactivated_at' => $this->past( 1 ) ) );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'      => $license['id'],
				'period_start'    => $now,
				'period_end'      => $now,
				'idempotency_key' => hash( 'sha256', 'deleted-user' ),
				'status'          => 'scheduled',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$renewal_id = (int) $wpdb->insert_id;
		$acted      = $this->audit( $now, $customer );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $customer );

		$row = $this->license( $license['id'] );
		$this->assertSame( 0, (int) $row['customer_id'] );
		$this->assertSame( 'active', $row['status'], 'The license is not revoked.' );
		$this->assertSame( 0, (int) $row['renewal_enabled'] );
		$this->assertSame( 0, (int) $row['payment_token_id'] );
		$this->assertNull( $row['next_payment_at'] );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal_id ) ) );
		$this->assertSame( 'active', $this->activation( $live['id'] )['status'] );
		$this->assertFalse( $this->exists( 'activations', $old_site['id'] ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT actor_user_id FROM {$tables['audit_log']} WHERE id = %d", $acted ) ) );
		$this->assertSame( 1, $this->count_rows( 'audit_log', "action = 'license_owner_deleted' AND object_id = " . $license['id'] ) );
		$this->assertSame( 1, $this->count_rows( 'audit_log', "action = 'renewal_disabled' AND object_id = " . $license['id'] ) );

		$untouched = $this->license( $foreign['id'] );
		$this->assertSame( $other, (int) $untouched['customer_id'] );
		$this->assertSame( 1, (int) $untouched['renewal_enabled'] );
	}

	// B-43: deleting the saved payment method stops automatic renewal instead of running into a failed charge.
	public function test_deleted_payment_token_switches_auto_renewal_off(): void {
		$license = $this->seed_license( array( 'renewal_enabled' => 1, 'payment_token_id' => 4711 ) );
		$other   = $this->seed_license( array( 'renewal_enabled' => 1, 'payment_token_id' => 4712 ) );

		// B-57: a deletion that is not requested by the customer (the gateway syncs its token
		// rows with the payment provider) must not stop renewals.
		do_action( 'woocommerce_payment_token_deleted', 4711, null );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['renewal_enabled'], 'A gateway sync leaves the license untouched.' );
		$this->assertSame( 4711, (int) $this->license( $license['id'] )['payment_token_id'] );
		$this->assertSame( 0, $this->count_rows( 'audit_log', "action = 'renewal_disabled' AND object_id = " . $license['id'] ) );
		$this->assertSame( 'other', Orphans::token_deletion_context( 4711 ) );

		// The My Account request "delete payment method" for exactly this token.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$GLOBALS['wp']->query_vars['delete-payment-method'] = '4712';
		$this->assertSame( 'other', Orphans::token_deletion_context( 4711 ), 'The request names another token.' );
		$GLOBALS['wp']->query_vars['delete-payment-method'] = '4711';
		$this->assertSame( 'customer', Orphans::token_deletion_context( 4711 ) );
		do_action( 'woocommerce_payment_token_deleted', 4711, null );
		unset( $GLOBALS['wp']->query_vars['delete-payment-method'] );

		$this->assertSame( 0, (int) $this->license( $license['id'] )['renewal_enabled'] );
		$this->assertSame( 1, $this->count_rows( 'audit_log', "action = 'renewal_disabled' AND object_id = " . $license['id'] ), 'One handler, one audit entry.' );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['payment_token_id'] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'] );
		$this->assertSame( 1, (int) $this->license( $other['id'] )['renewal_enabled'] );
		$this->assertFalse( RenewalSwitch::off( $license['id'], 'again' ), 'Nothing left to switch off.' );

		$GLOBALS['wp']->query_vars['delete-payment-method'] = '4712';
		add_filter( 'wplc_disable_renewal_on_token_deletion', '__return_false' );
		do_action( 'woocommerce_payment_token_deleted', 4712, null );
		$this->assertSame( 1, (int) $this->license( $other['id'] )['renewal_enabled'] );
		remove_filter( 'wplc_disable_renewal_on_token_deletion', '__return_false' );
		unset( $GLOBALS['wp']->query_vars['delete-payment-method'] );

		// An integration can opt in for deletions of its own.
		add_filter( 'wplc_disable_renewal_on_token_deletion', '__return_true' );
		do_action( 'woocommerce_payment_token_deleted', 4712, null );
		remove_filter( 'wplc_disable_renewal_on_token_deletion', '__return_true' );
		$this->assertSame( 0, (int) $this->license( $other['id'] )['renewal_enabled'] );
	}

	// A-25: a trashed or deleted order leaves a trace on its licenses and changes nothing else.
	public function test_removed_order_is_noted_on_its_licenses(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$license = $this->licenses_for_order( $order->get_id() )[0];

		$order->delete( false );
		$order->delete( true );

		$this->assertSame( 'active', $this->license( (int) $license['id'] )['status'] );
		$this->assertSame( 1, $this->count_rows( 'audit_log', "action = 'source_order_removed' AND object_id = " . (int) $license['id'] ) );
		$this->assertNotFalse( has_action( 'deleted_user', array( Orphans::class, 'on_user_deleted' ) ) );
	}

	// Wave 2: consent evidence of ended licenses is deleted after its period, that of licenses in force never;
	// a deleted account is removed from the evidence.
	public function test_consent_evidence_follows_retention_and_account_deletion(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$ended    = $this->seed_license( array( 'status' => 'expired', 'customer_id' => $customer ) );
		$running  = $this->seed_license( array( 'status' => 'active', 'customer_id' => $customer ) );
		$old      = gmdate( 'Y-m-d H:i:s', time() - 3700 * DAY_IN_SECONDS );
		$recent   = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		$insert   = static function ( int $license_id, string $consent_at ) use ( $wpdb, $tables, $customer ): int {
			$wpdb->insert( $tables['consents'], array( 'license_id' => $license_id, 'customer_id' => $customer, 'consent_at' => $consent_at, 'consent_text' => 'I agree', 'created_at' => $consent_at ) );
			return (int) $wpdb->insert_id;
		};
		$ended_old      = $insert( $ended['id'], $old );
		$ended_recent   = $insert( $ended['id'], $recent );
		$running_old    = $insert( $running['id'], $old );
		$orphan_old     = $insert( 99999999, $old );

		$processed = Retention::run();

		$this->assertSame( 2, $processed['consents'] );
		$this->assertSame( 0, $this->count_rows( 'consents', 'id IN (' . $ended_old . ',' . $orphan_old . ')' ) );
		$this->assertSame( 2, $this->count_rows( 'consents', 'id IN (' . $ended_recent . ',' . $running_old . ')' ) );
		$this->assertArrayNotHasKey( 'consents', Retention::run(), 'Nothing left for the rule.' );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $customer );
		$this->assertSame( 0, $this->count_rows( 'consents', 'customer_id = ' . $customer ) );
		$this->assertSame( 2, $this->count_rows( 'consents', 'id IN (' . $ended_recent . ',' . $running_old . ')' ), 'The evidence itself stays.' );
	}
}
