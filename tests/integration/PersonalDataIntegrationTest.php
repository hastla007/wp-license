<?php
use WPLC\Infrastructure\Database\Schema;
use WPLC\Privacy\PersonalData;

final class PersonalDataIntegrationTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
	}

	public function test_export_includes_license_details_but_excludes_secrets(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'user_email' => 'privacy-export@example.test' ) );
		$license_id = $this->create_license( $user_id, 'active' );
		$this->create_activation( $license_id, 'active' );

		$result = PersonalData::export_personal_data( 'privacy-export@example.test', 1 );
		$serialized = wp_json_encode( $result );

		$this->assertFalse( $result['done'] === false && empty( $result['data'] ) );
		$this->assertStringContainsString( 'privacy-product', $serialized );
		$this->assertStringContainsString( 'private-shop.example.test', $serialized );
		$this->assertStringNotContainsString( 'plaintext-license-secret', $serialized );
		$this->assertStringNotContainsString( 'encrypted-installation-secret', $serialized );
		$this->assertStringNotContainsString( 'payment-token-secret', $serialized );
		$this->assertSame( 1, count( $result['data'] ) );
		$this->assertStringNotContainsString( 'Announced renewal amount', $serialized, 'Nothing is announced for this license.' );

		// INH-11: an announced renewal price is part of the export.
		$wpdb->update(
			Schema::table_names()['licenses'],
			array(
				'renewal_amount_minor'      => 4900,
				'price_change_amount_minor' => 5900,
				'price_change_effective_at' => '2027-03-01 00:00:00',
				'price_change_announced_at' => '2026-11-16 10:00:00',
				'price_change_notified_at'  => '2026-11-16 10:00:05',
				'price_change_notice_days'  => 60,
			),
			array( 'id' => $license_id )
		);
		$fields = array();
		foreach ( PersonalData::export_personal_data( 'privacy-export@example.test', 1 )['data'][0]['data'] as $field ) {
			$fields[ $field['name'] ] = $field['value'];
		}
		$this->assertStringStartsWith( '59.00 ', $fields['Announced renewal amount'] );
		$this->assertSame( '2027-03-01 00:00:00', $fields['Announced amount applies from'] );
		$this->assertSame( '2026-11-16 10:00:00', $fields['Price change announced'] );
		$this->assertSame( '2026-11-16 10:00:05', $fields['Price change notice sent'] );
	}

	/**
	 * INH-11: a license detached from its owner keeps no announcement; a kept license keeps it.
	 */
	public function test_erasure_and_retention_clear_an_announced_price_with_the_owner(): void {
		global $wpdb;

		$tables  = Schema::table_names();
		$email   = 'privacy-price@example.test';
		$user_id = self::factory()->user->create( array( 'user_email' => $email ) );
		$pending = array(
			'renewal_amount_minor'      => 4900,
			'price_change_amount_minor' => 5900,
			'price_change_effective_at' => '2027-03-01 00:00:00',
			'price_change_announced_at' => '2026-11-16 10:00:00',
			'price_change_notified_at'  => '2026-11-16 10:00:05',
			'price_change_notice_days'  => 60,
		);
		$erased  = $this->create_license( $user_id, 'expired' );
		$kept    = $this->create_license( $user_id, 'active' );
		$aged    = $this->create_license( self::factory()->user->create(), 'expired' );
		foreach ( array( $erased, $kept, $aged ) as $id ) {
			$wpdb->update( $tables['licenses'], $pending, array( 'id' => $id ) );
		}
		$columns = static function ( int $id ) use ( $wpdb, $tables ): array {
			return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT price_change_amount_minor, price_change_effective_at, price_change_announced_at, price_change_notified_at, price_change_notice_days, renewal_amount_minor FROM %i WHERE id = %d', $tables['licenses'], $id ), ARRAY_A );
		};
		$cleared = array(
			'price_change_amount_minor' => null,
			'price_change_effective_at' => null,
			'price_change_announced_at' => null,
			'price_change_notified_at'  => null,
			'price_change_notice_days'  => null,
			'renewal_amount_minor'      => '4900',
		);

		PersonalData::erase_personal_data( $email, 1 );
		$this->assertSame( $cleared, $columns( $erased ), 'Erased with the owner; the agreed amount is no personal data and stays like the other license fields.' );
		$this->assertSame( '5900', $columns( $kept )['price_change_amount_minor'], 'A license that stays with the customer keeps its announcement.' );

		// The retention rule that anonymizes long-ended licenses treats the columns the same way.
		$old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$wpdb->update( $tables['licenses'], array( 'paid_until' => $old, 'updated_at' => $old ), array( 'id' => $aged ) );
		$days = static function ( $rules ) {
			$rules['anonymize_licenses'] = 365;
			return $rules;
		};
		add_filter( 'wplc_retention_days', $days );
		\WPLC\Maintenance\Retention::run();
		remove_filter( 'wplc_retention_days', $days );
		$this->assertSame( $cleared, $columns( $aged ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE id = %d', $tables['licenses'], $aged ) ) );

		// A deleted account: the license runs on without an owner and without the announcement made to that owner.
		wp_delete_user( $user_id );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE id = %d', $tables['licenses'], $kept ) ) );
		$this->assertSame( $cleared, $columns( $kept ) );
	}

	public function test_erasure_detaches_terminal_license_and_preserves_active_license(): void {
		global $wpdb;

		$email = 'privacy-erase@example.test';
		$user_id = self::factory()->user->create( array( 'user_email' => $email ) );
		$terminal_id = $this->create_license( $user_id, 'expired' );
		$terminal_activation_id = $this->create_activation( $terminal_id, 'inactive' );
		$active_id = $this->create_license( $user_id, 'active' );
		$active_activation_id = $this->create_activation( $active_id, 'active' );

		$result = PersonalData::erase_personal_data( $email, 1 );
		$tables = Schema::table_names();

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM {$tables['licenses']} WHERE id = %d", $terminal_id ) ) );
		$this->assertSame( $user_id, (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM {$tables['licenses']} WHERE id = %d", $active_id ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d", $terminal_activation_id ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d AND status = %s", $active_activation_id, 'active' ) ) );
	}

	private function create_license( int $customer_id, string $status ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['licenses'],
			array(
				'license_uuid' => wp_generate_uuid4(),
				'license_key_hash' => hash( 'sha256', wp_generate_uuid4() ),
				'license_key_secret_id' => 'integration-2026',
				'key_prefix' => 'WPLC-PRIVACY',
				'customer_id' => $customer_id,
				'product_key' => 'privacy-product',
				'plan_key' => 'privacy-plan',
				'status' => $status,
				'billing_interval' => 'year',
				'starts_at' => $now,
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'source_order_id' => null,
				'source_order_item_id' => null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function create_activation( int $license_id, string $status ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['activations'],
			array(
				'license_id' => $license_id,
				'installation_uuid' => wp_generate_uuid4(),
				'installation_secret_hash' => hash( 'sha256', 'plaintext-license-secret' ),
				'installation_secret_encrypted' => 'encrypted-installation-secret',
				'site_url' => 'https://private-shop.example.test',
				'environment' => 'production',
				'product_version' => '9.9.9',
				'wp_version' => '6.8',
				'php_version' => PHP_VERSION,
				'status' => $status,
				'activated_at' => $now,
				'last_seen_at' => $now,
				'deactivated_at' => 'inactive' === $status ? $now : null,
			)
		);
		return (int) $wpdb->insert_id;
	}
}
