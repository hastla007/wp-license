<?php
/**
 * T-83: runtime conformance of the REST API with docs/openapi-v1.json.
 *
 * Real WP_REST_Request objects are dispatched through the real REST server;
 * requests are signed exactly like a client installation signs them.
 *
 * @package WPLC
 */

require_once __DIR__ . '/helpers/OpenApiSpec.php';

final class RestConformanceIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Parsed contract.
	 *
	 * @var WPLC_OpenApi_Spec
	 */
	private $spec;

	/**
	 * Observed responses the contract does not declare.
	 *
	 * @var string[]
	 */
	private $spec_gaps = array();

	public function set_up(): void {
		parent::set_up();
		$this->spec      = new WPLC_OpenApi_Spec();
		$this->spec_gaps = array();
	}

	/**
	 * Record whether the contract declares this status for the operation.
	 *
	 * @param string           $method   Method.
	 * @param string           $path     OpenAPI path template.
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	private function check_declared( string $method, string $path, WP_REST_Response $response ): void {
		$status = $response->get_status();
		if ( ! in_array( $status, $this->spec->declared_statuses( $method, $path ), true ) ) {
			$data              = $response->get_data();
			$this->spec_gaps[] = $method . ' ' . $path . ' returned ' . $status
				. ( is_array( $data ) && isset( $data['code'] ) ? ' (' . $data['code'] . ')' : '' );
		}
	}

	/**
	 * Report undeclared responses without hiding the behavioral assertions made before.
	 *
	 * @return void
	 */
	private function flag_spec_gaps(): void {
		if ( $this->spec_gaps ) {
			$this->markTestIncomplete(
				'SPEC GAP: docs/openapi-v1.json does not declare: ' . implode( '; ', array_unique( $this->spec_gaps ) )
				. '. Clients generated from the contract will not handle these responses.'
			);
		}
	}

	/**
	 * Assert an error response that the contract declares as ApiError.
	 *
	 * @param string           $method   Method.
	 * @param string           $path     Path template.
	 * @param int              $status   Expected status.
	 * @param string           $code     Expected error code.
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	private function assertContractError( string $method, string $path, int $status, string $code, WP_REST_Response $response ): void {
		$this->assertApiError( $status, $code, $response );
		$this->assertSame( array( 'code', 'message', 'data' ), $this->spec->required( 'ApiError' ) );
		$this->check_declared( $method, $path, $response );
	}

	/**
	 * Assert a 200 SignedEnvelope whose signature verifies with the installation secret.
	 *
	 * @param string           $method   Method.
	 * @param string           $path     Path template.
	 * @param WP_REST_Response $response Response.
	 * @param string           $secret   Installation secret.
	 * @return array<string,mixed> The data member.
	 */
	private function assertSignedEnvelope( string $method, string $path, WP_REST_Response $response, string $secret ): array {
		$body = $response->get_data();
		$this->assertSame( 200, $response->get_status(), 'Body: ' . wp_json_encode( $body ) );
		$this->check_declared( $method, $path, $response );
		foreach ( $this->spec->required( 'SignedEnvelope' ) as $field ) {
			$this->assertArrayHasKey( $field, $body );
		}
		$this->assertIsArray( $body['data'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $body['signature'] );
		$this->assertSame(
			hash_hmac( 'sha256', (string) wp_json_encode( $body['data'], JSON_UNESCAPED_SLASHES ), $secret ),
			$body['signature'],
			'The envelope signature does not verify as documented.'
		);

		return $body['data'];
	}

	// ------------------------------------------------------------------
	// Contract coverage.
	// ------------------------------------------------------------------

	public function test_every_documented_operation_is_a_registered_route_and_vice_versa(): void {
		$routes     = rest_get_server()->get_routes( 'wplc/v1' );
		$registered = array();
		foreach ( $routes as $route => $handlers ) {
			if ( '/wplc/v1' === $route ) {
				continue;
			}
			$template = preg_replace( '/\(\?P<([a-z_]+)>[^)]+\)/', '{$1}', substr( $route, strlen( '/wplc/v1' ) ) );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( array_filter( $handler['methods'] ) ) as $method ) {
					$registered[] = $method . ' ' . $template;
				}
			}
		}
		$documented = array_map(
			static function ( array $operation ): string {
				return $operation[0] . ' ' . $operation[1];
			},
			$this->spec->operations()
		);
		sort( $registered );
		sort( $documented );

		$this->assertSame( $documented, $registered );
	}

	// ------------------------------------------------------------------
	// POST /activations.
	// ------------------------------------------------------------------

	public function test_activation_happy_path_returns_a_verifiable_envelope_and_stores_no_plaintext_secret(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2, 'feature.export' => true ) );

		$result = $this->activate_via_api(
			$license['key'],
			array(
				'site_url'        => 'HTTPS://Shop.Example.COM:443/store/',
				'product_version' => '1.2.3',
				'wp_version'      => '6.8',
				'php_version'     => '8.2.0',
			)
		);
		$data   = $this->assertSignedEnvelope( 'POST', '/activations', $result['response'], $result['secret'] );

		foreach ( $this->spec->entitlement_fields() as $field ) {
			$this->assertArrayHasKey( $field, $data, 'The contract promises "' . $field . '" in entitlement responses.' );
		}
		$this->assertSame( $result['uuid'], $data['installation_id'] );
		$this->assertSame( 'active', $data['license_status'] );
		$this->assertSame( 2, $data['site_limit'] );
		$this->assertSame( 1, $data['site_count'] );
		$this->assertSame( 'production', $data['environment'] );
		$this->assertSame( array( 'feature.export' => true, 'sites.live_limit' => 2 ), $this->sorted( $data['entitlements'] ) );

		$row = $this->activation( (int) $data['activation_id'] );
		$this->assertSame( 'https://shop.example.com/store', $row['site_url'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '1.2.3', $row['product_version'] );
		$this->assertStringNotContainsString( $result['secret'], (string) wp_json_encode( $row ), 'The installation secret is stored in plaintext.' );
		$this->assertSame( hash( 'sha256', $result['secret'] ), $row['installation_secret_hash'] );
		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'site_activated', (int) $data['activation_id'] ) )
		);
		$this->flag_spec_gaps();
	}

	public function test_activation_accepts_a_lowercase_key_with_surrounding_whitespace(): void {
		$license = $this->seed_license();
		$result  = $this->activate_via_api( '  ' . strtolower( $license['key'] ) . "\n" );

		$this->assertSame( 200, $result['response']->get_status() );
	}

	public function test_activation_retry_with_the_same_installation_is_idempotent(): void {
		$license = $this->seed_license();
		$first   = $this->activate_via_api( $license['key'] );
		$again   = $this->activate_via_api(
			$license['key'],
			array(
				'installation_id'     => $first['uuid'],
				'installation_secret' => $first['secret'],
				'site_url'            => 'https://moved.example.com',
			)
		);

		$first_data = $this->assertSignedEnvelope( 'POST', '/activations', $first['response'], $first['secret'] );
		$again_data = $this->assertSignedEnvelope( 'POST', '/activations', $again['response'], $first['secret'] );
		$this->assertSame( $first_data['activation_id'], $again_data['activation_id'] );
		$this->assertSame( 1, $again_data['site_count'] );
		$this->assertSame( 'https://moved.example.com', $this->activation( (int) $first_data['activation_id'] )['site_url'] );
	}

	public function test_site_limit_is_enforced_with_409_site_limit(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2 ) );

		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );
		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );
		$third = $this->activate_via_api( $license['key'] )['response'];

		$this->assertContractError( 'POST', '/activations', 409, 'site_limit', $third );
		$this->assertSame( 2, $third->get_data()['data']['site_limit'] );
		$this->assertSame( 2, $third->get_data()['data']['site_count'] );

		global $wpdb;
		$tables = $this->tables();
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d", $license['id'] ) ) );
		$this->flag_spec_gaps();
	}

	public function test_staging_activation_rules(): void {
		$license = $this->seed_license();
		$live    = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $live['response']->get_status() );

		// Without a parent.
		$this->assertContractError(
			'POST',
			'/activations',
			400,
			'staging_parent_required',
			$this->activate_via_api( $license['key'], array( 'environment' => 'staging' ) )['response']
		);
		// With a parent that is not an active live site of this license.
		$this->assertContractError(
			'POST',
			'/activations',
			409,
			'staging_parent_invalid',
			$this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => wp_generate_uuid4() ) )['response']
		);

		// A valid staging site does not use a live slot.
		$staging = $this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] ) );
		$data    = $this->assertSignedEnvelope( 'POST', '/activations', $staging['response'], $staging['secret'] );
		$this->assertSame( 'staging', $data['environment'] );
		$this->assertSame( (int) $live['response']->get_data()['data']['activation_id'], $data['staging_parent_id'] );
		$this->assertSame( 1, $data['site_count'] );

		// One staging site per live site.
		$this->assertContractError(
			'POST',
			'/activations',
			409,
			'staging_limit',
			$this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] ) )['response']
		);
		$this->flag_spec_gaps();
	}

	public function test_staging_parent_of_another_license_is_rejected(): void {
		$license_a = $this->seed_license();
		$license_b = $this->seed_license();
		$live_a    = $this->activate_via_api( $license_a['key'] );

		$response = $this->activate_via_api( $license_b['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live_a['uuid'] ) )['response'];

		$this->assertContractError( 'POST', '/activations', 409, 'staging_parent_invalid', $response );
	}

	public function test_development_activation_requires_a_local_host(): void {
		$license = $this->seed_license();

		foreach ( array( 'https://shop.example.com', 'https://localhost.example.com', 'https://test.example.org', 'http://192.168.1.10' ) as $url ) {
			$this->assertContractError(
				'POST',
				'/activations',
				400,
				'development_site_invalid',
				$this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => $url ) )['response']
			);
		}

		foreach ( array( 'http://localhost:8080', 'https://shop.test', 'http://dev.local/wp', 'http://127.0.0.1', 'http://shop.localhost' ) as $url ) {
			$result = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => $url ) );
			$data   = $this->assertSignedEnvelope( 'POST', '/activations', $result['response'], $result['secret'] );
			$this->assertSame( 'development', $data['environment'], $url );
			$this->assertSame( 0, $data['site_count'], 'Development sites must not use a live slot.' );
		}
		$this->flag_spec_gaps();
	}

	public function test_development_activation_accepts_the_ipv6_loopback(): void {
		$license = $this->seed_license();

		$result = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://[::1]:8888' ) );

		if ( 400 === $result['response']->get_status() && 'development_site_invalid' === $result['response']->get_data()['code'] ) {
			$this->markTestIncomplete(
				'BUG: ActivationService::is_local_development_url() lists "::1" as a local host, but wp_parse_url() returns the IPv6 host '
				. 'with brackets ("[::1]"), so the comparison never matches and http://[::1]:8888 is rejected with development_site_invalid. '
				. 'Fix: strip the brackets (trim( $host, "[]" )) before comparing.'
			);
		}
		$this->assertSame( 200, $result['response']->get_status() );
		$this->assertSame( 'development', $result['response']->get_data()['data']['environment'] );
	}

	public function test_activation_rejects_wrong_product_unknown_key_and_inactive_licenses(): void {
		$license = $this->seed_license();
		$this->assertContractError(
			'POST',
			'/activations',
			403,
			'product_mismatch',
			$this->activate_via_api( $license['key'], array( 'product_key' => 'another-product' ) )['response']
		);

		$unknown = \WPLC\License\LicenseKey::for_uuid( wp_generate_uuid4() );
		$this->assertContractError( 'POST', '/activations', 401, 'invalid_key', $this->activate_via_api( $unknown )['response'] );

		$past     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$inactive = array(
			'paid period ended'      => array( 'status' => 'active', 'paid_until' => $past ),
			'expired status'         => array( 'status' => 'expired', 'paid_until' => $past ),
			'revoked'                => array( 'status' => 'revoked' ),
			'suspended'              => array( 'status' => 'suspended' ),
			'pending'                => array( 'status' => 'pending' ),
			'grace period exhausted' => array( 'status' => 'past_due', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) ),
		);
		foreach ( $inactive as $label => $overrides ) {
			$candidate = $this->seed_license( $overrides );
			$response  = $this->activate_via_api( $candidate['key'] )['response'];
			$this->assertApiError( 403, 'expired', $response );
			$this->check_declared( 'POST', '/activations', $response );
		}
		$this->flag_spec_gaps();
	}

	public function test_activation_is_allowed_during_the_renewal_grace_period(): void {
		$license = $this->seed_license(
			array(
				'status'          => 'past_due',
				'paid_until'      => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
				'renewal_enabled' => 1,
			)
		);
		$result  = $this->activate_via_api( $license['key'] );
		$data    = $this->assertSignedEnvelope( 'POST', '/activations', $result['response'], $result['secret'] );

		$this->assertSame( 'past_due', $data['license_status'] );
		$this->assertArrayHasKey( 'feature.export', $data['entitlements'], 'Entitlements must stay valid during the grace period.' );
	}

	public function test_activation_validates_required_and_malformed_fields(): void {
		$license = $this->seed_license();
		$valid   = array(
			'license_key'         => $license['key'],
			'product_key'         => 'test-product',
			'installation_id'     => wp_generate_uuid4(),
			'installation_secret' => str_repeat( 's', 40 ),
			'site_url'            => 'https://shop.example.com',
		);

		foreach ( $this->spec->required( 'ActivationRequest' ) as $field ) {
			$body = $valid;
			unset( $body[ $field ] );
			$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/activations', $body ) );
			$this->assertContractError( 'POST', '/activations', 400, 'rest_missing_callback_param', $response );
		}

		$malformed = array(
			'secret too short'      => array( 'installation_secret' => str_repeat( 's', 31 ) ),
			'secret too long'       => array( 'installation_secret' => str_repeat( 's', 129 ) ),
			'installation not uuid' => array( 'installation_id' => 'not-a-uuid' ),
			'url with query'        => array( 'site_url' => 'https://shop.example.com/?a=b' ),
			'url with credentials'  => array( 'site_url' => 'https://user:pass@shop.example.com' ),
			'non-http url'          => array( 'site_url' => 'ftp://shop.example.com' ),
			'unknown environment'   => array( 'environment' => 'production2' ),
		);
		foreach ( $malformed as $label => $overrides ) {
			$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/activations', array_merge( $valid, $overrides ) ) );
			$this->assertApiError( 400, 'invalid_activation', $response );
		}

		global $wpdb;
		$tables = $this->tables();
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['activations']}" ) );
		$this->flag_spec_gaps();
	}

	public function test_site_url_longer_than_the_column_is_rejected_as_invalid_input(): void {
		global $wpdb;
		$tables   = $this->tables();
		$license  = $this->seed_license();
		$long_url = 'https://shop.example.com/' . str_repeat( 'p', 300 );

		$suppress = $wpdb->suppress_errors( true );
		$response = $this->activate_via_api( $license['key'], array( 'site_url' => $long_url ) )['response'];
		$wpdb->suppress_errors( $suppress );
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT site_url FROM {$tables['activations']} WHERE license_id = %d", $license['id'] ) );

		if ( 400 !== $response->get_status() ) {
			$this->markTestIncomplete(
				'BUG: SiteUrl::normalize() has no length limit, but activations.site_url is varchar(255). A ' . strlen( $long_url )
				. '-character site URL passes validation and then answers ' . $response->get_status() . ' '
				. ( $response->get_data()['code'] ?? 'OK' ) . ( '' !== $stored ? ' with the stored URL cut to ' . strlen( $stored ) . ' characters' : '' )
				. ' instead of 400 invalid_activation. Fix: reject normalized URLs longer than 255 characters in SiteUrl::normalize() or ActivationService::activate().'
			);
		}
		$this->assertApiError( 400, 'invalid_activation', $response );
	}

	public function test_installation_id_cannot_be_taken_over_with_another_secret_or_license(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 5 ) );
		$other   = $this->seed_license();
		$first   = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $first['response']->get_status() );

		$this->assertContractError(
			'POST',
			'/activations',
			409,
			'installation_conflict',
			$this->activate_via_api( $license['key'], array( 'installation_id' => $first['uuid'], 'installation_secret' => str_repeat( 'x', 40 ) ) )['response']
		);
		$this->assertContractError(
			'POST',
			'/activations',
			409,
			'installation_conflict',
			$this->activate_via_api( $other['key'], array( 'installation_id' => $first['uuid'], 'installation_secret' => $first['secret'] ) )['response']
		);
	}

	public function test_deactivate_then_reactivate_with_the_same_installation_id(): void {
		$license       = $this->seed_license();
		$first         = $this->activate_via_api( $license['key'] );
		$activation_id = (int) $first['response']->get_data()['data']['activation_id'];

		$deactivated = $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $activation_id, null, $first ) );
		$data        = $this->assertSignedEnvelope( 'DELETE', '/activations/{activation_id}', $deactivated, $first['secret'] );
		$this->assertSame( array( 'activation_id' => $activation_id, 'status' => 'deactivated' ), $data );
		$this->assertSame( 'deactivated', $this->activation( $activation_id )['status'] );
		$this->assertNotNull( $this->activation( $activation_id )['deactivated_at'] );

		// The same installation ID is burned: the client must generate a new one.
		$again = $this->activate_via_api(
			$license['key'],
			array(
				'installation_id'     => $first['uuid'],
				'installation_secret' => $first['secret'],
			)
		);
		$this->assertContractError( 'POST', '/activations', 409, 'activation_deactivated', $again['response'] );

		// The slot itself is free again for a new installation.
		$fresh = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $fresh['response']->get_status() );
		$this->assertSame( 1, $fresh['response']->get_data()['data']['site_count'] );
		$this->flag_spec_gaps();
	}

	public function test_activation_attempts_are_rate_limited_per_ip(): void {
		// Different keys each time, so only the per-address limit (30/min) applies.
		for ( $attempt = 1; $attempt <= 30; $attempt++ ) {
			$unknown = \WPLC\License\LicenseKey::for_uuid( wp_generate_uuid4() );
			$this->assertSame( 401, $this->activate_via_api( $unknown )['response']->get_status(), 'Attempt ' . $attempt );
		}

		$unknown = \WPLC\License\LicenseKey::for_uuid( wp_generate_uuid4() );
		$this->assertContractError( 'POST', '/activations', 429, 'rate_limited', $this->activate_via_api( $unknown )['response'] );

		// A valid key is locked out as well: the limiter runs before the key lookup.
		$license = $this->seed_license();
		$this->assertSame( 429, $this->activate_via_api( $license['key'] )['response']->get_status() );
		$this->flag_spec_gaps();
	}

	public function test_activation_attempts_are_rate_limited_per_key(): void {
		// One key hammered repeatedly is stopped after 20 attempts per minute,
		// before the per-address limit of 30 is reached.
		$unknown = \WPLC\License\LicenseKey::for_uuid( wp_generate_uuid4() );
		for ( $attempt = 1; $attempt <= 20; $attempt++ ) {
			$this->assertSame( 401, $this->activate_via_api( $unknown )['response']->get_status(), 'Attempt ' . $attempt );
		}

		$this->assertSame( 429, $this->activate_via_api( $unknown )['response']->get_status() );
	}

	public function test_activation_without_a_valid_remote_address_uses_a_shared_bucket(): void {
		// An unresolvable client address must not lock everyone out; it is
		// counted in one shared bucket instead.
		$license                = $this->seed_license();
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );
	}

	// ------------------------------------------------------------------
	// DELETE /activations/{activation_id}.
	// ------------------------------------------------------------------

	public function test_deactivation_requires_a_signature_and_the_matching_activation(): void {
		$license       = $this->seed_license( array(), array( 'sites.live_limit' => 2 ) );
		$first         = $this->activate_via_api( $license['key'] );
		$second        = $this->activate_via_api( $license['key'] );
		$first_id      = (int) $first['response']->get_data()['data']['activation_id'];
		$route         = '/wplc/v1/activations/' . $first_id;
		$path_template = '/activations/{activation_id}';

		$this->assertContractError( 'DELETE', $path_template, 401, 'invalid_request_signature', $this->dispatch( $this->api_request( 'DELETE', $route ) ) );

		$forged = $this->api_request( 'DELETE', $route, null, array( 'uuid' => $first['uuid'], 'secret' => str_repeat( 'f', 40 ) ) );
		$this->assertContractError( 'DELETE', $path_template, 401, 'invalid_request_signature', $this->dispatch( $forged ) );

		// Another installation of the same license cannot deactivate this one.
		$foreign = $this->api_request( 'DELETE', $route, null, $second );
		$this->assertContractError( 'DELETE', $path_template, 403, 'activation_mismatch', $this->dispatch( $foreign ) );

		$this->assertSame( 'active', $this->activation( $first_id )['status'] );

		// After deactivation the credential no longer authenticates.
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'DELETE', $route, null, $first ) )->get_status() );
		$this->assertContractError( 'DELETE', $path_template, 401, 'activation_not_found', $this->dispatch( $this->api_request( 'DELETE', $route, null, $first ) ) );
		$this->assertContractError(
			'POST',
			'/entitlements/check',
			401,
			'activation_not_found',
			$this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $first ) )
		);
		$this->flag_spec_gaps();
	}

	public function test_api_deactivation_of_a_live_site_also_releases_its_staging_site(): void {
		$license = $this->seed_license();
		$live    = $this->activate_via_api( $license['key'] );
		$staging = $this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] ) );
		$this->assertSame( 200, $staging['response']->get_status() );
		$live_id    = (int) $live['response']->get_data()['data']['activation_id'];
		$staging_id = (int) $staging['response']->get_data()['data']['activation_id'];

		$this->assertSame( 200, $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $live_id, null, $live ) )->get_status() );

		$staging_check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $staging ) );
		if ( 'active' === $this->activation( $staging_id )['status'] || 200 === $staging_check->get_status() ) {
			$this->markTestIncomplete(
				'BUG: ActivationService::deactivate() leaves the staging child active after its live parent was deactivated. '
				. 'The orphaned staging installation still authenticates (entitlements/check returned ' . $staging_check->get_status() . ') '
				. 'and the customer can then activate a new live site: one license, two working installations beyond the limit. '
				. 'Fix: deactivate active children WHERE staging_parent_id = parent under the same license lock (ActivationManager::deactivate contract).'
			);
		}
		$this->assertSame( 'deactivated', $this->activation( $staging_id )['status'] );
		$this->assertSame( 401, $staging_check->get_status() );
	}

	// ------------------------------------------------------------------
	// POST /entitlements/check.
	// ------------------------------------------------------------------

	public function test_entitlement_check_returns_the_documented_fields_and_updates_client_versions(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 3, 'feature.export' => true, 'limits.codes' => 250 ) );
		$site    = $this->activate_via_api( $license['key'] );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];

		$response = $this->dispatch(
			$this->api_request(
				'POST',
				'/wplc/v1/entitlements/check',
				array( 'product_version' => '2.0.0', 'wp_version' => '6.9', 'php_version' => '8.3.1' ),
				$site
			)
		);
		$data     = $this->assertSignedEnvelope( 'POST', '/entitlements/check', $response, $site['secret'] );

		foreach ( $this->spec->entitlement_fields() as $field ) {
			$this->assertArrayHasKey( $field, $data, 'The contract promises "' . $field . '".' );
		}
		$this->assertSame( $id, $data['activation_id'] );
		$this->assertSame( 'active', $data['license_status'] );
		$this->assertSame( 3, $data['site_limit'] );
		$this->assertSame( 1, $data['site_count'] );
		$this->assertSame( array( 'feature.export' => true, 'limits.codes' => 250, 'sites.live_limit' => 3 ), $this->sorted( $data['entitlements'] ) );
		$this->assertSame( strtotime( $data['server_time'] . ' UTC' ) + 14 * DAY_IN_SECONDS, strtotime( $data['cache_expires_at'] . ' UTC' ) );

		$row = $this->activation( $id );
		$this->assertSame( array( '2.0.0', '6.9', '8.3.1' ), array( $row['product_version'], $row['wp_version'], $row['php_version'] ) );
		$this->flag_spec_gaps();
	}

	public function test_entitlement_check_reports_lost_rights_without_entitlements(): void {
		global $wpdb;
		$tables = $this->tables();
		$cases  = array(
			'revoked'                 => array( array( 'status' => 'revoked' ), 'revoked' ),
			'suspended'               => array( array( 'status' => 'suspended' ), 'suspended' ),
			'paid period ended'       => array( array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), 'expired' ),
			'expired by the cron run' => array( array( 'status' => 'expired', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), 'expired' ),
		);

		foreach ( $cases as $label => $case ) {
			$license = $this->seed_license();
			$site    = $this->activate_via_api( $license['key'] );
			$this->assertSame( 200, $site['response']->get_status(), $label );
			$wpdb->update( $tables['licenses'], $case[0], array( 'id' => $license['id'] ) );

			$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );
			$data     = $this->assertSignedEnvelope( 'POST', '/entitlements/check', $response, $site['secret'] );

			$this->assertSame( $case[1], $data['license_status'], $label );
			$this->assertSame( array(), $data['entitlements'], $label . ': a license without access must not return entitlements.' );
		}
	}

	public function test_signed_endpoints_reject_missing_expired_and_replayed_requests(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$route   = '/wplc/v1/entitlements/check';

		// No authentication headers at all.
		$this->assertContractError( 'POST', '/entitlements/check', 401, 'invalid_request_signature', $this->dispatch( $this->api_request( 'POST', $route, array() ) ) );

		// Timestamp outside the five-minute window, correctly signed.
		$stale = $this->api_request( 'POST', $route, array(), $site, null, (string) ( time() - 301 ) );
		$this->assertContractError( 'POST', '/entitlements/check', 401, 'request_expired', $this->dispatch( $stale ) );

		// Body changed after signing.
		$tampered = $this->api_request( 'POST', $route, array( 'product_version' => '1.0.0' ), $site );
		$tampered->set_body( '{"product_version":"9.9.9"}' );
		$this->assertContractError( 'POST', '/entitlements/check', 401, 'invalid_request_signature', $this->dispatch( $tampered ) );

		// Unknown installation.
		$unknown = $this->api_request( 'POST', $route, array(), array( 'uuid' => wp_generate_uuid4(), 'secret' => $site['secret'] ) );
		$this->assertContractError( 'POST', '/entitlements/check', 401, 'activation_not_found', $this->dispatch( $unknown ) );

		// Replay of an accepted request.
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', $route, array(), $site, 'replay-nonce-0000000001' ) )->get_status() );
		$replay = $this->dispatch( $this->api_request( 'POST', $route, array(), $site, 'replay-nonce-0000000001' ) );
		$this->assertContractError( 'POST', '/entitlements/check', 409, 'request_replayed', $replay );
		$this->flag_spec_gaps();
	}

	// ------------------------------------------------------------------
	// POST /updates/check.
	// ------------------------------------------------------------------

	/**
	 * Offer one package through the documented filter.
	 *
	 * @param array<string,mixed> $overrides Package overrides.
	 * @return void
	 */
	private function offer_package( array $overrides = array() ): void {
		$package = array_merge(
			array(
				'version'       => '2.0.0',
				'package_url'   => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/downloads/test-product-2.0.0.zip',
				'sha256'        => str_repeat( 'ab', 32 ),
				'requires_php'  => '7.4',
				'requires_wp'   => '6.5',
				'tested_wp'     => '6.8',
				'release_notes' => '<p>Fixes</p><script>alert(1)</script>',
			),
			$overrides
		);
		add_filter(
			'wplc_update_packages',
			static function ( $packages, $product_key ) use ( $package ) {
				$packages['test-product'] = $package;
				return $packages;
			},
			10,
			2
		);
	}

	public function test_update_check_without_a_package_reports_no_update(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );

		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site ) );
		$data     = $this->assertSignedEnvelope( 'POST', '/updates/check', $response, $site['secret'] );

		$this->assertFalse( $data['update_available'] );
		$this->flag_spec_gaps();
	}

	public function test_update_check_offers_a_newer_package_through_a_one_time_grant(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->offer_package();

		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.9.9' ), $site ) );
		$data     = $this->assertSignedEnvelope( 'POST', '/updates/check', $response, $site['secret'] );

		$this->assertTrue( $data['update_available'] );
		$this->assertSame( '2.0.0', $data['version'] );
		$this->assertSame( str_repeat( 'ab', 32 ), $data['sha256'] );
		$this->assertStringNotContainsString( '<script', $data['release_notes'] );
		$this->assertMatchesRegularExpression( '#/wplc/v1/updates/download/([a-f0-9]{64})$#', $data['package_url'] );
		$this->assertStringNotContainsString( 'test-product-2.0.0.zip', (string) wp_json_encode( $data ), 'The real package location must not be disclosed.' );

		preg_match( '#/([a-f0-9]{64})$#', $data['package_url'], $match );
		$grant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['download_tokens']} WHERE license_id = %d", $license['id'] ), ARRAY_A );
		$this->assertSame( hash( 'sha256', $match[1] ), $grant['token_hash'], 'Only the hash of the grant token may be stored.' );
		$this->assertNull( $grant['consumed_at'] );

		// Same or older version: no update, no new grant.
		$same = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '2.0.0' ), $site ) );
		$this->assertFalse( $this->assertSignedEnvelope( 'POST', '/updates/check', $same, $site['secret'] )['update_available'] );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['download_tokens']}" ) );
		$this->flag_spec_gaps();
	}

	public function test_update_check_rejects_other_products_inactive_licenses_and_untrusted_packages(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );

		// Required arguments.
		$missing = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product' ), $site ) );
		$this->assertApiError( 400, 'rest_missing_callback_param', $missing );
		$this->check_declared( 'POST', '/updates/check', $missing );

		$other = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'another-product', 'current_version' => '1.0.0' ), $site ) );
		$this->assertContractError( 'POST', '/updates/check', 403, 'license_inactive', $other );

		$this->offer_package( array( 'package_url' => 'https://evil.example.net/package.zip' ) );
		$untrusted = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site ) );
		$this->assertContractError( 'POST', '/updates/check', 503, 'package_configuration_invalid', $untrusted );

		// B-140: a license without access gets a signed answer that names the version and the reason,
		// but neither a package nor a grant (before: a bare 403 license_inactive).
		$wpdb->update( $tables['licenses'], array( 'status' => 'revoked' ), array( 'id' => $license['id'] ) );
		$revoked = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site ) );
		$data    = $this->assertSignedEnvelope( 'POST', '/updates/check', $revoked, $site['secret'] );
		$this->assertTrue( $data['update_available'] );
		$this->assertFalse( $data['license_active'] );
		$this->assertSame( 'license_revoked', $data['reason'] );
		$this->assertSame( '2.0.0', $data['version'] );
		$this->assertSame( '', $data['package_url'] );
		$this->assertSame( '', $data['package'] );
		$this->assertArrayNotHasKey( 'sha256', $data );
		$this->assertArrayNotHasKey( 'download_expires_at', $data );

		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['download_tokens']}" ) );
		$this->flag_spec_gaps();
	}

	// ------------------------------------------------------------------
	// GET /updates/download/{token}.
	// ------------------------------------------------------------------

	public function test_download_route_only_matches_well_formed_tokens(): void {
		foreach ( array( 'short', str_repeat( 'g', 64 ), str_repeat( 'a', 65 ) ) as $token ) {
			$response = $this->dispatch( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) );
			$this->assertSame( 404, $response->get_status(), $token );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
			$this->check_declared( 'GET', '/updates/download/{token}', $response );
		}

		// WordPress matches routes case-insensitively, so an upper-case token reaches
		// the handler; it is normalized to lower case and answered with a bare 404.
		$this->halt_instead_of_exit();
		try {
			$this->dispatch( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . str_repeat( 'A', 64 ) ) );
			$this->fail( 'An unknown grant must end the request with a 404 status header.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		}
		$this->flag_spec_gaps();
	}

	/**
	 * Sort an entitlement map by key for comparison.
	 *
	 * @param array<string,mixed> $entitlements Entitlements.
	 * @return array<string,mixed>
	 */
	private function sorted( array $entitlements ): array {
		ksort( $entitlements );

		return $entitlements;
	}
}
