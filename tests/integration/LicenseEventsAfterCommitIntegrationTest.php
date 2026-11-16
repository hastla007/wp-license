<?php
/**
 * WFC-LIC-07: `wplc/license_issued_followup`, `wplc/license_status_changed` and
 * `wplc/license_plan_changed` (and the new activation actions) fire after the commit.
 *
 * WordPress runs every test inside one transaction that is rolled back afterwards, so a
 * second database connection would never see anything. This class switches the test
 * connection to autocommit, as in production, and lets a listener read the stored state
 * through a second connection while the action runs. Everything the tests create is
 * deleted again in tear_down().
 *
 * @package WPLC
 */

use WPLC\License\ActivationManager;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;

final class LicenseEventsAfterCommitIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Highest ID per table before the test (table => array{0:column,1:max}).
	 *
	 * @var array<string,array{0:string,1:int}>
	 */
	private $watermarks = array();

	/** @var string[] */
	private $products_before = array();

	/** @var mysqli|null */
	private $other;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		// End the test transaction: from here on every statement is committed, as in production.
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		$tables = array(
			$wpdb->posts => 'ID',
			$wpdb->postmeta => 'meta_id',
			$wpdb->users => 'ID',
			$wpdb->usermeta => 'umeta_id',
			$wpdb->comments => 'comment_ID',
			$wpdb->commentmeta => 'meta_id',
			$wpdb->prefix . 'woocommerce_order_items' => 'order_item_id',
			$wpdb->prefix . 'woocommerce_order_itemmeta' => 'meta_id',
			$wpdb->prefix . 'actionscheduler_actions' => 'action_id',
			$wpdb->prefix . 'actionscheduler_logs' => 'log_id',
			$wpdb->prefix . 'wc_orders' => 'id',
			$wpdb->prefix . 'wc_orders_meta' => 'id',
			$wpdb->prefix . 'wc_order_addresses' => 'id',
			$wpdb->prefix . 'wc_order_operational_data' => 'id',
			$wpdb->prefix . 'wc_order_stats' => 'order_id',
			$wpdb->prefix . 'wc_order_product_lookup' => 'order_item_id',
			$wpdb->prefix . 'wc_customer_lookup' => 'customer_id',
		);
		foreach ( $this->tables() as $name => $table ) {
			if ( 'products' !== $name ) {
				$tables[ $table ] = 'id';
			}
		}
		foreach ( $tables as $table => $column ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$this->watermarks[ $table ] = array( $column, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(%i), 0) FROM %i', $column, $table ) ) );
			}
		}
		$this->products_before = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT product_key FROM %i', $this->tables()['products'] ) );

		$this->other = mysqli_init();
		$this->assertTrue( $this->other->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ), 'A second database connection is needed.' );
	}

	public function tear_down(): void {
		global $wpdb;

		if ( $this->other instanceof mysqli ) {
			$this->other->close();
		}
		foreach ( $this->watermarks as $table => $mark ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i > %d', $table, $mark[0], $mark[1] ) );
		}
		$products = $this->tables()['products'];
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT product_key FROM %i', $products ) ) as $key ) {
			if ( ! in_array( $key, $this->products_before, true ) ) {
				$wpdb->delete( $products, array( 'product_key' => $key ) );
			}
		}
		// Clean term relationships and lookup rows of the deleted products.
		$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l LEFT JOIN %i p ON p.ID = l.product_id WHERE p.ID IS NULL', $lookup, $wpdb->posts ) );
		}
		wp_cache_flush();

		// Back to the transaction WordPress rolls back in parent::tear_down().
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tear_down();
	}

	/**
	 * One column of a license as the second connection sees it.
	 *
	 * @param int    $license_id License ID.
	 * @param string $column     Column.
	 * @return string|null
	 */
	private function committed( int $license_id, string $column ): ?string {
		$result = $this->other->query( 'SELECT `' . $column . '` FROM `' . $this->tables()['licenses'] . '` WHERE id = ' . $license_id );
		$row    = $result ? $result->fetch_row() : null;

		return is_array( $row ) ? (string) $row[0] : null;
	}

	/**
	 * Whether any connection holds the license lock.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	private function license_lock_held( int $license_id ): bool {
		$name   = $this->other->real_escape_string( LicenseLifecycle::lock_name( $license_id ) );
		$result = $this->other->query( "SELECT IS_USED_LOCK('{$name}') IS NOT NULL" );

		return '1' === (string) $result->fetch_row()[0];
	}

	public function test_status_changed_fires_after_the_commit_and_the_lock(): void {
		$license_id = $this->seed_license()['id'];
		$seen       = array();
		add_action(
			'wplc/license_status_changed',
			function ( $id, $old, $new ) use ( &$seen ) {
				$seen[] = array( $this->committed( (int) $id, 'status' ), $new, $this->license_lock_held( (int) $id ) );
			},
			10,
			3
		);

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Commit check', 1 ) );
		$this->assertFalse( LicenseLifecycle::admin_set_status( $license_id, 'suspended', 'Same status again', 1 ) );

		$this->assertSame( array( array( 'suspended', 'suspended', false ) ), $seen, 'Runs once, sees the committed status through another connection and runs without the license lock.' );
	}

	public function test_renewal_status_event_sees_the_committed_term(): void {
		$license_id = $this->seed_license()['id'];
		$next       = gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS );
		$seen       = array();
		add_action(
			'wplc/license_status_changed',
			function ( $id ) use ( &$seen ) {
				$seen[] = array( $this->committed( (int) $id, 'paid_until' ), $this->license_lock_held( (int) $id ) );
			}
		);

		$this->assertTrue( LicenseLifecycle::mark_renewed_manually( $license_id, $next, 0 ) );

		$this->assertSame( array( array( $next, false ) ), $seen );
	}

	public function test_plan_changed_fires_after_the_commit_and_the_lock(): void {
		$customer = self::factory()->user->create();
		$solo     = $this->create_license_product( array( 'plan_key' => 'solo' ), '49.00' );
		$business = $this->create_license_product( array( 'plan_key' => 'business', 'live_site_limit' => 5 ), '149.00' );
		$order    = $this->create_paid_order( array( array( $solo, 1 ) ), $customer );
		$license  = (int) $this->licenses_for_order( $order->get_id() )[0]['id'];
		$seen     = array();
		add_action(
			'wplc/license_plan_changed',
			function ( $id ) use ( &$seen ) {
				$seen[] = array( 'action', $this->committed( (int) $id, 'plan_key' ), $this->license_lock_held( (int) $id ) );
			}
		);

		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( $license, $business->get_id(), 'Commit check', 1 ) );

		$this->assertSame( array( array( 'action', 'business', false ) ), $seen );
	}

	public function test_issued_followup_fires_after_the_license_is_committed(): void {
		$customer = self::factory()->user->create();
		$seeded   = $this->seed_license( array( 'customer_id' => $customer ) );
		$seen     = array();
		add_action(
			'wplc/license_issued_followup',
			function ( $id ) use ( &$seen ) {
				$seen[] = array( 'action', $this->committed( (int) $id, 'status' ), $this->license_lock_held( (int) $id ) );
			}
		);

		LicenseIssuer::run_followup( $seeded['id'] );

		$this->assertSame( array( array( 'action', 'active', false ) ), $seen );
	}

	public function test_activation_created_and_removed_fire_after_the_commit_and_the_activation_lock(): void {
		$seeded = $this->seed_license();
		$seen   = array();
		$probe  = function ( $license_id, $activation_id ) use ( &$seen ) {
			$name   = $this->other->real_escape_string( ActivationManager::lock_name( (int) $license_id ) );
			$locked = '1' === (string) $this->other->query( "SELECT IS_USED_LOCK('{$name}') IS NOT NULL" )->fetch_row()[0];
			$result = $this->other->query( 'SELECT status FROM `' . $this->tables()['activations'] . '` WHERE id = ' . (int) $activation_id );
			$row    = $result ? $result->fetch_row() : null;
			$seen[] = array( current_action(), is_array( $row ) ? $row[0] : null, $locked );
		};
		add_action( 'wplc/activation_created', $probe, 10, 2 );
		add_action( 'wplc/activation_removed', $probe, 10, 2 );

		$site = $this->activate_via_api( $seeded['key'] );
		$id   = (int) $site['response']->get_data()['data']['activation_id'];
		$this->assertTrue( ActivationManager::deactivate( $id, 'Commit check', 1, 'admin_site_released' ) );

		$this->assertSame( array( array( 'wplc/activation_created', 'active', false ), array( 'wplc/activation_removed', 'deactivated', false ) ), $seen );
	}
}
