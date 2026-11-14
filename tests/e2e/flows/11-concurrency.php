<?php
/**
 * Flow 11: real concurrency against MySQL/MariaDB.
 *
 * Every scenario sends several requests to the PHP server AT THE SAME TIME (curl_multi; the
 * server runs WPLC_E2E_SERVER_WORKERS worker processes, each with its own database
 * connection). A start barrier in the mu-plugin lets all requests load WordPress first and
 * then enter the plugin code together. Operations that have no HTTP endpoint of their own
 * (Action Scheduler jobs, WooCommerce payment hooks, lifecycle calls) run through the
 * test-only race endpoint of the mu-plugin, inside the server processes.
 *
 * Each scenario is repeated WPLC_E2E_RACE_ROUNDS times (default 3). The assertions are
 * invariants that must hold for EVERY interleaving (exactly one winner, no lost update,
 * audit trail consistent), not one expected order of events.
 *
 * Needs the state of flows 01-05 (products, a published release of voucher-captain).
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t             = e2e_tables();
$rounds        = max( 1, (int) ( getenv( 'WPLC_E2E_RACE_ROUNDS' ) ?: 3 ) );
$workers       = (int) getenv( 'WPLC_E2E_SERVER_WORKERS' );
$simple_id     = (int) e2e_get( 'simple_id' );
$variation_ids = (array) e2e_get( 'variation_ids' );
$customer_id   = e2e_customer( 'rita' );
$server        = 'https://shop.test';
$flow_started  = microtime( true );
$utc           = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};
$codes         = static function ( array $responses ) {
	$codes = array_column( $responses, 'code' );
	sort( $codes );
	return implode( ',', $codes );
};
/*
 * How many requests of a batch really waited at the start barrier. A PHP server worker can
 * accept a second connection while the first request sleeps and handles it afterwards, so a
 * single batch may overlap less than intended. That is not a defect of the plugin; the share
 * over the whole flow is asserted at the end.
 */
$barrier = array( 'batches' => 0, 'requests' => 0, 'waited' => 0, 'weak' => 0 );
$together = static function ( array $responses, string $label ) use ( &$barrier ) {
	$waited = e2e_waited( $responses );
	$barrier['batches']++;
	$barrier['requests'] += count( $responses );
	$barrier['waited']   += $waited;
	if ( $waited < 2 ) {
		$barrier['weak']++;
		e2e_note( $label . ': only ' . $waited . ' of ' . count( $responses ) . ' requests waited at the start barrier' );
	}
};
$max_audit_id  = static function () use ( $wpdb, $t ) {
	return (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$t['audit_log']}" );
};
/*
 * Audit trail of a license since an audit ID: every entry that records a status change must
 * start from the status the previous one ended in, and the last one must be the current status.
 */
$audit_chain_problem = static function ( int $license_id, int $since, string $start_status ) use ( $wpdb, $t ) {
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, action, before_json, after_json FROM {$t['audit_log']} WHERE object_type = 'license' AND object_id = %d AND id > %d ORDER BY id ASC", $license_id, $since ), ARRAY_A );
	$status = $start_status;
	foreach ( $rows as $row ) {
		$before = json_decode( (string) $row['before_json'], true );
		$after  = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $before ) || ! is_array( $after ) || ! isset( $before['status'], $after['status'] ) ) {
			continue;
		}
		if ( $before['status'] !== $status ) {
			return 'audit #' . $row['id'] . ' (' . $row['action'] . ') starts from ' . $before['status'] . ' but the license was ' . $status;
		}
		$status = $after['status'];
	}
	$current = (string) e2e_license( $license_id )['status'];

	return $status === $current ? '' : 'audit trail ends in ' . $status . ', license is ' . $current;
};
/*
 * Entitlement rows must follow the license: none active on a suspended/revoked license,
 * all active (until paid_until) on an active one.
 */
$entitlement_problem = static function ( int $license_id ) {
	$license = e2e_license( $license_id );
	$rows    = e2e_rows( 'entitlements', 'license_id = ' . $license_id );
	foreach ( $rows as $row ) {
		if ( in_array( $license['status'], array( 'suspended', 'revoked' ), true ) && 'active' === $row['status'] ) {
			return 'entitlement ' . $row['entitlement_key'] . ' is active on a ' . $license['status'] . ' license';
		}
		if ( in_array( $license['status'], array( 'active', 'past_due' ), true ) && ( 'active' !== $row['status'] || $row['ends_at'] !== $license['paid_until'] ) ) {
			return 'entitlement ' . $row['entitlement_key'] . ' is ' . $row['status'] . ' until ' . $row['ends_at'] . ' on a ' . $license['status'] . ' license paid until ' . $license['paid_until'];
		}
	}

	return '';
};
$charges_for = static function ( string $renewal_key ) {
	return array_values(
		array_filter(
			wplc_e2e_charge_rows(),
			static function ( $charge ) use ( $renewal_key ) {
				return 0 === strpos( (string) $charge['key'], $renewal_key . ':' );
			}
		)
	);
};
$sign = static function ( string $route, string $body, string $installation_id, string $secret ) {
	$timestamp = (string) time();
	$nonce     = bin2hex( random_bytes( 16 ) );

	return array(
		'Content-Type'           => 'application/json',
		'X-WPLC-Installation-Id' => $installation_id,
		'X-WPLC-Timestamp'       => $timestamp,
		'X-WPLC-Nonce'           => $nonce,
		'X-WPLC-Signature'       => hash_hmac( 'sha256', implode( "\n", array( 'POST', $route, $timestamp, $nonce, hash( 'sha256', $body ) ) ), $secret ),
	);
};
$activation_request = static function ( string $key, string $product_key, string $site_url, string $installation_id, string $secret ) {
	return array(
		'method'  => 'POST',
		'path'    => '/wp-json/wplc/v1/activations',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => (string) wp_json_encode(
			array(
				'license_key'         => $key,
				'product_key'         => $product_key,
				'installation_id'     => $installation_id,
				'installation_secret' => $secret,
				'site_url'            => $site_url,
				'environment'         => 'production',
				'product_version'     => '1.0.0',
			)
		),
	);
};
// The activation endpoint limits attempts per address and key prefix and minute; this flow
// sends more than that from 127.0.0.1 on purpose. TEST SETUP: empty the limiter buckets.
$reset_rate_limits = static function () use ( $wpdb, $t ) {
	$wpdb->query( "DELETE FROM {$t['request_limits']}" );
};
$buy_renewing = static function () use ( $customer_id, $simple_id ) {
	$order = e2e_checkout( $customer_id, array( $simple_id => 1 ), 'wplc_e2e_fake', true );
	$rows  = e2e_order_licenses( (int) $order->get_id() );

	return array( (int) $rows[0]['id'], (int) $order->get_id() );
};

e2e_ok( $workers >= 4, 'the PHP server runs at least 4 worker processes (WPLC_E2E_SERVER_WORKERS = ' . $workers . ')' );
update_option( 'wplc_e2e_fake_mode', 'success' );
update_option( 'wplc_e2e_fake_delay_ms', 0 );
update_option( 'wplc_e2e_no_async_runner', 1, false );
$reset_rate_limits();

// =====================================================================================
// 1. The same signed request sent 8 times at once: exactly one is accepted (nonce replay).
// =====================================================================================
$order   = e2e_create_order( $customer_id, array( $simple_id => 1 ) );
$license = e2e_order_licenses( (int) $order->get_id() )[0];
$inst_id = wp_generate_uuid4();
$secret  = bin2hex( random_bytes( 32 ) );
$single  = e2e_parallel( array( $activation_request( e2e_key( $license ), 'voucher-captain', 'https://race-replay.example', $inst_id, $secret ) ) );
e2e_eq( 200, $single[0]['code'], '[replay] setup: one site activated', substr( $single[0]['body'], 0, 200 ) );
for ( $round = 1; $round <= $rounds; $round++ ) {
	$body      = '{"product_version":"1.0.0"}';
	$headers   = $sign( '/wplc/v1/entitlements/check', $body, $inst_id, $secret );
	$responses = e2e_parallel( array_fill( 0, 8, array( 'method' => 'POST', 'path' => '/wp-json/wplc/v1/entitlements/check', 'headers' => $headers, 'body' => $body ) ) );
	$accepted  = count( array_filter( array_column( $responses, 'code' ), static function ( $code ) {
		return 200 === $code;
	} ) );
	$replayed  = count( array_filter( $responses, static function ( $response ) {
		return 409 === $response['code'] && 'request_replayed' === ( $response['json']['code'] ?? '' );
	} ) );
	e2e_eq( array( 1, 7 ), array( $accepted, $replayed ), "[replay] round {$round}: 8 identical signed requests at once -> exactly 1 accepted, 7 x 409 request_replayed (" . $codes( $responses ) . ')' );
	$together( $responses, "[replay] round {$round}" );
	e2e_eq( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['request_nonces']} WHERE nonce_hash = %s", hash( 'sha256', $headers['X-WPLC-Nonce'] ) ) ), "[replay] round {$round}: the nonce is stored once" );
}

// =====================================================================================
// 2. Six different sites activate one license with a live-site limit of 2 at once.
// =====================================================================================
for ( $round = 1; $round <= $rounds; $round++ ) {
	$reset_rate_limits();
	$order    = e2e_create_order( $customer_id, array( (int) $variation_ids[0] => 1 ) );
	$license  = e2e_order_licenses( (int) $order->get_id() )[0];
	$limit    = \WPLC\Api\V1\ActivationService::site_limit( (int) $license['id'], $license );
	$requests = array();
	for ( $i = 1; $i <= 6; $i++ ) {
		$requests[] = $activation_request( e2e_key( $license ), 'affiliate-captain', "https://race-{$round}-site-{$i}.example", wp_generate_uuid4(), bin2hex( random_bytes( 32 ) ) );
	}
	$responses = e2e_parallel( $requests );
	$ok        = count( array_filter( array_column( $responses, 'code' ), static function ( $code ) {
		return 200 === $code;
	} ) );
	$refused   = count( array_filter( $responses, static function ( $response ) {
		return 409 === $response['code'] && 'site_limit' === ( $response['json']['code'] ?? '' );
	} ) );
	$active    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['activations']} WHERE license_id = %d AND status = 'active' AND environment = 'production'", (int) $license['id'] ) );
	e2e_eq( array( 2, 2, 4, 2 ), array( $limit, $ok, $refused, $active ), "[site limit] round {$round}: limit 2, 6 sites at once -> 2 activated, 4 x 409 site_limit, 2 active rows (" . $codes( $responses ) . ')' );
	e2e_eq( 2, count( e2e_rows( 'audit_log', "action = 'site_activated' AND object_id IN (SELECT id FROM {$t['activations']} WHERE license_id = " . (int) $license['id'] . ')' ) ), "[site limit] round {$round}: 2 site_activated audit entries" );
	$together( $responses, "[site limit] round {$round}" );
}

// =====================================================================================
// 3. One-time download token and one-time stream URL, each used 6 times at once.
// =====================================================================================
$reset_rate_limits();
$order   = e2e_create_order( $customer_id, array( $simple_id => 1 ) );
$license = e2e_order_licenses( (int) $order->get_id() )[0];
delete_option( 'wplc_e2e_client_race_dl' );
e2e_as_site( 'https://race-download.example' );
$client = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_race_dl' );
$result = $client->activate( e2e_key( $license ) );
e2e_ok( is_array( $result ), '[download] setup: site activated' );
for ( $round = 1; $round <= $rounds; $round++ ) {
	$update = $client->check_update();
	if ( ! e2e_ok( is_array( $update ) && ! empty( $update['package_url'] ), "[download] round {$round}: update check hands out a one-time download URL", is_wp_error( $update ) ? $update->get_error_code() : $update ) ) {
		break;
	}
	$path      = substr( (string) $update['package_url'], strlen( $server ) );
	$responses = e2e_parallel( array_fill( 0, 6, array( 'method' => 'GET', 'path' => $path ) ) );
	$winners   = array_values( array_filter( $responses, static function ( $response ) {
		return 302 === $response['code'];
	} ) );
	e2e_eq( '302,404,404,404,404,404', $codes( $responses ), "[download] round {$round}: one download token used 6 times at once -> exactly one redirect, 5 x 404" );
	$together( $responses, "[download] round {$round}" );
	if ( 1 !== count( $winners ) ) {
		continue;
	}
	$stream    = substr( $winners[0]['location'], strlen( $server ) );
	$responses = e2e_parallel( array_fill( 0, 6, array( 'method' => 'GET', 'path' => $stream ) ) );
	$served    = array_values( array_filter( $responses, static function ( $response ) {
		return 200 === $response['code'];
	} ) );
	e2e_eq( '200,404,404,404,404,404', $codes( $responses ), "[download] round {$round}: the one-time stream URL used 6 times at once -> the package is served exactly once" );
	e2e_ok( 1 === count( $served ) && hash( 'sha256', $served[0]['body'] ) === $update['sha256'], "[download] round {$round}: the one stream that was served has the registry checksum" );
}
e2e_as_site( '' );

// =====================================================================================
// 4. Concurrent state changes on one license.
// =====================================================================================
update_option( 'wplc_e2e_fake_delay_ms', 400 );
// 4a. Admin suspends while the automatic renewal charge is in flight (or just before it).
for ( $round = 1; $round <= $rounds; $round++ ) {
	list( $license_id ) = $buy_renewing();
	$renewal_id         = e2e_move_paid_until( $license_id, $utc( time() - HOUR_IN_SECONDS ) );
	$renewal            = e2e_renewal( $renewal_id );
	$since              = $max_audit_id();
	$offset             = 1 === $round % 2 ? 150 : 0; // Odd rounds: during the charge; even rounds: at the same moment.
	$responses          = e2e_race(
		array(
			array( 'process_renewal', array( 'id' => $renewal_id ) ),
			array( 'admin_set_status', array( 'id' => $license_id, 'status' => 'suspended', 'delay_ms' => $offset ) ),
		)
	);
	$license  = e2e_license( $license_id );
	$renewal  = e2e_renewal( $renewal_id );
	$charges  = $charges_for( (string) $renewal['idempotency_key'] );
	$extended = $license['paid_until'] === $renewal['period_end'];
	e2e_ok( count( $charges ) <= 1, "[suspend vs renewal] round {$round}: charged at most once", count( $charges ) );
	e2e_eq( 'suspended', $license['status'], "[suspend vs renewal] round {$round}: the admin suspension is kept" );
	e2e_ok( ( 'paid' === $renewal['status'] ) === $extended, "[suspend vs renewal] round {$round}: renewal row is paid exactly when the license period was extended", array( $renewal['status'], $license['paid_until'], $renewal['period_end'] ) );
	e2e_ok( 0 === count( $charges ) || $extended || 'review_required' === $renewal['status'], "[suspend vs renewal] round {$round}: a charge that could not be booked is flagged for review, never silently lost", array( count( $charges ), $renewal['status'], $license['paid_until'] ) );
	e2e_eq( '', $audit_chain_problem( $license_id, $since, 'active' ), "[suspend vs renewal] round {$round}: audit trail is a consistent chain of status changes" );
	e2e_eq( '', $entitlement_problem( $license_id ), "[suspend vs renewal] round {$round}: entitlements follow the license status" );
	e2e_note( "[suspend vs renewal] round {$round} (suspend +{$offset} ms): charges " . count( $charges ) . ', renewal ' . $renewal['status'] . ', paid_until ' . $license['paid_until'] );
}

// 4b. The customer cancels at period end while the renewal charge is in flight.
for ( $round = 1; $round <= $rounds; $round++ ) {
	list( $license_id ) = $buy_renewing();
	$renewal_id         = e2e_move_paid_until( $license_id, $utc( time() - HOUR_IN_SECONDS ) );
	$offset             = 1 === $round % 2 ? 150 : 0;
	e2e_race(
		array(
			array( 'process_renewal', array( 'id' => $renewal_id ) ),
			array( 'schedule_cancellation', array( 'id' => $license_id, 'delay_ms' => $offset ) ),
		)
	);
	$license = e2e_license( $license_id );
	$renewal = e2e_renewal( $renewal_id );
	$charges = $charges_for( (string) $renewal['idempotency_key'] );
	e2e_eq( '1', (string) $license['cancel_at_period_end'], "[cancel vs renewal] round {$round}: the customer's cancellation is not lost" );
	e2e_ok( count( $charges ) <= 1 && ( ( 1 === count( $charges ) ) === ( 'paid' === $renewal['status'] && $license['paid_until'] === $renewal['period_end'] ) ), "[cancel vs renewal] round {$round}: charged at most once, and a charge is booked (renewal paid, period extended)", array( count( $charges ), $renewal['status'], $license['paid_until'], $renewal['period_end'] ) );
	// The next period must never be charged: run every open renewal row of the license now.
	$before_next = count( wplc_e2e_charge_rows() );
	foreach ( e2e_rows( 'renewals', 'license_id = ' . $license_id . " AND status = 'scheduled'" ) as $next ) {
		\WPLC\Billing\RenewalProcessor::process( (int) $next['id'] );
	}
	e2e_eq( array( $before_next, 0 ), array( count( wplc_e2e_charge_rows() ), count( e2e_rows( 'renewals', 'license_id = ' . $license_id . " AND status = 'scheduled'" ) ) ), "[cancel vs renewal] round {$round}: the next period of the cancelled license is not charged (its renewal is cancelled when it runs)" );
	e2e_note( "[cancel vs renewal] round {$round} (cancel +{$offset} ms): charges " . count( $charges ) . ', renewal ' . $renewal['status'] . ', cancel_at_period_end ' . $license['cancel_at_period_end'] );
}

// 4c. The original order is refunded in full while the renewal charge is in flight.
for ( $round = 1; $round <= $rounds; $round++ ) {
	list( $license_id, $order_id ) = $buy_renewing();
	$renewal_id                    = e2e_move_paid_until( $license_id, $utc( time() - HOUR_IN_SECONDS ) );
	$since                         = $max_audit_id();
	$offset                        = 1 === $round % 2 ? 150 : 0;
	e2e_race(
		array(
			array( 'process_renewal', array( 'id' => $renewal_id ) ),
			array( 'full_refund', array( 'id' => $order_id, 'delay_ms' => $offset ) ),
		)
	);
	$license  = e2e_license( $license_id );
	$renewal  = e2e_renewal( $renewal_id );
	$charges  = $charges_for( (string) $renewal['idempotency_key'] );
	$extended = $license['paid_until'] === $renewal['period_end'];
	e2e_eq( array( 'revoked', '0' ), array( $license['status'], (string) $license['renewal_enabled'] ), "[refund vs renewal] round {$round}: the license is revoked and renews no more" );
	e2e_ok( count( $charges ) <= 1 && ( 'paid' === $renewal['status'] ) === $extended, "[refund vs renewal] round {$round}: charged at most once; renewal paid exactly when the period was extended", array( count( $charges ), $renewal['status'] ) );
	e2e_ok( 0 === count( $charges ) || $extended || 'review_required' === $renewal['status'], "[refund vs renewal] round {$round}: a charge for the revoked license is flagged for review", array( count( $charges ), $renewal['status'] ) );
	e2e_eq( '', $audit_chain_problem( $license_id, $since, 'active' ), "[refund vs renewal] round {$round}: audit trail is a consistent chain" );
	e2e_eq( '', $entitlement_problem( $license_id ), "[refund vs renewal] round {$round}: entitlements follow the license status" );
	e2e_note( "[refund vs renewal] round {$round} (refund +{$offset} ms): charges " . count( $charges ) . ', renewal ' . $renewal['status'] );
}
update_option( 'wplc_e2e_fake_delay_ms', 0 );

// 4d. Six lifecycle operations on one license at the same moment.
for ( $round = 1; $round <= $rounds; $round++ ) {
	$order      = e2e_create_order( $customer_id, array( $simple_id => 1 ) );
	$license_id = (int) e2e_order_licenses( (int) $order->get_id() )[0]['id'];
	$since      = $max_audit_id();
	$extend_to  = $utc( time() + 400 * DAY_IN_SECONDS );
	$responses  = e2e_race(
		array(
			array( 'admin_set_status', array( 'id' => $license_id, 'status' => 'suspended' ) ),
			array( 'chargeback', array( 'id' => $license_id ) ),
			array( 'mark_past_due', array( 'id' => $license_id ) ),
			array( 'admin_extend', array( 'id' => $license_id, 'paid_until' => $extend_to ) ),
			array( 'admin_set_status', array( 'id' => $license_id, 'status' => 'active' ) ),
			array( 'schedule_cancellation', array( 'id' => $license_id ) ),
		)
	);
	$license = e2e_license( $license_id );
	e2e_ok( in_array( $license['status'], array( 'active', 'past_due', 'suspended' ), true ), "[lifecycle] round {$round}: final status is a valid state ({$license['status']})" );
	e2e_eq( '', $audit_chain_problem( $license_id, $since, 'active' ), "[lifecycle] round {$round}: every recorded status change starts where the previous one ended" );
	e2e_eq( '', $entitlement_problem( $license_id ), "[lifecycle] round {$round}: entitlements follow the license status" );
	$together( $responses, "[lifecycle] round {$round}" );
}

// =====================================================================================
// 5. Renewals run twice at once.
// =====================================================================================
// 5a. The same automatic renewal job runs 4 times at once (Action Scheduler double run).
update_option( 'wplc_e2e_fake_delay_ms', 300 );
list( $license_id ) = $buy_renewing();
for ( $round = 1; $round <= $rounds; $round++ ) {
	$renewal_id = e2e_move_paid_until( $license_id, $utc( time() - HOUR_IN_SECONDS ) );
	$renewal    = e2e_renewal( $renewal_id );
	$action_ids = as_get_scheduled_actions( array( 'hook' => 'wplc_process_renewal', 'args' => array( $renewal_id ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
	$action_id  = (int) reset( $action_ids );
	$since      = $max_audit_id();
	$responses  = e2e_race(
		array(
			array( 'run_action', array( 'id' => $action_id ) ),
			array( 'run_action', array( 'id' => $action_id ) ),
			array( 'process_renewal', array( 'id' => $renewal_id ) ),
			array( 'process_renewal', array( 'id' => $renewal_id ) ),
		)
	);
	$license = e2e_license( $license_id );
	$renewal = e2e_renewal( $renewal_id );
	$charges = $charges_for( (string) $renewal['idempotency_key'] );
	e2e_ok( $action_id > 0, "[double run] round {$round}: the renewal job is queued" );
	e2e_eq( 1, count( $charges ), "[double run] round {$round}: 4 concurrent runs of one renewal -> charged exactly once" );
	e2e_eq( array( 'paid', 'active', $renewal['period_end'] ), array( $renewal['status'], $license['status'], $license['paid_until'] ), "[double run] round {$round}: renewal paid, license active, extended by exactly one period" );
	e2e_eq( 1, count( e2e_rows( 'audit_log', "action = 'renewal_succeeded' AND object_type = 'license' AND object_id = {$license_id} AND id > {$since}" ) ), "[double run] round {$round}: one renewal_succeeded audit entry" );
	e2e_eq( 1, count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'meta_key' => '_wplc_renewal_id', 'meta_value' => $renewal_id ) ) ), "[double run] round {$round}: one renewal order" );
	$together( $responses, "[double run] round {$round}" );
}
update_option( 'wplc_e2e_fake_delay_ms', 0 );

// 5b. A manual renewal order is reported paid by several hooks at once.
$order      = e2e_create_order( $customer_id, array( $simple_id => 1 ) );
$license_id = (int) e2e_order_licenses( (int) $order->get_id() )[0]['id'];
for ( $round = 1; $round <= $rounds; $round++ ) {
	e2e_move_paid_until( $license_id, $utc( time() + 10 * DAY_IN_SECONDS ) );
	$before        = e2e_license( $license_id );
	$renewal_order = \WPLC\Billing\ManualRenewal::start( $license_id, $customer_id );
	if ( ! e2e_ok( $renewal_order instanceof WC_Order, "[manual paid twice] round {$round}: renewal order created", is_wp_error( $renewal_order ) ? $renewal_order->get_error_code() : null ) ) {
		break;
	}
	$renewal_id = (int) $renewal_order->get_meta( '_wplc_renewal_id', true );
	$since      = $max_audit_id();
	$responses  = e2e_race(
		array(
			array( 'payment_complete', array( 'id' => (int) $renewal_order->get_id() ) ),
			array( 'payment_complete', array( 'id' => (int) $renewal_order->get_id() ) ),
			array( 'order_status', array( 'id' => (int) $renewal_order->get_id(), 'status' => 'completed' ) ),
			array( 'reconcile_paid_order', array( 'id' => (int) $renewal_order->get_id(), 'delay_ms' => 50 ) ),
			array( 'reconcile_paid_order', array( 'id' => (int) $renewal_order->get_id(), 'delay_ms' => 100 ) ),
		)
	);
	// A hook that found the renewal lock taken queued an async reconciliation; run it as the queue would.
	e2e_run_actions( 'wplc_reconcile_paid_renewal_order' );
	$license  = e2e_license( $license_id );
	$renewal  = e2e_renewal( $renewal_id );
	$expected = \WPLC\Billing\ManualRenewal::next_paid_until( (string) $before['paid_until'], (string) $before['billing_interval'], time() );
	e2e_eq( array( 'paid', $expected, $expected ), array( $renewal['status'], $renewal['period_end'], $license['paid_until'] ), "[manual paid twice] round {$round}: renewal paid once, license extended by exactly one period" );
	e2e_eq( 1, count( e2e_rows( 'audit_log', "action = 'manual_renewal_paid' AND object_id = {$renewal_id} AND id > {$since}" ) ), "[manual paid twice] round {$round}: one manual_renewal_paid audit entry" );
	e2e_eq( 1, count( e2e_rows( 'audit_log', "action = 'renewal_succeeded' AND object_type = 'license' AND object_id = {$license_id} AND id > {$since}" ) ), "[manual paid twice] round {$round}: one renewal_succeeded audit entry" );
	$together( $responses, "[manual paid twice] round {$round}" );
}

// =====================================================================================
// 6. A paid order is reported by several WooCommerce hooks at once: one license per unit.
// =====================================================================================
for ( $round = 1; $round <= $rounds; $round++ ) {
	$order     = e2e_create_order( $customer_id, array( $simple_id => 3 ), array( 'pay' => false ) );
	$order_id  = (int) $order->get_id();
	$responses = e2e_race(
		array(
			array( 'payment_complete', array( 'id' => $order_id ) ),
			array( 'payment_complete', array( 'id' => $order_id ) ),
			array( 'order_status', array( 'id' => $order_id, 'status' => 'processing' ) ),
			array( 'order_status', array( 'id' => $order_id, 'status' => 'completed' ) ),
			array( 'issue_order', array( 'id' => $order_id, 'delay_ms' => 30 ) ),
			array( 'issue_order', array( 'id' => $order_id, 'delay_ms' => 60 ) ),
		)
	);
	$licenses = e2e_order_licenses( $order_id );
	$order    = wc_get_order( $order_id );
	$items    = $order->get_items( 'line_item' );
	$item     = reset( $items );
	$meta_ids = array_map( 'intval', (array) $item->get_meta( '_wplc_license_ids', true ) );
	sort( $meta_ids );
	e2e_eq( array( 0, 1, 2 ), array_map( 'intval', array_column( $licenses, 'unit_index' ) ), "[order paid] round {$round}: quantity 3, six concurrent paid hooks -> exactly 3 licenses (units 0, 1, 2)" );
	e2e_eq( array_map( 'intval', array_column( $licenses, 'id' ) ), $meta_ids, "[order paid] round {$round}: the order item lists all 3 license IDs" );
	e2e_eq( array( 'active', 'active', 'active' ), array_column( $licenses, 'status' ), "[order paid] round {$round}: all 3 licenses are active" );
	$notes = array_filter( wc_get_order_notes( array( 'order_id' => $order_id ) ), static function ( $note ) {
		return false !== strpos( $note->content, 'issued license' );
	} );
	e2e_eq( 3, count( $notes ), "[order paid] round {$round}: 3 'issued license' order notes" );
	$together( $responses, "[order paid] round {$round}" );
}

e2e_ok(
	$barrier['requests'] > 0 && $barrier['waited'] >= 0.6 * $barrier['requests'] && $barrier['weak'] <= max( 1, (int) floor( $barrier['batches'] / 4 ) ),
	sprintf( 'requests really ran concurrently: %d of %d waited at the start barrier; %d of %d batches overlapped weakly', $barrier['waited'], $barrier['requests'], $barrier['weak'], $barrier['batches'] )
);
delete_option( 'wplc_e2e_no_async_runner' );
delete_option( 'wplc_e2e_fake_delay_ms' );
$elapsed = microtime( true ) - $flow_started;
e2e_note( sprintf( 'flow 11: %d round(s) per scenario in %.1f s', $rounds, $elapsed ) );
