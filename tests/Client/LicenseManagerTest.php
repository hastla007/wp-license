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

	protected function tearDown(): void {
		unset( $GLOBALS['wplc_test_filters'], $GLOBALS['wplc_test_sites'], $GLOBALS['wplc_test_get_sites'], $GLOBALS['wplc_test_switched'], $GLOBALS['wplc_test_restored'] );
		unset( $GLOBALS['wplc_test_transients'], $GLOBALS['wplc_test_single_events'], $GLOBALS['wplc_test_site_options'], $GLOBALS['wplc_test_blog'], $GLOBALS['wplc_test_blog_stack'] );
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

		// Turned back once more, to before the last signed answer: the stored fetched_at now lies in the
		// future, which is what an edited option looks like. The snapshot counts as used up (audit finding 1).
		$this->kit->now -= 30 * DAY_IN_SECONDS;
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
		$this->kit->now += 43 * DAY_IN_SECONDS; // Five days past the highest time seen: day 17.
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_a109_a_constantly_wrong_clock_does_not_matter_up_to_the_tolerance(): void {
		// AL-1 (decision of 16 Nov 2026): a clock that is behind, or ahead by at most the tolerance of one day, keeps
		// the full signed window of 14 days; the customer server may even live ten years in the past.
		foreach ( array( -3650 * DAY_IN_SECONDS, -DAY_IN_SECONDS, 0, LicenseManager::OFFLINE_TOLERANCE ) as $offset ) {
			$this->kit       = new WPLC_Client_Test_Kit();
			$this->kit->now += $offset;
			$manager         = $this->activated();
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ), 'offset ' . $offset );
			$this->kit->advance( 14 * DAY_IN_SECONDS - 1 );
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ), 'offset ' . $offset );
			$this->kit->advance( 1 );
			$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'offset ' . $offset );
			$this->assertSame( '', $manager->status()->reason, 'The signed window ended first.' );
		}
	}

	public function test_al1_a_clock_ahead_by_more_than_the_tolerance_shortens_the_window(): void {
		// Ten days ahead: the absolute limit (cache_expires_at + 1 day, local time) is reached after five days offline.
		$this->kit->now += 10 * DAY_IN_SECONDS;
		$manager         = $this->activated();
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->advance( 5 * DAY_IN_SECONDS );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$this->kit->advance( 1 );
		$status = $manager->status();
		$this->assertFalse( $status->access );
		$this->assertSame( LicenseStatus::STATE_STALE, $status->state );
		$this->assertSame( LicenseStatus::REASON_CLOCK_AHEAD, $status->reason );

		// More than 14 + 1 days ahead: stale right after every signed answer.
		$this->kit       = new WPLC_Client_Test_Kit();
		$this->kit->now += 15 * DAY_IN_SECONDS + 1;
		$manager         = $this->activated();
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::REASON_CLOCK_AHEAD, $manager->status()->reason );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->refresh()->state );
		$notice = $manager->notice();
		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'the clock of this site is ahead, or the offline period has expired', $notice['text'] );
	}

	public function test_al1_resetting_the_unsigned_clock_fields_on_day_20_does_not_unlock_the_old_snapshot(): void {
		$manager = $this->activated();

		// Offline for twenty days (the reproduction of the audit of 16 Nov 2026).
		$this->kit->advance( 20 * DAY_IN_SECONDS );
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'blocked' ) );
		$manager->refresh();
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );

		// Someone with write access to the options resets fetched_at, clock_high and server_time_max.
		// Up to client 2.0.3 this made the genuinely signed snapshot active again for 14 days.
		$state                                              = $this->kit->store->data['test_addon_license_state'];
		$state['fetched_at']                                = $this->kit->now;
		$state['clock_high']                                = $this->kit->now;
		$state['server_time_max']                           = 0;
		$this->kit->store->data['test_addon_license_state'] = $state;

		$reset  = $this->kit->manager();
		$status = $reset->status();
		$this->assertFalse( $reset->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $status->state );
		$this->assertSame( LicenseStatus::REASON_CLOCK_AHEAD, $status->reason );

		// A filter cannot stretch the tolerance beyond seven days: from day 21 on no reset helps.
		$GLOBALS['wplc_test_filters']['test_addon_license_offline_tolerance'][] = static function () {
			return 30 * DAY_IN_SECONDS;
		};
		$this->assertSame( LicenseManager::MAX_OFFLINE_TOLERANCE, $reset->offline_tolerance() );
		$this->kit->now                                    += DAY_IN_SECONDS + 1;
		$state['fetched_at']                                = $this->kit->now;
		$state['clock_high']                                = $this->kit->now;
		$this->kit->store->data['test_addon_license_state'] = $state;
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.flag' ) );
		unset( $GLOBALS['wplc_test_filters'] );

		// Only a new signed answer restores the rights.
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $reset->refresh()->state );
		$this->assertTrue( $reset->is_entitled( 'feature.flag' ) );
	}

	public function test_al1_the_tolerance_filter_is_limited_to_zero_to_seven_days(): void {
		$manager = $this->kit->manager();
		$this->assertSame( DAY_IN_SECONDS, $manager->offline_tolerance() );

		$GLOBALS['wplc_test_filters']['test_addon_license_offline_tolerance'] = array(
			static function () {
				return -5;
			},
		);
		$this->assertSame( 0, $manager->offline_tolerance() );

		$GLOBALS['wplc_test_filters']['test_addon_license_offline_tolerance'] = array(
			static function () {
				return 3 * DAY_IN_SECONDS;
			},
		);
		$this->assertSame( 3 * DAY_IN_SECONDS, $manager->offline_tolerance() );

		// With a tolerance of zero, a clock one second past the signed deadline (and the window not used up) is stale.
		$GLOBALS['wplc_test_filters']['test_addon_license_offline_tolerance'] = array(
			static function () {
				return 0;
			},
		);
		$this->kit->now += 14 * DAY_IN_SECONDS + 1;
		$this->assertSame( LicenseStatus::REASON_CLOCK_AHEAD, $this->activated()->status()->reason );
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

		// P-22: without the confirmation nothing is sent; the old address keeps its activation.
		$unconfirmed = $manager->run_form_action( 'move', self::KEY );
		$this->assertSame( 'error', $unconfirmed['type'] );
		$this->assertStringContainsString( 'Confirm that the site moved', $unconfirmed['text'] );
		$this->assertSame( array(), $this->kit->server->requests );
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $manager->status()->state );
		$this->assertSame( 'https://shop.example', $this->kit->credentials()['site_url'] );

		$message = $manager->run_form_action( 'move', self::KEY, true );

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
		$this->assertSame( 'success', $manager->run_form_action( 'deactivate', '', true )['type'] );
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

	// R-26 / R-27 (client 2.1.0): storage is confirmed, a failed write never leaves more rights behind.

	public function test_r26_a_signed_withdrawal_that_cannot_be_stored_does_not_leave_the_older_rights_in_charge(): void {
		foreach ( array( 'suspended', 'revoked', 'expired' ) as $license_status ) {
			$this->kit       = new WPLC_Client_Test_Kit();
			$manager         = $this->activated();
			$server          = $this->kit->server;
			$server->queue[] = $server->signed( $server->entitlement( array( 'license_status' => $license_status, 'access' => false, 'entitlements' => array() ) ) );
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

			$this->kit->store->fail = true;
			$result                 = $manager->refresh();

			$this->assertInstanceOf( WP_Error::class, $result, $license_status );
			$this->assertSame( 'wplc_state_not_saved', $result->get_error_code() );
			$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'The request that received the answer follows it.' );
			$this->assertFalse( $manager->status()->access );
			$this->assertSame( $license_status, $manager->status()->license_status );

			// A later request (new object, same database): the older snapshot that granted access is gone.
			$this->kit->store->fail = false;
			$later                  = $this->kit->manager();
			$this->assertFalse( $later->is_entitled( 'feature.flag' ), $license_status );
			$this->assertSame( LicenseStatus::STATE_STALE, $later->status()->state );
			$this->assertArrayNotHasKey( 'test_addon_license_state', $this->kit->store->data );

			// The next answer that can be stored applies normally.
			$server->queue[] = $server->signed( $server->entitlement( array( 'license_status' => $license_status, 'access' => false, 'entitlements' => array() ) ) );
			$this->assertFalse( $later->refresh()->access );
			$this->assertArrayHasKey( 'envelope', $this->kit->store->data['test_addon_license_state'] );
		}
	}

	public function test_r26_a_withdrawn_right_that_cannot_be_stored_is_not_granted_from_the_older_state(): void {
		$manager         = $this->activated();
		$server          = $this->kit->server;
		$server->queue[] = $server->signed( $server->entitlement( array( 'entitlements' => array( 'other.flag' => true ) ) ) );

		$this->kit->store->fail = true;
		$this->assertSame( 'wplc_state_not_saved', $manager->refresh()->get_error_code() );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertTrue( $manager->is_entitled( 'other.flag' ) );

		$this->kit->store->fail = false;
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.flag' ) );
	}

	public function test_r26_an_unstored_answer_with_the_same_rights_keeps_the_older_state(): void {
		$manager         = $this->activated();
		$server          = $this->kit->server;
		$before          = $this->kit->store->data['test_addon_license_state'];
		$server->queue[] = $server->signed( $server->entitlement() );

		$this->kit->store->fail = true;
		$this->assertSame( 'wplc_state_not_saved', $manager->refresh()->get_error_code() );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

		// Nothing was taken away, so a database hiccup does not switch a paid add-on off.
		$this->kit->store->fail = false;
		$this->assertSame( $before, $this->kit->store->data['test_addon_license_state'] );
		$this->assertTrue( $this->kit->manager()->is_entitled( 'feature.flag' ) );

		// A failed request (kept cache) that cannot be recorded changes nothing either.
		$this->kit->store->fail     = true;
		$this->kit->server->queue[] = new WP_Error( 'http_request_failed', 'down' );
		$this->assertSame( 'wplc_connection_failed', $manager->refresh()->get_error_code() );
		$this->assertSame( $before, $this->kit->store->data['test_addon_license_state'] );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
	}

	public function test_r26_a_database_that_refuses_write_and_delete_is_reported_and_the_request_follows_the_answer(): void {
		$manager         = $this->activated();
		$server          = $this->kit->server;
		$server->queue[] = $server->signed( $server->entitlement( array( 'license_status' => 'revoked', 'access' => false, 'entitlements' => array() ) ) );

		$this->kit->store->fail        = true;
		$this->kit->store->fail_delete = true;
		$this->assertSame( 'wplc_state_not_saved', $manager->refresh()->get_error_code() );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_REVOKED, $manager->status()->state );
	}

	/**
	 * Add-on audit 16 Nov 2026, item 10: "removed" is only reported when the credentials are gone.
	 */
	public function test_audit16_deactivation_reports_credentials_that_cannot_be_removed(): void {
		$manager                       = $this->activated();
		$this->kit->store->fail_delete = true;

		$message = $manager->run_form_action( 'deactivate', '', true );

		$this->assertSame( 'error', $message['type'] );
		$this->assertStringContainsString( '(wplc_credentials_not_removed)', $message['text'] );
		$this->assertTrue( $manager->client()->is_activated(), 'The credentials could not be deleted.' );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'The server released the site, so the stored rights are dropped anyway.' );
		$this->assertArrayNotHasKey( 'envelope', $this->kit->store->data['test_addon_license_state'], 'The state could not be deleted and was emptied instead.' );

		$forget = $manager->run_form_action( 'forget' );
		$this->assertSame( 'error', $forget['type'] );
		$this->assertStringContainsString( '(wplc_credentials_not_removed)', $forget['text'] );

		$this->kit->store->fail_delete = false;
		$this->assertTrue( $manager->forget_locally() );
		$this->assertSame( 'success', $manager->run_form_action( 'forget' )['type'] );
		$this->assertFalse( $manager->client()->is_activated() );
	}

	public function test_privacy_text_names_the_server_and_what_is_sent(): void {
		$text = $this->kit->manager()->privacy_text();

		$this->assertStringContainsString( 'Test Add-on', $text );
		$this->assertStringContainsString( 'license.example', $text );
		$this->assertStringContainsString( 'address (URL)', $text );
		$this->assertStringContainsString( 'WordPress and of PHP', $text );
		// Audit finding 7: every field of the requests is named.
		$this->assertStringContainsString( 'product key', $text );
		$this->assertStringContainsString( 'environment (live, staging or development site)', $text );
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
		$this->assertSame( '2.4.0', LicenseManager::VERSION );
		$this->assertSame( LicenseManager::VERSION, LicenseClient::VERSION );
	}

	// ------------------------------------------------------------------
	// Audit 2026-11 (client 2.0.1).
	// ------------------------------------------------------------------

	public function test_audit1_edited_clock_fields_cannot_keep_the_rights_forever(): void {
		$this->activated();
		$state = $this->kit->store->data['test_addon_license_state'];

		// fetched_at a little ahead (clock jitter, NTP) is fine.
		$this->kit->store->data['test_addon_license_state'] = array_merge( $state, array( 'fetched_at' => $this->kit->now + LicenseManager::CLOCK_SKEW ) );
		$this->assertTrue( $this->kit->manager()->is_entitled( 'feature.flag' ) );

		// Someone sets fetched_at (and the other unsigned clock fields) far into the future so that no time
		// would ever pass: the snapshot counts as tampered, not as fresh.
		$this->kit->store->data['test_addon_license_state'] = array_merge(
			$state,
			array(
				'fetched_at'      => $this->kit->now + 10 * 365 * DAY_IN_SECONDS,
				'clock_high'      => 0,
				'server_time_max' => 0,
			)
		);
		$tampered = $this->kit->manager();
		$this->assertFalse( $tampered->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $tampered->status()->state );

		// The next scheduled check asks at once (no back-off) and a signed answer repairs the state.
		$this->kit->server->requests = array();
		$tampered->run_scheduled_check();
		$this->assertCount( 1, $this->kit->server->requests );
		$this->assertTrue( $tampered->is_entitled( 'feature.flag' ) );
		$this->assertSame( $this->kit->now, $this->kit->store->data['test_addon_license_state']['fetched_at'] );
	}

	public function test_audit1_the_unsigned_stored_server_time_is_not_read(): void {
		$manager = $this->activated();
		$this->kit->advance( 15 * DAY_IN_SECONDS );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'The signed cache_expires_at (day 14) has passed.' );

		// Client 2.0.0 added the time since fetched_at to the stored, unsigned `server_time`: moving that back
		// by a month re-opened the window. Only the signed server_time inside the payload counts now.
		$state                                              = $this->kit->store->data['test_addon_license_state'];
		$state['server_time']                               = $this->kit->now - 60 * DAY_IN_SECONDS;
		$state['server_time_max']                           = 0;
		$this->kit->store->data['test_addon_license_state'] = $state;
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.flag' ) );
	}

	public function test_audit2_no_stored_flag_skips_the_server_signature(): void {
		$this->activated();
		$state = $this->kit->store->data['test_addon_license_state'];

		// Forged rights with a recomputed HMAC, plus a fresh `verified_at` that client 2.0.0 honoured as
		// "Ed25519 checked less than twelve hours ago" on PHP without ext-sodium.
		$forged                                             = $state;
		$forged['envelope']['payload']                      = str_replace( '"feature.off":false', '"feature.off":true', $state['envelope']['payload'] );
		$forged['envelope']['payload_signature']            = hash_hmac( 'sha256', $forged['envelope']['payload'], $this->kit->credentials()['installation_secret'] );
		$forged['verified_at']                              = $this->kit->now;
		$this->kit->store->data['test_addon_license_state'] = $forged;
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.off' ) );
		$this->assertFalse( $this->kit->manager()->is_entitled( 'feature.flag' ) );

		// On a PHP without any Ed25519 implementation even the genuine snapshot unlocks nothing (fail closed).
		$this->kit->store->data['test_addon_license_state'] = $state;
		$bare = $this->kit->manager( array( 'signature_verifier' => false ) );
		$this->assertFalse( $bare->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $bare->status()->state );
		$this->assertSame( 'wplc_ed25519_unavailable', $bare->refresh()->get_error_code() );
		$this->assertTrue( $bare->client()->is_activated(), 'The activation is kept for a PHP that can verify.' );
		$this->assertTrue( $this->kit->manager()->is_entitled( 'feature.flag' ) );
	}

	public function test_audit4_unsigned_refusals_never_drop_the_credentials_on_deactivation(): void {
		$manager = $this->activated();
		foreach ( array( WPLC_Test_Server::error( 409, 'site_mismatch' ), WPLC_Test_Server::error( 401, 'activation_not_found' ) ) as $answer ) {
			$this->kit->server->queue[] = $answer;
			$this->assertInstanceOf( WP_Error::class, $manager->deactivate() );
			$this->assertTrue( $manager->client()->is_activated() );
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		}
		$this->kit->server->queue[] = WPLC_Test_Server::error( 409, 'site_mismatch' );
		$message                    = $manager->run_form_action( 'deactivate', '', true );
		$this->assertSame( 'error', $message['type'], 'The error is reported.' );
		$this->assertStringContainsString( '(site_mismatch)', $message['text'] );

		$this->kit->server->queue[] = $this->signed_refusal();
		$this->assertSame( array( 'status' => 'removed_locally', 'reason' => 'activation_deactivated' ), $manager->deactivate() );
		$this->assertFalse( $manager->client()->is_activated() );
	}

	public function test_audit6_the_public_keys_filter_only_works_in_a_development_build(): void {
		$attacker = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );
		foreach ( array( 'release_addon', 'dev_addon' ) as $prefix ) {
			$GLOBALS['wplc_test_filters'][ $prefix . '_license_public_keys' ][] = static function () use ( $attacker ) {
				return array( $attacker->public_key );
			};
		}

		// Release build: another plugin's filter is ignored; the pinned key stays the only one.
		$release = $this->kit->manager( array( 'product_key' => 'release-addon' ) );
		$this->assertFalse( LicenseManager::is_dev_build( 'release_addon' ) );
		$this->kit->server->queue[] = static function ( array $request ) use ( $attacker ) {
			$attacker->secrets[ $request['body']['installation_id'] ] = $request['body']['installation_secret'];
			return WPLC_Test_Server::json( 200, $attacker->envelope( $request, $attacker->entitlement( array( 'product_key' => 'release-addon' ) ) ) );
		};
		$this->assertSame( 'wplc_server_signature_invalid', $release->activate( self::KEY )->get_error_code(), 'The injected key signs nothing.' );

		// Nothing pinned and only the filter: still misconfigured.
		$unpinned = $this->kit->manager( array( 'product_key' => 'release-addon', 'public_keys' => array() ) );
		$this->assertSame( LicenseStatus::STATE_MISCONFIGURED, $unpinned->status()->state );

		// Development build (constant of the add-on defined): the filter is honoured, e.g. for a staging server.
		define( 'DEV_ADDON_LICENSE_DEV', true );
		$this->assertTrue( LicenseManager::is_dev_build( 'dev_addon' ) );
		$this->assertTrue( $this->kit->manager( array( 'product_key' => 'dev-addon', 'public_keys' => array() ) )->client()->is_configured() );
	}

	public function test_audit7_uninstall_cleans_every_site_of_a_large_network_in_batches(): void {
		$GLOBALS['wplc_test_sites'] = range( 1, 1234 );
		$manager                    = $this->activated();

		$manager->uninstall();

		$this->assertSame( range( 1, 1234 ), $GLOBALS['wplc_test_switched'] );
		$this->assertSame( 1234, $GLOBALS['wplc_test_restored'] );
		$this->assertCount( 13, $GLOBALS['wplc_test_get_sites'] );
		$this->assertSame( LicenseManager::UNINSTALL_BATCH, $GLOBALS['wplc_test_get_sites'][0]['number'] );
		$this->assertSame( 1200, $GLOBALS['wplc_test_get_sites'][12]['offset'] );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertArrayNotHasKey( 'test_addon_license_state', $this->kit->store->data );

		// Exactly one full batch: one extra, empty query ends the loop.
		$GLOBALS['wplc_test_sites']     = range( 1, LicenseManager::UNINSTALL_BATCH );
		$GLOBALS['wplc_test_get_sites'] = array();
		$GLOBALS['wplc_test_switched']  = array();
		$this->kit->manager()->uninstall();
		$this->assertCount( LicenseManager::UNINSTALL_BATCH, $GLOBALS['wplc_test_switched'] );
		$this->assertCount( 2, $GLOBALS['wplc_test_get_sites'] );
	}

	// ------------------------------------------------------------------
	// Audit 2026-11-15 (client 2.0.2).
	// ------------------------------------------------------------------

	public function test_p23_a_clock_turned_back_schedules_one_background_check_at_once(): void {
		$manager = $this->activated();

		// The VM ran ten minutes ahead when the last answer arrived; NTP now corrects the clock.
		$this->kit->now -= 600;
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ), 'Still only a signed answer restores the rights.' );
		$this->assertSame( array( array( 'test_addon_license_check', time() ) ), $GLOBALS['wplc_test_single_events'] ?? null );
		$this->assertSame( LicenseManager::RECHECK_LOCK, $GLOBALS['wplc_test_transients']['test_addon_license_recheck'][1] );

		// Every further front-end request sees the lock and schedules nothing more.
		$this->kit->manager()->status();
		$this->kit->manager()->is_entitled( 'feature.flag' );
		$this->assertCount( 1, $GLOBALS['wplc_test_single_events'] );
		$this->assertSame( array(), $this->kit->server->requests, 'No request in the page view itself.' );

		// The background check runs (WP-Cron, minutes later) and the features are back.
		$this->kit->manager()->run_scheduled_check();
		$this->assertCount( 1, $this->kit->server->requests );
		$this->assertTrue( $this->kit->manager()->is_entitled( 'feature.flag' ) );

		// A normal clock never schedules anything.
		unset( $GLOBALS['wplc_test_single_events'], $GLOBALS['wplc_test_transients'] );
		$this->kit->manager()->status();
		$this->assertArrayNotHasKey( 'wplc_test_single_events', $GLOBALS );
	}

	public function test_p25_the_ed25519_result_of_a_snapshot_is_verified_once_per_request(): void {
		$calls    = 0;
		$verifier = static function ( string $signature, string $message, string $key ) use ( &$calls ): bool {
			++$calls;
			return sodium_crypto_sign_verify_detached( $signature, $message, $key );
		};
		$this->kit->manager( array( 'signature_verifier' => $verifier ) )->activate( self::KEY );
		$this->assertFalse( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->client()->uses_native_ed25519() );
		$this->assertFalse( $this->kit->manager()->client()->uses_native_ed25519() && ! extension_loaded( 'sodium' ) );

		// Several add-ons / instances read the same snapshot in a new request: one (slow) verification.
		$memo = new ReflectionProperty( LicenseClient::class, 'verified' );
		$memo->setValue( null, array() );
		$calls = 0;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.flag' ) );
		}
		$this->assertSame( 1, $calls );
		$this->assertArrayNotHasKey( 'wplc_test_transients', $GLOBALS, 'Without AUTH_KEY/AUTH_SALT nothing is stored outside the request.' );

		// A forged snapshot (recomputed HMAC) is verified again on every read and never accepted.
		$state                                              = $this->kit->store->data['test_addon_license_state'];
		$forged                                             = $state;
		$forged['envelope']['payload']                      = str_replace( '"feature.off":false', '"feature.off":true', $state['envelope']['payload'] );
		$forged['envelope']['payload_signature']            = hash_hmac( 'sha256', $forged['envelope']['payload'], $this->kit->credentials()['installation_secret'] );
		$this->kit->store->data['test_addon_license_state'] = $forged;
		$calls = 0;
		$this->assertFalse( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.off' ) );
		$this->assertFalse( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.off' ) );
		$this->assertSame( 2, $calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_p25_with_wp_config_salts_the_result_survives_the_request_and_cannot_be_forged(): void {
		define( 'AUTH_KEY', str_repeat( 'k', 64 ) );
		define( 'AUTH_SALT', str_repeat( 's', 64 ) );
		$calls    = 0;
		$verifier = static function ( string $signature, string $message, string $key ) use ( &$calls ): bool {
			++$calls;
			return sodium_crypto_sign_verify_detached( $signature, $message, $key );
		};
		$memo = new ReflectionProperty( LicenseClient::class, 'verified' );
		$this->kit->manager( array( 'signature_verifier' => $verifier ) )->activate( self::KEY );

		$memo->setValue( null, array() );
		unset( $GLOBALS['wplc_test_transients'] );
		$calls = 0;
		$this->assertTrue( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.flag' ) );
		$this->assertSame( 1, $calls );
		$names = array_keys( $GLOBALS['wplc_test_transients'] ?? array() );
		$this->assertCount( 1, $names );
		$this->assertStringStartsWith( 'wplc_ed25519_', $names[0] );
		$this->assertSame( DAY_IN_SECONDS, $GLOBALS['wplc_test_transients'][ $names[0] ][1] );

		// Next request (memo empty): the stored tag is used, no verification.
		$memo->setValue( null, array() );
		$calls = 0;
		$this->assertTrue( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.flag' ) );
		$this->assertSame( 0, $calls );

		// A value written by someone with database access but without the wp-config salts is ignored.
		$memo->setValue( null, array() );
		$GLOBALS['wplc_test_transients'][ $names[0] ][0] = hash_hmac( 'sha256', 'guess', 'guess' );
		$calls = 0;
		$this->assertTrue( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.flag' ) );
		$this->assertSame( 1, $calls );

		// The HMAC with the installation secret is still checked on every read.
		$memo->setValue( null, array() );
		$state = $this->kit->store->data['test_addon_license_state'];
		$state['envelope']['payload_signature'] = str_repeat( '0', 64 );
		$this->kit->store->data['test_addon_license_state'] = $state;
		$this->assertFalse( $this->kit->manager( array( 'signature_verifier' => $verifier ) )->is_entitled( 'feature.flag' ) );
	}

	public function test_p47_a_void_snapshot_is_checked_without_building_texts(): void {
		$this->activated();
		$state                                              = $this->kit->store->data['test_addon_license_state'];
		$state['envelope']['payload_signature_ed25519']     = base64_encode( str_repeat( "\0", 64 ) );
		$this->kit->store->data['test_addon_license_state'] = $state;

		$manager  = $this->kit->manager();
		$property = new ReflectionProperty( LicenseClient::class, 'messages' );
		$property->setValue( $manager->client(), null );

		// Would fail with an Error if a WP_Error with a text were built (feature checks run before init).
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
		$this->assertSame( 'wplc_server_signature_invalid', $this->kit->manager()->client()->verified_snapshot_data( $state['envelope'] ) );
	}

	// ------------------------------------------------------------------
	// AL-4, AL-5, AL-6 and the early texts (decisions of 16 Nov 2026, client 2.1.0).
	// ------------------------------------------------------------------

	public function test_al5_releasing_the_site_needs_a_confirmation(): void {
		$manager = $this->activated();

		$message = $manager->run_form_action( 'deactivate' );
		$this->assertSame( 'error', $message['type'] );
		$this->assertStringContainsString( 'Confirm that this site gives up its license', $message['text'] );
		$this->assertSame( array(), $this->kit->server->requests, 'Nothing is sent without the confirmation.' );
		$this->assertTrue( $manager->client()->is_activated() );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );

		$message = $manager->run_form_action( 'deactivate', '', true );
		$this->assertSame( 'success', $message['type'] );
		$this->assertSame( array( 'DELETE' ), array_column( $this->kit->server->requests, 'method' ) );
		$this->assertFalse( $manager->client()->is_activated() );

		// deactivate() itself (the API for code) is unchanged.
		$manager = $this->activated();
		$this->assertSame( 'deactivated', $manager->deactivate()['status'] );
	}

	public function test_al6_a_signed_site_mismatch_removes_only_the_local_data(): void {
		$server = $this->kit->server;
		$answer = static function ( array $overrides = array(), array $options = array() ) use ( $server ): Closure {
			return static function ( array $request ) use ( $server, $overrides, $options ) {
				$data = array_merge(
					array(
						'activation_id'     => 41,
						'installation_id'   => '',
						'activation_status' => 'active',
						'site_match'        => false,
						'server_time'       => gmdate( 'Y-m-d H:i:s', $server->now ),
					),
					$overrides
				);
				return WPLC_Test_Server::error( 409, 'site_mismatch', array( 'signed' => $server->envelope( $request, $data, $options ) ) );
			};
		};
		$manager = $this->activated();

		// Not enough: another key, another request, or data that does not say site_match: false.
		$attacker = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );
		foreach ( array( $answer( array(), array( 'secret_key' => $attacker->secret_key ) ), $answer( array(), array( 'nonce' => str_repeat( 'a', 32 ) ) ), $answer( array( 'site_match' => true ) ) ) as $forged ) {
			$this->kit->server->queue[] = $forged;
			$result                     = $manager->deactivate();
			$this->assertSame( 'site_mismatch', $result->get_error_code() );
			$this->assertFalse( LicenseClient::is_signed_site_mismatch( $result ) );
			$this->assertTrue( $manager->client()->is_activated() );
			$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		}

		// Verified: the activation belongs to the other address. Nothing was released there; this site forgets it.
		$this->kit->server->requests = array();
		$this->kit->server->queue[]  = $answer();
		$message                     = $manager->run_form_action( 'deactivate', '', true );
		$this->assertSame( 'success', $message['type'] );
		$this->assertStringContainsString( 'original site was not changed', $message['text'] );
		$this->assertCount( 1, $this->kit->server->requests );
		$this->assertFalse( $manager->client()->is_activated() );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
		$this->assertArrayNotHasKey( 'test_addon_license_state', $this->kit->store->data );

		$client                     = $this->activated()->client();
		$this->kit->server->queue[] = $answer();
		$this->assertSame( array( 'status' => 'removed_locally', 'reason' => 'site_mismatch' ), $client->deactivate() );
	}

	public function test_al4_site_health_shows_the_license_without_secrets(): void {
		$info = $this->kit->manager()->debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );
		$this->assertArrayHasKey( 'wp-core', $info, 'Other sections stay.' );
		$this->assertSame( 'unlicensed', $info['test_addon_license']['fields']['state']['debug'] );
		$this->assertSame( 'never', $info['test_addon_license']['fields']['last_checked']['debug'] );

		$manager                  = $this->activated();
		$this->kit->server->queue = array( new WP_Error( 'http_request_failed', 'down' ) );
		$this->kit->advance( 3600 );
		$manager->refresh();

		$section = $manager->debug_information( array() )['test_addon_license'];
		$fields  = $section['fields'];
		$this->assertSame( 'Test Add-on – License', $section['label'] );
		$this->assertSame( array( 'state', 'last_checked', 'last_error', 'offline_until', 'environment', 'channel', 'installation', 'server', 'signature', 'keys', 'version' ), array_keys( $fields ) );
		$this->assertSame( 'active', $fields['state']['debug'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->kit->now - 3600 ) . ' UTC', $fields['last_checked']['debug'] );
		$this->assertSame( 'wplc_connection_failed, ' . gmdate( 'Y-m-d H:i:s', $this->kit->now ) . ' UTC', $fields['last_error']['debug'] );
		$this->assertStringContainsString( 'could not be reached', $fields['last_error']['value'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->kit->now - 3600 + 14 * DAY_IN_SECONDS ) . ' UTC', $fields['offline_until']['debug'] );
		$this->assertSame( 'production', $fields['environment']['debug'] );
		$this->assertSame( 'stable', $fields['channel']['debug'] );
		$this->assertSame( 'license.example', $fields['server']['value'] );
		$this->assertSame( '2.4.0', $fields['version']['value'] );
		$this->assertSame( substr( hash( 'sha256', base64_decode( $this->kit->server->public_key ) ), 0, 16 ), $fields['keys']['debug'] );
		$this->assertContains( $fields['signature']['debug'], array( 'native', 'compat' ) );

		// Masked: the installation ID is shortened; secret, key and snapshot never appear.
		$credentials = $this->kit->credentials();
		$this->assertSame( substr( $credentials['installation_id'], 0, 8 ) . '…', $fields['installation']['value'] );
		$json = (string) json_encode( $section, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		foreach ( array( $credentials['installation_secret'], $credentials['installation_id'], $credentials['license_key_hash'], self::KEY, 'payload' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}

		// A clock ahead names its reason.
		$this->kit       = new WPLC_Client_Test_Kit();
		$this->kit->now += 16 * DAY_IN_SECONDS;
		$fields          = $this->activated()->debug_information( array() )['test_addon_license']['fields'];
		$this->assertSame( 'stale (clock_ahead)', $fields['state']['debug'] );
		$this->assertSame( 'Could not be verified for too long – The clock of this site is ahead, or the offline period has expired.', $fields['state']['value'] );
	}

	public function test_audit16_errors_shown_by_the_form_are_translated_when_shown(): void {
		$manager = $this->kit->manager();
		// An error created before init carries its code as message; the form looks the text up.
		$early = new WP_Error( 'wplc_not_activated', 'wplc_not_activated' );
		$this->assertSame( 'This site has not been activated with a license key yet.', $manager->error_text( $early ) );
		$this->assertSame( 'The license server could not be reached. Premium features keep working; the check is repeated automatically.', $manager->error_text( new WP_Error( 'wplc_backoff', 'wplc_backoff' ) ) );
		$this->assertSame( 'Kept as it is.', $manager->error_text( new WP_Error( 'wplc_update_failed', 'Kept as it is.' ) ) );
	}

	public function test_p48_the_server_url_filter_counts_when_added_after_the_add_on_was_loaded(): void {
		$manager = $this->kit->manager();
		$GLOBALS['wplc_test_filters']['test_addon_license_server_url'][] = static function () {
			return 'https://staging-shop.example';
		};

		$this->assertSame( 'staging-shop.example', $manager->client()->server_host() );
	}

	public function test_p49_settings_url_of_an_add_on_with_its_own_screen(): void {
		$this->assertSame( '', $this->kit->manager( array( 'settings_parent' => false ) )->settings_url(), 'No screen of this class: no link to a page that does not exist.' );

		$own = 'https://shop.example/wp-admin/admin.php?page=my-addon&tab=license';
		$this->assertSame( $own, $this->kit->manager( array( 'settings_parent' => false, 'settings_url' => $own ) )->settings_url() );
	}

	// ------------------------------------------------------------------
	// SET-LIC-33, -34, -35 (library 2.4.0): beta by choice, environment, brand.
	// ------------------------------------------------------------------

	/** Channel sent with the last `POST /updates/check`. */
	private function channel_of_the_update_check( LicenseManager $manager ): string {
		$manager->updater()->metadata( true );
		$checks = array_values( array_filter( $this->kit->server->requests, static function ( array $request ) { return '/wplc/v1/updates/check' === $request['route']; } ) );
		$this->assertNotEmpty( $checks );

		return (string) end( $checks )['body']['channel'];
	}

	public function test_set_lic33_without_the_build_switch_nothing_changes(): void {
		$manager = $this->activated();

		$this->assertSame( 'stable', $manager->update_channel() );
		$this->assertFalse( $manager->offers_channel_choice() );
		$this->assertSame( 'wplc_beta_unavailable', $manager->choose_beta( true )->get_error_code() );
		$message = $manager->run_form_action( 'channel', '', false, array( 'beta' => true ) );
		$this->assertSame( 'error', $message['type'] );
		$this->assertStringContainsString( 'not available for this license', $message['text'] );
		$this->assertArrayNotHasKey( 'test_addon_license_channel', $this->kit->store->data );

		// A value written by hand does not count either: the build decides whether there is a choice.
		$this->kit->store->data['test_addon_license_channel'] = 'beta';
		$this->assertSame( 'stable', $this->channel_of_the_update_check( $this->kit->manager() ) );

		// `channel => beta` of the build, as before: every site asks for beta, no box.
		$beta = $this->kit->manager( array( 'channel' => 'beta', 'allow_channel_choice' => true ) );
		$this->assertSame( 'beta', $beta->update_channel() );
		$this->assertFalse( $beta->offers_channel_choice() );
	}

	public function test_set_lic33_the_customer_opts_in_per_site_and_the_update_check_asks_for_beta(): void {
		$this->activated();
		$manager = $this->kit->manager( array( 'allow_channel_choice' => true ) );

		$this->assertTrue( $manager->offers_channel_choice(), 'A server without update_channels gives beta to every client.' );
		$this->assertFalse( $manager->beta_chosen() );
		$this->assertSame( 'stable', $this->channel_of_the_update_check( $manager ), 'Default off.' );

		$message = $manager->run_form_action( 'channel', '', false, array( 'beta' => true ) );
		$this->assertSame( array( 'type' => 'success', 'text' => 'This site now receives beta versions.' ), $message );
		$this->assertSame( 'beta', $this->kit->store->data['test_addon_license_channel'] );
		$this->assertTrue( $manager->beta_chosen() );
		$this->assertSame( 'beta', $manager->update_channel() );
		$this->assertSame( 'beta', $this->channel_of_the_update_check( $this->kit->manager( array( 'allow_channel_choice' => true ) ) ) );

		$message = $manager->run_form_action( 'channel', '', false, array( 'beta' => false ) );
		$this->assertSame( 'This site now receives stable versions only.', $message['text'] );
		$this->assertArrayNotHasKey( 'test_addon_license_channel', $this->kit->store->data );
		$this->assertSame( 'stable', $this->channel_of_the_update_check( $manager ) );

		// Uninstall removes the choice with the other data.
		$manager->choose_beta( true );
		$manager->uninstall();
		$this->assertArrayNotHasKey( 'test_addon_license_channel', $this->kit->store->data );
	}

	public function test_set_lic33_the_box_follows_the_signed_update_channels_and_needs_an_activation(): void {
		$this->assertFalse( $this->kit->manager( array( 'allow_channel_choice' => true ) )->offers_channel_choice(), 'No activation, no box.' );

		$this->activated();
		$this->kit->server->queue[] = $this->kit->server->signed( $this->kit->server->entitlement( array( 'update_channels' => array( 'stable' ) ) ) );
		$manager                    = $this->kit->manager( array( 'allow_channel_choice' => true ) );
		$this->assertInstanceOf( LicenseStatus::class, $manager->refresh() );
		$this->assertFalse( $manager->offers_channel_choice(), 'The license excludes beta.' );
		$this->assertSame( 'wplc_beta_unavailable', $manager->choose_beta( true )->get_error_code() );

		$this->kit->advance( 60 );
		$this->kit->server->queue[] = $this->kit->server->signed( $this->kit->server->entitlement( array( 'update_channels' => array( 'stable', 'beta' ) ) ) );
		$manager->refresh();
		$this->assertTrue( $manager->offers_channel_choice() );
		$this->assertTrue( $manager->choose_beta( true ) );

		// A site that chose beta keeps the box to switch back, also when the license no longer covers beta.
		$this->kit->advance( 60 );
		$this->kit->server->queue[] = $this->kit->server->signed( $this->kit->server->entitlement( array( 'update_channels' => array( 'stable' ) ) ) );
		$manager->refresh();
		$this->assertTrue( $manager->offers_channel_choice() );
		$this->assertTrue( $manager->choose_beta( false ) );
		$this->assertFalse( $manager->offers_channel_choice() );

		// A copy of the site offers nothing.
		$this->kit->store->site = 'https://copy.example';
		$this->assertFalse( $this->kit->manager( array( 'allow_channel_choice' => true ) )->offers_channel_choice() );
	}

	public function test_set_lic34_the_environment_chosen_on_the_form_is_sent_and_unknown_values_mean_detected(): void {
		$manager = $this->kit->manager();
		$this->assertSame( array( 'production', 'staging', 'development' ), LicenseClient::ENVIRONMENTS );

		$this->assertSame( 'success', $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'development' ) )['type'] );
		$this->assertSame( 'development', $this->kit->server->requests[0]['body']['environment'] );
		$this->assertSame( 'development', $manager->status()->environment );
		$this->assertSame( 'success', $manager->run_form_action( 'deactivate', '', true )['type'] );

		$this->kit->server->requests = array();
		$this->assertSame( 'success', $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'qa-box' ) )['type'] );
		$this->assertSame( 'production', $this->kit->server->requests[0]['body']['environment'], 'An unknown value falls back to the detection.' );
	}

	public function test_set_lic34_a_staging_site_needs_the_live_site_and_is_told_when_it_counts_as_live(): void {
		$manager = $this->kit->manager();
		$parent  = '0b7f5a52-3c1d-4e8f-9a6b-2d4c5e6f7a8b';

		// Without the installation ID of the live site nothing is sent, and the text says where to find it.
		foreach ( array( '', 'not-an-id' ) as $given ) {
			$message = $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'staging', 'staging_parent' => $given ) );
			$this->assertSame( 'error', $message['type'] );
			$this->assertStringContainsString( 'installation ID of its live site', $message['text'] );
		}
		$this->assertSame( array(), $this->kit->server->requests );

		// The server counts the staging request as a live site (address unrelated to the live site).
		$server          = $this->kit->server;
		$server->queue[] = static function ( array $request ) use ( $server ) {
			$server->secrets[ $request['body']['installation_id'] ] = $request['body']['installation_secret'];
			return WPLC_Test_Server::json( 200, $server->envelope( $request, $server->entitlement( array( 'environment' => 'production' ) ) ) );
		};
		$message = $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'staging', 'staging_parent' => strtoupper( $parent ) ) );
		$this->assertSame( 'success', $message['type'] );
		$this->assertStringContainsString( 'counts it as a live site, not as a staging site', $message['text'] );
		$this->assertSame( 'staging', $server->requests[0]['body']['environment'] );
		$this->assertSame( $parent, $server->requests[0]['body']['staging_parent_installation_id'] );
		$this->assertSame( 'production', $manager->status()->environment, 'The screen shows what the server stored.' );
		$manager->run_form_action( 'deactivate', '', true );

		// The same with all live slots in use: the error explains why a staging site needed one.
		$server->queue[] = WPLC_Test_Server::error( 409, 'site_limit', array( 'site_limit' => 1, 'site_count' => 1, 'environment' => 'production', 'requested_environment' => 'staging' ) );
		$message         = $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'staging', 'staging_parent' => $parent ) );
		$this->assertSame( 'error', $message['type'] );
		$this->assertStringContainsString( 'counts it as a further live site', $message['text'] );
		$this->assertStringContainsString( '(site_limit)', $message['text'] );

		// A real staging activation keeps the usual message.
		$message = $manager->run_form_action( 'activate', self::KEY, false, array( 'environment' => 'staging', 'staging_parent' => $parent ) );
		$this->assertSame( 'The license was activated for this site.', $message['text'] );
		$this->assertSame( 'staging', $manager->status()->environment );
	}

	public function test_set_lic35_brand_values_of_the_build_and_their_checks(): void {
		$this->assertSame( array(), LicenseManager::BUILD_BRAND, 'The library itself carries no brand.' );
		$this->assertSame(
			array( 'support_email' => '', 'support_url' => '', 'key_placeholder' => 'WPLC-…' ),
			$this->kit->manager()->brand()
		);
		$this->assertSame(
			array( 'support_email' => '', 'support_url' => '', 'key_placeholder' => 'WPLC-…' ),
			$this->kit->manager( array( 'brand' => array( 'support_email' => 'not an address', 'support_url' => 'javascript:alert(1)', 'key_placeholder' => '  ' ) ) )->brand(),
			'Invalid values are dropped.'
		);

		$brand   = array( 'support_email' => 'help@vendor.example', 'support_url' => 'https://vendor.example/support', 'key_placeholder' => 'VEND-' . str_repeat( 'x', 100 ) );
		$manager = $this->kit->manager( array( 'brand' => $brand ) );
		$this->assertSame( 'help@vendor.example', $manager->brand()['support_email'] );
		$this->assertSame( 'https://vendor.example/support', $manager->brand()['support_url'] );
		$this->assertSame( 80, strlen( $manager->brand()['key_placeholder'] ), 'At most 80 characters.' );

		// The notices of a suspended or revoked license say whom to ask; the others stay as they were.
		$manager->activate( self::KEY );
		$this->kit->server->queue[] = $this->kit->server->signed( $this->kit->server->entitlement( array( 'license_status' => 'suspended', 'access' => false ) ) );
		$manager->refresh();
		$this->assertSame( LicenseStatus::STATE_SUSPENDED, $manager->status()->state );
		$this->assertStringEndsWith( 'Please contact support. Support: help@vendor.example · https://vendor.example/support', $manager->notice()['text'] );
		$this->assertStringNotContainsString( 'Support:', (string) ( $this->kit->manager()->notice()['text'] ?? '' ) );
	}
}
