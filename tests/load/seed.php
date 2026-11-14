<?php
/**
 * Load-test data generator (T-89).
 *
 * Fills the plugin tables of a TEST site with realistic volumes. Never run it
 * on a production shop: it inserts thousands of WordPress users and licenses.
 *
 * Usage (WP-CLI, from anywhere):
 *
 *     wp eval-file tests/load/seed.php [scale=1] [seed=89] [reset]
 *
 * - scale  Multiplies every volume (0.1 for a quick run, 2 for twice as much).
 * - seed   Random seed; the same seed and scale produce the same data.
 * - reset  Only remove previously seeded rows (and users), insert nothing.
 *
 * Volumes at scale=1: 20 000 customers (WP users), 50 000 licenses with their
 * entitlements, 120 000 activations, 30 000 renewals, 150 000 audit rows,
 * 50 000 inbox events, 20 000 request nonces, 30 000 reminder log rows,
 * 10 000 download tokens, 10 000 package streams and 3 000 rate-limit rows.
 *
 * Rows are written with multi-row INSERTs (the plugin's own insert paths are
 * bound to WooCommerce orders and far too slow for these volumes). Column
 * values follow what the plugin writes: license keys are derived with
 * LicenseKey::for_uuid() under the current key ID, so every seeded key works
 * against the REST API (see tests/load/bench.php). The first ID of every
 * table is kept in the option `wplc_load_seed`; a later run removes the
 * previous rows first. Pre-existing rows are never touched.
 *
 * @package WPLC
 */

// phpcs:disable -- Test tooling, not shipped.

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/load/seed.php\n" );
	exit( 1 );
}

$wplc_seed_args = array( 'scale' => 1.0, 'seed' => 89, 'reset' => false );
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $wplc_arg ) {
	if ( 'reset' === $wplc_arg ) {
		$wplc_seed_args['reset'] = true;
	} elseif ( preg_match( '/^(scale|seed)=([0-9.]+)$/', (string) $wplc_arg, $m ) ) {
		$wplc_seed_args[ $m[1] ] = 'seed' === $m[1] ? (int) $m[2] : (float) $m[2];
	}
}

/**
 * Bulk writer for one table.
 */
final class WPLC_Load_Writer {
	/** @var string */
	private $table;
	/** @var string[] */
	private $columns;
	/** @var string[] */
	private $rows = array();
	/** @var int */
	public $written = 0;

	public function __construct( string $table, array $columns ) {
		$this->table   = $table;
		$this->columns = $columns;
	}

	public function add( array $values ): void {
		$out = array();
		foreach ( $values as $value ) {
			if ( null === $value ) {
				$out[] = 'NULL';
			} elseif ( is_int( $value ) ) {
				$out[] = (string) $value;
			} else {
				$out[] = "'" . esc_sql( (string) $value ) . "'";
			}
		}
		$this->rows[] = '(' . implode( ',', $out ) . ')';
		if ( count( $this->rows ) >= 1000 ) {
			$this->flush();
		}
	}

	public function flush(): void {
		global $wpdb;
		if ( ! $this->rows ) {
			return;
		}
		$sql = "INSERT INTO {$this->table} (" . implode( ',', $this->columns ) . ') VALUES ' . implode( ',', $this->rows );
		if ( false === $wpdb->query( $sql ) ) {
			fwrite( STDERR, "Insert into {$this->table} failed: {$wpdb->last_error}\n" );
			exit( 1 );
		}
		$this->written += count( $this->rows );
		$this->rows     = array();
	}
}

/** Weighted random pick. */
function wplc_load_pick( array $weights ) {
	$total = array_sum( $weights );
	$r     = mt_rand( 1, (int) $total );
	foreach ( $weights as $key => $weight ) {
		$r -= $weight;
		if ( $r <= 0 ) {
			return $key;
		}
	}
	return array_key_first( $weights );
}

function wplc_load_uuid(): string {
	$hex = '';
	for ( $i = 0; $i < 4; $i++ ) {
		$hex .= sprintf( '%08x', mt_rand( 0, 0x7fffffff ) | ( mt_rand( 0, 1 ) << 31 ) );
	}
	$hex[12] = '4';
	$hex[16] = dechex( 8 + mt_rand( 0, 3 ) );
	return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
}

function wplc_load_dt( int $ts ): string {
	return gmdate( 'Y-m-d H:i:s', $ts );
}

function wplc_load_hex( int $bytes ): string {
	$out = '';
	for ( $i = 0; $i < $bytes; $i += 4 ) {
		$out .= sprintf( '%08x', mt_rand() );
	}
	return substr( $out, 0, $bytes * 2 );
}

global $wpdb;
$tables = Schema::table_names();
$seeded = get_option( 'wplc_load_seed', array() );
$seeded = is_array( $seeded ) ? $seeded : array();
$t0     = microtime( true );

// ---------------------------------------------------------------- reset
if ( $seeded ) {
	foreach ( $seeded as $name => $first_id ) {
		$first_id = (int) $first_id;
		if ( 'users' === $name ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id >= %d", $first_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID >= %d", $first_id ) );
		} elseif ( isset( $tables[ $name ] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables[ $name ]} WHERE id >= %d", $first_id ) );
		}
	}
	delete_option( 'wplc_load_seed' );
	wp_cache_flush();
	WP_CLI::log( sprintf( 'Removed previously seeded rows (%.1fs).', microtime( true ) - $t0 ) );
}
if ( $wplc_seed_args['reset'] ) {
	return;
}

mt_srand( $wplc_seed_args['seed'] );
$scale = max( 0.001, $wplc_seed_args['scale'] );
$n     = static function ( int $count ) use ( $scale ): int {
	return max( 1, (int) round( $count * $scale ) );
};
$now    = time();
$day    = 86400;
$year   = 365 * $day;
$key_id = LicenseKey::current_key_id();

$first_ids = array();
$next_id   = static function ( string $table, string $pk = 'id' ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( "SELECT COALESCE(MAX({$pk}),0)+1 FROM {$table}" );
};

$wpdb->query( 'SET unique_checks = 0' );
$wpdb->query( 'SET autocommit = 0' );

// ---------------------------------------------------------------- customers
$customer_count     = $n( 20000 );
$first_ids['users'] = $next_id( $wpdb->users, 'ID' );
// Explicit IDs keep the license -> customer mapping simple.
$wpdb->query( "ALTER TABLE {$wpdb->users} AUTO_INCREMENT = {$first_ids['users']}" );
$users      = new WPLC_Load_Writer( $wpdb->users, array( 'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_registered', 'display_name' ) );
$usermeta   = new WPLC_Load_Writer( $wpdb->usermeta, array( 'user_id', 'meta_key', 'meta_value' ) );
$first      = array( 'anna', 'ben', 'clara', 'david', 'eva', 'felix', 'greta', 'hans', 'ida', 'jonas', 'karin', 'lukas', 'mia', 'noah', 'olga', 'paul', 'rosa', 'sven', 'tina', 'uwe' );
$domains    = array( 'example.com', 'example.net', 'example.org', 'agentur.example', 'webdesign.example', 'shop.example' );
$cap_key    = $wpdb->prefix . 'capabilities';
$level_key  = $wpdb->prefix . 'user_level';
$pass       = '$P$BloadseedloadseedloadseedXXXX.'; // Unusable password hash.
$customers  = array();
for ( $i = 0; $i < $customer_count; $i++ ) {
	$id     = $first_ids['users'] + $i;
	$login  = 'load_' . $first[ $i % count( $first ) ] . $i;
	$email  = $first[ mt_rand( 0, count( $first ) - 1 ) ] . '.' . $i . '@' . $domains[ mt_rand( 0, count( $domains ) - 1 ) ];
	$users->add( array( $id, $login, $pass, $login, $email, wplc_load_dt( $now - mt_rand( 0, 4 * $year ) ), ucfirst( $first[ $i % count( $first ) ] ) . ' ' . $i ) );
	$usermeta->add( array( $id, $cap_key, 'a:1:{s:8:"customer";b:1;}' ) );
	$usermeta->add( array( $id, $level_key, '0' ) );
	$customers[] = $id;
}
$users->flush();
$usermeta->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'users: %d (%.1fs)', $users->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- plans
$plans = $wpdb->get_results( "SELECT product_key, plan_key, billing_interval, live_site_limit, entitlements_json, woocommerce_product_id, price_minor FROM {$tables['plans']} WHERE active = 1", ARRAY_A );
if ( ! $plans ) {
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tables['products']} (product_key, display_name, active, created_at, updated_at) VALUES ('load-product', 'Load Product', 1, %s, %s)", wplc_load_dt( $now ), wplc_load_dt( $now ) ) );
	$plans = array(
		array( 'product_key' => 'load-product', 'plan_key' => 'single', 'billing_interval' => 'year', 'live_site_limit' => 1, 'entitlements_json' => '{"sites.live_limit":1,"support.level":"standard"}', 'woocommerce_product_id' => 0, 'price_minor' => 4900 ),
		array( 'product_key' => 'load-product', 'plan_key' => 'agency', 'billing_interval' => 'year', 'live_site_limit' => 25, 'entitlements_json' => '{"sites.live_limit":25,"support.level":"priority"}', 'woocommerce_product_id' => 0, 'price_minor' => 19900 ),
	);
}
// Most sales on a few plans: weight 40 for the first plan of each product, 5 for the others.
$plan_weights = array();
$seen_product = array();
foreach ( $plans as $index => $plan ) {
	$plan_weights[ $index ] = isset( $seen_product[ $plan['product_key'] ] ) ? 5 : 40;
	$seen_product[ $plan['product_key'] ] = true;
}

// ---------------------------------------------------------------- licenses + entitlements
$license_count = $n( 50000 );
$first_ids['licenses']     = $next_id( $tables['licenses'] );
$first_ids['entitlements'] = $next_id( $tables['entitlements'] );
$license_writer = new WPLC_Load_Writer(
	$tables['licenses'],
	array( 'id', 'license_uuid', 'license_key_hash', 'license_key_secret_id', 'key_prefix', 'customer_id', 'product_key', 'plan_key', 'status', 'status_reason', 'renewal_enabled', 'payment_gateway_id', 'payment_token_id', 'renewal_amount_minor', 'renewal_currency', 'renewal_product_id', 'cancel_at_period_end', 'revoked_at', 'billing_interval', 'starts_at', 'paid_until', 'next_payment_at', 'source_order_id', 'source_order_item_id', 'unit_index', 'created_at', 'updated_at' )
);
$ent_writer = new WPLC_Load_Writer( $tables['entitlements'], array( 'license_id', 'entitlement_key', 'value_json', 'starts_at', 'ends_at', 'status' ) );

// Agencies: 1% of the customers own ~30% of the licenses; customer 0 is a big reseller.
$agency_count = max( 1, (int) ( $customer_count / 100 ) );
$licenses     = array(); // id => [status, created_ts, paid_until_ts, limit, renewal_enabled]
$order_id     = 1000000;
// Licenses are created in time order, so IDs grow with created_at as in production.
$created_list = array();
for ( $i = 0; $i < $license_count; $i++ ) {
	$created_list[] = $now - mt_rand( 0, 4 * $year );
}
sort( $created_list );
foreach ( $created_list as $i => $created ) {
	$id   = $first_ids['licenses'] + $i;
	$r    = mt_rand( 1, 100 );
	$customer = $r <= 3 ? $customers[0] : ( $r <= 30 ? $customers[ mt_rand( 1, $agency_count ) ] : $customers[ mt_rand( 0, $customer_count - 1 ) ] );
	$plan     = $plans[ wplc_load_pick( $plan_weights ) ];
	$interval = 'month' === $plan['billing_interval'] ? 'month' : 'year';
	$period   = 'month' === $interval ? 30 * $day : $year;
	$uuid     = wplc_load_uuid();
	$key      = LicenseKey::for_uuid( $uuid, $key_id );
	// Status mix of a shop that has sold for four years.
	$age     = $now - $created;
	$renewed = mt_rand( 1, 100 ) <= 70; // Customer kept paying.
	if ( $renewed || $age < $period ) {
		$periods    = (int) floor( $age / $period ) + 1;
		$paid_until = $created + $periods * $period;
		$status     = wplc_load_pick( array( 'active' => 940, 'past_due' => 25, 'suspended' => 10, 'revoked' => 15, 'pending' => 10 ) );
		if ( 'past_due' === $status ) {
			$paid_until = $now - mt_rand( 0, 13 ) * $day;
		}
	} else {
		$paid_until = $created + (int) mt_rand( 1, max( 1, (int) floor( $age / $period ) ) ) * $period;
		$paid_until = min( $paid_until, $now - $day );
		$status     = wplc_load_pick( array( 'expired' => 90, 'revoked' => 5, 'suspended' => 5 ) );
	}
	$renewal_enabled = ( 'active' === $status || 'past_due' === $status ) && mt_rand( 1, 100 ) <= 45 ? 1 : 0;
	$cancel          = $renewal_enabled && mt_rand( 1, 100 ) <= 5 ? 1 : 0;
	$entitlements    = json_decode( (string) $plan['entitlements_json'], true );
	$entitlements    = is_array( $entitlements ) ? $entitlements : array( 'sites.live_limit' => (int) $plan['live_site_limit'] );
	$limit           = isset( $entitlements['sites.live_limit'] ) ? (int) $entitlements['sites.live_limit'] : (int) $plan['live_site_limit'];
	$manual          = mt_rand( 1, 100 ) <= 8; // Issued by an administrator, without order.
	$order_id       += $manual ? 0 : 1;

	$license_writer->add(
		array(
			$id,
			$uuid,
			LicenseKey::hash( $key ),
			$key_id,
			LicenseKey::prefix( $uuid ),
			$customer,
			$plan['product_key'],
			$plan['plan_key'],
			$status,
			'revoked' === $status ? 'Chargeback' : '',
			$renewal_enabled,
			$renewal_enabled ? 'stripe' : '',
			$renewal_enabled ? mt_rand( 1, 50000 ) : 0,
			(int) $plan['price_minor'],
			'EUR',
			(int) $plan['woocommerce_product_id'],
			$cancel,
			'revoked' === $status ? wplc_load_dt( $created + (int) ( ( $paid_until - $created ) / 2 ) ) : null,
			$interval,
			wplc_load_dt( $created ),
			'pending' === $status ? null : wplc_load_dt( $paid_until ),
			$renewal_enabled && ! $cancel ? wplc_load_dt( $paid_until ) : null,
			$manual ? null : $order_id,
			$manual ? null : $order_id * 2,
			0,
			wplc_load_dt( $created ),
			wplc_load_dt( min( $now, $created + mt_rand( 0, max( 0, $age ) ) ) ),
		)
	);
	foreach ( $entitlements as $ent_key => $value ) {
		$ent_writer->add( array( $id, (string) $ent_key, (string) wp_json_encode( $value ), wplc_load_dt( $created ), null, 'active' ) );
	}
	$licenses[ $id ] = array( $status, $created, $paid_until, max( 1, $limit ), $renewal_enabled );
}
$license_writer->flush();
$ent_writer->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'licenses: %d, entitlements: %d (%.1fs)', $license_writer->written, $ent_writer->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- activations
$activation_target = $n( 120000 );
$first_ids['activations'] = $next_id( $tables['activations'] );
$act_writer = new WPLC_Load_Writer(
	$tables['activations'],
	array( 'id', 'license_id', 'installation_uuid', 'installation_secret_hash', 'installation_secret_encrypted', 'site_url', 'environment', 'staging_parent_id', 'product_version', 'wp_version', 'php_version', 'status', 'activated_at', 'last_seen_at', 'deactivated_at' )
);
$license_ids     = array_keys( $licenses );
$activation_id   = $first_ids['activations'];
$active_ids      = array();
$ended_ids       = array();
$secret_blob     = 'v1:' . $key_id . ':' . base64_encode( str_repeat( 'x', 90 ) );
$activations_per = $activation_target / max( 1, $license_count );
$remaining       = $activation_target;
foreach ( $license_ids as $index => $license_id ) {
	if ( $remaining <= 0 ) {
		break;
	}
	list( $status, $created, $paid_until, $limit ) = $licenses[ $license_id ];
	// Mean ~2.4 per license; agency plans use their limit.
	$count = $limit > 2 ? mt_rand( 1, min( 40, $limit + 2 ) ) : wplc_load_pick( array( 0 => 10, 1 => 30, 2 => 25, 3 => 15, 4 => 10, 5 => 5, 8 => 5 ) );
	$count = $count * $activations_per / 2.4;
	$count = (int) min( $remaining, (int) floor( $count ) + ( mt_rand() / mt_getrandmax() < $count - floor( $count ) ? 1 : 0 ) );
	if ( count( $license_ids ) - $index <= $remaining / 6 ) {
		$count = max( $count, 1 ); // Use up the target near the end.
	}
	$production_id = 0;
	for ( $a = 0; $a < $count; $a++ ) {
		$env    = 0 === $a ? 'production' : wplc_load_pick( array( 'production' => 70, 'staging' => 20, 'development' => 10 ) );
		$ended  = 'active' !== $status || mt_rand( 1, 100 ) <= 30;
		$act_at = $created + mt_rand( 0, max( 1, min( $now, $paid_until ) - $created ) );
		$parent = 'staging' === $env && $production_id ? $production_id : null;
		$host   = 'site' . $license_id . '-' . $a . '.' . $domains[ $a % count( $domains ) ];
		$act_writer->add(
			array(
				$activation_id,
				$license_id,
				wplc_load_uuid(),
				wplc_load_hex( 32 ),
				$secret_blob,
				'https://' . ( 'staging' === $env ? 'staging.' : '' ) . $host,
				$env,
				$parent,
				'1.' . mt_rand( 0, 9 ) . '.' . mt_rand( 0, 9 ),
				'6.' . mt_rand( 4, 9 ),
				'8.' . mt_rand( 1, 4 ) . '.' . mt_rand( 0, 20 ),
				$ended ? 'deactivated' : 'active',
				wplc_load_dt( $act_at ),
				wplc_load_dt( $ended ? $act_at + mt_rand( 0, 90 ) * $day : $now - mt_rand( 0, 7 * $day ) ),
				$ended ? wplc_load_dt( min( $now, $act_at + mt_rand( 1, 400 ) * $day ) ) : null,
			)
		);
		if ( 'production' === $env && ! $production_id ) {
			$production_id = $activation_id;
		}
		if ( $ended ) {
			$ended_ids[] = $activation_id;
		} else {
			$active_ids[] = $activation_id;
		}
		++$activation_id;
		--$remaining;
	}
}
$act_writer->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'activations: %d (%.1fs)', $act_writer->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- renewals
$renewal_count = $n( 30000 );
$first_ids['renewals'] = $next_id( $tables['renewals'] );
$ren_writer = new WPLC_Load_Writer(
	$tables['renewals'],
	array( 'license_id', 'period_start', 'period_end', 'renewal_order_id', 'gateway_attempt_id', 'idempotency_key', 'status', 'retry_at', 'grace_ends_at', 'attempt_count', 'review_reason', 'review_at', 'created_at', 'updated_at' )
);
$renewal_ids_by_license = array();
$renewal_id             = $first_ids['renewals'];
for ( $i = 0; $i < $renewal_count; $i++ ) {
	$license_id = $license_ids[ mt_rand( 0, count( $license_ids ) - 1 ) ];
	list( $lstatus, $created, $paid_until ) = $licenses[ $license_id ];
	$status = wplc_load_pick(
		array(
			'paid'                   => 7500,
			'cancelled'              => 800,
			'scheduled'              => 800,
			'manual'                 => 400,
			'failed'                 => 250,
			'manual_action_required' => 100,
			'refunded'               => 50,
			'review_required'        => 30,
			'closed'                 => 30,
			'reconciling'            => 20,
			'processing'             => 10,
			'expired'                => 10,
		)
	);
	$start = 'scheduled' === $status ? $paid_until : $created + mt_rand( 0, max( 1, $now - $created ) );
	$retry = null;
	if ( in_array( $status, array( 'scheduled', 'failed', 'reconciling' ), true ) ) {
		// Mostly in the future; a few overdue rows for the requeue sweep.
		$retry = mt_rand( 1, 100 ) <= 3 ? $now - mt_rand( 2, 72 ) * 3600 : max( $start, $now + mt_rand( 1, 300 ) * $day );
	}
	$updated = 'processing' === $status ? $now - mt_rand( 0, 3600 ) : min( $now, $start + mt_rand( 0, 30 ) * $day );
	$ren_writer->add(
		array(
			$license_id,
			wplc_load_dt( $start ),
			wplc_load_dt( $start + $year ),
			in_array( $status, array( 'paid', 'manual', 'failed', 'manual_action_required', 'refunded', 'review_required' ), true ) ? 2000000 + $i : null,
			'paid' === $status ? 'pi_' . wplc_load_hex( 12 ) : '',
			hash( 'sha256', 'load-renewal-' . $wplc_seed_args['seed'] . '-' . $i ),
			$status,
			null === $retry ? null : wplc_load_dt( $retry ),
			wplc_load_dt( $start + 14 * $day ),
			'failed' === $status ? mt_rand( 1, 4 ) : 1,
			'review_required' === $status ? 'Payment result unknown' : '',
			'review_required' === $status ? wplc_load_dt( $updated ) : null,
			wplc_load_dt( $start - 7 * $day ),
			wplc_load_dt( $updated ),
		)
	);
	$renewal_ids_by_license[ $license_id ][] = $renewal_id++;
}
$ren_writer->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'renewals: %d (%.1fs)', $ren_writer->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- audit log
$audit_count = $n( 150000 );
$first_ids['audit_log'] = $next_id( $tables['audit_log'] );
$audit_writer = new WPLC_Load_Writer( $tables['audit_log'], array( 'actor_user_id', 'action', 'object_type', 'object_id', 'reason', 'before_json', 'after_json', 'created_at' ) );
$audit_times  = array();
for ( $i = 0; $i < $audit_count; $i++ ) {
	$audit_times[] = $now - mt_rand( 0, 3 * $year );
}
sort( $audit_times ); // IDs grow with created_at (Retention relies on it).
$all_activation_ids = array_merge( $active_ids, $ended_ids );
$renewal_licenses   = array_keys( $renewal_ids_by_license );
foreach ( $audit_times as $i => $ts ) {
	$type = wplc_load_pick( array( 'license' => 50, 'activation' => 35, 'renewal' => 15 ) );
	if ( 'activation' === $type && $all_activation_ids ) {
		$object = $all_activation_ids[ mt_rand( 0, count( $all_activation_ids ) - 1 ) ];
		$action = wplc_load_pick( array( 'site_activated' => 70, 'site_deactivated' => 25, 'site_replaced' => 5 ) );
	} elseif ( 'renewal' === $type && $renewal_licenses ) {
		$ids    = $renewal_ids_by_license[ $renewal_licenses[ mt_rand( 0, count( $renewal_licenses ) - 1 ) ] ];
		$object = $ids[ mt_rand( 0, count( $ids ) - 1 ) ];
		$action = wplc_load_pick( array( 'renewal_paid' => 70, 'renewal_failed' => 20, 'manual_renewal_started' => 10 ) );
	} else {
		$type   = 'license';
		$object = $license_ids[ mt_rand( 0, count( $license_ids ) - 1 ) ];
		$action = wplc_load_pick( array( 'license_issued' => 30, 'renewal_succeeded' => 40, 'license_expired' => 15, 'admin_status_change' => 5, 'cancellation_scheduled' => 10 ) );
	}
	$audit_writer->add(
		array(
			mt_rand( 1, 100 ) <= 10 ? 1 : 0,
			$action,
			$type,
			$object,
			'admin_status_change' === $action ? 'Customer request by email' : '',
			'license' === $type ? '{"status":"active","paid_until":"' . wplc_load_dt( $ts ) . '"}' : null,
			'license' === $type ? '{"status":"active","paid_until":"' . wplc_load_dt( $ts + $year ) . '"}' : null,
			wplc_load_dt( $ts ),
		)
	);
}
$audit_writer->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'audit_log: %d (%.1fs)', $audit_writer->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- event inbox
$inbox_count = $n( 50000 );
$first_ids['event_inbox'] = $next_id( $tables['event_inbox'] );
$inbox_writer = new WPLC_Load_Writer( $tables['event_inbox'], array( 'source', 'source_event_id', 'event_type', 'payload_hash', 'payload_json', 'received_at', 'processed_at', 'outcome' ) );
$inbox_times  = array();
for ( $i = 0; $i < $inbox_count; $i++ ) {
	$inbox_times[] = $now - mt_rand( 0, $year );
}
sort( $inbox_times );
$payload = wp_json_encode( array( 'object' => 'event', 'data' => array( 'object' => array( 'object' => 'payment_intent', 'amount' => 4900, 'currency' => 'eur', 'metadata' => array( 'order_id' => '123456' ), 'padding' => str_repeat( 'p', 600 ) ) ) ) );
foreach ( $inbox_times as $i => $ts ) {
	$outcome = wplc_load_pick( array( 'processed' => 9000, 'ignored' => 900, 'failed' => 60, 'pending_reconciliation' => 20, 'manual_review' => 15, 'received' => 5 ) );
	$inbox_writer->add(
		array(
			'stripe',
			'evt_load' . $wplc_seed_args['seed'] . '_' . $i,
			wplc_load_pick( array( 'payment_intent.succeeded' => 70, 'payment_intent.payment_failed' => 10, 'charge.refunded' => 5, 'charge.dispute.created' => 1, 'customer.updated' => 14 ) ),
			wplc_load_hex( 32 ),
			$payload,
			wplc_load_dt( $ts ),
			in_array( $outcome, array( 'received', 'pending_reconciliation' ), true ) ? null : wplc_load_dt( $ts + mt_rand( 0, 120 ) ),
			$outcome,
		)
	);
}
$inbox_writer->flush();
$wpdb->query( 'COMMIT' );
WP_CLI::log( sprintf( 'event_inbox: %d (%.1fs)', $inbox_writer->written, microtime( true ) - $t0 ) );

// ---------------------------------------------------------------- request nonces, rate limits
$first_ids['request_nonces'] = $next_id( $tables['request_nonces'] );
$nonce_writer = new WPLC_Load_Writer( $tables['request_nonces'], array( 'activation_id', 'nonce_hash', 'expires_at', 'created_at' ) );
$nonce_count  = $n( 20000 );
for ( $i = 0; $i < $nonce_count && $active_ids; $i++ ) {
	$created = $now - mt_rand( 0, 600 );
	$nonce_writer->add( array( $active_ids[ mt_rand( 0, count( $active_ids ) - 1 ) ], wplc_load_hex( 32 ), wplc_load_dt( $created + 600 ), wplc_load_dt( $created ) ) );
}
$nonce_writer->flush();
$first_ids['request_limits'] = $next_id( $tables['request_limits'] );
$limit_writer = new WPLC_Load_Writer( $tables['request_limits'], array( 'limiter_hash', 'bucket_start', 'hits', 'created_at' ) );
$limit_count  = $n( 3000 );
for ( $i = 0; $i < $limit_count; $i++ ) {
	$bucket = $now - ( $now % 3600 ) - mt_rand( 0, 23 ) * 3600;
	$limit_writer->add( array( wplc_load_hex( 32 ), wplc_load_dt( $bucket ), mt_rand( 1, 30 ), wplc_load_dt( $bucket ) ) );
}
$limit_writer->flush();
$wpdb->query( 'COMMIT' );

// ---------------------------------------------------------------- reminder log
$first_ids['reminder_log'] = $next_id( $tables['reminder_log'] );
$reminder_writer = new WPLC_Load_Writer( $tables['reminder_log'], array( 'license_id', 'reminder_type', 'period_end', 'sent_at' ) );
$reminder_count  = $n( 30000 );
for ( $i = 0; $i < $reminder_count; $i++ ) {
	$license_id = $license_ids[ $i % count( $license_ids ) ];
	$paid_until = $licenses[ $license_id ][2] - (int) floor( $i / count( $license_ids ) ) * $year;
	$type       = 0 === $i % 3 ? 'renewal_notice' : 'expiry_reminder';
	$reminder_writer->add( array( $license_id, $type . ( 'expiry_reminder' === $type ? ':' . ( 0 === $i % 2 ? 30 : 7 ) : '' ), wplc_load_dt( $paid_until ), wplc_load_dt( min( $now, $paid_until - 7 * $day ) ) ) );
}
$reminder_writer->flush();
$wpdb->query( 'COMMIT' );

// ---------------------------------------------------------------- download tokens, package streams
$first_ids['download_tokens'] = $next_id( $tables['download_tokens'] );
$token_writer = new WPLC_Load_Writer( $tables['download_tokens'], array( 'license_id', 'token_hash', 'package_url', 'expires_at', 'consumed_at', 'created_at' ) );
$token_count  = $n( 10000 );
for ( $i = 0; $i < $token_count; $i++ ) {
	$created = $now - mt_rand( 0, 2 * $day );
	$token_writer->add( array( $license_ids[ mt_rand( 0, count( $license_ids ) - 1 ) ], wplc_load_hex( 32 ), 'https://downloads.example/package.zip', wplc_load_dt( $created + 3600 ), mt_rand( 1, 100 ) <= 60 ? wplc_load_dt( $created + 60 ) : null, wplc_load_dt( $created ) ) );
}
$token_writer->flush();
$first_ids['package_streams'] = $next_id( $tables['package_streams'] );
$stream_writer = new WPLC_Load_Writer( $tables['package_streams'], array( 'token_hash', 'release_id', 'license_id', 'source', 'expires_at', 'consumed_at', 'created_at' ) );
for ( $i = 0; $i < $token_count; $i++ ) {
	$created = $now - mt_rand( 0, 2 * $day );
	$stream_writer->add( array( wplc_load_hex( 32 ), 1, $license_ids[ mt_rand( 0, count( $license_ids ) - 1 ) ], mt_rand( 1, 100 ) <= 80 ? 'update' : 'customer', wplc_load_dt( $created + 3600 ), mt_rand( 1, 100 ) <= 60 ? wplc_load_dt( $created + 60 ) : null, wplc_load_dt( $created ) ) );
}
$stream_writer->flush();
$wpdb->query( 'COMMIT' );
$wpdb->query( 'SET autocommit = 1' );
$wpdb->query( 'SET unique_checks = 1' );

update_option( 'wplc_load_seed', $first_ids, false );
foreach ( array_merge( array_values( $tables ), array( $wpdb->users, $wpdb->usermeta ) ) as $table ) {
	$wpdb->query( "ANALYZE TABLE {$table}" );
}
wp_cache_flush();
WP_CLI::success( sprintf( 'Seeded in %.1fs (scale %s, seed %d).', microtime( true ) - $t0, $scale, $wplc_seed_args['seed'] ) );
