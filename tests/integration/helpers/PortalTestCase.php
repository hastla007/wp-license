<?php
/**
 * Shared helpers for the customer account, email and reminder integration tests.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Gateway\RecurringGatewayAdapter;
use WPLC\Notifications\ReminderLog;

if ( ! class_exists( 'WPLC_Test_Renewal_Adapter' ) ) {
	/**
	 * Renewal adapter of a made-up gateway: every CC token of the customer can be charged.
	 */
	final class WPLC_Test_Renewal_Adapter implements RecurringGatewayAdapter {
		public function gateway_id(): string {
			return 'wplc_test_gateway';
		}

		public function supports_checkout(): bool {
			return true;
		}

		public function supports_saved_token( int $token_id, int $customer_id ): bool {
			$token = WC_Payment_Tokens::get( $token_id );
			return $token && 'wplc_test_gateway' === $token->get_gateway_id() && (int) $token->get_user_id() === $customer_id;
		}

		public function charge( $order, int $token_id, string $idempotency_key ) {
			return new WP_Error( 'not_used', 'The test adapter never charges.' );
		}
	}
}

/**
 * Base class: captured emails, rendered account page, posted frontend forms.
 */
abstract class WPLC_Portal_TestCase extends WPLC_Integration_TestCase {

	/**
	 * The reminder log must be a real table: inside a test WordPress turns
	 * CREATE TABLE into CREATE TEMPORARY TABLE, which the installer cannot verify.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		ReminderLog::reset();
		if ( ! ReminderLog::maybe_install() ) {
			throw new RuntimeException( 'Fixture: the reminder log table could not be created.' );
		}
	}

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		if ( function_exists( 'wc_clear_notices' ) && WC()->session ) {
			wc_clear_notices();
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function tear_down(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/**
	 * Emails handed to the mailer since the last reset.
	 *
	 * @return array<int,array{to:string,subject:string,body:string}>
	 */
	protected function mails(): array {
		$mails = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $sent ) {
			$to = array();
			foreach ( (array) $sent['to'] as $recipient ) {
				$to[] = is_array( $recipient ) ? (string) $recipient[0] : (string) $recipient;
			}
			$mails[] = array(
				'to'      => implode( ',', $to ),
				'subject' => (string) $sent['subject'],
				'body'    => (string) $sent['body'],
			);
		}
		return $mails;
	}

	/**
	 * One of the license email objects of the WooCommerce mailer.
	 *
	 * @param string $key Short key, e.g. "issued".
	 * @return WC_Email
	 */
	protected function email_object( string $key ): WC_Email {
		$emails = WC()->mailer()->get_emails();
		$name   = \WPLC\Notifications\Notifications::registry_key( $key );
		$this->assertArrayHasKey( $name, $emails, 'The email "' . $key . '" is not registered.' );

		return $emails[ $name ];
	}

	/**
	 * Render the account license page for a user.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	protected function render_portal( int $user_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		CustomerPortal::render();

		return (string) ob_get_clean();
	}

	/**
	 * Post a form to the frontend handler like a browser would.
	 *
	 * @param array<string,mixed> $post POST fields.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	protected function front_post( array $post ): array {
		$this->halt_instead_of_exit();
		$_POST                     = wp_slash( $post );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		try {
			CustomerPortal::handle_front_post();
		} catch ( WPDieException $e ) {
			return array(
				'outcome'  => 'die',
				'status'   => (int) $e->getCode(),
				'message'  => $e->getMessage(),
				'location' => '',
			);
		} catch ( WPLC_Test_Halt $e ) {
			return array(
				'outcome'  => $e->kind,
				'status'   => $e->status,
				'message'  => '',
				'location' => $e->location,
			);
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		return array(
			'outcome'  => 'returned',
			'status'   => 0,
			'message'  => '',
			'location' => '',
		);
	}

	/**
	 * Assert that a handler answered with a redirect that carries one notice.
	 *
	 * The notice is in the WooCommerce session when there is one, otherwise
	 * its code travels in the URL.
	 *
	 * @param string              $code   Key of CustomerPortal::notice_messages().
	 * @param array<string,mixed> $result Result of front_post() or run_admin_post().
	 * @return void
	 */
	protected function assertRedirectWithNotice( string $code, array $result ): void {
		$this->assertSame( 'redirect', $result['outcome'], 'Expected a redirect, got: ' . $result['outcome'] . ' ' . $result['message'] );
		$messages = CustomerPortal::notice_messages();
		$this->assertArrayHasKey( $code, $messages );

		$in_url     = false !== strpos( (string) $result['location'], 'wplc_notice=' . $code );
		$in_session = false;
		if ( function_exists( 'wc_get_notices' ) && WC()->session ) {
			foreach ( (array) wc_get_notices() as $notices ) {
				foreach ( (array) $notices as $notice ) {
					if ( is_array( $notice ) && isset( $notice['notice'] ) && $notice['notice'] === $messages[ $code ][1] ) {
						$in_session = true;
					}
				}
			}
		}
		$this->assertTrue( $in_url || $in_session, 'The notice "' . $code . '" was neither queued nor carried in the redirect URL: ' . $result['location'] );
	}

	/**
	 * Extract the first form that contains all needles.
	 *
	 * @param string   $html    Page markup.
	 * @param string[] $needles Substrings the form must contain.
	 * @return string Form markup, or an empty string.
	 */
	protected function find_form( string $html, array $needles ): string {
		if ( ! preg_match_all( '/<form\b.*?<\/form>/s', $html, $forms ) ) {
			return '';
		}
		foreach ( $forms[0] as $form ) {
			$all = true;
			foreach ( $needles as $needle ) {
				if ( false === strpos( $form, $needle ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return $form;
			}
		}
		return '';
	}

	/**
	 * Hidden and other input values of a form, by name.
	 *
	 * @param string $form Form markup.
	 * @return array<string,string>
	 */
	protected function form_fields( string $form ): array {
		$fields = array();
		if ( preg_match_all( '/<input\b[^>]*\bname="([^"]+)"[^>]*\bvalue="([^"]*)"/', $form, $inputs, PREG_SET_ORDER ) ) {
			foreach ( $inputs as $input ) {
				$fields[ $input[1] ] = html_entity_decode( $input[2], ENT_QUOTES );
			}
		}
		return $fields;
	}

	/**
	 * Register the test gateway adapter and give a customer a saved card of it.
	 *
	 * @param int    $user_id Customer.
	 * @param string $last4   Last four digits.
	 * @return int Token ID.
	 */
	protected function create_saved_card( int $user_id, string $last4 = '4242' ): int {
		if ( false === has_filter( 'wplc_recurring_gateway_adapters', array( self::class, 'register_test_adapter' ) ) ) {
			add_filter( 'wplc_recurring_gateway_adapters', array( self::class, 'register_test_adapter' ) );
		}
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 12, false ) );
		$token->set_gateway_id( 'wplc_test_gateway' );
		$token->set_card_type( 'visa' );
		$token->set_last4( $last4 );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $user_id );
		$this->assertGreaterThan( 0, $token->save(), 'Fixture: the payment token could not be saved.' );

		return (int) $token->get_id();
	}

	/**
	 * Filter callback: add the test adapter.
	 *
	 * @param mixed $adapters Registered adapters.
	 * @return array<int,mixed>
	 */
	public static function register_test_adapter( $adapters ): array {
		$adapters   = is_array( $adapters ) ? $adapters : array();
		$adapters[] = new WPLC_Test_Renewal_Adapter();

		return $adapters;
	}

	/**
	 * Audit actions recorded for an object, oldest first.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string[]
	 */
	protected function audit_actions( string $object_type, int $object_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT action FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $object_type, $object_id )
		);
	}
}
