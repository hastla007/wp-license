<?php
/**
 * Settings for the sale, part 4 (SET-LIC-24, -25, -28, -30, -43, -45) in WordPress.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Account\SiteOverview;
use WPLC\Admin\Diagnostics;
use WPLC\Admin\KeyConfigurationNotice;
use WPLC\Admin\Settings;
use WPLC\Admin\SettingsTools;
use WPLC\Catalog\CatalogStore;
use WPLC\Notifications\Notifications;
use WPLC\Security\KeyAge;
use WPLC\Settings\Registry;
use WPLC\Update\S3Storage;

final class ShopSetupSettingsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Shop manager.
	 *
	 * @var int
	 */
	private $manager;

	/**
	 * Mails handed to wp_mail().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@example.test' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		delete_option( Settings::OPTION );
		delete_option( KeyAge::OPTION );
		delete_option( Settings::RULES_DECIDED_OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_settings_errors'] = array();
		$this->mails                   = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'status_header' );
		$_POST                         = array();
		$_REQUEST                      = array();
		$GLOBALS['wp_settings_errors'] = array();
		delete_option( Settings::OPTION );
		delete_option( KeyAge::OPTION );
		delete_option( Settings::RULES_DECIDED_OPTION );
		delete_transient( SettingsTools::NONCE_ACTION );
		S3Storage::override_config( null );
		CatalogStore::reset_cache();
		parent::tear_down();
	}

	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Save settings as the settings form does.
	 *
	 * @param array<string,mixed> $values Submitted fields.
	 * @return void
	 */
	private function submit( array $values ): void {
		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, array_merge( Registry::stored(), $values ) );
		$_POST = array();
	}

	/**
	 * Codes of the messages the last save reported.
	 *
	 * @return string[]
	 */
	private function error_codes(): array {
		return array_column( (array) get_settings_errors( Settings::OPTION ), 'code' );
	}

	// ------------------------------------------------------------------ SET-LIC-30

	/** SET-LIC-30: the object storage fields drive the storage; the access keys stay constants; the page shows state and test. */
	public function test_object_storage_fields_and_connection_test(): void {
		// A shop manager cannot change them: the bucket host becomes a trusted package host.
		wp_set_current_user( $this->manager );
		$this->submit( array( 's3_bucket' => 'evil-bucket' ) );
		$this->assertSame( '', Registry::string( 's3_bucket' ) );
		wp_set_current_user( $this->admin );

		$this->submit( array( 's3_bucket' => 'acme-releases', 's3_region' => 'eu-central-1', 's3_endpoint' => '', 's3_path_style' => '0' ) );
		$this->assertSame( array( 'acme-releases', 'eu-central-1' ), array( Registry::string( 's3_bucket' ), Registry::string( 's3_region' ) ) );
		$this->submit( array( 's3_endpoint' => 'https://storage.example.com/bucket' ) );
		$this->assertContains( 'wplc_invalid_s3_endpoint', $this->error_codes(), 'An endpoint with a path is refused.' );
		$this->assertSame( '', Registry::string( 's3_endpoint' ) );
		$this->assertArrayNotHasKey( 's3_bucket', \WPLC\Settings\Changes::export()['settings'], 'Not part of an export.' );

		// Without the access keys: incomplete.
		$this->assertSame( array( 'invalid', 'incomplete' ), array( S3Storage::config()['state'], S3Storage::config()['error'] ) );
		$field = $this->capture( array( Settings::class, 'render_s3_path_style_field' ) );
		$this->assertStringContainsString( 'data-wplc-object-storage="invalid"', $field );
		$this->assertStringContainsString( 'WPLC_S3_ACCESS_KEY_ID', $field );

		// With the access keys (standing in for the constants) the bucket of the settings is used.
		S3Storage::override_config( array( 'access_key_id' => 'AKIDSETTINGS', 'secret_access_key' => 'settings-secret-never-shown', 'with_settings' => true ) );
		$config = S3Storage::config();
		$this->assertSame( array( 'configured', 'acme-releases', 's3.eu-central-1.amazonaws.com' ), array( $config['state'], $config['bucket'], $config['endpoint_host'] ) );
		$this->assertContains( 'acme-releases.s3.eu-central-1.amazonaws.com', (array) apply_filters( 'wplc_update_package_hosts', array() ), 'The bucket host is trusted.' );
		$field = $this->capture( array( Settings::class, 'render_s3_path_style_field' ) );
		$this->assertStringContainsString( 'bucket acme-releases on s3.eu-central-1.amazonaws.com', $field );
		$this->assertStringContainsString( 'form="wplc-object-storage-check"', $field );
		$this->assertStringNotContainsString( 'settings-secret-never-shown', $field );
		$tools = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'id="wplc-object-storage-check"', $tools );

		// The connection test: one signed HEAD request; the result is shown once below the fields.
		$requests = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$requests ) {
				$requests[] = array( $args['method'], $url );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		$result = $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 's3_check', '_wpnonce' => wp_create_nonce( SettingsTools::NONCE_ACTION ) ) );
		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringContainsString( 'tab=updates#wplc-object-storage', $result['location'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'HEAD', $requests[0][0] );
		$this->assertStringStartsWith( 'https://acme-releases.s3.eu-central-1.amazonaws.com/', $requests[0][1] );
		$field = $this->capture( array( Settings::class, 'render_s3_path_style_field' ) );
		$this->assertStringContainsString( 'HTTP 403', $field );
		$this->assertStringNotContainsString( 'X-Amz-Signature', $field );
		$this->assertStringNotContainsString( 'HTTP 403', $this->capture( array( Settings::class, 'render_s3_path_style_field' ) ), 'Shown once.' );

		// A request without the nonce is refused.
		$this->assertSame( 'die', $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 's3_check' ) )['outcome'] );
	}

	/** SET-LIC-30: a constant wins over its field; the field is read-only and a save keeps the stored value. */
	public function test_a_constant_wins_over_its_object_storage_field(): void {
		$this->submit( array( 's3_path_style' => '0' ) );
		if ( ! defined( 'WPLC_S3_PATH_STYLE' ) ) {
			define( 'WPLC_S3_PATH_STYLE', true );
		}
		$this->assertSame( 'WPLC_S3_PATH_STYLE', Registry::locking_constant( 's3_path_style' ) );
		$field = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 's3_path_style' ) );
			}
		);
		$this->assertStringNotContainsString( 'name="wplc_settings[s3_path_style]"', $field );
		$this->assertStringContainsString( 'checked', $field, 'The field shows the value of the constant.' );
		$this->assertStringContainsString( 'WPLC_S3_PATH_STYLE', $field );
		$this->submit( array( 's3_path_style' => '1' ) );
		$this->assertSame( 0, (int) Registry::get( 's3_path_style' ), 'The stored value stays.' );
		S3Storage::override_config( array( 'bucket' => 'acme', 'access_key_id' => 'AKID', 'secret_access_key' => 's', 'with_settings' => true ) );
		$this->assertTrue( S3Storage::config()['path_style'], 'The constant is used.' );
	}

	// ------------------------------------------------------------------ SET-LIC-43

	/** SET-LIC-43: a license email with sample data goes to the chosen address; nonce, capability and an hourly limit. */
	public function test_test_email_to_a_chosen_address(): void {
		$titles = Notifications::email_titles();
		$this->assertArrayHasKey( 'issued', $titles );
		$this->assertArrayHasKey( 'renewal_notice', $titles );

		$message = SettingsTools::send_test_email( 'issued', 'qa@example.test', 'en_US' );
		$this->assertStringContainsString( 'qa@example.test', $message );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'qa@example.test', is_array( $this->mails[0]['to'] ) ? $this->mails[0]['to'][0] : $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Test] ', (string) $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Sample product', (string) $this->mails[0]['message'], 'Sample data only.' );
		$this->assertSame( 1, (int) $this->query_count( 'test_email_sent' ) );

		// Refused: unknown email, invalid address, a language that is not installed.
		$this->assertStringContainsString( 'choose one of the license emails', SettingsTools::send_test_email( 'nope', 'qa@example.test', 'en_US' ) );
		$this->assertStringContainsString( 'valid email address', SettingsTools::send_test_email( 'issued', 'not-an-address', 'en_US' ) );
		$this->assertStringContainsString( 'installed languages', SettingsTools::send_test_email( 'issued', 'qa@example.test', 'xx_XX' ) );
		$this->assertCount( 1, $this->mails );

		// At most TEST_EMAILS_PER_HOUR per user and hour.
		for ( $i = 1; $i < SettingsTools::TEST_EMAILS_PER_HOUR; $i++ ) {
			SettingsTools::send_test_email( 'expired', 'qa@example.test', 'en_US' );
		}
		$this->assertCount( SettingsTools::TEST_EMAILS_PER_HOUR, $this->mails );
		$this->assertStringContainsString( 'Try again later', SettingsTools::send_test_email( 'expired', 'qa@example.test', 'en_US' ) );
		$this->assertCount( SettingsTools::TEST_EMAILS_PER_HOUR, $this->mails );
		delete_transient( 'wplc_test_emails_' . $this->admin );

		// Through the form: nonce and capability.
		$this->assertSame( 'die', $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'test_email', 'email' => 'issued', 'address' => 'qa@example.test', 'locale' => 'en_US' ) )['outcome'] );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer );
		$this->assertSame( 'die', $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'test_email', 'email' => 'issued', 'address' => 'qa@example.test', 'locale' => 'en_US', '_wpnonce' => wp_create_nonce( SettingsTools::NONCE_ACTION ) ) )['outcome'] );
		wp_set_current_user( $this->manager );
		$result = $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'test_email', 'email' => 'issued', 'address' => 'qa@example.test', 'locale' => 'en_US', '_wpnonce' => wp_create_nonce( SettingsTools::NONCE_ACTION ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertCount( SettingsTools::TEST_EMAILS_PER_HOUR + 1, $this->mails, 'A shop manager may send test emails.' );

		$tools = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'name="tool" value="test_email"', $tools );
		$this->assertStringContainsString( '<option value="en_US"', $tools );
	}

	/**
	 * Number of audit entries of an action.
	 *
	 * @param string $action Audit action.
	 * @return int
	 */
	private function query_count( string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->tables()['audit_log']} WHERE action = %s", $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ------------------------------------------------------------------ SET-LIC-28

	/** SET-LIC-28: the first-seen date of each key is stored; a reminder is due after the chosen months, off by default. */
	public function test_key_rotation_reminder(): void {
		$then   = time() - 400 * DAY_IN_SECONDS;
		$stored = KeyAge::record( $then );
		$this->assertCount( 1, $stored['license'] );
		$this->assertCount( 1, $stored['encryption'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $then ), current( $stored['license'] ) );
		$this->assertStringStartsWith( 'integration-2026:', (string) key( $stored['license'] ) );
		$this->assertStringNotContainsString( 'integration-only-license-secret', (string) wp_json_encode( get_option( KeyAge::OPTION ) ) );
		KeyAge::record();
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $then ), current( KeyAge::stored()['license'] ), 'The first date is kept.' );

		$ages = KeyAge::ages();
		$this->assertSame( array( 13, false ), array( $ages['license']['months'], $ages['license']['due'] ) );
		$this->assertSame( 'integration-2026', $ages['license']['key_id'] );
		$this->assertSame( array(), KeyAge::due(), 'Off by default.' );
		$this->assertSame( 0, Diagnostics::metrics()['keys_due_for_rotation'] );

		// A shop manager cannot change it; an administrator can.
		wp_set_current_user( $this->manager );
		$this->submit( array( 'key_rotation_reminder_months' => '12' ) );
		$this->assertSame( 0, KeyAge::reminder_months() );
		wp_set_current_user( $this->admin );
		$this->submit( array( 'key_rotation_reminder_months' => '12' ) );
		$this->assertSame( 12, KeyAge::reminder_months() );
		$this->assertEqualsCanonicalizing( array( 'license', 'encryption' ), KeyAge::due() );
		$this->assertSame( 2, Diagnostics::metrics()['keys_due_for_rotation'] );

		// The health alert of the daily run.
		$alerts = array();
		add_action(
			'wplc/health_alert',
			static function ( $metric, $value ) use ( &$alerts ) {
				$alerts[ $metric ] = $value;
			},
			10,
			2
		);
		Diagnostics::emit_alerts();
		remove_all_actions( 'wplc/health_alert' );
		$this->assertSame( 2, $alerts['keys_due_for_rotation'] ?? null );

		// The diagnostics page and the notice for administrators.
		$labels = array_column( Diagnostics::rows(), 'state', 'label' );
		$this->assertSame( 'warning', $labels['Age: License key secret'] ?? null );
		set_current_screen( 'woocommerce_page_wplc-settings' );
		$notice = $this->capture( array( KeyConfigurationNotice::class, 'render_rotation_reminder' ) );
		$this->assertStringContainsString( 'data-wplc-key-rotation="license,encryption"', $notice );
		$this->assertStringContainsString( 'WPLC_LICENSE_KEY_SECRETS', $notice );
		wp_set_current_user( $this->manager );
		$this->assertSame( '', $this->capture( array( KeyConfigurationNotice::class, 'render_rotation_reminder' ) ), 'Administrators only.' );
		wp_set_current_user( $this->admin );

		// The filter wins.
		add_filter( 'wplc_key_rotation_reminder_months', '__return_zero' );
		$this->assertSame( array(), KeyAge::due() );
		remove_all_filters( 'wplc_key_rotation_reminder_months' );
		set_current_screen( 'front' );
	}

	// ------------------------------------------------------------------ SET-LIC-25

	/** SET-LIC-25: addresses, menu names, entries per page and warning thresholds of the customer account. */
	public function test_customer_account_settings(): void {
		global $wp_rewrite;
		$this->submit( array( 'account_licenses_slug' => 'lizenzen', 'account_sites_slug' => 'websites', 'account_licenses_label' => 'Meine Lizenzen', 'account_sites_label' => 'Meine Websites', 'account_licenses_per_page' => '3', 'account_sites_per_page' => '5', 'account_stale_days' => '3', 'account_expiry_days' => '60' ) );
		$this->assertSame( 'lizenzen', CustomerPortal::endpoint_slug() );
		$this->assertSame( 'websites', SiteOverview::endpoint_slug() );
		$this->assertSame( 'lizenzen', CustomerPortal::add_query_var( array() )[ CustomerPortal::ENDPOINT ] );

		// The rules are written again on the next request.
		update_option( CustomerPortal::REWRITE_VERSION_OPTION, 'old' );
		update_option( SiteOverview::RULES_OPTION, 'old' );
		CustomerPortal::maybe_flush_rewrite_rules();
		SiteOverview::maybe_flush_rewrite_rules();
		$this->assertStringEndsWith( ':lizenzen', (string) get_option( CustomerPortal::REWRITE_VERSION_OPTION ) );
		$this->assertSame( SiteOverview::RULES_VERSION . ':websites', get_option( SiteOverview::RULES_OPTION ) );

		// Links to the page "Sites" use the new address; the query variable keeps its key.
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$this->assertSame( 'https://shop.example/my-account/websites/2/', SiteOverview::endpoint_url( 'https://shop.example/my-account/wplc-sites/2/', SiteOverview::ENDPOINT, '2', 'https://shop.example/my-account/' ) );
		$this->assertSame( 'https://shop.example/my-account/orders/', SiteOverview::endpoint_url( 'https://shop.example/my-account/orders/', 'orders', '', 'https://shop.example/my-account/' ) );
		SiteOverview::register_endpoint();
		$this->assertContains( SiteOverview::ENDPOINT, $GLOBALS['wp']->public_query_vars );
		$wp_rewrite->set_permalink_structure( '' );

		// Menu names and numbers.
		$items = CustomerPortal::add_menu_item( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
		$this->assertSame( 'Meine Lizenzen', $items[ CustomerPortal::ENDPOINT ] );
		$this->assertSame( 'Meine Lizenzen', CustomerPortal::endpoint_title() );
		$this->assertSame( 'Meine Websites', SiteOverview::menu_label() );
		$this->assertSame( 4, CustomerPortal::page_count( 10 ) );
		$this->assertSame( 5, SiteOverview::per_page() );
		$now  = time();
		$row  = array( 'license_status' => 'active', 'renewal_enabled' => 0, 'cancel_at_period_end' => 0, 'paid_until' => gmdate( 'Y-m-d H:i:s', $now + 45 * DAY_IN_SECONDS ), 'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now - 4 * DAY_IN_SECONDS ) );
		$this->assertSame( array( 'expires_soon', 'not_seen' ), SiteOverview::warnings( $row, null, $now ) );
		$this->assertStringContainsString( '3 days', SiteOverview::warning_label( 'not_seen', $row ) );

		// Two pages cannot share an address; an address of WooCommerce is taken.
		$this->submit( array( 'account_licenses_slug' => 'konto', 'account_sites_slug' => 'konto' ) );
		$this->assertContains( 'wplc_account_slug_taken', $this->error_codes() );
		$this->assertSame( array( 'lizenzen', 'websites' ), array( Registry::string( 'account_licenses_slug' ), Registry::string( 'account_sites_slug' ) ) );
		$GLOBALS['wp_settings_errors'] = array();
		$this->submit( array( 'account_sites_slug' => 'orders' ) );
		$this->assertContains( 'wplc_account_slug_taken', $this->error_codes() );
		$this->assertSame( 'websites', Registry::string( 'account_sites_slug' ) );

		// The filters still decide last.
		add_filter(
			'wplc_sites_endpoint_slug',
			static function () {
				return 'sites-de';
			}
		);
		$this->assertSame( 'sites-de', SiteOverview::endpoint_slug() );
		remove_all_filters( 'wplc_sites_endpoint_slug' );
		update_option( CustomerPortal::REWRITE_VERSION_OPTION, 'old' );
		update_option( SiteOverview::RULES_OPTION, 'old' );
	}

	// ------------------------------------------------------------------ SET-LIC-24

	/** SET-LIC-24: installation steps, documentation address and plan names of the catalog file, per language. */
	public function test_texts_per_product_from_the_catalog_file(): void {
		$file = array(
			'format'        => 1,
			'product_key'   => 'acme-texts',
			'prefix'        => 'acme_texts',
			'name'          => 'Acme Texts',
			'entitlements'  => array(),
			'docs_url'      => array( 'en' => 'https://acme.example/docs', 'de_DE' => 'https://acme.example/de/docs' ),
			'install_steps' => array( array( 'en' => 'Download the ZIP.', 'de_DE' => 'Lade die ZIP-Datei herunter.' ), 'Activate the plugin.' ),
			'plans'         => array( 'single' => array( 'name' => array( 'en' => 'One site', 'de_DE' => 'Eine Website' ) ) ),
		);
		$report = CatalogStore::import_json( (string) wp_json_encode( $file ), CatalogStore::SOURCE_ADMIN );
		$this->assertTrue( $report['ok'] );
		$license = array( 'product_key' => 'acme-texts', 'plan_key' => 'single' );

		$this->assertSame( array( 'Download the ZIP.', 'Activate the plugin.' ), Notifications::install_steps( $license ) );
		$this->assertSame( 'https://acme.example/docs', Notifications::docs_url( 'acme-texts' ) );
		$this->assertSame( 'One site', Notifications::plan_display_name( 'single', $license ) );
		$this->assertSame( 'https://acme.example/de/docs', CatalogStore::docs_url( 'acme-texts', 'de_DE' ) );
		$this->assertSame( array( 'Lade die ZIP-Datei herunter.', 'Activate the plugin.' ), CatalogStore::install_steps( 'acme-texts', 'de_DE' ) );
		$this->assertSame( 'Eine Website', CatalogStore::plan_name( 'acme-texts', 'single', 'de_DE' ) );
		$this->assertSame( 'One site', CatalogStore::plan_name( 'acme-texts', 'single', 'fr_FR' ), 'Without a text of the locale: English.' );

		// Without texts in the file: the built-in steps and the readable plan key, as before.
		$other = array( 'product_key' => 'acme-other', 'plan_key' => 'agency-plus' );
		$this->assertCount( 3, Notifications::install_steps( $other ) );
		$this->assertSame( '', Notifications::docs_url( 'acme-other' ) );
		$this->assertSame( 'Agency Plus', Notifications::plan_display_name( 'agency-plus', $other ) );

		// The filters receive the texts and decide last.
		add_filter(
			'wplc_install_steps',
			static function ( $steps ) {
				$steps[] = 'Filtered.';
				return $steps;
			}
		);
		add_filter(
			'wplc_plan_label',
			static function ( $label ) {
				return $label . ' (filtered)';
			}
		);
		$this->assertSame( array( 'Download the ZIP.', 'Activate the plugin.', 'Filtered.' ), Notifications::install_steps( $license ) );
		$this->assertSame( 'One site (filtered)', Notifications::plan_display_name( 'single', $license ) );
		remove_all_filters( 'wplc_install_steps' );
		remove_all_filters( 'wplc_plan_label' );

		// The same file again changes nothing; the export carries the texts.
		$this->assertSame( CatalogStore::RESULT_UNCHANGED, CatalogStore::import_json( (string) wp_json_encode( $file ), CatalogStore::SOURCE_ADMIN )['result'] );
		$this->assertStringContainsString( '"install_steps"', \WPLC\Catalog\CatalogFile::encode( (array) CatalogStore::get( 'acme-texts' ) ) );
		CatalogStore::remove( 'acme-texts' );
	}

	// ------------------------------------------------------------------ SET-LIC-45

	/** SET-LIC-45: the guided step asks country profile, staging and grace period; answered, it counts as done. */
	public function test_guided_getting_started(): void {
		$this->assertFalse( Settings::setup_state()['rules'] );
		$page = $this->capture( array( Settings::class, 'render' ) );
		$this->assertStringContainsString( 'data-wplc-step="rules" data-wplc-step-done="0"', $page );
		$this->assertStringContainsString( 'name="tool" value="guided"', $page );
		$this->assertStringContainsString( 'name="staging_sites_per_live"', $page );
		$this->assertStringContainsString( 'name="renewal_grace_days"', $page );

		// A refused answer saves nothing and leaves the step open.
		$message = SettingsTools::answer_guided_questions( array( 'staging_sites_per_live' => '2', 'renewal_grace_days' => '0' ), '' );
		$this->assertStringContainsString( 'Grace period', $message );
		$this->assertFalse( Settings::setup_state()['rules'] );
		$this->assertSame( 1, Registry::int( 'staging_sites_per_live' ) );

		// Through the form: the numbers are applied, a country profile becomes the usual suggestion.
		$result = $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'guided', 'staging_sites_per_live' => '2', 'renewal_grace_days' => '21', 'profile' => 'DE', '_wpnonce' => wp_create_nonce( SettingsTools::NONCE_ACTION ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( array( 2, 21 ), array( Registry::int( 'staging_sites_per_live' ), Registry::int( 'renewal_grace_days' ) ) );
		$this->assertTrue( Settings::setup_state()['rules'] );
		$pending = SettingsTools::pending();
		$this->assertSame( array( 'preset', 'DE' ), array( $pending['source'] ?? '', $pending['name'] ?? '' ), 'The profile is a suggestion to confirm, not applied.' );
		$page = $this->capture( array( Settings::class, 'render' ) );
		$this->assertStringContainsString( 'data-wplc-step="rules" data-wplc-step-done="1"', $page );
		$this->assertStringContainsString( 'Change the answers', $page );
		delete_transient( 'wplc_settings_pending_' . $this->admin );

		// A shop manager answers too; the manufacturer rule stays read-only for them.
		delete_option( Settings::RULES_DECIDED_OPTION );
		wp_set_current_user( $this->manager );
		$form = $this->capture(
			static function () {
				SettingsTools::render_guided_questions( false );
			}
		);
		$this->assertStringNotContainsString( 'name="staging_sites_per_live"', $form );
		$this->assertStringContainsString( 'wplc_manage_policy', $form );
		SettingsTools::answer_guided_questions( array( 'staging_sites_per_live' => '5', 'renewal_grace_days' => '30' ), '' );
		$this->assertSame( array( 2, 30 ), array( Registry::int( 'staging_sites_per_live' ), Registry::int( 'renewal_grace_days' ) ) );
		$this->assertTrue( Settings::setup_state()['rules'] );
		wp_set_current_user( $this->admin );
	}
}
