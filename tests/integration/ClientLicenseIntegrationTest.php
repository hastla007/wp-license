<?php
/**
 * The bundled client library (src/Client) against the real REST server of this process.
 *
 * See ClientServerBridge.php for what is real here and what the bridge stands in for.
 * Every test names the task it belongs to (docs/TODO.md).
 *
 * @package WPLC
 */

use WPLC\Client\LicenseClient;
use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;

require_once __DIR__ . '/ClientServerBridge.php';

final class ClientLicenseIntegrationTest extends WPLC_Client_Bridge_TestCase {

	/**
	 * Local clock of the customer site (injected into the manager).
	 *
	 * @var int
	 */
	private $clock = 0;

	public function set_up(): void {
		parent::set_up();
		$this->clock = time();
	}

	/**
	 * Manager of an add-on that is configured like a real one.
	 *
	 * @param array<string,mixed> $config Overrides.
	 * @return LicenseManager
	 */
	private function manager( array $config = array() ): LicenseManager {
		return new LicenseManager(
			array_merge(
				array(
					'product_key' => 'test-product',
					'version'     => '1.0.0',
					'name'        => 'Test Product Pro',
					'server_url'  => 'https://license.test',
					'public_keys' => array( $this->server_public_key() ),
					'clock'       => function () {
						return $this->clock;
					},
				),
				$config
			)
		);
	}

	/**
	 * Active activation rows of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_activations( int $license_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->tables()['activations']} WHERE license_id = %d AND status = 'active' ORDER BY id ASC", $license_id ), ARRAY_A );
	}

	// ------------------------------------------------------------------
	// The whole life of an activation.
	// ------------------------------------------------------------------

	public function test_t134_activate_check_update_check_and_deactivate_against_the_real_server(): void {
		$license = $this->seed_license();
		$manager = $this->manager();

		$status = $manager->activate( strtolower( $license['key'] ) . "\n" );

		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() . ': ' . wp_json_encode( $status->get_error_data() ) : '' );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertTrue( $manager->is_entitled( 'feature.export' ) );
		$this->assertFalse( $manager->is_entitled( 'feature.other' ) );
		$this->assertSame( 'production', $status->environment );
		$this->assertSame( array( 1, 1 ), array( $status->site_limit, $status->site_count ) );
		$this->assertNotSame( '', $status->cache_expires_at );

		$rows = $this->active_activations( $license['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( $manager->client()->installation_id(), $rows[0]['installation_uuid'] );
		$this->assertSame( untrailingslashit( home_url() ), $rows[0]['site_url'] );
		$this->assertSame( '1.0.0', $rows[0]['product_version'] );

		// The stored credentials: site and environment are kept, the key is not.
		$credentials = get_option( 'test_product_license' );
		$this->assertSame( array( 'installation_id', 'installation_secret', 'activation_id', 'license_key_hash', 'product_key', 'site_url', 'environment', 'activated_at', 'clock_offset' ), array_keys( $credentials ) );
		$this->assertSame( (int) $rows[0]['id'], $credentials['activation_id'] );
		$this->assertStringNotContainsString( $license['key'], (string) wp_json_encode( array( $credentials, get_option( 'test_product_license_state' ) ) ) );
		// B-16: a non-autoloaded option, not a transient.
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( 'test_product_license_state', wp_load_alloptions() );
		$this->assertArrayNotHasKey( 'test_product_license', wp_load_alloptions() );
		$this->assertFalse( get_transient( 'test_product_license_state' ) );

		// The snapshot is the answer of the real server and carries its Ed25519 signature.
		$envelope = get_option( 'test_product_license_state' )['envelope'];
		$this->assertTrue( \WPLC\Security\ResponseSigner::verify( \WPLC\Security\ResponseSigner::payload_message( $credentials['installation_id'], $envelope['payload'] ), $envelope['payload_signature_ed25519'], $this->server_public_key() ) );

		// Signed entitlement check ("Check now"): sends the site address, gets site_match.
		$this->http_log = array();
		$status         = $manager->refresh();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check 200' ), $this->http_routes() );
		$this->assertSame( home_url(), $this->http_log[0]['body']['site_url'] );
		$snapshot = json_decode( get_option( 'test_product_license_state' )['envelope']['payload'], true )['data'];
		$this->assertTrue( $snapshot['site_match'] );
		$this->assertSame( 'test-product', $snapshot['product_key'] );
		$this->assertTrue( $snapshot['access'] );

		// Activating again reuses the installation.
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->activate( $license['key'] )->state );
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );

		// Periodic update check: no release yet, and no download grant is created.
		$update = $manager->client()->check_update();
		$this->assertIsArray( $update );
		$this->assertFalse( $update['update_available'] );
		$this->assertTrue( $update['license_active'] );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['download_tokens']}" ) );

		// Deactivate: signed DELETE with the site address; credentials and snapshot are removed.
		$this->http_log = array();
		$result         = $manager->deactivate();
		$this->assertSame( 'deactivated', $result['status'] );
		$this->assertSame( array( 'DELETE /wplc/v1/activations/' . $rows[0]['id'] . ' 200' ), $this->http_routes() );
		$this->assertSame( array(), $this->active_activations( $license['id'] ) );
		$this->assertFalse( get_option( 'test_product_license', false ) );
		$this->assertFalse( get_option( 'test_product_license_state', false ) );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
	}

	public function test_t134_a_client_that_pins_another_key_rejects_the_real_server(): void {
		$license = $this->seed_license();
		$pair    = sodium_crypto_sign_keypair();
		$manager = $this->manager( array( 'public_keys' => array( base64_encode( sodium_crypto_sign_publickey( $pair ) ) ) ) );

		$result = $manager->activate( $license['key'] );

		$this->assertWPError( $result );
		$this->assertSame( 'wplc_server_signature_invalid', $result->get_error_code() );
		$this->assertFalse( $manager->client()->is_activated() );
		// The server did create the activation; the pending installation lets a fixed add-on pick it up.
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );
		$pending = get_option( 'test_product_license_pending' );
		$this->assertSame( $this->active_activations( $license['id'] )[0]['installation_uuid'], $pending['installation_id'] );

		// A-31: after a key rotation the add-on pins the old and the new key.
		$rotated = $this->manager( array( 'public_keys' => array( base64_encode( sodium_crypto_sign_publickey( $pair ) ), $this->server_public_key() ) ) );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $rotated->activate( $license['key'] )->state );
		$this->assertCount( 1, $this->active_activations( $license['id'] ), 'B-71: the same installation, not a second one.' );
		$this->assertSame( $pending['installation_id'], $rotated->client()->installation_id() );
	}

	// ------------------------------------------------------------------
	// B-72: refusals of the real server arrive with code and data.
	// ------------------------------------------------------------------

	public function test_b72_refusals_of_the_real_server(): void {
		$past  = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$cases = array(
			'license_expired'   => array( 'status' => 'expired', 'paid_until' => $past ),
			'license_suspended' => array( 'status' => 'suspended' ),
			'license_revoked'   => array( 'status' => 'revoked' ),
			'license_inactive'  => array( 'status' => 'pending' ),
		);
		foreach ( $cases as $code => $overrides ) {
			$license = $this->seed_license( $overrides );
			$result  = $this->manager( array( 'option_prefix' => 'refused_' . $code ) )->activate( $license['key'] );
			$this->assertWPError( $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( $overrides['status'], $result->get_error_data()['license_status'] );
			$this->assertFalse( get_option( 'refused_' . $code . '_license', false ) );
			$this->assertFalse( get_option( 'refused_' . $code . '_license_pending', false ), 'No pending installation after a definite refusal.' );
		}

		$result = $this->manager()->activate( 'WPLC-0000-0000-0000-0000-0000-0000' );
		$this->assertSame( 'invalid_key', $result->get_error_code() );

		$other = $this->seed_license( array( 'product_key' => 'other-product' ) );
		$this->assertSame( 'product_mismatch', $this->manager()->activate( $other['key'] )->get_error_code() );

		// site_limit with its numbers: a second site on a one-site license.
		$license = $this->seed_license();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $this->manager()->activate( $license['key'] )->state );
		$this->acting_home = 'https://second-shop.example.com';
		$result            = $this->manager( array( 'option_prefix' => 'second_site' ) )->activate( $license['key'], 'production' );
		$this->assertSame( 'site_limit', $result->get_error_code() );
		$this->assertSame( array( 1, 1 ), array( $result->get_error_data()['site_limit'], $result->get_error_data()['site_count'] ) );

		// development on a public host.
		$result = $this->manager( array( 'option_prefix' => 'dev_site' ) )->activate( $license['key'], 'development' );
		$this->assertSame( 'development_site_invalid', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// B-70: signed states of the real server lock, unsigned errors do not.
	// ------------------------------------------------------------------

	public function test_b70_signed_license_states_of_the_real_server(): void {
		global $wpdb;
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );

		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'suspended' ), array( 'id' => $license['id'] ) );
		$status = $manager->refresh();
		$this->assertSame( LicenseStatus::STATE_SUSPENDED, $status->state );
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
		$this->assertTrue( $manager->client()->is_activated() );

		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'active' ), array( 'id' => $license['id'] ) );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
		$this->assertTrue( $manager->is_entitled( 'feature.export' ) );

		// Grace period: payment overdue, access until grace_until.
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'status'     => 'past_due',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			),
			array( 'id' => $license['id'] )
		);
		$status = $manager->refresh();
		$this->assertSame( LicenseStatus::STATE_GRACE, $status->state );
		$this->assertTrue( $status->access );
		$this->assertNotSame( '', $status->grace_until );
		$this->assertSame( $status->access_until, $status->cache_expires_at, 'B-68: the offline window ends with the access, here in 12 days.' );
		$this->assertSame( 'grace', $manager->notice()['state'] );

		// Expired for good.
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			),
			array( 'id' => $license['id'] )
		);
		$status = $manager->refresh();
		$this->assertSame( LicenseStatus::STATE_EXPIRED, $status->state );
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
	}

	public function test_b70_release_in_the_customer_account_reaches_the_site_as_signed_refusal(): void {
		global $wpdb;
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );
		$row = $this->active_activations( $license['id'] )[0];

		// The customer releases the site in the account (server side).
		$wpdb->update(
			$this->tables()['activations'],
			array(
				'status'         => 'deactivated',
				'deactivated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $row['id'] )
		);

		// Any signed route: the update check answers 403 with a signed envelope.
		$update = $manager->client()->check_update();
		$this->assertWPError( $update );
		$this->assertSame( 'activation_deactivated', $update->get_error_code() );
		$this->assertTrue( LicenseClient::is_signed_refusal( $update ), 'The refusal of the real server verifies against its pinned key.' );
		$this->assertFalse( $manager->client()->is_activated() );
		// The snapshot is void without its credentials: nothing keeps running.
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
	}

	public function test_b70_released_installation_gets_signed_deactivated_on_check_and_can_activate_again(): void {
		global $wpdb;
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );
		$first = $manager->client()->installation_id();
		$wpdb->update(
			$this->tables()['activations'],
			array(
				'status'         => 'deactivated',
				'deactivated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'installation_uuid' => $first )
		);

		$status = $manager->refresh();

		$this->assertSame( LicenseStatus::STATE_DEACTIVATED, $status->state );
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertSame( 'deactivated', $manager->notice()['state'] );

		$status = $manager->activate( $license['key'] );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertNotSame( $first, $manager->client()->installation_id() );
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );
	}

	public function test_a110_unknown_installation_keeps_the_cache_and_asks_for_the_key(): void {
		global $wpdb;
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );

		// The activation row is gone (retention long after a release, restored server backup).
		$wpdb->delete( $this->tables()['activations'], array( 'license_id' => $license['id'] ) );

		$result = $manager->refresh();
		$this->assertSame( 'activation_not_found', $result->get_error_code() );
		$this->assertSame( LicenseManager::CLASS_REACTIVATE, LicenseManager::error_class( $result ) );
		$this->assertTrue( $manager->is_entitled( 'feature.export' ), 'An unsigned 401 is no proof that the license ended.' );
		$this->assertTrue( $manager->status()->needs_reactivation );

		// "Remove license": the server knows nothing, the local data goes.
		$result = $manager->deactivate();
		$this->assertSame( 'removed_locally', $result['status'] );
		$this->assertFalse( $manager->client()->is_activated() );

		// Entering the key creates a new installation.
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->activate( $license['key'] )->state );
	}

	public function test_b18_firewall_page_and_outage_keep_the_rights_until_the_signed_deadline(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$status  = $manager->activate( $license['key'] );
		$expires = LicenseManager::utc( $status->cache_expires_at );

		$this->http_faults[] = $this->http_response( 403, '<!DOCTYPE html><title>Just a moment...</title>', array( 'content-type' => 'text/html; charset=UTF-8' ) );
		$this->assertSame( 'wplc_blocked_by_firewall', $manager->refresh()->get_error_code() );
		$this->http_faults[] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( 'wplc_connection_failed', $manager->refresh()->get_error_code() );
		$this->assertTrue( $manager->is_entitled( 'feature.export' ) );

		$this->clock = $expires - 1;
		$this->assertTrue( $manager->is_entitled( 'feature.export' ) );
		$this->clock = $expires + 1;
		$this->assertFalse( $manager->is_entitled( 'feature.export' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
	}

	public function test_a109_request_with_a_wrong_clock_is_corrected_by_the_real_server(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );

		// The clock of the customer server jumps two hours back.
		$this->clock    = time() - 2 * HOUR_IN_SECONDS;
		$this->http_log = array();

		$status = $manager->refresh();

		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check 401', 'POST /wplc/v1/entitlements/check 200' ), $this->http_routes() );
		$this->assertEqualsWithDelta( 2 * HOUR_IN_SECONDS, get_option( 'test_product_license' )['clock_offset'], 5 );
	}

	// ------------------------------------------------------------------
	// A-34, B-69, B-163, B-164: copies, staging, moves, key change.
	// ------------------------------------------------------------------

	public function test_b69_cloned_site_cannot_end_the_live_activation_and_becomes_its_staging_site(): void {
		$license = $this->seed_license();
		$live    = $this->manager();
		$live->activate( $license['key'] );
		$live_id = $live->client()->installation_id();

		// The database is copied to a staging host: same options, other address.
		$this->acting_home = 'https://staging.example.org';
		$copy              = $this->manager();
		$this->http_log    = array();

		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $copy->status()->state );
		$this->assertFalse( $copy->is_entitled( 'feature.export' ) );
		$this->assertSame( 'wplc_site_changed', $copy->refresh()->get_error_code() );
		$this->assertSame( 'wplc_site_changed', $copy->client()->check_update()->get_error_code() );
		$this->assertSame( array(), $this->http_log, 'The copy does not talk to the server with the copied credentials.' );

		// Even a hand-made DELETE from the copy with the copied credentials is refused by the server (its side of B-69).
		$credentials = get_option( 'test_product_license' );
		$response    = $this->dispatch(
			$this->api_request(
				'DELETE',
				'/wplc/v1/activations/' . $credentials['activation_id'],
				array( 'site_url' => 'https://staging.example.org' ),
				array(
					'uuid'   => $credentials['installation_id'],
					'secret' => $credentials['installation_secret'],
				)
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'site_mismatch', $response->get_data()['code'] );
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );

		// The customer enters the key on the copy; the environment is staging.
		$status = $copy->activate( $license['key'], 'staging' );
		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( 'staging', $status->environment );
		$this->assertNotSame( $live_id, $copy->client()->installation_id() );

		$rows = $this->active_activations( $license['id'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'production', 'staging' ), array_column( $rows, 'environment' ) );
		$this->assertSame( (int) $rows[0]['id'], (int) $rows[1]['staging_parent_id'] );
		$this->assertSame( 1, $status->site_count, 'The staging site does not use a live slot.' );
	}

	public function test_b164_moved_site_transfers_its_activation(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );
		$installation = $manager->client()->installation_id();

		$this->acting_home = 'https://new-domain.example.com';
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $manager->status()->state );

		$status = $manager->move( $license['key'] );

		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( $installation, $manager->client()->installation_id() );
		$rows = $this->active_activations( $license['id'] );
		$this->assertCount( 1, $rows, 'Still one site.' );
		$this->assertSame( 'https://new-domain.example.com', $rows[0]['site_url'] );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
	}

	public function test_b163_changing_the_key_frees_the_slot_of_the_old_license(): void {
		$old     = $this->seed_license();
		$new     = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $old['key'] );

		$status = $manager->activate( $new['key'] );

		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( array(), $this->active_activations( $old['id'] ) );
		$this->assertCount( 1, $this->active_activations( $new['id'] ) );
	}

	public function test_a108_delete_through_a_method_override(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );
		$this->http_log = array();

		// The host of the customer refuses the DELETE method.
		$this->http_faults[] = $this->http_response( 405, '<html>Method Not Allowed</html>', array( 'content-type' => 'text/html' ) );
		$result              = $manager->deactivate();

		$this->assertSame( 'deactivated', $result['status'] );
		$this->assertSame( array( 'DELETE', 'POST' ), array_column( $this->http_log, 'method' ) );
		$this->assertSame( array(), $this->active_activations( $license['id'] ) );
	}

	// ------------------------------------------------------------------
	// B-28: multisite decision; B-172: uninstall; admin form and cron.
	// ------------------------------------------------------------------

	public function test_b172_uninstall_releases_the_activation_and_removes_everything(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->activate( $license['key'] );
		$manager->ensure_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'test_product_license_check' ) );
		$manager->client()->check_update();

		$manager->uninstall();

		$this->assertSame( array(), $this->active_activations( $license['id'] ) );
		foreach ( array( 'test_product_license', 'test_product_license_state', 'test_product_license_pending', 'test_product_update_meta' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
		$this->assertFalse( wp_next_scheduled( 'test_product_license_check' ) );

		// With an unreachable server the local data is removed all the same (the slot stays in use, as documented).
		$manager->activate( $license['key'] );
		$this->http_faults[] = new WP_Error( 'http_request_failed', 'down' );
		$manager->uninstall();
		$this->assertFalse( get_option( 'test_product_license', false ) );
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );
	}

	public function test_settings_form_notices_cron_and_catch_up_in_wordpress(): void {
		$license = $this->seed_license();
		$manager = $this->manager();
		$manager->register();
		$changes = array();
		add_action(
			'test_product_license_status_changed',
			static function ( $state, $before ) use ( &$changes ) {
				$changes[] = $before . '>' . $state;
			},
			10,
			2
		);
		$this->assertNotFalse( has_action( 'test_product_license_check' ) );
		$this->assertNotFalse( has_action( 'admin_post_test_product_license' ) );

		// A subscriber may not use the form.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$outcome = $this->run_admin_post( 'test_product_license', array() );
		$this->assertSame( array( 'die', 403 ), array( $outcome['outcome'], $outcome['status'] ) );
		ob_start();
		$manager->render_settings();
		$manager->render_notice();
		$this->assertSame( '', ob_get_clean() );

		// An administrator activates through the form.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
		ob_start();
		$manager->render_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-info', $notice );
		$this->assertStringContainsString( 'enter your license key', $notice );
		$this->assertStringContainsString( 'page=test-product-license', $notice );

		$outcome = $this->run_admin_post(
			'test_product_license',
			array(
				'_wpnonce'                 => wp_create_nonce( 'test_product_license' ),
				'wplc_do'                  => 'activate',
				'test_product_license_key' => $license['key'],
			)
		);
		$this->assertSame( 'redirect', $outcome['outcome'] );
		$this->assertStringContainsString( 'options-general.php?page=test-product-license', $outcome['location'] );
		$this->assertSame( array( 'unlicensed>active' ), $changes );
		$this->assertCount( 1, $this->active_activations( $license['id'] ) );

		ob_start();
		$manager->render_settings();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'The license was activated for this site.', $html );
		$this->assertStringContainsString( $manager->client()->installation_id(), $html );
		$this->assertStringContainsString( 'value="deactivate"', $html );
		$this->assertStringContainsString( 'value="check"', $html );
		$this->assertStringNotContainsString( $license['key'], $html, 'The key is never printed.' );
		$this->assertStringNotContainsString( get_option( 'test_product_license' )['installation_secret'], $html );
		ob_start();
		$manager->render_notice();
		$this->assertSame( '', ob_get_clean(), 'No notice for an active license.' );

		// Missing nonce.
		$outcome = $this->run_admin_post( 'test_product_license', array( 'wplc_do' => 'deactivate' ) );
		$this->assertSame( 'die', $outcome['outcome'] );
		$this->assertTrue( $manager->client()->is_activated() );

		// Daily check: scheduled by init, runs the signed check.
		$manager->ensure_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'test_product_license_check' ) );
		$this->http_log = array();
		do_action( 'test_product_license_check' );
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check 200' ), $this->http_routes() );

		// Catch-up: WP-Cron did not run for two days; the next admin page view of an administrator checks.
		$this->http_log = array();
		$manager->catch_up();
		$this->assertSame( array(), $this->http_log, 'Nothing to catch up right after a check.' );
		$state                  = get_option( 'test_product_license_state' );
		$state['last_attempt'] -= 2 * DAY_IN_SECONDS;
		update_option( 'test_product_license_state', $state, false );
		$manager->catch_up();
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check 200' ), $this->http_routes() );

		// Privacy guide entry.
		$this->assertStringContainsString( 'license.test', $manager->privacy_text() );

		// Deactivation through the form unschedules the check.
		$outcome = $this->run_admin_post(
			'test_product_license',
			array(
				'_wpnonce' => wp_create_nonce( 'test_product_license' ),
				'wplc_do'  => 'deactivate',
			)
		);
		$this->assertSame( 'redirect', $outcome['outcome'] );
		$this->assertSame( array(), $this->active_activations( $license['id'] ) );
		$this->assertFalse( wp_next_scheduled( 'test_product_license_check' ) );
		$this->assertSame( array( 'unlicensed>active', 'active>unlicensed' ), $changes );
	}

	public function test_b28_storage_follows_the_multisite_decision(): void {
		$store = new \WPLC\Client\OptionStore( true );
		if ( ! is_multisite() ) {
			$this->assertFalse( $store->is_network(), 'Outside multisite the network switch is ignored.' );
			$this->assertSame( home_url(), $store->site_url() );
		} else {
			$this->assertTrue( $store->is_network() );
			$this->assertSame( network_home_url(), $store->site_url() );
		}
		$this->assertTrue( $store->set( 'wplc_client_it_value', array( 'a' => 1 ) ) );
		$this->assertTrue( $store->set( 'wplc_client_it_value', array( 'a' => 1 ) ), 'Writing the same value again is not a failure.' );
		$this->assertSame( array( 'a' => 1 ), $store->get( 'wplc_client_it_value' ) );
		$store->delete( 'wplc_client_it_value' );
		$this->assertSame( 'gone', $store->get( 'wplc_client_it_value', 'gone' ) );

		$this->assertContains( LicenseClient::detect_environment(), array( 'production', 'staging', 'development' ) );
	}
}
