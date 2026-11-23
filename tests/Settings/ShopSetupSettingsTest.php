<?php
/**
 * Settings for the sale, part 4 (SET-LIC-24, -25, -28, -30, -43, -45): defaults, limits and pure helpers.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Account\CustomerPortal;
use WPLC\Account\SiteOverview;
use WPLC\Admin\Settings;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;
use WPLC\License\LicenseKey;
use WPLC\Security\KeyAge;
use WPLC\Security\SecretCrypto;
use WPLC\Settings\Definitions;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;
use WPLC\Update\S3Storage;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class ShopSetupSettingsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wplc_test_filters']['wplc_key_rotation_reminder_months'] );
		S3Storage::override_config( null );
	}

	// ------------------------------------------------------------------ SET-LIC-30

	/** SET-LIC-30: bucket, region, endpoint and path style are settings; the access keys are not. */
	public function test_object_storage_fields_are_settings_without_credentials(): void {
		$defaults = Registry::defaults();
		foreach ( array( 's3_bucket' => '', 's3_region' => '', 's3_endpoint' => '', 's3_path_style' => 0 ) as $key => $default ) {
			$definition = Registry::definition( $key );
			$this->assertSame( $default, $defaults[ $key ], $key );
			$this->assertSame( 'updates', $definition['group'], $key );
			$this->assertSame( 'manage_package_hosts', $definition['capability'], "$key: the bucket host becomes a trusted package host." );
			$this->assertFalse( $definition['exportable'], "$key: the storage belongs to this server." );
			$this->assertSame( 'WPLC_S3_' . strtoupper( substr( $key, 3 ) ), $definition['constant'], "$key: its constant wins." );
			$this->assertSame( '', Registry::locking_constant( $key ), "$key: no constant is defined in the unit tests." );
		}
		foreach ( array_keys( Registry::definitions() ) as $key ) {
			$this->assertStringNotContainsString( 'secret', (string) $key );
			$this->assertStringNotContainsString( 'access_key', (string) $key );
		}

		$this->assertSame( 'acme-releases', Registry::validate( 's3_bucket', 'acme-releases' )['value'] );
		$this->assertSame( 'invalid_format', Registry::validate( 's3_bucket', 'a' )['code'] );
		$this->assertSame( 'invalid_format', Registry::validate( 's3_bucket', 'bucket/key' )['code'] );
		$this->assertSame( 'eu-central-1', Registry::validate( 's3_region', 'eu-central-1' )['value'] );
		$this->assertSame( 'invalid_format', Registry::validate( 's3_region', 'eu central' )['code'] );
		$this->assertSame( 'https://abc.r2.cloudflarestorage.com', Registry::validate( 's3_endpoint', 'https://abc.r2.cloudflarestorage.com' )['value'] );
		foreach ( array( 'http://s3.example.com', 'https://s3.example.com/path', 'https://s3.example.com:9000', 'ftp://x.example' ) as $endpoint ) {
			$this->assertSame( 'invalid_format', Registry::validate( 's3_endpoint', $endpoint )['code'], $endpoint );
		}
		$this->assertStringContainsString( Texts::label( 's3_bucket' ), Texts::problem( 's3_bucket', 'invalid_format' ) );
	}

	/** SET-LIC-30: without the access keys a bucket from the settings is "incomplete"; with them it is used. */
	public function test_object_storage_reads_the_settings_where_no_constant_is_defined(): void {
		$this->assertSame( 'off', S3Storage::config()['state'], 'Default: no object storage.' );
		S3Storage::override_config( array( 'bucket' => 'acme-releases', 'with_settings' => true ) );
		$this->assertSame( array( 'invalid', 'incomplete' ), array( S3Storage::config()['state'], S3Storage::config()['error'] ), 'The access keys stay constants.' );
		S3Storage::override_config( array( 'bucket' => 'acme-releases', 'region' => 'eu-central-1', 'access_key_id' => 'AKID', 'secret_access_key' => 'secret', 'with_settings' => true ) );
		$config = S3Storage::config();
		$this->assertSame( array( 'configured', 's3.eu-central-1.amazonaws.com', false ), array( $config['state'], $config['endpoint_host'], $config['path_style'] ) );
		$this->assertStringContainsString( 'License settings', S3Storage::error_message( 'incomplete' ) );
	}

	// ------------------------------------------------------------------ SET-LIC-28

	/** SET-LIC-28: the reminder is off by default, administrators only, and the filter wins. */
	public function test_key_rotation_reminder_is_off_by_default_and_the_filter_wins(): void {
		$definition = Registry::definition( 'key_rotation_reminder_months' );
		$this->assertSame( array( 0, 0, 120, 'operations', 'manage_package_hosts', 'wplc_key_rotation_reminder_months' ), array( $definition['default'], $definition['min'], $definition['max'], $definition['group'], $definition['capability'], $definition['filter'] ) );
		$this->assertSame( 0, KeyAge::reminder_months() );
		$this->assertSame( array(), KeyAge::due(), 'Off: nothing is due, whatever the age.' );
		$this->assertSame( 1, Definitions::ALERT_THRESHOLDS['keys_due_for_rotation'], 'A due key raises a health alert once the reminder is on.' );
		$this->assertArrayHasKey( 'keys_due_for_rotation', Texts::alert_metrics() );

		$GLOBALS['wplc_test_filters']['wplc_key_rotation_reminder_months'][] = static function () {
			return 500;
		};
		$this->assertSame( 120, KeyAge::reminder_months(), 'The filter wins and is kept in range.' );
	}

	/** SET-LIC-28: a key is told apart by a fingerprint that reveals nothing about it; ages are calendar months. */
	public function test_key_fingerprints_and_months(): void {
		$license = LicenseKey::fingerprint();
		$this->assertMatchesRegularExpression( '/^test-2026:[0-9a-f]{16}$/', $license );
		$this->assertStringNotContainsString( 'test-current-license-key', $license );
		$this->assertMatchesRegularExpression( '/^test-2026:[0-9a-f]{16}$/', SecretCrypto::fingerprint() );
		$this->assertNotSame( $license, SecretCrypto::fingerprint(), 'Different secrets, different fingerprints.' );

		$from = (int) strtotime( '2026-01-31 12:00:00 UTC' );
		$this->assertSame( 0, KeyAge::months_between( $from, (int) strtotime( '2026-02-28 12:00:00 UTC' ) ) );
		$this->assertSame( 2, KeyAge::months_between( $from, (int) strtotime( '2026-03-31 12:00:00 UTC' ) ) );
		$this->assertSame( 12, KeyAge::months_between( $from, (int) strtotime( '2027-01-31 12:00:00 UTC' ) ) );
		$this->assertSame( 0, KeyAge::months_between( $from, $from - 10 ) );
		foreach ( KeyAge::KINDS as $kind ) {
			$this->assertNotSame( $kind, KeyAge::label( $kind ) );
			$this->assertNotSame( '', KeyAge::how_to_rotate( $kind ) );
		}
	}

	// ------------------------------------------------------------------ SET-LIC-25

	/** SET-LIC-25: the customer account defaults to the addresses, names and numbers so far. */
	public function test_customer_account_defaults_to_today(): void {
		$defaults = Registry::defaults();
		$this->assertSame( CustomerPortal::ENDPOINT, $defaults['account_licenses_slug'] );
		$this->assertSame( SiteOverview::ENDPOINT, $defaults['account_sites_slug'] );
		$this->assertSame( CustomerPortal::PER_PAGE, $defaults['account_licenses_per_page'] );
		$this->assertSame( SiteOverview::PER_PAGE, $defaults['account_sites_per_page'] );
		$this->assertSame( SiteOverview::STALE_DAYS, $defaults['account_stale_days'] );
		$this->assertSame( SiteOverview::EXPIRY_DAYS, $defaults['account_expiry_days'] );
		$this->assertSame( '', $defaults['account_licenses_label'] );
		$this->assertSame( '', $defaults['account_sites_label'] );
		foreach ( Registry::keys_of_group( 'account' ) as $key ) {
			$this->assertSame( 'manage_settings', Registry::definition( $key )['capability'], "$key is a rule of the shop." );
		}
		$this->assertSame( 'wplc_account_endpoint_slug', Registry::definition( 'account_licenses_slug' )['filter'] );
		$this->assertSame( 'wplc_sites_endpoint_slug', Registry::definition( 'account_sites_slug' )['filter'] );

		$this->assertSame( 'Licenses', CustomerPortal::menu_label() );
		$this->assertSame( 'Sites', SiteOverview::menu_label() );
		$this->assertSame( 2, CustomerPortal::page_count( 11 ), 'Default: 10 licenses per page.' );
		$this->assertSame( array( SiteOverview::STALE_DAYS, SiteOverview::EXPIRY_DAYS ), array( SiteOverview::stale_days(), SiteOverview::expiry_days() ) );
	}

	/** SET-LIC-25: an address is lowercase letters, digits and hyphens; two pages cannot share one. */
	public function test_account_addresses_are_checked(): void {
		$this->assertSame( 'lizenzen', Registry::validate( 'account_licenses_slug', 'lizenzen' )['value'] );
		foreach ( array( 'Lizenzen', 'my licenses', 'lic/enses', '-licenses', 'lizenzen?x=1' ) as $slug ) {
			$this->assertFalse( Registry::validate( 'account_licenses_slug', $slug )['ok'], $slug );
		}
		$this->assertSame( 'out_of_range', Registry::validate( 'account_licenses_per_page', '0' )['code'] );
		$this->assertSame( 'too_long', Registry::validate( 'account_sites_label', str_repeat( 'x', 61 ) )['code'] );

		$this->assertSame( '', Settings::account_slug_conflict( Registry::defaults() ) );
		$this->assertSame( 'pages', Settings::account_slug_conflict( array( 'account_licenses_slug' => 'pages', 'account_sites_slug' => 'pages' ) ) );
	}

	// ------------------------------------------------------------------ SET-LIC-24

	/** SET-LIC-24: the catalog file may carry documentation address, installation steps and plan names per language. */
	public function test_catalog_file_carries_texts_for_customers(): void {
		$file = array(
			'format'        => 1,
			'product_key'   => 'acme-pro',
			'name'          => 'Acme Pro',
			'entitlements'  => array( array( 'key' => 'acme.pdf', 'label' => 'PDF' ) ),
			'docs_url'      => array( 'en' => 'https://acme.example/docs', 'de_DE' => 'https://acme.example/de/docs' ),
			'install_steps' => array( array( 'en' => 'Download it.', 'de_DE' => 'Lade es herunter.' ), 'Activate it.' ),
			'plans'         => array(
				'single' => array( 'name' => array( 'en' => 'One site', 'de_DE' => 'Eine Website' ) ),
				'agency' => array( 'name' => 'Agency' ),
			),
		);
		$parsed = CatalogFile::validate( $file );
		$this->assertTrue( $parsed['ok'], wp_json_encode_for_test( $parsed['errors'] ) );
		$catalog = $parsed['catalog'];
		$this->assertSame( array( 'agency', 'single' ), array_keys( $catalog['plans'] ), 'Plans are sorted, so the fingerprint does not depend on the order.' );
		$this->assertSame( array( 'en' => 'Activate it.' ), $catalog['install_steps'][1] );
		$this->assertSame( $parsed['catalog'], CatalogFile::parse( CatalogFile::encode( $catalog ) )['catalog'], 'Export and import keep them.' );

		// A file without them keeps its normalized form and its fingerprint.
		$plain = $file;
		unset( $plain['docs_url'], $plain['install_steps'], $plain['plans'] );
		$plain_catalog = CatalogFile::validate( $plain )['catalog'];
		$this->assertSame( array( 'format', 'product_key', 'prefix', 'name', 'status', 'entitlements' ), array_keys( $plain_catalog ) );
		$this->assertStringNotContainsString( 'install_steps', CatalogFile::encode( $plain_catalog ) );

		$bad = array(
			'docs_url'      => array( 'javascript:alert(1)', 'url_invalid' ),
			'install_steps' => array( array(), 'install_steps_invalid' ),
			'plans'         => array( array( 'Single' => array( 'name' => 'x' ) ), 'plan_key_invalid' ),
		);
		foreach ( $bad as $field => list( $value, $code ) ) {
			$result = CatalogFile::validate( array( $field => $value ) + $plain );
			$this->assertFalse( $result['ok'], $field );
			$this->assertSame( $code, $result['errors'][0]['code'], $field );
			$this->assertNotSame( $code, CatalogFile::describe( $code ) );
		}
		$this->assertSame( 'text_too_long', CatalogFile::validate( array( 'install_steps' => array( str_repeat( 'x', 501 ) ) ) + $plain )['errors'][0]['code'] );
		$this->assertSame( 'install_steps_invalid', CatalogFile::validate( array( 'install_steps' => array_fill( 0, 11, 'x' ) ) + $plain )['errors'][0]['code'] );

		// The text of a locale, its fallback, then English.
		$this->assertSame( 'Eine Website', CatalogStore::text( $catalog['plans']['single']['name'], 'de_DE' ) );
		$this->assertSame( 'One site', CatalogStore::text( $catalog['plans']['single']['name'], 'fr_FR' ) );
	}
}

/**
 * JSON of a value for an assertion message.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_test( $value ): string {
	return (string) json_encode( $value );
}
