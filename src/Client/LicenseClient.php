<?php
/**
 * Transport of the license client: activation, signed requests, verified answers.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\LicenseClient', false ) ) {

	/**
	 * Talks to a WP License Captain server on behalf of one premium add-on.
	 *
	 * Add-ons normally use the LicenseManager facade; this class is the layer
	 * below it. It needs WordPress 6.5 and PHP 7.4, nothing else.
	 *
	 * Rules it enforces (docs/API-V1.md):
	 * - only the bytes of a verified `payload` are trusted: HMAC with the
	 *   installation secret, Ed25519 signature of the server against the keys
	 *   pinned in the add-on, and the echoed request nonce;
	 * - a copy of the site (other address than the activated one) never calls
	 *   the server with the copied credentials;
	 * - stored credentials are dropped only on a signed answer.
	 */
	final class LicenseClient {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.1.0';

		/**
		 * Context of the Ed25519 signature over the payload text.
		 */
		const SIGNATURE_CONTEXT = 'wplc-response-v2';

		/**
		 * Context of the legacy Ed25519 signature over the JSON of the data (`signature_ed25519`).
		 *
		 * Not accepted by this client since 2.0.2 (that signature carries no nonce); the constant stays for code that reads it.
		 */
		const LEGACY_SIGNATURE_CONTEXT = 'wplc-response-v1';

		/**
		 * Seconds a pending activation (UUID and secret stored before the request) is reused.
		 */
		const PENDING_LIFETIME = 86400;

		/**
		 * Base URLs of the REST API (primary first), without trailing slash.
		 *
		 * @var string[]
		 */
		private $api_bases = array();

		/**
		 * Product key of the add-on.
		 *
		 * @var string
		 */
		private $product_key;

		/**
		 * Installed version of the add-on.
		 *
		 * @var string
		 */
		private $product_version;

		/**
		 * Name of the option that holds the credentials.
		 *
		 * @var string
		 */
		private $option_key;

		/**
		 * Pinned public keys of the license server (binary, 32 bytes each).
		 *
		 * @var string[]
		 */
		private $public_keys = array();

		/**
		 * Whether the add-on pinned keys at all (then a server signature is required).
		 *
		 * @var bool
		 */
		private $keys_given = false;

		/**
		 * Texts.
		 *
		 * @var Messages
		 */
		private $messages;

		/**
		 * Storage.
		 *
		 * @var OptionStore
		 */
		private $store;

		/**
		 * HTTP transport: callable( string $url, array $args ): array|\WP_Error.
		 *
		 * @var callable|null
		 */
		private $transport;

		/**
		 * Clock: callable(): int.
		 *
		 * @var callable|null
		 */
		private $clock;

		/**
		 * Timeout in seconds; 0 selects 5 seconds in web requests and 15 in cron and WP-CLI.
		 *
		 * @var int
		 */
		private $timeout = 0;

		/**
		 * Envelope of the last verified answer (payload and signatures).
		 *
		 * @var array<string,string>
		 */
		private $last_envelope = array();

		/**
		 * Ed25519 verification: callable( string $signature, string $message, string $public_key ): bool, or null
		 * when this PHP has neither ext-sodium nor sodium_compat.
		 *
		 * @var callable|null
		 */
		private $verifier;

		/**
		 * Whether the host of the server address may resolve to a local or private address (development servers).
		 *
		 * @var bool
		 */
		private $allow_local_server = false;

		/**
		 * Hashes of snapshot signatures verified in this request (shared by all instances; see verified_snapshot_data()).
		 *
		 * @var array<string,bool>
		 */
		private static $verified = array();

		/**
		 * Constructor.
		 *
		 * @param string              $server_url      Address of the license server (HTTPS).
		 * @param string              $product_key     Product key of the add-on in the server catalog.
		 * @param string              $product_version Installed version of the add-on.
		 * @param string              $option_key      Option that holds the credentials of this add-on.
		 * @param array<string,mixed> $args            {
		 *     Optional settings.
		 *
		 *     @type string[]    $public_keys    Base64 Ed25519 public keys of the license server. Several keys
		 *                                       allow a rotation. Without keys only the HMAC is checked (tests).
		 *     @type string      $text_domain    Text domain of the add-on.
		 *     @type string[]    $fallback_urls  Further server addresses tried when the first cannot be reached.
		 *     @type string      $rest_base      Full REST base when the server does not use `/wp-json`,
		 *                                       e.g. `https://shop.example/index.php?rest_route=`.
		 *     @type int         $timeout        Timeout in seconds for every request.
		 *     @type bool        $network        Multisite: one activation for the network (network options).
		 *     @type bool        $allow_insecure Accept an `http://` server address (local development only).
		 *     @type bool        $allow_local_server Since 2.1.0: let the host of the server addresses resolve to a
		 *                                       local or private IP address (development servers). Requests go
		 *                                       through wp_safe_remote_request(), which refuses such addresses;
		 *                                       with this setting only the hosts of the configured server
		 *                                       addresses are let through (filter `http_request_host_is_external`,
		 *                                       and their port through `http_allowed_safe_ports`), for the
		 *                                       duration of each request. Default false.
		 *     @type OptionStore $store          Storage (tests).
		 *     @type callable    $transport      Replacement for wp_safe_remote_request() (tests).
		 *     @type callable    $clock          Replacement for time() (tests).
		 *     @type callable|false $signature_verifier Replacement for sodium_crypto_sign_verify_detached(); false
		 *                                       simulates a PHP without any Ed25519 implementation (tests).
		 * }
		 */
		public function __construct( string $server_url, string $product_key, string $product_version, string $option_key, array $args = array() ) {
			$insecure = ! empty( $args['allow_insecure'] );
			$urls     = array_merge( array( $server_url ), isset( $args['fallback_urls'] ) && is_array( $args['fallback_urls'] ) ? $args['fallback_urls'] : array() );
			foreach ( $urls as $index => $url ) {
				$url = self::clean_server_url( (string) $url, $insecure );
				if ( '' === $url ) {
					continue;
				}
				$this->api_bases[] = 0 === $index && ! empty( $args['rest_base'] ) && '' !== self::clean_server_url( (string) $args['rest_base'], $insecure, true )
					? rtrim( (string) $args['rest_base'], '/' )
					: $url . '/wp-json';
			}
			if ( '' === self::clean_server_url( $server_url, $insecure ) ) {
				// A broken primary address is a configuration error, not a reason to use a fallback silently.
				$this->api_bases = array();
			}

			$this->product_key     = strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', $product_key ) );
			$this->product_version = substr( trim( $product_version ), 0, 30 );
			$this->option_key      = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', $option_key ) );
			$this->messages        = new Messages( isset( $args['text_domain'] ) ? (string) $args['text_domain'] : '' );
			$this->store           = isset( $args['store'] ) && $args['store'] instanceof OptionStore ? $args['store'] : new OptionStore( ! empty( $args['network'] ) );
			$this->transport       = isset( $args['transport'] ) && is_callable( $args['transport'] ) ? $args['transport'] : null;
			$this->clock           = isset( $args['clock'] ) && is_callable( $args['clock'] ) ? $args['clock'] : null;
			$this->timeout         = isset( $args['timeout'] ) ? max( 0, (int) $args['timeout'] ) : 0;
			$this->allow_local_server = ! empty( $args['allow_local_server'] );
			$this->verifier        = self::default_verifier();
			if ( array_key_exists( 'signature_verifier', $args ) && null !== $args['signature_verifier'] ) {
				$this->verifier = is_callable( $args['signature_verifier'] ) ? $args['signature_verifier'] : null;
			}

			if ( ! empty( $args['public_keys'] ) && is_array( $args['public_keys'] ) ) {
				$this->keys_given = true;
				foreach ( $args['public_keys'] as $key ) {
					$binary = is_string( $key ) ? base64_decode( trim( $key ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a public key.
					if ( is_string( $binary ) && 32 === strlen( $binary ) ) {
						$this->public_keys[] = $binary;
					}
				}
			}
		}

		// ------------------------------------------------------------------
		// State.
		// ------------------------------------------------------------------

		/**
		 * Whether the constructor arguments are usable.
		 *
		 * @return bool
		 */
		public function is_configured(): bool {
			return ! empty( $this->api_bases )
				&& '' !== $this->product_key
				&& '' !== $this->option_key
				&& ( ! $this->keys_given || ! empty( $this->public_keys ) );
		}

		/**
		 * Whether answers must carry a signature of the license server.
		 *
		 * @return bool
		 */
		public function requires_server_signature(): bool {
			return $this->keys_given;
		}

		/**
		 * Whether this PHP can check the Ed25519 signature of the server (ext-sodium or sodium_compat).
		 *
		 * Without it, answers and stored snapshots are never accepted while keys are pinned: the client
		 * does not fall back to the HMAC alone, because the installation secret is stored next to the
		 * snapshot and anyone who can edit the database could recompute the HMAC.
		 *
		 * @return bool
		 */
		public function can_verify_server_signature(): bool {
			return null !== $this->verifier;
		}

		/**
		 * Whether credentials of an activation are stored (also on a copy of the site).
		 *
		 * @return bool
		 */
		public function is_activated(): bool {
			return null !== $this->stored_credentials();
		}

		/**
		 * Installation UUID of the stored activation, or an empty string.
		 *
		 * @return string
		 */
		public function installation_id(): string {
			$credentials = $this->stored_credentials();

			return null === $credentials ? '' : (string) $credentials['installation_id'];
		}

		/**
		 * Environment the server stored for this activation, or an empty string.
		 *
		 * @return string
		 */
		public function environment(): string {
			$credentials = $this->stored_credentials();

			return null === $credentials ? '' : (string) ( $credentials['environment'] ?? 'production' );
		}

		/**
		 * Address the stored activation was made for, or an empty string.
		 *
		 * @return string
		 */
		public function activated_site_url(): string {
			$credentials = $this->stored_credentials();

			return null === $credentials ? '' : (string) ( $credentials['site_url'] ?? '' );
		}

		/**
		 * Whether this site still runs under the address it was activated for.
		 *
		 * False for a copy of the site that carries the credentials of the
		 * original (staging clone, migration). True when nothing is stored.
		 *
		 * @return bool
		 */
		public function site_matches(): bool {
			$credentials = $this->stored_credentials();
			if ( null === $credentials || empty( $credentials['site_url'] ) ) {
				return true;
			}

			return self::site_key( (string) $credentials['site_url'] ) === self::site_key( $this->store->site_url() );
		}

		/**
		 * Product key of the add-on.
		 *
		 * @return string
		 */
		public function product_key(): string {
			return $this->product_key;
		}

		/**
		 * Installed version of the add-on.
		 *
		 * @return string
		 */
		public function product_version(): string {
			return $this->product_version;
		}

		/**
		 * Storage used by this client.
		 *
		 * @return OptionStore
		 */
		public function store(): OptionStore {
			return $this->store;
		}

		/**
		 * Texts of the client.
		 *
		 * @return Messages
		 */
		public function messages(): Messages {
			return $this->messages;
		}

		/**
		 * Host name of the license server, or an empty string.
		 *
		 * @return string
		 */
		public function server_host(): string {
			return empty( $this->api_bases ) ? '' : (string) wp_parse_url( $this->api_bases[0], PHP_URL_HOST );
		}

		/**
		 * Current time of the (injectable) clock.
		 *
		 * @return int
		 */
		public function now(): int {
			return null === $this->clock ? time() : (int) call_user_func( $this->clock );
		}

		/**
		 * Payload and signatures of the last verified answer.
		 *
		 * The LicenseManager stores this as its offline snapshot.
		 *
		 * @return array<string,string> Keys `payload`, `payload_signature`, `payload_signature_ed25519`, `key_id`; empty before the first answer.
		 */
		public function last_envelope(): array {
			return $this->last_envelope;
		}

		/**
		 * Environment of this site for the activation request.
		 *
		 * `wp_get_environment_type()` "staging" becomes `staging`, "local" and
		 * "development" become `development`, everything else `production`.
		 *
		 * @return string
		 */
		public static function detect_environment(): string {
			$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
			if ( 'staging' === $type ) {
				return 'staging';
			}

			return in_array( $type, array( 'local', 'development' ), true ) ? 'development' : 'production';
		}

		// ------------------------------------------------------------------
		// Activation.
		// ------------------------------------------------------------------

		/**
		 * Activate this site.
		 *
		 * - Working credentials for the same key are reused (no second site slot).
		 * - A new activation is requested only when the server answers that it
		 *   does not know the stored installation (`activation_not_found`,
		 *   `installation_secret_invalid`, or a signed "deactivated"). A timeout
		 *   or any other error is returned as it is.
		 * - UUID and secret are stored as "pending" before the request and reused
		 *   on the next attempt, so a lost answer does not orphan the site.
		 * - Another key than the stored one: the old activation is released first.
		 * - On a copy of the site the copied credentials are never used; for a
		 *   staging environment they name the live site the copy belongs to.
		 *
		 * @param string $license_key                    Key entered by the customer.
		 * @param string $environment                    `production`, `staging`, `development`; empty: detect_environment().
		 * @param string $staging_parent_installation_id Installation UUID of the live site of a staging site.
		 * @param bool   $force                          Request a new activation without trying the stored one.
		 * @return array<string,mixed>|\WP_Error Signed entitlement data.
		 */
		public function activate( string $license_key, string $environment = '', string $staging_parent_installation_id = '', bool $force = false ) {
			if ( ! $this->is_configured() ) {
				return $this->error( 'wplc_configuration_invalid' );
			}
			$license_key = strtoupper( trim( $license_key ) );
			if ( '' === $license_key ) {
				return $this->error( 'wplc_license_key_missing' );
			}
			$environment = '' === $environment ? self::detect_environment() : $environment;
			$key_hash    = hash( 'sha256', $license_key );
			$stored      = $this->credentials();

			if ( ! is_wp_error( $stored ) ) {
				if ( ! $this->site_matches() ) {
					// A copy of an activated site: the stored installation is the original.
					if ( 'staging' === $environment && '' === $staging_parent_installation_id && 'production' === ( $stored['environment'] ?? 'production' ) ) {
						$staging_parent_installation_id = (string) $stored['installation_id'];
					}
				} elseif ( ! $force ) {
					$stored_hash = (string) ( $stored['license_key_hash'] ?? '' );
					if ( '' === $stored_hash || hash_equals( $stored_hash, $key_hash ) ) {
						$current = $this->check_entitlements();
						if ( ! is_wp_error( $current ) ) {
							if ( 'deactivated' !== ( $current['activation_status'] ?? '' ) ) {
								return $current;
							}
						} elseif ( ! self::needs_new_activation( $current ) ) {
							return $current;
						}
					} else {
						// Another license on the same site: free the slot of the old one (best effort).
						$this->deactivate();
					}
				}
			}

			$result = $this->request_activation( $license_key, $key_hash, $environment, $staging_parent_installation_id, false );
			if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'activation_deactivated', 'installation_conflict', 'activation_conflict' ), true ) ) {
				// The pending installation ID is used up on the server: one attempt with a new one.
				$result = $this->request_activation( $license_key, $key_hash, $environment, $staging_parent_installation_id, true );
			}

			return $result;
		}

		/**
		 * Tell the server that this installation moved to the current address.
		 *
		 * Repeats the activation with the stored UUID and secret; the server then
		 * stores the new address instead of counting a second site. Use it when
		 * the customer confirms that the site moved (not for a copy).
		 *
		 * @param string $license_key Key entered by the customer.
		 * @return array<string,mixed>|\WP_Error
		 */
		public function move( string $license_key ) {
			$stored = $this->credentials();
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			if ( ! $this->is_configured() ) {
				return $this->error( 'wplc_configuration_invalid' );
			}
			$license_key = strtoupper( trim( $license_key ) );
			if ( '' === $license_key ) {
				return $this->error( 'wplc_license_key_missing' );
			}
			$body = $this->activation_body( $license_key, (string) $stored['installation_id'], (string) $stored['installation_secret'], (string) ( $stored['environment'] ?? 'production' ), '' );
			$data = $this->send_activation( $body );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$saved = $this->save_credentials( $data, (string) $stored['installation_id'], (string) $stored['installation_secret'], hash( 'sha256', $license_key ) );

			return is_wp_error( $saved ) ? $saved : $data;
		}

		/**
		 * Whether an error of a signed request means "the server does not know this installation".
		 *
		 * @param \WP_Error $error Error.
		 * @return bool
		 */
		public static function needs_new_activation( \WP_Error $error ): bool {
			return in_array( $error->get_error_code(), array( 'activation_not_found', 'installation_secret_invalid' ), true ) || self::is_signed_refusal( $error );
		}

		/**
		 * Whether an error carries a verified, signed "deactivated" answer of the server.
		 *
		 * @param \WP_Error $error Error.
		 * @return bool
		 */
		public static function is_signed_refusal( \WP_Error $error ): bool {
			$data = $error->get_error_data();

			return 'activation_deactivated' === $error->get_error_code() && is_array( $data ) && ! empty( $data['wplc_signed'] );
		}

		/**
		 * Whether an error is a verified, signed `site_mismatch` of the server (since 2.1.0).
		 *
		 * The server answers the release (`DELETE /activations/{id}`) of an activation whose stored
		 * address differs from the address this site sends with a `409 site_mismatch`. Newer servers
		 * add `data.signed`, an envelope bound to the request with `site_match: false`. Only that
		 * signed answer makes deactivate() remove the local credentials (the activation belongs to
		 * the other address and stays active there); an unsigned 409 of an older server keeps them.
		 *
		 * @since 2.1.0
		 *
		 * @param \WP_Error $error Error.
		 * @return bool
		 */
		public static function is_signed_site_mismatch( \WP_Error $error ): bool {
			$data = $error->get_error_data();

			return 'site_mismatch' === $error->get_error_code() && is_array( $data ) && ! empty( $data['wplc_signed'] );
		}

		/**
		 * Send one activation request with a pending (or new) installation ID.
		 *
		 * @param string $license_key Normalized key.
		 * @param string $key_hash    SHA-256 of the key.
		 * @param string $environment Environment.
		 * @param string $parent_id   Installation UUID of the live site.
		 * @param bool   $fresh       Ignore a stored pending installation.
		 * @return array<string,mixed>|\WP_Error
		 */
		private function request_activation( string $license_key, string $key_hash, string $environment, string $parent_id, bool $fresh ) {
			$site_key = self::site_key( $this->store->site_url() );
			$pending  = $fresh ? array() : $this->store->get( $this->option_key . '_pending', array() );
			$usable   = is_array( $pending )
				&& ! empty( $pending['installation_id'] )
				&& ! empty( $pending['installation_secret'] )
				&& hash_equals( (string) ( $pending['license_key_hash'] ?? '' ), $key_hash )
				&& ( $pending['environment'] ?? '' ) === $environment
				&& ( $pending['site_key'] ?? '' ) === $site_key
				&& ( $pending['parent'] ?? '' ) === $parent_id
				&& abs( $this->now() - (int) ( $pending['created_at'] ?? 0 ) ) < self::PENDING_LIFETIME;
			if ( ! $usable ) {
				$pending = array(
					'installation_id'     => self::uuid4(),
					'installation_secret' => bin2hex( random_bytes( 32 ) ),
					'license_key_hash'    => $key_hash,
					'environment'         => $environment,
					'site_key'            => $site_key,
					'parent'              => $parent_id,
					'created_at'          => $this->now(),
				);
				// Stored before the request: if the answer is lost, the next attempt repeats the same installation.
				if ( ! $this->store->set( $this->option_key . '_pending', $pending ) ) {
					return $this->error( 'wplc_credentials_not_saved' );
				}
			}

			$body = $this->activation_body( $license_key, (string) $pending['installation_id'], (string) $pending['installation_secret'], $environment, $parent_id );
			$data = $this->send_activation( $body );
			if ( is_wp_error( $data ) ) {
				$status = (int) ( ( (array) $data->get_error_data() )['status'] ?? 0 );
				if ( $status >= 400 && $status < 500 && 429 !== $status && 0 !== strpos( (string) $data->get_error_code(), 'wplc_' ) ) {
					// A definite refusal: nothing was created for this installation ID.
					$this->store->delete( $this->option_key . '_pending' );
				}
				return $data;
			}

			$saved = $this->save_credentials( $data, (string) $pending['installation_id'], (string) $pending['installation_secret'], $key_hash );
			if ( is_wp_error( $saved ) ) {
				// The pending installation stays, so the next attempt finds the same activation.
				return $saved;
			}
			$this->store->delete( $this->option_key . '_pending' );

			return $data;
		}

		/**
		 * Body of POST /activations.
		 *
		 * @param string $license_key     Key.
		 * @param string $installation_id UUID.
		 * @param string $secret          Secret.
		 * @param string $environment     Environment.
		 * @param string $parent_id       Installation UUID of the live site.
		 * @return array<string,string>
		 */
		private function activation_body( string $license_key, string $installation_id, string $secret, string $environment, string $parent_id ): array {
			$body = array(
				'license_key'         => $license_key,
				'product_key'         => $this->product_key,
				'installation_id'     => $installation_id,
				'installation_secret' => $secret,
				'site_url'            => $this->store->site_url(),
				'environment'         => $environment,
			);
			if ( '' !== $parent_id ) {
				$body['staging_parent_installation_id'] = $parent_id;
			}

			return array_merge( $body, $this->versions() );
		}

		/**
		 * Send POST /activations and verify the answer.
		 *
		 * @param array<string,string> $body Body.
		 * @return array<string,mixed>|\WP_Error
		 */
		private function send_activation( array $body ) {
			// Not HMAC-authenticated: the server echoes the installation ID in place of a request nonce.
			$http = $this->send( 'POST', '/wplc/v1/activations', $body, array() );
			if ( is_wp_error( $http ) ) {
				return $http;
			}
			$data = $this->interpret( $http, $body['installation_secret'], $body['installation_id'], $body['installation_id'], false );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			// The ID is stored and later used in the route of the deactivation: only a positive integer is accepted.
			if ( strtolower( (string) ( $data['installation_id'] ?? '' ) ) !== strtolower( $body['installation_id'] ) || 0 === self::positive_id( $data['activation_id'] ?? null ) ) {
				return $this->error( 'wplc_response_invalid' );
			}

			return $this->check_product( $data, false );
		}

		/**
		 * Store the credentials of a confirmed activation.
		 *
		 * @param array<string,mixed> $data            Signed activation data.
		 * @param string              $installation_id UUID.
		 * @param string              $secret          Secret.
		 * @param string              $key_hash        SHA-256 of the license key.
		 * @return true|\WP_Error
		 */
		private function save_credentials( array $data, string $installation_id, string $secret, string $key_hash ) {
			$saved = $this->store->set(
				$this->option_key,
				array(
					'installation_id'     => $installation_id,
					'installation_secret' => $secret,
					'activation_id'       => self::positive_id( $data['activation_id'] ?? null ),
					'license_key_hash'    => $key_hash,
					'product_key'         => $this->product_key,
					'site_url'            => $this->store->site_url(),
					'environment'         => (string) ( $data['environment'] ?? 'production' ),
					'activated_at'        => $this->now(),
					'clock_offset'        => 0,
				)
			);

			return $saved ? true : $this->error( 'wplc_credentials_not_saved' );
		}

		// ------------------------------------------------------------------
		// Signed requests.
		// ------------------------------------------------------------------

		/**
		 * Fetch the current signed license status and feature rights.
		 *
		 * A signed answer `activation_status: deactivated` removes the stored
		 * credentials; the data is still returned.
		 *
		 * @return array<string,mixed>|\WP_Error
		 */
		public function check_entitlements() {
			$data = $this->signed_request( 'POST', '/wplc/v1/entitlements/check', array_merge( array( 'site_url' => $this->store->site_url() ), $this->versions() ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$data = $this->check_product( $data );
			if ( ! is_wp_error( $data ) && 'deactivated' === ( $data['activation_status'] ?? '' ) ) {
				$this->forget();
			}

			return $data;
		}

		/**
		 * Ask for a newer version.
		 *
		 * @param bool                $download Whether a package is needed now. False for the periodic check
		 *                                      (metadata only, the server stores no download grant).
		 * @param array<string,mixed> $extra    Optional `channel`, `core_version`, `target_version` (strings).
		 * @return array<string,mixed>|\WP_Error Signed update data (docs/UPDATES.md).
		 */
		public function check_update( bool $download = false, array $extra = array() ) {
			$body = array(
				'product_key'     => $this->product_key,
				'current_version' => $this->product_version,
				'download'        => $download,
			);
			foreach ( array( 'channel', 'core_version', 'target_version' ) as $field ) {
				if ( isset( $extra[ $field ] ) && is_string( $extra[ $field ] ) && '' !== $extra[ $field ] ) {
					$body[ $field ] = $extra[ $field ];
				}
			}
			$versions = $this->versions();
			unset( $versions['product_version'] );

			$data = $this->signed_request( 'POST', '/wplc/v1/updates/check', array_merge( $body, $versions ), $download ? 15 : 0 );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return $this->check_product( $data, false );
		}

		/**
		 * Release the activation of this site and remove the stored credentials.
		 *
		 * The credentials are removed
		 * - after the signed confirmation of the server,
		 * - without any request when this is a copy of the activated site,
		 * - on a signed refusal (`activation_deactivated` with an envelope that
		 *   verifies against the pinned keys): the activation is already released;
		 * - on a signed `site_mismatch` (since 2.1.0, see is_signed_site_mismatch()):
		 *   the activation belongs to another address and stays active there.
		 * In the last three cases the result is `status: removed_locally` with the
		 * `reason` `wplc_site_changed`, `activation_deactivated` or `site_mismatch`.
		 * Every other error keeps the credentials and is returned, also an
		 * unsigned `site_mismatch` or `activation_not_found` (anyone between the
		 * site and the server could send those); use forget() to drop them anyway.
		 * When the credentials cannot be removed from the database the result is
		 * `wplc_credentials_not_removed` (data `released`: whether the server
		 * released the activation), never a success.
		 *
		 * @return array<string,mixed>|\WP_Error
		 */
		public function deactivate() {
			$credentials = $this->credentials();
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}
			if ( ! $this->site_matches() ) {
				if ( ! $this->forget() ) {
					return $this->error( 'wplc_credentials_not_removed', array( 'released' => false ) );
				}
				return array(
					'status' => 'removed_locally',
					'reason' => 'wplc_site_changed',
				);
			}

			$route = '/wplc/v1/activations/' . (int) $credentials['activation_id'];
			$body  = array( 'site_url' => $this->store->site_url() );
			$data  = $this->signed_request( 'DELETE', $route, $body );
			if ( is_wp_error( $data ) ) {
				$status = (int) ( ( (array) $data->get_error_data() )['status'] ?? 0 );
				if ( 'wplc_blocked_by_firewall' === $data->get_error_code() || in_array( $status, array( 405, 501 ), true ) ) {
					// Hosts and firewalls that refuse DELETE: WordPress accepts the method as an override of POST.
					$data = $this->signed_request( 'DELETE', $route, $body, 0, 'POST' );
				}
			}
			if ( is_wp_error( $data ) ) {
				if ( self::is_signed_refusal( $data ) || self::is_signed_site_mismatch( $data ) ) {
					// After a signed refusal interpret() already tried to forget; this attempt tells whether the data is gone.
					// After a signed site_mismatch nothing was released: the activation stays with the other address.
					if ( ! $this->forget() ) {
						return $this->error( 'wplc_credentials_not_removed', array( 'released' => self::is_signed_refusal( $data ) ) );
					}
					return array(
						'status' => 'removed_locally',
						'reason' => $data->get_error_code(),
					);
				}
				return $data;
			}
			if ( ! $this->forget() ) {
				return $this->error( 'wplc_credentials_not_removed', array( 'released' => true ) );
			}

			return $data;
		}

		/**
		 * Remove the stored credentials without contacting the server.
		 *
		 * @since 2.0.3 Returns whether they are gone (was void).
		 *
		 * @return bool Whether neither the credentials nor a pending activation remain stored.
		 */
		public function forget(): bool {
			$credentials = $this->store->delete( $this->option_key );
			$pending     = $this->store->delete( $this->option_key . '_pending' );

			return $credentials && $pending;
		}

		/**
		 * Verify a stored snapshot (payload and signatures) with the current credentials.
		 *
		 * @param array<string,mixed> $envelope              Value of last_envelope() as stored.
		 * @param bool                $skip_server_signature Check the HMAC only (see LicenseManager).
		 * @return array<string,mixed>|\WP_Error Signed data.
		 */
		public function verify_snapshot( array $envelope, bool $skip_server_signature = false ) {
			$data = $this->verified_snapshot_data( $envelope, $skip_server_signature );

			return is_array( $data ) ? $data : $this->error( $data );
		}

		/**
		 * Like verify_snapshot(), but returns the error code instead of a WP_Error.
		 *
		 * Builds no texts, so it can run before `init` (feature checks while the
		 * add-on loads) without loading translations too early.
		 *
		 * The Ed25519 result of a snapshot is remembered when this PHP verifies
		 * with sodium_compat (no ext-sodium, about 20 ms per check): for the rest
		 * of the request, and for a day in the object cache (or a transient)
		 * under a key derived from signature, message and public key. The cached
		 * value is an HMAC of that key with the `AUTH_KEY`/`AUTH_SALT` of
		 * wp-config.php, so a database edit cannot mark a forged snapshot as
		 * verified; without those constants only the per-request memo is used.
		 * The HMAC with the installation secret is checked every time.
		 *
		 * @param array<string,mixed> $envelope              Value of last_envelope() as stored.
		 * @param bool                $skip_server_signature Check the HMAC only (tests and diagnostics; never decide about rights with it).
		 * @return array<string,mixed>|string Signed data, or an error code.
		 */
		public function verified_snapshot_data( array $envelope, bool $skip_server_signature = false ) {
			$credentials = $this->stored_credentials();
			if ( null === $credentials ) {
				return 'wplc_not_activated';
			}
			$keys     = $skip_server_signature ? array() : $this->public_keys;
			$required = $this->keys_given && ! $skip_server_signature;
			$data     = self::verify( $envelope, (string) $credentials['installation_secret'], '', $keys, (string) $credentials['installation_id'], $required, $this->snapshot_verifier() );
			if ( ! is_array( $data ) ) {
				return $data;
			}

			$problem = $this->product_problem( $data, true );

			return '' === $problem ? $data : $problem;
		}

		/**
		 * Whether the Ed25519 checks run on ext-sodium (fast) rather than on sodium_compat or a replacement.
		 *
		 * @return bool
		 */
		public function uses_native_ed25519(): bool {
			return 'sodium_crypto_sign_verify_detached' === $this->verifier && extension_loaded( 'sodium' );
		}

		/**
		 * Ed25519 verification for stored snapshots: the plain verifier, or one that remembers positive results.
		 *
		 * @return callable|null
		 */
		private function snapshot_verifier() {
			$verifier = $this->verifier;
			if ( null === $verifier || $this->uses_native_ed25519() ) {
				return $verifier;
			}
			$secret = self::verification_cache_secret();

			return static function ( string $signature, string $message, string $key ) use ( $verifier, $secret ): bool {
				$hash = hash( 'sha256', strlen( $key ) . ':' . $key . strlen( $signature ) . ':' . $signature . $message );
				if ( isset( self::$verified[ $hash ] ) ) {
					return true;
				}
				$name = 'wplc_ed25519_' . substr( $hash, 0, 40 );
				$tag  = '' === $secret ? '' : hash_hmac( 'sha256', $hash, $secret );
				if ( '' !== $tag ) {
					$stored = self::cache_get( $name );
					if ( is_string( $stored ) && hash_equals( $tag, $stored ) ) {
						self::$verified[ $hash ] = true;
						return true;
					}
				}
				if ( true !== (bool) call_user_func( $verifier, $signature, $message, $key ) ) {
					return false;
				}
				self::$verified[ $hash ] = true;
				if ( '' !== $tag ) {
					self::cache_set( $name, $tag );
				}

				return true;
			};
		}

		/**
		 * Key of the cached Ed25519 results: derived from AUTH_KEY and AUTH_SALT of wp-config.php; empty without them.
		 *
		 * @return string
		 */
		private static function verification_cache_secret(): string {
			if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
				return '';
			}
			$secret = (string) constant( 'AUTH_KEY' ) . (string) constant( 'AUTH_SALT' );
			if ( strlen( $secret ) < 32 || false !== strpos( $secret, 'put your unique phrase here' ) ) {
				return '';
			}

			return hash_hmac( 'sha256', 'wplc-client-ed25519-cache', $secret );
		}

		/**
		 * Read a cached verification tag (object cache when persistent, transient otherwise).
		 *
		 * @param string $name Key.
		 * @return mixed
		 */
		private static function cache_get( string $name ) {
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				return wp_cache_get( $name, 'wplc_client' );
			}

			return function_exists( 'get_transient' ) ? get_transient( $name ) : false;
		}

		/**
		 * Store a verification tag for a day.
		 *
		 * @param string $name Key.
		 * @param string $tag  Tag.
		 * @return void
		 */
		private static function cache_set( string $name, string $tag ): void {
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				wp_cache_set( $name, $tag, 'wplc_client', DAY_IN_SECONDS );
			} elseif ( function_exists( 'set_transient' ) ) {
				set_transient( $name, $tag, DAY_IN_SECONDS );
			}
		}

		/**
		 * Re-verify a response envelope that the add-on stored as an offline cache.
		 *
		 * Store the complete envelope as received (the `envelope` key of the array
		 * returned by check_entitlements_envelope()), never only the decoded data,
		 * and call this before every use of the cache. With pinned server keys the
		 * cache is accepted only with a valid server signature for this
		 * installation. Same check as verify_snapshot().
		 *
		 * @param array<string,mixed> $envelope Envelope as received from the server.
		 * @return array<string,mixed>|\WP_Error Verified data.
		 */
		public function verify_cached_envelope( array $envelope ) {
			return $this->verify_snapshot( $envelope );
		}

		/**
		 * Like check_entitlements(), but also returns the raw envelope for an offline cache.
		 *
		 * @return array{data:array<string,mixed>,envelope:array<string,mixed>}|\WP_Error
		 */
		public function check_entitlements_envelope() {
			$data = $this->check_entitlements();
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return array(
				'data'     => $data,
				'envelope' => $this->last_envelope(),
			);
		}

		/**
		 * Pure verification of the server's Ed25519 payload signature of a fresh answer, without the installation secret.
		 *
		 * Checks the signature `payload_signature_ed25519` over
		 * "wplc-response-v2\n{installation UUID}\n{payload}" against the pinned
		 * keys, and that the payload echoes the nonce of the request the caller
		 * sent. The nonce is mandatory: without it a recorded answer could be
		 * replayed for ever. The legacy `signature_ed25519` (context
		 * `wplc-response-v1`, no nonce) is never accepted since client 2.0.2;
		 * the server still sends it for old copies only.
		 *
		 * For a stored offline cache use verify_cached_envelope() instead (it
		 * also checks the HMAC with the installation secret).
		 *
		 * @param array<string,mixed> $envelope        Decoded response body.
		 * @param string              $installation_id Installation UUID.
		 * @param string[]            $public_keys     Accepted public keys (base64).
		 * @param string              $expected_nonce  Nonce of the request; an empty string is refused.
		 * @return array<string,mixed>|string Verified data, or one of the codes `wplc_server_signature_invalid`,
		 *                                    `wplc_ed25519_unavailable`, `wplc_response_invalid`, `wplc_nonce_mismatch`.
		 */
		public static function verified_server_data( array $envelope, string $installation_id, array $public_keys, string $expected_nonce = '' ) {
			$payload = $envelope['payload'] ?? null;
			if ( '' === $installation_id || ! is_string( $payload ) || '' === $payload ) {
				return 'wplc_server_signature_invalid';
			}
			$verifier = self::default_verifier();
			if ( null === $verifier ) {
				return 'wplc_ed25519_unavailable';
			}
			if ( ! self::server_signature_valid( $payload, $envelope['payload_signature_ed25519'] ?? null, self::decode_keys( $public_keys ), $installation_id, $verifier ) ) {
				return 'wplc_server_signature_invalid';
			}
			$decoded = json_decode( $payload, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'], $decoded['nonce'] ) || ! is_array( $decoded['data'] ) || ! is_string( $decoded['nonce'] ) ) {
				return 'wplc_response_invalid';
			}
			if ( '' === $expected_nonce || ! hash_equals( $expected_nonce, $decoded['nonce'] ) ) {
				return 'wplc_nonce_mismatch';
			}

			return $decoded['data'];
		}

		/**
		 * Pure verification of a response envelope.
		 *
		 * Checks, in this order: the HMAC of the received `payload` text, the
		 * Ed25519 signature of the server over
		 * "wplc-response-v2\n{installation UUID}\n{payload}" (when public keys
		 * are given), the structure of the payload and the echoed nonce. The
		 * legacy members `data`, `signature` and `signature_ed25519` are ignored.
		 *
		 * @param array<string,mixed> $envelope        Decoded response body.
		 * @param string              $secret          Installation secret.
		 * @param string              $expected_nonce  Value the server must echo; an empty string skips the comparison.
		 * @param mixed               $public_keys     Base64 public keys; any non-empty list makes the server signature mandatory.
		 * @param string              $installation_id Installation UUID (part of the signed message).
		 * @return array<string,mixed>|string Verified data, or one of the codes `wplc_signature_invalid`,
		 *                                    `wplc_server_signature_invalid`, `wplc_ed25519_unavailable`,
		 *                                    `wplc_nonce_mismatch`, `wplc_response_invalid`.
		 */
		public static function verified_data( array $envelope, string $secret, string $expected_nonce = '', $public_keys = array(), string $installation_id = '' ) {
			$keys = is_array( $public_keys ) ? self::decode_keys( $public_keys ) : array();

			return self::verify( $envelope, $secret, $expected_nonce, $keys, $installation_id, is_array( $public_keys ) && ! empty( $public_keys ), self::default_verifier() );
		}

		/**
		 * Binary 32-byte keys of a list of base64 keys; invalid entries are skipped.
		 *
		 * @param array<mixed> $public_keys Base64 keys.
		 * @return string[]
		 */
		private static function decode_keys( array $public_keys ): array {
			$keys = array();
			foreach ( $public_keys as $key ) {
				$binary = is_string( $key ) ? base64_decode( trim( $key ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a public key.
				if ( is_string( $binary ) && 32 === strlen( $binary ) ) {
					$keys[] = $binary;
				}
			}

			return $keys;
		}

		/**
		 * Whether a base64 Ed25519 signature of the server over the payload text verifies with one of the keys.
		 *
		 * @param string   $payload         Payload text as received.
		 * @param mixed    $signature       Base64 signature (`payload_signature_ed25519`).
		 * @param string[] $keys            Binary public keys.
		 * @param string   $installation_id Installation UUID.
		 * @param callable $verifier        Ed25519 verification.
		 * @return bool
		 */
		private static function server_signature_valid( string $payload, $signature, array $keys, string $installation_id, callable $verifier ): bool {
			$binary = is_string( $signature ) ? base64_decode( $signature, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a signature.
			if ( ! is_string( $binary ) || 64 !== strlen( $binary ) || '' === $installation_id ) {
				return false;
			}
			$message = self::SIGNATURE_CONTEXT . "\n" . strtolower( $installation_id ) . "\n" . $payload;
			foreach ( $keys as $key ) {
				try {
					if ( true === (bool) call_user_func( $verifier, $binary, $message, $key ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}

			return false;
		}

		/**
		 * Verification with decoded keys.
		 *
		 * @param array<string,mixed> $envelope        Decoded response body.
		 * @param string              $secret          Installation secret.
		 * @param string              $expected_nonce  Expected nonce, or an empty string.
		 * @param string[]            $keys            Binary public keys.
		 * @param string              $installation_id Installation UUID.
		 * @param bool                $require_server  Whether a valid server signature is mandatory.
		 * @param callable|null       $verifier        Ed25519 verification; null when PHP has none.
		 * @return array<string,mixed>|string
		 */
		private static function verify( array $envelope, string $secret, string $expected_nonce, array $keys, string $installation_id, bool $require_server, $verifier ) {
			$payload   = $envelope['payload'] ?? null;
			$signature = $envelope['payload_signature'] ?? null;
			if ( '' === $secret || ! is_string( $payload ) || '' === $payload || ! is_string( $signature ) ) {
				return 'wplc_signature_invalid';
			}
			if ( ! hash_equals( hash_hmac( 'sha256', $payload, $secret ), strtolower( $signature ) ) ) {
				return 'wplc_signature_invalid';
			}
			if ( $require_server ) {
				if ( null === $verifier ) {
					// No Ed25519 implementation: never accept the HMAC alone in place of the server signature.
					return 'wplc_ed25519_unavailable';
				}
				if ( ! self::server_signature_valid( $payload, $envelope['payload_signature_ed25519'] ?? null, $keys, $installation_id, $verifier ) ) {
					return 'wplc_server_signature_invalid';
				}
			}
			$decoded = json_decode( $payload, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'], $decoded['nonce'] ) || ! is_array( $decoded['data'] ) || ! is_string( $decoded['nonce'] ) ) {
				return 'wplc_response_invalid';
			}
			if ( '' !== $expected_nonce && ! hash_equals( $expected_nonce, $decoded['nonce'] ) ) {
				return 'wplc_nonce_mismatch';
			}

			return $decoded['data'];
		}

		/**
		 * Sign and send a request with the stored credentials.
		 *
		 * @param string              $method      Method that is signed and that the server executes.
		 * @param string              $route       REST route.
		 * @param array<string,mixed> $body        JSON body.
		 * @param int                 $timeout     Timeout in seconds, 0 for the default.
		 * @param string              $http_method Method on the wire when it differs (`POST` with a method override).
		 * @return array<string,mixed>|\WP_Error
		 */
		private function signed_request( string $method, string $route, array $body, int $timeout = 0, string $http_method = '' ) {
			if ( ! $this->is_configured() ) {
				return $this->error( 'wplc_configuration_invalid' );
			}
			$credentials = $this->credentials();
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}
			if ( ! $this->site_matches() ) {
				// Copied credentials belong to the original site: never use them from here.
				return $this->error(
					'wplc_site_changed',
					array(
						'activated_site_url' => (string) $credentials['site_url'],
						'current_site_url'   => $this->store->site_url(),
					)
				);
			}
			$raw = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
			if ( false === $raw ) {
				return $this->error( 'wplc_json_error' );
			}
			$secret          = (string) $credentials['installation_secret'];
			$installation_id = (string) $credentials['installation_id'];

			for ( $attempt = 1; ; $attempt++ ) {
				$nonce     = bin2hex( random_bytes( 16 ) );
				$timestamp = (string) ( $this->now() + (int) ( $credentials['clock_offset'] ?? 0 ) );
				$headers   = array(
					'X-WPLC-Installation-Id' => $installation_id,
					'X-WPLC-Timestamp'       => $timestamp,
					'X-WPLC-Nonce'           => $nonce,
					'X-WPLC-Signature'       => hash_hmac( 'sha256', implode( "\n", array( $method, $route, $timestamp, $nonce, hash( 'sha256', $raw ) ) ), $secret ),
				);
				if ( '' !== $http_method && $http_method !== $method ) {
					$headers['X-HTTP-Method-Override'] = $method;
				}
				$http = $this->send( '' === $http_method ? $method : $http_method, $route, $raw, $headers, $timeout );
				if ( is_wp_error( $http ) ) {
					return $http;
				}
				$data = $this->interpret( $http, $secret, $nonce, $installation_id, true );
				if ( ! is_wp_error( $data ) || $attempt > 1 ) {
					return $data;
				}
				if ( 'request_expired' === $data->get_error_code() ) {
					// A wrong local clock must not end in a permanent failure: follow the clock of the server once.
					$server_time = ( (array) $data->get_error_data() )['server_time'] ?? null;
					if ( ! is_numeric( $server_time ) ) {
						return $data;
					}
					$credentials['clock_offset'] = (int) $server_time - $this->now();
					$this->store->set( $this->option_key, $credentials );
					continue;
				}
				if ( 'request_replayed' !== $data->get_error_code() ) {
					return $data;
				}
			}
		}

		/**
		 * Turn an HTTP answer into verified data or an error.
		 *
		 * @param array<string,mixed> $http            Status, body, headers.
		 * @param string              $secret          Installation secret.
		 * @param string              $nonce           Value the server must echo.
		 * @param string              $installation_id Installation UUID.
		 * @param bool                $authenticated   Whether the request was signed (enables the signed refusal).
		 * @return array<string,mixed>|\WP_Error
		 */
		private function interpret( array $http, string $secret, string $nonce, string $installation_id, bool $authenticated ) {
			$status = (int) $http['status'];
			$json   = json_decode( (string) $http['body'], true );
			$html   = ! is_array( $json ) && ( false !== stripos( (string) $http['content_type'], 'html' ) || 0 === strpos( ltrim( (string) $http['body'] ), '<' ) );

			if ( $status < 200 || $status >= 300 ) {
				if ( ! is_array( $json ) || ! isset( $json['code'] ) || ! is_string( $json['code'] ) ) {
					// Not an answer of the REST API: a firewall, a bot challenge, a maintenance page.
					return $this->error( $html ? 'wplc_blocked_by_firewall' : 'wplc_http_error', array( 'status' => $status ) );
				}
				$code           = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', $json['code'] ) );
				$data           = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
				$data['status'] = $status;
				if ( '' !== $http['retry_after'] && ! isset( $data['retry_after'] ) ) {
					$data['retry_after'] = (int) $http['retry_after'];
				}
				if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
					// For logs only; the text shown to the user comes from Messages.
					$data['server_message'] = substr( wp_strip_all_tags( $json['message'] ), 0, 300 );
				}
				$signed = $data['signed'] ?? null;
				unset( $data['signed'], $data['wplc_signed'], $data['verified'] );
				if ( $authenticated && in_array( $code, array( 'activation_deactivated', 'site_mismatch' ), true ) && is_array( $signed ) ) {
					$verified = self::verify( $signed, $secret, $nonce, $this->public_keys, $installation_id, $this->keys_given, $this->verifier );
					if ( is_array( $verified ) && 'activation_deactivated' === $code && 'deactivated' === ( $verified['activation_status'] ?? '' ) ) {
						// Only this signed refusal may end the activation; an unsigned 4xx never does.
						$data['wplc_signed'] = true;
						$data['verified']    = $verified;
						$this->forget();
					} elseif ( is_array( $verified ) && 'site_mismatch' === $code && false === ( $verified['site_match'] ?? null ) ) {
						// Signed: the activation belongs to another address (since 2.1.0). deactivate() decides what follows.
						$data['wplc_signed'] = true;
						$data['verified']    = $verified;
					}
				}

				return $this->error( $code, $data );
			}

			if ( ! is_array( $json ) ) {
				return $this->error( $html ? 'wplc_blocked_by_firewall' : 'wplc_response_invalid', array( 'status' => $status ) );
			}
			$data = self::verify( $json, $secret, $nonce, $this->public_keys, $installation_id, $this->keys_given, $this->verifier );
			if ( ! is_array( $data ) ) {
				return $this->error( $data, array( 'status' => $status ) );
			}
			$this->last_envelope = array(
				'payload'                   => (string) $json['payload'],
				'payload_signature'         => (string) $json['payload_signature'],
				'payload_signature_ed25519' => isset( $json['payload_signature_ed25519'] ) && is_string( $json['payload_signature_ed25519'] ) ? $json['payload_signature_ed25519'] : '',
				'key_id'                    => isset( $json['key_id'] ) && is_string( $json['key_id'] ) ? $json['key_id'] : '',
			);

			return $data;
		}

		/**
		 * Send a request; tries the fallback addresses when the server cannot be reached.
		 *
		 * @param string                     $method  HTTP method.
		 * @param string                     $route   REST route.
		 * @param array<string,mixed>|string $body    Body: array (encoded here) or the raw JSON text.
		 * @param array<string,string>       $headers Additional headers.
		 * @param int                        $timeout Timeout in seconds, 0 for the default.
		 * @return array{status:int,body:string,content_type:string,retry_after:string}|\WP_Error
		 */
		private function send( string $method, string $route, $body, array $headers, int $timeout = 0 ) {
			if ( is_array( $body ) ) {
				$body = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
				if ( false === $body ) {
					return $this->error( 'wplc_json_error' );
				}
			}
			if ( 0 === $timeout ) {
				$timeout = $this->timeout;
			}
			if ( 0 === $timeout ) {
				// A page load of the customer must never wait long for the license server.
				$background = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
				$timeout    = $background ? 15 : 5;
			}
			$args  = array(
				'method'      => $method,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array_merge(
					array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					$headers
				),
				'body'        => $body,
			);
			$error = null;
			foreach ( $this->api_bases as $base ) {
				$http = null === $this->transport ? $this->safe_request( $base . $route, $args ) : call_user_func( $this->transport, $base . $route, $args );
				if ( is_wp_error( $http ) ) {
					$error = $http;
					continue;
				}
				if ( ! is_array( $http ) ) {
					$error = new \WP_Error( 'http_request_failed', 'Invalid transport result.' );
					continue;
				}
				$response_headers = $http['headers'] ?? array();

				return array(
					'status'       => (int) ( $http['response']['code'] ?? 0 ),
					'body'         => (string) ( $http['body'] ?? '' ),
					'content_type' => self::header( $response_headers, 'content-type' ),
					'retry_after'  => self::header( $response_headers, 'retry-after' ),
				);
			}

			return $this->error(
				'wplc_connection_failed',
				array(
					'transport_code'    => null === $error ? '' : (string) $error->get_error_code(),
					'transport_message' => null === $error ? '' : (string) $error->get_error_message(),
				)
			);
		}

		/**
		 * Send one request with wp_safe_remote_request() (since 2.1.0; was wp_remote_request()).
		 *
		 * WordPress refuses hosts that resolve to a local or private IP address and ports other
		 * than 80, 443 and 8080, also after a DNS change of the server host. With
		 * `allow_local_server` only the host (and port) of this URL is let through, while this
		 * request runs.
		 *
		 * @param string              $url  Full URL.
		 * @param array<string,mixed> $args Arguments of the WordPress HTTP API.
		 * @return array<string,mixed>|\WP_Error
		 */
		private function safe_request( string $url, array $args ) {
			$host  = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );
			$port  = (int) wp_parse_url( $url, PHP_URL_PORT );
			$allow = $this->allow_local_server && '' !== $host && function_exists( 'add_filter' ) && function_exists( 'remove_filter' );
			if ( ! $allow ) {
				return wp_safe_remote_request( $url, $args );
			}
			$external = static function ( $is_external, $request_host = '' ) use ( $host ) {
				return (bool) $is_external || strtolower( trim( (string) $request_host, '[]' ) ) === $host;
			};
			$ports    = static function ( $allowed, $request_host = '' ) use ( $host, $port ) {
				$allowed = (array) $allowed;
				if ( $port > 0 && strtolower( trim( (string) $request_host, '[]' ) ) === $host ) {
					$allowed[] = $port;
				}
				return $allowed;
			};
			add_filter( 'http_request_host_is_external', $external, 10, 2 );
			add_filter( 'http_allowed_safe_ports', $ports, 10, 2 );
			try {
				return wp_safe_remote_request( $url, $args );
			} finally {
				remove_filter( 'http_request_host_is_external', $external, 10 );
				remove_filter( 'http_allowed_safe_ports', $ports, 10 );
			}
		}

		// ------------------------------------------------------------------
		// Helpers.
		// ------------------------------------------------------------------

		/**
		 * Reject signed data that was issued for another product or names none.
		 *
		 * @param array<string,mixed> $data          Signed data.
		 * @param bool                $allow_refusal Accept a signed "deactivated" answer without product key (entitlement check).
		 * @return array<string,mixed>|\WP_Error
		 */
		private function check_product( array $data, bool $allow_refusal = true ) {
			$problem = $this->product_problem( $data, $allow_refusal );

			return '' === $problem ? $data : $this->error( $problem );
		}

		/**
		 * Why signed data does not belong to this add-on; empty when it does.
		 *
		 * Since 2.0.3 `product_key` is required: the server sends it in every activation, entitlement and
		 * update answer, and signed data without it is `wplc_response_invalid`. The one exception (with
		 * `$allow_refusal`) is the signed "deactivated" answer of the entitlement check, which carries no
		 * product key and grants nothing. Builds no texts (used by verified_snapshot_data()).
		 *
		 * @param array<string,mixed> $data          Signed data.
		 * @param bool                $allow_refusal Accept `activation_status: deactivated` without access and without product key.
		 * @return string Error code or ''.
		 */
		private function product_problem( array $data, bool $allow_refusal ): string {
			$product = $data['product_key'] ?? null;
			if ( ! is_string( $product ) || '' === $product ) {
				$refusal = 'deactivated' === ( $data['activation_status'] ?? '' ) && true !== ( $data['access'] ?? null );

				return $allow_refusal && $refusal ? '' : 'wplc_response_invalid';
			}

			return strtolower( $product ) === $this->product_key ? '' : 'wplc_product_mismatch';
		}

		/**
		 * A positive integer ID from signed data; 0 for anything else.
		 *
		 * @param mixed $value Value of the answer (JSON integer, or a decimal string without leading zero).
		 * @return int
		 */
		private static function positive_id( $value ): int {
			if ( is_int( $value ) ) {
				return $value > 0 ? $value : 0;
			}
			if ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,17}$/D', $value ) ) {
				return (int) $value;
			}

			return 0;
		}

		/**
		 * Versions reported to the server (support, compatibility of updates).
		 *
		 * @return array<string,string>
		 */
		private function versions(): array {
			return array(
				'product_version' => $this->product_version,
				'wp_version'      => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
				'php_version'     => PHP_VERSION,
			);
		}

		/**
		 * Stored credentials of a completed activation.
		 *
		 * @return array<string,mixed>|\WP_Error
		 */
		private function credentials() {
			$credentials = $this->stored_credentials();

			return null === $credentials ? $this->error( 'wplc_not_activated' ) : $credentials;
		}

		/**
		 * Stored credentials of a completed activation, or null (no error object, for the cheap state checks).
		 *
		 * @return array<string,mixed>|null
		 */
		private function stored_credentials() {
			$credentials = '' === $this->option_key ? array() : $this->store->get( $this->option_key, array() );
			if ( ! is_array( $credentials ) || empty( $credentials['installation_id'] ) || empty( $credentials['installation_secret'] ) || empty( $credentials['activation_id'] ) ) {
				return null;
			}

			return $credentials;
		}

		/**
		 * Ed25519 verification of this PHP: ext-sodium or the sodium_compat polyfill WordPress ships.
		 *
		 * @return callable|null
		 */
		private static function default_verifier() {
			return function_exists( 'sodium_crypto_sign_verify_detached' ) ? 'sodium_crypto_sign_verify_detached' : null;
		}

		/**
		 * Build an error with the client's own text for the code.
		 *
		 * Since 2.1.0 no text is built before `init` (Messages::ready()): the message is then the
		 * code itself and the text is looked up when it is shown (LicenseManager::error_text()).
		 *
		 * @param string              $code Error code.
		 * @param array<string,mixed> $data Error data.
		 * @return \WP_Error
		 */
		private function error( string $code, array $data = array() ): \WP_Error {
			return new \WP_Error( $code, Messages::ready() ? $this->messages->error( $code ) : $code, $data );
		}

		/**
		 * Read one response header from an array or a header object.
		 *
		 * @param mixed  $headers Headers.
		 * @param string $name    Lower-case name.
		 * @return string
		 */
		private static function header( $headers, string $name ): string {
			if ( is_array( $headers ) ) {
				foreach ( $headers as $key => $value ) {
					if ( strtolower( (string) $key ) === $name ) {
						return is_array( $value ) ? (string) reset( $value ) : (string) $value;
					}
				}
				return '';
			}
			if ( $headers instanceof \ArrayAccess && isset( $headers[ $name ] ) ) {
				$value = $headers[ $name ];
				return is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}

			return '';
		}

		/**
		 * Validate a server address.
		 *
		 * @param string $url         URL.
		 * @param bool   $insecure    Accept http.
		 * @param bool   $allow_query Accept a query string (REST base with `?rest_route=`).
		 * @return string URL without trailing slash, or an empty string.
		 */
		private static function clean_server_url( string $url, bool $insecure, bool $allow_query = false ): string {
			$url   = rtrim( trim( $url ), '/' );
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
				return '';
			}
			if ( isset( $parts['query'] ) && ! $allow_query ) {
				return '';
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			if ( 'https' !== $scheme && ! ( $insecure && 'http' === $scheme ) ) {
				return '';
			}

			return $url;
		}

		/**
		 * Comparison key of a site address: host without `www.` plus path, lower case, without scheme.
		 *
		 * Mirrors the rule of the server ("the same site", docs/API-V1.md), so a
		 * switch to HTTPS or to `www.` is not taken for a copy.
		 *
		 * @param string $url Site address.
		 * @return string
		 */
		public static function site_key( string $url ): string {
			$parts = wp_parse_url( trim( $url ) );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return strtolower( trim( $url ) );
			}
			$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}
			$port = isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ? ':' . (int) $parts['port'] : '';
			$path = isset( $parts['path'] ) ? strtolower( rtrim( preg_replace( '#/+#', '/', (string) $parts['path'] ), '/' ) ) : '';

			return $host . $port . $path;
		}

		/**
		 * Random UUID version 4.
		 *
		 * @return string
		 */
		private static function uuid4(): string {
			$bytes    = random_bytes( 16 );
			$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
			$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
		}
	}
}
