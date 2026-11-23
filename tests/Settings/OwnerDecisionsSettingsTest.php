<?php
/**
 * Settings for the sale, part 6: owner decisions of 23.11.2026 (SET-LIC-17, SET-LIC-20, E-04, E-08).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Billing\PlanUpgrade;
use WPLC\License\LicenseKey;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;
use WPLC\Update\UpdateChannels;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class OwnerDecisionsSettingsTest extends TestCase {

	private const HOOKS = array(
		'wplc_license_update_channels',
		'wplc_plan_upgrades_enabled',
		'wplc_plan_downgrades_enabled',
	);

	protected function tearDown(): void {
		foreach ( self::HOOKS as $hook ) {
			unset( $GLOBALS['wplc_test_filters'][ $hook ] );
		}
	}

	/** Every new setting keeps the behaviour so far, except the review notice the owner chose (E-07). */
	public function test_defaults_are_the_behaviour_so_far_and_the_owner_decisions(): void {
		$defaults = Registry::defaults();
		$this->assertSame( 1, $defaults['beta_updates'], 'E-05: existing plans allowed.' );
		$this->assertSame( 1, $defaults['suspend_on_order_cancelled'] );
		$this->assertSame( 1, $defaults['suspend_on_order_item_change'] );
		$this->assertSame( 1, $defaults['partial_refund_review'], 'E-07: new review notice, on.' );
		$this->assertSame( 0, $defaults['restore_after_won_dispute'] );
		$this->assertSame( 1, $defaults['plan_upgrades_enabled'] );
		$this->assertSame( 1, $defaults['plan_downgrades_enabled'] );
		$this->assertSame( LicenseKey::DEFAULT_PREFIX, $defaults['license_key_prefix'] );
		$this->assertSame( 'WPLC', LicenseKey::DEFAULT_PREFIX );
	}

	/** Groups, capabilities, filters and the legal flag of the new settings. */
	public function test_groups_capabilities_filters_and_legal_review(): void {
		$expected = array(
			'beta_updates'                 => array( 'updates', 'manage_policy', 'wplc_license_update_channels', false ),
			'suspend_on_order_cancelled'   => array( 'rules', 'manage_settings', 'wplc_suspend_license_on_order_status', true ),
			'suspend_on_order_item_change' => array( 'rules', 'manage_settings', 'wplc_suspend_license_on_order_item_change', true ),
			'partial_refund_review'        => array( 'rules', 'manage_settings', 'wplc_partial_refund_review', true ),
			'restore_after_won_dispute'    => array( 'rules', 'manage_settings', 'wplc_restore_license_after_won_dispute', true ),
			'plan_upgrades_enabled'        => array( 'rules', 'manage_settings', 'wplc_plan_upgrades_enabled', true ),
			'plan_downgrades_enabled'      => array( 'rules', 'manage_settings', 'wplc_plan_downgrades_enabled', true ),
			'license_key_prefix'           => array( 'rules', 'manage_policy', '', false ),
		);
		foreach ( $expected as $key => $rule ) {
			$definition = Registry::definition( $key );
			$this->assertNotNull( $definition, $key );
			$this->assertSame( $rule, array( $definition['group'], $definition['capability'], $definition['filter'], $definition['legal'] ), $key );
			$this->assertNotSame( '', Texts::label( $key ), $key );
			$this->assertNotSame( '', Texts::help( $key ), $key );
		}
		// E-04: the prefix has no filter, a filter could change it after the first license.
		$this->assertIsCallable( Registry::definition( 'license_key_prefix' )['locked'] );
	}

	/** SET-LIC-20: channels of a license; stable always first; the filter decides last. */
	public function test_update_channels_of_a_license(): void {
		$license = array( 'id' => 0, 'product_key' => 'acme' );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::for_license( $license ), 'Default: beta allowed as before.' );
		$this->assertSame( 'beta', UpdateChannels::effective( 'BETA ', $license ) );
		$this->assertSame( 'stable', UpdateChannels::effective( 'nightly', $license ) );

		$GLOBALS['wplc_test_filters']['wplc_license_update_channels'][] = static fn() => array( 'stable' );
		$this->assertSame( array( 'stable' ), UpdateChannels::for_license( $license ) );
		$this->assertSame( 'stable', UpdateChannels::effective( 'beta', $license ), 'Without the right: stable.' );

		$this->assertSame( array( 'stable' ), UpdateChannels::clean( 'beta' ), 'Not a list: stable only.' );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::clean( array( 'beta' ) ), 'Stable is always part of it.' );
		$this->assertSame( array( 'stable' ), UpdateChannels::clean( array( 'nightly', 7 ) ) );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::clean( array( 'beta', 'stable', 'beta' ) ) );
	}

	/** SET-LIC-20: the plan override is a yes/no choice with the limits of a switch. */
	public function test_beta_right_per_plan_is_a_switch(): void {
		$this->assertSame( array( 0, 1, 'beta_updates' ), \WPLC\Catalog\PlanOverrides::FIELDS['beta_allowed'] );
		$this->assertContains( 'beta_allowed', \WPLC\Catalog\PlanOverrides::SWITCHES );
		$this->assertSame( 1, \WPLC\Catalog\PlanOverrides::of_plan( array( 'beta_allowed' => '1' ), 'beta_allowed' ) );
		$this->assertSame( 0, \WPLC\Catalog\PlanOverrides::of_plan( array( 'beta_allowed' => '0' ), 'beta_allowed' ) );
		$this->assertNull( \WPLC\Catalog\PlanOverrides::of_plan( array( 'beta_allowed' => null ), 'beta_allowed' ), 'NULL = global setting.' );
		$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( 'beta_allowed', '2' )['ok'] );
	}

	/** E-04: the prefix is four characters, upper case; the length of keys and references never changes. */
	public function test_key_prefix_is_checked_and_keeps_the_length(): void {
		$this->assertSame( 'ACME', Registry::validate( 'license_key_prefix', 'acme' )['value'] );
		$this->assertSame( 'AC9E', Registry::validate( 'license_key_prefix', ' AC9E- ' )['value'], 'The hyphen is the separator.' );
		foreach ( array( '', 'ACM', 'ACMEX', '1ACM', 'AC-E', 'ÄCME', array( 'ACME' ) ) as $bad ) {
			$result = Registry::validate( 'license_key_prefix', $bad );
			$this->assertFalse( $result['ok'], (string) wp_json_encode_or_string( $bad ) );
		}
		$this->assertSame( 'not_a_key_prefix', Registry::validate( 'license_key_prefix', 'ACM' )['code'] );
		$this->assertStringContainsString( 'ACME', Texts::problem( 'license_key_prefix', 'not_a_key_prefix' ) );
		$this->assertNotSame( '', Texts::problem( 'license_key_prefix', 'locked' ) );

		$this->assertSame( 'WPLC', LicenseKey::key_prefix(), 'Default without a stored value.' );
		$this->assertSame( 'WPLC-1A2B3C4D', LicenseKey::prefix( '1a2b3c4d-0000-4000-8000-000000000000' ) );
		$this->assertSame( 'WPLC', LicenseKey::prefix_alternatives() );
	}

	/** E-08: plan changes stay prorated by day; two switches, the filters decide last. */
	public function test_plan_change_switches(): void {
		$this->assertTrue( PlanUpgrade::upgrades_allowed() );
		$this->assertTrue( PlanUpgrade::downgrades_allowed() );
		$GLOBALS['wplc_test_filters']['wplc_plan_upgrades_enabled'][]   = static fn() => false;
		$GLOBALS['wplc_test_filters']['wplc_plan_downgrades_enabled'][] = static fn() => false;
		$this->assertFalse( PlanUpgrade::upgrades_allowed() );
		$this->assertFalse( PlanUpgrade::downgrades_allowed() );
	}

	/** SET-LIC-17: refund rules read the settings, the existing filters receive them. */
	public function test_refund_rules_feed_the_existing_filters(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/License/LicenseLifecycle.php' );
		$this->assertStringContainsString( "apply_filters( 'wplc_suspend_license_on_order_status', \\WPLC\\Settings\\Registry::bool( 'suspend_on_order_cancelled' ), \$order )", $source );
		$this->assertStringContainsString( "apply_filters( 'wplc_suspend_license_on_order_item_change', \\WPLC\\Settings\\Registry::bool( 'suspend_on_order_item_change' ),", $source );
		$this->assertStringContainsString( "'woocommerce_order_partially_refunded', array( self::class, 'handle_partial_amount_refund' )", $source );
		// A full refund always revokes: no setting is read on that path.
		$this->assertDoesNotMatchRegularExpression( '/function handle_full_refund\([^{]*\{[^}]*Registry::/s', $source );
		$this->assertNotSame( \WPLC\License\AuditReason::label( \WPLC\License\AuditReason::encode( \WPLC\License\AuditReason::PARTIAL_REFUND_REVIEW, array( 'order' => 5, 'amount' => '10.00', 'currency' => 'EUR' ) ) ), 'partial_refund_review' );
	}
}

if ( ! function_exists( 'wp_json_encode_or_string' ) ) {
	/**
	 * Message helper for the data provider loop above.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode_or_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : (string) json_encode( $value );
	}
}
