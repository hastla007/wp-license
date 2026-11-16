<?php
/**
 * T-31: releases in S3-compatible object storage, delivered through pre-signed URLs.
 *
 * No test reaches the network: every HTTP request is answered by a pre_http_request mock.
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Api\V1\UpdateService;
use WPLC\CLI\ReleaseCommand;
use WPLC\CLI\StorageCommand;
use WPLC\Update\CustomerDownload;
use WPLC\Update\ReleaseRegistry;
use WPLC\Update\S3Storage;

final class S3StorageIntegrationTest extends WPLC_Integration_TestCase {

	/** Secret of the test configuration; must never show up anywhere. */
	private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY-it-only';

	/** Access key ID of the test configuration. */
	private const ACCESS_KEY_ID = 'AKIDINTEGRATIONTEST';

	/** Request host of the bucket (path-style MinIO-like endpoint). */
	private const HOST = 'objects.example.com';

	/**
	 * Requests seen by the HTTP mock: method and URL.
	 *
	 * @var array<int,array{method:string,url:string}>
	 */
	private $requests = array();

	/**
	 * Bytes the HTTP mock serves for GET.
	 *
	 * @var string
	 */
	private $served = '';

	/**
	 * Status the HTTP mock answers.
	 *
	 * @var int
	 */
	private $answer_status = 200;

	/**
	 * Messages written to the WooCommerce log during the test.
	 *
	 * @var string[]
	 */
	private $logged = array();

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Previous value of $_SERVER['HTTPS'].
	 *
	 * @var mixed
	 */
	private $https_before;

	/**
	 * Previous PHP error_log target.
	 *
	 * @var string|false
	 */
	private $error_log_before = false;

	/**
	 * PHP error_log file of this test.
	 *
	 * @var string
	 */
	private $error_log_file = '';

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->https_before = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS']   = 'on';
		S3Storage::override_config(
			array(
				'endpoint'          => 'https://' . self::HOST,
				'region'            => 'eu-central-1',
				'bucket'            => 'wplc-releases',
				'access_key_id'     => self::ACCESS_KEY_ID,
				'secret_access_key' => self::SECRET,
				'path_style'        => true,
			)
		);

		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
					'url'    => (string) $url,
				);
				if ( self::HOST !== wp_parse_url( (string) $url, PHP_URL_HOST ) ) {
					return new WP_Error( 'http_request_blocked', 'HTTP is blocked in tests.' );
				}
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $this->served ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => $this->answer_status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			0,
			3
		);

		// Everything the plugin logs, at every level.
		add_filter( 'wplc_log_debug', '__return_true' );
		$this->logged = array();
		add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level, $context ) {
				$this->logged[] = $level . ' ' . $message . ' ' . wp_json_encode( $context );
				return $message;
			},
			10,
			3
		);
		$this->error_log_file   = (string) wp_tempnam( 'wplc-s3-error-log' );
		$this->files[]          = $this->error_log_file;
		$this->error_log_before = ini_set( 'error_log', $this->error_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->tables()['products'],
			array(
				'product_key'  => 'test-product',
				'display_name' => 'Test Product',
				'active'       => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
	}

	public function tear_down(): void {
		S3Storage::override_config( null );
		if ( false !== $this->error_log_before ) {
			ini_set( 'error_log', (string) $this->error_log_before ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		if ( null === $this->https_before ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_before;
		}
		unset( $_SERVER['REQUEST_METHOD'] );
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Plugin ZIP for the test product.
	 *
	 * @param string $version Version header.
	 * @return string Bytes of the ZIP.
	 */
	private function zip_bytes( string $version ): string {
		$path          = sys_get_temp_dir() . '/wplc-s3-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'test-product/test-product.php', "<?php\n/**\n * Plugin Name: Test Product\n * Version: {$version}\n */\n" );
		$zip->close();

		return (string) file_get_contents( $path );
	}

	/**
	 * Create and publish a release whose package is an object in the bucket.
	 *
	 * @param string $version Version.
	 * @param string $key     Object key.
	 * @return int Release ID.
	 */
	private function s3_release( string $version, string $key ): int {
		$this->served = $this->zip_bytes( $version );
		$id           = ReleaseRegistry::create(
			array(
				'product_key' => 'test-product',
				'version'     => $version,
				'storage'     => 'url',
				'package_url' => 's3://wplc-releases/' . $key,
				'sha256'      => hash( 'sha256', $this->served ),
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_code() : '' );
		$published = ReleaseRegistry::publish( $id, 0, 'test' );
		$this->assertTrue( $published, is_wp_error( $published ) ? $published->get_error_code() . ': ' . $published->get_error_message() : '' );

		return $id;
	}

	/**
	 * POST /updates/check with download=true and return the signed data.
	 *
	 * @param array<string,mixed> $site Installation credentials.
	 * @return WP_REST_Response
	 */
	private function check( array $site ): WP_REST_Response {
		return $this->dispatch(
			$this->api_request(
				'POST',
				'/wplc/v1/updates/check',
				array(
					'product_key'     => 'test-product',
					'current_version' => '1.0.0',
				),
				$site
			)
		);
	}

	/**
	 * Redeem a grant URL.
	 *
	 * @param string $package_url package_url from an update check.
	 * @return WPLC_Test_Halt
	 */
	private function redeem( string $package_url ): WPLC_Test_Halt {
		$this->assertSame( 1, preg_match( '#/([a-f0-9]{64})$#', $package_url, $match ), 'No grant in ' . $package_url );
		$this->halt_instead_of_exit();
		try {
			$this->dispatch( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $match[1] ) );
		} catch ( WPLC_Test_Halt $halt ) {
			return $halt;
		}
		$this->fail( 'The download handler returned instead of redirecting or sending a status.' );
	}

	/**
	 * Assert that a URL is a fresh pre-signed GET URL for the key.
	 *
	 * @param string $url URL.
	 * @param string $encoded_path Expected encoded path.
	 * @return void
	 */
	private function assertPresigned( string $url, string $encoded_path ): void {
		$parts = wp_parse_url( $url );
		$this->assertSame( 'https', $parts['scheme'] );
		$this->assertSame( self::HOST, $parts['host'] );
		$this->assertSame( $encoded_path, $parts['path'] );
		parse_str( (string) $parts['query'], $query );
		$this->assertSame( 'AWS4-HMAC-SHA256', $query['X-Amz-Algorithm'] );
		$this->assertSame( '300', $query['X-Amz-Expires'], 'Default validity: five minutes.' );
		$this->assertSame( 'host', $query['X-Amz-SignedHeaders'] );
		$this->assertStringStartsWith( self::ACCESS_KEY_ID . '/' . gmdate( 'Ymd' ) . '/eu-central-1/s3/aws4_request', $query['X-Amz-Credential'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $query['X-Amz-Signature'] );
		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( (string) $query['X-Amz-Date'] ) ) );
	}

	/**
	 * Assert that the secret appears in no response, log, audit row or option.
	 *
	 * @param string[] $outputs Response bodies, headers and locations seen by the test.
	 * @return void
	 */
	private function assertSecretNowhere( array $outputs ): void {
		global $wpdb;
		$needles = array( self::SECRET, rawurlencode( self::SECRET ) );
		$haystacks = array_merge(
			$outputs,
			$this->logged,
			array(
				(string) file_get_contents( $this->error_log_file ),
				(string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$this->tables()['audit_log']}", ARRAY_A ) ),
				(string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$this->tables()['download_tokens']}", ARRAY_A ) ),
				(string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$this->tables()['releases']}", ARRAY_A ) ),
				(string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A ) ),
			)
		);
		foreach ( $haystacks as $index => $haystack ) {
			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, $haystack, 'The secret leaked into output #' . $index . '.' );
			}
		}
		// Signed links are not written to the audit log or the release log either.
		$this->assertStringNotContainsString( 'X-Amz-Signature', (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$this->tables()['audit_log']}", ARRAY_A ) ) );
	}

	// ------------------------------------------------------------------
	// Tests.
	// ------------------------------------------------------------------

	public function test_t31_s3_release_is_verified_on_publish_through_a_presigned_url(): void {
		$id      = $this->s3_release( '1.1.0', 'test-product/test product 1.1.0 ü.zip' );
		$release = ReleaseRegistry::get( $id );
		$this->assertSame( 'https://' . self::HOST . '/wplc-releases/test-product/test%20product%201.1.0%20%C3%BC.zip', $release['package_url'], 'The release stores the permanent object URL.' );
		$this->assertSame( 'test-product/test-product.php', $release['plugin_file'] );

		// The publish check downloaded the object once, through a signed GET.
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertPresigned( $this->requests[0]['url'], '/wplc-releases/test-product/test%20product%201.1.0%20%C3%BC.zip' );
		$this->assertTrue( UpdateService::has_package_resolver(), 'The configured bucket counts as resolver: no "not delivered" warning.' );
		$this->assertSame( array(), array_values( array_filter( ReleaseRegistry::problems(), static function ( $problem ) { return 'untrusted_package_host' === $problem['code']; } ) ) );
	}

	public function test_t31_entitled_license_is_redirected_to_a_presigned_url_and_others_are_refused(): void {
		$this->s3_release( '1.1.0', 'test-product/test-product-1.1.0.zip' );
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );

		$check = $this->check( $site );
		$this->assertSame( 200, $check->get_status(), (string) wp_json_encode( $check->get_data() ) );
		$data = $check->get_data()['data'];
		$this->assertTrue( $data['update_available'] );
		$this->assertStringContainsString( '/wplc/v1/updates/download/', $data['package_url'], 'The client gets the grant URL, never the object URL.' );
		$this->assertStringNotContainsString( self::HOST, (string) wp_json_encode( $check->get_data() ) );

		$halt = $this->redeem( $data['package_url'] );
		$this->assertSame( 'redirect', $halt->kind, 'status ' . $halt->status );
		$this->assertSame( 302, $halt->status );
		$this->assertPresigned( $halt->location, '/wplc-releases/test-product/test-product-1.1.0.zip' );

		// Used up: the same grant does not redirect again.
		$again = $this->redeem( $data['package_url'] );
		$this->assertSame( array( 'status', 404 ), array( $again->kind, $again->status ) );

		// A revoked license: the grant issued before is refused and no URL is signed.
		$second = $this->check( $site )->get_data()['data']['package_url'];
		$GLOBALS['wpdb']->update( $this->tables()['licenses'], array( 'status' => 'revoked' ), array( 'id' => $license['id'] ) );
		$refused = $this->redeem( $second );
		$this->assertSame( array( 'status', 404 ), array( $refused->kind, $refused->status ) );
		$revoked = $this->check( $site )->get_data()['data'];
		$this->assertSame( '', $revoked['package_url'] );
		$this->assertSame( 'license_revoked', $revoked['reason'] );

		// A license of another product gets nothing.
		$other      = $this->seed_license( array( 'product_key' => 'other-product' ) );
		$other_site = $this->seed_activation( $other['id'] );
		$this->assertSame( 403, $this->check( $other_site )->get_status() );

		// An expired license learns the version but gets no package.
		$expired      = $this->seed_license(
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ),
			)
		);
		$expired_data = $this->check( $this->seed_activation( $expired['id'] ) )->get_data()['data'];
		$this->assertSame( array( '', 'license_expired' ), array( $expired_data['package_url'], $expired_data['reason'] ) );

		$this->assertSecretNowhere( array( (string) wp_json_encode( $check->get_data() ), $halt->location ) );
	}

	public function test_t31_validity_is_filterable_and_capped_at_seven_days(): void {
		$this->s3_release( '1.1.0', 'test-product/test-product-1.1.0.zip' );
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );

		add_filter( 'wplc_s3_presign_expires', static function () { return 60; } );
		parse_str( (string) wp_parse_url( $this->redeem( $this->check( $site )->get_data()['data']['package_url'] )->location, PHP_URL_QUERY ), $short );
		$this->assertSame( '60', $short['X-Amz-Expires'] );

		add_filter( 'wplc_s3_presign_expires', static function () { return 30 * DAY_IN_SECONDS; }, 20 );
		parse_str( (string) wp_parse_url( $this->redeem( $this->check( $site )->get_data()['data']['package_url'] )->location, PHP_URL_QUERY ), $long );
		$this->assertSame( '604800', $long['X-Amz-Expires'] );
	}

	public function test_t31_custom_resolver_keeps_precedence(): void {
		$this->s3_release( '1.1.0', 'test-product/test-product-1.1.0.zip' );
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );

		$custom = 'https://' . self::HOST . '/wplc-releases/test-product/test-product-1.1.0.zip?signed-by=custom';
		add_filter(
			'wplc_resolve_update_package_url',
			static function () use ( $custom ) {
				return $custom;
			}
		);
		$halt = $this->redeem( $this->check( $site )->get_data()['data']['package_url'] );
		$this->assertSame( array( 'redirect', $custom ), array( $halt->kind, $halt->location ), 'A URL changed by a custom resolver is not signed again.' );
	}

	public function test_t31_without_configuration_the_object_url_is_not_delivered(): void {
		$this->s3_release( '1.1.0', 'test-product/test-product-1.1.0.zip' );
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );
		$grant   = $this->check( $site )->get_data()['data']['package_url'];

		// The constants were removed after publishing: the bucket host is no longer trusted, nothing is redirected.
		S3Storage::override_config( array() );
		$this->assertFalse( UpdateService::has_package_resolver() );
		$halt = $this->redeem( $grant );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		$this->assertSame( 'object_storage_unavailable', ReleaseRegistry::create(
			array(
				'product_key' => 'test-product',
				'version'     => '1.2.0',
				'storage'     => 'url',
				'package_url' => 's3://wplc-releases/test-product/test-product-1.2.0.zip',
				'sha256'      => str_repeat( 'a', 64 ),
			)
		)->get_error_code() );
	}

	public function test_t31_customer_download_redirects_to_a_presigned_url(): void {
		global $wpdb;
		$this->s3_release( '1.1.0', 'test-product/test-product-1.1.0.zip' );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer ) );
		wp_set_current_user( $customer );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$served = $this->run_admin_post(
			CustomerDownload::ACTION,
			array(
				'license_id' => $license['id'],
				'_wpnonce'   => wp_create_nonce( CustomerDownload::nonce_action( $license['id'] ) ),
			)
		);
		$this->assertSame( 'redirect', $served['outcome'], $served['message'] );
		$this->assertPresigned( $served['location'], '/wplc-releases/test-product/test-product-1.1.0.zip' );
		$audit = $wpdb->get_var( $wpdb->prepare( "SELECT after_json FROM {$this->tables()['audit_log']} WHERE action = %s AND object_id = %d", 'package_downloaded', $license['id'] ) );
		$this->assertStringContainsString( '"storage":"url"', (string) $audit );

		// Somebody else's license: refused before anything is signed.
		$requests = count( $this->requests );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$refused = $this->run_admin_post(
			CustomerDownload::ACTION,
			array(
				'license_id' => $license['id'],
				'_wpnonce'   => wp_create_nonce( CustomerDownload::nonce_action( $license['id'] ) ),
			)
		);
		$this->assertSame( array( 'die', 403 ), array( $refused['outcome'], $refused['status'] ) );
		$this->assertCount( $requests, $this->requests );

		$this->assertSecretNowhere( array( $served['location'], $refused['message'] ) );
	}

	public function test_t31_cli_registers_an_object_and_checks_the_storage(): void {
		$this->served = $this->zip_bytes( '1.3.0' );
		$created      = ReleaseCommand::run_create(
			array(
				'product' => 'test-product',
				'version' => '1.3.0',
				'object'  => 'test-product/test-product-1.3.0.zip',
				'sha256'  => hash( 'sha256', $this->served ),
				'publish' => true,
			)
		);
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertTrue( $created['published'] );
		$this->assertSame( 'https://' . self::HOST . '/wplc-releases/test-product/test-product-1.3.0.zip', ReleaseRegistry::get( $created['release_id'] )['package_url'] );
		$this->assertSame( 'zip_and_object', ReleaseCommand::run_create( array( 'object' => 'a.zip', 'zip' => '/tmp/a.zip' ) )->get_error_code() );
		$this->assertSame( 'invalid_sha256', ReleaseCommand::run_create( array( 'product' => 'test-product', 'version' => '1.4.0', 'object' => 's3://wplc-releases/b.zip' ) )->get_error_code() );

		// Storage check: a signed HEAD, the answer is mapped, nothing secret is printed.
		$this->requests = array();
		$ok             = StorageCommand::run_check( '' );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'HEAD', $this->requests[0]['method'] );
		$parts = wp_parse_url( $this->requests[0]['url'] );
		$this->assertSame( array( self::HOST, '/wplc-releases' ), array( $parts['host'], $parts['path'] ) );
		$this->assertStringContainsString( 'X-Amz-Signature=', $parts['query'] );
		$this->assertContains( 'Bucket: wplc-releases', $ok['lines'] );
		$this->assertContains( 'Endpoint host: ' . self::HOST, $ok['lines'] );

		$this->answer_status = 403;
		$denied              = StorageCommand::run_check( 'test-product/test-product-1.3.0.zip' );
		$this->assertSame( array( false, 'denied' ), array( $denied['ok'], $denied['code'] ) );
		$this->assertSame( '/wplc-releases/test-product/test-product-1.3.0.zip', wp_parse_url( end( $this->requests )['url'], PHP_URL_PATH ) );
		$this->answer_status = 404;
		$this->assertSame( 'not_found', StorageCommand::run_check( '' )['code'] );
		$this->answer_status = 301;
		$this->assertSame( 'wrong_endpoint', StorageCommand::run_check( '' )['code'] );
		$this->assertSame( 'invalid_key', StorageCommand::run_check( 'a/../b' )['code'] );

		$outputs = array();
		foreach ( array( $ok, $denied ) as $result ) {
			$outputs[] = $result['message'] . implode( "\n", $result['lines'] );
			$this->assertStringNotContainsString( 'X-Amz-Signature', $result['message'] . implode( "\n", $result['lines'] ) );
		}
		$this->assertSecretNowhere( $outputs );

		S3Storage::override_config( array() );
		$this->assertSame( array( false, 'not_configured', array() ), array_values( array_intersect_key( StorageCommand::run_check( '' ), array_flip( array( 'ok', 'code', 'lines' ) ) ) ) );
	}

	public function test_t31_diagnostics_show_bucket_and_host_but_no_credentials(): void {
		$rows = array();
		foreach ( Diagnostics::rows() as $row ) {
			$rows[ $row['label'] ] = $row;
		}
		$this->assertSame( 'Bucket wplc-releases on ' . self::HOST, $rows['Object storage (S3-compatible)']['value'] );
		$this->assertSame( 'ok', $rows['Object storage (S3-compatible)']['state'] );
		$this->assertStringNotContainsString( self::ACCESS_KEY_ID, (string) wp_json_encode( Diagnostics::rows() ) );
		$this->assertSecretNowhere( array( (string) wp_json_encode( Diagnostics::rows() ) ) );

		S3Storage::override_config( array( 'bucket' => 'wplc-releases' ) );
		$rows = array();
		foreach ( Diagnostics::rows() as $row ) {
			$rows[ $row['label'] ] = $row;
		}
		$this->assertSame( array( 'Invalid configuration', 'warning' ), array( $rows['Object storage (S3-compatible)']['value'], $rows['Object storage (S3-compatible)']['state'] ) );

		S3Storage::override_config( array() );
		$rows = array();
		foreach ( Diagnostics::rows() as $row ) {
			$rows[ $row['label'] ] = $row;
		}
		$this->assertSame( 'Not configured', $rows['Object storage (S3-compatible)']['value'] );
	}
}
