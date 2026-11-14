<?php
use PHPUnit\Framework\TestCase;
use WPLC\License\ActivationManager;
use WPLC\License\AuditReason;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
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
 * Minimal in-memory stand-in for the wpdb calls ActivationManager and AuditLog make.
 */
final class WPLC_Activation_Manager_Test_Db {
	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var array<int,array<string,mixed>> */
	public $activations = array();
	/** @var array<int,array<string,mixed>> */
	public $audit = array();
	/** @var array<int,string> */
	public $locks = array();
	/** @var bool */
	public $lock_available = true;
	/** @var bool */
	public $fail_updates = false;
	/** @var array<int,mixed> */
	private $args = array();

	public function prepare( $query, ...$args ) {
		$this->args = $args;
		return $query;
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			if ( ! $this->lock_available ) {
				return '0';
			}
			$this->locks[] = 'get:' . $this->args[0];
			return '1';
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			$this->locks[] = 'release:' . $this->args[0];
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
		$rows = array();
		foreach ( $this->activations as $row ) {
			if ( (int) $row['staging_parent_id'] === (int) $this->args[0] && (int) $row['license_id'] === (int) $this->args[1] && $row['status'] === $this->args[2] ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	public function update( $table, $data, $where ) {
		if ( $this->fail_updates ) {
			return false;
		}
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

final class ActivationManagerTest extends TestCase {
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$db = new WPLC_Activation_Manager_Test_Db();
		foreach (
			array(
				array( 1, 10, 'production', null, 'active', 'https://shop.example' ),
				array( 2, 10, 'staging', 1, 'active', 'https://staging.shop.example' ),
				array( 3, 10, 'staging', 1, 'deactivated', 'https://old-staging.shop.example' ),
				array( 4, 10, 'production', null, 'active', 'https://second.example' ),
				array( 5, 10, 'staging', 4, 'active', 'https://staging.second.example' ),
				array( 6, 11, 'production', null, 'active', 'https://other-license.example' ),
			) as $row
		) {
			$db->activations[ $row[0] ] = array(
				'id' => $row[0],
				'license_id' => $row[1],
				'environment' => $row[2],
				'staging_parent_id' => $row[3],
				'status' => $row[4],
				'site_url' => $row[5],
				'deactivated_at' => null,
			);
		}
		$GLOBALS['wpdb'] = $db;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	public function test_deactivates_the_activation_and_only_its_own_active_staging_children(): void {
		$db = $GLOBALS['wpdb'];

		$this->assertTrue( ActivationManager::deactivate( 1, AuditReason::DEACTIVATED_BY_OWNER, 42 ) );

		$this->assertSame( 'deactivated', $db->activations[1]['status'] );
		$this->assertNotNull( $db->activations[1]['deactivated_at'] );
		$this->assertSame( 'deactivated', $db->activations[2]['status'] );
		$this->assertNull( $db->activations[3]['deactivated_at'], 'An already deactivated child must stay untouched.' );
		$this->assertSame( 'active', $db->activations[4]['status'] );
		$this->assertSame( 'active', $db->activations[5]['status'] );
		$this->assertSame( 'active', $db->activations[6]['status'] );
	}

	public function test_writes_one_audit_entry_per_deactivated_activation(): void {
		$db = $GLOBALS['wpdb'];

		ActivationManager::deactivate( 1, AuditReason::DEACTIVATED_BY_OWNER, 42 );

		$this->assertCount( 2, $db->audit );
		$this->assertSame( array( 'site_deactivated', 'activation', 1, 42 ), array( $db->audit[0]['action'], $db->audit[0]['object_type'], $db->audit[0]['object_id'], $db->audit[0]['actor_user_id'] ) );
		$this->assertSame( AuditReason::DEACTIVATED_BY_OWNER, $db->audit[0]['reason'] );
		$this->assertSame( array( 'site_deactivated', 2, 42 ), array( $db->audit[1]['action'], $db->audit[1]['object_id'], $db->audit[1]['actor_user_id'] ) );
		$this->assertSame( 1, json_decode( $db->audit[1]['after_json'], true )['parent_activation_id'] );
		$this->assertStringNotContainsString( 'shop.example', $db->audit[0]['before_json'], 'Site URLs are redacted by the audit log.' );
	}

	public function test_custom_audit_action_applies_to_the_requested_activation_only(): void {
		$db = $GLOBALS['wpdb'];

		$this->assertTrue( ActivationManager::deactivate( 1, AuditReason::INSTALLATION_REPLACED, 0, 'site_replaced' ) );

		$this->assertSame( 'site_replaced', $db->audit[0]['action'] );
		$this->assertSame( 'site_deactivated', $db->audit[1]['action'] );
	}

	public function test_returns_false_without_side_effects_when_not_active_or_unknown(): void {
		$db = $GLOBALS['wpdb'];

		$this->assertFalse( ActivationManager::deactivate( 3, 'again' ) );
		$this->assertFalse( ActivationManager::deactivate( 999, 'unknown' ) );
		$this->assertFalse( ActivationManager::deactivate( 0, 'invalid' ) );
		$this->assertSame( array(), $db->audit );
		$this->assertSame( array(), $db->locks );

		$this->assertTrue( ActivationManager::deactivate( 1, 'first' ) );
		$this->assertFalse( ActivationManager::deactivate( 1, 'second' ) );
		$this->assertCount( 2, $db->audit );
	}

	public function test_returns_false_on_database_failure_and_releases_the_lock(): void {
		$db = $GLOBALS['wpdb'];
		$db->fail_updates = true;

		$this->assertFalse( ActivationManager::deactivate( 1, 'reason' ) );

		$this->assertSame( 'active', $db->activations[1]['status'] );
		$this->assertSame( 'active', $db->activations[2]['status'] );
		$this->assertSame( array(), $db->audit );
		$lock = ActivationManager::lock_name( 10 );
		$this->assertSame( array( 'get:' . $lock, 'release:' . $lock ), $db->locks );
	}

	public function test_returns_false_when_the_license_lock_is_busy(): void {
		$db = $GLOBALS['wpdb'];
		$db->lock_available = false;

		$this->assertFalse( ActivationManager::deactivate( 1, 'reason' ) );
		$this->assertSame( 'active', $db->activations[1]['status'] );
		$this->assertSame( array(), $db->locks );
	}

	public function test_lock_name_matches_the_name_used_by_the_rest_handlers(): void {
		$scope = \WPLC\Infrastructure\Database\Schema::lock_scope();
		$this->assertSame( 'wplc_' . substr( hash( 'sha256', $scope . '|license:10' ), 0, 48 ), ActivationManager::lock_name( 10 ) );
		$this->assertNotSame( ActivationManager::lock_name( 10 ), ActivationManager::lock_name( 11 ) );
		$this->assertLessThanOrEqual( 64, strlen( ActivationManager::lock_name( PHP_INT_MAX ) ) );
	}
}
