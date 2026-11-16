<?php
/**
 * Tells the shop when a new release of the license server plugin is published.
 *
 * @package WPLC
 */

namespace WPLC\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Release notification for the plugin itself (A-42).
 *
 * The plugin header names `Update URI: https://github.com/hastla007/wp-license`,
 * so WordPress asks the filter `update_plugins_github.com` during its regular
 * update check (twice a day, admin or cron). This class answers it from the
 * latest published release of that repository (GitHub API, cached for twelve
 * hours). The answer never contains a package: WordPress shows "a new version
 * is available" with a link to the release, and "automatic update is
 * unavailable". The operator downloads the ZIP of the release, compares its
 * `.sha256`, and installs it by upload (docs/PRODUCTION-OPERATIONS.md). No code
 * is downloaded or installed automatically, so a compromised release page
 * cannot replace the plugin on its own.
 *
 * Off with `define( 'WPLC_DISABLE_UPDATE_CHECK', true );` or the filter
 * `wplc_self_update_check_enabled`. The release address can be changed with
 * `wplc_self_update_release_api` (a fork, a mirror).
 */
final class SelfUpdateCheck {

	/** GitHub API address of the latest published release (drafts and pre-releases are not returned). */
	public const RELEASE_API = 'https://api.github.com/repos/hastla007/wp-license/releases/latest';

	/** Site transient with the last answer. */
	public const TRANSIENT = 'wplc_self_update';

	/** Seconds an answer is reused. */
	public const CACHE_SECONDS = 43200;

	/** Seconds a failed check is not repeated. */
	public const ERROR_CACHE_SECONDS = 3600;

	/**
	 * Register the update filter.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'filter_update' ), 10, 3 );
	}

	/**
	 * Whether the check runs.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		// E8b: when updates come from the vendor's license server, GitHub is not asked.
		if ( SelfLicense::active() ) {
			return false;
		}
		$enabled = ! ( defined( 'WPLC_DISABLE_UPDATE_CHECK' ) && constant( 'WPLC_DISABLE_UPDATE_CHECK' ) );
		/**
		 * Filters whether the plugin looks for new releases of itself (A-42).
		 *
		 * @param bool $enabled Default: true unless WPLC_DISABLE_UPDATE_CHECK is true.
		 */
		return (bool) apply_filters( 'wplc_self_update_check_enabled', $enabled );
	}

	/**
	 * Answer WordPress' update check for this plugin.
	 *
	 * @param array<string,mixed>|false $update      Answer of an earlier callback, or false.
	 * @param array<string,mixed>       $plugin_data Plugin header data.
	 * @param string                    $plugin_file Plugin file relative to the plugins directory.
	 * @return array<string,mixed>|false
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file ) {
		if ( ! defined( 'WPLC_FILE' ) || plugin_basename( WPLC_FILE ) !== $plugin_file || ! self::enabled() ) {
			return $update;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $update;
		}

		return self::update_row( $release, defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : '0', (string) $plugin_file );
	}

	/**
	 * The row WordPress stores in the update transient. Pure.
	 *
	 * @param array{version:string,url:string} $release   Latest release.
	 * @param string                           $installed Installed version.
	 * @param string                           $plugin_file Plugin basename.
	 * @return array<string,mixed>
	 */
	public static function update_row( array $release, string $installed, string $plugin_file ): array {
		$newer = version_compare( $release['version'], $installed, '>' );

		return array(
			'id'      => 'https://github.com/hastla007/wp-license',
			'slug'    => dirname( $plugin_file ),
			'plugin'  => $plugin_file,
			'version' => $newer ? $release['version'] : $installed,
			'url'     => $release['url'],
			// Never a package: the operator installs a verified ZIP by hand.
			'package' => '',
		);
	}

	/**
	 * Latest release, from the cache or the API.
	 *
	 * @param bool $fetch Ask the API when the cache is empty or old; false reads the cache only.
	 * @return array{version:string,url:string}|null
	 */
	public static function latest( bool $fetch = true ): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return is_array( $cached['release'] ) ? $cached['release'] : null;
		}
		if ( ! $fetch ) {
			return null;
		}
		/**
		 * Filters the API address of the latest release (A-42).
		 *
		 * @param string $url GitHub API address.
		 */
		$url      = (string) apply_filters( 'wplc_self_update_release_api', self::RELEASE_API );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
				'user-agent' => 'wp-license-captain',
			)
		);
		$release  = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$release = is_array( $data ) ? self::parse_release( $data ) : null;
		}
		set_site_transient(
			self::TRANSIENT,
			array(
				'release'    => $release,
				'checked_at' => time(),
			),
			null === $release ? self::ERROR_CACHE_SECONDS : self::CACHE_SECONDS
		);

		return $release;
	}

	/**
	 * Read version and link from a GitHub release. Pure.
	 *
	 * @param array<string,mixed> $data Decoded API answer.
	 * @return array{version:string,url:string}|null Null for drafts, pre-releases and anything unexpected.
	 */
	public static function parse_release( array $data ): ?array {
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $version ) ) {
			return null;
		}
		$url = (string) ( $data['html_url'] ?? '' );
		if ( 0 !== strpos( $url, 'https://github.com/' ) || preg_match( '/[\s"<>]/', $url ) ) {
			$url = 'https://github.com/hastla007/wp-license/releases';
		}

		return array(
			'version' => $version,
			'url'     => $url,
		);
	}
}
