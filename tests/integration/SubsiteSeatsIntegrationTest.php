<?php
/**
 * License contract for add-ons on a customer's multisite network (docs/MULTISITE.md,
 * "Add-ons on a customer network"; owner decision B2: every subsite counts as a site;
 * task WF-MS-25 of wp-workflowmaster, TODO WFC-LIC-03).
 *
 * The first test speaks to the REST API with the addresses of a network and runs in every
 * cell. The second one runs the bundled client on real subsites and needs the multisite cell.
 *
 * @package WPLC
 */

use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;

require_once __DIR__ . '/ClientServerBridge.php';

final class SubsiteSeatsIntegrationTest extends WPLC_Client_Bridge_TestCase {

	/**
	 * Site the license server runs on (the bridge answers there, whatever subsite the client acts on).
	 *
	 * @var int
	 */
	private $server_blog = 0;

	/**
	 * Public key of the server as the add-on pins it (read on the server site).
	 *
	 * @var string
	 */
	private $pinned_key = '';

	public function set_up(): void {
		parent::set_up();
		$this->server_blog = (int) get_current_blog_id();
		$this->pinned_key  = $this->server_public_key();
	}

	/**
	 * The client of a subsite calls the shop over HTTP; the shop answers with its own tables.
	 *
	 * @param mixed               $pre  Short-circuit value.
	 * @param array<string,mixed> $args Request arguments.
	 * @param string              $url  URL.
	 * @return mixed
	 */
	public function bridge( $pre, $args, $url ) {
		if ( ! is_multisite() || (int) get_current_blog_id() === $this->server_blog ) {
			return parent::bridge( $pre, $args, $url );
		}
		switch_to_blog( $this->server_blog );
		try {
			return parent::bridge( $pre, $args, $url );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Manager of an add-on with the default storage (per site, `network => false`).
	 *
	 * @return LicenseManager
	 */
	private function manager(): LicenseManager {
		return new LicenseManager(
			array(
				'product_key' => 'test-product',
				'version'     => '1.0.0',
				'name'        => 'Test Product Pro',
				'server_url'  => 'https://license.test',
				'public_keys' => array( $this->pinned_key ),
			)
		);
	}

	/**
	 * Active activation rows of a license, oldest first (read on the server site).
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function seats( int $license_id ): array {
		global $wpdb;

		$switched = is_multisite() && (int) get_current_blog_id() !== $this->server_blog;
		if ( $switched ) {
			switch_to_blog( $this->server_blog );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, site_url, installation_uuid, environment FROM {$this->tables()['activations']} WHERE license_id = %d AND status = 'active' ORDER BY id ASC", $license_id ), ARRAY_A );
		if ( $switched ) {
			restore_current_blog();
		}

		return $rows;
	}

	public function test_the_server_counts_every_subsite_address_and_never_the_network_root(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 3 ) );

		// Main site, a subdirectory subsite and a subdomain subsite of one network: three sites.
		$main = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://kundennetz.example.com/' ) );
		$sub  = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://kundennetz.example.com/zwei/' ) );
		$dom  = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://drei.kundennetz.example.com' ) );
		foreach ( array( $main, $sub, $dom ) as $index => $site ) {
			$this->assertSame( 200, $site['response']->get_status() );
			$this->assertSame( $index + 1, $site['response']->get_data()['data']['site_count'] );
		}
		$this->assertCount( 3, $this->seats( $license['id'] ) );

		// A fourth subsite of the same network is refused: being part of an activated network gives no seat.
		$fourth = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://kundennetz.example.com/vier/' ) )['response'];
		$this->assertApiError( 409, 'site_limit', $fourth );
		$this->assertSame( array( 3, 3 ), array( $fourth->get_data()['data']['site_limit'], $fourth->get_data()['data']['site_count'] ) );

		// Mapped domain of the subsite: the same installation moves, the seat and the activation stay.
		$sub_id = (int) $sub['response']->get_data()['data']['activation_id'];
		$moved  = $this->activate_via_api(
			$license['key'],
			array(
				'site_url'            => 'https://shop-zwei.example.com',
				'installation_id'     => $sub['uuid'],
				'installation_secret' => $sub['secret'],
			)
		)['response'];
		$this->assertSame( 200, $moved->get_status() );
		$this->assertSame( $sub_id, (int) $moved->get_data()['data']['activation_id'] );
		$this->assertSame( 3, $moved->get_data()['data']['site_count'], 'A mapped domain does not count twice.' );
		$this->assertSame( 'https://shop-zwei.example.com', $this->activation( $sub_id )['site_url'] );
		$this->assertCount( 3, $this->seats( $license['id'] ) );

		// The subsite gives its seat back; now the fourth subsite gets it.
		$release = $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $sub_id, array( 'site_url' => 'https://shop-zwei.example.com' ), $sub ) );
		$this->assertSame( 200, $release->get_status() );
		$this->assertCount( 2, $this->seats( $license['id'] ) );
		$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'site_url' => 'https://kundennetz.example.com/vier/' ) )['response']->get_status() );
		$this->assertSame(
			array( 'https://kundennetz.example.com', 'https://drei.kundennetz.example.com', 'https://kundennetz.example.com/vier' ),
			array_column( $this->seats( $license['id'] ), 'site_url' )
		);
	}

	public function test_each_subsite_of_a_network_activates_by_itself_with_the_bundled_client(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network (WP_MULTISITE=1).' );
		}
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2, 'feature.export' => true ) );
		$blogs   = array();
		foreach ( array( 'zwei', 'drei', 'vier' ) as $slug ) {
			$blogs[ $slug ] = (int) self::factory()->blog->create( array( 'domain' => 'example.org', 'path' => '/wplc-seat-' . $slug . '/' ) );
			$this->assertGreaterThan( 0, $blogs[ $slug ] );
			update_blog_option( $blogs[ $slug ], 'home', 'https://kundennetz.example.com/' . $slug );
		}

		// Two subsites activate the same key: two installations, two addresses, two seats.
		$installations = array();
		foreach ( array( 'zwei', 'drei' ) as $slug ) {
			switch_to_blog( $blogs[ $slug ] );
			$manager = $this->manager();
			$status  = $manager->activate( $license['key'] );
			$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
			$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
			$this->assertTrue( $status->is_entitled( 'feature.export' ) );
			$installations[ $slug ] = $manager->client()->installation_id();
			restore_current_blog();
		}
		$this->assertNotSame( $installations['zwei'], $installations['drei'] );
		$seats = $this->seats( $license['id'] );
		$this->assertSame( array( 'https://kundennetz.example.com/zwei', 'https://kundennetz.example.com/drei' ), array_column( $seats, 'site_url' ) );
		$this->assertSame( array( $installations['zwei'], $installations['drei'] ), array_column( $seats, 'installation_uuid' ) );

		// The site of the network the server runs on (and the network itself) holds nothing.
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $this->manager()->status()->state );

		// A third subsite is over the limit and stays without access.
		switch_to_blog( $blogs['vier'] );
		$refused = $this->manager()->activate( $license['key'] );
		$this->assertWPError( $refused );
		$this->assertSame( 'site_limit', $refused->get_error_code() );
		$this->assertSame( array( 2, 2 ), array( $refused->get_error_data()['site_limit'], $refused->get_error_data()['site_count'] ) );
		$this->assertFalse( $this->manager()->status()->is_entitled( 'feature.export' ) );
		restore_current_blog();

		// Mapped domain for the second subsite: no access until it moves; the move keeps installation and seat.
		update_blog_option( $blogs['zwei'], 'home', 'https://shop-zwei.example.com' );
		switch_to_blog( $blogs['zwei'] );
		$manager = $this->manager();
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $manager->status()->state );
		$this->assertFalse( $manager->status()->is_entitled( 'feature.export' ), 'No premium function under the new address before the move.' );
		$status = $manager->move( $license['key'] );
		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( $installations['zwei'], $manager->client()->installation_id() );
		restore_current_blog();
		$seats = $this->seats( $license['id'] );
		$this->assertSame( array( 'https://shop-zwei.example.com', 'https://kundennetz.example.com/drei' ), array_column( $seats, 'site_url' ), 'Still two seats; the mapped domain replaced the old address.' );

		// The other subsite is untouched by all of this.
		switch_to_blog( $blogs['drei'] );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $this->manager()->refresh()->state );
		// It removes its license; its seat is free for the third subsite.
		$this->manager()->deactivate();
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $this->manager()->status()->state );
		restore_current_blog();
		$this->assertCount( 1, $this->seats( $license['id'] ) );

		switch_to_blog( $blogs['vier'] );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $this->manager()->activate( $license['key'] )->state );
		restore_current_blog();
		$this->assertSame( array( 'https://shop-zwei.example.com', 'https://kundennetz.example.com/vier' ), array_column( $this->seats( $license['id'] ), 'site_url' ) );
	}
}
