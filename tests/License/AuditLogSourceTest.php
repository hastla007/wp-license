<?php
/**
 * A-76: every audit entry stores where it came from.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\AuditLogAdmin;
use WPLC\License\AuditLog;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) ) );
	}
}

final class AuditLogSourceTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wplc_test_filters']['wplc_audit_source'] );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function contexts(): array {
		return array(
			'wp-cli wins over everything'      => array( array( 'cli' => true, 'cron' => true, 'rest' => true, 'user_id' => 1, 'admin' => true, 'staff' => true ), 'cli' ),
			'wp-cron'                          => array( array( 'cron' => true ), 'cron' ),
			'action scheduler in a rest call'  => array( array( 'action' => true, 'rest' => true, 'route' => '/wc/v3/x' ), 'cron' ),
			'action scheduler async in admin'  => array( array( 'action' => true, 'admin' => true, 'user_id' => 3, 'staff' => true ), 'cron' ),
			'license api'                      => array( array( 'rest' => true, 'route' => '/wplc/v1/activate' ), 'rest' ),
			'stripe webhook'                   => array( array( 'rest' => true, 'route' => '/wplc/v1/webhooks/stripe' ), 'webhook' ),
			'stripe webhook with slash'        => array( array( 'route' => '/wplc/v1/webhooks/stripe/' ), 'webhook' ),
			'other webhook-like route is rest' => array( array( 'rest' => true, 'route' => '/wplc/v1/webhooks/stripe-test' ), 'rest' ),
			'rest without known route'         => array( array( 'rest' => true ), 'rest' ),
			'staff in wp-admin'                => array( array( 'admin' => true, 'user_id' => 1, 'staff' => true ), 'admin' ),
			'customer through admin-post'      => array( array( 'admin' => true, 'user_id' => 7, 'staff' => false ), 'customer' ),
			'customer on the front end'        => array( array( 'user_id' => 7 ), 'customer' ),
			'staff on the front end'           => array( array( 'user_id' => 1, 'staff' => true ), 'customer' ),
			'anonymous front end'              => array( array(), 'system' ),
			'anonymous wp-admin request'       => array( array( 'admin' => true ), 'system' ),
		);
	}

	/**
	 * @dataProvider contexts
	 * @param array<string,mixed> $context  Context.
	 * @param string              $expected Source.
	 */
	public function test_source_follows_the_request_context( array $context, string $expected ): void {
		$this->assertSame( $expected, AuditLog::classify_source( $context ) );
		$this->assertContains( $expected, AuditLog::SOURCES );
	}

	public function test_action_scheduler_and_rest_tracking_set_the_source(): void {
		$this->assertSame( 'system', AuditLog::current_source() );

		$request = new class() {
			public function get_route() {
				return '/wplc/v1/webhooks/stripe';
			}
		};
		$this->assertSame( 'passed', AuditLog::begin_rest_request( 'passed', null, $request ) );
		$this->assertSame( 'webhook', AuditLog::current_source() );
		AuditLog::begin_action();
		$this->assertSame( 'cron', AuditLog::current_source(), 'An action run inside the webhook request counts as cron.' );
		AuditLog::end_action();
		$this->assertSame( 'webhook', AuditLog::current_source() );
		$this->assertSame( 'done', AuditLog::end_rest_request( 'done' ) );
		$this->assertSame( 'system', AuditLog::current_source() );

		AuditLog::end_action();
		$this->assertSame( 'system', AuditLog::current_source(), 'An unmatched end never makes the depth negative.' );
	}

	public function test_filter_can_replace_the_source_within_the_column_width(): void {
		$seen = array();
		$GLOBALS['wplc_test_filters']['wplc_audit_source'][] = static function ( $source, $action, $type, $id ) use ( &$seen ) {
			$seen = array( $source, $action, $type, $id );
			return 'Partner Sync! with a very long name';
		};
		$this->assertSame( 'partnersyncwithavery', AuditLog::current_source( 'license_suspended', 'license', 5 ) );
		$this->assertSame( array( 'system', 'license_suspended', 'license', 5 ), $seen );

		$GLOBALS['wplc_test_filters']['wplc_audit_source'] = array(
			static function () {
				return array( 'not a string' );
			},
		);
		$this->assertSame( 'system', AuditLog::current_source(), 'An unusable value keeps the detected source.' );
	}

	public function test_admin_labels_and_filter_values(): void {
		$this->assertSame( 'Stripe webhook', AuditLogAdmin::source_label( 'webhook' ) );
		$this->assertSame( 'Unknown', AuditLogAdmin::source_label( '' ) );
		$this->assertSame( 'partner', AuditLogAdmin::source_label( 'partner' ) );
		foreach ( AuditLog::SOURCES as $source ) {
			$this->assertArrayHasKey( $source, AuditLogAdmin::source_labels() );
			$this->assertSame( $source, AuditLogAdmin::source_filter( $source ) );
		}
		$this->assertSame( 'unknown', AuditLogAdmin::source_filter( 'unknown' ) );
		$this->assertSame( '', AuditLogAdmin::source_filter( '' ) );
		$this->assertSame( 'cron', AuditLogAdmin::source_filter( 'CRON' ) );
	}
}
