<?php
/**
 * Release registry, update API, package delivery and the releases screen against a real database.
 *
 * Every test names the task it belongs to (docs/TODO.md, sections 7 and 8).
 *
 * @package WPLC
 */

use WPLC\Admin\ReleaseAdmin;
use WPLC\Api\V1\UpdateService;
use WPLC\CLI\ReleaseCommand;
use WPLC\Update\CustomerDownload;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStream;
use WPLC\Update\ReleaseRegistry;

final class ReleaseUpdateIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Private package directory of this test.
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
	 * Previous value of $_SERVER['HTTPS'].
	 *
	 * @var mixed
	 */
	private $https_before;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		// The update API only delivers HTTPS URLs; the local streaming route is built with rest_url().
		$this->https_before = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS']   = 'on';

		$this->storage_dir = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) );
		$dir               = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		// No test may reach the network.
		add_filter(
			'pre_http_request',
			static function ( $pre ) {
				return false !== $pre ? $pre : new WP_Error( 'http_request_blocked', 'HTTP is blocked in tests.' );
			},
			1
		);

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 'test-product' => 'Test Product', 'other-product' => 'Other Product' ) as $key => $name ) {
			$wpdb->insert(
				$this->tables()['products'],
				array(
					'product_key'  => $key,
					'display_name' => $name,
					'active'       => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
		}
	}

	public function tear_down(): void {
		if ( null === $this->https_before ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_before;
		}
		unset( $_SERVER['CONTENT_LENGTH'], $_SERVER['REQUEST_METHOD'] );
		$_FILES = array();
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
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Build a plugin ZIP.
	 *
	 * @param string               $folder  Top-level folder.
	 * @param string               $version Value of the Version header.
	 * @param array<string,string> $extra   Additional entries (path => content).
	 * @return string Path of the ZIP.
	 */
	private function make_zip( string $folder, string $version, array $extra = array() ): string {
		$path          = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( $folder . '/' . $folder . '.php', "<?php\n/**\n * Plugin Name: Test Product\n * Version: {$version}\n * Requires at least: 6.5\n * Requires PHP: 7.4\n */\n" );
		$zip->addFromString( $folder . '/readme.txt', 'Stable tag: ' . $version . "\n" . str_repeat( 'payload ', 500 ) );
		foreach ( $extra as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();

		return $path;
	}

	/**
	 * Create (and by default publish) a release with an uploaded-style local package.
	 *
	 * @param string              $version Version.
	 * @param array<string,mixed> $input   Additional release fields.
	 * @param bool                $publish Whether to publish.
	 * @return int Release ID.
	 */
	private function release( string $version, array $input = array(), bool $publish = true ): int {
		$product = (string) ( $input['product_key'] ?? 'test-product' );
		$id      = ReleaseRegistry::create_with_package(
			array_merge(
				array(
					'product_key'   => $product,
					'version'       => $version,
					'release_notes' => '<p>Notes of ' . $version . '</p>',
				),
				$input
			),
			$this->make_zip( $product, $version )
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_code() . ': ' . $id->get_error_message() : '' );
		if ( $publish ) {
			$published = ReleaseRegistry::publish( $id, 0, 'test' );
			$this->assertTrue( $published, is_wp_error( $published ) ? $published->get_error_code() . ': ' . $published->get_error_message() : '' );
		}

		return $id;
	}

	/**
	 * A license with an activated installation.
	 *
	 * @param array<string,mixed> $license_overrides License columns.
	 * @return array{license:array<string,mixed>,site:array<string,mixed>}
	 */
	private function installation( array $license_overrides = array() ): array {
		$license = $this->seed_license( $license_overrides );

		return array(
			'license' => $license,
			'site'    => $this->seed_activation( $license['id'] ),
		);
	}

	/**
	 * POST /updates/check and return the signed data.
	 *
	 * @param array<string,mixed> $site   Installation credentials.
	 * @param array<string,mixed> $params Additional body fields.
	 * @return array<string,mixed>
	 */
	private function check( array $site, array $params = array() ): array {
		$body     = array_merge(
			array(
				'product_key'     => 'test-product',
				'current_version' => '1.0.0',
			),
			$params
		);
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', $body, $site ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data()['data'];
	}

	/**
	 * Run a request whose handler ends with a redirect or status header plus exit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WPLC_Test_Halt|WP_REST_Response
	 */
	private function run_request( WP_REST_Request $request ) {
		$this->halt_instead_of_exit();
		try {
			return $this->dispatch( $request );
		} catch ( WPLC_Test_Halt $halt ) {
			return $halt;
		}
	}

	/**
	 * Extract the 64-character token from a download or stream URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function token_of( string $url ): string {
		$this->assertSame( 1, preg_match( '#([a-f0-9]{64})/?$#', rawurldecode( $url ), $match ), 'No token in ' . $url );

		return $match[1];
	}

	/**
	 * Redeem a download grant URL and return the stream token it redirects to.
	 *
	 * @param string $package_url package_url of an update check.
	 * @return string Stream token.
	 */
	private function stream_token_for( string $package_url ): string {
		$halt = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $this->token_of( $package_url ) ) );
		$this->assertInstanceOf( WPLC_Test_Halt::class, $halt );
		$this->assertSame( 'redirect', $halt->kind, 'status ' . $halt->status );
		$this->assertStringContainsString( 'wplc/v1/packages/', rawurldecode( $halt->location ) );

		return $this->token_of( $halt->location );
	}

	/**
	 * Row of a table by token.
	 *
	 * @param string $table Table key.
	 * @param string $token Plain token.
	 * @return array<string,mixed>
	 */
	private function grant_row( string $table, string $token ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables()[ $table ]} WHERE token_hash = %s", hash( 'sha256', $token ) ), ARRAY_A );
	}

	/**
	 * Log in a user who may manage releases.
	 *
	 * @return int User ID.
	 */
	private function login_manager(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Message of the last releases-screen action.
	 *
	 * @return array<string,mixed>
	 */
	private function notice(): array {
		$notice = get_transient( 'wplc_release_notice_' . get_current_user_id() );

		return is_array( $notice ) ? $notice : array();
	}

	// ------------------------------------------------------------------
	// B-53: package layout and version header, at upload and at publish.
	// ------------------------------------------------------------------

	public function test_b53_package_must_have_one_folder_and_a_matching_version_header(): void {
		$cases = array(
			'zip_folder_layout'    => $this->make_zip( 'test-product', '1.0.0', array( '__MACOSX/._x' => 'x' ) ),
			'zip_version_mismatch' => $this->make_zip( 'test-product', '1.0.1' ),
		);
		$loose                 = $this->make_zip( 'test-product', '1.0.0', array( 'readme.md' => 'loose file' ) );
		foreach ( array_merge( $cases, array( 'loose' => $loose ) ) as $expected => $zip ) {
			$result = ReleaseRegistry::create_with_package(
				array(
					'product_key' => 'test-product',
					'version'     => '1.0.0',
				),
				$zip
			);
			$this->assertWPError( $result );
			$this->assertSame( 'loose' === $expected ? 'zip_folder_layout' : $expected, $result->get_error_code() );
		}
		// A ZIP without a plugin header in its folder.
		$headerless    = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $headerless;
		$zip           = new ZipArchive();
		$zip->open( $headerless, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'test-product/index.php', '<?php // Silence.' );
		$zip->addFromString( 'test-product/inc/plugin.php', "<?php\n/* Plugin Name: Too deep\n Version: 1.0.0 */" );
		$zip->close();
		$result = ReleaseRegistry::create_with_package(
			array(
				'product_key' => 'test-product',
				'version'     => '1.0.0',
			),
			$headerless
		);
		$this->assertSame( 'zip_no_plugin_header', $result->get_error_code() );

		$this->assertSame( array(), (array) glob( $this->storage_dir . '/*.zip' ), 'A rejected package must not be stored.' );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['releases']}" ) );
	}

	public function test_b53_first_published_package_defines_the_product_folder(): void {
		$this->assertSame( '', ReleaseRegistry::product_slug( 'test-product' ) );
		$draft = $this->release( '1.0.0', array(), false );
		$this->assertSame( '', ReleaseRegistry::product_slug( 'test-product' ), 'A draft does not define the folder.' );
		$row = ReleaseRegistry::get( $draft );
		$this->assertSame( 'test-product/test-product.php', $row['plugin_file'] );
		// Empty requirements are taken from the plugin header.
		$this->assertSame( array( '7.4', '6.5' ), array( $row['requires_php'], $row['requires_wp'] ) );

		$this->assertTrue( ReleaseRegistry::publish( $draft, 0, 'first release' ) );
		$this->assertSame( 'test-product', ReleaseRegistry::product_slug( 'test-product' ) );

		// Another folder is refused at upload ...
		$wrong = ReleaseRegistry::create_with_package(
			array(
				'product_key' => 'test-product',
				'version'     => '1.1.0',
			),
			$this->make_zip( 'renamed-folder', '1.1.0' )
		);
		$this->assertSame( 'zip_slug_mismatch', $wrong->get_error_code() );

		// ... and at publish, when the folder of the product changed after the draft was uploaded.
		$second = $this->release( '1.1.0', array(), false );
		$this->assertTrue( ReleaseRegistry::set_product_slug( 'test-product', 'another-folder' ) );
		$refused = ReleaseRegistry::publish( $second, 0, 'second release' );
		$this->assertSame( 'zip_slug_mismatch', $refused->get_error_code() );
		$this->assertSame( 'draft', ReleaseRegistry::get( $second )['status'] );
	}

	public function test_b53_publish_rechecks_the_version_header_of_the_stored_file(): void {
		$id   = $this->release( '1.0.0', array(), false );
		$row  = ReleaseRegistry::get( $id );
		$path = LocalPackageStorage::path_for_key( (string) $row['package_url'] );
		// Somebody replaced the stored file by a build of another version (checksum updated to match).
		copy( $this->make_zip( 'test-product', '1.0.5' ), $path );
		$GLOBALS['wpdb']->update( $this->tables()['releases'], array( 'sha256' => hash_file( 'sha256', $path ) ), array( 'id' => $id ) );

		$result = ReleaseRegistry::publish( $id, 0, 'test' );
		$this->assertSame( 'zip_version_mismatch', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// B-54, B-174: versions and product keys.
	// ------------------------------------------------------------------

	public function test_b54_release_with_an_unsortable_version_suffix_is_refused(): void {
		foreach ( array( '2.0.0-pre1', '2.0.0-hotfix1', '2.0.0+build1' ) as $version ) {
			$result = ReleaseRegistry::create_with_package(
				array(
					'product_key' => 'test-product',
					'version'     => $version,
				),
				$this->make_zip( 'test-product', $version )
			);
			$this->assertSame( 'invalid_version', $result->get_error_code(), $version );
		}
		$this->assertIsInt( $this->release( '2.0.0-rc1', array( 'channel' => 'beta' ) ) );
	}

	public function test_b174_short_and_underscore_product_keys_can_have_releases(): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 'x', '_pro' ) as $key ) {
			$wpdb->insert(
				$this->tables()['products'],
				array(
					'product_key'  => $key,
					'display_name' => $key,
					'active'       => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			$this->assertIsInt( $this->release( '1.0.0', array( 'product_key' => $key ) ), $key );
		}
		// An empty version is reported as such, not as an unwritable directory.
		$empty = ReleaseRegistry::create_with_package(
			array(
				'product_key' => 'x',
				'version'     => '',
			),
			$this->make_zip( 'x', '1.0.1' )
		);
		$this->assertSame( 'invalid_version', $empty->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Warning for a version that is not above the highest published one.
	// ------------------------------------------------------------------

	public function test_publish_warning_for_a_version_that_is_not_the_highest(): void {
		$this->release( '1.5.0' );
		$lower  = $this->release( '1.4.1', array(), false );
		$higher = $this->release( '1.6.0', array(), false );

		$this->assertStringContainsString( '1.5.0', ReleaseRegistry::publish_warning( $lower ) );
		$this->assertSame( '', ReleaseRegistry::publish_warning( $higher ) );

		// The releases screen publishes anyway and shows the warning.
		$this->login_manager();
		$result = $this->run_admin_post(
			'wplc_release_publish',
			array(
				'release_id' => $lower,
				'reason'     => 'security backport',
				'_wpnonce'   => wp_create_nonce( 'wplc_release_publish_' . $lower ),
			)
		);
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( 'published', ReleaseRegistry::get( $lower )['status'] );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertTrue( $notice['warning'] );
		$this->assertStringContainsString( 'not higher than the highest published version 1.5.0', $notice['message'] );
	}

	// ------------------------------------------------------------------
	// B-26: narrow index, cached per product and channel, dropped on publish/withdraw.
	// ------------------------------------------------------------------

	public function test_b26_update_check_reads_a_narrow_cached_index_that_follows_publish_and_withdraw(): void {
		global $wpdb;
		$installation = $this->installation();
		$first        = $this->release( '1.1.0' );

		$index = ReleaseRegistry::published_index( 'test-product' );
		$this->assertCount( 1, $index );
		$this->assertArrayNotHasKey( 'release_notes', $index[0] );
		$this->assertArrayNotHasKey( 'package_url', $index[0] );

		// Served from the cache: no further query on the releases table for the index.
		$before = $wpdb->num_queries;
		ReleaseRegistry::published_index( 'test-product' );
		$this->assertSame( $before, $wpdb->num_queries );

		$this->assertSame( '1.1.0', $this->check( $installation['site'] )['version'] );

		$second = $this->release( '1.2.0' );
		$this->assertSame( '1.2.0', $this->check( $installation['site'] )['version'], 'Publishing must drop the cached index.' );

		$this->assertTrue( ReleaseRegistry::withdraw( $second, 'broken', 0 ) );
		$this->assertSame( '1.1.0', $this->check( $installation['site'] )['version'], 'Withdrawing must drop the cached index.' );

		$this->assertTrue( ReleaseRegistry::withdraw( $first, 'broken too', 0 ) );
		$data = $this->check( $installation['site'] );
		$this->assertFalse( $data['update_available'] );
		$this->assertSame( 'package_unavailable', $data['reason'] );
	}

	// ------------------------------------------------------------------
	// B-52, B-141: fields WordPress needs, details-view metadata, intermediate notes.
	// ------------------------------------------------------------------

	public function test_b52_b141_update_response_carries_wordpress_fields_and_metadata(): void {
		$installation = $this->installation();
		$this->release( '1.0.0' );
		$this->release( '1.1.0', array( 'tested_wp' => '6.8', 'requires_core' => '2.1.0' ) );
		$this->release( '1.2.0', array( 'tested_wp' => '6.9', 'requires_core' => '2.1.0', 'requires_php' => '8.0' ) );
		add_filter(
			'wplc_update_product_homepage',
			static function () {
				return 'https://shop.example/test-product/';
			}
		);
		add_filter(
			'wplc_update_product_icons',
			static function () {
				return array(
					'1x'  => 'https://shop.example/icon-128.png',
					'bad' => 'javascript:alert(1)',
				);
			}
		);

		$data = $this->check( $installation['site'], array( 'core_version' => '2.5.0' ) );

		$this->assertTrue( $data['update_available'] );
		$this->assertTrue( $data['license_active'] );
		// Old names kept, WordPress names added.
		$this->assertSame( '1.2.0', $data['version'] );
		$this->assertSame( '1.2.0', $data['new_version'] );
		$this->assertSame( $data['package_url'], $data['package'] );
		$this->assertMatchesRegularExpression( '#wplc/v1/updates/download/[a-f0-9]{64}$#', rawurldecode( $data['package'] ) );
		$this->assertSame( array( '6.9', '6.9' ), array( $data['tested_wp'], $data['tested'] ) );
		$this->assertSame( array( '6.5', '6.5' ), array( $data['requires_wp'], $data['requires'] ) );
		$this->assertSame( '8.0', $data['requires_php'] );
		$this->assertSame( '2.1.0', $data['requires_core'] );
		// Details view.
		$this->assertSame( 'test-product', $data['slug'] );
		$this->assertSame( 'test-product/test-product.php', $data['plugin'] );
		$this->assertSame( 'Test Product', $data['name'] );
		$this->assertSame( 'https://shop.example/test-product/', $data['homepage'] );
		$this->assertSame( array( '1x' => 'https://shop.example/icon-128.png' ), $data['icons'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['last_updated'] );
		$this->assertSame( 'stable', $data['channel'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $data['sha256'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['download_expires_at'] );
		// Notes of every skipped version, highest first.
		$this->assertSame( array( '1.2.0', '1.1.0' ), array_column( $data['changelog'], 'version' ) );
		$this->assertStringContainsString( 'Notes of 1.1.0', $data['changelog'][1]['notes'] );
		$this->assertStringContainsString( '<h4>1.2.0', $data['sections']['changelog'] );
		$this->assertStringContainsString( 'Notes of 1.1.0', $data['sections']['changelog'] );
		$this->assertStringNotContainsString( 'Notes of 1.0.0', $data['sections']['changelog'] );
		$this->assertSame( '<p>Notes of 1.2.0</p>', $data['release_notes'] );
		// Nothing about the storage leaves the server.
		$this->assertStringNotContainsString( 'wplc-it-', (string) wp_json_encode( $data ) );

		// Up to date: same metadata, no package, no grant.
		$current = $this->check( $installation['site'], array( 'current_version' => '1.2.0' ) );
		$this->assertFalse( $current['update_available'] );
		$this->assertSame( 'up_to_date', $current['reason'] );
		$this->assertSame( '1.2.0', $current['version'] );
		$this->assertSame( 'test-product', $current['slug'] );
		$this->assertSame( 'Test Product', $current['name'] );
		$this->assertNotEmpty( $current['last_updated'] );
		$this->assertSame( array( '1.2.0', '1.1.0', '1.0.0' ), array_column( $current['changelog'], 'version' ) );
		$this->assertSame( array( '', '' ), array( $current['package'], $current['package_url'] ) );
		$this->assertArrayNotHasKey( 'download_expires_at', $current );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['download_tokens']}" ) );
	}

	public function test_b52_icons_are_an_empty_list_when_none_are_configured(): void {
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0' ), $installation['site'] ) );
		$body     = $response->get_data();
		$this->assertSame( array(), $body['data']['icons'] );
		// The legacy signature survives a decode/encode round trip of the data (an empty object would not).
		$json = (string) wp_json_encode( $body['data'], JSON_UNESCAPED_SLASHES );
		$this->assertSame( $body['signature'], hash_hmac( 'sha256', (string) wp_json_encode( json_decode( $json, true ), JSON_UNESCAPED_SLASHES ), $installation['site']['secret'] ) );
	}

	// ------------------------------------------------------------------
	// A-40: no grant for a plain check; batched purge.
	// ------------------------------------------------------------------

	public function test_a40_check_without_download_creates_no_grant(): void {
		global $wpdb;
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$count = static function () use ( $wpdb ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \WPLC\Infrastructure\Database\Schema::table_names()['download_tokens'] );
		};

		foreach ( array( false, 'false', 0, '0' ) as $value ) {
			$data = $this->check( $installation['site'], array( 'download' => $value ) );
			$this->assertTrue( $data['update_available'] );
			$this->assertSame( '1.1.0', $data['new_version'] );
			$this->assertSame( array( '', '' ), array( $data['package'], $data['package_url'] ) );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $data['sha256'], 'The checksum is known before the download.' );
			$this->assertArrayNotHasKey( 'download_expires_at', $data );
		}
		$this->assertSame( 0, $count() );

		$with = $this->check( $installation['site'], array( 'download' => true ) );
		$this->assertNotSame( '', $with['package'] );
		$this->assertSame( 1, $count() );

		// Clients that predate the parameter keep getting a grant until the shop switches the default.
		$this->assertNotSame( '', $this->check( $installation['site'] )['package'] );
		add_filter( 'wplc_update_check_issues_download', '__return_false' );
		$this->assertSame( '', $this->check( $installation['site'] )['package'] );
		$this->assertSame( 2, $count() );
	}

	public function test_a40_purge_deletes_old_grants_and_forgets_used_package_locations(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license();
		$rows    = array(
			'old'           => array( time() - 9 * DAY_IN_SECONDS, null ),
			'old-used'      => array( time() - 8 * DAY_IN_SECONDS, time() - 8 * DAY_IN_SECONDS - 60 ),
			'used-long-ago' => array( time() - HOUR_IN_SECONDS, time() - HOUR_IN_SECONDS - 60 ),
			'used-just-now' => array( time() + 300, time() - 5 ),
			'open'          => array( time() + 300, null ),
		);
		foreach ( $rows as $label => $row ) {
			$wpdb->insert(
				$tables['download_tokens'],
				array(
					'license_id'  => $license['id'],
					'token_hash'  => hash( 'sha256', $label ),
					'package_url' => 'https://example.org/' . $label . '.zip',
					'expires_at'  => gmdate( 'Y-m-d H:i:s', $row[0] ),
					'consumed_at' => null === $row[1] ? null : gmdate( 'Y-m-d H:i:s', $row[1] ),
					'created_at'  => gmdate( 'Y-m-d H:i:s', $row[0] - 600 ),
				)
			);
		}

		UpdateService::purge_expired_grants();

		$left = $wpdb->get_results( "SELECT token_hash, package_url FROM {$tables['download_tokens']}", OBJECT_K );
		$this->assertArrayNotHasKey( hash( 'sha256', 'old' ), $left );
		$this->assertArrayNotHasKey( hash( 'sha256', 'old-used' ), $left );
		$this->assertSame( '', $left[ hash( 'sha256', 'used-long-ago' ) ]->package_url );
		$this->assertSame( 'https://example.org/used-just-now.zip', $left[ hash( 'sha256', 'used-just-now' ) ]->package_url, 'Still inside the reuse window.' );
		$this->assertSame( 'https://example.org/open.zip', $left[ hash( 'sha256', 'open' ) ]->package_url );
	}

	// ------------------------------------------------------------------
	// B-140: signed answer for a license without access.
	// ------------------------------------------------------------------

	public function test_b140_license_without_access_learns_version_and_reason_but_gets_no_package(): void {
		global $wpdb;
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$cases = array(
			'license_expired'   => array( 'status' => 'expired', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			'license_suspended' => array( 'status' => 'suspended' ),
			'license_revoked'   => array( 'status' => 'revoked' ),
		);
		foreach ( $cases as $reason => $columns ) {
			$wpdb->update( $this->tables()['licenses'], $columns, array( 'id' => $installation['license']['id'] ) );
			$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0', 'target_version' => '1.1.0' ), $installation['site'] ) );
			$body     = $response->get_data();
			$this->assertSame( 200, $response->get_status(), $reason );
			$this->assertSame( hash_hmac( 'sha256', (string) wp_json_encode( $body['data'], JSON_UNESCAPED_SLASHES ), $installation['site']['secret'] ), $body['signature'], 'The answer is signed.' );
			$data = $body['data'];
			$this->assertTrue( $data['update_available'], $reason );
			$this->assertFalse( $data['license_active'], $reason );
			$this->assertSame( $reason, $data['reason'] );
			$this->assertSame( '1.1.0', $data['new_version'] );
			$this->assertSame( array( '', '' ), array( $data['package'], $data['package_url'] ) );
			$this->assertArrayNotHasKey( 'download_expires_at', $data );
		}
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['download_tokens']}" ) );

		// Without any release there is nothing to announce.
		$wpdb->update( $this->tables()['releases'], array( 'status' => 'withdrawn' ), array( 'product_key' => 'test-product' ) );
		ReleaseRegistry::flush_cache( 'test-product' );
		$none = $this->check( $installation['site'] );
		$this->assertFalse( $none['update_available'] );
		$this->assertSame( 'license_revoked', $none['reason'] );
	}

	// ------------------------------------------------------------------
	// A-116, B-142: requirements are compared on the server; beta channel.
	// ------------------------------------------------------------------

	public function test_b142_release_that_needs_a_newer_core_plugin_is_not_offered(): void {
		$installation = $this->installation();
		$this->release( '1.1.0', array( 'requires_core' => '2.0.0' ) );
		$this->release( '1.2.0', array( 'requires_core' => '3.0.0' ) );

		// Core plugin 2.4: 1.2.0 does not fit, 1.1.0 is offered and the client is told why.
		$data = $this->check( $installation['site'], array( 'core_version' => '2.4.0' ) );
		$this->assertTrue( $data['update_available'] );
		$this->assertSame( '1.1.0', $data['new_version'] );
		$this->assertSame( '1.2.0', $data['blocked']['version'] );
		$this->assertSame( array( 'requires_core' ), $data['blocked']['unmet'] );
		$this->assertSame( '3.0.0', $data['blocked']['requires_core'] );

		// Core plugin 1.9: nothing fits.
		$none = $this->check( $installation['site'], array( 'core_version' => '1.9.0' ) );
		$this->assertFalse( $none['update_available'] );
		$this->assertSame( 'incompatible', $none['reason'] );
		$this->assertSame( '1.2.0', $none['blocked']['version'] );
		$this->assertArrayNotHasKey( 'package', $none );

		// New enough, or not reported at all (older clients): the highest version.
		$this->assertSame( '1.2.0', $this->check( $installation['site'], array( 'core_version' => '3.0.0' ) )['new_version'] );
		$all = $this->check( $installation['site'] );
		$this->assertSame( '1.2.0', $all['new_version'] );
		$this->assertArrayNotHasKey( 'blocked', $all );
	}

	public function test_a116_php_and_wordpress_requirements_use_the_versions_the_installation_reported(): void {
		global $wpdb;
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$this->release( '1.2.0', array( 'requires_php' => '8.1', 'requires_wp' => '6.6' ) );

		// Stored by an earlier entitlement check.
		$wpdb->update( $this->tables()['activations'], array( 'php_version' => '7.4.33', 'wp_version' => '6.5.2' ), array( 'id' => $installation['site']['id'] ) );
		$data = $this->check( $installation['site'] );
		$this->assertSame( '1.1.0', $data['new_version'] );
		$this->assertSame( array( 'requires_php', 'requires_wp' ), $data['blocked']['unmet'] );

		// Versions sent with the request win over the stored ones.
		$this->assertSame( '1.2.0', $this->check( $installation['site'], array( 'php_version' => '8.3.6', 'wp_version' => '6.7' ) )['new_version'] );
		// An array instead of a string is ignored (no "Array" comparison, no PHP warning).
		$this->assertSame( '1.1.0', $this->check( $installation['site'], array( 'php_version' => array( '9.0' ) ) )['new_version'] );
	}

	public function test_a116_beta_channel_is_only_delivered_on_request(): void {
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$this->release( '2.0.0-beta1', array( 'channel' => 'beta' ) );

		$stable = $this->check( $installation['site'] );
		$this->assertSame( array( '1.1.0', 'stable' ), array( $stable['new_version'], $stable['channel'] ) );
		$beta = $this->check( $installation['site'], array( 'channel' => 'beta' ) );
		$this->assertSame( array( '2.0.0-beta1', 'beta' ), array( $beta['new_version'], $beta['channel'] ) );
		// A beta installation also follows stable releases.
		$this->release( '2.0.0' );
		$this->assertSame( '2.0.0', $this->check( $installation['site'], array( 'channel' => 'beta' ) )['new_version'] );
		// Unknown channels fall back to stable.
		$this->assertSame( 'stable', $this->check( $installation['site'], array( 'channel' => 'nightly' ) )['channel'] );
	}

	// ------------------------------------------------------------------
	// B-145: a specific older version.
	// ------------------------------------------------------------------

	public function test_b145_api_delivers_a_specific_older_version(): void {
		$installation = $this->installation();
		$old          = $this->release( '1.1.0' );
		$this->release( '1.2.0' );

		$data = $this->check( $installation['site'], array( 'current_version' => '1.2.0', 'target_version' => '1.1.0' ) );
		$this->assertFalse( $data['update_available'], 'A rollback is not an update.' );
		$this->assertSame( '1.1.0', $data['version'] );
		$this->assertSame( '1.1.0', $data['target_version'] );
		$this->assertSame( ReleaseRegistry::get( $old )['sha256'], $data['sha256'] );
		$this->assertNotSame( '', $data['package'] );

		// The version that is installed, an unknown and a withdrawn version: no package.
		$same = $this->check( $installation['site'], array( 'current_version' => '1.1.0', 'target_version' => '1.1.0' ) );
		$this->assertSame( '', $same['package'] );
		$unknown = $this->check( $installation['site'], array( 'target_version' => '9.9.9' ) );
		$this->assertSame( 'package_unavailable', $unknown['reason'] );
		ReleaseRegistry::withdraw( $old, 'broken', 0 );
		$this->assertSame( 'package_unavailable', $this->check( $installation['site'], array( 'current_version' => '1.2.0', 'target_version' => '1.1.0' ) )['reason'] );
	}

	// ------------------------------------------------------------------
	// A-111: a missing local file.
	// ------------------------------------------------------------------

	public function test_a111_release_with_a_missing_file_is_not_offered_and_does_not_burn_a_grant(): void {
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$broken = $this->release( '1.2.0' );
		$data   = $this->check( $installation['site'] );
		$this->assertSame( '1.2.0', $data['new_version'] );
		$grant_token = $this->token_of( $data['package_url'] );

		$missing = array();
		add_action(
			'wplc_release_package_missing',
			static function ( $release ) use ( &$missing ) {
				$missing[] = (int) $release['id'];
			}
		);
		unlink( LocalPackageStorage::path_for_key( (string) ReleaseRegistry::get( $broken )['package_url'] ) );

		// The grant issued before the file vanished answers 404 and stays unused.
		$halt = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $grant_token ) );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ) );
		$this->assertNull( $this->grant_row( 'download_tokens', $grant_token )['consumed_at'] );

		// The feed falls back to the next version whose file exists, and says so through the action.
		$this->assertSame( '1.1.0', $this->check( $installation['site'] )['new_version'] );
		$this->assertContains( $broken, $missing );

		// The releases screen and the diagnostics hook name the problem.
		$problems = ReleaseRegistry::problems();
		$this->assertSame( array( 'package_missing' ), array_column( $problems, 'code' ) );
		$this->assertSame( $broken, $problems[0]['release_id'] );
	}

	// ------------------------------------------------------------------
	// B-19, B-52, A-111: the streaming route.
	// ------------------------------------------------------------------

	public function test_b19_stream_answers_head_without_using_the_token_and_allows_a_short_reuse(): void {
		global $wpdb;
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$token = $this->stream_token_for( $this->check( $installation['site'] )['package_url'] );
		$route = '/wplc/v1/packages/' . $token;

		$head = $this->run_request( new WP_REST_Request( 'HEAD', $route ) );
		$this->assertSame( array( 'status', 200 ), array( $head->kind, $head->status ) );
		$this->assertNull( $this->grant_row( 'package_streams', $token )['consumed_at'], 'HEAD must not use the token up.' );

		$first = $this->run_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( array( 'status', 200 ), array( $first->kind, $first->status ) );
		$this->assertNotNull( $this->grant_row( 'package_streams', $token )['consumed_at'] );

		// Second fetch inside the reuse window; a range resumes an interrupted download.
		$second = $this->run_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( array( 'status', 200 ), array( $second->kind, $second->status ) );
		$range = new WP_REST_Request( 'GET', $route );
		$range->set_header( 'Range', 'bytes=100-' );
		$partial = $this->run_request( $range );
		$this->assertSame( array( 'status', 206 ), array( $partial->kind, $partial->status ) );

		// After the window the token is dead.
		$wpdb->update( $this->tables()['package_streams'], array( 'consumed_at' => gmdate( 'Y-m-d H:i:s', time() - 121 ) ), array( 'token_hash' => hash( 'sha256', $token ) ) );
		$late = $this->run_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertInstanceOf( WP_REST_Response::class, $late );
		$this->assertSame( 404, $late->get_status() );
		$this->assertSame( 404, $this->run_request( new WP_REST_Request( 'HEAD', $route ) )->get_status() );
	}

	public function test_b19_strict_single_use_can_be_restored(): void {
		add_filter( 'wplc_download_reuse_seconds', '__return_zero' );
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$token = $this->stream_token_for( $this->check( $installation['site'] )['package_url'] );

		$first = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $token ) );
		$this->assertSame( array( 'status', 200 ), array( $first->kind, $first->status ) );
		$second = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $token ) );
		$this->assertInstanceOf( WP_REST_Response::class, $second );
		$this->assertSame( 404, $second->get_status() );
	}

	public function test_b52_content_md5_is_the_base64_md5_of_the_stored_package(): void {
		$id   = $this->release( '1.1.0' );
		$key  = (string) ReleaseRegistry::get( $id )['package_url'];
		$path = LocalPackageStorage::path_for_key( $key );
		$md5  = LocalPackageStorage::content_md5( $key );
		$this->assertSame( base64_encode( md5_file( $path, true ) ), $md5 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( $md5, PackageStream::response_headers( (int) filesize( $path ), 'x.zip', $md5, null )['headers']['Content-MD5'] );
		$this->assertSame( '', LocalPackageStorage::content_md5( 'missing-1.0.0-' . str_repeat( 'a', 32 ) . '.zip' ) );
	}

	public function test_get_routes_forbid_page_caching(): void {
		$installation = $this->installation();
		$this->release( '1.1.0' );
		$this->stream_token_for( $this->check( $installation['site'] )['package_url'] );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'Both GET routes must tell page caches to stay away.' );
	}

	// ------------------------------------------------------------------
	// B-143: releases with a package URL.
	// ------------------------------------------------------------------

	/**
	 * Answer the server-side download of a package URL with the given bytes.
	 *
	 * @param string $bytes File contents.
	 * @return void
	 */
	private function serve_remote_file( string $bytes ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( $bytes ) {
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			0,
			2
		);
	}

	/**
	 * Create a draft release with storage "url".
	 *
	 * @param string $version Version.
	 * @param string $sha256  Checksum.
	 * @return int
	 */
	private function url_release( string $version, string $sha256 ): int {
		$id = ReleaseRegistry::create(
			array(
				'product_key' => 'test-product',
				'version'     => $version,
				'storage'     => 'url',
				'package_url' => 'https://example.org/downloads/test-product-' . $version . '.zip',
				'sha256'      => $sha256,
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	public function test_b143_publishing_a_url_release_downloads_and_verifies_the_package(): void {
		$bytes = (string) file_get_contents( $this->make_zip( 'test-product', '1.1.0' ) );

		// Wrong checksum.
		$this->serve_remote_file( $bytes );
		$wrong = $this->url_release( '1.1.0', str_repeat( 'a', 64 ) );
		$this->assertSame( 'checksum_mismatch_remote', ReleaseRegistry::publish( $wrong, 0, 'test' )->get_error_code() );
		$this->assertSame( 'draft', ReleaseRegistry::get( $wrong )['status'] );

		// Right checksum, but the file is a build of another version.
		$other = $this->url_release( '1.1.5', hash( 'sha256', $bytes ) );
		$this->assertSame( 'zip_version_mismatch', ReleaseRegistry::publish( $other, 0, 'test' )->get_error_code() );

		// Matching file: published, plugin file and folder recorded.
		$this->assertTrue( ReleaseRegistry::delete_draft( $wrong, 0, 'wrong checksum' ) );
		$good = $this->url_release( '1.1.0', hash( 'sha256', $bytes ) );
		$this->assertTrue( ReleaseRegistry::publish( $good, 0, 'test' ) );
		$this->assertSame( 'test-product/test-product.php', ReleaseRegistry::get( $good )['plugin_file'] );
		$this->assertSame( 'test-product', ReleaseRegistry::product_slug( 'test-product' ) );
	}

	public function test_b143_unreachable_package_url_cannot_be_published(): void {
		// set_up() blocks HTTP: the download fails.
		$id     = $this->url_release( '1.1.0', str_repeat( 'a', 64 ) );
		$result = ReleaseRegistry::publish( $id, 0, 'test' );
		$this->assertSame( 'package_download_failed', $result->get_error_code() );
		$this->assertSame( 'draft', ReleaseRegistry::get( $id )['status'] );

		// A pipeline that verifies the package itself can switch the download off.
		add_filter( 'wplc_verify_url_release_on_publish', '__return_false' );
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'test' ) );
	}

	public function test_b143_grant_of_a_withdrawn_url_release_is_not_redeemed(): void {
		add_filter( 'wplc_verify_url_release_on_publish', '__return_false' );
		$installation = $this->installation();
		$id           = $this->url_release( '1.1.0', str_repeat( 'a', 64 ) );
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'test' ) );
		$token = $this->token_of( $this->check( $installation['site'] )['package_url'] );

		// HEAD says the download is there and leaves the grant alone.
		$head = $this->run_request( new WP_REST_Request( 'HEAD', '/wplc/v1/updates/download/' . $token ) );
		$this->assertSame( array( 'status', 200 ), array( $head->kind, $head->status ) );

		$this->assertTrue( ReleaseRegistry::withdraw( $id, 'wrong build', 0 ) );
		$halt = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) );
		$this->assertSame( array( 'status', 404 ), array( $halt->kind, $halt->status ), 'A withdrawn package must not be delivered any more.' );
		$this->assertNull( $this->grant_row( 'download_tokens', $token )['consumed_at'] );

		// Published again: the same grant works.
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'fixed' ) );
		$halt = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) );
		$this->assertSame( array( 'redirect', 'https://example.org/downloads/test-product-1.1.0.zip' ), array( $halt->kind, $halt->location ) );
	}

	// B-102: a published URL release whose host is no longer allowed (shop moved to another domain).
	public function test_b102_url_release_on_a_host_that_is_no_longer_allowed_is_reported(): void {
		add_filter( 'wplc_verify_url_release_on_publish', '__return_false' );
		$id = $this->url_release( '1.1.0', str_repeat( 'a', 64 ) );
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'test' ) );
		$this->assertSame( array(), ReleaseRegistry::problems() );

		// The shop now lives on another host.
		add_filter(
			'wplc_update_package_hosts',
			static function () {
				return array( 'new-shop.example' );
			},
			2000
		);
		$problems = ReleaseRegistry::problems();
		$this->assertSame( array( 'untrusted_package_host' ), array_column( $problems, 'code' ) );
		$this->assertSame( $id, $problems[0]['release_id'] );
		$this->assertStringContainsString( 'example.org', $problems[0]['message'] );
	}

	// ------------------------------------------------------------------
	// T-135: is the package directory reachable over HTTP?
	// ------------------------------------------------------------------

	public function test_t135_exposed_package_directory_is_detected(): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = $uploads['basedir'] . '/wplc-it-' . bin2hex( random_bytes( 4 ) );
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			},
			20
		);
		$requested = array();
		$answer    = 'file';
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$requested, &$answer, $dir ) {
				$requested[] = $url;
				// nginx without a deny rule hands the file out; with the rule it answers 403.
				return array(
					'headers'  => array(),
					'body'     => 'file' === $answer ? (string) file_get_contents( $dir . '/' . LocalPackageStorage::PROBE_FILE ) : 'Forbidden',
					'response' => array(
						'code'    => 'file' === $answer ? 200 : 403,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			0,
			3
		);

		$this->assertSame( 'exposed', LocalPackageStorage::exposure_status( true ) );
		$this->assertStringEndsWith( '/' . basename( $dir ) . '/' . LocalPackageStorage::PROBE_FILE, $requested[0] );
		$this->assertFileDoesNotExist( $dir . '/' . LocalPackageStorage::PROBE_FILE, 'The probe file is removed again.' );
		$this->assertContains( 'package_dir_exposed', array_column( ReleaseRegistry::problems(), 'code' ) );

		$answer = 'forbidden';
		$this->assertSame( 'exposed', LocalPackageStorage::exposure_status(), 'The result is cached.' );
		$this->assertSame( 'protected', LocalPackageStorage::exposure_status( true ) );
		$this->assertNotContains( 'package_dir_exposed', array_column( ReleaseRegistry::problems(), 'code' ) );

		foreach ( array( '.htaccess', 'index.html', 'web.config' ) as $file ) {
			unlink( $dir . '/' . $file );
		}
		rmdir( $dir );

		// A directory outside the uploads folder has no URL to probe.
		remove_all_filters( 'wplc_package_storage_dir' );
		add_filter(
			'wplc_package_storage_dir',
			static function () {
				return sys_get_temp_dir() . '/wplc-outside';
			}
		);
		$this->assertSame( 'outside_webroot', LocalPackageStorage::exposure_status( true ) );
	}

	// ------------------------------------------------------------------
	// Releases screen: A-35/B-49, A-57, A-59, A-119, A-127, A-131, B-137, release log.
	// ------------------------------------------------------------------

	public function test_a35_release_actions_use_the_filterable_capability(): void {
		$this->login_manager();
		$id   = $this->release( '1.1.0', array(), false );
		$post = array(
			'release_id' => $id,
			'reason'     => 'go',
			'_wpnonce'   => wp_create_nonce( 'wplc_release_publish_' . $id ),
		);
		add_filter(
			'wplc_capability',
			static function ( $capability, $area ) {
				return 'publish_releases' === $area ? 'wplc_publish_releases' : $capability;
			},
			10,
			2
		);

		// manage_woocommerce alone is no longer enough.
		$denied = $this->run_admin_post( 'wplc_release_publish', $post );
		$this->assertSame( 'die', $denied['outcome'] );
		$this->assertSame( 'draft', ReleaseRegistry::get( $id )['status'] );
		foreach ( array( 'wplc_release_create', 'wplc_release_import', 'wplc_release_withdraw', 'wplc_release_delete' ) as $action ) {
			$this->assertSame( 'die', $this->run_admin_post( $action, $post )['outcome'], $action );
		}

		wp_get_current_user()->add_cap( 'wplc_publish_releases' );
		$this->assertSame( 'redirect', $this->run_admin_post( 'wplc_release_publish', $post )['outcome'] );
		$this->assertSame( 'published', ReleaseRegistry::get( $id )['status'] );
	}

	public function test_b137_publish_withdraw_and_delete_need_a_reason_that_is_logged(): void {
		global $wpdb;
		$user_id = $this->login_manager();
		$id      = $this->release( '1.1.0', array(), false );
		$draft   = $this->release( '1.2.0', array(), false );
		$post    = static function ( string $action, int $release_id, string $reason ) {
			return array(
				'release_id' => $release_id,
				'reason'     => $reason,
				'_wpnonce'   => wp_create_nonce( 'wplc_release_' . $action . '_' . $release_id ),
			);
		};

		// Without a reason nothing happens.
		$this->run_admin_post( 'wplc_release_publish', $post( 'publish', $id, '   ' ) );
		$this->assertSame( 'draft', ReleaseRegistry::get( $id )['status'] );
		$this->assertFalse( $this->notice()['success'] );
		$this->assertStringContainsString( 'reason', $this->notice()['message'] );
		$this->run_admin_post( 'wplc_release_delete', $post( 'delete', $draft, '' ) );
		$this->assertNotNull( ReleaseRegistry::get( $draft ) );

		$this->run_admin_post( 'wplc_release_publish', $post( 'publish', $id, 'QA passed, ticket 4711' ) );
		$this->assertSame( 'published', ReleaseRegistry::get( $id )['status'] );
		$this->run_admin_post( 'wplc_release_withdraw', $post( 'withdraw', $id, '' ) );
		$this->assertSame( 'published', ReleaseRegistry::get( $id )['status'], 'Withdrawing without a reason is refused.' );
		$this->run_admin_post( 'wplc_release_withdraw', $post( 'withdraw', $id, str_repeat( 'x', 400 ) ) );
		$this->assertSame( 'withdrawn', ReleaseRegistry::get( $id )['status'] );
		$this->run_admin_post( 'wplc_release_delete', $post( 'delete', $draft, 'uploaded by mistake' ) );
		$this->assertNull( ReleaseRegistry::get( $draft ) );

		$log = $wpdb->get_results( $wpdb->prepare( "SELECT action, reason, actor_user_id FROM {$this->tables()['audit_log']} WHERE object_type = %s AND action IN ('release_published','release_withdrawn','release_deleted') ORDER BY id ASC", 'release' ), ARRAY_A );
		$this->assertSame( array( 'release_published', 'release_withdrawn', 'release_deleted' ), array_column( $log, 'action' ) );
		$this->assertSame( 'QA passed, ticket 4711', $log[0]['reason'] );
		$this->assertSame( 255, strlen( $log[1]['reason'] ), 'The reason is limited to 255 characters on the server.' );
		$this->assertSame( 'uploaded by mistake', $log[2]['reason'] );
		$this->assertSame( array( (string) $user_id ), array_values( array_unique( array_column( $log, 'actor_user_id' ) ) ) );

		// The release log on the screen shows who did what and why, also for the deleted draft.
		$entries = ReleaseRegistry::audit_entries( 10 );
		$this->assertSame( 'release_deleted', $entries[0]['action'] );
		$this->assertSame( array( 'test-product', '1.2.0', 'uploaded by mistake' ), array( $entries[0]['product_key'], $entries[0]['version'], $entries[0]['reason'] ) );
	}

	public function test_a59_a127_a131_releases_screen_markup(): void {
		$this->login_manager();
		update_option( 'timezone_string', 'Europe/Berlin' );
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		$published = $this->release( '1.1.0' );
		$this->release( '1.2.0', array(), false );
		$GLOBALS['wpdb']->update( $this->tables()['releases'], array( 'published_at' => '2026-01-15 23:30:00' ), array( 'id' => $published ) );

		ob_start();
		ReleaseAdmin::render();
		$html = (string) ob_get_clean();

		// A-59: every state-changing action asks before it runs, and has a required reason (B-137).
		$this->assertSame( 3, substr_count( $html, 'data-wplc-confirm="' ), 'withdraw 1.1.0, publish 1.2.0, delete 1.2.0' );
		$this->assertSame( 3, preg_match_all( '/name="reason"[^>]*\srequired/', $html ) );
		// A-127: no repeated id="_wpnonce".
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );
		$this->assertGreaterThanOrEqual( 5, substr_count( $html, 'name="_wpnonce"' ) );
		// A-119, A-131: the publication date in the site's time zone and format, not raw UTC.
		$this->assertStringContainsString( '16.01.2026 00:30', $html );
		$this->assertStringNotContainsString( '2026-01-15 23:30:00', $html );
		// A-119: help for the checksum; A-57: the action survives an oversized upload because it is in the URL.
		$this->assertStringContainsString( 'sha256sum', $html );
		$this->assertStringContainsString( 'admin-post.php?action=wplc_release_create', $html );
		// B-53, B-142, release log.
		$this->assertStringContainsString( 'Plugin folder: test-product', $html );
		$this->assertStringContainsString( 'name="requires_core"', $html );
		$this->assertStringContainsString( 'Release log', $html );

		// The confirmation script is loaded on this screen only.
		$_GET['page'] = ReleaseAdmin::PAGE;
		ReleaseAdmin::enqueue_assets();
		$this->assertTrue( wp_script_is( 'wplc-ui', 'enqueued' ) );
	}

	public function test_a57_oversized_upload_ends_with_a_message_and_errors_keep_the_form_values(): void {
		$this->login_manager();
		$this->halt_instead_of_exit();

		// post_max_size exceeded: PHP delivers neither fields nor files, only the action from the URL.
		$_POST                     = array();
		$_FILES                    = array();
		$_SERVER['CONTENT_LENGTH'] = '99999999';
		try {
			do_action( 'admin_post_wplc_release_create' );
			$this->fail( 'The handler must redirect back to the releases screen.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertSame( 'redirect', $halt->kind );
			$this->assertStringContainsString( 'page=wplc-releases', $halt->location );
		}
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertStringContainsString( 'larger than this server accepts', $notice['message'] );
		unset( $_SERVER['CONTENT_LENGTH'] );
		delete_transient( 'wplc_release_notice_' . get_current_user_id() );

		// A rejected form comes back filled in.
		$this->run_admin_post(
			'wplc_release_create',
			array(
				'_wpnonce'      => wp_create_nonce( ReleaseAdmin::NONCE_CREATE ),
				'product_key'   => 'other-product',
				'version'       => '1.2.3-hotfix1',
				'channel'       => 'beta',
				'package_url'   => 'https://example.org/other-product.zip',
				'sha256'        => str_repeat( 'b', 64 ),
				'requires_core' => '2.0.0',
				'release_notes' => 'Keeps <strong>this</strong>.',
			)
		);
		$this->assertFalse( $this->notice()['success'] );
		ob_start();
		ReleaseAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="1.2.3-hotfix1"', $html );
		$this->assertStringContainsString( 'value="https://example.org/other-product.zip"', $html );
		$this->assertStringContainsString( 'value="' . str_repeat( 'b', 64 ) . '"', $html );
		$this->assertStringContainsString( 'value="2.0.0"', $html );
		$this->assertMatchesRegularExpression( '/<option value="other-product"\s+selected/', $html );
		$this->assertMatchesRegularExpression( '/<option value="beta"\s+selected/', $html );
		$this->assertStringContainsString( 'Keeps &lt;strong&gt;this&lt;/strong&gt;.', $html );
	}

	public function test_a119_untrusted_host_is_named_when_the_draft_is_created(): void {
		$this->login_manager();
		$this->run_admin_post(
			'wplc_release_create',
			array(
				'_wpnonce'    => wp_create_nonce( ReleaseAdmin::NONCE_CREATE ),
				'product_key' => 'test-product',
				'version'     => '1.1.0',
				'package_url' => 'https://downloads.not-allowed.example/test-product-1.1.0.zip',
				'sha256'      => str_repeat( 'b', 64 ),
			)
		);
		$notice = $this->notice();
		$this->assertTrue( $notice['success'], 'The draft is still created.' );
		$this->assertStringContainsString( 'License settings', $notice['message'] );
		$this->assertStringNotContainsString( 'wplc_update_package_hosts', $notice['message'], 'A shop manager is pointed to the setting, not to a PHP filter.' );
	}

	// A-32: a URL release without a resolver is called out on the screen.
	public function test_a32_screen_warns_about_permanent_package_urls(): void {
		add_filter( 'wplc_verify_url_release_on_publish', '__return_false' );
		$this->login_manager();
		$this->assertTrue( ReleaseRegistry::publish( $this->url_release( '1.1.0', str_repeat( 'a', 64 ) ), 0, 'test' ) );
		$this->assertFalse( UpdateService::has_package_resolver() );

		ob_start();
		ReleaseAdmin::render();
		$this->assertStringContainsString( 'learns the permanent address', (string) ob_get_clean() );

		add_filter( 'wplc_resolve_update_package_url', 'strval' );
		$this->assertTrue( UpdateService::has_package_resolver() );
		ob_start();
		ReleaseAdmin::render();
		$this->assertStringNotContainsString( 'learns the permanent address', (string) ob_get_clean() );
	}

	// ------------------------------------------------------------------
	// B-169: WP-CLI.
	// ------------------------------------------------------------------

	public function test_b169_cli_creates_and_publishes_a_release_from_a_zip(): void {
		$installation = $this->installation();
		$zip          = $this->make_zip( 'test-product', '1.3.0' );

		$draft = ReleaseCommand::run_create(
			array(
				'product'       => 'test-product',
				'version'       => '1.3.0',
				'zip'           => $zip,
				'notes'         => '<p>From the pipeline</p>',
				'requires-core' => '2.0.0',
			)
		);
		$this->assertFalse( $draft['published'] );
		$row = ReleaseRegistry::get( $draft['release_id'] );
		$this->assertSame( array( 'draft', 'local', '2.0.0', hash_file( 'sha256', $zip ) ), array( $row['status'], $row['storage'], $row['requires_core'], $row['sha256'] ) );
		$this->assertFileExists( $zip, 'The source ZIP is copied, not moved.' );

		$published = ReleaseCommand::run_create(
			array(
				'product' => 'test-product',
				'version' => '1.4.0',
				'zip'     => $this->make_zip( 'test-product', '1.4.0' ),
				'publish' => true,
				'reason'  => 'CI build 77',
			)
		);
		$this->assertTrue( $published['published'] );
		$this->assertSame( '1.4.0', $this->check( $installation['site'] )['new_version'] );

		// A failed publish leaves neither a draft nor a file behind, so the pipeline can run again.
		$files_before = count( (array) glob( $this->storage_dir . '/*.zip' ) );
		$rows_before  = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['releases']}" );
		$calls        = 0;
		add_filter(
			'wplc_release_product_slug',
			static function ( $slug ) use ( &$calls ) {
				// The folder of the product changes between the upload check and the publish check.
				return ++$calls > 1 ? 'moved-folder' : $slug;
			}
		);
		$failed = ReleaseCommand::run_create(
			array(
				'product' => 'test-product',
				'version' => '1.5.0',
				'zip'     => $this->make_zip( 'test-product', '1.5.0' ),
				'publish' => true,
			)
		);
		$this->assertWPError( $failed );
		$this->assertSame( 'zip_slug_mismatch', $failed->get_error_code() );
		$this->assertCount( $files_before, (array) glob( $this->storage_dir . '/*.zip' ) );
		$this->assertSame( $rows_before, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->tables()['releases']}" ) );
		remove_all_filters( 'wplc_release_product_slug' );
		$this->assertWPError( ReleaseCommand::run_create( array( 'product' => 'test-product', 'version' => '1.6.0', 'zip' => '/nonexistent.zip' ) ) );
	}

	// ------------------------------------------------------------------
	// Customer account: A-55, A-116, A-127, B-17, B-145.
	// ------------------------------------------------------------------

	/**
	 * Rendered download form for a license.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	private function button_html( int $license_id ): string {
		ob_start();
		CustomerDownload::render_button( $this->license( $license_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Post the account download form like a browser would (front end, not wp-admin).
	 *
	 * @param int    $license_id License ID.
	 * @param string $version    Chosen version, or an empty string.
	 * @return WPLC_Test_Halt|WPDieException
	 */
	private function post_download( int $license_id, string $version = '' ) {
		$this->halt_instead_of_exit();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'wplc_action' => CustomerDownload::ACTION,
			'license_id'  => (string) $license_id,
			'version'     => $version,
			'_wpnonce'    => wp_create_nonce( CustomerDownload::nonce_action( $license_id ) ),
		);
		try {
			CustomerDownload::maybe_handle_frontend();
		} catch ( WPLC_Test_Halt $halt ) {
			return $halt;
		} catch ( WPDieException $die ) {
			return $die;
		}
		$this->fail( 'The download handler returned.' );
	}

	public function test_b17_b145_customer_downloads_latest_or_an_older_version_from_the_account_url(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer ) );
		wp_set_current_user( $customer );

		// A-55: without a release the customer is told so.
		$this->assertStringContainsString( 'No download is available for this product yet', $this->button_html( $license['id'] ) );

		$old = $this->release( '1.1.0' );
		$this->assertStringContainsString( 'Download version 1.1.0', $this->button_html( $license['id'] ) );
		$new  = $this->release( '1.2.0' );
		$html = $this->button_html( $license['id'] );
		// B-17: the form posts to the customer account, not to wp-admin.
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
		$this->assertStringContainsString( 'name="wplc_action" value="wplc_download_package"', $html );
		// A-127.
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );
		// B-145: older versions can be chosen.
		$this->assertMatchesRegularExpression( '/<option value="1\.2\.0"\s+selected[^>]*>1\.2\.0 \(latest\)/', $html );
		$this->assertStringContainsString( '<option value="1.1.0">1.1.0</option>', $html );

		$latest = $this->post_download( $license['id'] );
		$this->assertInstanceOf( WPLC_Test_Halt::class, $latest );
		$this->assertSame( $new, (int) $this->grant_row( 'package_streams', $this->token_of( $latest->location ) )['release_id'] );

		$older = $this->post_download( $license['id'], '1.1.0' );
		$this->assertInstanceOf( WPLC_Test_Halt::class, $older );
		$token = $this->token_of( $older->location );
		$this->assertSame( $old, (int) $this->grant_row( 'package_streams', $token )['release_id'] );
		$stream = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $token ) );
		$this->assertSame( array( 'status', 200 ), array( $stream->kind, $stream->status ) );

		// Unknown or malformed versions are refused.
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license['id'], '9.9.9' ) );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license['id'], '../1.1.0' ) );

		// Somebody else's license.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license['id'] ) );
		$this->assertSame( '', $this->button_html( $license['id'] ) );
	}

	public function test_a116_ended_license_keeps_the_versions_released_while_it_was_paid(): void {
		global $wpdb;
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license( array( 'customer_id' => $customer ) );
		wp_set_current_user( $customer );
		$paid_for = $this->release( '1.1.0' );
		$later    = $this->release( '1.2.0' );
		$wpdb->update( $this->tables()['releases'], array( 'published_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $paid_for ) );
		ReleaseRegistry::flush_cache( 'test-product' );

		// The license ended 30 days ago: 1.1.0 was released before that, 1.2.0 afterwards.
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			),
			array( 'id' => $license['id'] )
		);
		$html = $this->button_html( $license['id'] );
		$this->assertStringContainsString( 'Download version 1.1.0', $html );
		$this->assertStringNotContainsString( '1.2.0', $html );
		$this->assertStringContainsString( 'This license has ended', $html );

		$halt = $this->post_download( $license['id'] );
		$this->assertInstanceOf( WPLC_Test_Halt::class, $halt );
		$token = $this->token_of( $halt->location );
		$this->assertSame( $paid_for, (int) $this->grant_row( 'package_streams', $token )['release_id'] );
		$stream = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $token ) );
		$this->assertSame( array( 'status', 200 ), array( $stream->kind, $stream->status ), 'The stream serves an account download of an ended license.' );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license['id'], '1.2.0' ), 'A version released after the paid period needs a renewal.' );
		$this->assertSame( $later, (int) ReleaseRegistry::latest_published( 'test-product' )['id'] );

		// The update API stays closed for the ended license (B-140 covers its answer).
		$site = $this->seed_activation( $license['id'] );
		$this->assertSame( '', $this->check( $site )['package'] );

		// Refunded or charged back: nothing, not even the old version.
		foreach ( array( 'revoked', 'suspended', 'pending' ) as $status ) {
			$wpdb->update( $this->tables()['licenses'], array( 'status' => $status ), array( 'id' => $license['id'] ) );
			$this->assertSame( '', $this->button_html( $license['id'] ), $status );
			$this->assertInstanceOf( WPDieException::class, $this->post_download( $license['id'] ), $status );
		}
		// The token issued while the license was "expired" died with the revocation.
		$dead = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $token ) );
		$this->assertSame( 404, $dead->get_status() );

		// The shop can switch the policy off.
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'expired' ), array( 'id' => $license['id'] ) );
		add_filter( 'wplc_expired_license_download', '__return_false' );
		$this->assertSame( '', $this->button_html( $license['id'] ) );
	}
}
