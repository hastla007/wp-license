<?php
/**
 * SET-LIC-46: outgoing webhooks against a real database, Action Scheduler and the WordPress HTTP API
 * (requests answered through `pre_http_request`).
 *
 * @package WPLC
 */

use WPLC\CLI\WebhookCommand;
use WPLC\Events\AfterCommit;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\Maintenance\Retention;
use WPLC\Webhooks\Admin;
use WPLC\Webhooks\Deliveries;
use WPLC\Webhooks\Dispatcher;
use WPLC\Webhooks\Endpoints;
use WPLC\Webhooks\EventCatalog;
use WPLC\Webhooks\Payload;
use WPLC\Webhooks\UrlGuard;

final class WebhooksIntegrationTest extends WPLC_Integration_TestCase {

	private const URL = 'https://hooks.example.com/licenses';

	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private $requests = array();

	/** @var int HTTP status of the mocked receiver; 0 answers with a WP_Error. */
	private $status = 200;

	/** @var int */
	private $admin = 0;

	/** @var array<string,string> Host => address the mocked DNS returns. */
	private $dns = array();

	public function set_up(): void {
		parent::set_up();
		AfterCommit::reset();
		$this->requests = array();
		$this->status   = 200;
		$this->dns      = array( 'hooks.example.com' => '93.184.215.14' );
		UrlGuard::set_resolver(
			function ( string $host ): array {
				return isset( $this->dns[ $host ] ) ? array( $this->dns[ $host ] ) : array();
			}
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				if ( 0 === $this->status ) {
					return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
				}
				return array(
					'headers'  => array(),
					'body'     => 'ok',
					'response' => array(
						'code'    => $this->status,
						'message' => 'Status',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		UrlGuard::set_resolver( null );
		AfterCommit::reset();
		delete_option( Endpoints::INDEX_OPTION );
		delete_option( Endpoints::DISABLED_OPTION );
		parent::tear_down();
	}

	/**
	 * Create an endpoint and return its ID and secret.
	 *
	 * @param array<int,string> $events Events.
	 * @return array{id:int,secret:string}
	 */
	private function endpoint( array $events = array( 'license.status_changed' ), string $url = self::URL ): array {
		$created = Endpoints::create( 'CRM', $url, $events, true, $this->admin );
		$this->assertTrue( $created['ok'], 'Endpoint created: ' . $created['error'] );

		return array(
			'id'     => $created['id'],
			'secret' => $created['secret'],
		);
	}

	/**
	 * Pending queue entries of a delivery.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return int
	 */
	private function queued( int $delivery_id ): int {
		return count( as_get_scheduled_actions( array( 'hook' => Dispatcher::DELIVER_HOOK, 'args' => array( $delivery_id ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'wplc' ), 'ids' ) );
	}

	/**
	 * Run the queued attempt of a delivery the way Action Scheduler does: the queue entry is no
	 * longer pending while it runs (so the attempt can schedule its retry).
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return void
	 */
	private function run_queued( int $delivery_id ): void {
		as_unschedule_all_actions( Dispatcher::DELIVER_HOOK, array( $delivery_id ), 'wplc' );
		do_action( Dispatcher::DELIVER_HOOK, $delivery_id );
	}

	/**
	 * Times of the pending queue entries of a delivery.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return array<int,int>
	 */
	private function scheduled_times( int $delivery_id ): array {
		$times = array();
		foreach ( as_get_scheduled_actions( array( 'hook' => Dispatcher::DELIVER_HOOK, 'args' => array( $delivery_id ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'wplc' ) ) as $action ) {
			$date    = $action->get_schedule()->get_date();
			$times[] = null === $date ? 0 : $date->getTimestamp();
		}
		return $times;
	}

	/**
	 * Deliveries of an endpoint, newest first.
	 *
	 * @param int $endpoint_id Endpoint ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function deliveries( int $endpoint_id ): array {
		return Deliveries::for_endpoint( $endpoint_id, 100 );
	}

	public function test_schema_has_both_tables(): void {
		global $wpdb;
		$tables = Schema::table_names();
		foreach ( array( 'webhook_endpoints', 'webhook_deliveries' ) as $key ) {
			$this->assertSame( $tables[ $key ], $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables[ $key ] ) ) );
		}
		$this->assertSame( '31', Schema::VERSION );
		$this->assertTrue( Schema::run_registered_step( '31' ), 'Step 31 finds both tables and their indexes.' );
	}

	public function test_a_status_change_queues_after_the_commit_and_is_delivered_signed(): void {
		$endpoint = $this->endpoint();
		$seeded   = $this->seed_license( array( 'customer_id' => $this->admin ) );

		AfterCommit::begin();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Webhook check', $this->admin ) );
		$this->assertSame( array(), $this->deliveries( $endpoint['id'] ), 'Nothing is queued before the commit.' );
		AfterCommit::commit();

		$rows = $this->deliveries( $endpoint['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'license.status_changed', $rows[0]['event'] );
		$this->assertSame( Deliveries::PENDING, $rows[0]['status'] );
		$this->assertSame( 1, $this->queued( $rows[0]['id'] ), 'One Action Scheduler job per delivery.' );
		$this->assertSame( array(), $this->requests, 'Nothing is sent inside the request that changed the license.' );

		$this->run_queued( $rows[0]['id'] );

		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( self::URL, $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'], 'Sent with wp_safe_remote_post().' );
		$this->assertSame( 0, $request['args']['redirection'], 'Redirects are not followed.' );
		$headers = $request['args']['headers'];
		$body    = (string) $request['args']['body'];
		$this->assertTrue( Payload::verify( $endpoint['secret'], $headers['X-WPLC-Timestamp'], $body, $headers['X-WPLC-Signature'], time() ), 'The signature verifies with the secret shown once.' );
		$this->assertSame( '1', $headers['X-WPLC-Attempt'] );

		$payload = json_decode( $body, true );
		$this->assertSame( $rows[0]['event_id'], $payload['id'] );
		$this->assertSame( $payload['id'], $headers['X-WPLC-Event-Id'] );
		$this->assertSame( 'license.status_changed', $payload['event'] );
		$this->assertSame( 'wplc/license_status_changed', $payload['hook'] );
		$this->assertSame( '1.0.0', $payload['contract_version'] );
		$this->assertSame(
			array(
				'license_id' => $seeded['id'],
				'old_status' => 'active',
				'new_status' => 'suspended',
				'action'     => 'admin_status_change',
				'license'    => array(
					'product_key' => $this->license( $seeded['id'] )['product_key'],
					'plan_key'    => $this->license( $seeded['id'] )['plan_key'],
					'status'      => 'suspended',
					'expires_at'  => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $this->license( $seeded['id'] )['paid_until'] . ' UTC' ) ),
				),
			),
			$payload['data']
		);
		// No key, key prefix, key hash, email address, customer ID or secret in the body.
		$customer_email = get_userdata( $this->admin )->user_email;
		foreach ( array( $seeded['key'], LicenseKey::hash( $seeded['key'] ), LicenseKey::prefix( $seeded['uuid'] ), 'WPLC-', $customer_email, $endpoint['secret'], 'customer' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $body );
		}

		$row = Deliveries::get( $rows[0]['id'] );
		$this->assertSame( Deliveries::DELIVERED, $row['status'] );
		$this->assertSame( 200, $row['response_code'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertNotSame( '', Endpoints::get( $endpoint['id'] )['last_success_at'] );

		// A duplicate queue entry does not send again.
		$this->run_queued( $rows[0]['id'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_rolled_back_change_queues_nothing_and_unsubscribed_events_cost_no_delivery(): void {
		$endpoint = $this->endpoint( array( 'license.plan_changed' ) );
		$seeded   = $this->seed_license();

		AfterCommit::begin();
		LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Rolled back', $this->admin );
		AfterCommit::rollback();
		AfterCommit::on_shutdown();
		LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Not subscribed', $this->admin );

		$this->assertSame( array(), $this->deliveries( $endpoint['id'] ) );
	}

	public function test_without_an_active_endpoint_an_action_runs_no_webhook_query(): void {
		global $wpdb;
		$seeded = $this->seed_license();
		$this->assertFalse( get_option( Endpoints::INDEX_OPTION ) );
		$tables  = Schema::table_names();
		$queries = array();
		$watch   = static function ( $query ) use ( &$queries, $tables ) {
			if ( false !== strpos( $query, $tables['webhook_endpoints'] ) || false !== strpos( $query, $tables['webhook_deliveries'] ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'No webhook', $this->admin );
		remove_filter( 'query', $watch );
		$this->assertSame( array(), $queries );
		unset( $wpdb );
	}

	public function test_failed_attempts_are_retried_with_backoff_and_counted_once_per_delivery(): void {
		$endpoint     = $this->endpoint();
		$this->status = 503;
		$ids          = Dispatcher::emit( 'license.status_changed', array( 'license_id' => 0 ) );
		$this->assertCount( 1, $ids );
		$id = $ids[0];

		$this->run_queued( $id );
		$row = Deliveries::get( $id );
		$this->assertSame( Deliveries::RETRYING, $row['status'] );
		$this->assertSame( 503, $row['response_code'] );
		$this->assertSame( 'HTTP 503', $row['error'] );
		$next = $this->scheduled_times( $id );
		$this->assertCount( 1, $next );
		$this->assertEqualsWithDelta( time() + 60, $next[0], 5, 'The second attempt follows after one minute.' );
		$this->assertSame( 0, Endpoints::get( $endpoint['id'] )['consecutive_failures'], 'A retry is not a failed delivery yet.' );

		foreach ( array( 2 => 300, 3 => 1800, 4 => 7200 ) as $attempt => $delay ) {
			$this->run_queued( $id );
			$this->assertSame( $attempt, Deliveries::get( $id )['attempts'] );
			$this->assertEqualsWithDelta( time() + $delay, (int) strtotime( Deliveries::get( $id )['next_attempt_at'] . ' UTC' ), 5 );
			$this->assertEqualsWithDelta( time() + $delay, $this->scheduled_times( $id )[0] ?? 0, 5 );
		}
		$this->status = 0;
		$this->run_queued( $id );
		$row = Deliveries::get( $id );
		$this->assertSame( Deliveries::FAILED, $row['status'], 'No attempt left after the fifth.' );
		$this->assertSame( 5, $row['attempts'] );
		$this->assertStringContainsString( 'timed out', $row['error'] );
		$this->assertCount( 5, $this->requests );
		$this->assertSame( array( '1', '2', '3', '4', '5' ), array_map( static fn( $r ) => $r['args']['headers']['X-WPLC-Attempt'], $this->requests ) );
		$this->assertCount( 1, array_unique( array_map( static fn( $r ) => $r['args']['headers']['X-WPLC-Event-Id'], $this->requests ) ), 'The event ID stays the same for every attempt.' );
		$this->assertCount( 1, array_unique( array_map( static fn( $r ) => $r['args']['body'], $this->requests ) ), 'The body stays the same.' );
		$this->assertSame( 1, Endpoints::get( $endpoint['id'] )['consecutive_failures'] );

		// Redelivery: same event ID, attempts start again, success resets the counter.
		$this->status = 204;
		$this->assertTrue( Dispatcher::redeliver( $id ) );
		$this->assertFalse( Dispatcher::redeliver( $id ), 'A queued delivery cannot be queued twice.' );
		$this->run_queued( $id );
		$row = Deliveries::get( $id );
		$this->assertSame( Deliveries::DELIVERED, $row['status'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertSame( $this->requests[0]['args']['headers']['X-WPLC-Event-Id'], end( $this->requests )['args']['headers']['X-WPLC-Event-Id'] );
		$this->assertSame( 0, Endpoints::get( $endpoint['id'] )['consecutive_failures'] );
	}

	public function test_an_endpoint_is_switched_off_after_five_failed_deliveries_with_a_notice(): void {
		add_filter( 'wplc_webhook_retry_delays', '__return_empty_array' );
		$endpoint     = $this->endpoint();
		$this->status = 500;
		$fired        = array();
		add_action(
			'wplc/webhook_endpoint_disabled',
			static function ( $id, $failures ) use ( &$fired ) {
				$fired[] = array( $id, $failures );
			},
			10,
			2
		);

		$pending = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$pending = array_merge( $pending, Dispatcher::emit( 'license.status_changed', array( 'license_id' => $i ) ) );
		}
		foreach ( array_slice( $pending, 0, 5 ) as $id ) {
			$this->run_queued( $id );
		}

		$stored = Endpoints::get( $endpoint['id'] );
		$this->assertFalse( $stored['active'] );
		$this->assertSame( Endpoints::REASON_FAILURES, $stored['disabled_reason'] );
		$this->assertSame( array( array( $endpoint['id'], 5 ) ), $fired );
		$this->assertSame( array( $endpoint['id'] ), Endpoints::auto_disabled_ids() );
		$this->assertFalse( get_option( Endpoints::INDEX_OPTION ), 'No active endpoint left: actions cost no query.' );

		// The sixth queued delivery is not sent.
		$this->run_queued( $pending[5] );
		$this->assertCount( 5, $this->requests );
		$this->assertSame( Deliveries::CANCELLED, Deliveries::get( $pending[5] )['status'] );

		// Administrators see the notice; a shop manager does not.
		ob_start();
		Admin::render_disabled_notice();
		$this->assertStringContainsString( 'switched off because its deliveries failed repeatedly: CRM', (string) ob_get_clean() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		ob_start();
		Admin::render_disabled_notice();
		$this->assertSame( '', (string) ob_get_clean() );
		wp_set_current_user( $this->admin );

		// Switching it on clears notice and counter.
		$this->assertTrue( Endpoints::set_active( $endpoint['id'], true, $this->admin )['ok'] );
		$this->assertSame( array(), Endpoints::auto_disabled_ids() );
		$this->assertSame( 0, Endpoints::get( $endpoint['id'] )['consecutive_failures'] );
		$this->assertSame( array( 'license.status_changed' ), get_option( Endpoints::INDEX_OPTION ) );
	}

	public function test_the_address_is_checked_again_before_every_request(): void {
		$endpoint = $this->endpoint();
		$ids      = Dispatcher::emit( 'license.status_changed', array( 'license_id' => 1 ) );

		// DNS now points to the cloud metadata address (rebinding).
		$this->dns['hooks.example.com'] = '169.254.169.254';
		$this->run_queued( $ids[0] );

		$this->assertSame( array(), $this->requests, 'No request to an internal address.' );
		$row = Deliveries::get( $ids[0] );
		$this->assertSame( Deliveries::RETRYING, $row['status'] );
		$this->assertSame( 'Address refused: blocked_address', $row['error'] );
		unset( $endpoint );
	}

	public function test_private_and_plain_http_targets_cannot_be_saved(): void {
		$this->dns['internal.example.com'] = '10.0.0.8';
		foreach ( array(
			'http://hooks.example.com/x'      => UrlGuard::NOT_HTTPS,
			'https://127.0.0.1/x'             => UrlGuard::BLOCKED_ADDRESS,
			'https://internal.example.com/x'  => UrlGuard::BLOCKED_ADDRESS,
			'https://localhost/x'             => UrlGuard::LOCAL_HOST,
			'https://unknown.example.com/x'   => UrlGuard::UNRESOLVED,
		) as $url => $error ) {
			$this->assertSame( $error, Endpoints::create( 'x', $url, array( 'license.issued' ), true, $this->admin )['error'], $url );
		}
		$this->assertSame( 'no_events', Endpoints::create( 'x', self::URL, array( 'license.issued_with_key' ), true, $this->admin )['error'] );
		$this->assertSame( array(), Endpoints::all() );
	}

	public function test_the_secret_is_stored_encrypted_and_never_in_the_audit_log(): void {
		global $wpdb;
		$endpoint = $this->endpoint();
		$tables   = Schema::table_names();
		$stored   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT secret_encrypted FROM %i WHERE id = %d', $tables['webhook_endpoints'], $endpoint['id'] ) );
		$this->assertStringStartsWith( 'v1:integration-2026:', $stored );
		$this->assertStringNotContainsString( $endpoint['secret'], $stored );
		$this->assertSame( $endpoint['secret'], Endpoints::secret( $endpoint['id'] ) );

		$rotated = Endpoints::rotate_secret( $endpoint['id'], $this->admin );
		$this->assertNotSame( $endpoint['secret'], $rotated['secret'] );
		$this->assertSame( $rotated['secret'], Endpoints::secret( $endpoint['id'] ) );

		$audit = (string) wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s', $tables['audit_log'], 'webhook' ), ARRAY_A ) );
		$this->assertStringContainsString( 'webhook_endpoint_created', $audit );
		$this->assertStringContainsString( 'webhook_secret_rotated', $audit );
		$this->assertStringNotContainsString( $endpoint['secret'], $audit );
		$this->assertStringNotContainsString( $rotated['secret'], $audit );
		$this->assertStringNotContainsString( '/licenses', $audit, 'Only the host of the URL is logged (paths can carry tokens).' );
	}

	public function test_the_test_event_is_sent_once_also_to_an_endpoint_that_is_off(): void {
		$endpoint = $this->endpoint();
		Endpoints::set_active( $endpoint['id'], false, $this->admin );
		$this->status = 500;

		$delivery = Dispatcher::send_test( $endpoint['id'] );
		$this->assertSame( Deliveries::FAILED, $delivery['status'], 'Not retried.' );
		$this->assertSame( 0, $this->queued( $delivery['id'] ) );
		$this->assertSame( 0, Endpoints::get( $endpoint['id'] )['consecutive_failures'], 'A test does not count.' );
		$this->assertSame( EventCatalog::TEST_EVENT, json_decode( (string) $this->requests[0]['args']['body'], true )['event'] );
		$this->assertNull( Dispatcher::send_test( 999999 ) );
	}

	public function test_the_payload_filter_can_add_data_but_not_change_the_event_identity(): void {
		$this->endpoint( array( 'release.published' ) );
		add_filter(
			'wplc_webhook_payload',
			static function ( array $envelope, string $event ): array {
				$envelope['id']               = 'changed';
				$envelope['data']['extra']    = $event;
				return $envelope;
			},
			10,
			2
		);
		do_action( 'wplc/release_published', 4, 'acme-pro', '1.2.0', 'stable' );
		$rows = Deliveries::for_endpoint( Endpoints::all()[0]['id'] );
		$this->assertCount( 1, $rows );
		$payload = json_decode( (string) Deliveries::get( $rows[0]['id'] )['payload'], true );
		$this->assertSame( $rows[0]['event_id'], $payload['id'] );
		$this->assertSame(
			array(
				'release_id'  => 4,
				'product_key' => 'acme-pro',
				'version'     => '1.2.0',
				'channel'     => 'stable',
				'extra'       => 'release.published',
			),
			$payload['data']
		);
	}

	public function test_the_log_keeps_the_newest_rows_and_retention_removes_old_ones(): void {
		global $wpdb;
		$endpoint = $this->endpoint();
		$table    = Schema::table_names()['webhook_deliveries'];
		$old      = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert( $table, array( 'endpoint_id' => $endpoint['id'], 'event_id' => wp_generate_uuid4(), 'event' => 'license.issued', 'payload' => '{}', 'status' => 'delivered', 'created_at' => $old, 'updated_at' => $old ) );
		}
		$wpdb->insert( $table, array( 'endpoint_id' => $endpoint['id'], 'event_id' => wp_generate_uuid4(), 'event' => 'license.issued', 'payload' => '{}', 'status' => 'retrying', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS - 3600 ), 'updated_at' => $old ) );
		$fresh = Dispatcher::emit( 'license.status_changed', array( 'license_id' => 1 ) );

		$processed = Retention::run();
		$this->assertSame( 3, $processed['webhook_deliveries'] ?? 0, 'Finished rows older than 30 days are deleted; an open one gets a day more.' );
		$this->assertCount( 2, $this->deliveries( $endpoint['id'] ) );
		$this->assertNotNull( Deliveries::get( $fresh[0] ) );

		add_filter(
			'wplc_retention_days',
			static function ( $days ) {
				$days['webhook_deliveries'] = 0;
				return $days;
			}
		);
		$this->assertArrayNotHasKey( 'webhook_deliveries', Retention::run(), '0 switches the rule off.' );

		// The newest KEEP_PER_ENDPOINT rows are kept per endpoint.
		$now = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < Deliveries::KEEP_PER_ENDPOINT + 5; $i++ ) {
			$wpdb->insert( $table, array( 'endpoint_id' => $endpoint['id'], 'event_id' => wp_generate_uuid4(), 'event' => 'license.issued', 'payload' => '{}', 'status' => 'delivered', 'created_at' => $now, 'updated_at' => $now ) );
		}
		Deliveries::prune( $endpoint['id'] );
		$this->assertSame( Deliveries::KEEP_PER_ENDPOINT, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE endpoint_id = %d AND status = %s', $table, $endpoint['id'], 'delivered' ) ) );
		$this->assertSame( Deliveries::KEEP_PER_ENDPOINT + 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE endpoint_id = %d', $table, $endpoint['id'] ) ), 'Open deliveries are never pruned.' );
	}

	public function test_admin_actions_need_the_manufacturer_capability_and_a_valid_nonce(): void {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $manager );
		$result = $this->run_admin_post(
			Admin::ACTION,
			array(
				'operation' => 'save',
				'url'       => self::URL,
				'events'    => array( 'license.issued' ),
				'active'    => '1',
				'_wpnonce'  => wp_create_nonce( Admin::NONCE_ACTION ),
			)
		);
		$this->assertSame( 'die', $result['outcome'] );
		$this->assertSame( 403, $result['status'] );
		$this->assertSame( array(), Endpoints::all() );

		wp_set_current_user( $this->admin );
		$result = $this->run_admin_post(
			Admin::ACTION,
			array(
				'operation' => 'save',
				'url'       => self::URL,
				'events'    => array( 'license.issued' ),
				'_wpnonce'  => 'invalid',
			)
		);
		$this->assertSame( 'die', $result['outcome'] );
		$this->assertSame( array(), Endpoints::all() );
	}

	public function test_admin_screen_adds_an_endpoint_shows_the_secret_once_tests_and_redelivers(): void {
		$nonce  = wp_create_nonce( Admin::NONCE_ACTION );
		$result = $this->run_admin_post(
			Admin::ACTION,
			array(
				'operation' => 'save',
				'name'      => 'Billing system',
				'url'       => self::URL,
				'events'    => array( 'license.issued', 'activation.created', 'not.an.event' ),
				'active'    => '1',
				'_wpnonce'  => $nonce,
			)
		);
		$this->assertSame( 'redirect', $result['outcome'] );
		$endpoints = Endpoints::all();
		$this->assertCount( 1, $endpoints );
		$this->assertSame( array( 'license.issued', 'activation.created' ), $endpoints[0]['events'] );
		$this->assertTrue( $endpoints[0]['active'] );
		$id = $endpoints[0]['id'];

		ob_start();
		Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'The endpoint was added.', $html );
		$this->assertStringContainsString( 'value="' . Endpoints::secret( $id ) . '"', $html, 'The secret is shown after saving.' );
		ob_start();
		Admin::render();
		$this->assertStringNotContainsString( Endpoints::secret( $id ), (string) ob_get_clean(), 'Only once.' );

		$result = $this->run_admin_post( Admin::ACTION, array( 'operation' => 'test', 'endpoint_id' => $id, '_wpnonce' => $nonce ) );
		$this->assertStringContainsString( 'log=' . $id, $result['location'] );
		$this->assertCount( 1, $this->requests );
		$_GET['log'] = (string) $id;
		ob_start();
		Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'The test event was delivered (HTTP 200).', $html );
		$this->assertStringContainsString( EventCatalog::TEST_EVENT, $html );

		$this->status = 500;
		add_filter( 'wplc_webhook_retry_delays', '__return_empty_array' );
		$ids = Dispatcher::emit( 'license.issued', array( 'license_id' => 1, 'order_id' => 2 ) );
		$this->run_queued( $ids[0] );
		$this->assertSame( Deliveries::FAILED, Deliveries::get( $ids[0] )['status'] );
		$this->status = 200;
		$this->run_admin_post( Admin::ACTION, array( 'operation' => 'redeliver', 'delivery_id' => $ids[0], '_wpnonce' => $nonce ) );
		$this->assertSame( Deliveries::PENDING, Deliveries::get( $ids[0] )['status'] );
		$this->assertSame( 1, $this->queued( $ids[0] ) );

		// Delete needs the confirmation box.
		$this->run_admin_post( Admin::ACTION, array( 'operation' => 'delete', 'endpoint_id' => $id, '_wpnonce' => $nonce ) );
		$this->assertNotNull( Endpoints::get( $id ) );
		$this->run_admin_post( Admin::ACTION, array( 'operation' => 'delete', 'endpoint_id' => $id, 'confirm' => '1', '_wpnonce' => $nonce ) );
		$this->assertNull( Endpoints::get( $id ) );
		$this->assertSame( array(), $this->deliveries( $id ), 'The log goes with the endpoint.' );

		// The queued job of a deleted endpoint does nothing.
		$count = count( $this->requests );
		$this->run_queued( $ids[0] );
		$this->assertCount( $count, $this->requests );
	}

	public function test_wp_cli_adds_an_endpoint_with_all_events(): void {
		$result = WebhookCommand::run_add( self::URL, 'all', 'CLI', true );
		$this->assertTrue( $result['ok'] );
		$this->assertMatchesRegularExpression( '/^whsec_[0-9a-f]{48}$/', $result['secret'] );
		$this->assertSame( EventCatalog::names(), Endpoints::get( $result['id'] )['events'] );
		$rows = WebhookCommand::rows();
		$this->assertSame( 'yes', $rows[0]['active'] );
		$this->assertStringNotContainsString( $result['secret'], (string) wp_json_encode( $rows ), 'The list never prints the secret.' );

		$refused = WebhookCommand::run_add( 'http://hooks.example.com/', 'license.issued', '', true );
		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'The target URL must start with https://.', $refused['message'] );
	}
}
