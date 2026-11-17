<?php
/**
 * T-31: Signature Version 4 presigning for S3-compatible object storage.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Update\ReleaseRegistry;
use WPLC\Update\S3Presigner;
use WPLC\Update\S3Storage;

final class S3PresignerTest extends TestCase {

	/** Secret of the AWS Signature Version 4 test suite. */
	private const SUITE_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	/** Fixed clock of the derived examples: 2026-11-16T14:38:35Z. */
	private const FIXED_TIME = 1794839915;

	/** Key with a space, a plus sign and a non-ASCII character. */
	private const UNICODE_KEY = 'releases/acme pro/ü 1.0+beta.zip';

	protected function tearDown(): void {
		S3Storage::override_config( null );
		parent::tearDown();
	}

	/**
	 * Presigner with the test suite credentials.
	 *
	 * @param string $host       Endpoint host.
	 * @param string $region     Region.
	 * @param bool   $path_style Path-style addressing.
	 * @return S3Presigner
	 */
	private function presigner( string $host, string $region, bool $path_style ): S3Presigner {
		return new S3Presigner( $host, $region, 'wplc-releases', 'AKIDEXAMPLE', self::SUITE_SECRET, $path_style );
	}

	public function test_fixed_time_constant_is_the_documented_date(): void {
		$this->assertSame( '20261116T143835Z', gmdate( 'Ymd\THis\Z', self::FIXED_TIME ) );
	}

	// AWS S3 documentation, "Authenticating Requests: Using Query Parameters (AWS Signature Version 4)", example.
	public function test_aws_documentation_presigned_get_example(): void {
		$presigner = new S3Presigner( 's3.amazonaws.com', 'us-east-1', 'examplebucket', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', false );

		$this->assertSame(
			'https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
			$presigner->presign( 'GET', 'test.txt', 86400, gmmktime( 0, 0, 0, 5, 24, 2013 ) )
		);
	}

	// AWS General Reference, "Examples of how to derive a signing key for Signature Version 4".
	public function test_aws_signing_key_derivation_example(): void {
		$this->assertSame(
			'f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d',
			bin2hex( S3Presigner::signing_key( self::SUITE_SECRET, '20120215', 'us-east-1', 'iam' ) )
		);
	}

	/**
	 * Signature of a test-suite canonical request (service "service", 2015-08-30T12:36:00Z).
	 *
	 * @param string $canonical_request Canonical request.
	 * @return string
	 */
	private function suite_signature( string $canonical_request ): string {
		return S3Presigner::signature(
			self::SUITE_SECRET,
			'20150830',
			'us-east-1',
			'service',
			S3Presigner::string_to_sign( '20150830T123600Z', '20150830/us-east-1/service/aws4_request', $canonical_request )
		);
	}

	// AWS Signature Version 4 test suite: get-vanilla-query and get-vanilla-query-order-key-case.
	public function test_aws_test_suite_get_vanilla_query_vectors(): void {
		$empty_payload = hash( 'sha256', '' );
		$this->assertSame( 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $empty_payload );

		$vanilla = "GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n" . $empty_payload;
		$this->assertSame( '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $this->suite_signature( $vanilla ) );

		// The canonical query sorts by name, whatever order the request used (Param2=value2&Param1=value1).
		$query = S3Presigner::canonical_query(
			array(
				'Param2' => 'value2',
				'Param1' => 'value1',
			)
		);
		$this->assertSame( 'Param1=value1&Param2=value2', $query );
		$ordered = "GET\n/\n" . $query . "\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n" . $empty_payload;
		$this->assertSame( 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500', $this->suite_signature( $ordered ) );
	}

	// Reference values computed independently with botocore 1.44 (S3SigV4QueryAuth) at the fixed clock.
	public function test_fixed_clock_path_style_get_with_unicode_key(): void {
		$this->assertSame(
			'https://minio.example.com/wplc-releases/releases/acme%20pro/%C3%BC%201.0%2Bbeta.zip?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F20261116%2Feu-central-1%2Fs3%2Faws4_request&X-Amz-Date=20261116T143835Z&X-Amz-Expires=300&X-Amz-SignedHeaders=host&X-Amz-Signature=9bb13ba24cf6c0159050774b8d3905fcec8758146fbc981bb666933def884e83',
			$this->presigner( 'minio.example.com', 'eu-central-1', true )->presign( 'GET', self::UNICODE_KEY, 300, self::FIXED_TIME )
		);
	}

	public function test_fixed_clock_virtual_hosted_style_get_with_unicode_key(): void {
		$this->assertSame(
			'https://wplc-releases.s3.eu-central-1.amazonaws.com/releases/acme%20pro/%C3%BC%201.0%2Bbeta.zip?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F20261116%2Feu-central-1%2Fs3%2Faws4_request&X-Amz-Date=20261116T143835Z&X-Amz-Expires=300&X-Amz-SignedHeaders=host&X-Amz-Signature=3516da46a8a8a8620d9b19e9e97d5cab32a102721f3ef1766c4f88ccd29a8541',
			$this->presigner( 's3.eu-central-1.amazonaws.com', 'eu-central-1', false )->presign( 'GET', self::UNICODE_KEY, 300, self::FIXED_TIME )
		);
	}

	public function test_fixed_clock_head_bucket_in_both_styles(): void {
		$this->assertSame(
			'https://wplc-releases.s3.eu-central-1.amazonaws.com/?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F20261116%2Feu-central-1%2Fs3%2Faws4_request&X-Amz-Date=20261116T143835Z&X-Amz-Expires=60&X-Amz-SignedHeaders=host&X-Amz-Signature=b4982c4714fc149cbd747a73d3d639e0e737049e5b0a7a645e2c070d543acbda',
			$this->presigner( 's3.eu-central-1.amazonaws.com', 'eu-central-1', false )->presign( 'HEAD', '', 60, self::FIXED_TIME )
		);
		// Region "auto" as used by Cloudflare R2.
		$this->assertSame(
			'https://minio.example.com/wplc-releases?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F20261116%2Fauto%2Fs3%2Faws4_request&X-Amz-Date=20261116T143835Z&X-Amz-Expires=60&X-Amz-SignedHeaders=host&X-Amz-Signature=33421824117095c6cfa5c9ade94a1765616ae4a93fc6c93b6819ff683e14698f',
			$this->presigner( 'minio.example.com', 'auto', true )->presign( 'HEAD', '', 60, self::FIXED_TIME )
		);
	}

	public function test_get_and_head_of_the_same_object_have_different_signatures(): void {
		$presigner = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		$get       = $presigner->presign( 'GET', 'a.zip', 300, self::FIXED_TIME );
		$head      = $presigner->presign( 'head', 'a.zip', 300, self::FIXED_TIME );
		$this->assertNotSame( '', $head );
		$this->assertNotSame( $get, $head );
		$this->assertSame( '', $presigner->presign( 'PUT', 'a.zip', 300, self::FIXED_TIME ), 'Only read methods are signed.' );
	}

	public function test_expiry_is_clamped_to_one_second_up_to_seven_days(): void {
		$presigner = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		$this->assertStringContainsString( 'X-Amz-Expires=604800&', $presigner->presign( 'GET', 'a.zip', 30 * 86400, self::FIXED_TIME ) );
		$this->assertStringContainsString( 'X-Amz-Expires=1&', $presigner->presign( 'GET', 'a.zip', 0, self::FIXED_TIME ) );
		$this->assertStringContainsString( 'X-Amz-Expires=1&', $presigner->presign( 'GET', 'a.zip', -5, self::FIXED_TIME ) );
		$this->assertSame( 604800, S3Presigner::clamp_expires( PHP_INT_MAX ) );
		$this->assertSame( 300, S3Presigner::clamp_expires( 300 ) );
	}

	public function test_object_url_and_key_round_trip_in_both_styles(): void {
		$path    = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		$virtual = $this->presigner( 's3.eu-central-1.amazonaws.com', 'eu-central-1', false );

		$this->assertSame( 'https://minio.example.com/wplc-releases/releases/acme%20pro/%C3%BC%201.0%2Bbeta.zip', $path->object_url( self::UNICODE_KEY ) );
		$this->assertSame( 'https://wplc-releases.s3.eu-central-1.amazonaws.com/releases/acme%20pro/%C3%BC%201.0%2Bbeta.zip', $virtual->object_url( self::UNICODE_KEY ) );
		$this->assertSame( self::UNICODE_KEY, $path->key_from_url( $path->object_url( self::UNICODE_KEY ) ) );
		$this->assertSame( self::UNICODE_KEY, $virtual->key_from_url( $virtual->object_url( self::UNICODE_KEY ) ) );
		$this->assertSame( 'minio.example.com', $path->host() );
		$this->assertSame( 'wplc-releases.s3.eu-central-1.amazonaws.com', $virtual->host() );
		// "~" stays, "/" separates segments, everything else is %XX with upper-case digits.
		$this->assertSame( 'a~b/c%2A%3Fd%26e%3D', S3Presigner::encode_key( 'a~b/c*?d&e=' ) );
		$this->assertSame( 'a~b/c*?d&e=', $path->key_from_url( $path->object_url( 'a~b/c*?d&e=' ) ) );
	}

	public function test_urls_of_other_hosts_buckets_or_with_a_query_are_not_ours(): void {
		$path = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		$this->assertNull( $path->key_from_url( 'https://minio.example.com/other-bucket/a.zip' ) );
		$this->assertNull( $path->key_from_url( 'https://other.example.com/wplc-releases/a.zip' ) );
		$this->assertNull( $path->key_from_url( 'http://minio.example.com/wplc-releases/a.zip' ) );
		$this->assertNull( $path->key_from_url( 'https://minio.example.com:9000/wplc-releases/a.zip' ) );
		$this->assertNull( $path->key_from_url( 'https://user:pw@minio.example.com/wplc-releases/a.zip' ) );
		$this->assertNull( $path->key_from_url( 'https://minio.example.com/wplc-releases/a.zip#x' ) );
		$this->assertNull( $path->key_from_url( 'https://minio.example.com/wplc-releases/' ) );
		// Already signed by a custom resolver: left alone.
		$this->assertNull( $path->key_from_url( $path->presign( 'GET', 'a.zip', 300, self::FIXED_TIME ) ) );
		$this->assertSame( 'a.zip', $path->key_from_url( 'https://MINIO.example.com:443/wplc-releases/a.zip' ) );
	}

	public function test_invalid_keys_are_refused(): void {
		$path = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		foreach ( array( '', 'a/../b.zip', './a.zip', "a\nb.zip", "bad\xff.zip", str_repeat( 'a', 1025 ) ) as $key ) {
			$this->assertFalse( S3Presigner::is_valid_key( $key ), $key );
			$this->assertSame( '', $path->object_url( $key ) );
		}
		$this->assertSame( '', $path->presign( 'GET', 'a/../b.zip', 300, self::FIXED_TIME ) );
		$this->assertNull( $path->key_from_url( 'https://minio.example.com/wplc-releases/a/%2E%2E/b.zip' ) );
		$this->assertTrue( S3Presigner::is_valid_key( str_repeat( 'a', 1024 ) ) );
	}

	public function test_secret_is_kept_out_of_debug_output_and_urls(): void {
		$presigner = $this->presigner( 'minio.example.com', 'eu-central-1', true );
		$this->assertStringNotContainsString( self::SUITE_SECRET, print_r( $presigner, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		ob_start();
		var_dump( $presigner ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		$this->assertStringNotContainsString( self::SUITE_SECRET, (string) ob_get_clean() );
		$url = $presigner->presign( 'GET', 'a.zip', 300, self::FIXED_TIME );
		$this->assertStringNotContainsString( self::SUITE_SECRET, $url );
		$this->assertStringNotContainsString( rawurlencode( self::SUITE_SECRET ), $url );
	}

	// ------------------------------------------------------------------
	// Configuration (S3Storage, pure parts).
	// ------------------------------------------------------------------

	public function test_configuration_defaults_and_validation(): void {
		$base = array(
			'bucket'            => 'wplc-releases',
			'access_key_id'     => 'AKIDEXAMPLE',
			'secret_access_key' => self::SUITE_SECRET,
		);
		$this->assertSame( 'off', S3Storage::validate_config( array() )['state'] );

		$aws = S3Storage::validate_config( $base );
		$this->assertSame( array( 'configured', '', 's3.us-east-1.amazonaws.com', 'us-east-1', false ), array( $aws['state'], $aws['error'], $aws['endpoint_host'], $aws['region'], $aws['path_style'] ) );
		$this->assertArrayNotHasKey( 'secret_access_key', $aws, 'The validated configuration carries no credentials.' );
		$this->assertArrayNotHasKey( 'access_key_id', $aws );

		$r2 = S3Storage::validate_config( $base + array( 'endpoint' => 'https://0123abcd.r2.cloudflarestorage.com/', 'region' => 'auto' ) );
		$this->assertSame( array( 'configured', '0123abcd.r2.cloudflarestorage.com', 'auto' ), array( $r2['state'], $r2['endpoint_host'], $r2['region'] ) );

		$minio = S3Storage::validate_config( $base + array( 'endpoint' => 'minio.example.com', 'path_style' => true ) );
		$this->assertSame( array( 'configured', 'minio.example.com', true ), array( $minio['state'], $minio['endpoint_host'], $minio['path_style'] ) );

		// A bucket with dots (or upper case) is no single DNS label: path-style is used automatically.
		$dotted = S3Storage::validate_config( array_merge( $base, array( 'bucket' => 'releases.example.com' ) ) );
		$this->assertTrue( $dotted['path_style'] );

		$errors = array(
			'incomplete'          => array( 'bucket' => 'wplc-releases' ),
			'invalid_bucket'      => array_merge( $base, array( 'bucket' => 'a' ) ),
			'invalid_credentials' => array_merge( $base, array( 'access_key_id' => 'AKID EXAMPLE' ) ),
			'invalid_region'      => array_merge( $base, array( 'region' => 'eu central' ) ),
		);
		foreach ( array( 'http://minio.example.com', 'https://minio.example.com:9000', 'https://minio.example.com/prefix', 'https://user:pw@minio.example.com', 'https://minio.example.com/?x=1', 'https://localhost' ) as $endpoint ) {
			$result = S3Storage::validate_config( $base + array( 'endpoint' => $endpoint ) );
			$this->assertSame( array( 'invalid', 'invalid_endpoint' ), array( $result['state'], $result['error'] ), $endpoint );
		}
		foreach ( $errors as $code => $values ) {
			$result = S3Storage::validate_config( $values );
			$this->assertSame( array( 'invalid', $code ), array( $result['state'], $result['error'] ), $code );
		}
	}

	public function test_s3_uri_becomes_the_object_url_of_the_configured_bucket(): void {
		$this->assertSame( 's3://wplc-releases/a.zip', S3Storage::normalize_package_url( 's3://wplc-releases/a.zip' ), 'Unchanged without configuration.' );

		S3Storage::override_config(
			array(
				'endpoint'          => 'https://minio.example.com',
				'region'            => 'eu-central-1',
				'bucket'            => 'wplc-releases',
				'access_key_id'     => 'AKIDEXAMPLE',
				'secret_access_key' => self::SUITE_SECRET,
				'path_style'        => true,
			)
		);
		$this->assertSame( 'https://minio.example.com/wplc-releases/releases/acme%20pro/%C3%BC%201.0%2Bbeta.zip', S3Storage::normalize_package_url( 's3://wplc-releases/' . self::UNICODE_KEY ) );
		$this->assertSame( 's3://other/a.zip', S3Storage::normalize_package_url( 's3://other/a.zip' ) );
		$this->assertSame( 's3://wplc-releases/a/../b.zip', S3Storage::normalize_package_url( 's3://wplc-releases/a/../b.zip' ) );
		$this->assertSame( 'https://example.org/a.zip', S3Storage::normalize_package_url( 'https://example.org/a.zip' ) );
		$this->assertTrue( S3Storage::owns_url( 'https://minio.example.com/wplc-releases/a.zip' ) );
		$this->assertFalse( S3Storage::owns_url( 'https://example.org/a.zip' ) );

		$input = array(
			'product_key' => 'acme-pro',
			'version'     => '1.0.0',
			'storage'     => 'url',
			'package_url' => 's3://wplc-releases/acme-pro/acme-pro-1.0.0.zip',
			'sha256'      => str_repeat( 'a', 64 ),
		);
		$valid = ReleaseRegistry::validate( $input );
		$this->assertSame( array(), $valid['errors'] );
		$this->assertSame( 'https://minio.example.com/wplc-releases/acme-pro/acme-pro-1.0.0.zip', $valid['data']['package_url'] );

		$this->assertSame( array( 'object_storage_unavailable' ), ReleaseRegistry::validate( array_merge( $input, array( 'package_url' => 's3://other/a.zip' ) ) )['errors'] );
		S3Storage::override_config( null );
		$this->assertSame( array( 'object_storage_unavailable' ), ReleaseRegistry::validate( $input )['errors'] );
	}
}
