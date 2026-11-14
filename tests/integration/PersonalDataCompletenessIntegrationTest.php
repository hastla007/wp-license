<?php
/**
 * Completeness of the privacy exporter and eraser (B-43, B-44, B-131, A-100, A-101).
 *
 * @package WPLC
 */

use WPLC\Privacy\PersonalData;

final class PersonalDataCompletenessIntegrationTest extends WPLC_Integration_TestCase {

	private const EMAIL = 'privacy-complete@example.test';

	/**
	 * Run the exporter page by page like WordPress does.
	 *
	 * @param string $email Email.
	 * @return array{items:array<int,array<string,mixed>>,pages:int}
	 */
	private function export_all( string $email ): array {
		$items = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			$result = PersonalData::export_personal_data( $email, $page );
			$this->assertIsArray( $result );
			$items = array_merge( $items, $result['data'] );
			if ( $result['done'] ) {
				return array(
					'items' => $items,
					'pages' => $page,
				);
			}
		}
		$this->fail( 'The exporter never reported done.' );
	}

	/**
	 * @param array<int,array<string,mixed>> $items Export items.
	 * @param string                         $group Group ID.
	 * @return array<int,array<string,string>> One name => value map per item.
	 */
	private function group( array $items, string $group ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( $group !== $item['group_id'] ) {
				continue;
			}
			$map = array();
			foreach ( $item['data'] as $field ) {
				$map[ $field['name'] ] = $field['value'];
			}
			$out[] = $map;
		}

		return $out;
	}

	/**
	 * A customer with a renewing license, a renewal, history, mail log and downloads; plus a stranger with the same.
	 *
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		global $wpdb;
		$tables   = $this->tables();
		$now      = gmdate( 'Y-m-d H:i:s' );
		$out      = array();
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => self::EMAIL ) );
		$stranger = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'stranger@example.test' ) );

		$product = $this->create_license_product();
		$order   = $this->create_order( array( array( $product, 1 ) ), $customer );
		$order->update_meta_data( '_wplc_recurring_consent_at', '2026-05-01 10:00:00' );
		$order->save();

		foreach ( array( 'mine' => $customer, 'theirs' => $stranger ) as $who => $owner ) {
			$license = $this->seed_license(
				array(
					'customer_id'               => $owner,
					'product_key'               => 'mine' === $who ? 'export-product' : 'stranger-product',
					'renewal_enabled'           => 1,
					'payment_gateway_id'        => 'stripe',
					'payment_token_id'          => 'mine' === $who ? 9001 : 9002,
					'renewal_amount_minor'      => 5831,
					'renewal_currency'          => 'EUR',
					'renewal_currency_decimals' => 2,
					'source_order_id'           => 'mine' === $who ? $order->get_id() : null,
				)
			);
			$site    = $this->seed_activation( $license['id'], array( 'site_url' => 'https://' . $who . '-site.example.test' ) );
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => $license['id'],
					'period_start'    => '2027-05-01 10:00:00',
					'period_end'      => '2028-05-01 10:00:00',
					'idempotency_key' => hash( 'sha256', 'export-' . $who ),
					'status'          => 'scheduled',
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$wpdb->insert( $tables['audit_log'], array( 'actor_user_id' => 0, 'action' => 'renewal_succeeded_' . $who, 'object_type' => 'license', 'object_id' => $license['id'], 'reason' => 'note for ' . $who, 'created_at' => $now ) );
			$wpdb->insert( $tables['audit_log'], array( 'actor_user_id' => 0, 'action' => 'site_activated_' . $who, 'object_type' => 'activation', 'object_id' => $site['id'], 'reason' => '', 'created_at' => $now ) );
			$wpdb->insert( $tables['reminder_log'], array( 'license_id' => $license['id'], 'reminder_type' => 'renewal_notice_' . $who, 'period_end' => '2027-05-01 10:00:00', 'sent_at' => $now ) );
			$wpdb->insert( $tables['download_tokens'], array( 'license_id' => $license['id'], 'token_hash' => hash( 'sha256', 'tok-' . $who ), 'package_url' => 'https://secret-host.example.test/' . $who . '.zip', 'expires_at' => $now, 'consumed_at' => $now, 'created_at' => $now ) );
			$wpdb->insert( $tables['package_streams'], array( 'token_hash' => hash( 'sha256', 'stream-' . $who ), 'release_id' => 0, 'license_id' => $license['id'], 'source' => 'account', 'expires_at' => $now, 'created_at' => $now ) );
			$out[ $who ] = array(
				'license' => $license,
				'site'    => $site,
			);
		}
		// An entry the customer triggered on an object that is not theirs.
		$wpdb->insert( $tables['audit_log'], array( 'actor_user_id' => $customer, 'action' => 'customer_action', 'object_type' => 'order', 'object_id' => 5, 'reason' => '', 'created_at' => $now ) );

		$out['customer'] = $customer;
		$out['stranger'] = $stranger;
		$out['order']    = $order;

		return $out;
	}

	// B-44: renewals, consent, amount, interval, next payment, history, email log and downloads are exported.
	public function test_export_is_complete_and_limited_to_the_person(): void {
		$fixture = $this->fixture();

		$export = $this->export_all( self::EMAIL );
		$items  = $export['items'];
		$json   = (string) wp_json_encode( $items );

		$licenses = $this->group( $items, 'wp-license-captain' );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'export-product', $licenses[0]['Product'] );
		$this->assertSame( 'Yes', $licenses[0]['Automatic renewal'] );
		$this->assertSame( 'year', $licenses[0]['Billing interval'] );
		$this->assertSame( '58.31 EUR', $licenses[0]['Renewal amount'] );
		$this->assertSame( $this->license( $fixture['mine']['license']['id'] )['next_payment_at'], $licenses[0]['Next payment'] );
		$this->assertSame( 'Yes', $licenses[0]['Payment method on file for renewal'] );
		$this->assertSame( 'stripe', $licenses[0]['Payment provider'] );
		$this->assertSame( '2026-05-01 10:00:00', $licenses[0]['Consent to automatic renewal given at (UTC)'] );
		$this->assertSame( 'https://mine-site.example.test', $licenses[0]['Installation 1 site URL'] );

		$renewals = $this->group( $items, 'wp-license-captain-renewals' );
		$this->assertCount( 1, $renewals );
		$this->assertSame( '2027-05-01 10:00:00', $renewals[0]['Period start'] );
		$this->assertSame( 'scheduled', $renewals[0]['Status'] );

		$events = array_column( $this->group( $items, 'wp-license-captain-history' ), 'Event' );
		sort( $events );
		$this->assertSame( array( 'customer_action', 'renewal_succeeded_mine', 'site_activated_mine' ), $events );

		$emails = $this->group( $items, 'wp-license-captain-emails' );
		$this->assertCount( 1, $emails );
		$this->assertSame( 'renewal_notice_mine', $emails[0]['Email type'] );

		$downloads = $this->group( $items, 'wp-license-captain-downloads' );
		$this->assertCount( 2, $downloads );

		// Nothing of another customer, no secrets, no token values, no package locations.
		$this->assertStringNotContainsString( 'stranger', $json );
		$this->assertStringNotContainsString( 'theirs', $json );
		$this->assertStringNotContainsString( '9001', $json );
		$this->assertStringNotContainsString( 'secret-host', $json );
		$this->assertStringNotContainsString( $fixture['mine']['site']['secret'], $json );
		$this->assertStringNotContainsString( $fixture['mine']['license']['key'], $json );

		$this->assertGreaterThan( 1, $export['pages'], 'The phases are exported on separate pages.' );
	}

	// B-44, QA: odd input never throws and always terminates.
	public function test_export_and_erase_terminate_for_odd_input(): void {
		$this->fixture();
		foreach ( array( 'nobody@example.test', '', 'not-an-email' ) as $email ) {
			foreach ( array( 1, 0, -1, 99999 ) as $page ) {
				$export = PersonalData::export_personal_data( $email, $page );
				$this->assertSame( array( 'data' => array(), 'done' => true ), $export );
				$erase = PersonalData::erase_personal_data( $email, $page );
				$this->assertTrue( $erase['done'] );
				$this->assertFalse( $erase['items_removed'] );
			}
		}
		$beyond = PersonalData::export_personal_data( self::EMAIL, 99999 );
		$this->assertSame( array( 'data' => array(), 'done' => true ), $beyond );
		$this->assertIsArray( PersonalData::export_personal_data( self::EMAIL, 0 ) );
	}

	// B-44: more rows than one page of a phase are continued, and the last page reports done.
	public function test_history_longer_than_one_page_is_exported_completely(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->fixture();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$values  = array();
		for ( $i = 0; $i < 230; $i++ ) {
			$values[] = "(0,'bulk_event','license'," . (int) $fixture['mine']['license']['id'] . ",'','{$now}')";
		}
		$wpdb->query( "INSERT INTO {$tables['audit_log']} (actor_user_id,action,object_type,object_id,reason,created_at) VALUES " . implode( ',', $values ) );

		$history = $this->group( $this->export_all( self::EMAIL )['items'], 'wp-license-captain-history' );

		$this->assertCount( 233, $history );
	}

	// B-43: erasure switches automatic renewal off for retained licenses and says so.
	public function test_erasure_switches_auto_renewal_off_and_reports_it(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->fixture();
		$mine    = $fixture['mine']['license']['id'];
		$old     = $this->seed_activation( $mine, array( 'status' => 'deactivated', 'deactivated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = PersonalData::erase_personal_data( self::EMAIL, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_retained'], 'The license in force is kept.' );
		$this->assertTrue( $result['items_removed'], 'The deactivated installation and the actor reference were removed.' );
		$messages = implode( ' | ', $result['messages'] );
		$this->assertStringContainsString( 'Automatic renewal was switched off for 1 license', $messages );
		$this->assertStringContainsString( 'still in force was retained', $messages );

		$row = $this->license( $mine );
		$this->assertSame( $fixture['customer'], (int) $row['customer_id'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 0, (int) $row['renewal_enabled'] );
		$this->assertSame( 0, (int) $row['payment_token_id'] );
		$this->assertNull( $row['next_payment_at'] );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE license_id = %d", $mine ) ) );
		$this->assertSame( 'active', $this->activation( $fixture['mine']['site']['id'] )['status'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d", $old['id'] ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE actor_user_id = %d", $fixture['customer'] ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'renewal_disabled', $mine ) ) );

		// The other customer is untouched.
		$theirs = $this->license( $fixture['theirs']['license']['id'] );
		$this->assertSame( 1, (int) $theirs['renewal_enabled'] );
		$this->assertSame( 9002, (int) $theirs['payment_token_id'] );

		// A-101: a repeated call finds nothing more to do and still terminates.
		$again = PersonalData::erase_personal_data( self::EMAIL, 2 );
		$this->assertTrue( $again['done'] );
		$this->assertFalse( $again['items_removed'] );
		$this->assertTrue( $again['items_retained'] );
	}

	// A-100: an ended license loses its active installations too, plus email log and download records.
	public function test_erasure_of_an_ended_license_removes_active_installations_and_logs(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->fixture();
		$mine    = $fixture['mine']['license']['id'];
		$wpdb->update( $tables['licenses'], array( 'status' => 'expired' ), array( 'id' => $mine ) );
		$staging = $this->seed_activation( $mine, array( 'environment' => 'staging', 'staging_parent_id' => $fixture['mine']['site']['id'] ) );

		$result = PersonalData::erase_personal_data( self::EMAIL, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$row = $this->license( $mine );
		$this->assertSame( 0, (int) $row['customer_id'] );
		$this->assertSame( 0, (int) $row['payment_token_id'] );
		foreach ( array( 'activations', 'reminder_log', 'download_tokens', 'package_streams' ) as $table ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables[ $table ]} WHERE license_id = %d", $mine ) ), $table );
		}
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d", $staging['id'] ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'license_erased', $mine ) ) );
		// The stranger's rows survive.
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['reminder_log']} WHERE license_id = %d", $fixture['theirs']['license']['id'] ) ) );

		// Nothing is left to export for the person except the history entry they triggered.
		$export = $this->export_all( self::EMAIL );
		$this->assertCount( 0, $this->group( $export['items'], 'wp-license-captain' ) );
	}

	// A-101: more licenses than one batch are erased over several calls regardless of the page number.
	public function test_erasure_of_many_ended_licenses_completes_over_several_calls(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'many@example.test' ) );
		for ( $i = 0; $i < 120; $i++ ) {
			$this->seed_license( array( 'customer_id' => $customer, 'status' => 'expired' ), array() );
		}
		global $wpdb;
		$tables = $this->tables();

		$calls = 0;
		do {
			++$calls;
			// WordPress passes increasing pages; a repeated page number must not skip anything.
			$result = PersonalData::erase_personal_data( 'many@example.test', 1 );
			$this->assertLessThan( 10, $calls, 'The eraser does not terminate.' );
		} while ( ! $result['done'] );

		$this->assertSame( 3, $calls );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d", $customer ) ) );
	}

	// B-131: a suggested privacy policy text is registered.
	public function test_privacy_policy_text_is_suggested(): void {
		$text = PersonalData::privacy_policy_text();
		$this->assertStringContainsString( 'automatic renewal', $text );
		$this->assertStringContainsString( 'WordPress and PHP version', $text );
		$this->assertNotFalse( has_action( 'admin_init', array( PersonalData::class, 'add_privacy_policy_content' ) ) );

		// WordPress only accepts suggestions in the admin, during admin_init.
		global $wp_current_filter;
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		$wp_current_filter[] = 'admin_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		PersonalData::add_privacy_policy_content();
		array_pop( $wp_current_filter );
		set_current_screen( 'front' );
		$found = false;
		foreach ( WP_Privacy_Policy_Content::get_suggested_policy_text() as $section ) {
			if ( 'WP License Captain' === $section['plugin_name'] ) {
				$found = true;
				$this->assertStringContainsString( 'license server', $section['policy_text'] );
			}
		}
		$this->assertTrue( $found );
	}
}
