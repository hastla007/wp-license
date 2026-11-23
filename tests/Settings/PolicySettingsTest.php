<?php
/**
 * Settings for the sale, part 3 (SET-LIC-09, -10, -18, -19, -21, -31): manufacturer rules, defaults, limits and readers.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Capabilities;
use WPLC\Admin\Settings;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\SiteUrl;
use WPLC\Settings\Definitions;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class PolicySettingsTest extends TestCase {

	private const HOOKS = array(
		'wplc_staging_labels',
		'wplc_staging_host_suffixes',
		'wplc_local_host_suffixes',
		'wplc_staging_site_limit',
		'wplc_development_site_limit',
		'wplc_license_member_limit',
		'wplc_license_invitation_days',
		'wplc_license_transfer_enabled',
		'wplc_license_transfer_new_key',
		'wplc_expired_license_download',
		'wplc_customer_download_version_choices',
		'wplc_customer_download_hourly_limit',
		'wplc_download_reuse_seconds',
		'wplc_download_grant_seconds',
	);

	protected function tearDown(): void {
		foreach ( self::HOOKS as $hook ) {
			unset( $GLOBALS['wplc_test_filters'][ $hook ] );
		}
	}

	// ------------------------------------------------------------------ SET-LIC-31

	/** SET-LIC-31: the manufacturer rules have a capability of their own, granted to administrators by default. */
	public function test_manufacturer_rules_have_their_own_capability(): void {
		$defaults = Capabilities::defaults();
		$this->assertSame( 'manage_options', $defaults[ Capabilities::MANAGE_POLICY ] );
		$this->assertSame( 'wplc_manage_policy', Capabilities::filter_capability( 'manage_woocommerce', 'manage_policy' ) );
		$this->assertFalse( Capabilities::grant_defaults( array( 'manage_woocommerce' => true ), array( 'wplc_manage_policy' ) )['wplc_manage_policy'], 'A shop manager does not hold it.' );
		$this->assertTrue( Capabilities::grant_defaults( array( 'manage_options' => true ), array( 'wplc_manage_policy' ) )['wplc_manage_policy'] );
		$this->assertFalse( Capabilities::grant_defaults( array( 'manage_options' => true, 'wplc_manage_policy' => false ), array( 'wplc_manage_policy' ) )['wplc_manage_policy'], 'A denial stored on the role wins.' );

		// The overview "Who may do what" describes every capability.
		$this->assertEqualsCanonicalizing( array_keys( $defaults ), array_keys( Capabilities::descriptions() ) );
		foreach ( Capabilities::descriptions() as $capability => $text ) {
			$this->assertNotSame( '', $text, $capability );
		}
	}

	/** SET-LIC-31: the rules that act on every customer site need wplc_manage_policy; the offline period moved there. */
	public function test_rules_of_the_manufacturer_level_need_the_policy_capability(): void {
		$policy = array( 'offline_cache_days', 'staging_sites_per_live', 'development_sites', 'staging_labels', 'staging_host_suffixes', 'local_host_suffixes' );
		foreach ( $policy as $key ) {
			$this->assertSame( 'manage_policy', Registry::definition( $key )['capability'], $key );
		}
		$this->assertSame( 'manage_package_hosts', Registry::definition( 'package_hosts' )['capability'], 'The package hosts keep their own capability.' );
		$restricted = Settings::restricted_settings();
		$this->assertContains( Texts::label( 'offline_cache_days' ), $restricted['manage_policy'] );
		$this->assertStringContainsString( 'wplc_manage_policy', Settings::readonly_note( 'manage_policy' ) );
		$this->assertSame( 'Only an administrator may change this value.', Settings::readonly_note( 'manage_package_hosts' ) );
	}

	// ------------------------------------------------------------------ SET-LIC-09

	/** SET-LIC-09: the three lists default to the rules so far. */
	public function test_staging_lists_default_to_the_rules_so_far(): void {
		$this->assertSame( array( 'staging', 'stage', 'stg', 'dev', 'test', 'testing', 'preprod', 'preview', 'sandbox', 'uat' ), SiteUrl::staging_labels() );
		$this->assertSame( Definitions::STAGING_HOST_SUFFIXES, SiteUrl::staging_host_suffixes() );
		$this->assertCount( 16, SiteUrl::staging_host_suffixes() );
		$this->assertContains( 'wpengine.com', SiteUrl::staging_host_suffixes() );
		$this->assertSame( array( 'localhost', 'test', 'local', 'internal', 'lan', 'home.arpa', 'localdomain', 'ddev.site', 'lndo.site' ), SiteUrl::local_host_suffixes() );
		foreach ( array( 'staging_labels', 'staging_host_suffixes', 'local_host_suffixes' ) as $key ) {
			$this->assertTrue( Registry::definition( $key )['resettable'], $key );
			$this->assertSame( 'string_list', Registry::definition( $key )['type'], $key );
		}
	}

	/** SET-LIC-09: only valid name parts and endings are accepted. */
	public function test_only_valid_name_parts_and_endings_are_accepted(): void {
		$this->assertSame( array( 'staging', 'qa-1' ), Registry::validate( 'staging_labels', "Staging\nqa-1\n\nstaging" )['value'] );
		$this->assertSame( 'invalid_entries', Registry::validate( 'staging_labels', "stage.shop" )['code'], 'A name part has no dot.' );
		$this->assertSame( 'invalid_entries', Registry::validate( 'staging_labels', '-qa' )['code'] );

		$this->assertSame( array( 'kinsta.cloud', 'host.example' ), Registry::validate( 'staging_host_suffixes', ".kinsta.cloud\nhost.example" )['value'], 'The leading dot is optional.' );
		$this->assertSame( 'invalid_entries', Registry::validate( 'staging_host_suffixes', 'com' )['code'], 'A top-level domain alone would turn every shop into a staging copy.' );
		$this->assertSame( 'invalid_entries', Registry::validate( 'staging_host_suffixes', '*.example.com' )['code'] );
		$this->assertSame( 'invalid_entries', Registry::validate( 'staging_host_suffixes', 'https://example.com' )['code'] );

		$this->assertSame( array( 'test', 'corp.internal' ), Registry::validate( 'local_host_suffixes', ".test\ncorp.internal" )['value'] );
		$this->assertSame( 'invalid_entries', Registry::validate( 'local_host_suffixes', 'bad_name' )['code'] );
		$this->assertSame( array(), Registry::validate( 'staging_labels', '' )['value'], 'An empty list is allowed.' );
	}

	/** SET-LIC-09: the rules of SiteUrl follow changed lists; the filters on the result still decide last. */
	public function test_site_rules_follow_changed_lists(): void {
		$this->assertTrue( SiteUrl::looks_like_staging( 'https://dev.shop.example.com' ) );
		$this->assertFalse( SiteUrl::is_staging_of( 'https://mysite.host.example', 'https://shop.com' ) );
		$this->assertFalse( SiteUrl::is_local_host( 'box.corp' ) );

		$GLOBALS['wplc_test_filters']['wplc_staging_labels'][]        = static function ( $list ) {
			return array_values( array_diff( $list, array( 'dev' ) ) );
		};
		$GLOBALS['wplc_test_filters']['wplc_staging_host_suffixes'][] = static function ( $list ) {
			$list[] = '.host.example';
			return $list;
		};
		$GLOBALS['wplc_test_filters']['wplc_local_host_suffixes'][]   = static function ( $list ) {
			$list[] = 'corp';
			return $list;
		};
		$this->assertFalse( SiteUrl::looks_like_staging( 'https://dev.shop.example.com' ), 'Name part removed.' );
		$this->assertTrue( SiteUrl::looks_like_staging( 'https://staging.shop.example.com' ) );
		$this->assertTrue( SiteUrl::is_staging_of( 'https://mysite.host.example', 'https://shop.com' ), 'Hosting domain added (leading dot removed).' );
		$this->assertFalse( SiteUrl::is_staging_of( 'https://host.example', 'https://shop.com' ), 'Only subdomains of a hosting domain.' );
		$this->assertTrue( SiteUrl::is_local_host( 'box.corp' ) );
		$this->assertTrue( SiteUrl::is_local_host( 'localhost' ), 'A name without a dot stays local.' );
	}

	// ------------------------------------------------------------------ SET-LIC-10

	/** SET-LIC-10: staging and development limits default to the numbers so far; 0 forbids; the filters win. */
	public function test_staging_and_development_limits(): void {
		$license = array( 'id' => 1 );
		$this->assertSame( 1, Registry::defaults()['staging_sites_per_live'] );
		$this->assertSame( ActivationService::DEVELOPMENT_SITES_MIN, Registry::defaults()['development_sites'] );
		$this->assertSame( 1, ActivationService::staging_site_limit( $license ) );
		$this->assertSame( 5, ActivationService::development_site_limit( $license, 1 ) );
		$this->assertSame( 12, ActivationService::development_site_limit( $license, 6 ), 'At least twice the live-site limit.' );
		$this->assertSame( 0, Registry::validate( 'staging_sites_per_live', '0' )['value'], '0 = not included.' );
		$this->assertSame( 'out_of_range', Registry::validate( 'staging_sites_per_live', '11' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'development_sites', '101' )['code'] );

		$GLOBALS['wplc_test_filters']['wplc_staging_site_limit'][] = static function () {
			return 3;
		};
		$this->assertSame( 3, ActivationService::staging_site_limit( $license ) );
	}

	/** SET-LIC-10: the refusal names the number. */
	public function test_staging_limit_message_names_the_number(): void {
		$this->assertSame( 'This license does not include staging sites.', ActivationService::staging_limit_message( 0 ) );
		$this->assertSame( 'This live site already has its included staging activation.', ActivationService::staging_limit_message( 1 ) );
		$this->assertStringContainsString( ' 3 ', ActivationService::staging_limit_message( 3 ) );
	}

	// ------------------------------------------------------------------ SET-LIC-18

	/** SET-LIC-18: co-users and transfer default to the behaviour so far; the filters win. */
	public function test_co_user_and_transfer_settings(): void {
		$license = array( 'id' => 0 );
		$this->assertSame( \WPLC\License\LicenseMembers::DEFAULT_LIMIT, Registry::defaults()['co_user_limit'] );
		$this->assertSame( \WPLC\License\LicenseMembers::DEFAULT_INVITATION_DAYS, Registry::defaults()['invitation_days'] );
		$this->assertSame( 5, \WPLC\License\LicenseMembers::member_limit( $license ) );
		$this->assertSame( 14, \WPLC\License\LicenseMembers::invitation_days( 'member' ) );
		$this->assertTrue( \WPLC\License\LicenseMembers::transfers_enabled( $license ) );
		$this->assertFalse( \WPLC\License\LicenseMembers::transfer_new_key( $license ) );
		foreach ( array( 'co_user_limit', 'invitation_days', 'transfers_enabled', 'transfer_new_key' ) as $key ) {
			$this->assertSame( 'sharing', Registry::definition( $key )['group'], $key );
			$this->assertSame( 'manage_policy', Registry::definition( $key )['capability'], $key );
		}
		$this->assertArrayHasKey( 'sharing', Texts::groups() );
		$this->assertSame( 0, Registry::validate( 'co_user_limit', '0' )['value'], '0 = co-users switched off.' );
		$this->assertSame( 'out_of_range', Registry::validate( 'invitation_days', '0' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'invitation_days', '91' )['code'] );

		$GLOBALS['wplc_test_filters']['wplc_license_member_limit'][]     = static fn() => 9;
		$GLOBALS['wplc_test_filters']['wplc_license_invitation_days'][]  = static fn() => 30;
		$GLOBALS['wplc_test_filters']['wplc_license_transfer_enabled'][] = static fn() => false;
		$GLOBALS['wplc_test_filters']['wplc_license_transfer_new_key'][] = static fn() => true;
		$this->assertSame( 9, \WPLC\License\LicenseMembers::member_limit( $license ) );
		$this->assertSame( 30, \WPLC\License\LicenseMembers::invitation_days( 'transfer' ) );
		$this->assertFalse( \WPLC\License\LicenseMembers::transfers_enabled( $license ) );
		$this->assertTrue( \WPLC\License\LicenseMembers::transfer_new_key( $license ) );
	}

	// ------------------------------------------------------------------ SET-LIC-21

	/** SET-LIC-21: each override has the limits of the setting it replaces; empty = global setting. */
	public function test_overrides_per_plan_follow_the_limits_of_their_settings(): void {
		foreach ( \WPLC\Catalog\PlanOverrides::FIELDS as $field => $rule ) {
			list( $min, $max, $setting ) = $rule;
			$definition                  = Registry::definition( $setting );
			$this->assertNotNull( $definition, $field );
			$this->assertSame( array( (int) $definition['min'], (int) $definition['max'] ), array( $min, $max ), $field );
			$this->assertSame( array( 'ok' => true, 'value' => null ), \WPLC\Catalog\PlanOverrides::normalize( $field, '' ), $field );
			$this->assertSame( array( 'ok' => true, 'value' => $max ), \WPLC\Catalog\PlanOverrides::normalize( $field, (string) $max ), $field );
			$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( $field, (string) ( $max + 1 ) )['ok'], $field );
			$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( $field, '-1' )['ok'], $field );
			$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( $field, '2.5' )['ok'], $field );
		}
		$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( 'offline_cache_days', '0' )['ok'], 'At least one day.' );
		$this->assertFalse( \WPLC\Catalog\PlanOverrides::normalize( 'unknown', '1' )['ok'] );
		$this->assertSame( array_keys( \WPLC\Catalog\PlanOverrides::FIELDS ), array_keys( \WPLC\Catalog\ProductEditor::override_texts() ) );

		$this->assertSame( 3, \WPLC\Catalog\PlanOverrides::of_plan( array( 'staging_site_limit' => '3' ), 'staging_site_limit' ) );
		$this->assertNull( \WPLC\Catalog\PlanOverrides::of_plan( array( 'staging_site_limit' => null ), 'staging_site_limit' ) );
		$this->assertNull( \WPLC\Catalog\PlanOverrides::of_plan( null, 'staging_site_limit' ) );
		$this->assertNull( \WPLC\Catalog\PlanOverrides::of_plan( array( 'offline_cache_days' => '0' ), 'offline_cache_days' ), 'A stored value outside the limits counts as global.' );
		$this->assertFalse( \WPLC\Catalog\PlanOverrides::any(), 'Without a database nothing is overridden.' );
	}

	// ------------------------------------------------------------------ SET-LIC-19

	/** SET-LIC-19: download settings default to the values so far, take over the limits of the filters, and the filters win. */
	public function test_download_settings(): void {
		$defaults = Registry::defaults();
		$this->assertSame( 1, $defaults['expired_download'] );
		$this->assertSame( \WPLC\Update\CustomerDownload::DEFAULT_VERSION_CHOICES, $defaults['download_version_choices'] );
		$this->assertSame( \WPLC\Update\CustomerDownload::DEFAULT_HOURLY_LIMIT, $defaults['downloads_per_hour'] );
		$this->assertSame( \WPLC\Update\PackageStream::DEFAULT_REUSE_SECONDS, $defaults['download_reuse_seconds'] );
		$this->assertSame( \WPLC\Api\V1\UpdateService::TOKEN_TTL, $defaults['download_grant_seconds'] );
		foreach ( array( 'expired_download', 'download_version_choices', 'downloads_per_hour', 'download_reuse_seconds', 'download_grant_seconds' ) as $key ) {
			$this->assertSame( 'updates', Registry::definition( $key )['group'], $key );
			$this->assertSame( 'manage_policy', Registry::definition( $key )['capability'], $key );
		}
		// The limits the filters had (0 to 600 seconds, 1 to 50 versions, 1 to 1000 downloads).
		$this->assertSame( 600, Registry::validate( 'download_reuse_seconds', '600' )['value'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'download_reuse_seconds', '601' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'download_version_choices', '51' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'downloads_per_hour', '0' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'download_grant_seconds', '59' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'download_grant_seconds', '901' )['code'] );

		$this->assertSame( 10, \WPLC\Update\CustomerDownload::hourly_limit() );
		$this->assertSame( 0, \WPLC\Update\PackageStream::reuse_seconds() );
		$this->assertSame( 300, \WPLC\Api\V1\UpdateService::grant_seconds() );

		$GLOBALS['wplc_test_filters']['wplc_customer_download_hourly_limit'][] = static fn() => 5000;
		$GLOBALS['wplc_test_filters']['wplc_download_reuse_seconds'][]        = static fn() => 30;
		$GLOBALS['wplc_test_filters']['wplc_download_grant_seconds'][]        = static fn() => 5;
		$this->assertSame( 1000, \WPLC\Update\CustomerDownload::hourly_limit(), 'The filter wins within its limits.' );
		$this->assertSame( 30, \WPLC\Update\PackageStream::reuse_seconds() );
		$this->assertSame( 60, \WPLC\Api\V1\UpdateService::grant_seconds() );
	}

	// ------------------------------------------------------------------ SET-LIC-26

	/** SET-LIC-26: the switches default to what the filters returned so far. */
	public function test_switches_default_to_the_filter_defaults(): void {
		$defaults = Registry::defaults();
		$this->assertSame( 1, $defaults['show_license_term'] );
		$this->assertSame( 0, $defaults['renew_unsold_products'] );
		$this->assertSame( 1, $defaults['renewal_off_on_card_deletion'] );
		$this->assertSame( array( 'cod' ), $defaults['issue_after_completion_gateways'] );
		$this->assertSame( \WPLC\Account\PublicCancellation::DEFAULT_HOURLY_LIMIT, $defaults['public_cancel_hourly_limit'] );
		$this->assertTrue( Registry::definition( 'public_cancel_hourly_limit' )['legal'], 'The cancellation page needs a legal review.' );
		$this->assertSame( array( 'cod', 'bacs', 'my_gateway-2' ), Registry::validate( 'issue_after_completion_gateways', "COD\nbacs\nmy_gateway-2" )['value'] );
		$this->assertSame( 'invalid_entries', Registry::validate( 'issue_after_completion_gateways', 'gateway!' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'public_cancel_hourly_limit', '0' )['code'] );
	}
}
