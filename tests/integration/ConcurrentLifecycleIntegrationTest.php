<?php
/**
 * T-22: regression tests for defects found by the concurrency flow (tests/e2e/flows/11-concurrency.php).
 *
 * A second process is simulated where it acts: inside the gateway call (the window in which
 * an administrator, a refund or the customer changes the license), inside the license
 * issuer between its "already issued?" check and its INSERT, and as a second database
 * connection that holds the per-license lock.
 *
 * @package WPLC
 */

use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;

if ( ! class_exists( 'WPLC_Test_Interleaving_Adapter' ) ) {
	/**
	 * Recurring adapter that runs a callback while it "charges" and then succeeds.
	 */
	final class WPLC_Test_Interleaving_Adapter implements \WPLC\Gateway\RecurringGatewayAdapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/**
		 * What another request does while the gateway call is running.
		 *
		 * @var callable|null
		 */
		public $during_charge;

		/**
		 * Number of charge calls.
		 *
		 * @var int
		 */
		public $charges = 0;

		public function gateway_id(): string {
			return 'wplc_interleave';
		}

		public function supports_checkout(): bool {
			return true;
		}

		public function supports_saved_token( int $token_id, int $customer_id ): bool {
			$token = WC_Payment_Tokens::get( $token_id );

			return $token && 'wplc_interleave' === $token->get_gateway_id() && (int) $token->get_user_id() === $customer_id;
		}

		public function charge( $order, int $token_id, string $idempotency_key ) {
			++$this->charges;
			if ( $this->during_charge ) {
				call_user_func( $this->during_charge );
			}

			return array(
				'success'        => true,
				'transaction_id' => 'txn_interleave_' . $this->charges,
			);
		}
	}
}

final class ConcurrentLifecycleIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Adapter.
	 *
	 * @var WPLC_Test_Interleaving_Adapter
	 */
	private $adapter;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = new WPLC_Test_Interleaving_Adapter();
		add_filter(
			'wplc_recurring_gateway_adapters',
			function ( $adapters ) {
				return array_merge( array( $this->adapter ), is_array( $adapters ) ? $adapters : array() );
			},
			99
		);
	}

	/**
	 * A renewing license whose paid period ended an hour ago, with a saved token and a due renewal.
	 *
	 * @return array{license_id:int,renewal_id:int,source_order_id:int}
	 */
	private function due_renewal(): array {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$source   = $this->create_paid_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );

		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 10, false ) );
		$token->set_gateway_id( 'wplc_interleave' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $customer );
		$this->assertGreaterThan( 0, $token->save() );

		$paid_until = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$license_id = $this->seed_license(
			array(
				'customer_id'          => $customer,
				'paid_until'           => $paid_until,
				'next_payment_at'      => $paid_until,
				'renewal_enabled'      => 1,
				'payment_gateway_id'   => 'wplc_interleave',
				'payment_token_id'     => $token->get_id(),
				'renewal_amount_minor' => 4900,
				'renewal_currency'     => get_woocommerce_currency(),
				'source_order_id'      => $source->get_id(),
				'source_order_item_id' => $this->first_item( $source )->get_id(),
			)
		)['id'];
		$renewal_id = RenewalScheduler::schedule_next( $license_id );
		$this->assertGreaterThan( 0, $renewal_id, 'Fixture: no renewal row was scheduled.' );

		return array(
			'license_id'      => $license_id,
			'renewal_id'      => $renewal_id,
			'source_order_id' => (int) $source->get_id(),
		);
	}

	/**
	 * One renewal row.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return array<string,mixed>
	 */
	private function renewal( int $renewal_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A );
	}

	/**
	 * Audit rows of an object, oldest first.
	 *
	 * @param string $type Object type.
	 * @param int    $id Object ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit( string $type, int $id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ), ARRAY_A );
	}

	public function test_suspension_while_the_charge_runs_sends_the_paid_renewal_to_review(): void {
		$fixture    = $this->due_renewal();
		$license_id = $fixture['license_id'];
		$before     = $this->license( $license_id );
		$this->adapter->during_charge = static function () use ( $license_id ) {
			LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Fraud review', 1 );
		};

		RenewalProcessor::process( $fixture['renewal_id'] );

		$license = $this->license( $license_id );
		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 1, $this->adapter->charges );
		$this->assertSame( 'suspended', $license['status'], 'The suspension made during the charge must stand.' );
		$this->assertSame( $before['paid_until'], $license['paid_until'], 'A suspended license is not extended.' );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'], 'A charge that could not be booked must not be recorded as paid.' );
		$this->assertContains( 'renewal_review_required', array_column( $this->audit( 'renewal', $fixture['renewal_id'] ), 'action' ) );
		$this->assertNotContains( 'renewal_paid', array_column( $this->audit( 'renewal', $fixture['renewal_id'] ), 'action' ) );
		$order = wc_get_order( (int) $renewal['renewal_order_id'] );
		$this->assertTrue( $order->is_paid(), 'The order stays paid: the money was taken.' );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'could not be extended', $notes );

		// A repeated paid-order hook while the license is still suspended reports nothing new.
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );
		$this->assertSame( 1, count( array_keys( array_column( $this->audit( 'renewal', $fixture['renewal_id'] ), 'action' ), 'renewal_review_required', true ) ) );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'] );
	}

	public function test_refund_of_the_original_order_while_the_charge_runs_sends_the_renewal_to_review(): void {
		$fixture    = $this->due_renewal();
		$license_id = $fixture['license_id'];
		$source_id  = $fixture['source_order_id'];
		$this->adapter->during_charge = static function () use ( $source_id ) {
			LicenseLifecycle::handle_full_refund( $source_id );
		};

		RenewalProcessor::process( $fixture['renewal_id'] );

		$license = $this->license( $license_id );
		$this->assertSame( array( 'revoked', '0' ), array( $license['status'], (string) $license['renewal_enabled'] ) );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'] );
	}

	public function test_cancellation_while_the_charge_runs_is_not_lost(): void {
		$fixture    = $this->due_renewal();
		$license_id = $fixture['license_id'];
		$this->adapter->during_charge = static function () use ( $license_id ) {
			LicenseLifecycle::schedule_cancellation( $license_id, 'Customer cancelled', 0 );
		};

		RenewalProcessor::process( $fixture['renewal_id'] );

		$license = $this->license( $license_id );
		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'], 'The period that was charged is booked.' );
		$this->assertSame( $renewal['period_end'], $license['paid_until'] );
		$this->assertSame( '1', (string) $license['cancel_at_period_end'], 'A renewal must not reset a cancellation made while it was charging.' );

		// The next period is never charged.
		$this->adapter->during_charge = null;
		$next = RenewalScheduler::schedule_next( $license_id );
		RenewalProcessor::process( $next );
		$this->assertSame( 1, $this->adapter->charges );
		$this->assertSame( 'cancelled', $this->renewal( $next )['status'] );
	}

	public function test_lifecycle_change_waits_for_another_connection_that_holds_the_license_lock(): void {
		$license_id = $this->seed_license()['id'];
		$other      = mysqli_init();
		$this->assertTrue( $other->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ), 'A second database connection is needed.' );
		$name = LicenseLifecycle::lock_name( $license_id );
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0] );

		add_filter( 'wplc_license_lock_timeout', '__return_zero' );
		$changed = LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'While another request changes the license', 1 );
		$this->assertFalse( $changed, 'A change must not run while another connection holds the license lock.' );
		$this->assertSame( 'active', $this->license( $license_id )['status'] );
		$this->assertSame( array(), $this->audit( 'license', $license_id ) );

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Lock is free again', 1 ) );
		remove_filter( 'wplc_license_lock_timeout', '__return_zero' );
	}

	public function test_status_listeners_run_after_the_license_lock_is_released(): void {
		$license_id = $this->seed_license()['id'];
		$held       = null;
		$listener   = static function ( $id ) use ( &$held ) {
			global $wpdb;
			$held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', LicenseLifecycle::lock_name( (int) $id ) ) );
		};
		add_action( 'wplc/license_status_changed', $listener, 10, 1 );
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Listener check', 1 ) );
		remove_action( 'wplc/license_status_changed', $listener, 10 );

		$this->assertSame( '0', $held, 'Listeners may take other locks; they must not run while the license lock is held.' );
	}

	public function test_a_unit_issued_by_a_concurrent_hook_is_reused_without_a_database_error(): void {
		$product = $this->create_license_product();
		$order   = $this->create_order( array( array( $product, 2 ) ) );
		// Mark the order paid without the paid-order hooks; the test calls the issuer itself.
		$hooks = array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' );
		foreach ( $hooks as $hook ) {
			remove_action( $hook, array( LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		}
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		$order->save();
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		}
		$order_id = (int) $order->get_id();
		$this->assertSame( array(), $this->licenses_for_order( $order_id ), 'Fixture: licenses were issued too early.' );

		// Another paid-order hook issues every unit right after this request checked that unit 0
		// does not exist yet (the filter runs inside create_license(), before the INSERT).
		$raced  = false;
		$racer  = static function ( $token_id ) use ( &$raced, $order_id ) {
			if ( ! $raced ) {
				$raced = true;
				LicenseIssuer::maybe_issue_order( $order_id );
			}
			return $token_id;
		};
		$issued = 0;
		$count  = static function () use ( &$issued ) {
			++$issued;
		};
		add_filter( 'wplc_payment_token_id_for_order', $racer );
		add_action( 'wplc/license_issued', $count );
		// wpdb reports database errors through error_log(); capture them.
		$log          = wp_tempnam( 'wplc-t22-log' );
		$previous_log = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		LicenseIssuer::maybe_issue_order( $order_id );
		ini_set( 'error_log', (string) $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		remove_filter( 'wplc_payment_token_id_for_order', $racer );
		remove_action( 'wplc/license_issued', $count );

		$this->assertTrue( $raced, 'The simulated concurrent hook did not run.' );
		$licenses = $this->licenses_for_order( $order_id );
		$this->assertSame( array( '0', '1' ), array_map( 'strval', array_column( $licenses, 'unit_index' ) ) );
		$this->assertSame( 2, $issued, 'Each unit is announced once, by the request that created it.' );
		$item = $this->first_item( wc_get_order( $order_id ) );
		$this->assertSame( array_map( 'intval', array_column( $licenses, 'id' ) ), array_map( 'intval', (array) $item->get_meta( '_wplc_license_ids', true ) ), 'The item lists every license, not only those this request created.' );
		$this->assertStringNotContainsString( 'Duplicate entry', $logged, 'The expected duplicate key of the losing request must not be logged as a database error.' );
	}
}
