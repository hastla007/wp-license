<?php
/**
 * T-156: the audit log admin screen.
 *
 * @package WPLC
 */

use WPLC\Admin\AuditLogAdmin;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;

final class AuditLogAdminIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Shop manager viewing the screen.
	 *
	 * @var int
	 */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		$this->manager = self::factory()->user->create(
			array(
				'role'         => 'shop_manager',
				'display_name' => 'Manny Manager',
				'user_email'   => 'manny@example.test',
			)
		);
		wp_set_current_user( $this->manager );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->tables()['audit_log']}" );
	}

	/**
	 * Insert an audit row with a fixed creation time.
	 *
	 * @param array<string,mixed> $row Columns.
	 * @return int ID.
	 */
	private function raw_entry( array $row ): int {
		global $wpdb;
		$wpdb->insert(
			$this->tables()['audit_log'],
			array_merge(
				array(
					'actor_user_id' => 0,
					'action'        => 'test_action',
					'object_type'   => 'license',
					'object_id'     => 1,
					'reason'        => '',
					'before_json'   => null,
					'after_json'    => null,
					'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				),
				$row
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Render the screen for GET args.
	 *
	 * @param array<string,string> $args GET args.
	 * @return string HTML.
	 */
	private function render( array $args = array() ): string {
		$_GET     = array_merge( array( 'page' => AuditLogAdmin::PAGE ), $args );
		$_REQUEST = $_GET;
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! class_exists( 'WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		}
		set_current_screen( 'woocommerce_page_' . AuditLogAdmin::PAGE );
		ob_start();
		AuditLogAdmin::render();
		return (string) ob_get_clean();
	}

	public function test_system_wide_entries_render_translated_redacted_and_linked(): void {
		$license    = $this->seed_license();
		$activation = $this->seed_activation( $license['id'] );

		AuditLog::record( 'settings_updated', 'settings', 0, '', array( 'grace_days' => 14 ), array( 'grace_days' => 7 ), $this->manager );
		AuditLog::record( 'response_signing_key_rotated', 'system', 0, AuditReason::SIGNING_KEY_ROTATED, array( 'key_id' => 'old1' ), array( 'key_id' => 'new2' ) );
		AuditLog::record( 'site_deactivated', 'activation', $activation['id'], AuditReason::DEACTIVATED_BY_OWNER, array( 'status' => 'active', 'site_url' => 'https://secret-site.test' ), array( 'status' => 'deactivated' ) );
		// A legacy row written without redaction must not leak on this screen.
		$this->raw_entry(
			array(
				'action'      => 'legacy_import',
				'object_type' => 'catalog',
				'object_id'   => 0,
				'reason'      => 'token=abc123 for jane@example.test',
				'before_json' => wp_json_encode( array( 'api_token' => 'tok_LEAK', 'note' => 'WPLC-ABCDEF12-3456' ) ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'settings_updated', $html );
		$this->assertStringContainsString( 'grace_days: 14 → 7', $html );
		// Login and ID as on the license screen (B-135), not the editable display name.
		$this->assertStringContainsString( get_userdata( $this->manager )->user_login . ' (#' . $this->manager . ')', $html );
		$this->assertStringNotContainsString( 'Manny Manager', $html );
		$this->assertStringContainsString( esc_html( AuditReason::label( AuditReason::SIGNING_KEY_ROTATED ) ), $html );
		$this->assertStringNotContainsString( '>signing_key_rotated<', $html );
		$this->assertStringContainsString( 'page=wplc-settings', $html );
		// Activation entries link to their license.
		$this->assertStringContainsString( 'page=wplc-licenses&#038;license=' . $license['id'], $html );
		// Redaction, including rows that bypassed it when written.
		foreach ( array( 'tok_LEAK', 'abc123', 'jane@example.test', 'WPLC-ABCDEF12' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html, $secret );
		}
		// The site address of an activation stays readable (A-77, kept in the merge of both lines).
		$this->assertStringContainsString( 'secret-site.test', $html );
		$this->assertStringContainsString( '[REDACTED]', $html );
		// Snapshots are shown pretty-printed in a details element.
		$this->assertStringContainsString( '<details class="wplc-audit-details">', $html );
	}

	public function test_filters_by_type_object_action_actor_and_date(): void {
		$other = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$old   = $this->raw_entry( array( 'action' => 'old_one', 'created_at' => '2025-01-10 12:00:00' ) );
		$mid   = $this->raw_entry( array( 'action' => 'settings_updated', 'object_type' => 'settings', 'object_id' => 0, 'actor_user_id' => $this->manager, 'created_at' => '2025-03-05 12:00:00' ) );
		$this->raw_entry( array( 'action' => 'gap_filler', 'created_at' => '2025-03-20 12:00:00' ) );
		$new   = $this->raw_entry( array( 'action' => 'admin_status_change', 'object_id' => 77, 'actor_user_id' => $other, 'created_at' => '2025-06-01 12:00:00' ) );

		$ids = static function ( array $filters ): array {
			global $wpdb;
			$where = AuditLogAdmin::where( array_merge( array( 'object_type' => '', 'object_id' => '', 'action' => '', 'actor' => '', 'from' => '', 'to' => '' ), $filters ) );
			$table = \WPLC\Infrastructure\Database\Schema::table_names()['audit_log'];
			$sql   = "SELECT id FROM {$table}{$where['sql']} ORDER BY id DESC";
			return array_map( 'intval', $where['params'] ? $wpdb->get_col( $wpdb->prepare( $sql, $where['params'] ) ) : $wpdb->get_col( $sql ) );
		};

		$this->assertSame( array( $mid ), $ids( array( 'object_type' => 'settings' ) ) );
		$this->assertSame( array( $mid ), $ids( array( 'object_id' => '0' ) ) );
		$this->assertSame( array( $new ), $ids( array( 'object_type' => 'license', 'object_id' => '77' ) ) );
		$this->assertSame( array( $new ), $ids( array( 'action' => 'admin_status_change' ) ) );
		$this->assertSame( array( $mid ), $ids( array( 'actor' => 'manny@example.test' ) ) );
		$this->assertSame( array( $mid ), $ids( array( 'actor' => (string) $this->manager ) ) );
		$this->assertCount( 2, $ids( array( 'actor' => 'system' ) ) );
		$this->assertSame( array(), $ids( array( 'actor' => 'nobody@example.test' ) ) );
		// Date range (site timezone is UTC in tests), inclusive on both days.
		$this->assertSame( array( $mid + 1, $mid ), $ids( array( 'from' => '2025-03-05', 'to' => '2025-03-20' ) ) );
		$this->assertSame( array( $new ), $ids( array( 'from' => '2025-04-01' ) ) );
		$this->assertSame( array( $old ), $ids( array( 'to' => '2025-01-10' ) ) );
		$this->assertSame( array(), $ids( array( 'from' => '2030-01-01' ) ) );
		$this->assertCount( 4, $ids( array( 'from' => '2020-01-01' ) ) );

		// The rendered screen applies the filters from the request.
		$html = $this->render( array( 'audit_action' => 'settings_updated' ) );
		$this->assertStringContainsString( '<code>settings_updated</code>', $html );
		$this->assertStringNotContainsString( '<code>admin_status_change</code>', $html );
		$this->assertStringContainsString( 'value="settings_updated"', $html );
	}

	public function test_first_id_at_or_after_skips_gaps(): void {
		$a = $this->raw_entry( array( 'created_at' => '2025-01-01 00:00:00' ) );
		$b = $this->raw_entry( array( 'created_at' => '2025-01-02 00:00:00' ) );
		$c = $this->raw_entry( array( 'created_at' => '2025-01-03 00:00:00' ) );
		global $wpdb;
		$wpdb->delete( $this->tables()['audit_log'], array( 'id' => $b ) );

		$this->assertSame( $a, AuditLogAdmin::first_id_at_or_after( '2024-12-31 00:00:00' ) );
		$this->assertSame( $c, AuditLogAdmin::first_id_at_or_after( '2025-01-01 00:00:01' ) );
		$this->assertSame( $c, AuditLogAdmin::first_id_at_or_after( '2025-01-03 00:00:00' ) );
		$this->assertSame( $c + 1, AuditLogAdmin::first_id_at_or_after( '2025-01-04 00:00:00' ) );
	}

	public function test_paginates_and_requires_the_capability(): void {
		for ( $i = 0; $i < AuditLogAdmin::PER_PAGE + 3; $i++ ) {
			$this->raw_entry( array( 'action' => 'bulk_' . $i ) );
		}
		$page_two = $this->render( array( 'paged' => '2' ) );
		$this->assertStringContainsString( '<code>bulk_0</code>', $page_two );
		$this->assertStringNotContainsString( '<code>bulk_52</code>', $page_two );
		$this->assertStringContainsString( '53 items', $page_two );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$level = ob_get_level();
		try {
			$this->render();
			$this->fail( 'A customer must not see the audit log.' );
		} catch ( WPDieException $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
	}
}
