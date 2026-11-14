<?php
/**
 * The bundled updater (src/Client/Updater.php) with a real release ZIP, the real update feed and the real
 * WordPress upgrader.
 *
 * See ClientServerBridge.php for what is real here and what the bridge stands in for.
 * Every test names the task it belongs to (docs/TODO.md).
 *
 * @package WPLC
 */

use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;
use WPLC\Update\ReleaseRegistry;

require_once __DIR__ . '/ClientServerBridge.php';

final class ClientUpdaterIntegrationTest extends WPLC_Client_Bridge_TestCase {

	private const PRODUCT = 'wplc-client-it';
	private const PLUGIN  = 'wplc-client-it/wplc-client-it.php';

	/**
	 * Private package directory of the server in this test.
	 *
	 * @var string
	 */
	private $storage_dir = '';

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Local clock of the customer site.
	 *
	 * @var int
	 */
	private $clock = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$this->clock       = time();
		$this->storage_dir = sys_get_temp_dir() . '/wplc-client-it-' . bin2hex( random_bytes( 6 ) );
		$dir               = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->tables()['products'],
			array(
				'product_key'  => self::PRODUCT,
				'display_name' => 'Client IT Add-on',
				'active'       => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$this->remove_plugin();
	}

	public function tear_down(): void {
		$this->remove_plugin();
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( (array) glob( $this->storage_dir . '/{,.}[!.]*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->storage_dir ) ) {
			rmdir( $this->storage_dir );
		}
		delete_site_transient( 'update_plugins' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Fixtures.
	// ------------------------------------------------------------------

	/**
	 * Main file of the add-on with the given version.
	 *
	 * @param string $version Version.
	 * @param string $name    Plugin name.
	 * @return string
	 */
	private function plugin_source( string $version, string $name = 'Client IT Add-on' ): string {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n * Requires at least: 6.5\n * Requires PHP: 7.4\n * Update URI: https://license.test/wplc-client-it\n */\n";
	}

	/**
	 * Install the add-on in the plugins directory of the test site (not activated).
	 *
	 * @param string $version Installed version.
	 * @return void
	 */
	private function install_plugin( string $version ): void {
		wp_mkdir_p( WP_PLUGIN_DIR . '/wplc-client-it' );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::PLUGIN, $this->plugin_source( $version ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
	}

	/**
	 * Remove the add-on from the plugins directory.
	 *
	 * @return void
	 */
	private function remove_plugin(): void {
		foreach ( array( 'wplc-client-it', 'wplc-client-it-wrong' ) as $folder ) {
			foreach ( (array) glob( WP_PLUGIN_DIR . '/' . $folder . '/*' ) as $file ) {
				unlink( $file );
			}
			if ( is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) {
				rmdir( WP_PLUGIN_DIR . '/' . $folder );
			}
		}
		wp_clean_plugins_cache( false );
	}

	/**
	 * Version in the header of the installed add-on.
	 *
	 * @return string
	 */
	private function installed_version(): string {
		wp_clean_plugins_cache( false );
		clearstatcache();
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( WP_PLUGIN_DIR . '/' . self::PLUGIN, true );
		}

		return (string) get_file_data( WP_PLUGIN_DIR . '/' . self::PLUGIN, array( 'Version' => 'Version' ) )['Version'];
	}

	/**
	 * Publish a release with a real ZIP in the local package storage.
	 *
	 * @param string              $version Version.
	 * @param array<string,mixed> $input   Additional release fields.
	 * @return int Release ID.
	 */
	private function publish( string $version, array $input = array() ): int {
		$path          = sys_get_temp_dir() . '/wplc-client-it-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( self::PLUGIN, $this->plugin_source( $version ) );
		$zip->addFromString( 'wplc-client-it/readme.txt', 'Stable tag: ' . $version . "\n" . str_repeat( 'payload ', 400 ) );
		$zip->close();

		$id = ReleaseRegistry::create_with_package(
			array_merge(
				array(
					'product_key'   => self::PRODUCT,
					'version'       => $version,
					'tested_wp'     => '6.8',
					'release_notes' => '<p>Notes of ' . $version . '</p>',
				),
				$input
			),
			$path
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_code() . ': ' . $id->get_error_message() : '' );
		$published = ReleaseRegistry::publish( $id, 0, 'test' );
		$this->assertTrue( $published, is_wp_error( $published ) ? $published->get_error_code() . ': ' . $published->get_error_message() : '' );

		return $id;
	}

	/**
	 * Manager of the installed add-on, registered like in its main file.
	 *
	 * @param string              $version Installed version.
	 * @param array<string,mixed> $config  Overrides.
	 * @return LicenseManager
	 */
	private function manager( string $version = '1.0.0', array $config = array() ): LicenseManager {
		return new LicenseManager(
			array_merge(
				array(
					'plugin_file' => WP_PLUGIN_DIR . '/' . self::PLUGIN,
					'product_key' => self::PRODUCT,
					'version'     => $version,
					'name'        => 'Client IT Add-on',
					'server_url'  => 'https://license.test',
					'public_keys' => array( $this->server_public_key() ),
					'clock'       => function () {
						return $this->clock;
					},
				),
				$config
			)
		);
	}

	/**
	 * An installed, registered and activated add-on.
	 *
	 * @param array<string,mixed> $license_overrides License columns.
	 * @return array{manager:LicenseManager,license:array<string,mixed>}
	 */
	private function addon( array $license_overrides = array() ): array {
		$this->install_plugin( '1.0.0' );
		$license = $this->seed_license( array_merge( array( 'product_key' => self::PRODUCT ), $license_overrides ) );
		$manager = $this->manager();
		$manager->register();
		$status = $manager->activate( $license['key'] );
		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->http_log = array();

		return array(
			'manager' => $manager,
			'license' => $license,
		);
	}

	/**
	 * Number of download grants on the server.
	 *
	 * @return int
	 */
	private function grants(): int {
		return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['download_tokens']}" );
	}

	/**
	 * Run the WordPress plugin upgrader for the add-on.
	 *
	 * @return array{result:mixed,messages:string[]}
	 */
	private function upgrade(): array {
		// Plugin_Upgrader::upgrade() returns null for a failed download; the reply of the download hook says why.
		$reply   = null;
		$capture = static function ( $value ) use ( &$reply ) {
			$reply = $value;
			return $value;
		};
		add_filter( 'upgrader_pre_download', $capture, 999 );
		$level    = ob_get_level();
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( self::PLUGIN );
		// Automatic_Upgrader_Skin opens a second buffer when it reports an error and never closes it.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		remove_filter( 'upgrader_pre_download', $capture, 999 );
		if ( is_wp_error( $reply ) ) {
			$result = $reply;
		}
		$messages = array();
		foreach ( $skin->get_upgrade_messages() as $message ) {
			$messages[] = is_wp_error( $message ) ? $message->get_error_code() : wp_strip_all_tags( (string) $message );
		}

		return array(
			'result'   => is_wp_error( $skin->result ) ? $skin->result : $result,
			'messages' => $messages,
		);
	}

	// ------------------------------------------------------------------
	// B-52, B-55, B-56, A-41: the whole way of an update.
	// ------------------------------------------------------------------

	public function test_b52_update_is_announced_downloaded_verified_and_installed_by_wordpress(): void {
		$addon   = $this->addon();
		$manager = $addon['manager'];
		$updater = $manager->updater();
		$this->publish( '1.1.0', array( 'requires_php' => '7.4' ) );
		$this->publish( '1.2.0' );

		$this->assertSame( 'license.test', $updater->update_host() );
		$this->assertNotFalse( has_filter( 'update_plugins_license.test' ), 'B-56: hooked through the Update URI host.' );

		// 1. WordPress looks for updates.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$transient = get_site_transient( 'update_plugins' );
		$this->assertArrayHasKey( self::PLUGIN, (array) $transient->response );
		$item = $transient->response[ self::PLUGIN ];
		$this->assertSame( '1.2.0', $item->new_version );
		$this->assertSame( 'wplc-client-it', $item->slug );
		$this->assertSame( self::PLUGIN, $item->plugin );
		$this->assertSame( 'https://license.test/wplc-client-it', $item->id );
		$this->assertSame( '6.8', $item->tested );
		$this->assertSame( '6.5', $item->requires );
		$this->assertSame( '7.4', $item->requires_php );
		$this->assertSame( $updater->placeholder(), $item->package, 'A stable placeholder, not a ten-minute link.' );
		$this->assertStringNotContainsString( 'wplc/v1', $item->package );
		$checks = array_values( array_filter( $this->http_routes(), static function ( $r ) { return 0 === strpos( $r, 'POST /wplc/v1/updates/check' ); } ) );
		$this->assertCount( 1, $checks, 'B-55: one request although WordPress runs the filter several times.' );
		$this->assertFalse( $this->http_log[0]['body']['download'], 'The periodic check asks for metadata only.' );
		$this->assertSame( 'stable', $this->http_log[0]['body']['channel'] );
		$this->assertSame( PHP_VERSION, $this->http_log[0]['body']['php_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $this->http_log[0]['body']['wp_version'] );
		$this->assertSame( 0, $this->grants(), 'No download grant for a periodic check.' );

		// 2. A second look inside twelve hours costs no request.
		$this->http_log = array();
		wp_update_plugins();
		$this->assertSame( array(), $this->http_routes() );

		// 3. "View details".
		$info = plugins_api( 'plugin_information', array( 'slug' => 'wplc-client-it' ) );
		$this->assertIsObject( $info );
		$this->assertSame( 'Client IT Add-on', $info->name );
		$this->assertSame( '1.2.0', $info->version );
		$this->assertSame( '6.8', $info->tested );
		$this->assertStringContainsString( 'Notes of 1.2.0', $info->sections['changelog'] );
		$this->assertStringContainsString( 'Notes of 1.1.0', $info->sections['changelog'], 'The versions in between are part of the changelog.' );
		$this->assertSame( array(), $this->http_routes(), 'Served from the cached answer.' );

		// 4. The administrator clicks "Update now".
		$outcome = $this->upgrade();

		$this->assertTrue( $outcome['result'], is_wp_error( $outcome['result'] ) ? $outcome['result']->get_error_code() . ': ' . $outcome['result']->get_error_message() : wp_json_encode( $outcome ) );
		$this->assertSame( '1.2.0', $this->installed_version() );
		$this->assertSame(
			array(
				'POST /wplc/v1/updates/check 200',
				'GET /wplc/v1/updates/download/{token} 302',
				'GET /wplc/v1/packages/{token} 200',
			),
			$this->http_routes()
		);
		$this->assertTrue( $this->http_log[0]['body']['download'], 'A fresh grant is requested right before the download.' );
		$this->assertSame( 1, $this->grants() );
		$this->assertFalse( get_option( 'wplc_client_it_update_meta', false ), 'upgrader_process_complete forgets the cached feed.' );

		// 5. The updated add-on (now 1.2.0) is told that it is up to date; WordPress lists it under no_update.
		$this->http_log = array();
		$current        = $this->manager( '1.2.0' );
		remove_all_filters( 'update_plugins_license.test' );
		$current->updater()->register();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$transient = get_site_transient( 'update_plugins' );
		$this->assertArrayNotHasKey( self::PLUGIN, (array) $transient->response );
		$this->assertArrayHasKey( self::PLUGIN, (array) $transient->no_update, 'Needed for the auto-update switch in the plugin list.' );
		$this->assertSame( '', $transient->no_update[ self::PLUGIN ]->package );
	}

	public function test_a41_package_replaced_on_the_server_is_not_installed(): void {
		$addon   = $this->addon();
		$updater = $addon['manager']->updater();
		$id      = $this->publish( '1.1.0' );
		wp_update_plugins();

		// The stored package is replaced after it was published (compromised storage, CDN poisoning).
		$key  = (string) ReleaseRegistry::get( $id )['package_url'];
		$path = \WPLC\Update\LocalPackageStorage::path_for_key( $key );
		$this->assertIsString( $path );
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( self::PLUGIN, $this->plugin_source( '1.1.0', 'Evil twin' ) );
		$zip->close();

		$outcome = $this->upgrade();

		$this->assertWPError( $outcome['result'] );
		$this->assertSame( 'wplc_update_checksum', $outcome['result']->get_error_code(), 'The client compares the bytes it received with the sum the server signed at publish time.' );
		$this->assertSame( '1.0.0', $this->installed_version(), 'Nothing was installed.' );
		$this->assertStringNotContainsString( 'Evil twin', (string) file_get_contents( WP_PLUGIN_DIR . '/' . self::PLUGIN ) );
	}

	public function test_a41_tampered_download_is_rejected_by_the_sha256_of_the_signed_answer(): void {
		$addon   = $this->addon();
		$updater = $addon['manager']->updater();
		$this->publish( '1.1.0' );

		// The network between server and site delivers other bytes than the server signed.
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( empty( $args['filename'] ) ) {
					return $pre;
				}
				file_put_contents( $args['filename'], 'PK-not-the-signed-package' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$answer             = $this->http_response( 200, '', array( 'content-type' => 'application/zip' ) );
				$answer['filename'] = $args['filename'];
				return $answer;
			},
			-1,
			3
		);
		$before = (array) glob( get_temp_dir() . '*' );

		$result = $updater->filter_pre_download( false, $updater->placeholder(), null );

		$this->assertWPError( $result );
		$this->assertSame( 'wplc_update_checksum', $result->get_error_code() );
		$this->assertSame( array(), array_diff( (array) glob( get_temp_dir() . '*' ), $before ), 'The rejected download is removed.' );

		// Other packages are none of our business.
		$this->assertFalse( $updater->filter_pre_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null ) );
		$this->assertSame( 'kept', $updater->filter_pre_download( 'kept', 'https://example.com/x.zip', null ) );
	}

	// ------------------------------------------------------------------
	// B-140: license without access.
	// ------------------------------------------------------------------

	public function test_b140_expired_license_sees_the_version_but_gets_no_package(): void {
		global $wpdb;
		$addon   = $this->addon();
		$manager = $addon['manager'];
		$updater = $manager->updater();
		$this->publish( '1.1.0' );

		// The update is announced while the license is valid...
		wp_update_plugins();
		$this->assertSame( $updater->placeholder(), get_site_transient( 'update_plugins' )->response[ self::PLUGIN ]->package );
		$this->assertSame( '', $updater->update_hint() );

		// ...then the license expires and the administrator clicks "Update now".
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			),
			array( 'id' => $addon['license']['id'] )
		);
		$outcome = $this->upgrade();
		$this->assertWPError( $outcome['result'] );
		$this->assertSame( 'wplc_update_license', $outcome['result']->get_error_code() );
		$this->assertStringContainsString( 'expired', $outcome['result']->get_error_message() );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertSame( 0, $this->grants() );

		// The next look for updates still shows the version, without a package, and explains why.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$item = get_site_transient( 'update_plugins' )->response[ self::PLUGIN ];
		$this->assertSame( '1.1.0', $item->new_version );
		$this->assertSame( '', $item->package );
		$this->assertStringContainsString( 'Renew it to receive this update', $updater->update_hint() );
		ob_start();
		do_action( 'in_plugin_update_message-' . self::PLUGIN, array(), $item ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$row = ob_get_clean();
		$this->assertStringContainsString( 'Renew it to receive this update', $row );
		$this->assertStringContainsString( 'page=wplc-client-it-license', $row );
		$this->assertSame( '', plugins_api( 'plugin_information', array( 'slug' => 'wplc-client-it' ) )->download_link );
	}

	// ------------------------------------------------------------------
	// B-55: caching, failures, copies.
	// ------------------------------------------------------------------

	public function test_b55_feed_is_cached_twelve_hours_and_failures_one_hour(): void {
		$addon   = $this->addon();
		$updater = $addon['manager']->updater();
		$this->publish( '1.1.0' );

		$answered = function (): int {
			// A request whose injected clock is hours ahead is first refused (401 request_expired) and repeated; count answers.
			return count( array_filter( array_column( $this->http_log, 'status' ), static function ( $status ) { return 200 === $status; } ) );
		};
		$this->assertSame( '1.1.0', $updater->metadata()['version'] );
		$this->assertSame( 1, $answered() );
		$this->assertArrayNotHasKey( 'package', $updater->metadata() );
		$this->assertArrayNotHasKey( 'package_url', $updater->metadata() );

		$this->publish( '1.2.0' );
		$this->clock += 12 * HOUR_IN_SECONDS - 1;
		$this->assertSame( '1.1.0', $updater->metadata()['version'] );
		$this->assertSame( 1, $answered() );
		$this->assertSame( '1.2.0', $updater->metadata( true )['version'], 'A forced check ("Check again") skips the cache.' );
		$this->assertSame( 2, $answered() );
		$updater->flush();
		$this->publish( '1.3.0' );
		$this->assertSame( '1.3.0', $updater->metadata()['version'] );
		$this->publish( '1.4.0' );
		$this->clock += 12 * HOUR_IN_SECONDS;
		$this->assertSame( '1.4.0', $updater->metadata()['version'], 'After twelve hours the feed is asked again.' );

		// The server is down: one request, then silence for an hour. WordPress keeps what it knows.
		$updater->flush();
		$this->http_log      = array();
		$this->http_faults[] = new WP_Error( 'http_request_failed', 'cURL error 28' );
		$this->assertSame( array( 'error' => 'wplc_connection_failed' ), $updater->metadata() );
		$this->assertSame( array( 'error' => 'wplc_connection_failed' ), $updater->metadata() );
		$this->assertFalse( $updater->filter_update( false, array(), self::PLUGIN ) );
		$this->assertCount( 1, $this->http_log );
		$this->clock += HOUR_IN_SECONDS;
		$this->assertSame( '1.4.0', $updater->metadata()['version'] );

		// Another plugin with the same Update URI host is not answered.
		$this->assertSame( 'untouched', $updater->filter_update( 'untouched', array(), 'other/other.php' ) );
		$this->assertSame( 'untouched', $updater->filter_plugin_information( 'untouched', 'plugin_information', (object) array( 'slug' => 'other' ) ) );
		$this->assertSame( 'untouched', $updater->filter_plugin_information( 'untouched', 'query_plugins', (object) array( 'slug' => 'wplc-client-it' ) ) );
	}

	public function test_updater_is_silent_without_activation_and_on_a_copy_of_the_site(): void {
		$this->install_plugin( '1.0.0' );
		$manager = $this->manager();
		$manager->register();
		$updater = $manager->updater();
		$this->publish( '1.1.0' );

		$this->assertFalse( $updater->filter_update( false, array(), self::PLUGIN ) );
		$this->assertSame( array(), $this->http_log, 'No request without an activation.' );
		$this->assertStringContainsString( 'Enter your license key', $updater->update_hint() );

		$license = $this->seed_license( array( 'product_key' => self::PRODUCT ) );
		$manager->activate( $license['key'] );
		$this->http_log    = array();
		$this->acting_home = 'https://staging.example.org';
		$this->assertFalse( $updater->filter_update( false, array(), self::PLUGIN ) );
		$result = $updater->filter_pre_download( false, $updater->placeholder(), null );
		$this->assertWPError( $result );
		$this->assertSame( array(), $this->http_log, 'B-69: a copy does not use the copied credentials for updates either.' );
	}

	public function test_b56_missing_update_uri_header_is_reported_and_no_hook_is_added(): void {
		wp_mkdir_p( WP_PLUGIN_DIR . '/wplc-client-it' );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::PLUGIN, "<?php\n/**\n * Plugin Name: Client IT Add-on\n * Version: 1.0.0\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$manager = $this->manager();
		$updater = $manager->updater();

		$this->assertSame( '', $updater->update_host() );
		$this->assertFalse( $updater->register() );
		$this->assertFalse( has_filter( 'upgrader_pre_download', array( $updater, 'filter_pre_download' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$updater->render_missing_header_notice();
		$this->assertStringContainsString( 'Update URI', (string) ob_get_clean() );

		// A header that points to wordpress.org would hand the add-on to the public directory.
		$this->assertSame( '', $this->manager( '1.0.0', array( 'update_uri' => 'https://wordpress.org/plugins/wplc-client-it/' ) )->updater()->update_host() );
		$this->assertSame( 'license.test', $this->manager( '1.0.0', array( 'update_uri' => 'https://license.test/x' ) )->updater()->update_host() );
	}

	// ------------------------------------------------------------------
	// B-53 (client side): the unpacked folder.
	// ------------------------------------------------------------------

	public function test_source_selection_keeps_the_addon_in_its_folder(): void {
		global $wp_filesystem;
		$this->install_plugin( '1.0.0' );
		$updater = $this->manager()->updater();
		WP_Filesystem();
		$this->assertIsObject( $wp_filesystem );
		$work          = trailingslashit( get_temp_dir() ) . 'wplc-client-it-src-' . bin2hex( random_bytes( 4 ) );
		$extra         = array( 'plugin' => self::PLUGIN );
		$this->files[] = $work . '/wplc-client-it/wplc-client-it.php';
		$this->files[] = $work . '/other-plugin/other-plugin.php';

		// Right folder: untouched.
		wp_mkdir_p( $work . '/wplc-client-it' );
		file_put_contents( $work . '/wplc-client-it/wplc-client-it.php', $this->plugin_source( '1.1.0' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( $work . '/wplc-client-it/', $updater->filter_source_selection( $work . '/wplc-client-it/', $work, null, $extra ) );

		// Another folder name with the right plugin inside (a ZIP built by hand): moved to the right folder.
		rename( $work . '/wplc-client-it', $work . '/wplc-client-it-1.1.0' );
		$this->assertSame( $work . '/wplc-client-it/', $updater->filter_source_selection( $work . '/wplc-client-it-1.1.0/', $work, null, $extra ) );
		$this->assertFileExists( $work . '/wplc-client-it/wplc-client-it.php' );

		// A package that does not contain this add-on is refused.
		wp_mkdir_p( $work . '/other-plugin' );
		file_put_contents( $work . '/other-plugin/other-plugin.php', "<?php\n// Plugin Name: Other\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = $updater->filter_source_selection( $work . '/other-plugin/', $work, null, $extra );
		$this->assertWPError( $result );
		$this->assertSame( 'wplc_update_folder', $result->get_error_code() );

		// Upgrades of other plugins and earlier errors pass through.
		$this->assertSame( $work . '/other-plugin/', $updater->filter_source_selection( $work . '/other-plugin/', $work, null, array( 'plugin' => 'other-plugin/other-plugin.php' ) ) );
		$error = new WP_Error( 'x', 'x' );
		$this->assertSame( $error, $updater->filter_source_selection( $error, $work, null, $extra ) );

		unlink( $work . '/wplc-client-it/wplc-client-it.php' );
		unlink( $work . '/other-plugin/other-plugin.php' );
		rmdir( $work . '/wplc-client-it' );
		rmdir( $work . '/other-plugin' );
		rmdir( $work );
	}

	public function test_requirements_that_block_an_update_are_explained(): void {
		$addon   = $this->addon();
		$updater = $addon['manager']->updater();
		$this->publish( '1.1.0' );
		$this->publish( '2.0.0', array( 'requires_php' => '99.0' ) );

		$meta = $updater->metadata();

		$this->assertSame( '1.1.0', $meta['version'], 'The highest version this site can run.' );
		$this->assertSame( '2.0.0', $meta['blocked']['version'] );
		$hint = $updater->update_hint();
		$this->assertStringContainsString( '2.0.0', $hint );
		$this->assertStringContainsString( 'PHP 99.0', $hint );
	}
}
