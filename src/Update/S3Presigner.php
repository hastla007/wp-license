<?php
/**
 * AWS Signature Version 4 query-string presigning for S3-compatible object storage.
 *
 * @package WPLC
 */

namespace WPLC\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds object URLs and short-lived pre-signed URLs for one bucket (T-31).
 *
 * Pure PHP without an SDK and without WordPress calls, so it can be tested
 * against the published AWS examples. Works with every service that speaks the
 * S3 REST API with Signature Version 4: AWS S3, Cloudflare R2, MinIO,
 * Backblaze B2 (S3 API) and Google Cloud Storage (XML API with HMAC keys).
 *
 * Only the `host` header is signed and the payload is `UNSIGNED-PAYLOAD`, so
 * the client that follows the URL needs to send nothing but a plain GET.
 * The secret access key never leaves this object: it is used for the HMAC
 * chain only and is not part of any return value or exception message.
 */
final class S3Presigner {
	/** Signing algorithm. */
	public const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** Longest validity Signature Version 4 allows for a pre-signed URL: seven days. */
	public const MAX_EXPIRES = 604800;

	/** Shortest validity. */
	public const MIN_EXPIRES = 1;

	/** Longest object key S3 accepts, in bytes. */
	public const MAX_KEY_BYTES = 1024;

	/** @var string Endpoint host without scheme, port or path, lower case. */
	private string $endpoint_host;

	/** @var string Signing region, for example "eu-central-1" or "auto". */
	private string $region;

	/** @var string Bucket name. */
	private string $bucket;

	/** @var string Access key ID (public part of the key pair). */
	private string $access_key_id;

	/** @var string Secret access key. */
	private string $secret_access_key;

	/** @var bool Path-style ("host/bucket/key") instead of virtual-hosted-style ("bucket.host/key"). */
	private bool $path_style;

	/**
	 * Create a presigner for one bucket. The values are validated by S3Storage::config().
	 *
	 * @param string $endpoint_host     Endpoint host, e.g. "s3.eu-central-1.amazonaws.com".
	 * @param string $region            Signing region.
	 * @param string $bucket            Bucket name.
	 * @param string $access_key_id     Access key ID.
	 * @param string $secret_access_key Secret access key.
	 * @param bool   $path_style        Use path-style addressing.
	 */
	public function __construct( string $endpoint_host, string $region, string $bucket, string $access_key_id, string $secret_access_key, bool $path_style ) {
		$this->endpoint_host = strtolower( $endpoint_host );
		$this->region = $region;
		$this->bucket = $bucket;
		$this->access_key_id = $access_key_id;
		$this->secret_access_key = $secret_access_key;
		$this->path_style = $path_style;
	}

	/**
	 * Host the requests go to: the endpoint (path-style) or "bucket.endpoint" (virtual-hosted-style).
	 *
	 * @return string
	 */
	public function host(): string {
		return $this->path_style ? $this->endpoint_host : strtolower( $this->bucket ) . '.' . $this->endpoint_host;
	}

	/**
	 * Bucket name.
	 *
	 * @return string
	 */
	public function bucket(): string {
		return $this->bucket;
	}

	/**
	 * Whether path-style addressing is used.
	 *
	 * @return bool
	 */
	public function is_path_style(): bool {
		return $this->path_style;
	}

	/**
	 * Permanent (unsigned) HTTPS URL of an object. This is what a release stores.
	 *
	 * @param string $key Object key (raw, not encoded).
	 * @return string Empty string when the key is not valid.
	 */
	public function object_url( string $key ): string {
		if ( ! self::is_valid_key( $key ) ) {
			return '';
		}

		return 'https://' . $this->host() . $this->canonical_uri( $key );
	}

	/**
	 * Object key of a permanent object URL of this bucket.
	 *
	 * The URL must point to the configured host and bucket and must carry no
	 * query, fragment, credentials or port other than 443. A URL that already
	 * carries a query (for example one a custom resolver signed) is not ours.
	 *
	 * @param string $url URL.
	 * @return string|null Raw object key, or null when the URL does not belong to this bucket.
	 */
	public function key_from_url( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return null;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper without WordPress.
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| strtolower( (string) ( $parts['host'] ?? '' ) ) !== $this->host()
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
		) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );
		$prefix = $this->path_style ? '/' . $this->bucket . '/' : '/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			return null;
		}
		$key = rawurldecode( substr( $path, strlen( $prefix ) ) );

		return self::is_valid_key( $key ) ? $key : null;
	}

	/**
	 * Pre-signed URL for an object (GET to download, HEAD to check).
	 *
	 * @param string $method  HTTP method, GET or HEAD.
	 * @param string $key     Raw object key; an empty string addresses the bucket itself (HEAD bucket).
	 * @param int    $expires Validity in seconds, clamped to 1 … 604800.
	 * @param int    $now     Unix time of signing.
	 * @return string Empty string when the key is not valid.
	 */
	public function presign( string $method, string $key, int $expires, int $now ): string {
		$method = strtoupper( $method );
		if ( ( '' !== $key && ! self::is_valid_key( $key ) ) || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return '';
		}
		$amz_date = gmdate( 'Ymd\THis\Z', $now );
		$date = substr( $amz_date, 0, 8 );
		$scope = $date . '/' . $this->region . '/s3/aws4_request';
		$query = array(
			'X-Amz-Algorithm' => self::ALGORITHM,
			'X-Amz-Credential' => $this->access_key_id . '/' . $scope,
			'X-Amz-Date' => $amz_date,
			'X-Amz-Expires' => (string) self::clamp_expires( $expires ),
			'X-Amz-SignedHeaders' => 'host',
		);
		$uri = '' === $key ? ( $this->path_style ? '/' . self::encode_segment( $this->bucket ) : '/' ) : $this->canonical_uri( $key );
		$canonical_query = self::canonical_query( $query );
		$canonical_request = implode(
			"\n",
			array( $method, $uri, $canonical_query, 'host:' . $this->host(), '', 'host', 'UNSIGNED-PAYLOAD' )
		);
		$signature = self::signature(
			$this->secret_access_key,
			$date,
			$this->region,
			's3',
			self::string_to_sign( $amz_date, $scope, $canonical_request )
		);

		return 'https://' . $this->host() . $uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Clamp a validity to what Signature Version 4 allows. Pure helper.
	 *
	 * @param int $expires Seconds.
	 * @return int
	 */
	public static function clamp_expires( int $expires ): int {
		return max( self::MIN_EXPIRES, min( self::MAX_EXPIRES, $expires ) );
	}

	/**
	 * Whether a string is an object key this class signs. Pure helper.
	 *
	 * Valid UTF-8, 1 to 1024 bytes, no control characters, and no "." or ".."
	 * path segment: HTTP clients remove such segments before sending, which
	 * would break the signature or reach another object.
	 *
	 * @param string $key Raw key.
	 * @return bool
	 */
	public static function is_valid_key( string $key ): bool {
		if ( '' === $key || strlen( $key ) > self::MAX_KEY_BYTES || ! preg_match( '//u', $key ) || preg_match( '/[\x00-\x1f\x7f]/', $key ) ) {
			return false;
		}
		foreach ( explode( '/', $key ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Encode one path segment or query value the way Signature Version 4 expects (RFC 3986). Pure helper.
	 *
	 * Every byte except A-Z, a-z, 0-9, "-", ".", "_" and "~" becomes %XX with upper-case hex digits.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode_segment( string $value ): string {
		return rawurlencode( $value );
	}

	/**
	 * Encode an object key for the URL path: each segment encoded, "/" kept. Pure helper.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function encode_key( string $key ): string {
		return implode( '/', array_map( array( self::class, 'encode_segment' ), explode( '/', $key ) ) );
	}

	/**
	 * Canonical query string: names and values encoded, sorted by encoded name (byte order). Pure helper.
	 *
	 * @param array<string,string> $params Raw query parameters.
	 * @return string
	 */
	public static function canonical_query( array $params ): string {
		$encoded = array();
		foreach ( $params as $name => $value ) {
			$encoded[ self::encode_segment( (string) $name ) ] = self::encode_segment( (string) $value );
		}
		ksort( $encoded, SORT_STRING );
		$pairs = array();
		foreach ( $encoded as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}

		return implode( '&', $pairs );
	}

	/**
	 * String to sign of Signature Version 4. Pure helper.
	 *
	 * @param string $amz_date          Time stamp, "YYYYMMDDTHHMMSSZ".
	 * @param string $scope             Credential scope, "date/region/service/aws4_request".
	 * @param string $canonical_request Canonical request.
	 * @return string
	 */
	public static function string_to_sign( string $amz_date, string $scope, string $canonical_request ): string {
		return self::ALGORITHM . "\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
	}

	/**
	 * Derived signing key (binary). Pure helper.
	 *
	 * @param string $secret_access_key Secret access key.
	 * @param string $date              Date, "YYYYMMDD".
	 * @param string $region            Region.
	 * @param string $service           Service, "s3" here.
	 * @return string
	 */
	public static function signing_key( string $secret_access_key, string $date, string $region, string $service ): string {
		$key = hash_hmac( 'sha256', $date, 'AWS4' . $secret_access_key, true );
		$key = hash_hmac( 'sha256', $region, $key, true );
		$key = hash_hmac( 'sha256', $service, $key, true );

		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}

	/**
	 * Hex signature of a string to sign. Pure helper.
	 *
	 * @param string $secret_access_key Secret access key.
	 * @param string $date              Date, "YYYYMMDD".
	 * @param string $region            Region.
	 * @param string $service           Service.
	 * @param string $string_to_sign    String to sign.
	 * @return string
	 */
	public static function signature( string $secret_access_key, string $date, string $region, string $service, string $string_to_sign ): string {
		return hash_hmac( 'sha256', $string_to_sign, self::signing_key( $secret_access_key, $date, $region, $service ) );
	}

	/**
	 * Canonical URI of an object: "/key" or "/bucket/key", segments encoded once (S3 does not normalize paths).
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function canonical_uri( string $key ): string {
		return ( $this->path_style ? '/' . self::encode_segment( $this->bucket ) : '' ) . '/' . self::encode_key( $key );
	}

	/**
	 * Keep the secret out of var_dump(), print_r() and debug output.
	 *
	 * @return array<string,mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'host' => $this->host(),
			'bucket' => $this->bucket,
			'region' => $this->region,
			'path_style' => $this->path_style,
		);
	}
}
