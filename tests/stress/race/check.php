<?php
/**
 * Verdict of one race scenario (B-196): reads the fixture, the worker lines and the database
 * after the run and checks what must hold (table in tests/stress/README.md).
 *
 * usage: php check.php <fixture.json> <worker-out.json>... ; charges.log next to this file is read.
 * Prints "PASS <scenario>" or one "FAIL <scenario>: <reason>" line per broken rule.
 * Exit code: 0 pass, 1 fail, 2 harness error.
 *
 * @package WPLC
 */

putenv( 'QA_WORKER=1' );
require __DIR__ . '/boot.php';
global $wpdb;
$t  = \WPLC\Infrastructure\Database\Schema::table_names();
$fx = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $fx ) || empty( $fx['scenario'] ) ) {
	echo "FAIL ?: fixture unreadable\n";
	exit( 2 );
}
$sc   = (string) $fx['scenario'];
$outs = array();
foreach ( array_slice( $argv, 2 ) as $file ) {
	$line   = json_decode( trim( (string) file_get_contents( $file ) ), true );
	$outs[] = is_array( $line ) ? $line : array( 'unreadable' => basename( $file ) );
}
$charges = is_file( __DIR__ . '/charges.log' ) ? count( array_filter( file( __DIR__ . '/charges.log' ) ?: array() ) ) : 0;
$fails   = array();
$rule    = static function ( bool $ok, string $reason ) use ( &$fails ) {
	if ( ! $ok ) {
		$fails[] = $reason;
	}
};
$col     = static function ( string $key ) use ( &$outs ): array {
	return array_map(
		static function ( $o ) use ( $key ) {
			return $o[ $key ] ?? null;
		},
		$outs
	);
};
$license = static function () use ( $wpdb, $t, $fx ): array {
	$id = (int) ( $fx['license_id'] ?? ( $fx['license']['id'] ?? 0 ) );
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['licenses']} WHERE id = %d", $id ), ARRAY_A );
};
$active_count = static function ( string $extra = '' ) use ( $wpdb, $t, $fx ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['activations']} WHERE license_id = %d AND status = 'active' $extra", (int) $fx['license']['id'] ) ); // phpcs:ignore
};
$days     = static function ( $datetime ): float {
	return ( (int) strtotime( (string) $datetime . ' UTC' ) - time() ) / DAY_IN_SECONDS;
};
$statuses = $col( 'status' );
$codes    = $col( 'code' );
$ok_count = count(
	array_filter(
		$statuses,
		static function ( $s ) {
			return null !== $s && (int) $s >= 200 && (int) $s < 300;
		}
	)
);

// Every worker must report, and no PHP notice from src/ may occur.
foreach ( $outs as $index => $o ) {
	$rule( ! isset( $o['unreadable'] ) && array_key_exists( 'errors', $o ), "worker $index gave no result line" );
	$rule( empty( $o['errors'] ), "worker $index: notices from src/: " . implode( '; ', (array) ( $o['errors'] ?? array() ) ) );
	$rule( null === ( $o['status'] ?? null ) || (int) $o['status'] < 500, "worker $index answered HTTP " . ( $o['status'] ?? '' ) );
}

switch ( $sc ) {
	case 'activate_same':
		$rule( count( $outs ) === $ok_count, 'not every process got 200' );
		$rule( 1 === count( array_unique( array_map( 'strval', $col( 'act' ) ) ) ) && null !== $outs[0]['act'], 'processes got different activation IDs' );
		$rule( 1 === $active_count(), 'active activations: ' . $active_count() . ', expected 1' );
		break;
	case 'activate_diff':
	case 'activate_diff3':
		$limit = 'activate_diff3' === $sc ? 3 : 1;
		$want  = min( $limit, count( $outs ) );
		$rule( $want === $ok_count, "successful activations: $ok_count, expected $want" );
		$rule( count( $outs ) - $ok_count === count( array_keys( $codes, 'site_limit', true ) ), 'a refused activation did not answer site_limit' );
		$rule( $want === $active_count(), 'active activations: ' . $active_count() . ", expected $want" );
		break;
	case 'activate_samesite':
		$rule( count( $outs ) === $ok_count, 'not every process got 200' );
		$rule( 1 === $active_count(), 'active activations: ' . $active_count() . ', expected 1 (same site replaces)' );
		break;
	case 'nonce_replay':
		$rule( 1 === $ok_count, "requests passed: $ok_count, expected 1" );
		$rule( count( $outs ) - 1 === count( array_keys( $codes, 'request_replayed', true ) ), 'a replay did not answer request_replayed' );
		break;
	case 'deact_vs_check':
	case 'deact_parallel':
		$act = (array) $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$t['activations']} WHERE id = %d", (int) $fx['act']['id'] ), ARRAY_A );
		$rule( 'active' !== ( $act['status'] ?? '' ), 'the activation is still active' );
		$rule( 200 === (int) $outs[0]['status'], 'the first deactivation failed' );
		$audits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['audit_log']} WHERE object_type = 'activation' AND object_id = %d AND action LIKE %s", (int) $fx['act']['id'], '%deactivat%' ) );
		$rule( 1 === $audits, "deactivation audit entries: $audits, expected 1" );
		break;
	case 'staging_vs_deact':
	case 'staging_parallel':
		$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['activations']} s LEFT JOIN {$t['activations']} p ON p.id = s.staging_parent_id WHERE s.license_id = %d AND s.status = 'active' AND s.staging_parent_id IS NOT NULL AND ( p.id IS NULL OR p.status <> 'active' )", (int) $fx['license']['id'] ) );
		$rule( 0 === $orphans, "active staging sites without an active parent: $orphans" );
		$per_parent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(c),0) FROM ( SELECT COUNT(*) c FROM {$t['activations']} WHERE license_id = %d AND status = 'active' AND staging_parent_id IS NOT NULL GROUP BY staging_parent_id ) x", (int) $fx['license']['id'] ) );
		$rule( $per_parent <= 1, "active staging sites of one parent: $per_parent, expected at most 1" );
		break;
	case 'dl_token':
		$redirects = count( array_filter( $col( 'redirect' ) ) );
		$rule( 1 === $redirects, "redeemed downloads: $redirects, expected 1 (single use within the run)" );
		break;
	case 'issue_order':
		$order    = wc_get_order( (int) $fx['order'] );
		$quantity = 0;
		foreach ( $order ? $order->get_items() : array() as $item ) {
			$quantity += (int) $item->get_quantity();
		}
		$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['licenses']} WHERE source_order_id = %d", (int) $fx['order'] ) );
		$rule( $quantity > 0 && $quantity === $issued, "licenses for the order: $issued, expected $quantity" );
		$rule( $quantity === (int) array_sum( $col( 'issued_events' ) ), 'license_issued fired ' . array_sum( $col( 'issued_events' ) ) . " times, expected $quantity" );
		break;
	case 'process_parallel':
		$rule( 1 === $charges, "charge requests: $charges, expected 1" );
		$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$t['renewals']} WHERE id = %d", (int) $fx['renewal_id'] ), ARRAY_A );
		$rule( 'paid' === ( $row['status'] ?? '' ), 'renewal status ' . ( $row['status'] ?? 'missing' ) . ', expected paid' );
		$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['renewals']} WHERE license_id = %d AND id <> %d AND status IN ('scheduled','pending')", (int) $fx['license_id'], (int) $fx['renewal_id'] ) );
		$rule( $next <= 1, "open renewals of the next period: $next, expected at most 1" );
		break;
	case 'process_vs_manualpay':
		$rule( $charges <= 1, "charge requests: $charges, expected at most 1" );
		$extended = $days( $license()['paid_until'] ?? '' ) - $days( $fx['paid_until'] );
		$rule( $extended < 400, sprintf( 'paid_until moved %.1f days: more than one period was added', $extended ) );
		break;
	case 'process_vs_extend':
		$extended = count( array_filter( $col( 'res' ), static function ( $r ) { return true === $r; } ) );
		$rule( 0 === $extended || 0 === $charges, 'both the charge and the term change applied' );
		break;
	case 'manual_start':
		$results = array_unique( array_map( 'strval', $col( 'res' ) ) );
		$rule( 1 === count( $results ) && 0 === strpos( (string) reset( $results ), 'order:' ), 'more than one renewal order or an error: ' . implode( ', ', $results ) );
		break;
	case 'manual_paid':
		$extended = $days( $license()['paid_until'] ?? '' ) - $days( $fx['paid_until'] );
		$rule( $extended > 300 && $extended < 400, sprintf( 'paid_until moved %.1f days, expected one period', $extended ) );
		break;
	case 'refund_vs_pay':
		$extended = $days( $license()['paid_until'] ?? '' ) - $days( $fx['paid_until'] );
		$rule( $extended < 400, sprintf( 'paid_until moved %.1f days: more than one period was added', $extended ) );
		$order = wc_get_order( (int) $fx['renewal_order'] );
		$row   = (array) $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$t['renewals']} WHERE id = %d", (int) $fx['renewal_id'] ), ARRAY_A );
		$rule( ! ( $order && 'refunded' === $order->get_status() && 'paid' === ( $row['status'] ?? '' ) && $extended > 300 && 'active' === ( $license()['status'] ?? '' ) ), 'the refunded renewal still extends an active license' );
		break;
	case 'status_vs_cancel':
		$row       = $license();
		$suspended = 'suspended' === ( $row['status'] ?? '' );
		$rule( $suspended || ( 'active' === ( $row['status'] ?? '' ) && ! empty( $row['cancel_at_period_end'] ) ), 'final state ' . ( $row['status'] ?? '?' ) . '/cancel=' . ( $row['cancel_at_period_end'] ?? '?' ) . ' is no serial outcome' );
		break;
	case 'extend_vs_renew':
		$left = $days( $license()['paid_until'] ?? '' );
		$rule( abs( $left - 800 ) < 2 || abs( $left - 370 ) < 2, sprintf( 'paid_until is %.1f days ahead, expected the extension (800) or the renewal (370)', $left ) );
		break;
	default:
		echo "FAIL $sc: no rules for this scenario\n";
		exit( 2 );
}

if ( $fails ) {
	foreach ( $fails as $reason ) {
		echo "FAIL $sc: $reason\n";
	}
	exit( 1 );
}
echo "PASS $sc\n";
exit( 0 );
