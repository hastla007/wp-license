<?php
/**
 * Signed refusals of this server, built by its own code, reach the client library of this repository.
 *
 * AL-3: a release through the real path (ActivationManager::deactivate(), used by the customer account and
 * the admin) is answered with the signed refusal of Authenticator, and the client locks.
 * AL-6: the signed `409 site_mismatch` of ActivationService is verified by the client; an unsigned one
 * (older servers) keeps the credentials as before.
 *
 * What stands in here: an in-memory database for the calls ActivationManager makes, and the HTTP layer
 * (the WP_Error of the server is turned into the REST body WordPress would send). The whole REST path
 * with authentication runs in tests/integration/ClientLicenseIntegrationTest.php.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\Authenticator;
use WPLC\Client\LicenseClient;
use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;
use WPLC\License\ActivationManager;
use WPLC\License\AuditReason;
use WPLC\Security\ResponseSigner;

require_once dirname( __DIR__ ) . '/Client/client-test-kit.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

/**
 * In-memory activations table for the queries of ActivationManager::deactivate().
 */
final class WPLC_Refusal_Interop_Db {
	/** @var string */
	public $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public $activations = array();
	/** @var array<int,array<string,mixed>> */
	public $audit = array();
	/** @var array<int,mixed> */
	private $args = array();

	public function prepare( $query, ...$args ) {
		$this->args = $args;
		return $query;
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			return '1';
		}
		$row = $this->activations[ (int) $this->args[0] ] ?? null;
		return is_array( $row ) && $row['status'] === $this->args[1] ? (string) $row['license_id'] : null;
	}

	public function get_row( $query, $output = null ) {
		$row = $this->activations[ (int) $this->args[0] ] ?? null;
		return is_array( $row ) && $row['status'] === $this->args[1] ? $row : null;
	}

	public function get_results( $query, $output = null ) {
		return array();
	}

	public function update( $table, $data, $where ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->activations[ $id ] ) || $this->activations[ $id ]['status'] !== $where['status'] ) {
			return 0;
		}
		$this->activations[ $id ] = array_merge( $this->activations[ $id ], $data );
		return 1;
	}

	public function insert( $table, $data ) {
		$this->audit[] = $data;
		return 1;
	}
}

final class ServerRefusalInteropTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	/** @var WPLC_Client_Test_Kit */
	private $kit;

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		$this->kit = new WPLC_Client_Test_Kit();
		// The signing key of this server is the key the add-on pins (the fake server of the kit uses the same seed).
		$property = new ReflectionProperty( ResponseSigner::class, 'secret_key' );
		$property->setAccessible( true );
		$property->setValue( null, ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x07", 32 ) ) ) );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		ResponseSigner::reset();
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	/** REST body WordPress sends for a WP_Error of a route. */
	private static function rest_error( WP_Error $error ): array {
		$data = (array) $error->get_error_data();

		return WPLC_Test_Server::json( (int) $data['status'], array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $data ) );
	}

	/** @return array{0:LicenseManager,1:array<string,mixed>} Manager and stored credentials. */
	private function activated(): array {
		$manager = $this->kit->manager();
		$this->assertInstanceOf( LicenseStatus::class, $manager->activate( self::KEY ) );

		return array( $manager, $this->kit->credentials() );
	}

	/** @return array<int,array{0:string,1:string,2:int}> Release paths: reason, audit action, actor. */
	public function data_releases(): array {
		return array(
			'customer account (CustomerPortal::deactivate_site)'          => array( AuditReason::DEACTIVATED_BY_OWNER, 'site_deactivated', 7 ),
			'admin (SupportActions, wp wplc activation release)'          => array( 'Released by support', 'admin_site_released', 1 ),
			'end of the license (Retention, LicenseLifecycle via cron)'   => array( 'License ended', 'site_deactivated_license_ended', 0 ),
		);
	}

	/**
	 * @dataProvider data_releases
	 */
	public function test_al3_a_release_through_activation_manager_reaches_the_client_as_signed_refusal( string $reason, string $action, int $actor ): void {
		list( $manager, $credentials ) = $this->activated();
		$db                            = new WPLC_Refusal_Interop_Db();
		$db->activations[41]           = array(
			'id'                => 41,
			'license_id'        => 10,
			'installation_uuid' => $credentials['installation_id'],
			'environment'       => 'production',
			'staging_parent_id' => null,
			'status'            => 'active',
			'site_url'          => 'https://shop.example',
			'deactivated_at'    => null,
		);
		$GLOBALS['wpdb'] = $db;

		$this->assertTrue( ActivationManager::deactivate( 41, $reason, $actor, $action ) );
		$row = $db->activations[41];
		$this->assertSame( 'deactivated', $row['status'] );
		$this->assertSame( $action, $db->audit[0]['action'] );

		// The next signed request of the installation: authenticate() finds the row no longer active.
		$this->kit->server->queue[] = static function ( array $request ) use ( $row, $credentials ) {
			return self::rest_error( Authenticator::deactivated_refusal( $row, $credentials['installation_secret'], $request['headers']['x-wplc-nonce'] ) );
		};
		$result = $manager->refresh();

		$this->assertSame( 'activation_deactivated', $result->get_error_code() );
		$this->assertTrue( LicenseClient::is_signed_refusal( $result ), 'The refusal of this server verifies against the pinned key.' );
		$this->assertSame( LicenseManager::CLASS_LOCK, LicenseManager::error_class( $result ) );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_DEACTIVATED, $manager->status()->state );
	}

	public function test_al6_the_signed_site_mismatch_of_this_server_is_verified_by_the_client(): void {
		list( $manager, $credentials ) = $this->activated();
		$row                           = array(
			'id'                  => 41,
			'installation_uuid'   => $credentials['installation_id'],
			'installation_secret' => $credentials['installation_secret'],
			'status'              => 'active',
			'site_url'            => 'https://moved.example',
		);
		$answer                        = static function ( bool $signed ) use ( $row ): Closure {
			return static function ( array $request ) use ( $row, $signed ) {
				$error = ActivationService::site_mismatch_error( array_merge( $row, array( 'request_nonce' => $request['headers']['x-wplc-nonce'] ) ) );
				$data  = (array) $error->get_error_data();
				if ( ! $signed ) {
					// What servers before AL-6 sent.
					unset( $data['signed'] );
				}
				return self::rest_error( new WP_Error( $error->get_error_code(), $error->get_error_message(), $data ) );
			};
		};

		// The signed data says site_match: false and does not disclose the stored address.
		$probe = ActivationService::site_mismatch_error( array_merge( $row, array( 'request_nonce' => 'n' ) ) );
		$this->assertSame( 409, $probe->get_error_data()['status'] );
		$this->assertFalse( json_decode( $probe->get_error_data()['signed']['payload'], true )['data']['site_match'] );
		$this->assertStringNotContainsString( 'moved.example', (string) json_encode( $probe->get_error_data(), JSON_UNESCAPED_SLASHES ) );

		// Unsigned (older server): nothing changes on the site, as before.
		$this->kit->server->queue[] = $answer( false );
		$result                     = $manager->deactivate();
		$this->assertSame( 'site_mismatch', $result->get_error_code() );
		$this->assertFalse( LicenseClient::is_signed_site_mismatch( $result ) );
		$this->assertTrue( $manager->client()->is_activated() );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

		// Signed by this server: the local data goes, the activation of the other address stays.
		$this->kit->server->queue[] = $answer( true );
		$this->assertSame( array( 'status' => 'removed_locally', 'reason' => 'site_mismatch' ), $manager->deactivate() );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
	}
}
