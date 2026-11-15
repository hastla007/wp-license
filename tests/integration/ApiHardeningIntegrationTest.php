<?php
/**
 * Activation, authentication and signature rules added for the audit tasks
 * A-06, A-30, A-31, A-33, A-34, A-36, A-71, A-77, A-102 and B-22 … B-180.
 *
 * Every test dispatches real REST requests against MySQL. The scenarios of the
 * QA reviewer (QaNastyIntegrationTest) are asserted here instead of logged.
 *
 * @package WPLC
 */

use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\ApiLog;
use WPLC\Api\V1\Authenticator;
use WPLC\Api\V1\EntitlementService;
use WPLC\Api\V1\Response;
use WPLC\Api\V1\SiteUrl;
use WPLC\CLI\ActivationCommand;
use WPLC\CLI\LicenseCommand;
use WPLC\CLI\RotateInstallationSecretsCommand;
use WPLC\License\ActivationManager;
use WPLC\License\LicenseKey;
use WPLC\Security\ResponseSigner;
use WPLC\Security\SecretCrypto;

final class ApiHardeningIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		ResponseSigner::reset();
	}

	public function tear_down(): void {
		// The transaction rollback removes options a test wrote; forget the key that was read from them.
		ResponseSigner::reset();
		parent::tear_down();
	}

	/**
	 * Forget all rate-limit buckets (tests that need more than 20 activations of one key).
	 *
	 * @return void
	 */
	private function reset_limits(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->tables()['request_limits']}" );
	}

	/**
	 * Data member of a 200 envelope after checking the HMAC of the payload.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $secret   Installation secret.
	 * @param string           $nonce    Expected nonce.
	 * @return array<string,mixed>
	 */
	private function verified_data( WP_REST_Response $response, string $secret, string $nonce ): array {
		$body = $response->get_data();
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $body ) );

		return $this->verified_envelope( $body, $secret, $nonce );
	}

	/**
	 * Check payload signature and nonce of an envelope and return payload.data.
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 * @param string              $secret   Installation secret.
	 * @param string              $nonce    Expected nonce.
	 * @return array<string,mixed>
	 */
	private function verified_envelope( array $envelope, string $secret, string $nonce ): array {
		$this->assertSame( hash_hmac( 'sha256', $envelope['payload'], $secret ), $envelope['payload_signature'] );
		$payload = json_decode( $envelope['payload'], true );
		$this->assertSame( $nonce, $payload['nonce'] );

		return $payload['data'];
	}

	/**
	 * Active activations of a license, keyed by ID.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_rows( int $license_id ): array {
		$rows = array();
		foreach ( ActivationCommand::rows( $license_id, 'active' ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		return $rows;
	}

	// ------------------------------------------------------------------
	// A-06, B-175: version strings.
	// ------------------------------------------------------------------

	public function test_long_version_strings_are_cut_instead_of_failing_the_activation(): void {
		$license = $this->seed_license();
		$long    = '8.3.6-1ubuntu0.24.04.1+deb.sury.org+1~some-very-long-distribution-suffix';

		$result = $this->activate_via_api( $license['key'], array( 'product_version' => str_repeat( '1.2.3-', 20 ), 'wp_version' => '6.8-RC1-59981-src', 'php_version' => $long ) );

		$this->assertSame( 200, $result['response']->get_status(), (string) wp_json_encode( $result['response']->get_data() ) );
		$row = $this->activation( (int) $result['response']->get_data()['data']['activation_id'] );
		$this->assertSame( substr( $long, 0, 30 ), $row['php_version'] );
		$this->assertSame( 30, strlen( $row['product_version'] ) );
		$this->assertSame( '6.8-RC1-59981-src', $row['wp_version'] );

		// The entitlement check cuts the same way and counts characters, not bytes.
		$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array( 'php_version' => str_repeat( 'ä', 40 ) ), $result ) );
		$this->assertSame( 200, $check->get_status() );
		$this->assertSame( str_repeat( 'ä', 30 ), $this->activation( (int) $row['id'] )['php_version'] );
	}

	public function test_version_fields_must_be_strings(): void {
		global $wpdb;
		$license = $this->seed_license();

		foreach ( array( 'product_version', 'wp_version', 'php_version' ) as $field ) {
			foreach ( array( array( 'a', 'b' ), array( 'x' => array( 'y' => 1 ) ) ) as $value ) {
				$response = $this->activate_via_api( $license['key'], array( $field => $value ) )['response'];
				$this->assertApiError( 400, 'rest_invalid_param', $response );
			}
		}
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['activations']}" ) );

		$site  = $this->activate_via_api( $license['key'], array( 'php_version' => '8.2.1' ) );
		$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array( 'php_version' => array( 'x' ) ), $site ) );
		$this->assertApiError( 400, 'rest_invalid_param', $check );
		$this->assertSame( '8.2.1', $this->activation( (int) $site['response']->get_data()['data']['activation_id'] )['php_version'], 'Nothing like "Array" may be stored.' );
	}

	/** A-06: the old activation of a site only ends after the new one is stored. */
	public function test_a_failing_insert_leaves_the_previous_activation_of_the_site_active(): void {
		global $wpdb;
		$license = $this->seed_license();
		$first   = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$this->assertSame( 200, $first['response']->get_status() );
		$first_id = (int) $first['response']->get_data()['data']['activation_id'];

		$table  = $this->tables()['activations'];
		$break  = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $table . '`' ) ? 'INSERT INTO wplc_missing_table_for_test VALUES (1)' : $query;
		};
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$second = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $errors );

		$this->assertApiError( 500, 'activation_failed', $second['response'] );
		$this->assertSame( 'active', $this->activation( $first_id )['status'], 'The site lost its activation although the new one could not be stored.' );
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $first ) )->get_status() );

		// Without the failure the same request replaces the old activation.
		$third = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$this->assertSame( 200, $third['response']->get_status() );
		$this->assertSame( 'deactivated', $this->activation( $first_id )['status'] );
		$this->assertSame( 1, $third['response']->get_data()['data']['site_count'] );
	}

	// ------------------------------------------------------------------
	// B-75: installation secret.
	// ------------------------------------------------------------------

	public function test_installation_secret_is_validated_and_never_rewritten(): void {
		global $wpdb;
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 50 ) );

		$rejected = array(
			'31 characters'    => str_repeat( 'a', 31 ),
			'129 characters'   => str_repeat( 'a', 129 ),
			'percent escape'   => 'abc%41def' . str_repeat( 'x', 40 ),
			'tags'             => 'p<b>q' . str_repeat( 'x', 40 ),
			'lone less-than'   => 'a<b' . str_repeat( 'x', 40 ),
			'inner spaces'     => 'a  b' . str_repeat( 'x', 40 ),
			'trailing space'   => str_repeat( 'x', 40 ) . ' ',
			'line break'       => "a\nb" . str_repeat( 'x', 40 ),
			'tab'              => "a\tb" . str_repeat( 'x', 40 ),
			'four-byte chars'  => str_repeat( '😀', 16 ),
			'umlaut'           => str_repeat( 'ä', 40 ),
			'NUL'              => "ab\0cd" . str_repeat( 'x', 40 ),
		);
		$as_array = array( 'license_key' => $license['key'], 'product_key' => 'test-product', 'installation_id' => wp_generate_uuid4(), 'installation_secret' => array( str_repeat( 'x', 40 ) ), 'site_url' => 'https://array.example.com' );
		$this->assertApiError( 400, 'rest_invalid_param', $this->dispatch( $this->api_request( 'POST', '/wplc/v1/activations', $as_array ) ) );
		foreach ( $rejected as $label => $secret ) {
			$this->reset_limits();
			$response = $this->activate_via_api( $license['key'], array( 'installation_secret' => $secret ) )['response'];
			$this->assertSame( 400, $response->get_status(), $label );
			if ( is_string( $secret ) ) {
				$this->assertSame( 'invalid_activation', $response->get_data()['code'], $label );
				$this->assertSame( array( 'installation_secret' ), $response->get_data()['data']['fields'], $label );
			}
		}
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['activations']}" ), 'A rejected secret must not occupy a site slot.' );

		$accepted = array(
			'32 characters'  => str_repeat( 'a', 32 ),
			'128 characters' => str_repeat( 'a', 128 ),
			'hex'            => bin2hex( random_bytes( 32 ) ),
			'base64'         => base64_encode( random_bytes( 48 ) ),
			'base64url'      => rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ),
			'unreserved'     => 'A.b_c~d-e+f/g=' . str_repeat( 'Z9', 20 ),
		);
		foreach ( $accepted as $label => $secret ) {
			$this->reset_limits();
			$result = $this->activate_via_api( $license['key'], array( 'installation_secret' => $secret ) );
			$this->assertSame( 200, $result['response']->get_status(), $label );
			// The client can verify the answer and sign follow-up requests with exactly the secret it sent.
			$this->verified_envelope( $result['response']->get_data(), $secret, $result['uuid'] );
			$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $result ) );
			$this->assertSame( 200, $check->get_status(), $label . ': signed follow-up request' );
		}
	}

	// ------------------------------------------------------------------
	// B-72, B-173: input normalization.
	// ------------------------------------------------------------------

	public function test_environment_must_match_exactly(): void {
		global $wpdb;
		$license = $this->seed_license();

		foreach ( array( 'Production', 'pro duction', 'PRODUCTION', 'prod', 'live', ' staging', 'development ' ) as $environment ) {
			$response = $this->activate_via_api( $license['key'], array( 'environment' => $environment ) )['response'];
			$this->assertApiError( 400, 'invalid_activation', $response );
			$this->assertSame( array( 'environment' ), $response->get_data()['data']['fields'], $environment );
		}
		$this->assertApiError( 400, 'rest_invalid_param', $this->activate_via_api( $license['key'], array( 'environment' => 0 ) )['response'] );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['activations']}" ) );

		// Omitted and empty mean production.
		$this->assertSame( 'production', $this->activate_via_api( $license['key'], array( 'environment' => '' ) )['response']->get_data()['data']['environment'] );
	}

	public function test_several_invalid_fields_are_all_named(): void {
		$license  = $this->seed_license();
		$response = $this->activate_via_api( $license['key'], array( 'product_key' => 'bad key!', 'installation_id' => 'nope', 'site_url' => 'https://exa mple.com', 'installation_secret' => 'short' ) )['response'];

		$this->assertApiError( 400, 'invalid_activation', $response );
		$this->assertSame( array( 'product_key', 'installation_id', 'installation_secret', 'site_url' ), $response->get_data()['data']['fields'] );
	}

	public function test_pasted_license_keys_are_accepted(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 10 ) );
		$key     = $license['key'];

		$pasted = array(
			'line break'           => $key . "\r\n",
			'wrapped'              => substr( $key, 0, 50 ) . "\n" . substr( $key, 50 ),
			'non-breaking space'   => "\u{00A0}" . $key,
			'zero-width space'     => $key . "\u{200B}",
			'en dashes'            => str_replace( '-', "\u{2013}", $key ),
			'non-breaking hyphens' => str_replace( '-', "\u{2011}", strtolower( $key ) ),
			'quotes and a dot'     => '"' . $key . '".',
		);
		foreach ( $pasted as $label => $candidate ) {
			$this->reset_limits();
			$this->assertSame( 200, $this->activate_via_api( $candidate )['response']->get_status(), $label );
		}
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $pasted['en dashes'] ) );
	}

	public function test_uppercase_installation_ids_are_one_installation(): void {
		global $wpdb;
		$license = $this->seed_license();
		$uuid    = strtoupper( wp_generate_uuid4() );
		$site    = $this->activate_via_api( $license['key'], array( 'installation_id' => $uuid ) );
		$this->assertSame( 200, $site['response']->get_status() );
		$this->assertSame( strtolower( $uuid ), $site['response']->get_data()['data']['installation_id'] );
		$this->reset_limits();

		// B-166: both spellings authenticate and count in the same bucket.
		foreach ( array( $uuid, strtolower( $uuid ), ucfirst( strtolower( $uuid ) ) ) as $spelling ) {
			$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $spelling, 'secret' => $site['secret'] ) ) );
			$this->assertSame( 200, $response->get_status(), $spelling );
		}
		$tables = $this->tables();
		$this->assertSame(
			3,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s", Authenticator::limiter_hash( 'installation', strtolower( $uuid ) ) ) ),
			'The per-installation limit can be bypassed by changing the case of the installation ID.'
		);
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['request_limits']}" ) );
	}

	// ------------------------------------------------------------------
	// B-130, A-36, A-71: rate limiting, cleanup, trace.
	// ------------------------------------------------------------------

	public function test_limiter_rows_are_keyed_hmacs(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->activate_via_api( LicenseKey::for_uuid( wp_generate_uuid4() ) );
		$this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );

		$stored = $wpdb->get_col( "SELECT limiter_hash FROM {$tables['request_limits']}" );
		$this->assertContains( Authenticator::limiter_hash( 'activation-ip', '127.0.0.1' ), $stored );
		$this->assertContains( Authenticator::limiter_hash( 'installation', $site['uuid'] ), $stored );
		$this->assertContains( ActivationService::key_limiter_hash( $license['key'] ), $stored );
		// The former, reversible values.
		$this->assertNotContains( hash( 'sha256', 'activation-ip:127.0.0.1' ), $stored );
		$this->assertNotContains( hash( 'sha256', 'installation:' . $site['uuid'] ), $stored );
		$this->assertNotContains( hash( 'sha256', 'activation-key:' . substr( $license['key'], 0, 13 ) ), $stored );
	}

	public function test_a_throttled_installation_does_not_store_nonces(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];

		$wpdb->insert(
			$tables['request_limits'],
			array(
				'limiter_hash' => Authenticator::limiter_hash( 'installation', $site['uuid'] ),
				'bucket_start' => gmdate( 'Y-m-d H:i:00' ),
				'hits'         => Authenticator::REQUESTS_PER_INSTALLATION,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$before   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['request_nonces']} WHERE activation_id = %d", $id ) );
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );

		$this->assertApiError( 429, 'rate_limited', $response );
		$this->assertSame( '60', (string) $response->get_headers()['Retry-After'] );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['request_nonces']} WHERE activation_id = %d", $id ) ), 'A-36: the nonce was stored before the limit was checked.' );
	}

	public function test_cleanup_removes_everything_that_expired(): void {
		global $wpdb;
		$tables = $this->tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->insert( $tables['request_nonces'], array( 'activation_id' => 900000 + $i, 'nonce_hash' => hash( 'sha256', 'old' . $i ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => $now ) );
			$wpdb->insert( $tables['request_limits'], array( 'limiter_hash' => hash( 'sha256', 'old' . $i ), 'bucket_start' => gmdate( 'Y-m-d H:i:00', time() - 3 * DAY_IN_SECONDS ), 'hits' => 1, 'created_at' => $now ) );
		}
		$wpdb->insert( $tables['request_nonces'], array( 'activation_id' => 900100, 'nonce_hash' => hash( 'sha256', 'fresh' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'created_at' => $now ) );
		$wpdb->insert( $tables['request_limits'], array( 'limiter_hash' => hash( 'sha256', 'fresh' ), 'bucket_start' => gmdate( 'Y-m-d H:i:00' ), 'hits' => 1, 'created_at' => $now ) );

		$deleted = Authenticator::cleanup_security_rows();

		$this->assertSame( array( 'nonces' => 25, 'limits' => 25 ), $deleted );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['request_nonces']} WHERE activation_id >= 900000" ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['request_limits']} WHERE limiter_hash = %s", hash( 'sha256', 'fresh' ) ) ) );
		$this->assertNotFalse( has_action( 'wplc_cleanup_security_rows', array( Authenticator::class, 'run_cleanup' ) ) );
	}

	public function test_rejected_requests_are_counted_and_announced(): void {
		$license  = $this->seed_license();
		$announced = array();
		add_action(
			'wplc/api_request_rejected',
			static function ( $code, $status, $method, $route ) use ( &$announced ) {
				$announced[] = array( $code, $status, $method, $route );
			},
			10,
			4
		);

		$this->activate_via_api( LicenseKey::for_uuid( wp_generate_uuid4() ) );
		$this->activate_via_api( LicenseKey::for_uuid( wp_generate_uuid4() ) );
		$this->activate_via_api( $license['key'], array( 'product_key' => 'another-product' ) );
		$this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/4711' ) );
		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );

		$counts = ApiLog::counts();
		$this->assertSame( 2, $counts['invalid_key'] );
		$this->assertSame( 1, $counts['product_mismatch'] );
		$this->assertSame( 1, $counts['invalid_request_signature'] );
		$this->assertSame( array(), ApiLog::counts( 1 ) );
		$this->assertContains( array( 'invalid_key', 401, 'POST', '/wplc/v1/activations' ), $announced );
		$this->assertContains( array( 'invalid_request_signature', 401, 'DELETE', '/wplc/v1/activations/{id}' ), $announced, 'Path parameters must not reach the log.' );
		$this->assertCount( 4, $announced, 'Successful requests are not announced.' );
	}

	// ------------------------------------------------------------------
	// B-109, B-23: headers and messages of errors.
	// ------------------------------------------------------------------

	public function test_every_answer_including_errors_forbids_caching(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );

		$responses = array(
			'success'             => $site['response'],
			'missing parameter'   => $this->dispatch( $this->api_request( 'POST', '/wplc/v1/activations', array( 'license_key' => 'x' ) ) ),
			'invalid parameter'   => $this->activate_via_api( $license['key'], array( 'wp_version' => array( 'x' ) ) )['response'],
			'unknown key'         => $this->activate_via_api( LicenseKey::for_uuid( wp_generate_uuid4() ) )['response'],
			'unsigned check'      => $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array() ) ),
			'unsigned deactivate' => $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/1' ) ),
			'unsigned update'     => $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ) ) ),
			'signed check'        => $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) ),
		);
		foreach ( $responses as $label => $response ) {
			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Cache-Control', $headers, $label . ' (' . $response->get_status() . ')' );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'], $label );
			$this->assertStringContainsString( 'private', $headers['Cache-Control'], $label );
			$this->assertSame( 'no-referrer', $headers['Referrer-Policy'], $label );
		}

		// Unknown routes below the namespace never reach a callback.
		$request  = new WP_REST_Request( 'GET', '/wplc/v1/does-not-exist' );
		$response = apply_filters( 'rest_post_dispatch', $this->dispatch( $request ), rest_get_server(), $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		// Other namespaces are left alone.
		$request  = new WP_REST_Request( 'GET', '/wp/v2/does-not-exist' );
		$response = apply_filters( 'rest_post_dispatch', $this->dispatch( $request ), rest_get_server(), $request );
		$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
	}

	public function test_error_messages_are_not_translated(): void {
		$translate = static function ( $translation, $text, $domain ) {
			return 'wp-license' === $domain ? 'ÜBERSETZT: ' . $text : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		$license = $this->seed_license( array( 'status' => 'suspended' ) );
		$active  = $this->seed_license();
		$site    = $this->activate_via_api( $active['key'] );

		$responses = array(
			$this->activate_via_api( LicenseKey::for_uuid( wp_generate_uuid4() ) )['response'],
			$this->activate_via_api( $license['key'] )['response'],
			$this->activate_via_api( $active['key'], array( 'site_url' => 'nope' ) )['response'],
			$this->activate_via_api( $active['key'] )['response'],
			$this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array() ) ),
			$this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/999999', null, $site ) ),
		);
		remove_filter( 'gettext', $translate, 10 );

		$codes = array();
		foreach ( $responses as $response ) {
			$data    = $response->get_data();
			$codes[] = $data['code'];
			$this->assertStringNotContainsString( 'ÜBERSETZT', $data['message'], $data['code'] );
			$this->assertMatchesRegularExpression( '/^[\x20-\x7e]+$/', $data['message'], $data['code'] );
		}
		$this->assertSame( array( 'invalid_key', 'license_suspended', 'invalid_activation', 'site_limit', 'invalid_request_signature', 'activation_mismatch' ), $codes );
	}

	// ------------------------------------------------------------------
	// B-68, B-72, A-102: signed snapshot.
	// ------------------------------------------------------------------

	/**
	 * License states and the access window they must report.
	 *
	 * Offsets are days relative to now: [ license overrides, access, license_status, access_until, grace_until, cache_expires_at ].
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function data_access_windows(): array {
		return array(
			'no renewal, 3 days left: the cache ends with the paid time' => array( array( 'paid_until' => 3 ), true, 'active', 3, null, 3 ),
			'no renewal, 60 days left: 14 days offline'                  => array( array( 'paid_until' => 60 ), true, 'active', 60, null, 14 ),
			'auto renewal, 3 days left: grace is part of the window'     => array( array( 'paid_until' => 3, 'renewal_enabled' => 1 ), true, 'active', 17, 17, 14 ),
			'auto renewal cancelled: no grace'                           => array( array( 'paid_until' => 3, 'renewal_enabled' => 1, 'cancel_at_period_end' => 1 ), true, 'active', 3, null, 3 ),
			'past due, day 2 of the grace period'                        => array( array( 'paid_until' => -2, 'status' => 'past_due' ), true, 'past_due', 12, 12, 12 ),
			'past due, grace period over'                                => array( array( 'paid_until' => -15, 'status' => 'past_due' ), false, 'past_due', null, -1, 14 ),
			'paid period over, no renewal'                               => array( array( 'paid_until' => -1 ), false, 'expired', null, null, 14 ),
			'suspended'                                                  => array( array( 'paid_until' => 30, 'status' => 'suspended' ), false, 'suspended', null, null, 14 ),
			'license without an end'                                     => array( array( 'paid_until' => null, 'next_payment_at' => null ), true, 'active', null, null, 14 ),
		);
	}

	/**
	 * @dataProvider data_access_windows
	 *
	 * @param array<string,mixed> $overrides     License columns; paid_until as day offset.
	 * @param bool                $access        Expected access.
	 * @param string              $status        Expected license_status.
	 * @param int|null            $access_until  Expected day offset.
	 * @param int|null            $grace_until   Expected day offset.
	 * @param int                 $cache_expires Expected day offset.
	 */
	public function test_snapshot_reports_the_access_window( array $overrides, bool $access, string $status, ?int $access_until, ?int $grace_until, int $cache_expires ): void {
		global $wpdb;
		$tables  = $this->tables();
		$now     = time();
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );
		$day     = static function ( ?int $offset ) use ( $now ): ?string {
			return null === $offset ? null : gmdate( 'Y-m-d H:i:s', $now + $offset * DAY_IN_SECONDS );
		};
		$overrides['paid_until'] = $day( $overrides['paid_until'] );
		$wpdb->update( $tables['licenses'], $overrides, array( 'id' => $license['id'] ) );
		// Entitlements end with the paid period (NULL for a license without an end).
		$wpdb->update( $tables['entitlements'], array( 'ends_at' => $overrides['paid_until'] ), array( 'license_id' => $license['id'] ) );

		$data = EntitlementService::snapshot( $this->license( $license['id'] ), $this->activation( $site['id'] ), $now );

		$this->assertSame( $access, $data['access'] );
		$this->assertSame( $status, $data['license_status'] );
		$this->assertSame( $day( $access_until ), $data['access_until'], 'access_until' );
		$this->assertSame( $day( $grace_until ), $data['grace_until'], 'grace_until' );
		$this->assertSame( $day( $cache_expires ), $data['cache_expires_at'], 'cache_expires_at must be min( now + 14 days, access_until )' );
		$this->assertSame( $day( 0 ), $data['server_time'] );
		$this->assertSame( $access ? array( 'feature.export' => true, 'sites.live_limit' => 1 ) : array(), $this->sorted_map( $data['entitlements'] ) );
		// A-102: the product is part of the signed data.
		$this->assertSame( 'test-product', $data['product_key'] );
		$this->assertSame( 'active', $data['activation_status'] );

		// The route returns the same fields.
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'window-nonce-00000001' ) );
		$routed   = $this->verified_data( $response, $site['secret'], 'window-nonce-00000001' );
		$this->assertSame( array_keys( $data ), array_keys( $routed ) );
		$this->assertSame( $access, $routed['access'] );
	}

	/**
	 * Sort a map by key.
	 *
	 * @param array<string,mixed> $map Map.
	 * @return array<string,mixed>
	 */
	private function sorted_map( array $map ): array {
		ksort( $map );

		return $map;
	}

	public function test_activation_and_check_return_the_same_fields(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$check   = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );

		$this->assertSame( array_keys( $site['response']->get_data()['data'] ), array_keys( $check->get_data()['data'] ) );
		$this->assertSame( 'test-product', $site['response']->get_data()['data']['product_key'] );
	}

	/** B-179: a non-finite entitlement value must not break or blank the signed answer. */
	public function test_non_finite_entitlement_values_are_left_out(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license( array(), array( 'feature.export' => true ) );
		$row     = array( 'license_id' => $license['id'], 'starts_at' => gmdate( 'Y-m-d H:i:s' ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'status' => 'active' );
		$wpdb->insert( $tables['entitlements'], array_merge( $row, array( 'entitlement_key' => 'sites.live_limit', 'value_json' => '1e999' ) ) );
		$wpdb->insert( $tables['entitlements'], array_merge( $row, array( 'entitlement_key' => 'limits.codes', 'value_json' => '-1e999' ) ) );
		$wpdb->insert( $tables['entitlements'], array_merge( $row, array( 'entitlement_key' => 'limits.nested', 'value_json' => '{"a":[1,1e999]}' ) ) );
		$wpdb->insert( $tables['entitlements'], array_merge( $row, array( 'entitlement_key' => 'limits.ok', 'value_json' => '2.5' ) ) );

		$this->assertSame( 1, ActivationService::site_limit( $license['id'], $this->license( $license['id'] ) ) );
		$site = $this->activate_via_api( $license['key'] );
		$data = $this->verified_envelope( $site['response']->get_data(), $site['secret'], $site['uuid'] );

		$this->assertSame( array( 'feature.export' => true, 'limits.ok' => 2.5 ), $this->sorted_map( $data['entitlements'] ) );
		$this->assertNotSame( hash_hmac( 'sha256', '', $site['secret'] ), $site['response']->get_data()['signature'] );

		// If data still cannot be encoded, nothing is signed.
		$broken = Response::signed( array( 'limit' => INF ), $site['secret'], 200, 'nonce-nonce-nonce-01', $site['uuid'] );
		$this->assertSame( 500, $broken->get_status() );
		$this->assertSame( 'response_encoding_failed', $broken->get_data()['code'] );
		$this->assertArrayNotHasKey( 'signature', $broken->get_data() );
	}

	// ------------------------------------------------------------------
	// B-70, A-110: released installations.
	// ------------------------------------------------------------------

	public function test_a_released_installation_gets_signed_refusals_on_every_signed_route(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];

		// Released in the admin or the customer account, not by the installation itself.
		$this->assertTrue( ActivationManager::deactivate( $id, 'Released by support', 1 ) );

		$check = $this->verified_data(
			$this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'released-nonce-0000001' ) ),
			$site['secret'],
			'released-nonce-0000001'
		);
		$this->assertSame( 'deactivated', $check['activation_status'] );
		$this->assertSame( 'deactivated', $check['license_status'] );
		$this->assertFalse( $check['access'] );
		$this->assertNull( $check['access_until'] );
		$this->assertSame( array(), $check['entitlements'] );
		$this->assertSame( $id, $check['activation_id'] );
		$this->assertNotEmpty( $check['deactivated_at'] );

		// Routes that do not handle released installations themselves refuse with a signed proof.
		$update = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $site, 'released-nonce-0000002' ) );
		$this->assertApiError( 403, 'activation_deactivated', $update );
		$signed = $this->verified_envelope( $update->get_data()['data']['signed'], $site['secret'], 'released-nonce-0000002' );
		$this->assertSame( 'deactivated', $signed['license_status'] );
		$this->assertFalse( $signed['access'] );
		$public = ResponseSigner::public_key();
		$this->assertNotSame( '', $public );
		$this->assertTrue(
			ResponseSigner::verify(
				ResponseSigner::payload_message( $site['uuid'], $update->get_data()['data']['signed']['payload'] ),
				$update->get_data()['data']['signed']['payload_signature_ed25519'],
				$public
			)
		);

		// The installation can always confirm its own release and clear its credentials (A-110).
		$delete = $this->verified_data(
			$this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $id, null, $site, 'released-nonce-0000003' ) ),
			$site['secret'],
			'released-nonce-0000003'
		);
		$this->assertSame( array( 'activation_id' => $id, 'status' => 'deactivated' ), $delete );

		// A wrong signature for a released installation stays anonymous.
		$forged = $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $site['uuid'], 'secret' => str_repeat( 'f', 40 ) ) );
		$this->assertApiError( 401, 'invalid_request_signature', $this->dispatch( $forged ) );
		// The slot is free.
		$this->assertSame( 200, $this->activate_via_api( $license['key'] )['response']->get_status() );
	}

	// ------------------------------------------------------------------
	// B-69, A-34: site address in signed requests.
	// ------------------------------------------------------------------

	public function test_deactivation_compares_the_site_address(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://www.shop.example.com/store' ) );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];
		$route   = '/wplc/v1/activations/' . $id;

		// A staging clone carries the copied credentials but lives elsewhere.
		foreach ( array( 'https://staging.shop.example.com/store', 'https://shop.example.com', 'https://clone.example.org/store', 'not a url' ) as $clone ) {
			$response = $this->dispatch( $this->api_request( 'DELETE', $route, array( 'site_url' => $clone ), $site ) );
			$this->assertApiError( 409, 'site_mismatch', $response );
			$this->assertSame( 'active', $this->activation( $id )['status'], $clone );
		}
		$this->assertApiError( 400, 'rest_invalid_param', $this->dispatch( $this->api_request( 'DELETE', $route, array( 'site_url' => array( 'x' ) ), $site ) ) );

		// The site itself, in another spelling.
		$response = $this->dispatch( $this->api_request( 'DELETE', $route, array( 'site_url' => 'http://shop.example.com/Store/' ), $site ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'deactivated', $this->activation( $id )['status'] );
	}

	public function test_entitlement_check_reports_whether_the_site_address_matches(): void {
		$license  = $this->seed_license();
		$site     = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$mismatch = array();
		add_action(
			'wplc/activation_site_mismatch',
			static function ( $activation, $reported ) use ( &$mismatch ) {
				$mismatch[] = array( $activation, $reported );
			},
			10,
			2
		);

		$same  = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array( 'site_url' => 'http://www.shop.example.com/' ), $site ) )->get_data()['data'];
		$clone = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array( 'site_url' => 'https://staging.shop.example.com' ), $site ) )->get_data()['data'];
		$none  = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) )->get_data()['data'];

		$this->assertTrue( $same['site_match'] );
		$this->assertFalse( $clone['site_match'] );
		$this->assertTrue( $clone['access'], 'A mismatch is reported, not refused (B-164 is undecided).' );
		$this->assertArrayNotHasKey( 'site_match', $none );
		$this->assertCount( 1, $mismatch );
		$this->assertSame( 'https://staging.shop.example.com', $mismatch[0][1] );
		$this->assertArrayNotHasKey( 'installation_secret', $mismatch[0][0] );
		$this->assertArrayNotHasKey( 'installation_secret_encrypted', $mismatch[0][0] );
	}

	// ------------------------------------------------------------------
	// B-157, B-160, B-161, B-167, B-168, A-33: who counts as which site.
	// ------------------------------------------------------------------

	public function test_staging_on_an_unrelated_host_counts_as_a_live_site(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2 ) );
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://www.shop-one.com' ) );
		$live_id = (int) $live['response']->get_data()['data']['activation_id'];
		$staging = array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] );

		// A second shop declared as "staging".
		$second = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://www.shop-two.com' ) ) );
		$data   = $this->verified_envelope( $second['response']->get_data(), $second['secret'], $second['uuid'] );
		$this->assertSame( 'production', $data['environment'], 'The signed data must say how the site was counted.' );
		$this->assertNull( $data['staging_parent_id'] );
		$this->assertSame( 2, $data['site_count'] );
		$this->assertSame( 'production', $this->activation( (int) $data['activation_id'] )['environment'] );

		// Repeating the same request is idempotent and stays a live site.
		$again = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://www.shop-two.com', 'installation_id' => $second['uuid'], 'installation_secret' => $second['secret'] ) ) );
		$this->assertSame( $data['activation_id'], $again['response']->get_data()['data']['activation_id'] );
		$this->assertSame( 'production', $again['response']->get_data()['data']['environment'] );

		// With the limit used up it is refused like any live site.
		$third = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://www.shop-three.com' ) ) )['response'];
		$this->assertApiError( 409, 'site_limit', $third );
		$this->assertSame( 'staging', $third->get_data()['data']['requested_environment'] );
		$this->assertSame( 'production', $third->get_data()['data']['environment'] );

		// A real staging copy does not use a slot although the limit is reached.
		foreach ( array( 'https://staging.shop-one.com' ) as $url ) {
			$copy = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => $url ) ) );
			$this->assertSame( 200, $copy['response']->get_status(), $url );
			$this->assertSame( 'staging', $copy['response']->get_data()['data']['environment'] );
			$this->assertSame( $live_id, $copy['response']->get_data()['data']['staging_parent_id'] );
			$this->assertSame( 2, $copy['response']->get_data()['data']['site_count'] );
		}
	}

	public function test_staging_hosts_can_be_approved_by_filter(): void {
		$license = $this->seed_license();
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop-one.com' ) );
		$seen    = array();
		add_filter(
			'wplc_is_staging_host',
			static function ( $allowed, $host, $parent_host ) use ( &$seen ) {
				$seen[] = array( $allowed, $host, $parent_host );
				return 'agency-preview.net' === $host ? true : $allowed;
			},
			10,
			3
		);

		$copy = $this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'], 'site_url' => 'https://agency-preview.net' ) );

		$this->assertSame( 200, $copy['response']->get_status() );
		$this->assertSame( 'staging', $copy['response']->get_data()['data']['environment'] );
		$this->assertSame( array( false, 'agency-preview.net', 'shop-one.com' ), $seen[0] );
	}

	public function test_a_staging_activation_cannot_move_to_an_unrelated_host(): void {
		$license = $this->seed_license();
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop-one.com' ) );
		$staging = array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] );
		$copy    = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://staging.shop-one.com' ) ) );
		$this->assertSame( 200, $copy['response']->get_status() );
		$known = array( 'installation_id' => $copy['uuid'], 'installation_secret' => $copy['secret'] );

		$moved = $this->activate_via_api( $license['key'], array_merge( $staging, $known, array( 'site_url' => 'https://www.shop-two.com' ) ) );
		$this->assertApiError( 409, 'staging_site_invalid', $moved['response'] );
		$this->assertSame( 'https://staging.shop-one.com', $this->activation( (int) $copy['response']->get_data()['data']['activation_id'] )['site_url'] );

		$moved = $this->activate_via_api( $license['key'], array_merge( $staging, $known, array( 'site_url' => 'https://stage.shop-one.com' ) ) );
		$this->assertSame( 200, $moved['response']->get_status() );
	}

	public function test_the_same_site_replaces_its_activation_whatever_scheme_or_www(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$first   = $this->activate_via_api( $license['key'], array( 'site_url' => 'http://www.shop.example.com/Store' ) );
		$this->assertSame( 200, $first['response']->get_status() );
		$first_id = (int) $first['response']->get_data()['data']['activation_id'];

		// B-161: after the switch to HTTPS and without www the one-site license must still activate.
		$second = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com/store/' ) );
		$this->assertSame( 200, $second['response']->get_status(), (string) wp_json_encode( $second['response']->get_data() ) );
		$this->assertSame( 1, $second['response']->get_data()['data']['site_count'] );
		$this->assertSame( 'deactivated', $this->activation( $first_id )['status'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d", 'site_replaced', $first_id ) ) );

		// Another path or subdomain is another site.
		$this->assertApiError( 409, 'site_limit', $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com/other' ) )['response'] );
		$this->assertApiError( 409, 'site_limit', $this->activate_via_api( $license['key'], array( 'site_url' => 'https://b.shop.example.com/store' ) )['response'] );
	}

	/** B-160 */
	public function test_development_sites_with_the_same_address_do_not_replace_each_other(): void {
		$license = $this->seed_license();
		$alice   = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://localhost:8888' ) );
		$bob     = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://localhost:8888' ) );

		$this->assertSame( 200, $alice['response']->get_status() );
		$this->assertSame( 200, $bob['response']->get_status() );
		$this->assertSame( 'active', $this->activation( (int) $alice['response']->get_data()['data']['activation_id'] )['status'], 'The second developer threw the first one out.' );
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $alice ) )->get_status() );
		$this->assertTrue( $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $alice ) )->get_data()['data']['access'] );
	}

	/** A-33 */
	public function test_development_activations_are_capped_per_license(): void {
		$license = $this->seed_license();
		$limit   = ActivationService::development_site_limit( $this->license( $license['id'] ), 1 );
		$this->assertSame( 5, $limit );
		$this->assertSame( 12, ActivationService::development_site_limit( $this->license( $license['id'] ), 6 ) );

		for ( $i = 1; $i <= $limit; $i++ ) {
			$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://dev' . $i . '.local' ) )['response']->get_status(), 'Site ' . $i );
		}
		$over = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://one-more.local' ) )['response'];
		$this->assertApiError( 409, 'development_limit', $over );
		$this->assertSame( $limit, $over->get_data()['data']['development_limit'] );
		$this->assertSame( $limit, $over->get_data()['data']['development_count'] );

		// Development sites still do not use the live slot, and releasing one frees a development slot.
		$live = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $live['response']->get_status() );
		$this->assertSame( 1, $live['response']->get_data()['data']['site_count'] );
		$rows = ActivationCommand::rows( $license['id'] );
		$this->assertSame( 1, ActivationCommand::release_ids( array( (int) $rows[0]['id'] ), 'Unused development site' ) );
		$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://one-more.local' ) )['response']->get_status() );

		add_filter( 'wplc_development_site_limit', '__return_zero' );
		$this->assertApiError( 409, 'development_limit', $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://none.local' ) )['response'] );
	}

	/** B-167 */
	public function test_reactivating_the_live_site_keeps_its_staging_site(): void {
		$license = $this->seed_license();
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$staging = $this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'], 'site_url' => 'https://staging.shop.example.com' ) );
		$this->assertSame( 200, $staging['response']->get_status() );
		$staging_id = (int) $staging['response']->get_data()['data']['activation_id'];
		$old_id     = (int) $live['response']->get_data()['data']['activation_id'];

		// The live site lost its options and activates with a new installation ID.
		$new    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$new_id = (int) $new['response']->get_data()['data']['activation_id'];

		$this->assertSame( 'deactivated', $this->activation( $old_id )['status'] );
		$this->assertSame( 'active', $this->activation( $staging_id )['status'], 'The staging site was released together with the replaced live activation.' );
		$this->assertSame( $new_id, (int) $this->activation( $staging_id )['staging_parent_id'] );
		$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $staging ) )->get_data()['data'];
		$this->assertTrue( $check['access'] );
		$this->assertSame( $new_id, $check['staging_parent_id'] );

		// The staging site can repeat its activation with the new parent, and it still ends with the live site.
		$repeat = $this->activate_via_api( $license['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $new['uuid'], 'site_url' => 'https://staging.shop.example.com', 'installation_id' => $staging['uuid'], 'installation_secret' => $staging['secret'] ) );
		$this->assertSame( 200, $repeat['response']->get_status() );
		$this->assertTrue( ActivationManager::deactivate( $new_id, 'Released' ) );
		$this->assertSame( 'deactivated', $this->activation( $staging_id )['status'] );
	}

	/** B-168 */
	public function test_limits_are_checked_before_an_activation_is_replaced(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 2 ) );
		$a       = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://a.example.com' ) );
		$b       = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://b.example.com' ) );
		$this->assertSame( 200, $b['response']->get_status() );
		$a_id = (int) $a['response']->get_data()['data']['activation_id'];

		// The plan was changed to one site; two are still active.
		$wpdb->update( $tables['entitlements'], array( 'value_json' => '1' ), array( 'license_id' => $license['id'], 'entitlement_key' => 'sites.live_limit' ) );

		$again = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://a.example.com' ) )['response'];
		$this->assertApiError( 409, 'site_limit', $again );
		$this->assertSame( 1, $again->get_data()['data']['site_count'], 'The count leaves out the activation that would be replaced.' );
		$this->assertSame( 'active', $this->activation( $a_id )['status'], 'The site lost its old activation and did not get a new one.' );
		$this->assertCount( 2, $this->active_rows( $license['id'] ) );
	}

	// ------------------------------------------------------------------
	// A-77: audit trail keeps the site address.
	// ------------------------------------------------------------------

	public function test_audit_log_keeps_site_addresses_and_address_changes(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://old.example.com' ) );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];
		$this->activate_via_api( $license['key'], array( 'site_url' => 'https://new.example.com', 'installation_id' => $site['uuid'], 'installation_secret' => $site['secret'] ) );
		// Unchanged address: no further entry.
		$this->activate_via_api( $license['key'], array( 'site_url' => 'https://new.example.com', 'installation_id' => $site['uuid'], 'installation_secret' => $site['secret'] ) );
		ActivationManager::deactivate( $id, 'Released' );

		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT action,before_json,after_json FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", 'activation', $id ), ARRAY_A );
		$this->assertSame( array( 'site_activated', 'site_url_changed', 'site_deactivated' ), array_column( $entries, 'action' ) );
		$this->assertSame( 'https://old.example.com', json_decode( $entries[0]['after_json'], true )['site_url'] );
		$this->assertSame( 'https://old.example.com', json_decode( $entries[1]['before_json'], true )['site_url'] );
		$this->assertSame( 'https://new.example.com', json_decode( $entries[1]['after_json'], true )['site_url'] );
		$this->assertSame( 'https://new.example.com', json_decode( $entries[2]['before_json'], true )['site_url'] );
		$this->assertStringNotContainsString( $site['secret'], (string) wp_json_encode( $entries ) );
	}

	// ------------------------------------------------------------------
	// A-30, A-31, B-67, B-107, B-156: the server's signing key.
	// ------------------------------------------------------------------

	public function test_the_payload_signature_of_the_server_covers_the_nonce(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$public  = ResponseSigner::public_key();
		$first   = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'server-sig-nonce-0001' ) )->get_data();
		$second  = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site, 'server-sig-nonce-0002' ) )->get_data();

		foreach ( array( $site['response']->get_data(), $first, $second ) as $envelope ) {
			$this->assertTrue( ResponseSigner::verify( ResponseSigner::payload_message( $site['uuid'], $envelope['payload'] ), $envelope['payload_signature_ed25519'], $public ) );
			$this->assertSame( ResponseSigner::key_id_of( $public ), $envelope['key_id'] );
			// The legacy signature is still sent for old clients.
			$this->assertTrue( ResponseSigner::verify( ResponseSigner::message( $site['uuid'], (string) wp_json_encode( $envelope['data'], JSON_UNESCAPED_SLASHES ) ), $envelope['signature_ed25519'], $public ) );
		}
		// A-30: a recorded answer does not verify as the answer to a later request.
		$replayed = str_replace( 'server-sig-nonce-0001', 'server-sig-nonce-0002', $first['payload'] );
		$this->assertNotSame( $first['payload'], $replayed );
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::payload_message( $site['uuid'], $replayed ), $first['payload_signature_ed25519'], $public ) );
		$this->assertNotSame( $first['payload_signature_ed25519'], $second['payload_signature_ed25519'] );
	}

	public function test_the_signing_key_is_created_once_encrypted_and_audited(): void {
		global $wpdb;
		$tables = $this->tables();
		delete_option( ResponseSigner::OPTION );
		delete_option( ResponseSigner::KEY_ID_OPTION );
		ResponseSigner::reset();
		$created = array();
		add_action(
			'wplc/response_signing_key_created',
			static function ( $key_id ) use ( &$created ) {
				$created[] = $key_id;
			}
		);

		$status = ResponseSigner::status();

		$this->assertSame( 'ok', $status['state'] );
		$this->assertSame( 'option', $status['source'] );
		$stored = (string) get_option( ResponseSigner::OPTION );
		$this->assertStringStartsWith( 'v1:', $stored, 'B-156: the private key must only be stored encrypted.' );
		$this->assertSame( $status['key_id'], get_option( ResponseSigner::KEY_ID_OPTION ) );
		$this->assertSame( array( $status['key_id'] ), $created );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s", 'response_signing_key_created' ) ) );

		// A second request (other process: empty static cache) reads the same key instead of creating one.
		ResponseSigner::reset();
		wp_cache_flush();
		$this->assertSame( $status['public_key'], ResponseSigner::public_key() );
		$this->assertSame( $stored, get_option( ResponseSigner::OPTION ) );
		$this->assertCount( 1, $created );
	}

	/** B-107: a request that lost the race uses the key the winner stored, even with a stale option cache. */
	public function test_key_creation_reads_back_what_another_request_stored(): void {
		global $wpdb;
		delete_option( ResponseSigner::OPTION );
		delete_option( ResponseSigner::KEY_ID_OPTION );
		ResponseSigner::reset();

		// This request has already cached "the option does not exist".
		$this->assertSame( '', (string) get_option( ResponseSigner::OPTION, '' ) );
		// Meanwhile another request stores its key directly in the database.
		$winner    = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x05", 32 ) ) );
		$encrypted = SecretCrypto::encrypt( base64_encode( $winner ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => ResponseSigner::OPTION, 'option_value' => $encrypted, 'autoload' => 'off' ) );

		$this->assertSame( ResponseSigner::public_key_of( $winner ), ResponseSigner::public_key(), 'Two parallel first requests would sign with different keys.' );
		$this->assertSame( $encrypted, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", ResponseSigner::OPTION ) ) );
	}

	public function test_a_lost_or_undecryptable_signing_key_is_never_replaced_silently(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'] );
		$this->assertArrayHasKey( 'payload_signature_ed25519', $site['response']->get_data() );
		$old_public  = ResponseSigner::public_key();
		$unavailable = array();
		add_action(
			'wplc/response_signing_key_unavailable',
			static function ( $state ) use ( &$unavailable ) {
				$unavailable[] = $state;
			}
		);

		// Encryption keys or salts changed: the stored value cannot be decrypted any more.
		$undecryptable = 'v1:retired-key:' . base64_encode( random_bytes( 80 ) );
		update_option( ResponseSigner::OPTION, $undecryptable, false );
		ResponseSigner::reset();
		$this->assertSame( 'undecryptable', ResponseSigner::status()['state'] );
		$this->assertSame( '', ResponseSigner::public_key() );
		$this->assertSame( $undecryptable, get_option( ResponseSigner::OPTION ), 'The stored key was overwritten.' );

		// Answers still work, signed with the installation secret only.
		$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );
		$this->assertSame( 200, $check->get_status() );
		$this->assertArrayNotHasKey( 'payload_signature_ed25519', $check->get_data() );
		$this->assertArrayNotHasKey( 'signature_ed25519', $check->get_data() );
		$this->assertArrayNotHasKey( 'key_id', $check->get_data() );
		$this->assertArrayHasKey( 'payload_signature', $check->get_data() );

		// The option is gone although this server had a key.
		delete_option( ResponseSigner::OPTION );
		ResponseSigner::reset();
		$this->assertSame( 'missing', ResponseSigner::status()['state'] );
		$this->assertFalse( get_option( ResponseSigner::OPTION, false ), 'A new key was generated silently; pinned clients would be locked out.' );

		// Damaged content.
		update_option( ResponseSigner::OPTION, SecretCrypto::encrypt( base64_encode( str_repeat( "\x07", 64 ) ) ), false );
		ResponseSigner::reset();
		$this->assertSame( 'corrupt', ResponseSigner::status()['state'] );

		$this->assertSame( array( 'undecryptable', 'missing', 'corrupt' ), $unavailable );

		// Only a deliberate rotation creates a new key.
		ResponseSigner::reset();
		$this->assertNotNull( ResponseSigner::rotate() );
		$status = ResponseSigner::status();
		$this->assertSame( 'ok', $status['state'] );
		$this->assertNotSame( $old_public, $status['public_key'] );
		$this->assertStringStartsWith( 'v1:', (string) get_option( ResponseSigner::OPTION ) );
		$this->assertSame( $status['key_id'], get_option( ResponseSigner::KEY_ID_OPTION ) );
	}

	/** B-156: a plaintext key left by an earlier version is encrypted when it is read. */
	public function test_a_plaintext_signing_key_is_encrypted_on_read(): void {
		$secret = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x03", 32 ) ) );
		update_option( ResponseSigner::OPTION, base64_encode( $secret ), false );
		delete_option( ResponseSigner::KEY_ID_OPTION );
		ResponseSigner::reset();

		$this->assertSame( ResponseSigner::public_key_of( $secret ), ResponseSigner::public_key() );
		$stored = (string) get_option( ResponseSigner::OPTION );
		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertStringNotContainsString( base64_encode( $secret ), $stored );
		$this->assertSame( base64_encode( $secret ), SecretCrypto::decrypt( $stored ) );
		$this->assertSame( 'current', ResponseSigner::reencrypt_stored_key( true ) );
	}

	// ------------------------------------------------------------------
	// B-22: key rotation command.
	// ------------------------------------------------------------------

	/**
	 * Encrypt like a version before the key-ID envelope (no "v1:" prefix, no AAD).
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	private function legacy_envelope( string $plaintext ): string {
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$key        = hash( 'sha256', 'integration-only-encryption-secret', true );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );

		return base64_encode( $nonce . $tag . $ciphertext );
	}

	public function test_rotation_inspects_all_rows_and_reports_what_is_left(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$ids     = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$secret = 'legacy-secret-' . $i . '-' . str_repeat( 'x', 30 );
			$ids[]  = $this->seed_activation( $license['id'], array( 'installation_secret_hash' => hash( 'sha256', $secret ), 'installation_secret_encrypted' => $this->legacy_envelope( $secret ) ) )['id'];
		}
		$current = $this->seed_activation( $license['id'] );
		$broken  = $this->seed_activation( $license['id'], array( 'installation_secret_encrypted' => 'v1:retired-key:' . base64_encode( random_bytes( 60 ) ) ) );
		ResponseSigner::public_key();

		$this->assertSame( 8, RotateInstallationSecretsCommand::pending_count() );

		// Dry run: nothing changes, everything is counted, and the run is not "done".
		$dry = RotateInstallationSecretsCommand::run( array( 'batch_size' => 3 ) );
		$this->assertFalse( $dry['apply'] );
		$this->assertSame( array( 9, 7, 1, 1, 0 ), array( $dry['inspected'], $dry['rotated'], $dry['already_current'], $dry['failed'], $dry['not_inspected'] ) );
		$this->assertSame( array( $broken['id'] ), $dry['failed_ids'] );
		$this->assertSame( 8, $dry['remaining'] );
		$this->assertSame( 8, RotateInstallationSecretsCommand::pending_count() );

		// A limited run must say that rows were not inspected (the old default silently stopped after 5000).
		$part = RotateInstallationSecretsCommand::run( array( 'apply' => true, 'batch_size' => 2, 'max_rows' => 3 ) );
		$this->assertSame( array( 3, 3, 6 ), array( $part['inspected'], $part['rotated'], $part['not_inspected'] ) );
		$this->assertGreaterThan( 0, $part['remaining'] );
		$this->assertSame( $ids[2], $part['last_id'] );

		// Continue where it stopped; the unreadable row stays and keeps the result non-zero.
		$rest = RotateInstallationSecretsCommand::run( array( 'apply' => true, 'after_id' => $part['last_id'] ) );
		$this->assertSame( array( 6, 4, 1, 1, 0 ), array( $rest['inspected'], $rest['rotated'], $rest['already_current'], $rest['failed'], $rest['not_inspected'] ) );
		$this->assertSame( 1, $rest['remaining'] );
		$this->assertSame( 1, RotateInstallationSecretsCommand::pending_count() );
		foreach ( $ids as $id ) {
			$this->assertFalse( SecretCrypto::needs_rotation( $this->activation( $id )['installation_secret_encrypted'] ) );
			$this->assertSame( $this->activation( $id )['installation_secret_hash'], hash( 'sha256', (string) SecretCrypto::decrypt( $this->activation( $id )['installation_secret_encrypted'] ) ) );
		}
		$this->assertSame( 7, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s", 'installation_secret_rotated' ) ) );

		// Only when nothing is left the run reports completion.
		$wpdb->delete( $tables['activations'], array( 'id' => $broken['id'] ) );
		$done = RotateInstallationSecretsCommand::run();
		$this->assertSame( 0, $done['remaining'] );
		$this->assertSame( 'current', $done['signing_key'] );
		$this->assertSame( 0, RotateInstallationSecretsCommand::pending_count() );
		$this->assertSame( $current['id'], $done['last_id'] );
	}

	/** A-31: the stored signing key is part of the rotation. */
	public function test_rotation_reencrypts_the_stored_signing_key(): void {
		$secret = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x09", 32 ) ) );
		update_option( ResponseSigner::OPTION, $this->legacy_envelope( base64_encode( $secret ) ), false );
		ResponseSigner::reset();
		// A legacy envelope is not recognized as encrypted by its prefix, so the dry run treats it like plaintext input:
		// it must be a usable key to be rotated, otherwise the run fails loudly.
		$this->assertSame( 'failed', ResponseSigner::reencrypt_stored_key( false ) );
		$this->assertSame( 1, RotateInstallationSecretsCommand::run()['remaining'] );

		update_option( ResponseSigner::OPTION, base64_encode( $secret ), false );
		$this->assertSame( 'pending', ResponseSigner::reencrypt_stored_key( false ) );
		$this->assertSame( 'pending', RotateInstallationSecretsCommand::run()['signing_key'] );
		$applied = RotateInstallationSecretsCommand::run( array( 'apply' => true ) );
		$this->assertSame( 'rotated', $applied['signing_key'] );
		$this->assertSame( 0, $applied['remaining'] );
		$this->assertStringStartsWith( 'v1:' . SecretCrypto::active_key_id() . ':', (string) get_option( ResponseSigner::OPTION ) );
		ResponseSigner::reset();
		$this->assertSame( ResponseSigner::public_key_of( $secret ), ResponseSigner::public_key() );
	}

	// ------------------------------------------------------------------
	// B-165, B-24, B-64, T-146, A-74, B-172: helpers for diagnostics and support.
	// ------------------------------------------------------------------

	public function test_lock_self_test_takes_and_releases_a_lock(): void {
		global $wpdb;

		$this->assertTrue( ActivationManager::lock_self_test() );
		$this->assertTrue( ActivationManager::lock_self_test(), 'The lock of the first run was not released.' );

		// A server that refuses named locks.
		$refuse = static function ( $query ) {
			return false !== strpos( $query, 'GET_LOCK' ) ? 'SELECT 0' : $query;
		};
		add_filter( 'query', $refuse );
		$this->assertFalse( ActivationManager::lock_self_test() );
		$license = $this->seed_license();
		$busy    = $this->activate_via_api( $license['key'] )['response'];
		remove_filter( 'query', $refuse );

		$this->assertApiError( 503, 'retry_later', $busy );
		$this->assertSame( '5', (string) $busy->get_headers()['Retry-After'] );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['activations']}" ) );
	}

	public function test_idn_hosts_are_encoded_without_the_intl_extension(): void {
		$this->assertSame( 'xn--mnchen-3ya.de', SiteUrl::to_ascii( 'münchen.de', false ) );
		$this->assertSame( 'xn--mnchen-3ya.de', SiteUrl::to_ascii( 'MÜNCHEN.de', false ) );
		$this->assertSame( 'shop.xn--r8jz45g.jp', SiteUrl::to_ascii( 'shop.例え.jp', false ) );
		$this->assertSame( 'example.com', SiteUrl::to_ascii( 'Example.COM', false ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( SiteUrl::to_ascii( 'münchen.de', true ), SiteUrl::to_ascii( 'münchen.de', false ) );
		}
		$this->assertSame( 'https://xn--mnchen-3ya.de/shop', SiteUrl::normalize( 'https://MÜNCHEN.de/shop/' ) );
	}

	public function test_license_key_verification_sample(): void {
		global $wpdb;
		$this->seed_license();
		$this->seed_license();
		$broken = $this->seed_license();
		// A license whose key was derived with a secret this server no longer has.
		$wpdb->update( $this->tables()['licenses'], array( 'license_key_secret_id' => 'retired-2019' ), array( 'id' => $broken['id'] ) );

		$this->assertSame( array( 'checked' => 3, 'verified' => 2 ), LicenseKey::verification_sample() );
		$this->assertSame( array( 'checked' => 1, 'verified' => 0 ), LicenseKey::verification_sample( 1 ) );
	}

	public function test_support_views_of_a_license(): void {
		$license = $this->seed_license();
		$site    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://shop.example.com' ) );
		$id      = (int) $site['response']->get_data()['data']['activation_id'];

		foreach ( array( (string) $license['id'], $license['key'], ' ' . strtolower( $license['key'] ) . "\n", $license['uuid'] ) as $reference ) {
			$details = LicenseCommand::details( $reference );
			$this->assertIsArray( $details, $reference );
			$this->assertSame( $license['id'], $details['license']['id'] );
		}
		$this->assertTrue( $details['license']['has_access'] );
		$this->assertTrue( $details['license']['key_verifiable'] );
		$this->assertSame( 'https://shop.example.com', $details['activations'][0]['site_url'] );
		$json = (string) wp_json_encode( $details );
		$this->assertStringNotContainsString( $license['key'], $json, 'The license key must not be printed.' );
		$this->assertStringNotContainsString( $site['secret'], $json );
		$this->assertStringNotContainsString( 'installation_secret', $json );
		$this->assertNull( LicenseCommand::details( '999999999' ) );
		$this->assertNull( LicenseCommand::details( '' ) );

		// B-172: scripted reset of activations.
		$this->assertCount( 1, ActivationCommand::rows( $license['id'] ) );
		$this->assertSame( 1, ActivationCommand::release_ids( array( $id, $id, 0 ), 'Reset for a test' ) );
		$this->assertCount( 0, ActivationCommand::rows( $license['id'] ) );
		$this->assertCount( 1, ActivationCommand::rows( $license['id'], 'deactivated' ) );
		$this->assertCount( 1, ActivationCommand::rows( $license['id'], 'all' ) );
		$this->assertSame( 0, ActivationCommand::release_ids( array( $id ), 'again' ) );
	}
}
