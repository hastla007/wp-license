<?php
/**
 * PK-05: a project connector with its own copy of the client library next to a
 * Pro add-on on the same website.
 *
 * The Pro add-on uses the library of this repository (namespace WPLC\Client),
 * the connector the copy vendored into the starter (namespace
 * Vendor\Product\Premium\Client), as a connector built from the starter or
 * exported by captain-pro would. Both talk to the real REST server of this
 * process (see ClientServerBridge.php).
 *
 * @package WPLC
 */

use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;
use WPLC\License\LicenseLifecycle;

require_once __DIR__ . '/ClientServerBridge.php';

final class ProjectConnectorClientIntegrationTest extends WPLC_Client_Bridge_TestCase {

	const PRO       = 'captain-pro';
	const CONNECTOR = 'harbour-connector';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$starter = dirname( __DIR__, 2 ) . '/examples/premium-addon-starter/src/Client/';
		foreach ( array( 'OptionStore.php', 'Messages.php', 'LicenseClient.php' ) as $file ) {
			require_once $starter . $file;
		}
	}

	/**
	 * Manager of the Pro add-on.
	 *
	 * @return LicenseManager
	 */
	private function pro(): LicenseManager {
		return new LicenseManager(
			array(
				'product_key' => self::PRO,
				'version'     => '1.0.0',
				'name'        => 'Captain Pro',
				'server_url'  => 'https://license.test',
				'public_keys' => array( $this->server_public_key() ),
			)
		);
	}

	/**
	 * Client of the connector: its own copy of the library, its own options.
	 *
	 * @return \Vendor\Product\Premium\Client\LicenseClient
	 */
	private function connector() {
		return new \Vendor\Product\Premium\Client\LicenseClient(
			'https://license.test',
			self::CONNECTOR,
			'0.3.0',
			'harbour_connector_license',
			array( 'public_keys' => array( $this->server_public_key() ) )
		);
	}

	/**
	 * Active activations of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function active( int $license_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tables()['activations']} WHERE license_id = %d AND status = 'active'", $license_id ), ARRAY_A );
	}

	public function test_pk05_connector_and_pro_addon_each_hold_their_own_license_on_one_site(): void {
		$this->assertNotSame( \WPLC\Client\LicenseClient::class, get_class( $this->connector() ), 'Fixture: the connector uses its own copy of the library.' );

		// One site place each: if both counted on one limit, the second activation would be refused.
		$pro_license       = $this->seed_license( array( 'product_key' => self::PRO ), array( 'sites.live_limit' => 1, 'pro.flows' => true ) );
		$connector_license = $this->seed_license(
			array(
				'product_key' => self::CONNECTOR,
				'plan_key'    => 'project',
			),
			array(
				'sites.live_limit' => 1,
				'harbour.sync'     => true,
			)
		);

		$pro    = $this->pro();
		$status = $pro->activate( $pro_license['key'] );
		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->assertSame( array( 1, 1 ), array( $status->site_limit, $status->site_count ) );

		$connector = $this->connector();
		$activated = $connector->activate( $connector_license['key'] );
		$this->assertIsArray( $activated, is_wp_error( $activated ) ? $activated->get_error_code() . ': ' . wp_json_encode( $activated->get_error_data() ) : '' );
		$this->assertSame( self::CONNECTOR, $activated['product_key'] );
		$this->assertSame( array( 1, 1 ), array( (int) $activated['site_limit'], (int) $activated['site_count'] ), 'The connector takes one place of its own limit.' );

		// A key of the other product is refused by each client.
		$this->assertSame( 'product_mismatch', $this->connector_for( 'harbour_connector_cross' )->activate( $pro_license['key'] )->get_error_code() );

		// Separate activations on the same site address.
		$pro_rows       = $this->active( $pro_license['id'] );
		$connector_rows = $this->active( $connector_license['id'] );
		$this->assertCount( 1, $pro_rows );
		$this->assertCount( 1, $connector_rows );
		$this->assertSame( $pro_rows[0]['site_url'], $connector_rows[0]['site_url'] );
		$this->assertNotSame( $pro_rows[0]['installation_uuid'], $connector_rows[0]['installation_uuid'] );
		$this->assertSame( $connector->installation_id(), $connector_rows[0]['installation_uuid'] );
		$this->assertSame( $pro->client()->installation_id(), $pro_rows[0]['installation_uuid'] );

		// Separate options, named after each product.
		$pro_credentials       = get_option( 'captain_pro_license' );
		$connector_credentials = get_option( 'harbour_connector_license' );
		$this->assertSame( self::PRO, $pro_credentials['product_key'] );
		$this->assertSame( self::CONNECTOR, $connector_credentials['product_key'] );
		$this->assertNotSame( $pro_credentials['installation_secret'], $connector_credentials['installation_secret'] );

		// Each gets its own entitlements.
		$this->assertTrue( $pro->is_entitled( 'pro.flows' ) );
		$this->assertFalse( $pro->is_entitled( 'harbour.sync' ) );
		$check = $connector->check_entitlements();
		$this->assertIsArray( $check );
		$this->assertSame( self::CONNECTOR, $check['product_key'] );
		$this->assertTrue( $check['access'] );
		$this->assertArrayNotHasKey( 'pro.flows', (array) $check['entitlements'] );

		// Revoking the Pro license leaves the connector valid, and the other way round.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( LicenseLifecycle::admin_set_status( $pro_license['id'], 'revoked', 'Test: refund outside the shop', $admin ) );
		$this->assertNotSame( LicenseStatus::STATE_ACTIVE, $pro->refresh()->state );
		$check = $connector->check_entitlements();
		$this->assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_code() : '' );
		$this->assertTrue( $check['access'], 'The connector keeps its license.' );
		$this->assertSame( 'active', $check['license_status'] );
		$this->assertCount( 1, $this->active( $connector_license['id'] ) );
		$this->assertSame( 'active', $this->license( $connector_license['id'] )['status'] );

		// A second connector client on another site would need a second place of the connector, not of Pro.
		$this->acting_home = 'https://second-harbour.example.com';
		$second            = $this->connector_for( 'harbour_connector_second' )->activate( $connector_license['key'] );
		$this->assertSame( 'site_limit', $second->get_error_code() );
	}

	/**
	 * Connector client with another option key (another installation).
	 *
	 * @param string $option_key Option key.
	 * @return \Vendor\Product\Premium\Client\LicenseClient
	 */
	private function connector_for( string $option_key ) {
		return new \Vendor\Product\Premium\Client\LicenseClient(
			'https://license.test',
			self::CONNECTOR,
			'0.3.0',
			$option_key,
			array( 'public_keys' => array( $this->server_public_key() ) )
		);
	}
}
