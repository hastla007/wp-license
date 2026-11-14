<?php
/**
 * License state of this site: activation, signed offline cache, entitlement decisions.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\License;

use Vendor\Product\Premium\Client\LicenseClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps LicenseClient with the add-on's rules (B5, B6):
 *
 * - The offline cache is the complete signed envelope of the last entitlement check. It is re-verified with
 *   LicenseClient::verify_cached_envelope() (HMAC and the pinned server signature) before every use.
 * - The cache is valid for at most CACHE_MAX_AGE seconds after the server issued it (signed `server_time`).
 * - Server errors keep the cache; answers that the activation no longer exists clear it; a signed
 *   expired/revoked answer replaces it, which switches premium features off at once.
 * - Decisions fail closed: no pinned key, no cache, a tampered or stale cache, a foreign entitlement prefix or a
 *   status without access all mean "no".
 * - The client takes the server's answer as it is and does not compute grace periods itself.
 */
final class LicenseService {
	/** Statuses with access, as the server reports them (past_due = renewal grace granted by the server). */
	public const ACCESS_STATUSES = array( 'active', 'past_due' );

	/** Errors after which the stored activation is gone on the server: the cache is dropped. */
	public const GONE_ERRORS = array( 'activation_not_found', 'installation_secret_invalid', 'license_not_found', 'wplc_not_activated' );

	/** @var LicenseClient */
	private $client;
	/** @var string */
	private $credentials_option;
	/** @var string */
	private $state_option;
	/** @var string */
	private $entitlement_prefix;
	/** @var int */
	private $max_age;
	/** @var callable */
	private $clock;
	/** @var array<string,mixed>|null|false Verified snapshot of this request; false = not computed yet. */
	private $memo = false;
	/** @var string */
	private $cache_problem = '';

	/**
	 * @param LicenseClient $client             Client configured with server URL, product key and pinned keys.
	 * @param string        $credentials_option Option the client stores its credentials in.
	 * @param string        $state_option       Option for the signed cache and status metadata.
	 * @param string        $entitlement_prefix Prefix of this product's entitlement keys, e.g. "voucher.".
	 * @param int           $max_age            Maximum cache age in seconds.
	 * @param callable|null $clock              Returns the current Unix time (tests).
	 */
	public function __construct( LicenseClient $client, string $credentials_option, string $state_option, string $entitlement_prefix, int $max_age = 1209600, ?callable $clock = null ) {
		$this->client             = $client;
		$this->credentials_option = $credentials_option;
		$this->state_option       = $state_option;
		$this->entitlement_prefix = $entitlement_prefix;
		$this->max_age            = $max_age;
		$this->clock              = null === $clock ? 'time' : $clock;
	}

	/** Whether the build pins a server key. Without one nothing is activated or unlocked. */
	public function is_configured(): bool {
		return $this->client->requires_server_signature() && '' !== $this->entitlement_prefix;
	}

	/** Whether installation credentials are stored. */
	public function is_activated(): bool {
		$credentials = get_option( $this->credentials_option, array() );
		return is_array( $credentials ) && ! empty( $credentials['installation_id'] ) && ! empty( $credentials['installation_secret'] ) && ! empty( $credentials['activation_id'] );
	}

	/** Installation UUID of this site ('' when not activated). Not secret; a staging site needs it as parent. */
	public function installation_id(): string {
		$credentials = get_option( $this->credentials_option, array() );
		return is_array( $credentials ) ? (string) ( $credentials['installation_id'] ?? '' ) : '';
	}

	/**
	 * Activate this site.
	 *
	 * @param string $license_key    License key as entered.
	 * @param string $environment    production, staging or development.
	 * @param string $staging_parent Installation UUID of the live site (staging only).
	 * @return array<string,mixed>|\WP_Error Verified activation data.
	 */
	public function activate( string $license_key, string $environment = 'production', string $staging_parent = '' ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$license_key = strtoupper( trim( sanitize_text_field( $license_key ) ) );
		if ( '' === $license_key ) {
			return new \WP_Error( 'license_key_missing', __( 'Enter a license key.', 'premium-addon-starter' ) );
		}
		if ( ! in_array( $environment, array( 'production', 'staging', 'development' ), true ) ) {
			return new \WP_Error( 'environment_invalid', __( 'Choose production, staging or development.', 'premium-addon-starter' ) );
		}
		if ( 'staging' === $environment && ! wp_is_uuid( $staging_parent ) ) {
			return new \WP_Error( 'staging_parent_missing', __( 'A staging site needs the installation ID of its live site (shown on the license page of the live site).', 'premium-addon-starter' ) );
		}
		$force       = false;
		$credentials = get_option( $this->credentials_option, array() );
		if ( $this->is_activated() && is_array( $credentials ) ) {
			$stored_hash = (string) ( $credentials['license_key_hash'] ?? '' );
			$other_key   = '' !== $stored_hash && ! hash_equals( $stored_hash, hash( 'sha256', $license_key ) );
			$stored_env  = $this->state_value( 'environment' );
			$other_env   = '' !== $stored_env && $stored_env !== $environment;
			if ( $other_key || $other_env ) {
				// A different key or environment: release the old slot first (best effort), then activate anew.
				$this->client->deactivate();
				$force = true;
			}
		}
		$result = $this->client->activate( $license_key, $environment, 'staging' === $environment ? $staging_parent : '', $force );
		if ( is_wp_error( $result ) ) {
			$this->save_state( array( 'last_error' => $this->error_text( $result ) ) );
			return $result;
		}
		$this->save_state(
			array(
				'envelope'    => null,
				'key_hint'    => substr( $license_key, -4 ),
				'environment' => $environment,
				'last_error'  => '',
			)
		);
		// The activation answer is not exposed as an envelope; fetch the signed entitlements for the cache now.
		$this->refresh();
		return $result;
	}

	/**
	 * Release this site's activation on the server and delete the local credentials and cache.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate() {
		$result = $this->client->deactivate();
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), self::GONE_ERRORS, true ) ) {
			$this->save_state( array( 'last_error' => $this->error_text( $result ) ) );
			return $result;
		}
		$this->forget();
		return is_wp_error( $result ) ? array() : $result;
	}

	/** Delete credentials and cache locally (the server is not asked). */
	public function forget(): void {
		delete_option( $this->credentials_option );
		delete_option( $this->state_option );
		$this->memo = false;
	}

	/**
	 * Fetch the current signed entitlements and store the envelope as offline cache.
	 *
	 * @return array<string,mixed>|\WP_Error Verified data.
	 */
	public function refresh() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$now    = (int) call_user_func( $this->clock );
		$result = $this->client->check_entitlements_envelope();
		if ( is_wp_error( $result ) ) {
			$changes = array( 'last_error' => $this->error_text( $result ), 'last_attempt' => $now );
			if ( in_array( $result->get_error_code(), self::GONE_ERRORS, true ) ) {
				$changes['envelope'] = null;
			}
			$this->save_state( $changes );
			return $result;
		}
		$saved = $this->save_state(
			array(
				'envelope'     => $result['envelope'],
				'checked_at'   => $now,
				'last_attempt' => $now,
				'last_error'   => '',
				'environment'  => (string) ( $result['data']['environment'] ?? $this->state_value( 'environment' ) ),
			)
		);
		if ( ! $saved ) {
			// An old "active" cache must not outlive a newer answer: drop it when the new one cannot be stored.
			delete_option( $this->state_option );
			$this->memo = false;
			return new \WP_Error( 'license_state_not_saved', __( 'The license status could not be stored. Premium features stay off until the next successful check.', 'premium-addon-starter' ) );
		}
		return $result['data'];
	}

	/**
	 * Verified, fresh entitlement data from the cache, or null (fail closed).
	 *
	 * @return array<string,mixed>|null
	 */
	public function snapshot(): ?array {
		if ( false !== $this->memo ) {
			return $this->memo;
		}
		$this->memo = $this->verified_snapshot();
		return $this->memo;
	}

	/** Why snapshot() returned null: '', 'not_configured', 'missing', 'invalid', 'stale'. */
	public function cache_problem(): string {
		$this->snapshot();
		return $this->cache_problem;
	}

	/** Whether the license currently grants access (status as reported by the server). */
	public function has_access(): bool {
		$data = $this->snapshot();
		return is_array( $data ) && in_array( (string) ( $data['license_status'] ?? '' ), self::ACCESS_STATUSES, true );
	}

	/**
	 * Whether a premium feature is unlocked. Fails closed.
	 *
	 * @param string $key Entitlement key with this product's prefix, e.g. "voucher.pdf_templates".
	 */
	public function has( string $key ): bool {
		$value = $this->value( $key );
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ( is_int( $value ) || is_float( $value ) ) && $value > 0;
	}

	/**
	 * Raw entitlement value (true, a numeric limit, …) or null when it is not granted.
	 *
	 * @param string $key Entitlement key with this product's prefix.
	 * @return mixed
	 */
	public function value( string $key ) {
		if ( '' === $this->entitlement_prefix || 0 !== strpos( $key, $this->entitlement_prefix ) || ! $this->has_access() ) {
			return null;
		}
		$data         = $this->snapshot();
		$entitlements = is_array( $data ) && is_array( $data['entitlements'] ?? null ) ? $data['entitlements'] : array();
		return array_key_exists( $key, $entitlements ) ? $entitlements[ $key ] : null;
	}

	/**
	 * Status metadata for the settings page (never the secret or the license key).
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$state = get_option( $this->state_option, array() );
		$state = is_array( $state ) ? $state : array();
		unset( $state['envelope'] );
		return $state;
	}

	/**
	 * Verify the cached envelope and check its age.
	 *
	 * @return array<string,mixed>|null
	 */
	private function verified_snapshot(): ?array {
		if ( ! $this->is_configured() ) {
			$this->cache_problem = 'not_configured';
			return null;
		}
		$state    = get_option( $this->state_option, array() );
		$envelope = is_array( $state ) ? ( $state['envelope'] ?? null ) : null;
		if ( ! is_array( $envelope ) ) {
			$this->cache_problem = 'missing';
			return null;
		}
		$data = $this->client->verify_cached_envelope( $envelope );
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			$this->cache_problem = is_wp_error( $data ) && 'wplc_not_activated' === $data->get_error_code() ? 'missing' : 'invalid';
			return null;
		}
		$now     = (int) call_user_func( $this->clock );
		$issued  = strtotime( (string) ( $data['server_time'] ?? '' ) . ' UTC' );
		$expires = isset( $data['cache_expires_at'] ) ? strtotime( (string) $data['cache_expires_at'] . ' UTC' ) : false;
		if ( false === $issued || $now - $issued > $this->max_age || ( false !== $expires && $now > $expires ) ) {
			$this->cache_problem = 'stale';
			return null;
		}
		$this->cache_problem = '';
		return $data;
	}

	/**
	 * Merge changes into the state option.
	 *
	 * @param array<string,mixed> $changes Fields to set.
	 * @return bool Whether the stored option now holds the changes.
	 */
	private function save_state( array $changes ): bool {
		$state = get_option( $this->state_option, array() );
		$state = is_array( $state ) ? $state : array();
		$next  = array_merge( $state, $changes );
		$this->memo = false;
		if ( $next === $state ) {
			return true;
		}
		update_option( $this->state_option, $next, false );
		return get_option( $this->state_option, array() ) === $next;
	}

	/**
	 * One field of the stored state.
	 *
	 * @param string $field Field.
	 */
	private function state_value( string $field ): string {
		$state = get_option( $this->state_option, array() );
		return is_array( $state ) ? (string) ( $state[ $field ] ?? '' ) : '';
	}

	/**
	 * Error message with its code, for the settings page.
	 *
	 * @param \WP_Error $error Error.
	 */
	private function error_text( \WP_Error $error ): string {
		return sanitize_text_field( $error->get_error_message() . ' (' . $error->get_error_code() . ')' );
	}

	/** Error for a build without pinned server key. */
	private function not_configured(): \WP_Error {
		return new \WP_Error( 'license_not_configured', __( 'This build has no pinned license server key. Premium features stay off.', 'premium-addon-starter' ) );
	}
}
