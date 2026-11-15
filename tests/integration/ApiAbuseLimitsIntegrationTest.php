<?php
/**
 * Abuse limits and request integrity of the public API (audit of 15 Nov 2026:
 * P-09, P-10, P-11, P-12, P-22, P-40, P-41, P-50).
 *
 * @package WPLC
 */

use WPLC\Api\V1\Authenticator;
use WPLC\Api\V1\UpdateService;
use WPLC\License\LicenseKey;

final class ApiAbuseLimitsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Data member of a signed 200 answer.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string,mixed>
	 */
	private function data_of( WP_REST_Response $response ): array {
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data()['data'];
	}

	/**
	 * Offer version 2.0.0 of test-product through the documented filter.
	 *
	 * @return void
	 */
	private function offer_package(): void {
		$package = array(
			'version'     => '2.0.0',
			'package_url' => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/downloads/test-product-2.0.0.zip',
			'sha256'      => str_repeat( 'ab', 32 ),
		);
		add_filter(
			'wplc_update_packages',
			static function ( $packages ) use ( $package ) {
				$packages['test-product'] = $package;
				return $packages;
			}
		);
	}

	/**
	 * Signed update check with download=true; returns the grant token or ''.
	 *
	 * @param array<string,mixed> $site    Installation from activate_via_api().
	 * @param string              $current Reported version.
	 * @return string
	 */
	private function grant_for( array $site, string $current = '1.0.0' ): string {
		$data = $this->data_of( $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => $current, 'download' => true ), $site ) ) );

		return preg_match( '#/updates/download/([a-f0-9]{64})$#', (string) ( $data['package_url'] ?? '' ), $match ) ? $match[1] : '';
	}

	/**
	 * Stored grant hashes.
	 *
	 * @return string[]
	 */
	private function grant_hashes(): array {
		global $wpdb;

		return $wpdb->get_col( "SELECT token_hash FROM {$this->tables()['download_tokens']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ------------------------------------------------------------------
	// P-09: the public key prefix no longer locks out the owner.
	// ------------------------------------------------------------------

	public function test_failures_on_a_known_prefix_from_one_address_do_not_block_the_owner_elsewhere(): void {
		$license = $this->seed_license();
		$guess   = substr( $license['key'], 0, 13 ) . substr( LicenseKey::for_uuid( wp_generate_uuid4() ), 13 );
		$minute  = gmdate( 'Y-m-d H:i' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.66';
		for ( $attempt = 1; $attempt <= 45; $attempt++ ) {
			$this->activate_via_api( $guess );
		}
		$blocked = $this->activate_via_api( $license['key'] )['response'];
		if ( gmdate( 'Y-m-d H:i' ) === $minute ) {
			// The attacking address itself is past its ceiling.
			$this->assertSame( 429, $blocked->get_status() );
		}

		// The owner, from another address, activates at once.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );
	}

	// ------------------------------------------------------------------
	// P-10: new installations per license and hour.
	// ------------------------------------------------------------------

	public function test_new_installations_per_license_and_hour_are_limited_but_known_ones_reactivate(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 10 ) );
		add_filter(
			'wplc_new_activations_per_hour',
			static function () {
				return 2;
			}
		);

		$first = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://one.example.com' ) );
		$this->assertSame( 200, $first['response']->get_status() );
		$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'site_url' => 'https://two.example.com' ) )['response']->get_status() );

		$third = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://three.example.com' ) )['response'];
		$this->assertApiError( 429, 'rate_limited', $third );
		$retry = (int) $third->get_data()['data']['retry_after'];
		$this->assertGreaterThan( 0, $retry );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $retry );
		$this->assertSame( (string) $retry, $third->get_headers()['Retry-After'] );

		// A known installation is never refused by this limit.
		$again = $this->activate_via_api(
			$license['key'],
			array(
				'installation_id'     => $first['uuid'],
				'installation_secret' => $first['secret'],
				'site_url'            => 'https://one.example.com',
			)
		);
		$this->assertSame( 200, $again['response']->get_status() );
	}

	// ------------------------------------------------------------------
	// P-11: download grants.
	// ------------------------------------------------------------------

	public function test_repeated_download_checks_reuse_grants_instead_of_adding_rows(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->offer_package();

		$tokens = array();
		for ( $check = 1; $check <= 5; $check++ ) {
			$tokens[] = $this->grant_for( $site );
		}

		$this->assertNotContains( '', $tokens );
		$this->assertCount( 5, array_unique( $tokens ), 'Every check returns a fresh value.' );
		$stored = $this->grant_hashes();
		$this->assertCount( UpdateService::MAX_OPEN_GRANTS, $stored );
		// The two latest values work, the older ones were replaced.
		$this->assertEqualsCanonicalizing( array( hash( 'sha256', $tokens[3] ), hash( 'sha256', $tokens[4] ) ), $stored );
	}

	public function test_a_lower_current_version_than_reported_creates_no_grant(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'product_version' => '2.0.0' ) );
		$this->offer_package();

		$data = $this->data_of( $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '0', 'download' => true ), $site ) ) );

		$this->assertFalse( $data['update_available'] );
		$this->assertSame( '', $data['package_url'] );
		$this->assertSame( array(), $this->grant_hashes() );

		// An explicit older version still works through target_version.
		$this->assertSame( '1.0.0', UpdateService::installed_version( '1.0.0', '' ) );
		$this->assertSame( '2.0.0', UpdateService::installed_version( '0', '2.0.0' ) );
		$this->assertSame( '2.1.0', UpdateService::installed_version( '2.1.0', '2.0.0' ) );
	}

	public function test_unused_grants_are_purged_as_soon_as_they_expire(): void {
		global $wpdb;
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->offer_package();
		$token = $this->grant_for( $site );
		$wpdb->update( $this->tables()['download_tokens'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		do_action( 'wplc_cleanup_security_rows' );

		$this->assertSame( array(), $this->grant_hashes() );
	}

	// ------------------------------------------------------------------
	// P-12: only the signed JSON body carries parameters.
	// ------------------------------------------------------------------

	public function test_signed_routes_refuse_query_parameters_and_other_content_types(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->offer_package();

		// A MITM keeps body and signature and adds parameters in the query string.
		$query = $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0', 'download' => false ), $site );
		$query->set_query_params( array( 'download' => '1', 'channel' => 'beta' ) );
		$this->assertApiError( 400, 'request_parameters_unsigned', $this->dispatch( $query ) );

		// ... or changes the content type so the JSON body is no longer read.
		$plain = $this->api_request( 'DELETE', '/wplc/v1/activations/' . (int) $site['response']->get_data()['data']['activation_id'], array( 'site_url' => 'https://elsewhere.example.com' ), $site );
		$plain->set_header( 'Content-Type', 'text/plain' );
		$this->assertApiError( 400, 'request_parameters_unsigned', $this->dispatch( $plain ) );
		$form = $this->api_request( 'POST', '/wplc/v1/entitlements/check', array( 'site_url' => 'https://elsewhere.example.com' ), $site );
		$form->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$this->assertApiError( 400, 'request_parameters_unsigned', $this->dispatch( $form ) );
		$this->assertSame( array(), $this->grant_hashes() );

		// Routing parameters of plain permalinks are allowed; the request is answered normally.
		$routed = $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site );
		$routed->set_query_params( array( 'rest_route' => '/wplc/v1/entitlements/check' ) );
		$this->assertSame( 200, $this->dispatch( $routed )->get_status() );

		// The site address of a signed deactivation is checked from the signed body.
		$id      = (int) $site['response']->get_data()['data']['activation_id'];
		$copy    = $this->api_request( 'DELETE', '/wplc/v1/activations/' . $id, array( 'site_url' => 'https://copy.example.com' ), $site );
		$this->assertApiError( 409, 'site_mismatch', $this->dispatch( $copy ) );
	}

	// ------------------------------------------------------------------
	// P-22: a live activation does not move to a staging address.
	// ------------------------------------------------------------------

	public function test_a_live_activation_does_not_move_to_a_staging_address(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];
		$repeat  = array(
			'installation_id'     => $site['uuid'],
			'installation_secret' => $site['secret'],
		);

		foreach ( array( 'https://staging.shop.example.com', 'http://shop.local', 'https://shop.wpengine.com' ) as $copy ) {
			$moved = $this->activate_via_api( $license['key'], array_merge( $repeat, array( 'site_url' => $copy ) ) )['response'];
			$this->assertApiError( 409, 'site_move_refused', $moved );
			$this->assertSame( 'https://shop.example.com', $moved->get_data()['data']['site_url'] );
		}
		$this->assertSame( 'https://shop.example.com', $this->activation( $id )['site_url'] );

		// A real move (including to a .dev domain) is still possible.
		$this->assertSame( 200, $this->activate_via_api( $license['key'], array_merge( $repeat, array( 'site_url' => 'https://new-shop.dev' ) ) )['response']->get_status() );
		$this->assertSame( 'https://new-shop.dev', $this->activation( $id )['site_url'] );
	}

	// ------------------------------------------------------------------
	// P-40, P-41: replay window, failures, uniform answers.
	// ------------------------------------------------------------------

	public function test_nonces_outlive_the_accepted_timestamp_window(): void {
		global $wpdb;
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'window-nonce-00000001' ) )->get_status() );

		$expires = strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT expires_at FROM {$this->tables()['request_nonces']} WHERE nonce_hash = %s", hash( 'sha256', 'window-nonce-00000001' ) ) ) . ' UTC' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertGreaterThan( time() + 2 * Authenticator::TIMESTAMP_WINDOW, $expires );

		// An expired row of the same nonce is replaced in the same statement; a live one is a replay.
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->tables()['request_nonces']} SET expires_at = %s", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'window-nonce-00000001' ) )->get_status() );
		$this->assertApiError( 409, 'request_replayed', $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'window-nonce-00000001' ) ) );
	}

	public function test_unknown_installation_and_wrong_signature_look_the_same(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );

		$unknown = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => wp_generate_uuid4(), 'secret' => $site['secret'] ) ) );
		$forged  = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $site['uuid'], 'secret' => str_repeat( 'f', 40 ) ) ) );

		$this->assertSame( 401, $unknown->get_status() );
		$this->assertSame( $unknown->get_data(), $forged->get_data() );
	}

	public function test_an_address_with_many_failed_authentications_is_refused_before_the_lookup(): void {
		global $wpdb;
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		$wpdb->insert(
			$this->tables()['request_limits'],
			array(
				'limiter_hash' => Authenticator::limiter_hash( 'auth-failure-ip', '198.51.100.77' ),
				'bucket_start' => gmdate( 'Y-m-d H:i:00' ),
				'hits'         => Authenticator::AUTH_FAILURES_PER_IP - 1,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$forged = $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $site['uuid'], 'secret' => str_repeat( 'f', 40 ) ) );
		$this->assertSame( 401, $this->dispatch( $forged )->get_status() );

		// The address is now refused, even with a valid signature ...
		$this->assertApiError( 429, 'rate_limited', $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) ) );

		// ... other addresses are not affected.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) )->get_status() );
	}

	public function test_rejection_counters_use_minute_rows_sampled_under_load_and_summed_per_day(): void {
		global $wpdb;
		if ( '00:00' === gmdate( 'H:i' ) ) {
			$this->markTestSkipped( 'The first minute of the day is also the earlier bucket of this test.' );
		}
		$table = $this->tables()['request_limits'];
		$hash  = hash( 'sha256', 'api-error:invalid_key' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $table, array( 'limiter_hash' => $hash, 'bucket_start' => gmdate( 'Y-m-d 00:00:00' ), 'hits' => 5, 'created_at' => $now ) );
		$wpdb->insert( $table, array( 'limiter_hash' => $hash, 'bucket_start' => gmdate( 'Y-m-d H:i:00' ), 'hits' => \WPLC\Api\V1\ApiLog::SAMPLE_FROM, 'created_at' => $now ) );

		for ( $i = 0; $i < 50; $i++ ) {
			\WPLC\Api\V1\ApiLog::rejected( 'invalid_key', 401, 'POST', '/wplc/v1/activations' );
		}

		$total = \WPLC\Api\V1\ApiLog::counts()['invalid_key'];
		$this->assertGreaterThanOrEqual( 5 + \WPLC\Api\V1\ApiLog::SAMPLE_FROM, $total );
		$this->assertLessThanOrEqual( 5 + \WPLC\Api\V1\ApiLog::SAMPLE_FROM + 50 * \WPLC\Api\V1\ApiLog::SAMPLE_RATE, $total );
		$this->assertSame( 0, ( $total - 5 - \WPLC\Api\V1\ApiLog::SAMPLE_FROM ) % \WPLC\Api\V1\ApiLog::SAMPLE_RATE, 'Above the threshold the counter moves in steps of SAMPLE_RATE.' );
	}

	// ------------------------------------------------------------------
	// P-50: fewer writes per status check.
	// ------------------------------------------------------------------

	public function test_last_seen_is_written_at_most_hourly_unless_a_version_changes(): void {
		global $wpdb;
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'product_version' => '1.0.0' ) );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];
		$table   = $this->tables()['activations'];
		$check   = function ( array $body ) use ( $site ) {
			$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', $body, $site ) )->get_status() );
		};

		$recent = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		$wpdb->update( $table, array( 'last_seen_at' => $recent ), array( 'id' => $id ) );
		$check( array( 'product_version' => '1.0.0' ) );
		$this->assertSame( $recent, $this->activation( $id )['last_seen_at'], 'Nothing changed: no write.' );

		$check( array( 'product_version' => '1.1.0' ) );
		$this->assertSame( '1.1.0', $this->activation( $id )['product_version'] );
		$this->assertNotSame( $recent, $this->activation( $id )['last_seen_at'] );

		$old = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$wpdb->update( $table, array( 'last_seen_at' => $old ), array( 'id' => $id ) );
		$check( array() );
		$this->assertNotSame( $old, $this->activation( $id )['last_seen_at'] );
	}
}
