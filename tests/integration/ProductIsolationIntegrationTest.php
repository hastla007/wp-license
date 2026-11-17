<?php
/**
 * PK-03: package and updates only for the product of the license.
 *
 * Two products with releases. A customer with a license for product A gets no
 * package, no version and no name of product B on any delivery path: update
 * check, download grant, package stream, customer account (download and
 * version list), the placeholder address `?wplc-package=` and the release
 * overviews. Also not when the same customer holds a suspended or expired
 * license for B (an expired license keeps the versions released while it was
 * paid, A-116; nothing newer).
 *
 * @package WPLC
 */

use WPLC\Account\Blocks;
use WPLC\Update\CustomerDownload;
use WPLC\Update\PackageStream;
use WPLC\Update\ReleaseRegistry;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class ProductIsolationIntegrationTest extends WPLC_Portal_TestCase {

	const PRODUCT_A = 'alpha-addon';
	const PRODUCT_B = 'bravo-connector';
	const NAME_B    = 'Bravo Harbour Connector';

	/**
	 * Private package directory of this test.
	 *
	 * @var string
	 */
	private $storage_dir = '';

	/**
	 * Temporary files.
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
	 * Customer with the license for product A.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->https_before = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS']   = 'on';
		$this->storage_dir  = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) );
		$dir                = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		add_filter(
			'pre_http_request',
			static function ( $pre ) {
				return false !== $pre ? $pre : new WP_Error( 'http_request_blocked', 'HTTP is blocked in tests.' );
			},
			1
		);

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( self::PRODUCT_A => 'Alpha Addon', self::PRODUCT_B => self::NAME_B ) as $key => $name ) {
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
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	public function tear_down(): void {
		if ( null === $this->https_before ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_before;
		}
		$_GET = array();
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
	 * Publish a release with a local package.
	 *
	 * @param string $product Product key.
	 * @param string $version Version.
	 * @return int Release ID.
	 */
	private function release( string $product, string $version ): int {
		$path          = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( $product . '/' . $product . '.php', "<?php\n/**\n * Plugin Name: {$product}\n * Version: {$version}\n */\n" );
		$zip->addFromString( $product . '/readme.txt', 'Stable tag: ' . $version . "\n" . str_repeat( 'payload ', 200 ) );
		$zip->close();

		$id = ReleaseRegistry::create_with_package(
			array(
				'product_key'   => $product,
				'version'       => $version,
				'release_notes' => '<p>Notes of ' . $product . ' ' . $version . '</p>',
			),
			$path
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'test' ) );

		return $id;
	}

	/**
	 * Publish a release of product B stored by URL.
	 *
	 * @param string $version Version.
	 * @return int Release ID.
	 */
	private function url_release_b( string $version ): int {
		add_filter( 'wplc_verify_url_release_on_publish', '__return_false' );
		add_filter( 'wplc_require_package_resolver', '__return_false' );
		$id = ReleaseRegistry::create(
			array(
				'product_key' => self::PRODUCT_B,
				'version'     => $version,
				'storage'     => 'url',
				'package_url' => 'https://example.org/downloads/' . self::PRODUCT_B . '-' . $version . '.zip',
				'sha256'      => str_repeat( 'b', 64 ),
			)
		);
		$this->assertIsInt( $id );
		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'test' ) );

		return $id;
	}

	/**
	 * Set the publication time of a release.
	 *
	 * @param int $release_id Release ID.
	 * @param int $timestamp  Unix time.
	 * @return void
	 */
	private function published_at( int $release_id, int $timestamp ): void {
		global $wpdb;
		$wpdb->update( $this->tables()['releases'], array( 'published_at' => gmdate( 'Y-m-d H:i:s', $timestamp ) ), array( 'id' => $release_id ) );
		ReleaseRegistry::flush_cache( (string) ReleaseRegistry::get( $release_id )['product_key'] );
	}

	/**
	 * Run a request whose handler ends with a redirect or a status header.
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
	 * Status of a request: the halt status, or the REST status.
	 *
	 * @param WPLC_Test_Halt|WP_REST_Response $result Result of run_request().
	 * @return string "redirect", "status 200", "status 404", "rest 404" ...
	 */
	private function outcome( $result ): string {
		if ( $result instanceof WPLC_Test_Halt ) {
			return 'redirect' === $result->kind ? 'redirect' : 'status ' . $result->status;
		}

		return 'rest ' . $result->get_status();
	}

	/**
	 * POST /updates/check as an installation.
	 *
	 * @param array<string,mixed> $site    Installation.
	 * @param string              $product Product key sent.
	 * @param array<string,mixed> $extra   Additional fields.
	 * @return WP_REST_Response
	 */
	private function check( array $site, string $product, array $extra = array() ): WP_REST_Response {
		return $this->dispatch(
			$this->api_request(
				'POST',
				'/wplc/v1/updates/check',
				array_merge(
					array(
						'product_key'     => $product,
						'current_version' => '0.1.0',
						'download'        => true,
					),
					$extra
				),
				$site
			)
		);
	}

	/**
	 * A download grant row of a license, as the update check would store it.
	 *
	 * @param int    $license_id    License.
	 * @param int    $activation_id Installation.
	 * @param string $package_url   Stored package URL.
	 * @return string Plain token.
	 */
	private function grant( int $license_id, int $activation_id, string $package_url ): string {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->insert(
			$this->tables()['download_tokens'],
			array(
				'license_id'    => $license_id,
				'activation_id' => $activation_id,
				'token_hash'    => hash( 'sha256', $token ),
				'package_url'   => $package_url,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 300 ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $token;
	}

	/**
	 * Rendered download form of a license.
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
	 * Post the account download form.
	 *
	 * @param int    $license_id License ID.
	 * @param string $version    Version or empty.
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
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = array();
		}
		$this->fail( 'The download handler returned.' );
	}

	/**
	 * Assert that a text names nothing of product B.
	 *
	 * @param string $text    Text.
	 * @param string $context Where it comes from.
	 * @return void
	 */
	private function assertNothingOfB( string $text, string $context ): void {
		foreach ( array( self::NAME_B, self::PRODUCT_B, '2.5.0', '2.6.0', '2.7.0' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $text, $context . ' must not name "' . $needle . '".' );
		}
	}

	// ------------------------------------------------------------------
	// The test.
	// ------------------------------------------------------------------

	public function test_pk03_a_license_for_product_a_reaches_nothing_of_product_b(): void {
		$release_a = $this->release( self::PRODUCT_A, '1.1.0' );
		$release_b = $this->release( self::PRODUCT_B, '2.5.0' );
		$url_b     = $this->url_release_b( '2.6.0' );

		$license_a = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => self::PRODUCT_A ) );
		$site_a    = $this->seed_activation( $license_a['id'] );

		// 1. Update check: product B with the installation of A is refused without naming B.
		$refused = $this->check( $site_a, self::PRODUCT_B );
		$this->assertApiError( 403, 'license_inactive', $refused );
		$this->assertNothingOfB( (string) wp_json_encode( $refused->get_data() ), 'The refused update check' );
		// Asking A's own check for a version of B gives no package of B either.
		$own = $this->check( $site_a, self::PRODUCT_A, array( 'target_version' => '2.5.0' ) );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( self::PRODUCT_A, $own->get_data()['data']['product_key'] );
		$this->assertSame( '', (string) ( $own->get_data()['data']['package'] ?? '' ), 'No package for a version A does not have.' );
		$this->assertSame( '1.1.0', $this->check( $site_a, self::PRODUCT_A )->get_data()['data']['new_version'] );
		$this->assertNothingOfB( (string) wp_json_encode( $own->get_data() ), 'The update check of A' );

		// 2. Download grant of A that points to a package of B (local placeholder or URL): never delivered.
		foreach ( array( 'local' => PackageStream::release_url( $release_b ), 'url' => (string) ReleaseRegistry::get( $url_b )['package_url'] ) as $storage => $package_url ) {
			$token  = $this->grant( $license_a['id'], $site_a['id'], $package_url );
			$result = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) );
			$this->assertSame( 'status 404', $this->outcome( $result ), 'Grant of A for the ' . $storage . ' package of B.' );
			$head = $this->run_request( new WP_REST_Request( 'HEAD', '/wplc/v1/updates/download/' . $this->grant( $license_a['id'], $site_a['id'], $package_url ) ) );
			$this->assertSame( 'status 404', $this->outcome( $head ), 'HEAD of a grant of A for the ' . $storage . ' package of B.' );
		}
		// The same grant for A's own package works (the fixture is sound).
		$own_grant = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $this->grant( $license_a['id'], $site_a['id'], PackageStream::release_url( $release_a ) ) ) );
		$this->assertSame( 'redirect', $this->outcome( $own_grant ) );

		// 3. Package stream: a stream token of license A for the release of B is not served.
		foreach ( array( PackageStream::SOURCE_UPDATE, PackageStream::SOURCE_CUSTOMER ) as $source ) {
			$stream = PackageStream::issue( ReleaseRegistry::get( $release_b ), $license_a['id'], $source );
			$this->assertNotSame( '', $stream );
			$served = $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $stream ) );
			$this->assertSame( 'rest 404', $this->outcome( $served ), 'Stream of A for B (' . $source . ').' );
		}
		$own_stream = PackageStream::issue( ReleaseRegistry::get( $release_a ), $license_a['id'], PackageStream::SOURCE_UPDATE );
		$this->assertSame( 'status 200', $this->outcome( $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $own_stream ) ) ) );

		// 4. Customer account: download form, version list and download only of A.
		wp_set_current_user( $this->customer );
		$this->assertSame( array( '1.1.0' ), array_column( CustomerDownload::downloadable_versions( $this->license( $license_a['id'] ) ), 'version' ) );
		$button = $this->button_html( $license_a['id'] );
		$this->assertStringContainsString( 'Download version 1.1.0', $button );
		$this->assertNothingOfB( $button, 'The download form of A' );
		foreach ( array( '2.5.0', '2.6.0' ) as $version ) {
			$this->assertInstanceOf( WPDieException::class, $this->post_download( $license_a['id'], $version ), 'Version ' . $version . ' of B through the license of A.' );
		}
		$this->assertNothingOfB( $this->render_portal( $this->customer ), 'The account of the customer of A' );
		$this->assertNothingOfB( $this->render_site_overview(), 'The site overview of the customer of A' );

		// 5. The placeholder address of the client (`?wplc-package=`) is no download address of the server.
		$this->assertNotContains( 'wplc-package', $GLOBALS['wp']->public_query_vars );
		$streams_before = count( $this->stream_rows() );
		$_GET           = array( 'wplc-package' => self::PRODUCT_B );
		// The request itself: WordPress answers it as an ordinary front-end request (no plugin query var).
		remove_all_filters( 'status_header' );
		remove_all_filters( 'wp_redirect' );
		$this->go_to( home_url( '/?wplc-package=' . self::PRODUCT_B ) );
		$this->assertArrayNotHasKey( 'wplc-package', $GLOBALS['wp']->query_vars );
		$this->halt_instead_of_exit();
		ob_start();
		try {
			CustomerDownload::maybe_handle_frontend();
			$halted = '';
		} catch ( WPLC_Test_Halt $halt ) {
			$halted = $halt->kind . ' ' . $halt->location;
		} finally {
			$output = (string) ob_get_clean();
		}
		$this->assertSame( '', $halted, 'Nothing is delivered or redirected for the placeholder address.' );
		$this->assertSame( '', $output );
		$this->assertCount( $streams_before, $this->stream_rows(), 'No download is recorded for the placeholder address.' );

		// 6. Release overviews: the changelog of A names nothing of B, the releases screen is closed to customers.
		$this->assertStringContainsString( 'Notes of alpha-addon 1.1.0', Blocks::changelog_html( self::PRODUCT_A, 10 ) );
		$this->assertNothingOfB( Blocks::changelog_html( self::PRODUCT_A, 10 ), 'The changelog of A' );
		$this->assertFalse( current_user_can( \WPLC\Admin\Capabilities::cap( 'publish_releases' ) ) );
		$this->expectException( WPDieException::class );
		\WPLC\Admin\ReleaseAdmin::render();
	}

	public function test_pk03_a_suspended_or_expired_license_for_b_does_not_open_b(): void {
		global $wpdb;
		$this->release( self::PRODUCT_A, '1.1.0' );
		$paid_for = $this->release( self::PRODUCT_B, '2.5.0' );
		$later    = $this->release( self::PRODUCT_B, '2.7.0' );

		$license_a = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => self::PRODUCT_A ) );
		$site_a    = $this->seed_activation( $license_a['id'] );
		$license_b = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => self::PRODUCT_B ) );
		$site_b    = $this->seed_activation( $license_b['id'] );
		wp_set_current_user( $this->customer );

		// Suspended: nothing at all.
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'suspended' ), array( 'id' => $license_b['id'] ) );
		$this->assertNoPackageOfB( $license_b['id'], $site_b, $site_a, 'suspended' );
		$this->assertSame( '', $this->button_html( $license_b['id'] ) );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license_b['id'] ) );

		// Expired 30 days ago: 2.5.0 was released while it was paid (A-116 keeps it in the account), 2.7.0 afterwards.
		$this->published_at( $paid_for, time() - 40 * DAY_IN_SECONDS );
		$this->published_at( $later, time() - 10 * DAY_IN_SECONDS );
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			),
			array( 'id' => $license_b['id'] )
		);
		$this->assertNoPackageOfB( $license_b['id'], $site_b, $site_a, 'expired' );
		$this->assertSame( array( '2.5.0' ), array_column( CustomerDownload::downloadable_versions( $this->license( $license_b['id'] ) ), 'version' ) );
		$this->assertStringNotContainsString( '2.7.0', $this->button_html( $license_b['id'] ) );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license_b['id'], '2.7.0' ), 'A version of B released after the end needs a renewal.' );
		// The active license of A does not lend B its access.
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license_a['id'], '2.7.0' ) );
		$this->assertStringNotContainsString( '2.7.0', $this->render_portal( $this->customer ) );

		// With the A-116 policy switched off the expired license of B gets nothing either.
		add_filter( 'wplc_expired_license_download', '__return_false' );
		$this->assertSame( array(), CustomerDownload::downloadable_versions( $this->license( $license_b['id'] ) ) );
		$this->assertInstanceOf( WPDieException::class, $this->post_download( $license_b['id'] ) );
	}

	/**
	 * Update check and grants of B give no package for a license of B without access.
	 *
	 * @param int                 $license_b License of B.
	 * @param array<string,mixed> $site_b    Installation of B.
	 * @param array<string,mixed> $site_a    Installation of A.
	 * @param string              $context   Status under test.
	 * @return void
	 */
	private function assertNoPackageOfB( int $license_b, array $site_b, array $site_a, string $context ): void {
		$check = $this->check( $site_b, self::PRODUCT_B );
		$this->assertSame( 200, $check->get_status(), $context );
		$data = $check->get_data()['data'];
		$this->assertFalse( $data['license_active'], $context );
		$this->assertSame( '', $data['package'], $context . ': no package.' );
		$this->assertArrayNotHasKey( 'download_expires_at', $data, $context . ': no grant.' );
		// The installation of A cannot ask for B.
		$this->assertApiError( 403, 'license_inactive', $this->check( $site_a, self::PRODUCT_B ) );

		// An old grant of B is not redeemed any more.
		$latest = ReleaseRegistry::latest_published( self::PRODUCT_B );
		$token  = $this->grant( $license_b, (int) $site_b['id'], PackageStream::release_url( (int) $latest['id'] ) );
		$this->assertSame( 'status 404', $this->outcome( $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/updates/download/' . $token ) ) ), $context );
		$stream = PackageStream::issue( $latest, $license_b, PackageStream::SOURCE_UPDATE );
		$this->assertSame( 'rest 404', $this->outcome( $this->run_request( new WP_REST_Request( 'GET', '/wplc/v1/packages/' . $stream ) ) ), $context );
	}

	/**
	 * Rows of the package stream table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function stream_rows(): array {
		global $wpdb;

		return (array) $wpdb->get_results( "SELECT * FROM {$this->tables()['package_streams']} WHERE source = 'customer'", ARRAY_A );
	}

	/**
	 * Rendered site overview of the current customer.
	 *
	 * @return string
	 */
	private function render_site_overview(): string {
		if ( ! method_exists( \WPLC\Account\SiteOverview::class, 'render' ) ) {
			return '';
		}
		ob_start();
		\WPLC\Account\SiteOverview::render();

		return (string) ob_get_clean();
	}
}
