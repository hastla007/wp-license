<?php
/**
 * Shared helpers for the e2e flow scripts (run through `wp eval-file`).
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'e2e_ok' ) ) {
	return;
}

/**
 * Record one assertion.
 *
 * @param mixed  $condition Truthy = pass.
 * @param string $label What was asserted.
 * @param mixed  $detail Extra context printed on failure.
 * @return bool
 */
function e2e_ok( $condition, string $label, $detail = null ): bool {
	$flow   = getenv( 'WPLC_E2E_FLOW' ) ? (string) getenv( 'WPLC_E2E_FLOW' ) : 'adhoc';
	$passed = (bool) $condition;
	$line   = ( $passed ? 'PASS' : 'FAIL' ) . '  [' . $flow . '] ' . $label;
	if ( ! $passed && null !== $detail ) {
		$line .= "\n        got: " . ( is_scalar( $detail ) ? (string) $detail : wp_json_encode( $detail ) );
	}
	echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$results = getenv( 'WPLC_E2E_RESULTS' );
	if ( $results ) {
		file_put_contents( $results, $flow . "\t" . ( $passed ? 'PASS' : 'FAIL' ) . "\t" . str_replace( array( "\t", "\n" ), ' ', $label ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return $passed;
}

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $label Label.
 * @return bool
 */
function e2e_eq( $expected, $actual, string $label ): bool {
	return e2e_ok( $expected === $actual, $label, array( 'expected' => $expected, 'actual' => $actual ) );
}

/**
 * Print a note that is not an assertion.
 *
 * @param string $text Text.
 * @return void
 */
function e2e_note( string $text ): void {
	echo '      ' . $text . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Read state shared between flows.
 *
 * @param string $key Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function e2e_get( string $key, $default = null ) {
	$state = get_option( 'wplc_e2e_state', array() );

	return is_array( $state ) && array_key_exists( $key, $state ) ? $state[ $key ] : $default;
}

/**
 * Store state shared between flows.
 *
 * @param string $key Key.
 * @param mixed  $value Value.
 * @return void
 */
function e2e_set( string $key, $value ): void {
	$state         = get_option( 'wplc_e2e_state', array() );
	$state         = is_array( $state ) ? $state : array();
	$state[ $key ] = $value;
	update_option( 'wplc_e2e_state', $state, false );
}

/**
 * WPLC table names.
 *
 * @return array<string,string>
 */
function e2e_tables(): array {
	return \WPLC\Infrastructure\Database\Schema::table_names();
}

/**
 * Fetch one license row.
 *
 * @param int $license_id License ID.
 * @return array<string,mixed>|null
 */
function e2e_license( int $license_id ): ?array {
	global $wpdb;
	$t   = e2e_tables();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['licenses']} WHERE id = %d", $license_id ), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Fetch rows of a WPLC table.
 *
 * @param string $table Logical table name.
 * @param string $where SQL after WHERE (trusted test input).
 * @return array<int,array<string,mixed>>
 */
function e2e_rows( string $table, string $where = '1=1' ): array {
	global $wpdb;
	$t = e2e_tables();

	return (array) $wpdb->get_results( "SELECT * FROM {$t[ $table ]} WHERE {$where}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Become a user in this process and return cookies that authenticate the same
 * session against the HTTP server (nonces created here are valid there).
 *
 * @param int $user_id User ID (0 = log out).
 * @return array<int,\WP_Http_Cookie>
 */
function e2e_login( int $user_id ): array {
	unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ], $_COOKIE[ AUTH_COOKIE ] );
	wp_set_current_user( $user_id );
	if ( ! $user_id ) {
		return array();
	}
	$expiration = time() + DAY_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	$secure     = wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token );

	$_COOKIE[ LOGGED_IN_COOKIE ]   = $logged_in;
	$_COOKIE[ SECURE_AUTH_COOKIE ] = $secure;

	return array(
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in ) ),
		new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => $secure ) ),
	);
}

/**
 * Real HTTP request against the local server (never follows redirects).
 *
 * @param string              $method Method.
 * @param string              $url Absolute https://shop.test URL.
 * @param array<string,mixed> $args wp_remote_request args (body, headers, cookies).
 * @return array{code:int,headers:array<string,mixed>,body:string,location:string}
 */
function e2e_http( string $method, string $url, array $args = array() ): array {
	$args['method']      = $method;
	$args['timeout']     = 60;
	$args['redirection'] = 0;
	$response            = wp_remote_request( $url, $args );
	// The server process changed the database: drop this process's object cache.
	wp_cache_flush();
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'headers' => array(), 'body' => $response->get_error_message(), 'location' => '' );
	}
	$headers = wp_remote_retrieve_headers( $response );
	$headers = is_object( $headers ) ? $headers->getAll() : (array) $headers;

	$location = isset( $headers['location'] ) ? (string) ( is_array( $headers['location'] ) ? end( $headers['location'] ) : $headers['location'] ) : '';
	if ( '' !== $location && '/' === $location[0] ) {
		// A relative Location (e.g. built from the form's _wp_http_referer) is resolved like a browser does.
		$location = 'https://shop.test' . $location;
	}

	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'headers'  => $headers,
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'location' => $location,
	);
}

/**
 * Multipart body with one file field (wp_remote_request has no file upload helper).
 *
 * @param array<string,string> $fields Plain fields.
 * @param string               $file_field File field name.
 * @param string               $filename File name.
 * @param string               $content File content.
 * @param string               $mime MIME type.
 * @return array{headers:array<string,string>,body:string}
 */
function e2e_multipart( array $fields, string $file_field, string $filename, string $content, string $mime ): array {
	$boundary = '----wplce2e' . wp_generate_password( 16, false );
	$body     = '';
	foreach ( $fields as $name => $value ) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
	}
	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$file_field}\"; filename=\"{$filename}\"\r\nContent-Type: {$mime}\r\n\r\n{$content}\r\n--{$boundary}--\r\n";

	return array(
		'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
		'body'    => $body,
	);
}

/**
 * Run pending Action Scheduler actions of a hook now, regardless of their date.
 *
 * @param string $hook Hook name.
 * @return int Number of actions executed.
 */
function e2e_run_actions( string $hook ): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return 0;
	}
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
	$runner = ActionScheduler::runner();
	foreach ( $ids as $id ) {
		$runner->process_action( $id, 'e2e' );
	}

	return count( $ids );
}

/**
 * Pending Action Scheduler actions of a hook.
 *
 * @param string     $hook Hook.
 * @param array|null $args Exact args or null for any.
 * @return array<int,object>
 */
function e2e_pending_actions( string $hook, ?array $args = null ): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return array();
	}
	$query = array(
		'hook'     => $hook,
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 50,
	);
	if ( null !== $args ) {
		$query['args'] = $args;
	}

	return as_get_scheduled_actions( $query );
}

/**
 * Create a paid order for a customer through WooCommerce CRUD.
 *
 * @param int                 $customer_id Customer.
 * @param array<int,int>      $items Product/variation ID => quantity.
 * @param array<string,mixed> $options payment_method, meta (array), pay (bool).
 * @return \WC_Order
 */
function e2e_create_order( int $customer_id, array $items, array $options = array() ) {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	foreach ( $items as $product_id => $quantity ) {
		$order->add_product( wc_get_product( $product_id ), $quantity );
	}
	$customer = new WC_Customer( $customer_id );
	$order->set_address( $customer->get_billing(), 'billing' );
	$order->set_payment_method( $options['payment_method'] ?? 'bacs' );
	foreach ( (array) ( $options['meta'] ?? array() ) as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	$order->calculate_totals();
	$order->save();
	if ( ! isset( $options['pay'] ) || $options['pay'] ) {
		$order->payment_complete( 'e2e-' . $order->get_id() );
	}

	return wc_get_order( $order->get_id() );
}

/**
 * Licenses issued for an order.
 *
 * @param int $order_id Order ID.
 * @return array<int,array<string,mixed>>
 */
function e2e_order_licenses( int $order_id ): array {
	return e2e_rows( 'licenses', 'source_order_id = ' . (int) $order_id . ' ORDER BY id ASC' );
}

/**
 * The full license key of a license (derived, never stored).
 *
 * @param array<string,mixed> $license License row.
 * @return string
 */
function e2e_key( array $license ): string {
	return \WPLC\License\LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] );
}

/**
 * Hidden/text fields of the first HTML form that contains all given needles.
 * Lets a flow submit a form exactly as the page rendered it (action, nonce, IDs).
 *
 * @param string   $html Page HTML.
 * @param string[] $needles Strings the form markup must contain.
 * @return array<string,string>|null Field name => value, plus '__action' with the form action URL.
 */
function e2e_form( string $html, array $needles ): ?array {
	if ( ! preg_match_all( '/<form\b[^>]*>.*?<\/form>/s', $html, $forms ) ) {
		return null;
	}
	foreach ( $forms[0] as $form ) {
		foreach ( $needles as $needle ) {
			if ( false === strpos( $form, $needle ) ) {
				continue 2;
			}
		}
		$fields = array();
		if ( preg_match( '/<form\b[^>]*\baction=["\']([^"\']*)["\']/', $form, $action ) ) {
			$fields['__action'] = html_entity_decode( $action[1] );
		}
		if ( preg_match_all( '/<input\b[^>]*>/', $form, $inputs ) ) {
			foreach ( $inputs[0] as $input ) {
				if ( preg_match( '/\bname=["\']([^"\']+)["\']/', $input, $name ) && ! preg_match( '/\btype=["\'](submit|file|checkbox|radio)["\']/', $input ) ) {
					$fields[ $name[1] ] = preg_match( '/\bvalue=["\']([^"\']*)["\']/', $input, $value ) ? html_entity_decode( $value[1] ) : '';
				}
			}
		}

		return $fields;
	}

	return null;
}

/**
 * POST a form returned by e2e_form().
 *
 * @param array<string,string> $fields Fields incl. '__action'.
 * @param array<int,mixed>     $cookies Cookies.
 * @param array<string,string> $extra Extra/overriding fields.
 * @return array{code:int,headers:array<string,mixed>,body:string,location:string}
 */
function e2e_submit( array $fields, array $cookies, array $extra = array() ): array {
	$action = $fields['__action'] ?? admin_url( 'admin-post.php' );
	unset( $fields['__action'] );

	return e2e_http( 'POST', $action, array( 'cookies' => $cookies, 'body' => array_merge( $fields, $extra ) ) );
}

/**
 * Create (or find) a customer with a German billing address.
 *
 * @param string $login Login.
 * @return int User ID.
 */
function e2e_customer( string $login ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$customer = new WC_Customer();
	$customer->set_username( $login );
	$customer->set_email( $login . '@customer.test' );
	$customer->set_password( 'customer-e2e-pass' );
	$customer->set_first_name( ucfirst( $login ) );
	$customer->set_last_name( 'Tester' );
	$customer->set_billing_first_name( ucfirst( $login ) );
	$customer->set_billing_last_name( 'Tester' );
	$customer->set_billing_email( $login . '@customer.test' );
	$customer->set_billing_address_1( 'Teststr. 1' );
	$customer->set_billing_city( 'Berlin' );
	$customer->set_billing_postcode( '10115' );
	$customer->set_billing_country( 'DE' );

	return (int) $customer->save();
}

/**
 * The customer's saved card of the fake gateway (created on first use).
 *
 * @param int $customer_id Customer.
 * @return int Token ID.
 */
function e2e_fake_card( int $customer_id ): int {
	foreach ( WC_Payment_Tokens::get_customer_tokens( $customer_id, 'wplc_e2e_fake' ) as $token ) {
		return (int) $token->get_id();
	}
	$token = new WC_Payment_Token_CC();
	$token->set_token( 'e2e_card_' . $customer_id );
	$token->set_gateway_id( 'wplc_e2e_fake' );
	$token->set_card_type( 'visa' );
	$token->set_last4( '4242' );
	$token->set_expiry_month( '12' );
	$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 3 ) );
	$token->set_user_id( $customer_id );
	$token->set_default( true );
	$token->save();

	return (int) $token->get_id();
}

/**
 * Buy through the WooCommerce checkout object as a logged-in customer.
 *
 * Runs the real `woocommerce_checkout_create_order` hooks (renewal consent) and pays
 * with the given gateway. With $consent the "renew automatically" box is ticked.
 *
 * @param int            $customer_id Customer.
 * @param array<int,int> $items Product/variation ID => quantity.
 * @param string         $gateway Gateway ID.
 * @param bool           $consent Tick the automatic renewal consent.
 * @return \WC_Order
 */
function e2e_checkout( int $customer_id, array $items, string $gateway = 'wplc_e2e_fake', bool $consent = true ) {
	wp_set_current_user( $customer_id );
	wc_load_cart();
	$customer      = new WC_Customer( $customer_id, true );
	WC()->customer = $customer;
	WC()->cart->empty_cart();
	foreach ( $items as $product_id => $quantity ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			WC()->cart->add_to_cart( $product->get_parent_id(), $quantity, $product_id, $product->get_variation_attributes() );
		} else {
			WC()->cart->add_to_cart( $product_id, $quantity );
		}
	}
	WC()->cart->calculate_totals();
	if ( 'wplc_e2e_fake' === $gateway ) {
		e2e_fake_card( $customer_id );
	}
	unset( $_POST['wplc_recurring_consent'] );
	if ( $consent ) {
		$_POST['wplc_recurring_consent'] = '1';
	}
	$data = array(
		'payment_method' => $gateway,
		'order_comments' => '',
	);
	foreach ( $customer->get_billing() as $field => $value ) {
		$data[ 'billing_' . $field ] = $value;
	}
	$order_id = WC()->checkout()->create_order( $data );
	unset( $_POST['wplc_recurring_consent'] );
	if ( is_wp_error( $order_id ) ) {
		throw new RuntimeException( 'checkout failed: ' . $order_id->get_error_message() );
	}
	$order = wc_get_order( $order_id );
	// WC_Checkout::process_checkout() fires this right after create_order().
	do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );
	$order->payment_complete( 'e2e-' . $order_id );
	WC()->cart->empty_cart();
	wp_set_current_user( 0 );

	return wc_get_order( $order_id );
}

/**
 * Latest audit actions for an object, newest first.
 *
 * @param string $object_type Object type.
 * @param int    $object_id Object ID.
 * @return string[]
 */
function e2e_audit_actions( string $object_type, int $object_id ): array {
	return array_column( e2e_rows( 'audit_log', "object_type = '" . esc_sql( $object_type ) . "' AND object_id = " . $object_id . ' ORDER BY id DESC' ), 'action' );
}

/**
 * TIME SIMULATION: move the end of a license's paid period by editing the e2e database.
 *
 * Sets paid_until/next_payment_at and the entitlement end dates, drops the license's
 * unpaid renewal rows and, for a renewal-enabled license, lets the plugin schedule the
 * renewal for the new period (so period, grace end and idempotency key are consistent).
 *
 * @param int    $license_id License ID.
 * @param string $paid_until New paid_until, UTC 'Y-m-d H:i:s'.
 * @return int Renewal row ID (0 when the license does not renew automatically).
 */
function e2e_move_paid_until( int $license_id, string $paid_until ): int {
	global $wpdb;
	$t = e2e_tables();
	$wpdb->query( $wpdb->prepare( "UPDATE {$t['licenses']} SET paid_until = %s, next_payment_at = IF( renewal_enabled = 1, %s, NULL ) WHERE id = %d", $paid_until, $paid_until, $license_id ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$t['entitlements']} SET ends_at = %s WHERE license_id = %d", $paid_until, $license_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['renewals']} WHERE license_id = %d AND status <> 'paid'", $license_id ) );

	return \WPLC\Billing\RenewalScheduler::schedule_next( $license_id );
}

/**
 * TIME SIMULATION: move an open renewal and its license into the past by a number of days
 * (period start, period end, grace end, retry time, idempotency key, license paid_until).
 *
 * @param int $renewal_id Renewal ID.
 * @param int $days Days to move back.
 * @return void
 */
function e2e_age_renewal( int $renewal_id, int $days ): void {
	global $wpdb;
	$t       = e2e_tables();
	$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A );
	$shift   = static function ( $value ) use ( $days ) {
		return null === $value ? null : gmdate( 'Y-m-d H:i:s', strtotime( $value . ' UTC' ) - $days * DAY_IN_SECONDS );
	};
	$start = $shift( $renewal['period_start'] );
	$wpdb->update(
		$t['renewals'],
		array(
			'period_start'    => $start,
			'period_end'      => $shift( $renewal['period_end'] ),
			'grace_ends_at'   => $shift( $renewal['grace_ends_at'] ),
			'retry_at'        => $shift( $renewal['retry_at'] ),
			'idempotency_key' => \WPLC\Billing\RenewalScheduler::idempotency_key( (int) $renewal['license_id'], $start ),
		),
		array( 'id' => $renewal_id )
	);
	$wpdb->query( $wpdb->prepare( "UPDATE {$t['licenses']} SET paid_until = %s, next_payment_at = IF( renewal_enabled = 1, %s, NULL ) WHERE id = %d", $start, $start, (int) $renewal['license_id'] ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$t['entitlements']} SET ends_at = %s WHERE license_id = %d", $start, (int) $renewal['license_id'] ) );
}

/**
 * Run the pending Action Scheduler action of a hook with exactly these args, through the
 * Action Scheduler runner (as the queue would), regardless of its scheduled date.
 *
 * @param string $hook Hook.
 * @param array  $args Args.
 * @return bool True when a pending action was found and executed.
 */
function e2e_run_action( string $hook, array $args ): bool {
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'args'     => $args,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		),
		'ids'
	);
	if ( ! $ids ) {
		return false;
	}
	ActionScheduler::runner()->process_action( (int) reset( $ids ), 'e2e' );

	return true;
}

/**
 * One renewal row.
 *
 * @param int $renewal_id Renewal ID.
 * @return array<string,mixed>
 */
function e2e_renewal( int $renewal_id ): array {
	$rows = e2e_rows( 'renewals', 'id = ' . $renewal_id );

	return $rows ? $rows[0] : array();
}

/**
 * Charges recorded by the fake gateway adapter.
 *
 * @return array<int,array<string,mixed>>
 */
function e2e_charges(): array {
	wp_cache_delete( 'wplc_e2e_fake_charges', 'options' );

	return (array) get_option( 'wplc_e2e_fake_charges', array() );
}

/**
 * Captured mails whose subject contains a text.
 *
 * @param string $needle Subject part.
 * @return array<int,array<string,mixed>>
 */
function e2e_mails_with_subject( string $needle ): array {
	return array_values(
		array_filter(
			wplc_e2e_mails(),
			static function ( $mail ) use ( $needle ) {
				return false !== strpos( (string) $mail['subject'], $needle );
			}
		)
	);
}

/**
 * Filter home_url() so that this process acts as a customer site for the LicenseClient.
 * Call with '' to act as the shop again.
 *
 * @param string $home Site URL.
 * @return void
 */
function e2e_as_site( string $home ): void {
	static $hooked = false;
	$GLOBALS['wplc_e2e_home'] = $home;
	if ( $hooked ) {
		return;
	}
	$hooked = true;
	add_filter(
		'home_url',
		static function ( $url, $path ) {
			return '' !== $GLOBALS['wplc_e2e_home'] && ( '' === $path || '/' === $path ) ? $GLOBALS['wplc_e2e_home'] : $url;
		},
		10,
		2
	);
}
