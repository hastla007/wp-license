<?php
/**
 * Privacy exporter and eraser: P-35 (audit snapshots), P-36 (open manual
 * renewals of erased licenses) and P-64 (billing e-mail, missing fields,
 * gateway references, policy text).
 *
 * @package WPLC
 */

use WPLC\License\AuditLog;
use WPLC\Privacy\PersonalData;

final class PersonalDataAuditFixesIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Run the exporter page by page like WordPress does.
	 *
	 * @param string $email Email.
	 * @return array<int,array<string,mixed>> Items.
	 */
	private function export_all( string $email ): array {
		$items = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			$result = PersonalData::export_personal_data( $email, $page );
			$this->assertIsArray( $result );
			$items = array_merge( $items, $result['data'] );
			if ( $result['done'] ) {
				return $items;
			}
		}
		$this->fail( 'The exporter never reported done.' );
	}

	/**
	 * Audit rows of one object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_rows( string $type, int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tables()['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ), ARRAY_A );
	}

	public function test_p35_scrub_snapshot_replaces_site_urls_and_customer_ids(): void {
		$json = wp_json_encode(
			array(
				'site_url'    => 'https://jane-doe.example',
				'customer_id' => 17,
				'nested'      => array( 'customer_id' => 18, 'site_url' => 'https://other.example' ),
				'status'      => 'active',
			)
		);
		$this->assertSame(
			array(
				'site_url'    => PersonalData::ERASED,
				'customer_id' => PersonalData::ERASED,
				'nested'      => array( 'customer_id' => PersonalData::ERASED, 'site_url' => PersonalData::ERASED ),
				'status'      => 'active',
			),
			json_decode( (string) PersonalData::scrub_snapshot( $json ), true )
		);
		// Only one person's ID, site URLs untouched (an owner change also names the other owner).
		$this->assertSame(
			array(
				'site_url'    => 'https://jane-doe.example',
				'customer_id' => PersonalData::ERASED,
				'nested'      => array( 'customer_id' => 18, 'site_url' => 'https://other.example' ),
				'status'      => 'active',
			),
			json_decode( (string) PersonalData::scrub_snapshot( $json, 17, false ), true )
		);
		$this->assertNull( PersonalData::scrub_snapshot( wp_json_encode( array( 'status' => 'active' ) ) ) );
		$this->assertNull( PersonalData::scrub_snapshot( null ) );
		$this->assertNull( PersonalData::scrub_snapshot( 'not json' ) );
	}

	public function test_p35_p36_erasure_removes_site_urls_from_history_and_cancels_open_manual_renewals(): void {
		global $wpdb;
		$tables   = $this->tables();
		$email    = 'p35-erase@example.test';
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => $email ) );
		$other    = self::factory()->user->create( array( 'role' => 'customer' ) );

		// An expired license inside the renewal window with an open "renew now" order.
		$expired = $this->seed_license(
			array(
				'customer_id' => $customer,
				'status'      => 'expired',
				'paid_until'  => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ),
			)
		);
		$site    = $this->seed_activation( $expired['id'], array( 'site_url' => 'https://jane-doe.example' ) );
		\WPLC\License\ActivationManager::deactivate( $site['id'], 'Moved', 0, 'site_deactivated' );
		$order   = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );
		$order->update_meta_data( '_wplc_renewal_for_license', $expired['id'] );
		$order->save();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'       => $expired['id'],
				'period_start'     => $expired['paid_until'] ?? $now,
				'period_end'       => gmdate( 'Y-m-d H:i:s', time() + 360 * DAY_IN_SECONDS ),
				'idempotency_key'  => hash( 'sha256', 'p36-' . $expired['id'] ),
				'status'           => 'manual',
				'renewal_order_id' => $order->get_id(),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$renewal = (int) $wpdb->insert_id;
		$order->update_meta_data( \WPLC\Billing\RenewalOrder::META_RENEWAL, $renewal );
		$order->update_meta_data( \WPLC\Billing\RenewalOrder::META_MANUAL, 'yes' );
		$order->save();
		AuditLog::record( 'license_issued', 'license', $expired['id'], '', null, array( 'customer_id' => $customer ), 0 );

		// A license the person handed over earlier: the owner change names their ID.
		$handed = $this->seed_license( array( 'customer_id' => $other ) );
		AuditLog::record( 'license_owner_changed', 'license', $handed['id'], 'Sold', array( 'customer_id' => $customer ), array( 'customer_id' => $other ), 1 );

		// A license in force with a deactivated installation that the eraser deletes.
		$active      = $this->seed_license( array( 'customer_id' => $customer ) );
		$old_site    = $this->seed_activation( $active['id'], array( 'site_url' => 'https://old-jane.example' ) );
		$kept_site   = $this->seed_activation( $active['id'], array( 'site_url' => 'https://live-jane.example' ) );
		\WPLC\License\ActivationManager::deactivate( $old_site['id'], 'Gone', 0, 'site_deactivated' );
		AuditLog::record( 'site_activated', 'activation', $kept_site['id'], '', null, array( 'site_url' => 'https://live-jane.example' ), 0 );

		// Before the erasure, the export contains the snapshots (P-35).
		$export = wp_json_encode( $this->export_all( $email ) );
		$this->assertStringContainsString( 'jane-doe.example', $export );

		do {
			$result = PersonalData::erase_personal_data( $email, 1 );
		} while ( ! $result['done'] );

		$rows    = array_merge(
			$this->audit_rows( 'license', $expired['id'] ),
			$this->audit_rows( 'activation', $site['id'] ),
			$this->audit_rows( 'activation', $old_site['id'] ),
			$this->audit_rows( 'license', $handed['id'] )
		);
		// The stored snapshots as they are, one per line.
		$history = implode( "\n", array_merge( array_column( $rows, 'before_json' ), array_column( $rows, 'after_json' ) ) );
		$this->assertStringContainsString( 'site_url', $history, 'Fixture: the snapshots name site addresses.' );
		$this->assertStringNotContainsString( 'jane-doe.example', $history );
		$this->assertStringNotContainsString( 'old-jane.example', $history );
		$this->assertStringNotContainsString( '"customer_id":' . $customer . ',', $history );
		$this->assertStringNotContainsString( '"customer_id":' . $customer . '}', $history );
		$this->assertStringContainsString( '"customer_id":' . $other, $history, 'The other owner of the hand-over is not erased.' );
		$this->assertStringContainsString( PersonalData::ERASED, $history );
		// The live installation of the license in force keeps its history.
		$this->assertStringContainsString( 'live-jane.example', wp_json_encode( $this->audit_rows( 'activation', $kept_site['id'] ) ) );

		// P-36: the open manual renewal and its order are closed instead of waiting for a payment that could not be booked.
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal ) ) );
		$this->assertContains( wc_get_order( $order->get_id() )->get_status(), array( 'cancelled', 'failed' ) );
		$this->assertSame( 0, (int) $this->license( $expired['id'] )['customer_id'] );
	}

	public function test_p64_billing_email_finds_the_account_and_unknown_addresses_are_reported(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'account-p64@example.test' ) );
		$order    = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );
		$order->set_billing_email( 'billing-p64@example.test' );
		$order->save();
		$license  = $this->seed_license(
			array(
				'customer_id'     => $customer,
				'product_key'     => 'billing-product',
				'status_reason'   => 'Paused by support',
				'source_order_id' => $order->get_id(),
			)
		);
		$this->seed_activation( $license['id'], array( 'installation_uuid' => '11111111-2222-4333-8444-555555555555' ) );

		$this->assertSame( array( $customer ), PersonalData::user_ids_for_email( 'billing-p64@example.test' ) );
		$this->assertSame( array( $customer ), PersonalData::user_ids_for_email( 'ACCOUNT-p64@example.test' ) );
		$export = wp_json_encode( $this->export_all( 'billing-p64@example.test' ) );
		$this->assertStringContainsString( 'billing-product', $export );
		$this->assertStringContainsString( 'Paused by support', $export, 'The status reason is exported.' );
		$this->assertStringContainsString( '11111111-2222-4333-8444-555555555555', $export, 'The installation ID is exported.' );

		// An order whose billing address it is, but that belongs to someone else than the license owner, does not count.
		$owner   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$foreign = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $owner );
		$foreign->set_billing_email( 'agency-p64@example.test' );
		$foreign->save();
		$this->seed_license( array( 'customer_id' => self::factory()->user->create(), 'source_order_id' => $foreign->get_id() ) );
		$this->assertSame( array(), PersonalData::user_ids_for_email( 'agency-p64@example.test' ) );

		$erase = PersonalData::erase_personal_data( 'unknown-p64@example.test', 1 );
		$this->assertTrue( $erase['done'] );
		$this->assertFalse( $erase['items_removed'] );
		$this->assertNotEmpty( $erase['messages'], 'An address without data is reported instead of a silent "done".' );
	}

	public function test_p64_export_contains_renewal_history_and_review_reason(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'renewal-p64@example.test' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer ) );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'      => $license['id'],
				'period_start'    => $now,
				'period_end'      => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ),
				'idempotency_key' => hash( 'sha256', 'p64-renewal-' . $license['id'] ),
				'status'          => 'review_required',
				'review_reason'   => 'result_unknown_timeout',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		AuditLog::record( 'renewal_review_opened_p64', 'renewal', (int) $wpdb->insert_id, 'Review', null, null, 0 );

		$export = wp_json_encode( $this->export_all( 'renewal-p64@example.test' ) );
		$this->assertStringContainsString( 'renewal_review_opened_p64', $export );
		$this->assertStringContainsString( 'result_unknown_timeout', $export );
	}

	public function test_p64_erasure_clears_gateway_references_when_renewal_is_already_off(): void {
		global $wpdb;
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'refs-p64@example.test' ) );
		$license  = $this->seed_license(
			array(
				'customer_id'          => $customer,
				'renewal_enabled'      => 0,
				'payment_token_id'     => 0,
			)
		);
		$wpdb->update( $this->tables()['licenses'], array( 'gateway_customer_ref' => 'cus_TEST123', 'gateway_method_ref' => 'pm_TEST456' ), array( 'id' => $license['id'] ) );

		$result = PersonalData::erase_personal_data( 'refs-p64@example.test', 1 );

		$row = $this->license( $license['id'] );
		$this->assertNull( $row['gateway_customer_ref'] );
		$this->assertNull( $row['gateway_method_ref'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( $customer, (int) $row['customer_id'], 'The license in force is kept.' );
		$this->assertTrue( PersonalData::erase_personal_data( 'refs-p64@example.test', 1 )['done'] );
	}

	public function test_p64_privacy_policy_text_names_the_payment_provider_and_package_hosts(): void {
		$text = PersonalData::privacy_policy_text();
		$this->assertStringContainsString( 'Stripe', $text );
		$this->assertStringContainsString( 'order key', $text );
		$this->assertStringContainsString( 'external package host', $text );
		$this->assertStringContainsString( 'billing email address', $text );
	}

	// P3-A5: the suggested text names every flow with personal data added since.
	public function test_privacy_policy_text_names_webhooks_cancellation_page_and_the_evidence_records(): void {
		$text = PersonalData::privacy_policy_text();
		$this->assertStringContainsString( 'outgoing webhooks', $text );
		$this->assertStringContainsString( 'right of withdrawal', $text );
		$this->assertStringContainsString( 'cancellation page', $text );
		$this->assertStringContainsString( 'raise the renewal price', $text );
		$this->assertStringContainsString( 'upgrade a license', $text );
		$this->assertStringContainsString( 'ten years after the end of the year', $text );
	}

	// P3-A4: a price-change notice record is exported with its own labels.
	public function test_price_change_notice_records_are_exported_as_notices(): void {
		global $wpdb;
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'notice-a4@example.test' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer ) );
		$this->assertTrue(
			\WPLC\Billing\ConsentRecord::store(
				array(
					'license_id' => $license['id'],
					'customer_id' => $customer,
					'consent_at' => '2026-05-01 10:00:00',
					'source' => \WPLC\Billing\ConsentRecord::SOURCE_PRICE_NOTICE,
					'text' => 'Price change notice sent by email: from 49.00 EUR to 59.00 EUR',
					'amount_minor' => 5900,
					'currency' => 'EUR',
				)
			)
		);
		$this->assertTrue( \WPLC\Billing\ConsentRecord::store( array( 'license_id' => $license['id'], 'customer_id' => $customer, 'consent_at' => '2026-05-01 10:00:00', 'source' => 'price_notice', 'text' => 'Price change notice sent by email: from 49.00 EUR to 59.00 EUR' ) ), 'Repeated: nothing new.' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d', $this->tables()['consents'], $license['id'] ) ) );

		$found = array();
		for ( $page = 1; $page <= 20; ++$page ) {
			$result = PersonalData::export_personal_data( 'notice-a4@example.test', $page );
			foreach ( $result['data'] as $group ) {
				if ( 'wp-license-captain-consents' === $group['group_id'] ) {
					foreach ( $group['data'] as $pair ) {
						$found[ $pair['name'] ] = $pair['value'];
					}
				}
			}
			if ( $result['done'] ) {
				break;
			}
		}
		$this->assertSame( '2026-05-01 10:00:00', $found['Price change notice sent at (UTC)'] ?? null );
		$this->assertStringContainsString( 'to 59.00 EUR', (string) ( $found['Content of the notice'] ?? '' ) );
	}
}
