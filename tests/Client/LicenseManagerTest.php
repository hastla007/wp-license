<?php
/**
 * LicenseManager facade against a fake license server that signs like the real one.
 *
 * Every test names the task it belongs to (docs/TODO.md).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\LicenseClient;
use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;

require_once __DIR__ . '/client-test-kit.php';

final class LicenseManagerTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	/** @var WPLC_Client_Test_Kit */
	private $kit;

	protected function setUp(): void {
		$this->kit                    = new WPLC_Client_Test_Kit();
		$GLOBALS['wplc_test_actions'] = array();
		$GLOBALS['wplc_test_cron']    = array();
	}

	private function activated(): LicenseManager {
		$manager = $this->kit->manager();
		$status  = $manager->activate( self::KEY );
		$this->assertInstanceOf( LicenseStatus::class, $status, is_wp_error( $status ) ? $status->get_error_code() : '' );
		$this->kit->server->requests  = array();
		$GLOBALS['wplc_test_actions'] = array();

		return $manager;
	}

	/** Signed refusal of a released installation, as `POST /updates/check` or any signed route sends it. */
	private function signed_refusal(): Closure {
		$server = $this->kit->server;

		return static function ( array $request ) use ( $server ) {
			$data = array(
				'activation_id'     => 41,
				'installation_id'   => '',
				'activation_status' => 'deactivated',
				'license_status'    => 'deactivated',
				'access'            => false,
				'entitlements'      => array(),
				'server_time'       => gmdate( 'Y-m-d H:i:s', $server->now ),
			);
			return WPLC_Test_Server::error( 403, 'activation_deactivated', array( 'signed' => $server->envelope( $request, $data ) ) );
		};
	}

	// ------------------------------------------------------------------
	// B-73: facade basics.
	// ------------------------------------------------------------------

	public function test_b73_status_and_feature_gate_after_activation(): void {
		$manager = $this->kit->manager();
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );

		$status = $manager->activate( self::KEY );

		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertTrue( $status->access );
		$this->assertSame( 'active', $status->license_status );
		$this->assertSame( 'production', $status->environment );
		$this->assertSame( 1, $status->site_limit );
		$this->assertSame( $manager->client()->installation_id(), $status->installation_id );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->kit->now + 14 * DAY_IN_SECONDS ), $status->cache_expires_at );
		$this->assertSame( $this->kit->now, $status->last_checked );

		// Value types: true and a number above zero grant, false, zero and an absent key do not.
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->assertTrue( $manager->is_entitled( 'feature.limit' ) );
		$this->assertFalse( $manager->is_entitled( 'feature.off' ) );
		$this->assertFalse( $manager->is_entitled( 'feature.zero' ) );
		$this->assertFalse( $manager->is_entitled( 'feature.unknown' ) );
		$this->assertSame( 3, $manager->status()->entitlement( 'feature.limit' ) );
		$this->assertSame( 7, $manager->status()->entitlement( 'feature.unknown', 7 ) );

		// The gate never calls the server.
		$this->assertCount( 1, $this->kit->server->requests );
		$this->assertSame( array( array( 'test_addon_license_status_changed', 'active', 'unlicensed', $status->state ) ), array_map( static function ( $a ) { return array( $a[0], $a[1], $a[2], $a[3]->state ); }, $GLOBALS['wplc_test_actions'] ) );
	}

	public function test_t134_facade_refuses_to_work_without_a_pinned_key(): void {
		$manager = $this->kit->manager( array( 'public_keys' => array() ) );

		$this->assertSame( LicenseStatus::STATE_MISCONFIGURED, $manager->status()->state );
		$this->assertSame( 'wplc_configuration_invalid', $manager->activate( self::KEY )->get_error_code() );
		$this->assertSame( array(), $this->kit->server->requests );
		$this->assertSame( 'error', $manager->notice()['type'] );
	}

	// ------------------------------------------------------------------
	// A-109, B-70, B-72: the table "lock / keep cache / re-activate".
	// ------------------------------------------------------------------

	/**
	 * Every unsigned error keeps the rights until the signed cache_expires_at.
	 *
	 * @dataProvider data_unsigned_errors
	 *
	 * @param mixed  $answer     Scripted answer of the server.
	 * @param string $code       Expected error code.
	 * @param string $class      Expected class.
	 * @param bool   $reactivate Whether the notice must ask for the key.
	 */
	public function test_unsigned_errors_never_take_rights_away( $answer, string $code, string $class, bool $reactivate ): void {
		$manager                    = $this->activated();
		$credentials                = $this->kit->credentials();
		$this->kit->server->queue[] = $answer;
		// request_expired is retried once by the client; answer the retry in the same way.
		$this->kit->server->queue[] = $answer;
		$this->kit->advance( DAY_IN_SECONDS );

		$result = $manager->refresh();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $class, LicenseManager::error_class( $result ) );
		$status = $manager->status();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ), 'Rights stay.' );
		$this->assertSame( $code, $status->last_error );
		$this->assertSame( $reactivate, $status->needs_reactivation );
		$this->assertSame( $credentials['installation_id'], $this->kit->credentials()['installation_id'], 'Credentials stay.' );
		$this->assertSame( array(), $GLOBALS['wplc_test_actions'], 'No status change.' );
		if ( $reactivate ) {
			$this->assertStringContainsString( 'Enter the license key again', $manager->notice()['text'] );
		}

		// ...but only until the signed deadline of the snapshot: the failed attempts do not extend it.
		$this->kit->advance( 13 * DAY_IN_SECONDS - 1 );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->advance( 1 );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
	}

	/** @return array<string,array{0:mixed,1:string,2:string,3:bool}> */
	public function data_unsigned_errors(): array {
		$keep = LicenseManager::CLASS_KEEP;
		$re   = LicenseManager::CLASS_REACTIVATE;

		return array(
			'timeout'                         => array( new WP_Error( 'http_request_failed', 'cURL error 28' ), 'wplc_connection_failed', $keep, false ),
			'500'                             => array( WPLC_Test_Server::error( 500, 'response_encoding_failed' ), 'response_encoding_failed', $keep, false ),
			'503 retry_later'                 => array( WPLC_Test_Server::error( 503, 'retry_later' ), 'retry_later', $keep, false ),
			'503 replay store'                => array( WPLC_Test_Server::error( 503, 'replay_store_unavailable' ), 'replay_store_unavailable', $keep, false ),
			'429 rate_limited'                => array( WPLC_Test_Server::error( 429, 'rate_limited' ), 'rate_limited', $keep, false ),
			'firewall html 403'               => array( WPLC_Test_Server::json( 403, '<html>Access denied</html>', array( 'content-type' => 'text/html' ) ), 'wplc_blocked_by_firewall', $keep, false ),
			'html with status 200'            => array( WPLC_Test_Server::json( 200, '<html>captcha</html>', array( 'content-type' => 'text/html' ) ), 'wplc_blocked_by_firewall', $keep, false ),
			'not json'                        => array( WPLC_Test_Server::json( 200, 'OK' ), 'wplc_response_invalid', $keep, false ),
			'json without envelope'           => array( WPLC_Test_Server::json( 200, array( 'data' => array( 'access' => false ) ) ), 'wplc_signature_invalid', $keep, false ),
			'401 invalid_request_signature'   => array( WPLC_Test_Server::error( 401, 'invalid_request_signature' ), 'invalid_request_signature', $keep, false ),
			'401 request_expired'             => array( WPLC_Test_Server::error( 401, 'request_expired', array( 'server_time' => 1800086400 ) ), 'request_expired', $keep, false ),
			'409 request_replayed'            => array( WPLC_Test_Server::error( 409, 'request_replayed' ), 'request_replayed', $keep, false ),
			'unsigned activation_deactivated' => array( WPLC_Test_Server::error( 403, 'activation_deactivated' ), 'activation_deactivated', $keep, false ),
			'unsigned license_inactive'       => array( WPLC_Test_Server::error( 403, 'license_inactive' ), 'license_inactive', $keep, false ),
			'unknown code'                    => array( WPLC_Test_Server::error( 418, 'something_new' ), 'something_new', $keep, false ),
			'401 activation_not_found'        => array( WPLC_Test_Server::error( 401, 'activation_not_found' ), 'activation_not_found', $re, true ),
			'401 installation_secret_invalid' => array( WPLC_Test_Server::error( 401, 'installation_secret_invalid' ), 'installation_secret_invalid', $re, true ),
			'404 license_not_found'           => array( WPLC_Test_Server::error( 404, 'license_not_found' ), 'license_not_found', $re, true ),
		);
	}

	/**
	 * A forged or foreign "signed" answer is just another unsigned error.
	 *
	 * @dataProvider data_forged_answers
	 *
	 * @param string $kind Kind of forgery.
	 */
	public function test_t134_forged_answers_never_take_rights_away( string $kind ): void {
		$manager = $this->activated();
		$server  = $this->kit->server;
		$other   = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );
		$locked  = $server->entitlement( array( 'license_status' => 'revoked', 'access' => false, 'entitlements' => array() ) );
		$answers = array(
			'signed with another key' => $server->signed( $locked, array( 'secret_key' => $other->secret_key ) ),
			'server signature missing' => $server->signed( $locked, array( 'strip' => array( 'payload_signature_ed25519' ) ) ),
			'legacy members only'      => $server->signed( $locked, array( 'strip' => array( 'payload', 'payload_signature', 'payload_signature_ed25519' ) ) ),
			'recorded for another nonce' => $server->signed( $locked, array( 'nonce' => 'recorded-earlier' ) ),
			'other product'            => $server->signed( array_merge( $locked, array( 'product_key' => 'other-addon' ) ) ),
		);
		$server->queue[] = $answers[ $kind ];

		$result = $manager->refresh();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( LicenseManager::CLASS_KEEP, LicenseManager::error_class( $result ) );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->assertTrue( $manager->client()->is_activated() );
	}

	/** @return array<string,array{0:string}> */
	public function data_forged_answers(): array {
		return array(
			'signed with another key'    => array( 'signed with another key' ),
			'server signature missing'   => array( 'server signature missing' ),
			'legacy members only'        => array( 'legacy members only' ),
			'recorded for another nonce' => array( 'recorded for another nonce' ),
			'other product'              => array( 'other product' ),
		);
	}

	/**
	 * A signed status locks at once; the credentials stay so that a renewal is picked up.
	 *
	 * @dataProvider data_signed_states
	 *
	 * @param array<string,mixed> $overrides Signed data.
	 * @param string              $state     Expected state.
	 * @param bool                $access    Expected access.
	 */
	public function test_b72_signed_status_decides( array $overrides, string $state, bool $access ): void {
		$manager                    = $this->activated();
		$server                     = $this->kit->server;
		$server->queue[]            = $server->signed( $server->entitlement( $overrides ) );

		$status = $manager->refresh();

		$this->assertInstanceOf( LicenseStatus::class, $status );
		$this->assertSame( $state, $status->state );
		$this->assertSame( $access, $status->access );
		$this->assertSame( $access, $manager->is_entitled( 'feature.flag' ) );
		$this->assertTrue( $manager->client()->is_activated(), 'Credentials stay: the next check can report a renewal.' );
		if ( LicenseStatus::STATE_ACTIVE !== $state ) {
			$this->assertSame( 'test_addon_license_status_changed', $GLOBALS['wplc_test_actions'][0][0] );
			$this->assertSame( array( $state, 'active' ), array( $GLOBALS['wplc_test_actions'][0][1], $GLOBALS['wplc_test_actions'][0][2] ) );
			$this->assertSame( $state, $manager->notice()['state'] );
		} else {
			$this->assertNull( $manager->notice() );
		}

		// A renewal unlocks with the next signed answer.
		$this->kit->advance( 60 );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string,2:bool}> */
	public function data_signed_states(): array {
		$none = array( 'access' => false, 'entitlements' => array(), 'access_until' => null );

		return array(
			'active'            => array( array(), LicenseStatus::STATE_ACTIVE, true ),
			'past_due in grace' => array( array( 'license_status' => 'past_due' ), LicenseStatus::STATE_GRACE, true ),
			'active past paid_until, in grace' => array( array( 'paid_until' => '2020-01-01 00:00:00' ), LicenseStatus::STATE_GRACE, true ),
			'lifetime'          => array( array( 'paid_until' => null, 'access_until' => null, 'grace_until' => null ), LicenseStatus::STATE_ACTIVE, true ),
			'past_due after grace' => array( array_merge( $none, array( 'license_status' => 'past_due' ) ), LicenseStatus::STATE_EXPIRED, false ),
			'expired'           => array( array_merge( $none, array( 'license_status' => 'expired' ) ), LicenseStatus::STATE_EXPIRED, false ),
			'suspended'         => array( array_merge( $none, array( 'license_status' => 'suspended' ) ), LicenseStatus::STATE_SUSPENDED, false ),
			'revoked'           => array( array_merge( $none, array( 'license_status' => 'revoked' ) ), LicenseStatus::STATE_REVOKED, false ),
			'pending'           => array( array_merge( $none, array( 'license_status' => 'pending' ) ), LicenseStatus::STATE_INACTIVE, false ),
			'access is not a boolean true' => array( array( 'access' => 1 ), LicenseStatus::STATE_INACTIVE, false ),
			'site_match false'  => array( array( 'site_match' => false ), LicenseStatus::STATE_SITE_CHANGED, false ),
		);
	}

	public function test_b70_signed_deactivation_locks_and_drops_the_credentials(): void {
		$deactivated = array( 'activation_status' => 'deactivated', 'license_status' => 'deactivated', 'access' => false, 'entitlements' => array(), 'deactivated_at' => '2026-11-01 00:00:00' );

		// As signed 200 of the entitlement check.
		$manager                    = $this->activated();
		$server                     = $this->kit->server;
		$server->queue[]            = $server->signed( $server->entitlement( $deactivated ) );
		$status                     = $manager->refresh();
		$this->assertSame( LicenseStatus::STATE_DEACTIVATED, $status->state );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertArrayNotHasKey( 'envelope', $this->kit->store->data['test_addon_license_state'] );
		$this->assertSame( array( 'deactivated', 'active' ), array( $GLOBALS['wplc_test_actions'][0][1], $GLOBALS['wplc_test_actions'][0][2] ) );
		$this->assertStringContainsString( 'was released', $manager->notice()['text'] );

		// As signed refusal (403 with data.signed) of any signed route.
		$this->kit                  = new WPLC_Client_Test_Kit();
		$manager                    = $this->activated();
		$this->kit->server->queue[] = $this->signed_refusal();
		$result                     = $manager->refresh();
		$this->assertSame( LicenseManager::CLASS_LOCK, LicenseManager::error_class( $result ) );
		$this->assertSame( LicenseStatus::STATE_DEACTIVATED, $manager->status()->state );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertFalse( $manager->client()->is_activated() );

		// Entering the key again activates with a new installation.
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->activate( self::KEY )->state );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	// ------------------------------------------------------------------
	// B-16, B-68, B-70: the durable cache and its signed deadline.
	// ------------------------------------------------------------------

	public function test_b68_the_deadline_is_the_signed_cache_expires_at_not_a_local_timer(): void {
		$manager = $this->kit->manager();
		$server  = $this->kit->server;
		// Three days of paid time and no grace period left: the server caps the offline window at access_until.
		$server->queue[] = static function ( array $request ) use ( $server ) {
			$server->secrets[ $request['body']['installation_id'] ] = $request['body']['installation_secret'];
			$end = gmdate( 'Y-m-d H:i:s', $server->now + 3 * DAY_IN_SECONDS );
			return WPLC_Test_Server::json( 200, $server->envelope( $request, $server->entitlement( array( 'paid_until' => $end, 'access_until' => $end, 'grace_until' => null, 'cache_expires_at' => $end ) ) ) );
		};
		$manager->activate( self::KEY );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

		// The site blocks the license server from now on.
		for ( $day = 1; $day <= 2; $day++ ) {
			$this->kit->advance( DAY_IN_SECONDS );
			$server->queue = array( new WP_Error( 'http_request_failed', 'blocked' ) );
			$manager->refresh();
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ), 'day ' . $day );
		}
		$this->kit->advance( DAY_IN_SECONDS );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'Blocking the server does not outlast the paid time.' );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
		$this->assertSame( 'error', $manager->notice()['type'] );

		// A new signed answer restores the rights.
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
	}

	public function test_b16_cache_is_an_option_that_survives_and_is_verified_on_every_read(): void {
		$manager = $this->activated();
		$state   = $this->kit->store->data['test_addon_license_state'];
		$this->assertSame( array( 'payload', 'payload_signature', 'payload_signature_ed25519', 'key_id' ), array_keys( $state['envelope'] ) );

		// A second instance (next page view) reads the same snapshot without a request.
		$this->assertTrue( $this->kit->manager()->is_entitled( 'feature.flag' ) );
		$this->assertSame( array(), $this->kit->server->requests );

		// Someone edits the stored rights in the database.
		$forged                        = $state;
		$forged['envelope']['payload'] = str_replace( '"feature.off":false', '"feature.off":true', $state['envelope']['payload'] );
		$this->assertNotSame( $state['envelope']['payload'], $forged['envelope']['payload'] );
		$this->kit->store->data['test_addon_license_state'] = $forged;
		$tampered = $this->kit->manager();
		$this->assertFalse( $tampered->is_entitled( 'feature.off' ) );
		$this->assertFalse( $tampered->is_entitled( 'feature.flag' ), 'The whole snapshot is void.' );
		$this->assertSame( LicenseStatus::STATE_STALE, $tampered->status()->state );

		// ...and recomputes the HMAC with the installation secret found next to it: the server signature still fails.
		$forged['envelope']['payload_signature']            = hash_hmac( 'sha256', $forged['envelope']['payload'], $this->kit->credentials()['installation_secret'] );
		$this->kit->store->data['test_addon_license_state'] = $forged;
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.off' ) );

		// A longer deadline next to the snapshot has no effect: the deadline is read from the signed payload.
		$this->kit->store->data['test_addon_license_state'] = array_merge( $state, array( 'cache_expires_at' => '2099-01-01 00:00:00' ) );
		$this->kit->advance( 14 * DAY_IN_SECONDS );
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.flag' ) );
	}

	public function test_a_snapshot_of_another_installation_is_void(): void {
		$manager = $this->activated();
		$state   = $this->kit->store->data['test_addon_license_state'];

		// Same site, new activation (other UUID and secret), old snapshot left behind.
		$other = new WPLC_Client_Test_Kit();
		$other->manager()->activate( self::KEY );
		$other->store->data['test_addon_license_state']['envelope'] = $state['envelope'];

		$this->assertFalse( $other->manager()->is_entitled( 'feature.flag' ) );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_a109_clock_rollback_does_not_extend_the_offline_window(): void {
		$manager = $this->activated();

		// Offline for twelve days; every failed attempt records the local time.
		$this->kit->advance( 12 * DAY_IN_SECONDS );
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'blocked' ) );
		$manager->refresh();
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

		// The clock of the site is turned back by eleven days and then runs on.
		$this->kit->now -= 11 * DAY_IN_SECONDS;
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->now += 3 * DAY_IN_SECONDS;
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ), 'Local day 4 counts as day 12: the highest time seen.' );
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'blocked' ) );
		$manager->refresh( true );

		// Turned back once more, to before the activation.
		$this->kit->now -= 30 * DAY_IN_SECONDS;
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->now += 43 * DAY_IN_SECONDS; // Two days past the highest time seen: day 14.
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_a109_a_constantly_wrong_clock_does_not_matter(): void {
		// The customer server lives ten years in the past.
		$this->kit->now -= 3650 * DAY_IN_SECONDS;
		$manager         = $this->activated();
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->advance( 14 * DAY_IN_SECONDS - 1 );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->advance( 1 );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_a30_an_older_signed_answer_is_not_accepted_again(): void {
		$manager = $this->activated();
		$server  = $this->kit->server;

		// Day 5: signed "revoked".
		$this->kit->advance( 5 * DAY_IN_SECONDS );
		$server->queue[] = $server->signed( $server->entitlement( array( 'license_status' => 'revoked', 'access' => false, 'entitlements' => array() ) ) );
		$this->assertSame( LicenseStatus::STATE_REVOKED, $manager->refresh()->state );

		// A man in the middle answers the next check with correctly signed data whose server_time is older
		// (it cannot replay a recorded answer as it is: the nonce would not match).
		$server->queue[] = $server->signed( $server->entitlement( array( 'server_time' => gmdate( 'Y-m-d H:i:s', $server->now - 5 * DAY_IN_SECONDS ) ) ) );
		$result          = $manager->refresh();
		$this->assertSame( 'wplc_response_stale', $result->get_error_code() );
		$this->assertSame( LicenseStatus::STATE_REVOKED, $manager->status()->state );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );

		// The stored snapshot swapped for the older "active" one of the same installation is caught by the highest server time.
		$this->assertGreaterThan( 0, $this->kit->store->data['test_addon_license_state']['server_time_max'] );
	}

	// ------------------------------------------------------------------
	// A-108: back-off, catch-up, check now.
	// ------------------------------------------------------------------

	public function test_a108_backoff_after_transport_errors_and_check_now_ignores_it(): void {
		$manager = $this->activated();
		$server  = $this->kit->server;
		$down    = new WP_Error( 'http_request_failed', 'down' );

		$expected = array( 15 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS );
		foreach ( $expected as $delay ) {
			$server->requests = array();
			$server->queue    = array( $down );
			$manager->run_scheduled_check();
			$this->assertCount( 1, $server->requests );
			$state = $this->kit->store->data['test_addon_license_state'];
			$this->assertSame( $this->kit->now + $delay, $state['next_attempt'] );

			// Inside the window the scheduled check does not ask again...
			$server->requests = array();
			$this->kit->advance( $delay - 1 );
			$manager->run_scheduled_check();
			$this->assertSame( 'wplc_backoff', $manager->refresh( false )->get_error_code() );
			$this->assertSame( array(), $server->requests );
			$this->kit->advance( 1 );
		}

		// ...the button "Check now" always does.
		$this->kit->advance( -10 );
		$server->queue = array( $down );
		$this->assertSame( 'wplc_connection_failed', $manager->refresh()->get_error_code() );
		$this->assertCount( 1, $server->requests );

		// Retry-After of the server is respected, and the delay is capped at one day.
		$server->queue = array( WPLC_Test_Server::error( 429, 'rate_limited', array( 'retry_after' => 20 * HOUR_IN_SECONDS ) ) );
		$manager->refresh();
		$this->assertSame( $this->kit->now + 20 * HOUR_IN_SECONDS, $this->kit->store->data['test_addon_license_state']['next_attempt'] );
		for ( $i = 0; $i < 10; $i++ ) {
			$server->queue = array( $down );
			$manager->refresh();
		}
		$this->assertSame( $this->kit->now + DAY_IN_SECONDS, $this->kit->store->data['test_addon_license_state']['next_attempt'] );

		// A success resets everything.
		$server->queue = array();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
		$state = $this->kit->store->data['test_addon_license_state'];
		$this->assertSame( array( 0, 0, null ), array( $state['next_attempt'], $state['error_streak'], $state['error'] ) );
		$this->assertSame( '', $manager->status()->last_error );
	}

	public function test_offline_notice_appears_after_three_days_without_contact(): void {
		$manager = $this->activated();
		$this->assertNull( $manager->notice() );

		$this->kit->advance( 2 * DAY_IN_SECONDS );
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'down' ) );
		$manager->refresh();
		$this->assertNull( $manager->notice(), 'A short outage is not worth a notice.' );

		$this->kit->advance( 2 * DAY_IN_SECONDS );
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'down' ) );
		$manager->refresh();
		$notice = $manager->notice();
		$this->assertSame( 'warning', $notice['type'] );
		$this->assertStringContainsString( 'cannot be reached', $notice['text'] );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_scheduled_check_does_nothing_without_activation_or_on_a_copy(): void {
		$manager = $this->kit->manager();
		$manager->run_scheduled_check();
		$this->assertSame( 'wplc_not_activated', $manager->refresh()->get_error_code() );

		$manager                = $this->activated();
		$this->kit->store->site = 'https://staging.shop.example';
		$manager->run_scheduled_check();
		$this->assertSame( array(), $this->kit->server->requests );
	}

	// ------------------------------------------------------------------
	// B-69, B-164: copies and moves.
	// ------------------------------------------------------------------

	public function test_b69_copy_of_the_site_is_locked_locally_and_cannot_release_the_original(): void {
		$manager = $this->activated();
		$live    = $this->kit->credentials();

		$this->kit->store->site = 'https://staging.shop.example';
		$copy                   = $this->kit->manager();

		$status = $copy->status();
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $status->state );
		$this->assertFalse( $copy->is_entitled( 'feature.flag' ), 'The copied snapshot grants nothing on another address.' );
		$this->assertSame( 'site_changed', $copy->notice()['state'] );
		$this->assertSame( 'wplc_site_changed', $copy->refresh()->get_error_code() );

		$message = $copy->run_form_action( 'deactivate' );
		$this->assertSame( 'success', $message['type'] );
		$this->assertStringContainsString( 'original site was not changed', $message['text'] );
		$this->assertSame( array(), $this->kit->server->requests, 'Not a single request with the copied credentials.' );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $copy->status()->state );

		// The copy is then activated as staging site of the live installation.
		$this->kit->store->data['test_addon_license'] = $live;
		$status                                      = $this->kit->manager()->activate( self::KEY, 'staging' );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $status->state );
		$this->assertSame( 'staging', $status->environment );
		$this->assertSame( $live['installation_id'], $this->kit->server->requests[0]['body']['staging_parent_installation_id'] );
	}

	public function test_b164_move_keeps_the_installation_and_unlocks(): void {
		$manager                = $this->activated();
		$installation           = $manager->client()->installation_id();
		$this->kit->store->site = 'https://new-domain.example';
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $manager->status()->state );

		$message = $manager->run_form_action( 'move', self::KEY );

		$this->assertSame( 'success', $message['type'] );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->status()->state );
		$this->assertSame( $installation, $manager->client()->installation_id() );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	// ------------------------------------------------------------------
	// Form actions, deactivation, uninstall: A-110, B-172.
	// ------------------------------------------------------------------

	public function test_form_actions_report_code_and_translated_text(): void {
		$manager = $this->kit->manager();

		$this->kit->server->queue[] = WPLC_Test_Server::error( 409, 'site_limit', array( 'site_limit' => 1, 'site_count' => 1 ) );
		$message                    = $manager->run_form_action( 'activate', self::KEY );
		$this->assertSame( 'error', $message['type'] );
		$this->assertStringContainsString( '(site_limit)', $message['text'] );
		$this->assertStringNotContainsString( 'Server text', $message['text'] );

		$this->assertSame( 'error', $manager->run_form_action( 'activate', '' )['type'] );
		$this->assertFalse( wp_next_scheduled( 'test_addon_license_check' ) );
		$this->assertSame( 'success', $manager->run_form_action( 'activate', self::KEY )['type'] );
		$this->assertNotFalse( wp_next_scheduled( 'test_addon_license_check' ), 'The daily check is scheduled with the activation.' );
		$this->assertSame( 'success', $manager->run_form_action( 'check' )['type'] );
		$this->assertSame( 'success', $manager->run_form_action( 'deactivate' )['type'] );
		$this->assertFalse( wp_next_scheduled( 'test_addon_license_check' ) );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
		$this->assertArrayNotHasKey( 'test_addon_license_state', $this->kit->store->data );
	}

	public function test_failed_deactivation_keeps_state_and_rights(): void {
		$manager                    = $this->activated();
		$this->kit->server->queue[] = new WP_Error( 'http_request_failed', 'down' );

		$this->assertInstanceOf( WP_Error::class, $manager->deactivate() );

		$this->assertTrue( $manager->client()->is_activated() );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_privacy_text_names_the_server_and_what_is_sent(): void {
		$text = $this->kit->manager()->privacy_text();

		$this->assertStringContainsString( 'Test Add-on', $text );
		$this->assertStringContainsString( 'license.example', $text );
		$this->assertStringContainsString( 'address (URL)', $text );
		$this->assertStringContainsString( 'WordPress and of PHP', $text );
	}

	public function test_several_addons_share_the_classes_without_sharing_state(): void {
		$first  = $this->kit->manager();
		$second = $this->kit->manager( array( 'product_key' => 'second-addon', 'name' => 'Second' ) );

		$first->activate( self::KEY );

		$this->assertSame( 'test_addon', $first->prefix() );
		$this->assertSame( 'second_addon', $second->prefix() );
		$this->assertSame( 'second_addon_license_check', $second->cron_hook() );
		$this->assertTrue( $first->is_entitled( 'feature.flag' ) );
		$this->assertFalse( $second->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $second->status()->state );
		$this->assertSame( '2.0.0', LicenseManager::VERSION );
		$this->assertSame( LicenseManager::VERSION, LicenseClient::VERSION );
	}
}
