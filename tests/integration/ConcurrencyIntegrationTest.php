<?php
/**
 * T-84: single-use and compare-and-set behavior, tested sequentially.
 *
 * True parallel races need two database connections and are out of scope here;
 * these tests pin down the SQL guards (single-use UPDATE, UNIQUE keys, status
 * compare-and-set) that make the parallel case safe.
 *
 * @package WPLC
 */

use WPLC\License\LicenseLifecycle;

final class ConcurrencyIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Trusted package URL on the shop's own host.
	 *
	 * @return string
	 */
	private function package_url(): string {
		return 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/downloads/test-product-2.0.0.zip';
	}

	/**
	 * Obtain a download grant token through POST /updates/check.
	 *
	 * @param array<string,mixed> $site Installation credentials.
	 * @return string 64-character token.
	 */
	private function request_grant( array $site ): string {
		$url = $this->package_url();
		add_filter(
			'wplc_update_packages',
			static function ( $packages ) use ( $url ) {
				$packages['test-product'] = array(
					'version'     => '2.0.0',
					'package_url' => $url,
					'sha256'      => str_repeat( 'cd', 32 ),
				);
				return $packages;
			}
		);
		$response = $this->dispatch(
			$this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site )
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, preg_match( '#/([a-f0-9]{64})$#', (string) $response->get_data()['data']['package_url'], $match ) );

		return $match[1];
	}

	/**
	 * Call the download endpoint; the handler ends with a redirect or a bare status plus exit.
	 *
	 * @param string $token Token.
	 * @return WPLC_Test_Halt
	 */
	private function download( string $token ): WPLC_Test_Halt {
		$this->halt_instead_of_exit();
		try {
			$this->dispatch( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) );
		} catch ( WPLC_Test_Halt $halt ) {
			return $halt;
		}
		$this->fail( 'The download handler returned instead of redirecting or sending a status.' );
	}

	/**
	 * Grant row for a token.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>
	 */
	private function grant( string $token ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['download_tokens']} WHERE token_hash = %s", hash( 'sha256', $token ) ),
			ARRAY_A
		);
	}

	// ------------------------------------------------------------------
	// One-time download grants.
	// ------------------------------------------------------------------

	public function test_download_grant_can_be_consumed_exactly_once(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		$first = $this->download( $token );
		$this->assertSame( 'redirect', $first->kind );
		$this->assertSame( 302, $first->status );
		$this->assertSame( $this->package_url(), $first->location );

		$grant = $this->grant( $token );
		$this->assertNotNull( $grant['consumed_at'] );
		$this->assertSame( '', $grant['package_url'], 'A consumed grant must not keep the package location.' );

		$second = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $second->kind, $second->status ) );
	}

	public function test_grant_is_refused_after_the_license_was_revoked_and_stays_unconsumed(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		$wpdb->update( $tables['licenses'], array( 'status' => 'revoked' ), array( 'id' => $license['id'] ) );

		$halt = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		$this->assertNull( $this->grant( $token )['consumed_at'] );
	}

	public function test_grant_is_refused_after_the_paid_period_ended(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		$wpdb->update( $tables['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $license['id'] ) );

		$halt = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
	}

	public function test_expired_grant_is_refused(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		$wpdb->update( $tables['download_tokens'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'token_hash' => hash( 'sha256', $token ) ) );

		$halt = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		$this->assertNull( $this->grant( $token )['consumed_at'] );
	}

	public function test_unknown_token_is_refused(): void {
		$halt = $this->download( str_repeat( 'a', 64 ) );

		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
	}

	public function test_grant_is_refused_when_the_package_host_is_no_longer_trusted(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		// The shop's own host is re-added late (local package storage), so remove it after that.
		add_filter( 'wplc_update_package_hosts', '__return_empty_array', 2000 );

		$halt = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		$this->assertNull( $this->grant( $token )['consumed_at'] );
	}

	public function test_storage_resolver_returning_an_untrusted_url_never_redirects(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$token   = $this->request_grant( $site );

		add_filter(
			'wplc_resolve_update_package_url',
			static function () {
				return 'https://attacker.example.net/package.zip';
			}
		);

		$halt = $this->download( $token );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		// Documents current behavior: the grant is already burned at this point,
		// so the client has to run a new update check after the resolver is fixed.
		$this->assertNotNull( $this->grant( $token )['consumed_at'] );
	}

	public function test_expired_and_consumed_grants_are_purged_after_the_retention_period(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$old     = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
		$recent  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows    = array(
			'old-expired'    => array( 'expires_at' => $old, 'consumed_at' => null ),
			'old-consumed'   => array( 'expires_at' => $old, 'consumed_at' => $old ),
			'recent-expired' => array( 'expires_at' => $recent, 'consumed_at' => null ),
		);
		foreach ( $rows as $label => $row ) {
			$wpdb->insert(
				$tables['download_tokens'],
				array_merge(
					array(
						'license_id'  => $license['id'],
						'token_hash'  => hash( 'sha256', $label ),
						'package_url' => '',
						'created_at'  => $old,
					),
					$row
				)
			);
		}

		\WPLC\Api\V1\UpdateService::purge_expired_grants();

		$left = $wpdb->get_col( "SELECT token_hash FROM {$tables['download_tokens']}" );
		$this->assertSame( array( hash( 'sha256', 'recent-expired' ) ), $left );
	}

	// ------------------------------------------------------------------
	// Request nonce replay.
	// ------------------------------------------------------------------

	public function test_nonce_replay_is_refused_across_routes_but_scoped_to_one_installation(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2 ) );
		$site_a  = $this->activate_via_api( $license['key'] );
		$site_b  = $this->activate_via_api( $license['key'] );
		$nonce   = 'shared-nonce-0000000001';

		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_a, $nonce ) )->get_status() );

		// Same nonce, same installation, same route.
		$this->assertApiError( 409, 'request_replayed', $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_a, $nonce ) ) );
		// Same nonce, same installation, different route and body.
		$this->assertApiError(
			409,
			'request_replayed',
			$this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site_a, $nonce ) )
		);
		// Another installation may use the same nonce value once.
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_b, $nonce ) )->get_status() );
		$this->assertApiError( 409, 'request_replayed', $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_b, $nonce ) ) );

		// Only the nonce hash is stored, once per installation.
		$stored = $wpdb->get_col( "SELECT nonce_hash FROM {$tables['request_nonces']}" );
		$this->assertSame( array( hash( 'sha256', $nonce ), hash( 'sha256', $nonce ) ), $stored );
	}

	public function test_rejected_requests_do_not_consume_the_nonce(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$nonce   = 'reusable-nonce-000000001';

		$forged = $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $site['uuid'], 'secret' => str_repeat( 'z', 40 ) ), $nonce );
		$this->assertSame( 401, $this->dispatch( $forged )->get_status() );

		// The legitimate client can still use that nonce: an attacker cannot burn nonces.
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, $nonce ) )->get_status() );
	}

	public function test_replayed_deactivation_is_refused_and_does_not_log_twice(): void {
		global $wpdb;
		$tables        = $this->tables();
		$license       = $this->seed_license();
		$site          = $this->activate_via_api( $license['key'] );
		$activation_id = (int) $site['response']->get_data()['data']['activation_id'];
		$route         = '/wplc/v1/activations/' . $activation_id;
		$nonce         = 'deactivate-nonce-0000001';

		$this->assertSame( 200, $this->dispatch( $this->api_request( 'DELETE', $route, null, $site, $nonce ) )->get_status() );
		$this->assertNotSame( 200, $this->dispatch( $this->api_request( 'DELETE', $route, null, $site, $nonce ) )->get_status() );

		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'site_deactivated', $activation_id ) )
		);
	}

	// ------------------------------------------------------------------
	// Status compare-and-set.
	// ------------------------------------------------------------------

	/**
	 * Audit rows for a license.
	 *
	 * @param int $license_id License ID.
	 * @return int
	 */
	private function license_audit_count( int $license_id ): int {
		global $wpdb;
		$tables = $this->tables();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d", 'license', $license_id ) );
	}

	public function test_transition_from_a_stale_status_changes_nothing(): void {
		global $wpdb;
		$tables     = $this->tables();
		$license_id = $this->seed_license()['id'];
		$licenses   = $tables['licenses'];
		$raced      = false;

		// Simulate a concurrent request: right before the lifecycle UPDATE (which
		// was prepared against the status it just read, "active"), another
		// process suspends the license.
		$race = static function ( $query ) use ( &$raced, $wpdb, $licenses, $license_id ) {
			if ( ! $raced && 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, $licenses ) && false !== strpos( $query, "'revoked'" ) ) {
				$raced = true;
				$wpdb->query( $wpdb->prepare( "UPDATE {$licenses} SET status = %s WHERE id = %d", 'suspended', $license_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $query;
		};
		add_filter( 'query', $race );
		$changed = LicenseLifecycle::admin_set_status( $license_id, 'revoked', 'Stale write', 1 );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced, 'The simulated concurrent update did not run; the test no longer exercises the compare-and-set.' );
		$this->assertFalse( $changed );
		$license = $this->license( $license_id );
		$this->assertSame( 'suspended', $license['status'], 'The stale transition overwrote the concurrent status.' );
		$this->assertNull( $license['revoked_at'] );
		$this->assertSame( 0, $this->license_audit_count( $license_id ), 'A transition that did not happen must not be audited.' );
	}

	public function test_terminal_states_ignore_late_lifecycle_events(): void {
		global $wpdb;
		$tables = $this->tables();

		foreach ( array( 'revoked', 'expired' ) as $terminal ) {
			$license_id = $this->seed_license( array( 'status' => $terminal, 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) )['id'];
			$before     = $this->license( $license_id );

			LicenseLifecycle::mark_past_due( $license_id, 'late failure' );
			LicenseLifecycle::mark_renewed( $license_id, gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ), 0 );
			LicenseLifecycle::suspend_for_chargeback( $license_id, 'late chargeback' );
			LicenseLifecycle::handle_full_refund( 0 );
			LicenseLifecycle::expire_due_licenses();
			$this->assertFalse( LicenseLifecycle::schedule_cancellation( $license_id, 'late cancel', 1 ) );

			$after = $this->license( $license_id );
			$this->assertSame( $before['status'], $after['status'], $terminal );
			$this->assertSame( $before['paid_until'], $after['paid_until'], $terminal . ': a late renewal payment must not extend a ' . $terminal . ' license.' );
			$this->assertSame( 0, $this->license_audit_count( $license_id ) );
		}

		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE status = 'active'" ) );
	}

	public function test_repeated_transition_is_applied_and_audited_once(): void {
		$license_id = $this->seed_license()['id'];

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Fraud review', 1 ) );
		$this->assertFalse( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Fraud review', 1 ) );

		$this->assertSame( 'suspended', $this->license( $license_id )['status'] );
		$this->assertSame( 1, $this->license_audit_count( $license_id ) );
	}

	public function test_expiry_sweep_expires_only_due_licenses_and_respects_the_grace_period(): void {
		$past_1d  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$past_20d = gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS );
		$future   = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$due_manual     = $this->seed_license( array( 'paid_until' => $past_1d, 'renewal_enabled' => 0 ) )['id'];
		$due_cancelled  = $this->seed_license( array( 'paid_until' => $past_1d, 'renewal_enabled' => 1, 'cancel_at_period_end' => 1 ) )['id'];
		$in_grace       = $this->seed_license( array( 'paid_until' => $past_1d, 'renewal_enabled' => 1 ) )['id'];
		$grace_over     = $this->seed_license( array( 'paid_until' => $past_20d, 'renewal_enabled' => 1 ) )['id'];
		$still_paid     = $this->seed_license( array( 'paid_until' => $future ) )['id'];
		$already_revoked = $this->seed_license( array( 'paid_until' => $past_1d, 'status' => 'revoked' ) )['id'];

		LicenseLifecycle::expire_due_licenses();
		LicenseLifecycle::expire_due_licenses();

		$this->assertSame( 'expired', $this->license( $due_manual )['status'] );
		$this->assertSame( 'expired', $this->license( $due_cancelled )['status'] );
		$this->assertSame( 'active', $this->license( $in_grace )['status'] );
		$this->assertSame( 'expired', $this->license( $grace_over )['status'] );
		$this->assertSame( 'active', $this->license( $still_paid )['status'] );
		$this->assertSame( 'revoked', $this->license( $already_revoked )['status'] );
		$this->assertSame( 1, $this->license_audit_count( $due_manual ), 'The second sweep must not audit again.' );
	}

	public function test_duplicate_license_for_the_same_order_unit_is_rejected_by_the_database(): void {
		global $wpdb;
		$this->seed_license( array( 'source_order_id' => 501, 'source_order_item_id' => 9001, 'unit_index' => 0 ) );
		$count = $this->license_count();

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			$this->tables()['licenses'],
			array(
				'license_uuid'         => wp_generate_uuid4(),
				'license_key_hash'     => hash( 'sha256', 'duplicate' ),
				'key_prefix'           => 'WPLC-DUPLICATE',
				'product_key'          => 'test-product',
				'plan_key'             => 'single',
				'status'               => 'pending',
				'starts_at'            => gmdate( 'Y-m-d H:i:s' ),
				'source_order_id'      => 501,
				'source_order_item_id' => 9001,
				'unit_index'           => 0,
				'created_at'           => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $inserted, 'The UNIQUE(source_order_item_id, unit_index) key is the last line of defense against double issuance.' );
		$this->assertSame( $count, $this->license_count() );
	}
}
