<?php
/**
 * Premium updates from the license server (B7).
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\Update;

use Vendor\Product\Premium\Client\LicenseClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the add-on into the WordPress plugin updater.
 *
 * - Detection: check() asks /wplc/v1/updates/check (signed) from the daily cron and whenever WordPress rebuilds the
 *   update_plugins transient (also from cron) and the stored result is older than 12 hours. No capability check:
 *   detection must not depend on a logged-in admin.
 * - Download: the package URL is used up by its first request and valid for five minutes, so upgrader_pre_download asks for a fresh one
 *   right before downloading, follows redirects by hand (HTTPS, allow-listed hosts only), enforces the size limit and
 *   compares the SHA-256 from the signed answer before WordPress unpacks anything. Any failure returns a WP_Error;
 *   WordPress then aborts and the installed version stays untouched.
 */
final class Updater {
	/** Seconds after which the transient filter asks the server again. */
	public const RECHECK_AFTER = 43200;

	/** Route prefix of one-time package URLs on the license server. */
	public const DOWNLOAD_ROUTE = '/wp-json/wplc/v1/updates/download/';

	/** @var LicenseClient */
	private $client;
	/** @var string */
	private $plugin_basename;
	/** @var string */
	private $slug;
	/** @var string */
	private $product_key;
	/** @var string */
	private $current_version;
	/** @var string */
	private $option;
	/** @var string */
	private $server_url;
	/** @var string[] */
	private $allowed_hosts;
	/** @var int */
	private $max_bytes;
	/** @var callable */
	private $clock;

	/**
	 * @param LicenseClient $client          Configured client.
	 * @param string        $plugin_basename e.g. voucher-captain-pro/voucher-captain-pro.php.
	 * @param string        $slug            Plugin slug.
	 * @param string        $product_key     Product key of the license server catalog.
	 * @param string        $current_version Installed version.
	 * @param string        $option          Option for the last check.
	 * @param string        $server_url      License server URL.
	 * @param string[]      $allowed_hosts   Hosts downloads may come from.
	 * @param int           $max_bytes       Largest accepted ZIP.
	 * @param callable|null $clock           Returns the current Unix time (tests).
	 */
	public function __construct( LicenseClient $client, string $plugin_basename, string $slug, string $product_key, string $current_version, string $option, string $server_url, array $allowed_hosts, int $max_bytes, ?callable $clock = null ) {
		$this->client          = $client;
		$this->plugin_basename = $plugin_basename;
		$this->slug            = $slug;
		$this->product_key     = $product_key;
		$this->current_version = $current_version;
		$this->option          = $option;
		$this->server_url      = untrailingslashit( $server_url );
		$this->allowed_hosts   = array_map( 'strtolower', $allowed_hosts );
		$this->max_bytes       = $max_bytes;
		$this->clock           = null === $clock ? 'time' : $clock;
	}

	/** Register the WordPress hooks. */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
	}

	/**
	 * Ask the server for an update and store the validated result.
	 *
	 * @return array<string,mixed>|\WP_Error Stored update metadata (update_available false when none).
	 */
	public function check() {
		$now    = (int) call_user_func( $this->clock );
		$result = $this->client->check_update( true );
		if ( is_wp_error( $result ) ) {
			$stored = $this->stored();
			// Without access (license_inactive, activation gone) no update is offered any more.
			if ( in_array( $result->get_error_code(), array( 'license_inactive', 'activation_not_found', 'wplc_not_activated' ), true ) ) {
				$stored['update'] = null;
			}
			$stored['checked_at'] = $now;
			$stored['last_error'] = sanitize_text_field( $result->get_error_message() . ' (' . $result->get_error_code() . ')' );
			update_option( $this->option, $stored, false );
			return $result;
		}
		$update = $this->validated( $result );
		if ( is_wp_error( $update ) ) {
			update_option( $this->option, array( 'checked_at' => $now, 'update' => null, 'last_error' => $update->get_error_message() ), false );
			return $update;
		}
		update_option( $this->option, array( 'checked_at' => $now, 'update' => $update, 'last_error' => '' ), false );
		return null === $update ? array( 'update_available' => false ) : $update;
	}

	/**
	 * Add the stored update to WordPress' update_plugins transient.
	 *
	 * @param mixed $transient Transient value.
	 * @return mixed
	 */
	public function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$stored = $this->stored();
		$now    = (int) call_user_func( $this->clock );
		if ( $now - (int) ( $stored['checked_at'] ?? 0 ) > self::RECHECK_AFTER ) {
			$this->check();
			$stored = $this->stored();
		}
		$update = is_array( $stored['update'] ?? null ) ? $stored['update'] : null;
		$item   = array(
			'id'          => $this->server_url . '/' . $this->slug,
			'slug'        => $this->slug,
			'plugin'      => $this->plugin_basename,
			'new_version' => $this->current_version,
			'url'         => $this->server_url,
			'package'     => '',
		);
		if ( null !== $update && version_compare( (string) $update['version'], $this->current_version, '>' ) ) {
			$item['new_version']  = (string) $update['version'];
			$item['package']      = (string) $update['package_url'];
			$item['requires_php'] = (string) $update['requires_php'];
			$item['requires']     = (string) $update['requires_wp'];
			$item['tested']       = (string) $update['tested_wp'];
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $this->plugin_basename ] = (object) $item;
			unset( $transient->no_update[ $this->plugin_basename ] );
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->plugin_basename ] = (object) $item;
			unset( $transient->response[ $this->plugin_basename ] );
		}
		return $transient;
	}

	/**
	 * "View details" for this plugin.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}
		$stored = $this->stored();
		$update = is_array( $stored['update'] ?? null ) ? $stored['update'] : array();
		return (object) array(
			'name'          => $this->slug,
			'slug'          => $this->slug,
			'version'       => (string) ( $update['version'] ?? $this->current_version ),
			'requires_php'  => (string) ( $update['requires_php'] ?? '' ),
			'requires'      => (string) ( $update['requires_wp'] ?? '' ),
			'tested'        => (string) ( $update['tested_wp'] ?? '' ),
			'homepage'      => $this->server_url,
			'download_link' => '',
			'sections'      => array( 'changelog' => wp_kses_post( (string) ( $update['release_notes'] ?? '' ) ) ),
		);
	}

	/**
	 * Download and verify this plugin's package instead of WordPress.
	 *
	 * @param mixed               $reply      false, or what an earlier filter returned.
	 * @param string              $package    Package URL from the transient.
	 * @param mixed               $upgrader   WP_Upgrader.
	 * @param array<string,mixed> $hook_extra Upgrade context.
	 * @return mixed Path of the verified ZIP, WP_Error, or $reply for other packages.
	 */
	public function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! $this->is_own_package( (string) $package, is_array( $hook_extra ) ? $hook_extra : array() ) ) {
			return $reply;
		}
		// The URL in the transient may be used or expired: ask for a fresh grant and checksum.
		$fresh = $this->client->check_update( true );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$update = $this->validated( $fresh );
		if ( is_wp_error( $update ) ) {
			return $update;
		}
		if ( null === $update ) {
			return new \WP_Error( 'update_not_available', __( 'The license server offers no update for this site.', 'premium-addon-starter' ) );
		}
		return $this->download( (string) $update['package_url'], (string) $update['sha256'] );
	}

	/**
	 * Download a package to a temporary file and verify size and SHA-256.
	 *
	 * @param string $url    One-time package URL.
	 * @param string $sha256 Expected checksum from the signed answer.
	 * @return string|\WP_Error Path of the verified file.
	 */
	public function download( string $url, string $sha256 ) {
		$file = wp_tempnam( $this->slug . '.zip' );
		if ( ! is_string( $file ) || '' === $file ) {
			return new \WP_Error( 'package_temp_failed', __( 'Could not create a temporary file for the update.', 'premium-addon-starter' ) );
		}
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			if ( ! $this->is_allowed_url( $url ) ) {
				return $this->fail( $file, 'package_host_not_allowed', __( 'The update package is not served from an allowed HTTPS host.', 'premium-addon-starter' ) );
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 300,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $this->max_bytes + 1,
					'headers'             => array( 'Accept' => 'application/zip, application/octet-stream' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $this->fail( $file, 'package_download_failed', $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$url      = 0 === strpos( $location, '/' ) ? $this->origin( $url ) . $location : $location;
				continue;
			}
			if ( 200 !== $code ) {
				/* translators: %d: HTTP status code. */
				return $this->fail( $file, 'package_download_failed', sprintf( __( 'The update download failed (HTTP %d).', 'premium-addon-starter' ), $code ) );
			}
			$length = (int) wp_remote_retrieve_header( $response, 'content-length' );
			clearstatcache( true, $file );
			if ( $length > $this->max_bytes || (int) filesize( $file ) > $this->max_bytes ) {
				return $this->fail( $file, 'package_too_large', __( 'The update package is larger than allowed.', 'premium-addon-starter' ) );
			}
			$actual = (string) hash_file( 'sha256', $file );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || ! hash_equals( strtolower( $sha256 ), $actual ) ) {
				return $this->fail( $file, 'package_checksum_mismatch', __( 'The update package does not match its signed SHA-256 checksum. Nothing was installed.', 'premium-addon-starter' ) );
			}
			return $file;
		}
		return $this->fail( $file, 'package_too_many_redirects', __( 'The update download was redirected too often.', 'premium-addon-starter' ) );
	}

	/**
	 * Forget the stored update after this plugin was updated.
	 *
	 * @param mixed               $upgrader WP_Upgrader.
	 * @param array<string,mixed> $options  Upgrade context.
	 */
	public function after_upgrade( $upgrader, $options ): void {
		$plugins = (array) ( $options['plugins'] ?? array() );
		if ( isset( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}
		if ( 'plugin' === ( $options['type'] ?? '' ) && in_array( $this->plugin_basename, $plugins, true ) ) {
			delete_option( $this->option );
		}
	}

	/**
	 * Stored result of the last check.
	 *
	 * @return array<string,mixed>
	 */
	public function stored(): array {
		$stored = get_option( $this->option, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Validate a verified update answer.
	 *
	 * @param array<string,mixed> $data Verified data of updates/check.
	 * @return array<string,string>|null|\WP_Error Metadata, null when there is no newer version.
	 */
	private function validated( array $data ) {
		if ( empty( $data['update_available'] ) ) {
			return null;
		}
		$update = array();
		foreach ( array( 'version', 'package_url', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'release_notes', 'product_key' ) as $field ) {
			$update[ $field ] = is_scalar( $data[ $field ] ?? null ) ? (string) $data[ $field ] : '';
		}
		$product_ok = '' === $update['product_key'] || $update['product_key'] === $this->product_key;
		if ( ! preg_match( '/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $update['version'] ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $update['sha256'] ) ) || ! $this->is_allowed_url( $update['package_url'] ) || ! $product_ok ) {
			return new \WP_Error( 'update_metadata_invalid', __( 'The license server sent invalid update data.', 'premium-addon-starter' ) );
		}
		$update['sha256'] = strtolower( $update['sha256'] );
		return version_compare( $update['version'], $this->current_version, '>' ) ? $update : null;
	}

	/**
	 * Whether a package belongs to this plugin.
	 *
	 * @param string              $package    Package URL.
	 * @param array<string,mixed> $hook_extra Upgrade context.
	 */
	private function is_own_package( string $package, array $hook_extra ): bool {
		if ( ( $hook_extra['plugin'] ?? '' ) === $this->plugin_basename ) {
			return true;
		}
		return '' !== $this->server_url && 0 === strpos( $package, $this->server_url . self::DOWNLOAD_ROUTE );
	}

	/**
	 * HTTPS, standard port, no credentials, host on the allow-list.
	 *
	 * @param string $url URL.
	 */
	private function is_allowed_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), $this->allowed_hosts, true );
	}

	/**
	 * Scheme and host of a URL.
	 *
	 * @param string $url URL.
	 */
	private function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) ? 'https://' . (string) ( $parts['host'] ?? '' ) : '';
	}

	/**
	 * Remove the temporary file and return an error.
	 *
	 * @param string $file    Temporary file.
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private function fail( string $file, string $code, string $message ): \WP_Error {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
		return new \WP_Error( $code, $message );
	}
}
