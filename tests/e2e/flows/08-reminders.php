<?php
/**
 * Flow 8: settings screen and expiry reminders / renewal notices from the daily job.
 *
 * TIME IS SIMULATED by editing paid_until in the e2e database (e2e_move_paid_until()).
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t         = e2e_tables();
$simple_id = (int) e2e_get( 'simple_id' );
$utc       = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};

// --- Settings screen: save through options.php like the browser does. ---
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$page          = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-settings' ), array( 'cookies' => $admin_cookies ) );
$form          = e2e_form( $page['body'], array( 'options.php', 'wplc_settings[reminder_days]' ) );
e2e_ok( 200 === $page['code'] && is_array( $form ) && 'wplc_settings' === ( $form['option_page'] ?? '' ), 'settings screen renders its form', $page['code'] );
$saved = e2e_submit(
	(array) $form,
	$admin_cookies,
	array(
		'wplc_settings[package_hosts]' => "downloads.example.com\nhttps://not-a-host.example/path\n",
		'wplc_settings[reminder_days]' => '14, 7, abc, 0',
		'wplc_settings[sender_note]'   => "Questions? support@shop.test\n<script>x</script>",
	)
);
wp_cache_delete( 'wplc_settings', 'options' );
wp_cache_delete( 'alloptions', 'options' );
$settings = get_option( 'wplc_settings' );
e2e_ok( 302 === $saved['code'] && false !== strpos( $saved['location'], 'settings-updated' ), 'saving the settings redirects back with settings-updated', array( $saved['code'], $saved['location'] ) );
e2e_eq( array( 'downloads.example.com' ), $settings['package_hosts'] ?? null, 'settings: valid package host stored, URL-with-path line rejected' );
e2e_eq( '14,7', str_replace( ' ', '', (string) ( $settings['reminder_days'] ?? '' ) ), 'settings: reminder days sanitized to 14,7' );
e2e_ok( false === strpos( (string) ( $settings['sender_note'] ?? '' ), '<script' ) && false !== strpos( (string) ( $settings['sender_note'] ?? '' ), 'support@shop.test' ), 'settings: sender note stored without markup', $settings['sender_note'] ?? null );
$after = e2e_http( 'GET', $saved['location'], array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $after['code'] && false !== strpos( $after['body'], 'downloads.example.com' ) && false !== strpos( $after['body'], 'not a valid host name' ), 'settings screen shows the saved values and the warning about the rejected line', $after['code'] );
e2e_ok( in_array( 'downloads.example.com', (array) apply_filters( 'wplc_update_package_hosts', array( 'shop.test' ) ), true ), 'the saved host is on the package host allow-list' );
e2e_eq( array( 14, 7 ), \WPLC\Notifications\Notifications::lead_days(), 'the reminder schedule uses the saved days' );
$carol_cookies = e2e_login( (int) e2e_get( 'carol_id' ) );
$forbidden     = e2e_http( 'POST', admin_url( 'options.php' ), array( 'cookies' => $carol_cookies, 'body' => array( 'option_page' => 'wplc_settings', 'action' => 'update', '_wpnonce' => wp_create_nonce( 'wplc_settings-options' ), 'wplc_settings[reminder_days]' => '1' ) ) );
wp_cache_delete( 'wplc_settings', 'options' );
wp_cache_delete( 'alloptions', 'options' );
e2e_eq( '14,7', str_replace( ' ', '', (string) get_option( 'wplc_settings' )['reminder_days'] ), 'a customer cannot change the settings' );
e2e_login( 0 );

// --- Reminders. ---
// Mails about one license (every license email prints the key prefix).
$mails_about = static function ( array $license ) {
	return array_values(
		array_filter(
			wplc_e2e_mails(),
			static function ( $mail ) use ( $license ) {
				return false !== strpos( (string) $mail['message'], (string) $license['key_prefix'] );
			}
		)
	);
};
$helen_id = e2e_customer( 'helen' );
$order    = e2e_checkout( $helen_id, array( $simple_id => 3 ), 'bacs', false );
$licenses = e2e_order_licenses( (int) $order->get_id() );
$seven    = (int) $licenses[0]['id'];
$far      = (int) $licenses[1]['id'];
$expired  = (int) $licenses[2]['id'];
$ivan_id  = e2e_customer( 'ivan' );
$auto     = e2e_order_licenses( (int) e2e_checkout( $ivan_id, array( $simple_id => 1 ), 'wplc_e2e_fake', true )->get_id() )[0];

e2e_move_paid_until( $seven, $utc( time() + 7 * DAY_IN_SECONDS - 600 ) );
e2e_move_paid_until( $far, $utc( time() + 60 * DAY_IN_SECONDS ) );
e2e_move_paid_until( (int) $auto['id'], $utc( time() + 6 * DAY_IN_SECONDS ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$t['licenses']} SET status = 'suspended' WHERE id = %d", $expired ) );
e2e_move_paid_until( $expired, $utc( time() + 5 * DAY_IN_SECONDS ) );

wplc_e2e_clear_mails();
delete_option( 'wplc_reminder_cursor' );
do_action( 'wplc_daily_lifecycle' );
e2e_run_actions( 'wplc_send_reminders' );
$to_helen = $mails_about( $licenses[0] );
e2e_eq( 1, count( $to_helen ), '[time simulated: 7 days left] first daily run: exactly one email about the license', array_column( $to_helen, 'subject' ) );
e2e_eq( array( 0, 0 ), array( count( $mails_about( $licenses[1] ) ), count( $mails_about( $licenses[2] ) ) ), 'no reminder for the license with 60 days left and none for the suspended license' );
e2e_ok( $to_helen && false !== strpos( wp_json_encode( $to_helen[0]['to'] ), 'helen@customer.test' ), 'the reminder goes to the customer' );
if ( $to_helen ) {
	e2e_ok( false !== strpos( $to_helen[0]['subject'], 'license ends on' ) && false !== strpos( $to_helen[0]['subject'], 'Voucher Captain Pro' ), 'reminder subject names product and end date', $to_helen[0]['subject'] );
	e2e_ok( ! preg_match( '/\{[a-z_]+\}/', $to_helen[0]['subject'] . $to_helen[0]['message'] ), 'no unreplaced placeholder in the reminder' );
	e2e_ok( false !== strpos( $to_helen[0]['message'], $licenses[0]['key_prefix'] ) && false === strpos( $to_helen[0]['message'], e2e_key( $licenses[0] ) ), 'reminder shows the key prefix, not the full key' );
	e2e_ok( false !== strpos( $to_helen[0]['message'], 'wplc-licenses' ), 'reminder links to the account licenses page (where "renew" is offered)' );
}
$to_ivan = $mails_about( $auto );
e2e_ok( 1 === count( $to_ivan ) && false !== strpos( $to_ivan[0]['subject'], 'license renews on' ), 'the automatically renewing license gets one renewal notice ("renews on") instead of an expiry reminder', array_column( $to_ivan, 'subject' ) );
if ( $to_ivan ) {
	e2e_ok( false !== strpos( wp_strip_all_tags( $to_ivan[0]['message'] ), '49' ) && ! preg_match( '/\{[a-z_]+\}/', $to_ivan[0]['subject'] . $to_ivan[0]['message'] ), 'renewal notice names the amount and has no unreplaced placeholder', wp_strip_all_tags( $to_ivan[0]['message'] ) );
}

// Second and third run on the same day / the next day: nothing new.
delete_option( 'wplc_reminder_cursor' );
do_action( 'wplc_daily_lifecycle' );
e2e_run_actions( 'wplc_send_reminders' );
do_action( 'wplc_daily_lifecycle' );
$count_helen = count( $mails_about( $licenses[0] ) );
$count_ivan  = count( $mails_about( $auto ) );
e2e_eq( array( 1, 1 ), array( $count_helen, $count_ivan ), 'after two more runs of the daily job there is still exactly one reminder per license' );
$log_table = \WPLC\Notifications\ReminderLog::table_name();
e2e_eq( array( 'expiry_7' ), $wpdb->get_col( $wpdb->prepare( "SELECT reminder_type FROM {$log_table} WHERE license_id = %d AND reminder_type <> 'issued'", $seven ) ), 'the reminder log has exactly one reminder entry (expiry_7) for the license' ); // phpcs:ignore WordPress.DB.PreparedSQL

// The later stage (smallest lead) is a separate reminder; a renewed period gets its own reminders.
e2e_move_paid_until( $far, $utc( time() + 13 * DAY_IN_SECONDS ) );
do_action( 'wplc_daily_lifecycle' );
e2e_run_actions( 'wplc_send_reminders' );
e2e_eq( array( 1, 1 ), array( count( $mails_about( $licenses[1] ) ), count( $mails_about( $licenses[0] ) ) ), '[time simulated: 13 days left on the second license] it now gets its 14-day reminder; the first license gets nothing new' );

// Reminders switched off in the settings.
$settings                  = get_option( 'wplc_settings' );
$settings['reminder_days'] = '0';
update_option( 'wplc_settings', $settings );
$jane_id = e2e_customer( 'jane' );
$jane    = e2e_order_licenses( (int) e2e_checkout( $jane_id, array( $simple_id => 1 ), 'bacs', false )->get_id() )[0];
e2e_move_paid_until( (int) $jane['id'], $utc( time() + 3 * DAY_IN_SECONDS ) );
wplc_e2e_clear_mails();
do_action( 'wplc_daily_lifecycle' );
e2e_run_actions( 'wplc_send_reminders' );
e2e_eq( 0, count( $mails_about( $jane ) ), 'with reminder days "0" no expiry reminder is sent' );
$settings['reminder_days'] = '30,7';
update_option( 'wplc_settings', $settings );

e2e_note( 'Not asserted, reported: the "sender note" setting is stored but no email template prints it.' );
e2e_set( 'helen_id', $helen_id );
