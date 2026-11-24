<?php
/**
 * T-106: the short key format end to end, and legacy 106-character keys still work.
 *
 * New keys: `<PREFIX>-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX` (34 characters, Crockford Base32). Keys
 * issued before 24.11.2026 keep their 106 characters; nothing stored is rewritten. Both go
 * through the REST activation and check, the customer account, the public cancellation page,
 * the admin search, WP-CLI and the issue email.
 *
 * @package WPLC
 */

use WPLC\Account\PublicCancellation;
use WPLC\Admin\LicenseSearch;
use WPLC\CLI\LicenseCommand;
use WPLC\License\AuditLog;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class LicenseKeyFormatIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	/**
	 * Account email of the customer.
	 *
	 * @var string
	 */
	private $email;

	public function set_up(): void {
		parent::set_up();
		$this->email    = 't106-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test';
		$this->customer = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => $this->email,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	/**
	 * A license issued by the issuer (short key) or seeded with a legacy key.
	 *
	 * @param string $format Key format.
	 * @return array{id:int,key:string,row:array<string,mixed>}
	 */
	private function license_with( string $format ): array {
		global $wpdb;

		if ( LicenseKey::FORMAT_SHORT === $format ) {
			$plan = \WPLC\Catalog\Catalog::mapping_for_product( $this->create_license_product() );
			$this->assertIsArray( $plan );
			$id = LicenseIssuer::issue_manual( $this->customer, $plan, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 1, 'T-106' );
			$this->assertGreaterThan( 0, $id );
			$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 1 ), array( 'id' => $id ) );
		} else {
			$id = $this->seed_license(
				array(
					'customer_id'     => $this->customer,
					'renewal_enabled' => 1,
					'key_format'      => LicenseKey::FORMAT_LONG,
				)
			)['id'];
		}
		$row = $this->license( $id );
		$key = LicenseKey::verified_for_license( (string) $row['license_uuid'], (string) $row['license_key_secret_id'], (string) $row['license_key_hash'] );
		$this->assertNotSame( '', $key );

		return array(
			'id'  => $id,
			'key' => $key,
			'row' => $row,
		);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:callable(string):string}>
	 */
	public function data_formats(): array {
		return array(
			'short key, typed in lower case without hyphens' => array( LicenseKey::FORMAT_SHORT, 34, static fn ( string $k ): string => strtolower( str_replace( '-', '', $k ) ) ),
			'short key, O for 0 and spaces'                  => array( LicenseKey::FORMAT_SHORT, 34, static fn ( string $k ): string => substr( $k, 0, 5 ) . str_replace( array( '0', '-' ), array( 'O', ' ' ), substr( $k, 5 ) ) ),
			'legacy 106-character key'                       => array( LicenseKey::FORMAT_LONG, 106, static fn ( string $k ): string => ' ' . strtolower( $k ) . "\n" ),
		);
	}

	/**
	 * @dataProvider data_formats
	 *
	 * @param string                  $format Key format.
	 * @param int                     $length Key length.
	 * @param callable(string):string $typed  How the customer types the key.
	 */
	public function test_t106_key_works_everywhere( string $format, int $length, callable $typed ): void {
		$license = $this->license_with( $format );
		$key     = $license['key'];
		$row     = $license['row'];

		// Format and stored verifier; the reference keeps its 13 characters.
		$this->assertSame( $length, strlen( $key ) );
		$this->assertSame( $format, LicenseKey::stored_format( (string) $row['license_uuid'], (string) $row['license_key_secret_id'], (string) $row['license_key_hash'] ) );
		$this->assertSame( LicenseKey::hash( $key ), (string) $row['license_key_hash'] );
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $typed( $key ) ) );
		$this->assertSame( 13, strlen( (string) $row['key_prefix'] ) );
		if ( LicenseKey::FORMAT_SHORT === $format ) {
			$this->assertMatchesRegularExpression( '/^WPLC-(?:[0-9A-HJKMNP-TV-Z]{5}-){4}[0-9A-HJKMNP-TV-Z]{5}$/D', $key );
		}

		// REST: activation with the key as typed, then a signed entitlement check.
		$site = $this->activate_via_api( $typed( $key ), array( 'product_key' => (string) $row['product_key'] ) );
		$this->assertSame( 200, $site['response']->get_status(), (string) wp_json_encode( $site['response']->get_data() ) );
		$check = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );
		$this->assertSame( 200, $check->get_status(), (string) wp_json_encode( $check->get_data() ) );

		// Customer account: shows exactly this key.
		$html = $this->render_portal( $this->customer );
		$this->assertStringContainsString( esc_html( $key ), $html );

		// Admin search: a pasted key is kept only as the stored hash.
		$criteria = LicenseSearch::criteria( $typed( $key ) );
		$this->assertSame( array( LicenseSearch::TYPE_LICENSE_KEY_HASH, (string) $row['license_key_hash'] ), array( $criteria['type'], $criteria['value'] ) );

		// WP-CLI: wp wplc license show <key>.
		$details = LicenseCommand::details( $typed( $key ) );
		$this->assertIsArray( $details );
		$this->assertSame( $license['id'], (int) $details['license']['id'] );
		$this->assertTrue( $details['license']['key_verifiable'] );

		// Audit log: the key is redacted.
		AuditLog::record( 't106_redaction', 'license', $license['id'], 'Customer wrote ' . $typed( $key ) );
		global $wpdb;
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$this->tables()['audit_log']} WHERE action = %s ORDER BY id DESC LIMIT 1", 't106_redaction' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertStringContainsString( '[REDACTED-LICENSE-KEY]', $stored );
		$this->assertStringNotContainsStringIgnoringCase( substr( str_replace( '-', '', $key ), 4, 16 ), str_replace( array( '-', ' ' ), '', $stored ) );

		// Support resends the key email (full key enabled by the shop): the key in its own format.
		$mail                               = $this->email_object( 'issued' );
		$mail->settings['include_full_key'] = 'yes';
		reset_phpmailer_instance();
		$this->assertTrue( \WPLC\Admin\SupportActions::resend_key( $license['id'], 'T-106 test', 1 ) );
		$bodies = implode( "\n", array_column( $this->mails(), 'body' ) );
		$this->assertStringContainsString( $key, $bodies );
		$mail->settings['include_full_key'] = 'no';

		// Public cancellation with the key as reference: both form steps.
		$reference = PublicCancellation::normalize_reference( $typed( $key ) );
		$this->assertCount( 1, PublicCancellation::find_licenses( $this->email, $reference ) );
		$review = PublicCancellation::process( 'review', $this->email, $reference );
		PublicCancellation::process( 'confirm', (string) $review['email'], (string) $review['reference'], '', (int) $review['expires'], (string) $review['signature'] );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'], 'The renewal is cancelled.' );
	}

	public function test_t106_a_reissued_legacy_key_comes_out_short(): void {
		$license = $this->license_with( LicenseKey::FORMAT_SHORT );
		$this->assertSame( 34, strlen( $license['key'] ) );

		// A reissued legacy key comes out short; the old key stops working.
		$legacy = $this->license_with( LicenseKey::FORMAT_LONG );
		$this->assertTrue( \WPLC\Admin\SupportActions::reissue_key( $legacy['id'], 'T-106 test', 1 ) );
		$row = $this->license( $legacy['id'] );
		$new = LicenseKey::verified_for_license( (string) $row['license_uuid'], (string) $row['license_key_secret_id'], (string) $row['license_key_hash'] );
		$this->assertSame( 34, strlen( $new ) );
		$this->assertSame( 200, $this->activate_via_api( $new, array( 'product_key' => (string) $row['product_key'] ) )['response']->get_status() );
		$this->assertSame( 401, $this->activate_via_api( $legacy['key'], array( 'product_key' => (string) $row['product_key'] ) )['response']->get_status() );
	}
}
