<?php
/**
 * INH-05: a new renewal price for existing licenses, with advance notice.
 *
 * @package WPLC
 */

use WPLC\Admin\PriceChangeAdmin;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\PriceChange;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\Notifications\ReminderLog;
use WPLC\Notifications\RenewalTerms;

require_once __DIR__ . '/helpers/RenewalTestCase.php';

final class PriceChangeIntegrationTest extends WPLC_Renewal_TestCase {

	/**
	 * Mails handed to the mailer.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

	/**
	 * Whether the mailer accepts messages.
	 *
	 * @var bool
	 */
	private $mailer_works = true;

	/**
	 * Licenses reported through wplc/price_change_not_applied.
	 *
	 * @var int[]
	 */
	private $not_applied = array();

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'pre_wp_mail', '__return_true' );
		add_filter(
			'pre_wp_mail',
			function ( $result, $atts ) {
				if ( ! $this->mailer_works ) {
					return false;
				}
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		add_action(
			'wplc/price_change_not_applied',
			function ( $license_id ) {
				$this->not_applied[] = (int) $license_id;
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wplc_billing_paused' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function in_days( int $days ): int {
		return time() + $days * DAY_IN_SECONDS + 60;
	}

	/** Mails whose subject contains a text. */
	private function mails_with( string $needle ): array {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) use ( $needle ) {
					return false !== strpos( (string) $mail['subject'], $needle );
				}
			)
		);
	}

	/**
	 * Pretend the announcement and its notice happened $days_ago days ago, for a date $effective_in days from now.
	 */
	private function age( int $license_id, int $days_ago, int $effective_in, bool $notified = true ): void {
		global $wpdb;
		$then = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		$wpdb->update(
			$this->tables()['licenses'],
			array(
				'price_change_announced_at' => $then,
				'price_change_notified_at'  => $notified ? $then : null,
				'price_change_effective_at' => gmdate( 'Y-m-d H:i:s', time() + $effective_in * DAY_IN_SECONDS ),
			),
			array( 'id' => $license_id )
		);
	}

	public function test_announcing_stores_the_change_notifies_the_customer_and_keeps_the_price_until_the_date(): void {
		$f     = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$id    = $f['license_id'];
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Refused: not higher, too early, no reason.
		$this->assertSame( PriceChange::RESULT_NOT_AN_INCREASE, PriceChange::announce( $id, 4900, $this->in_days( 60 ), 'Price list', $admin ) );
		$this->assertSame( PriceChange::RESULT_NOTICE_TOO_SHORT, PriceChange::announce( $id, 5900, $this->in_days( 59 ) - 120, 'Price list', $admin ) );
		$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::announce( $id, 5900, $this->in_days( 60 ), ' ', $admin ) );
		$this->assertNull( PriceChange::pending( $this->license( $id ) ) );
		$this->assertCount( 0, $this->mails_with( 'renewal price' ) );

		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list 2027', $admin ) );
		$license = $this->license( $id );
		$pending = PriceChange::pending( $license );
		$this->assertSame( 5900, $pending['amount_minor'] );
		$this->assertSame( 60, $pending['notice_days'] );
		$this->assertGreaterThan( 0, $pending['notified_at'], 'The mailer accepted the notice, so its time is recorded.' );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'], 'The stored amount stays until the change applies.' );
		$this->assertTrue( ReminderLog::was_sent( $id, PriceChange::REMINDER_TYPE, $pending['announced_at'] ), 'Reminder log entry.' );
		$actions = $this->audit_actions( 'license', $id );
		$this->assertContains( 'renewal_price_change_announced', $actions );
		$this->assertContains( 'renewal_price_change_notified', $actions );
		$announced = array_values( array_filter( $this->audit_rows( 'license', $id ), static function ( $row ) { return 'renewal_price_change_announced' === $row['action']; } ) )[0];
		$this->assertSame( (string) $admin, (string) $announced['actor_user_id'] );
		$this->assertSame( 'Price list 2027', $announced['reason'] );

		// The email names the old price, the new price, the date and the way out.
		$mails = $this->mails_with( 'renewal price' );
		$this->assertCount( 1, $mails );
		$body = wp_strip_all_tags( (string) $mails[0]['message'] );
		$this->assertStringContainsString( '49', $body );
		$this->assertStringContainsString( '59', $body );
		$this->assertStringContainsString( wp_strip_all_tags( \WPLC\Notifications\Notifications::format_date( $pending['applies_at'] ) ), $body );
		$this->assertStringContainsString( 'cancel the automatic renewal', $body );

		// A second announcement needs the first one withdrawn; nothing is sent twice.
		$this->assertSame( PriceChange::RESULT_ALREADY_ANNOUNCED, PriceChange::announce( $id, 6900, $this->in_days( 90 ), 'Again', $admin ) );
		$this->assertTrue( PriceChange::send_notice( $id ) );
		$this->assertCount( 1, $this->mails_with( 'renewal price' ) );

		// Until the date: automatic and manual renewals cost the old amount.
		$this->assertSame( 4900, RenewalOrder::price( $license, false )['amount_minor'] );
		$this->assertSame( 4900, RenewalOrder::price( $license, true )['amount_minor'] );
		$order = ManualRenewal::start( $id, $f['customer'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '49.00', wc_format_decimal( $order->get_total(), 2 ) );
		PriceChange::daily();
		$this->assertSame( 4900, (int) $this->license( $id )['renewal_amount_minor'], 'The daily job changes nothing before the date.' );
	}

	public function test_from_the_date_on_renewals_cost_the_new_price_and_the_job_makes_it_the_stored_amount(): void {
		$f  = $this->renewing_license( -60 );
		$id = $f['license_id'];
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 ) );
		// 61 days later: the notice is older than the period and the date has come.
		$this->age( $id, 61, -1 );
		$license = $this->license( $id );
		$this->assertTrue( PriceChange::applies( $license, time() ) );
		$this->assertSame( 5900, RenewalOrder::price( $license, true )['amount_minor'] );
		$this->assertSame( 5900, RenewalOrder::price( $license, false )['amount_minor'] );
		$this->assertStringContainsString( '59', RenewalTerms::amount_text( RenewalTerms::gross( $license ) ), 'Emails and account name the amount that will be charged.' );

		// The automatic charge takes the new amount (first run sends the advance notice of the charge, B-41).
		RenewalProcessor::process( $f['renewal_id'] );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges() );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		$this->assertSame( '59.00', wc_format_decimal( wc_get_order( (int) $renewal['renewal_order_id'] )->get_total(), 2 ) );

		// The daily job writes the amount and clears the announcement, once.
		PriceChange::daily();
		$license = $this->license( $id );
		$this->assertSame( 5900, (int) $license['renewal_amount_minor'] );
		$this->assertNull( PriceChange::pending( $license ) );
		$this->assertNull( $license['price_change_notified_at'] );
		$this->assertSame( 1, count( array_keys( $this->audit_actions( 'license', $id ), 'renewal_price_change_applied', true ) ) );
		PriceChange::daily();
		$this->assertSame( 1, count( array_keys( $this->audit_actions( 'license', $id ), 'renewal_price_change_applied', true ) ) );
		// The card on file is the one the customer saved; nothing about the payment method was touched.
		$this->assertSame( (string) $this->license( $id )['payment_token_id'], (string) $license['payment_token_id'] );
	}

	public function test_an_order_priced_before_the_date_keeps_its_amount(): void {
		$f  = $this->renewing_license( 5 * DAY_IN_SECONDS );
		$id = $f['license_id'];
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		$order = ManualRenewal::start( $id, $f['customer'] );
		$this->assertSame( '49.00', wc_format_decimal( $order->get_total(), 2 ) );
		$order->update_status( 'on-hold' );

		$this->age( $id, 61, -1 );
		// Starting again leads to the same order (or says that it waits for its payment); it is never re-priced.
		$again = ManualRenewal::start( $id, $f['customer'] );
		if ( $again instanceof WC_Order ) {
			$this->assertSame( $order->get_id(), $again->get_id() );
		}
		$this->assertSame( '49.00', wc_format_decimal( wc_get_order( $order->get_id() )->get_total(), 2 ), 'An order waiting for its payment is not re-priced.' );
	}

	public function test_without_a_sent_notice_the_old_price_is_charged_and_the_shop_is_told_once(): void {
		$f  = $this->renewing_license( -60 );
		$id = $f['license_id'];
		$this->mailer_works = false;
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 ) );
		$pending = PriceChange::pending( $this->license( $id ) );
		$this->assertSame( 0, $pending['notified_at'], 'The mailer refused the notice.' );
		$this->assertFalse( ReminderLog::was_sent( $id, PriceChange::REMINDER_TYPE, $pending['announced_at'] ) );
		$this->assertNotContains( 'renewal_price_change_notified', $this->audit_actions( 'license', $id ) );

		// The date passes without a notice.
		$this->age( $id, 61, -1, false );
		$license = $this->license( $id );
		$this->assertSame( 4900, RenewalOrder::price( $license, false )['amount_minor'] );
		$this->assertSame( 4900, RenewalOrder::price( $license, true )['amount_minor'] );
		PriceChange::daily();
		PriceChange::daily();
		$this->assertSame( array( $id ), $this->not_applied, 'Reported once, not every day.' );
		$this->assertSame( 1, count( array_keys( $this->audit_actions( 'license', $id ), 'renewal_price_change_not_applied', true ) ) );
		$this->assertSame( 4900, (int) $this->license( $id )['renewal_amount_minor'] );

		// The automatic renewal goes through at the old price.
		$this->mailer_works = true;
		add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		RenewalProcessor::process( $f['renewal_id'] );
		remove_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		$this->assertSame( 1, $this->charges() );
		$this->assertSame( '49.00', wc_format_decimal( wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] )->get_total(), 2 ) );

		// The mailer works again: the daily job sends the notice, and the period starts only now.
		PriceChange::daily();
		$pending = PriceChange::pending( $this->license( $id ) );
		$this->assertGreaterThan( 0, $pending['notified_at'] );
		$this->assertCount( 1, $this->mails_with( 'renewal price' ) );
		$this->assertGreaterThanOrEqual( time() + 60 * DAY_IN_SECONDS - 5, $pending['applies_at'] );
		$this->assertSame( 4900, RenewalOrder::price( $this->license( $id ), false )['amount_minor'] );
		$mail = wp_strip_all_tags( (string) $this->mails_with( 'renewal price' )[0]['message'] );
		$this->assertStringContainsString( wp_strip_all_tags( \WPLC\Notifications\Notifications::format_date( $pending['applies_at'] ) ), $mail, 'The late email names the date that really applies.' );
	}

	public function test_a_notice_younger_than_the_period_does_not_move_the_price(): void {
		$f  = $this->renewing_license( -60 );
		$id = $f['license_id'];
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		// The announced date came, but the notice is only ten days old.
		$this->age( $id, 10, -1 );
		$this->assertSame( 4900, RenewalOrder::price( $this->license( $id ), false )['amount_minor'] );
		PriceChange::daily();
		$this->assertSame( 4900, (int) $this->license( $id )['renewal_amount_minor'] );
		$this->assertNotNull( PriceChange::pending( $this->license( $id ) ), 'The announcement waits for the end of the period.' );
		$this->assertSame( array(), $this->not_applied, 'A notice was sent: nothing to report.' );
	}

	public function test_withdrawing_keeps_the_price_and_tells_the_customer(): void {
		$f     = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$id    = $f['license_id'];
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( PriceChange::RESULT_NOTHING_ANNOUNCED, PriceChange::withdraw( $id, 'Mistake', $admin ) );
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', $admin );
		$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::withdraw( $id, '', $admin ) );

		$this->assertSame( PriceChange::RESULT_OK, PriceChange::withdraw( $id, 'Mistake', $admin ) );
		$license = $this->license( $id );
		$this->assertNull( PriceChange::pending( $license ) );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$rows = array_values( array_filter( $this->audit_rows( 'license', $id ), static function ( $row ) { return 'renewal_price_change_withdrawn' === $row['action']; } ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $admin, (string) $rows[0]['actor_user_id'] );
		$mails = $this->mails_with( 'stays the same' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'withdrawn', wp_strip_all_tags( (string) $mails[0]['message'] ) );
		// Even long after the former date nothing changes.
		$this->assertSame( 4900, RenewalOrder::price( $license, false )['amount_minor'] );
		// A new announcement is possible and sends a new notice.
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $id, 6900, $this->in_days( 60 ), 'Second try', $admin ) );
		$this->assertCount( 2, $this->mails_with( 'license changes' ), 'One notice per announcement.' );
	}

	public function test_a_plan_change_drops_the_announcement_and_a_suspended_license_is_left_alone(): void {
		global $wpdb;
		$f  = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$id = $f['license_id'];
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		do_action( 'wplc/license_plan_changed', $id );
		$this->assertNull( PriceChange::pending( $this->license( $id ) ), 'The amount of the new plan is a new agreement.' );
		$this->assertContains( 'renewal_price_change_dropped', $this->audit_actions( 'license', $id ) );

		// A suspended license: nothing can be announced; an announcement made earlier stays but is not applied while suspended.
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		$this->age( $id, 61, -1 );
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'suspended' ), array( 'id' => $id ) );
		PriceChange::daily();
		$license = $this->license( $id );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$this->assertNotNull( PriceChange::pending( $license ) );
		$other = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'suspended' ), array( 'id' => $other['license_id'] ) );
		$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::announce( $other['license_id'], 5900, $this->in_days( 60 ), 'Price list', 1 ) );
		// Reactivated after the date: the customer was told in time, the new price applies.
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'active' ), array( 'id' => $id ) );
		PriceChange::daily();
		$this->assertSame( 5900, (int) $this->license( $id )['renewal_amount_minor'] );
	}

	public function test_a_paused_copy_of_the_shop_sends_no_notice(): void {
		$f  = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$id = $f['license_id'];
		add_filter( 'wplc_billing_paused', '__return_true' );
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 ) );
		$this->assertCount( 0, $this->mails_with( 'renewal price' ) );
		$this->assertSame( 0, PriceChange::pending( $this->license( $id ) )['notified_at'] );
		remove_filter( 'wplc_billing_paused', '__return_true' );
		PriceChange::daily();
		$this->assertCount( 1, $this->mails_with( 'renewal price' ) );
		$this->assertGreaterThan( 0, PriceChange::pending( $this->license( $id ) )['notified_at'] );
	}

	public function test_a_missing_card_is_not_replaced_at_the_new_price_either(): void {
		$f  = $this->renewing_license( -60 );
		$id = $f['license_id'];
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		$this->age( $id, 61, -1 );
		// The customer has a second card; the card of the license is deleted.
		$second = $this->token( $f['customer'] );
		$token  = (int) $this->license( $id )['payment_token_id'];
		$this->assertNotSame( $second, $token );
		WC_Payment_Tokens::delete( $token );

		add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		RenewalProcessor::process( $f['renewal_id'] );
		remove_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		$this->assertSame( 0, $this->charges(), 'No other card of the customer is charged, at no price.' );
		$this->assertNotSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	public function test_bulk_announcement_for_a_plan_and_the_screens(): void {
		global $wpdb;
		$a = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$b = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$c = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$t = $this->tables()['licenses'];
		foreach ( array( $a, $b, $c ) as $f ) {
			$wpdb->update( $t, array( 'product_key' => 'bulk-product', 'plan_key' => 'single' ), array( 'id' => $f['license_id'] ) );
		}
		// One license already pays more, one is in another plan.
		$wpdb->update( $t, array( 'renewal_amount_minor' => 6900 ), array( 'id' => $b['license_id'] ) );
		$wpdb->update( $t, array( 'plan_key' => 'agency' ), array( 'id' => $c['license_id'] ) );

		$currency = (string) $this->license( $a['license_id'] )['renewal_currency'];
		$dry      = PriceChange::announce_for_plan( 'bulk-product', 'single', 5900, $currency, $this->in_days( 60 ), 'Price list', 1, true );
		$this->assertSame( 1, $dry['announced'] );
		$this->assertNull( PriceChange::pending( $this->license( $a['license_id'] ) ), 'A dry run writes nothing.' );
		$this->assertCount( 0, $this->mails_with( 'renewal price' ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post = array(
			'product_key'    => 'bulk-product',
			'plan_key'       => 'single',
			'currency'       => $currency,
			'amount'         => '59.00',
			'effective_date' => gmdate( 'Y-m-d', PriceChange::earliest_effective_at( time() ) ),
			'reason'         => 'Price list 2027',
			'_wpnonce'       => wp_create_nonce( 'wplc_admin_price_change_plan' ),
		);
		// Without the ticked confirmation no email goes out.
		$result = $this->run_admin_post( 'wplc_admin_price_change_plan', $post );
		$this->assertStringContainsString( 'price_change_not_confirmed', $result['location'] );
		$this->assertCount( 0, $this->mails_with( 'renewal price' ) );
		$result = $this->run_admin_post( 'wplc_admin_price_change_plan', $post + array( 'confirm_announce' => '1' ) );
		$this->assertStringContainsString( 'price_change_plan_done', $result['location'] );
		$this->assertStringContainsString( 'wplc_n=1', $result['location'] );
		$this->assertSame( 5900, PriceChange::pending( $this->license( $a['license_id'] ) )['amount_minor'] );
		$this->assertNull( PriceChange::pending( $this->license( $b['license_id'] ) ), 'A license that already pays more is not touched.' );
		$this->assertNull( PriceChange::pending( $this->license( $c['license_id'] ) ), 'Another plan is not touched.' );
		$this->assertCount( 1, $this->mails_with( 'renewal price' ) );

		// The license page shows the announcement and offers to withdraw it; the handlers need capability and nonce.
		ob_start();
		\WPLC\Admin\LicenseDetail::render( $a['license_id'] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'wplc_admin_price_change_withdraw', $html );
		$this->assertStringContainsString( 'The customer was notified on', $html );
		ob_start();
		\WPLC\Admin\LicenseDetail::render( $b['license_id'] );
		$this->assertStringContainsString( 'wplc_admin_price_change_announce', (string) ob_get_clean() );
		ob_start();
		PriceChangeAdmin::render();
		$this->assertStringContainsString( '<code>bulk-product</code>', (string) ob_get_clean() );

		$withdraw = array( 'license_id' => $a['license_id'], 'reason' => 'Mistake' );
		$this->assertSame( 'die', $this->run_admin_post( 'wplc_admin_price_change_withdraw', $withdraw + array( '_wpnonce' => 'wrong' ) )['outcome'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( 'die', $this->run_admin_post( 'wplc_admin_price_change_withdraw', $withdraw + array( '_wpnonce' => wp_create_nonce( 'wplc_admin_price_change_withdraw_' . $a['license_id'] ) ) )['outcome'] );
		$this->assertNotNull( PriceChange::pending( $this->license( $a['license_id'] ) ) );
		wp_set_current_user( $admin );
		$result = $this->run_admin_post( 'wplc_admin_price_change_withdraw', $withdraw + array( '_wpnonce' => wp_create_nonce( 'wplc_admin_price_change_withdraw_' . $a['license_id'] ) ) );
		$this->assertStringContainsString( 'price_change_withdrawn', $result['location'] );
		$this->assertNull( PriceChange::pending( $this->license( $a['license_id'] ) ) );

		$single = array(
			'license_id'     => $a['license_id'],
			'amount'         => '59.00',
			'effective_date' => gmdate( 'Y-m-d', PriceChange::earliest_effective_at( time() ) ),
			'reason'         => 'Again',
			'_wpnonce'       => wp_create_nonce( 'wplc_admin_price_change_announce_' . $a['license_id'] ),
		);
		$this->assertStringContainsString( 'price_change_announced', $this->run_admin_post( 'wplc_admin_price_change_announce', $single )['location'] );
		$early                   = $single;
		$early['license_id']     = $b['license_id'];
		$early['_wpnonce']       = wp_create_nonce( 'wplc_admin_price_change_announce_' . $b['license_id'] );
		$early['amount']         = '79.00';
		$early['effective_date'] = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
		$this->assertStringContainsString( 'price_change_notice_too_short', $this->run_admin_post( 'wplc_admin_price_change_announce', $early )['location'] );
	}

	public function test_the_account_and_the_charge_notice_name_the_announced_price(): void {
		$f  = $this->renewing_license( 20 * DAY_IN_SECONDS );
		$id = $f['license_id'];
		PriceChange::announce( $id, 5900, $this->in_days( 60 ), 'Price list', 1 );
		$license = $this->license( $id );
		$rows    = RenewalTerms::rows( $license );
		$labels  = array_keys( $rows );
		$this->assertStringContainsString( '49', $rows[ __( 'Amount', 'wp-license' ) ], 'The next renewal (in 20 days) still costs the old price.' );
		$from = array_values( array_filter( $labels, static function ( $label ) { return 0 === strpos( $label, 'Amount from ' ); } ) );
		$this->assertCount( 1, $from );
		$this->assertStringContainsString( '59', $rows[ $from[0] ] );

		// A license that renews by hand shows the sentence in its renewal text.
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 0 ), array( 'id' => $id ) );
		wp_set_current_user( $f['customer'] );
		ob_start();
		\WPLC\Account\CustomerPortal::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'a renewal costs', $html );

		// Once the price applies by the next due date, it is the one amount shown.
		$this->age( $id, 61, -1 );
		$rows = RenewalTerms::rows( array_merge( $this->license( $id ), array( 'renewal_enabled' => 1 ) ) );
		$this->assertStringContainsString( '59', $rows[ __( 'Amount', 'wp-license' ) ] );
		$this->assertCount( 3, $rows );
	}

	public function test_the_two_emails_are_registered_and_have_a_preview(): void {
		$emails = WC()->mailer()->get_emails();
		foreach ( array( 'WPLC_Email_price_change' => 'wplc_price_change', 'WPLC_Email_price_change_withdrawn' => 'wplc_price_change_withdrawn' ) as $key => $id ) {
			$found = null;
			foreach ( $emails as $email ) {
				if ( $id === $email->id ) {
					$found = $email;
				}
			}
			$this->assertNotNull( $found, $id );
			$found->set_preview_data();
			foreach ( array( $found->get_content_html(), $found->get_content_plain() ) as $content ) {
				$this->assertStringContainsString( '59', wp_strip_all_tags( $content ), $id . ' preview names the sample price.' );
			}
		}
	}
}
