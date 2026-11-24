<?php
/**
 * P2-I2 and P2-M8: the advance notice of automatic renewals.
 *
 * Switching the email "Upcoming automatic renewal" off stops every automatic charge (B-41). The
 * plugin does not send it behind the shop's back; it warns license managers, shows the problem on
 * the diagnostics page and raises the health alert `renewal_notice_disabled`.
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Notifications\Emails\RenewalNoticeEmail;
use WPLC\Notifications\Notifications;
use WPLC\Settings\Registry;

final class RenewalNoticeDisabledIntegrationTest extends WPLC_Integration_TestCase {

	public function tear_down(): void {
		$this->set_notice_enabled( true );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function notice_email(): \WC_Email {
		$emails = WC()->mailer()->get_emails();
		$this->assertArrayHasKey( Notifications::registry_key( 'renewal_notice' ), $emails );

		return $emails[ Notifications::registry_key( 'renewal_notice' ) ];
	}

	private function set_notice_enabled( bool $enabled ): void {
		$this->notice_email()->enabled = $enabled ? 'yes' : 'no';
	}

	private function warning_html(): string {
		ob_start();
		Notifications::render_renewal_notice_warning();
		return (string) ob_get_clean();
	}

	public function test_switched_off_notice_is_reported_while_licenses_renew_automatically(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$this->seed_license( array( 'renewal_enabled' => 1 ) );
		$this->seed_license( array( 'renewal_enabled' => 1, 'status' => 'past_due' ) );
		$this->seed_license( array( 'renewal_enabled' => 1, 'cancel_at_period_end' => 1 ) );
		$this->seed_license( array( 'renewal_enabled' => 0 ) );

		// Email on: nothing to report.
		$this->assertSame( 0, Notifications::licenses_blocked_by_disabled_renewal_notice() );
		$this->assertSame( 0, Diagnostics::metrics()['renewal_notice_disabled'] );
		$this->assertSame( '', $this->warning_html() );

		$this->set_notice_enabled( false );
		$this->assertSame( 2, Notifications::licenses_blocked_by_disabled_renewal_notice(), 'Licenses in force with automatic renewal.' );

		// Admin notice for license managers on the plugin's and the shop's screens.
		$html = $this->warning_html();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'automatic renewals are not charged', $html );
		$this->assertStringContainsString( '2 licenses with automatic renewal will not be charged', $html );
		$this->assertStringContainsString( 'section=wplc_email_renewal_notice', $html );
		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->warning_html(), 'Not on unrelated screens.' );
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->warning_html(), 'Only for license managers.' );
		$this->assertNotFalse( has_action( 'admin_notices', array( Notifications::class, 'render_renewal_notice_warning' ) ) );

		// Diagnostics: figure, row and health alert.
		$this->assertSame( 2, Diagnostics::metrics()['renewal_notice_disabled'] );
		$rows = array_values( array_filter( Diagnostics::rows(), static function ( $row ) { return 'Automatic renewals blocked' === $row['label']; } ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['state'] );
		$this->assertSame( '2', $rows[0]['value'] );
		$this->assertSame( 1, Diagnostics::thresholds()['renewal_notice_disabled'] );
		$alerts = array();
		remove_all_actions( 'wplc/health_alert' );
		add_action(
			'wplc/health_alert',
			static function ( $metric, $value, $threshold, $url = '' ) use ( &$alerts ) {
				$alerts[ $metric ] = array( $value, $url );
			},
			10,
			4
		);
		Diagnostics::emit_alerts();
		$this->assertSame( 2, $alerts['renewal_notice_disabled'][0] ?? null );
		$this->assertStringContainsString( 'section=wplc_email_renewal_notice', (string) $alerts['renewal_notice_disabled'][1] );

		// The notice is still not sent behind the shop's back: no charge without it (B-41).
		$this->assertFalse( Notifications::renewal_notice_sent( $this->license( $this->seed_license( array( 'renewal_enabled' => 1 ) )['id'] ) ) );
	}

	public function test_the_filter_can_silence_the_alert(): void {
		add_filter(
			'wplc_health_alert_thresholds',
			static function ( $thresholds ) {
				unset( $thresholds['renewal_notice_disabled'] );
				return $thresholds;
			}
		);
		$this->assertArrayNotHasKey( 'renewal_notice_disabled', Diagnostics::thresholds() );
	}

	// P2-M8: the description in the email list names the lead time in force.
	public function test_description_names_the_configured_lead_time(): void {
		$this->assertStringContainsString( 'Sent 7 days before', ( new RenewalNoticeEmail() )->get_description() );
		update_option( Registry::OPTION, array( 'renewal_notice_days' => '14' ) );
		$this->assertStringContainsString( 'Sent 14 days before', ( new RenewalNoticeEmail() )->get_description() );
		$this->assertStringContainsString( 'Sent 1 day before', RenewalNoticeEmail::description_text( 1 ) );
		$this->assertStringContainsString( 'no automatic charge', RenewalNoticeEmail::description_text( 7 ) );
	}
}
