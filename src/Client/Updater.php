<?php
/**
 * WordPress updater of a premium add-on.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Updater', false ) ) {

	/**
	 * Connects the update feed of the license server (docs/UPDATES.md) with the WordPress update system.
	 *
	 * - The add-on must carry an `Update URI` header whose host is not
	 *   wordpress.org. WordPress then asks the filter `update_plugins_{host}`
	 *   and never offers a plugin of the same name from wordpress.org.
	 * - The periodic check sends `download: false` and is cached: twelve hours
	 *   after a success, one hour after a failure.
	 * - The `package` given to WordPress is a stable placeholder. Right before
	 *   the download a fresh, ten-minute link is requested, the ZIP is
	 *   downloaded and its SHA-256 is compared with the signed value.
	 * - The download only follows `https://` addresses (plain `http://` only
	 *   for localhost), also on every redirect, and stops at
	 *   MAX_PACKAGE_BYTES (filter `{prefix}_license_max_package_bytes`).
	 */
	final class Updater {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.0.1';

		/**
		 * Seconds a successful check is cached.
		 */
		const CACHE_SUCCESS = 43200;

		/**
		 * Seconds a failed check is cached.
		 */
		const CACHE_FAILURE = 3600;

		/**
		 * Default size limit of an update package in bytes (100 MB).
		 */
		const MAX_PACKAGE_BYTES = 104857600;

		/**
		 * Redirects followed by the package download (the one-time link redirects to the storage).
		 */
		const MAX_REDIRECTS = 5;

		/**
		 * License manager of the add-on.
		 *
		 * @var LicenseManager
		 */
		private $manager;

		/**
		 * Constructor.
		 *
		 * @param LicenseManager $manager License manager of the add-on.
		 */
		public function __construct( LicenseManager $manager ) {
			$this->manager = $manager;
		}

		/**
		 * Register the update hooks. Called by LicenseManager::register().
		 *
		 * @return bool False when the add-on has no usable `Update URI` header.
		 */
		public function register(): bool {
			$host = $this->update_host();
			if ( '' === $host ) {
				add_action( 'admin_notices', array( $this, 'render_missing_header_notice' ) );
				return false;
			}
			add_filter( 'update_plugins_' . $host, array( $this, 'filter_update' ), 10, 3 );
			add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
			add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
			add_action( 'in_plugin_update_message-' . $this->basename(), array( $this, 'render_update_message' ), 10, 0 );

			return true;
		}

		/**
		 * Plugin file relative to the plugins directory, e.g. `voucher-captain-pro/voucher-captain-pro.php`.
		 *
		 * @return string
		 */
		public function basename(): string {
			return plugin_basename( (string) $this->manager->config( 'plugin_file' ) );
		}

		/**
		 * Folder of the add-on inside the plugins directory.
		 *
		 * @return string
		 */
		public function slug(): string {
			$folder = dirname( $this->basename() );

			return '.' === $folder ? basename( $this->basename(), '.php' ) : $folder;
		}

		/**
		 * Value of the `Update URI` header.
		 *
		 * @return string
		 */
		public function update_uri(): string {
			$uri = (string) $this->manager->config( 'update_uri' );
			if ( '' === $uri && '' !== (string) $this->manager->config( 'plugin_file' ) && function_exists( 'get_file_data' ) ) {
				$headers = get_file_data( (string) $this->manager->config( 'plugin_file' ), array( 'UpdateURI' => 'Update URI' ), 'plugin' );
				$uri     = (string) ( $headers['UpdateURI'] ?? '' );
			}

			return trim( $uri );
		}

		/**
		 * Host of the `Update URI` header; empty when the header is missing or points to wordpress.org.
		 *
		 * @return string
		 */
		public function update_host(): string {
			$host = strtolower( (string) wp_parse_url( $this->update_uri(), PHP_URL_HOST ) );

			return in_array( $host, array( 'w.org', 'wordpress.org' ), true ) || '.wordpress.org' === substr( $host, -14 ) ? '' : $host;
		}

		/**
		 * Stable placeholder that stands for "the newest package" in the update transient.
		 *
		 * @return string
		 */
		public function placeholder(): string {
			return 'https://' . $this->update_host() . '/?wplc-package=' . rawurlencode( $this->manager->client()->product_key() );
		}

		/**
		 * Cached update data of the server.
		 *
		 * @param bool $fresh Ignore the cache.
		 * @return array<string,mixed> Signed data of `POST /updates/check`, or `array( 'error' => code )` after a failure.
		 */
		public function metadata( bool $fresh = false ): array {
			$client = $this->manager->client();
			if ( ! $client->is_activated() || ! $client->site_matches() ) {
				return array( 'error' => 'wplc_not_activated' );
			}
			$now    = $client->now();
			$cached = $client->store()->get( $this->cache_option(), array() );
			if ( ! $fresh
				&& is_array( $cached )
				&& isset( $cached['data'], $cached['expires'], $cached['version'] )
				&& $cached['version'] === $client->product_version()
				&& $now < (int) $cached['expires']
				&& $now >= (int) $cached['expires'] - self::CACHE_SUCCESS
			) {
				return (array) $cached['data'];
			}

			$data = $client->check_update( false, $this->request_fields() );
			if ( is_wp_error( $data ) ) {
				$data = array( 'error' => (string) $data->get_error_code() );
				$ttl  = self::CACHE_FAILURE;
			} else {
				// A download link is never part of the periodic answer; make sure none is kept.
				unset( $data['package'], $data['package_url'], $data['download_expires_at'] );
				$ttl = self::CACHE_SUCCESS;
			}
			$client->store()->set(
				$this->cache_option(),
				array(
					'data'    => $data,
					'expires' => $now + $ttl,
					'version' => $client->product_version(),
				)
			);

			return $data;
		}

		/**
		 * Forget the cached update data (after an update, an activation, a deactivation).
		 *
		 * @return void
		 */
		public function flush(): void {
			$this->manager->client()->store()->delete( $this->cache_option() );
		}

		/**
		 * Filter `update_plugins_{host}`: tell WordPress about the newest version.
		 *
		 * @param mixed  $update      Update data so far.
		 * @param mixed  $plugin_data Plugin headers.
		 * @param string $plugin_file Plugin file relative to the plugins directory.
		 * @return mixed
		 */
		public function filter_update( $update, $plugin_data, $plugin_file ) {
			if ( $plugin_file !== $this->basename() ) {
				return $update;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- "Check again" of WordPress; only bypasses a cache.
			$fresh = is_admin() && ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
			$meta  = $this->metadata( $fresh );
			if ( isset( $meta['error'] ) || empty( $meta['version'] ) ) {
				return $update;
			}
			$installed = $this->manager->client()->product_version();
			$newer     = ! empty( $meta['update_available'] ) && version_compare( (string) $meta['version'], $installed, '>' );
			$item      = array(
				'id'           => $this->update_uri(),
				'slug'         => $this->slug(),
				'plugin'       => $this->basename(),
				'version'      => $newer ? (string) $meta['version'] : $installed,
				'url'          => (string) ( $meta['homepage'] ?? '' ),
				// Empty without access: WordPress then shows "automatic update is unavailable" plus our hint.
				'package'      => $newer && ! empty( $meta['license_active'] ) ? $this->placeholder() : '',
				'tested'       => (string) ( $meta['tested'] ?? ( $meta['tested_wp'] ?? '' ) ),
				'requires'     => (string) ( $meta['requires'] ?? ( $meta['requires_wp'] ?? '' ) ),
				'requires_php' => (string) ( $meta['requires_php'] ?? '' ),
				'icons'        => isset( $meta['icons'] ) && is_array( $meta['icons'] ) ? $meta['icons'] : array(),
			);

			return $item;
		}

		/**
		 * Filter `plugins_api`: the "View details" window.
		 *
		 * @param mixed  $result Result so far.
		 * @param string $action Requested action.
		 * @param mixed  $args   Arguments.
		 * @return mixed
		 */
		public function filter_plugin_information( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->slug() ) {
				return $result;
			}
			$meta = $this->metadata();
			if ( isset( $meta['error'] ) || empty( $meta['version'] ) ) {
				return $result;
			}
			$sections = array();
			foreach ( isset( $meta['sections'] ) && is_array( $meta['sections'] ) ? $meta['sections'] : array() as $name => $html ) {
				$sections[ sanitize_key( (string) $name ) ] = wp_kses_post( (string) $html );
			}
			if ( empty( $sections ) ) {
				$sections['changelog'] = wp_kses_post( (string) ( $meta['release_notes'] ?? '' ) );
			}

			return (object) array(
				'name'          => (string) ( $meta['name'] ?? $this->manager->config( 'name' ) ),
				'slug'          => $this->slug(),
				'version'       => (string) $meta['version'],
				'homepage'      => (string) ( $meta['homepage'] ?? '' ),
				'last_updated'  => (string) ( $meta['last_updated'] ?? '' ),
				'requires'      => (string) ( $meta['requires'] ?? ( $meta['requires_wp'] ?? '' ) ),
				'tested'        => (string) ( $meta['tested'] ?? ( $meta['tested_wp'] ?? '' ) ),
				'requires_php'  => (string) ( $meta['requires_php'] ?? '' ),
				'sections'      => $sections,
				'icons'         => isset( $meta['icons'] ) && is_array( $meta['icons'] ) ? $meta['icons'] : array(),
				'download_link' => ! empty( $meta['license_active'] ) ? $this->placeholder() : '',
				'external'      => true,
			);
		}

		/**
		 * Filter `upgrader_pre_download`: fetch a fresh link, download, verify the SHA-256.
		 *
		 * @param mixed $reply    Reply so far.
		 * @param mixed $package  Package requested by the upgrader.
		 * @param mixed $upgrader Upgrader.
		 * @return mixed Path of the verified ZIP, or a WP_Error.
		 */
		public function filter_pre_download( $reply, $package, $upgrader = null ) {
			if ( ! is_string( $package ) || $package !== $this->placeholder() ) {
				return $reply;
			}
			$messages = $this->manager->client()->messages();
			$update   = $this->manager->client()->check_update( true, $this->request_fields() );
			if ( is_wp_error( $update ) ) {
				return new \WP_Error( 'wplc_update_failed', sprintf( $messages->get( 'update.failed' ), $update->get_error_message() ), $update->get_error_code() );
			}
			if ( array_key_exists( 'license_active', $update ) && empty( $update['license_active'] ) ) {
				$this->flush();
				return new \WP_Error( 'wplc_update_license', $this->reason_text( (string) ( $update['reason'] ?? 'license_inactive' ) ) );
			}
			$url = (string) ( $update['package'] ?? ( $update['package_url'] ?? '' ) );
			$sum = strtolower( (string) ( $update['sha256'] ?? '' ) );
			if ( empty( $update['update_available'] ) || '' === $url || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sum ) ) {
				$this->flush();
				return new \WP_Error( 'wplc_update_unavailable', $messages->get( 'update.unavailable' ) );
			}
			if ( ! self::is_allowed_package_url( $url ) ) {
				return new \WP_Error( 'wplc_update_insecure', $messages->get( 'update.insecure' ) );
			}
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
				$upgrader->skin->feedback( 'downloading_package', $this->manager->client()->server_host() );
			}
			$file = $this->download( $url );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$actual = hash_file( 'sha256', $file );
			if ( ! is_string( $actual ) || ! hash_equals( $sum, $actual ) ) {
				wp_delete_file( $file );
				return new \WP_Error( 'wplc_update_checksum', $messages->get( 'update.checksum' ) );
			}

			return $file;
		}

		/**
		 * Largest package the download accepts, in bytes.
		 *
		 * @return int
		 */
		public function max_package_bytes(): int {
			$max = self::MAX_PACKAGE_BYTES;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filters the size limit of an update package of this add-on, in bytes (default 100 MB).
				 *
				 * @param int $max Limit in bytes.
				 */
				$max = (int) apply_filters( $this->manager->prefix() . '_license_max_package_bytes', $max ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
			}

			return max( 1, $max );
		}

		/**
		 * Whether a package address may be downloaded: `https://`, or `http://` on localhost only.
		 *
		 * @param string $url Address.
		 * @return bool
		 */
		public static function is_allowed_package_url( string $url ): bool {
			$parts = wp_parse_url( trim( $url ) );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			if ( 'https' === $scheme ) {
				return true;
			}
			$host = strtolower( trim( (string) $parts['host'], '[]' ) );

			return 'http' === $scheme && ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || '.localhost' === substr( $host, -10 ) );
		}

		/**
		 * Download a package into a temporary file: HTTPS on every hop, size limited.
		 *
		 * No HEAD request first: pre-signed storage URLs are signed for GET only. The
		 * response is streamed with `limit_response_size`, so a larger file is cut off,
		 * detected and deleted instead of filling the disk.
		 *
		 * @param string $url Package address from the signed update answer.
		 * @return string|\WP_Error Path of the temporary file.
		 */
		private function download( string $url ) {
			$messages  = $this->manager->client()->messages();
			$max       = $this->max_package_bytes();
			$transport = $this->manager->config( 'transport' );
			for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
				if ( ! self::is_allowed_package_url( $url ) ) {
					return new \WP_Error( 'wplc_update_insecure', $messages->get( 'update.insecure' ) );
				}
				$file = wp_tempnam( 'wplc-update' );
				if ( '' === $file ) {
					return new \WP_Error( 'wplc_update_failed', sprintf( $messages->get( 'update.failed' ), 'tmp' ) );
				}
				$args     = array(
					'timeout'             => 300,
					'redirection'         => 0,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $max + 1,
				);
				$response = is_callable( $transport ) ? call_user_func( $transport, $url, $args ) : wp_safe_remote_get( $url, $args );
				if ( is_wp_error( $response ) || ! is_array( $response ) ) {
					wp_delete_file( $file );
					return is_wp_error( $response ) ? $response : new \WP_Error( 'wplc_update_failed', sprintf( $messages->get( 'update.failed' ), 'http' ) );
				}
				$code    = (int) ( $response['response']['code'] ?? 0 );
				$headers = $response['headers'] ?? array();
				if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
					wp_delete_file( $file );
					$location = self::header( $headers, 'location' );
					if ( '' === $location ) {
						return new \WP_Error( 'wplc_update_failed', sprintf( $messages->get( 'update.failed' ), 'HTTP ' . $code ) );
					}
					$url = class_exists( 'WP_Http' ) ? (string) \WP_Http::make_absolute_url( $location, $url ) : $location;
					continue;
				}
				if ( 200 !== $code ) {
					wp_delete_file( $file );
					return new \WP_Error( 'wplc_update_failed', sprintf( $messages->get( 'update.failed' ), 'HTTP ' . $code ) );
				}
				$length = self::header( $headers, 'content-length' );
				clearstatcache( true, $file );
				$size = is_file( $file ) ? (int) filesize( $file ) : 0;
				if ( ( '' !== $length && (int) $length > $max ) || $size > $max ) {
					wp_delete_file( $file );
					return new \WP_Error( 'wplc_update_too_large', sprintf( $messages->get( 'update.too_large' ), (string) max( 1, (int) floor( $max / 1048576 ) ) ) );
				}

				return $file;
			}

			return new \WP_Error( 'wplc_update_failed', $messages->get( 'update.redirects' ) );
		}

		/**
		 * Read one response header from an array or a header object.
		 *
		 * @param mixed  $headers Headers.
		 * @param string $name    Lower-case name.
		 * @return string
		 */
		private static function header( $headers, string $name ): string {
			if ( is_array( $headers ) || $headers instanceof \Traversable ) {
				foreach ( $headers as $key => $value ) {
					if ( strtolower( (string) $key ) === $name ) {
						return is_array( $value ) ? (string) reset( $value ) : (string) $value;
					}
				}
			}
			if ( $headers instanceof \ArrayAccess && isset( $headers[ $name ] ) ) {
				$value = $headers[ $name ];
				return is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}

			return '';
		}

		/**
		 * Filter `upgrader_source_selection`: the unpacked folder must be the folder of this add-on.
		 *
		 * @param mixed $source        Unpacked folder.
		 * @param mixed $remote_source Parent of the unpacked folder.
		 * @param mixed $upgrader      Upgrader.
		 * @param mixed $hook_extra    Context of the upgrade.
		 * @return mixed
		 */
		public function filter_source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
			if ( is_wp_error( $source ) || ! is_string( $source ) || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== $this->basename() ) {
				return $source;
			}
			global $wp_filesystem;
			$expected = $this->slug();
			$main     = basename( $this->basename() );
			if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->exists( trailingslashit( $source ) . $main ) ) {
				// Another plugin in the package would replace or deactivate this add-on.
				return new \WP_Error( 'wplc_update_folder', $this->manager->client()->messages()->get( 'update.folder' ) );
			}
			if ( basename( untrailingslashit( $source ) ) === $expected ) {
				return $source;
			}
			$target = trailingslashit( trailingslashit( dirname( untrailingslashit( $source ) ) ) . $expected );
			if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
				return new \WP_Error( 'wplc_update_folder', $this->manager->client()->messages()->get( 'update.folder' ) );
			}

			return $target;
		}

		/**
		 * Action `in_plugin_update_message-{file}`: why the update cannot be installed.
		 *
		 * @return void
		 */
		public function render_update_message(): void {
			$text = $this->update_hint();
			if ( '' !== $text ) {
				printf( '<br><strong>%s</strong> <a href="%s">%s</a>', esc_html( $text ), esc_url( $this->manager->settings_url() ), esc_html( $this->manager->client()->messages()->get( 'notice.open_settings' ) ) );
			}
		}

		/**
		 * Text that explains why an announced update has no package; empty when it has one.
		 *
		 * @return string
		 */
		public function update_hint(): string {
			$meta = $this->metadata();
			if ( isset( $meta['error'] ) ) {
				return 'wplc_not_activated' === $meta['error'] ? $this->manager->client()->messages()->get( 'update.not_activated' ) : '';
			}
			if ( array_key_exists( 'license_active', $meta ) && empty( $meta['license_active'] ) ) {
				return $this->reason_text( (string) ( $meta['reason'] ?? 'license_inactive' ) );
			}
			if ( isset( $meta['blocked'] ) && is_array( $meta['blocked'] ) ) {
				$messages = $this->manager->client()->messages();
				$unmet    = array();
				foreach ( (array) ( $meta['blocked']['unmet'] ?? array() ) as $requirement ) {
					$text = $messages->get( 'update.' . sanitize_key( (string) $requirement ) );
					if ( '' !== $text ) {
						$unmet[] = sprintf( $text, (string) ( $meta['blocked'][ $requirement ] ?? '' ) );
					}
				}

				return sprintf( $messages->get( 'update.blocked' ), (string) ( $meta['blocked']['version'] ?? '' ), implode( ', ', $unmet ) );
			}

			return '';
		}

		/**
		 * Notice for the developer: the `Update URI` header is missing.
		 *
		 * @return void
		 */
		public function render_missing_header_notice(): void {
			if ( current_user_can( 'update_plugins' ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( $this->manager->client()->messages()->get( 'notice.update_uri_missing' ), (string) $this->manager->config( 'name' ) ) ) );
			}
		}

		/**
		 * Text for a `reason` of the update feed.
		 *
		 * @param string $reason Reason.
		 * @return string
		 */
		private function reason_text( string $reason ): string {
			$messages = $this->manager->client()->messages();
			$text     = $messages->get( 'update.' . sanitize_key( $reason ) );

			return '' === $text ? $messages->get( 'update.license_inactive' ) : $text;
		}

		/**
		 * Optional fields of the update request.
		 *
		 * @return array<string,string>
		 */
		private function request_fields(): array {
			$core = $this->manager->config( 'core_version' );
			if ( ! is_string( $core ) && is_callable( $core ) ) {
				$core = call_user_func( $core );
			}

			return array(
				'channel'      => 'beta' === $this->manager->config( 'channel' ) ? 'beta' : 'stable',
				'core_version' => is_string( $core ) ? $core : '',
			);
		}

		/**
		 * Name of the option that caches the update data.
		 *
		 * @return string
		 */
		private function cache_option(): string {
			return $this->manager->prefix() . '_update_meta';
		}
	}
}
