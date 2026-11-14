<?php
/**
 * Load-test benchmark (T-89): times the hot code paths and explains their queries.
 *
 * Run on a TEST site seeded with tests/load/seed.php:
 *
 *     wp eval-file tests/load/bench.php [runs=5] [only=<substring>] [json=/path/out.json] [http=<port>] [explain=0]
 *
 * - runs     Repetitions per scenario; the median wall time is reported.
 * - only     Run only scenarios whose name contains this text.
 * - json     Also write the full result (queries, timings, plans) as JSON.
 * - http     Port of a running `php -S 127.0.0.1:<port> tests/e2e/router.php`
 *            server for the same site (see tests/load/README section in
 *            docs/PERFORMANCE.md). Adds real-HTTP scenarios: admin list page,
 *            customer account page, REST activation, entitlement and update check.
 * - explain  0 skips EXPLAIN and the per-query re-runs.
 *
 * In-process scenarios call the plugin code directly with SAVEQUERIES. Jobs that
 * change data (expiry, reminders, retention, ...) run inside a transaction that
 * is rolled back, so the seeded data stays the same between runs.
 *
 * For every plugin query of a scenario the report shows the time of one
 * isolated re-run, the rows the server really read (Handler_read_* counters)
 * and the EXPLAIN plan (access type, key, estimated rows, Extra).
 *
 * @package WPLC
 */

// phpcs:disable -- Test tooling, not shipped.

use WPLC\Admin\Diagnostics;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\LicenseSearchStore;
use WPLC\Account\CustomerPortal;
use WPLC\Api\V1\Authenticator;
use WPLC\Api\V1\UpdateService;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalReview;
use WPLC\Client\LicenseClient;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\Maintenance\Retention;
use WPLC\Notifications\Notifications;
use WPLC\Update\PackageStream;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/load/bench.php\n" );
	exit( 1 );
}
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

$opt = array( 'runs' => 5, 'only' => '', 'json' => '', 'http' => 0, 'explain' => 1 );
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $wplc_arg ) {
	if ( preg_match( '/^(runs|only|json|http|explain)=(.*)$/', (string) $wplc_arg, $m ) ) {
		$opt[ $m[1] ] = in_array( $m[1], array( 'runs', 'http', 'explain' ), true ) ? (int) $m[2] : $m[2];
	}
}

global $wpdb;
$tables = Schema::table_names();
$admin  = (int) $wpdb->get_var( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = '{$wpdb->prefix}capabilities' AND m.meta_value LIKE '%administrator%' ORDER BY u.ID ASC LIMIT 1" );

// ---------------------------------------------------------------- sample values from the data
$big_customer   = (int) $wpdb->get_var( "SELECT customer_id FROM {$tables['licenses']} GROUP BY customer_id ORDER BY COUNT(*) DESC LIMIT 1" );
$small_customer = (int) $wpdb->get_var( "SELECT customer_id FROM {$tables['licenses']} GROUP BY customer_id HAVING COUNT(*) = 2 ORDER BY customer_id DESC LIMIT 1" );
$mid_license    = $wpdb->get_row( "SELECT * FROM {$tables['licenses']} ORDER BY id ASC LIMIT 1 OFFSET " . (int) ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['licenses']}" ) / 2 ), ARRAY_A );
$busy_license   = (int) $wpdb->get_var( "SELECT license_id FROM {$tables['activations']} GROUP BY license_id ORDER BY COUNT(*) DESC LIMIT 1" );
$top_product    = (string) $wpdb->get_var( "SELECT product_key FROM {$tables['licenses']} GROUP BY product_key ORDER BY COUNT(*) DESC LIMIT 1" );
$rare_product   = (string) $wpdb->get_var( "SELECT product_key FROM {$tables['licenses']} GROUP BY product_key ORDER BY COUNT(*) ASC LIMIT 1" );
$customer_email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID = %d", $small_customer ) );
$full_key       = LicenseKey::for_uuid( (string) $mid_license['license_uuid'], (string) $mid_license['license_key_secret_id'] );
$total_pages    = (int) ceil( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['licenses']}" ) / 50 );

// ---------------------------------------------------------------- helpers
function wplc_bench_admin_list( array $get ): void {
	$_GET = $get + array( 'page' => 'wplc-licenses' );
	$_REQUEST = $_GET;
	$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( $_GET );
	if ( ! function_exists( 'convert_to_screen' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}
	if ( ! class_exists( '\\WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
	set_current_screen( 'woocommerce_page_wplc-licenses' );
	LicenseAdmin::render();
}

function wplc_bench_search( string $term ): string {
	return LicenseSearchStore::save( get_current_user_id(), $term );
}

/** Runs $fn $runs times, returns median wall ms and the plugin queries of the last run. */
function wplc_bench_scenario( callable $fn, int $runs, bool $rollback ): array {
	global $wpdb;
	$walls = array();
	$queries = array();
	for ( $r = 0; $r < $runs; $r++ ) {
		wp_cache_flush();
		LicenseSearchStore::reset_cache();
		$wpdb->queries = array();
		if ( $rollback ) {
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->queries = array();
		}
		$t = microtime( true );
		ob_start();
		try {
			$fn();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			if ( $rollback ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return array( 'error' => get_class( $e ) . ': ' . $e->getMessage() );
		}
		ob_end_clean();
		$walls[] = ( microtime( true ) - $t ) * 1000;
		$queries = $wpdb->queries;
		if ( $rollback ) {
			$wpdb->query( 'ROLLBACK' );
		}
	}
	sort( $walls );
	$db_ms = 0.0;
	foreach ( $queries as $q ) {
		$db_ms += $q[1] * 1000;
	}

	return array(
		'wall_ms'     => $walls[ (int) floor( count( $walls ) / 2 ) ],
		'db_ms'       => $db_ms,
		'query_count' => count( $queries ),
		'queries'     => wplc_bench_relevant( array_map( static function ( $q ) { return array( 'sql' => $q[0], 'time' => $q[1] ); }, $queries ) ),
	);
}

/** Plugin and user-table queries worth explaining. */
function wplc_bench_relevant( array $queries ): array {
	global $wpdb;
	$out = array();
	foreach ( $queries as $q ) {
		$sql = trim( (string) $q['sql'] );
		if ( ! preg_match( '/^(SELECT|DELETE|UPDATE)\b/i', $sql ) || preg_match( '/^SELECT\s+(GET_LOCK|RELEASE_LOCK|FOUND_ROWS|COUNT\(\*\) FROM information_schema)/i', $sql ) ) {
			continue;
		}
		if ( false === strpos( $sql, $wpdb->prefix . 'wplc_' ) && ! preg_match( '/FROM ' . preg_quote( $wpdb->users, '/' ) . '\b/', $sql ) ) {
			continue;
		}
		$out[] = array( 'sql' => $sql, 'time_ms' => (float) $q['time'] * 1000 );
	}
	return $out;
}

/** Isolated re-run (rolled back for writes), rows read and EXPLAIN of one query. */
function wplc_bench_explain( string $sql ): array {
	global $wpdb;
	$write = ! preg_match( '/^SELECT/i', $sql );
	$plan  = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
	$times = array();
	$read  = 0;
	for ( $i = 0; $i < 3; $i++ ) {
		// FLUSH commits implicitly, so it must come before the transaction.
		$wpdb->query( 'FLUSH STATUS' );
		if ( $write ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		$t = microtime( true );
		$wpdb->query( $sql );
		$times[] = ( microtime( true ) - $t ) * 1000;
		$status = $wpdb->get_results( "SHOW SESSION STATUS LIKE 'Handler_read%'", ARRAY_N );
		$read   = 0;
		foreach ( (array) $status as $row ) {
			if ( 'Handler_read_key' === $row[0] || 'Handler_read_next' === $row[0] || 'Handler_read_prev' === $row[0] || 'Handler_read_rnd_next' === $row[0] || 'Handler_read_first' === $row[0] || 'Handler_read_last' === $row[0] || 'Handler_read_rnd' === $row[0] ) {
				$read += (int) $row[1];
			}
		}
		if ( $write ) {
			$wpdb->query( 'ROLLBACK' );
		}
	}
	sort( $times );
	$compact = array();
	foreach ( (array) $plan as $row ) {
		$compact[] = sprintf( '%s:%s key=%s rows=%s %s', $row['table'] ?? '-', $row['type'] ?? '-', $row['key'] ?? '-', $row['rows'] ?? '-', $row['Extra'] ?? '' );
	}
	return array( 'ms' => $times[1], 'rows_read' => $read, 'plan' => $compact );
}

function wplc_bench_short( string $sql ): string {
	global $wpdb;
	$sql = preg_replace( '/\s+/', ' ', str_replace( $wpdb->prefix, '', $sql ) );
	$sql = preg_replace( '/IN \((\d+,){5,}\d+\)/', 'IN (...ids)', (string) $sql );
	return strlen( (string) $sql ) > 240 ? substr( (string) $sql, 0, 237 ) . '...' : (string) $sql;
}

// ---------------------------------------------------------------- scenarios
$as_admin = static function () use ( $admin ) {
	wp_set_current_user( $admin );
};
$scenarios = array();
$list = static function ( array $get ) use ( $as_admin ) {
	return static function () use ( $get, $as_admin ) {
		$as_admin();
		wplc_bench_admin_list( $get );
	};
};
$search_list = static function ( string $term, array $get = array() ) use ( $as_admin ) {
	return static function () use ( $term, $get, $as_admin ) {
		$as_admin();
		$token = wplc_bench_search( $term );
		wplc_bench_admin_list( $get + array( LicenseSearchStore::QUERY_ARG => $token ) );
	};
};

$scenarios['list: default (created desc)']      = array( $list( array() ), false );
$scenarios['list: created asc']                 = array( $list( array( 'orderby' => 'created', 'order' => 'asc' ) ), false );
$scenarios['list: status asc']                  = array( $list( array( 'orderby' => 'status', 'order' => 'asc' ) ), false );
$scenarios['list: status desc']                 = array( $list( array( 'orderby' => 'status', 'order' => 'desc' ) ), false );
$scenarios['list: paid_until asc']              = array( $list( array( 'orderby' => 'paid_until', 'order' => 'asc' ) ), false );
$scenarios['list: paid_until desc']             = array( $list( array( 'orderby' => 'paid_until', 'order' => 'desc' ) ), false );
$scenarios['list: last page']                   = array( $list( array( 'paged' => $total_pages ) ), false );
$scenarios['list: status=active']               = array( $list( array( 'status' => 'active' ) ), false );
$scenarios['list: status=past_due']             = array( $list( array( 'status' => 'past_due' ) ), false );
$scenarios['list: status=past_due paid_until']  = array( $list( array( 'status' => 'past_due', 'orderby' => 'paid_until', 'order' => 'asc' ) ), false );
$scenarios['list: product=top']                 = array( $list( array( 'product' => $top_product ) ), false );
$scenarios['list: product=rare']                = array( $list( array( 'product' => $rare_product ) ), false );
$scenarios['list: product=top status=expired']  = array( $list( array( 'product' => $top_product, 'status' => 'expired' ) ), false );
$scenarios['list: search email (full)']         = array( $search_list( $customer_email ), false );
$scenarios['list: search email fragment']       = array( $search_list( '@agentur.example' ), false );
$scenarios['list: search key prefix']           = array( $search_list( (string) $mid_license['key_prefix'] ), false );
$scenarios['list: search key fragment']         = array( $search_list( substr( (string) $mid_license['key_prefix'], 0, 9 ) ), false );
$scenarios['list: search full key']             = array( $search_list( $full_key ), false );
$scenarios['list: search #id']                  = array( $search_list( '#' . (int) $mid_license['id'] ), false );
$scenarios['list: search number']               = array( $search_list( (string) (int) $mid_license['id'] ), false );
$scenarios['list: search text (product)']       = array( $search_list( 'captain' ), false );
$scenarios['detail: busiest license']           = array( static function () use ( $as_admin, $busy_license ) {
	$as_admin();
	wplc_bench_admin_list( array( 'license' => $busy_license ) );
}, false );
$scenarios['detail: typical license']           = array( static function () use ( $as_admin, $mid_license ) {
	$as_admin();
	wplc_bench_admin_list( array( 'license' => (int) $mid_license['id'] ) );
}, false );
$scenarios['account: big customer page 1']      = array( static function () use ( $big_customer ) {
	wp_set_current_user( $big_customer );
	CustomerPortal::render( 1 );
}, false );
$scenarios['account: big customer last page']   = array( static function () use ( $big_customer ) {
	wp_set_current_user( $big_customer );
	CustomerPortal::render( 9999 );
}, false );
$scenarios['account: small customer']           = array( static function () use ( $small_customer ) {
	wp_set_current_user( $small_customer );
	CustomerPortal::render( 1 );
}, false );
$scenarios['job: expire_due_licenses']          = array( array( LicenseLifecycle::class, 'expire_due_licenses' ), true );
$scenarios['job: requeue_overdue_renewals']     = array( array( RenewalProcessor::class, 'requeue_overdue_renewals' ), true );
$scenarios['job: reconcile_stuck_renewals']     = array( array( RenewalProcessor::class, 'reconcile_stuck_renewals' ), true );
$scenarios['job: send_due_reminders']           = array( array( Notifications::class, 'send_due_reminders' ), true );
$scenarios['job: retention run']                = array( array( Retention::class, 'run' ), true );
$scenarios['job: cleanup_security_rows']        = array( array( Authenticator::class, 'cleanup_security_rows' ), true );
$scenarios['job: purge_expired_grants']         = array( array( UpdateService::class, 'purge_expired_grants' ), true );
$scenarios['diagnostics: metrics']              = array( array( Diagnostics::class, 'metrics' ), false );
$scenarios['review: open_count + open_cases']   = array( static function () {
	RenewalReview::open_count();
	RenewalReview::open_cases();
}, false );
if ( method_exists( PackageStream::class, 'purge_expired' ) ) {
	$scenarios['job: package stream purge'] = array( array( PackageStream::class, 'purge_expired' ), true );
}

// ---------------------------------------------------------------- run
$result = array( 'meta' => array( 'date' => gmdate( 'c' ), 'schema' => get_option( Schema::VERSION_OPTION ), 'counts' => array() ), 'scenarios' => array() );
foreach ( $tables as $name => $table ) {
	$result['meta']['counts'][ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}
$result['meta']['counts']['users'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
WP_CLI::log( 'Rows: ' . wp_json_encode( $result['meta']['counts'] ) );

$explained = array();
foreach ( $scenarios as $name => $scenario ) {
	if ( '' !== $opt['only'] && false === stripos( $name, $opt['only'] ) ) {
		continue;
	}
	$res = wplc_bench_scenario( $scenario[0], max( 1, $opt['runs'] ), $scenario[1] );
	if ( isset( $res['error'] ) ) {
		WP_CLI::warning( "$name: {$res['error']}" );
		continue;
	}
	WP_CLI::log( sprintf( "\n== %s: %.1f ms wall, %d queries, %.1f ms db", $name, $res['wall_ms'], $res['query_count'], $res['db_ms'] ) );
	$shown = array();
	foreach ( $res['queries'] as $i => $q ) {
		$short = wplc_bench_short( $q['sql'] );
		$norm  = preg_replace( "/'[^']*'|\\b\\d+\\b/", '?', $short );
		if ( isset( $shown[ $norm ] ) ) {
			++$shown[ $norm ];
			continue; // Same statement with other values (per-row lookups): explained once.
		}
		$shown[ $norm ] = 1;
		if ( $opt['explain'] ) {
			$key = md5( $q['sql'] );
			if ( ! isset( $explained[ $key ] ) ) {
				$explained[ $key ] = wplc_bench_explain( $q['sql'] );
			}
			$res['queries'][ $i ] += $explained[ $key ];
			$e = $explained[ $key ];
			WP_CLI::log( sprintf( '  %7.2f ms  read %7d  %s', $e['ms'], $e['rows_read'], wplc_bench_short( $q['sql'] ) ) );
			foreach ( $e['plan'] as $line ) {
				WP_CLI::log( '                         ' . $line );
			}
		} else {
			WP_CLI::log( sprintf( '  %7.2f ms  %s', $q['time_ms'], wplc_bench_short( $q['sql'] ) ) );
		}
	}
	foreach ( $shown as $norm => $count ) {
		if ( $count > 1 ) {
			WP_CLI::log( sprintf( '  (x%d) %s', $count, $norm ) );
		}
	}
	$result['scenarios'][ $name ] = $res;
}

// ---------------------------------------------------------------- real HTTP
if ( $opt['http'] > 0 ) {
	putenv( 'WPLC_E2E_PORT=' . $opt['http'] );
	$log = getenv( 'WPLC_LOAD_QUERY_LOG' ) ?: '';
	$read_log = static function () use ( $log ): array {
		static $offset = 0;
		if ( '' === $log || ! is_file( $log ) ) {
			return array();
		}
		clearstatcache();
		$fh = fopen( $log, 'r' );
		fseek( $fh, $offset );
		$lines = array();
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$lines[] = json_decode( $line, true );
		}
		$offset = ftell( $fh );
		fclose( $fh );
		return array_values( array_filter( $lines ) );
	};
	$cookies_for = static function ( int $user_id ): array {
		$expiration = time() + DAY_IN_SECONDS;
		$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		return array(
			new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ) ) ),
			new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ) ) ),
		);
	};
	$http_runs = max( 3, $opt['runs'] );
	$http = static function ( string $name, callable $request ) use ( $http_runs, $read_log, &$result ) {
		$read_log();
		$walls = array();
		for ( $i = 0; $i < $http_runs; $i++ ) {
			$t = microtime( true );
			$code = $request();
			$walls[] = ( microtime( true ) - $t ) * 1000;
		}
		sort( $walls );
		$server = $read_log();
		$last   = $server ? end( $server ) : array( 'queries' => array(), 'wall' => 0 );
		$db     = 0.0;
		foreach ( $last['queries'] as $q ) {
			$db += $q['time'] * 1000;
		}
		$relevant = wplc_bench_relevant( $last['queries'] );
		WP_CLI::log( sprintf( "\n== HTTP %s: %.1f ms median (%d runs, last status %s), server %.1f ms, %d queries, %.1f ms db", $name, $walls[ (int) floor( count( $walls ) / 2 ) ], $http_runs, (string) $code, $last['wall'] * 1000, count( $last['queries'] ), $db ) );
		foreach ( $relevant as $q ) {
			WP_CLI::log( sprintf( '  %7.2f ms  %s', $q['time_ms'], wplc_bench_short( $q['sql'] ) ) );
		}
		$result['scenarios'][ 'HTTP ' . $name ] = array( 'wall_ms' => $walls[ (int) floor( count( $walls ) / 2 ) ], 'server_ms' => $last['wall'] * 1000, 'db_ms' => $db, 'query_count' => count( $last['queries'] ), 'queries' => $relevant );
	};
	$admin_cookies = $cookies_for( $admin );
	$get_page = static function ( string $path, array $cookies ) {
		return static function () use ( $path, $cookies ) {
			$response = wp_remote_get( 'https://shop.test' . $path, array( 'cookies' => $cookies, 'timeout' => 60, 'redirection' => 0 ) );
			return is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response );
		};
	};
	$http( 'admin list page (default)', $get_page( '/wp-admin/admin.php?page=wplc-licenses', $admin_cookies ) );
	$http( 'admin list page (status=active, paid_until asc)', $get_page( '/wp-admin/admin.php?page=wplc-licenses&status=active&orderby=paid_until&order=asc', $admin_cookies ) );
	$http( 'admin detail page (busiest license)', $get_page( '/wp-admin/admin.php?page=wplc-licenses&license=' . $busy_license, $admin_cookies ) );
	$http( 'account licenses page (big customer)', $get_page( '/my-account/wplc-licenses/', $cookies_for( $big_customer ) ) );

	// REST through the bundled client: a fresh installation on a seeded license.
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT l.* FROM {$tables['licenses']} l JOIN {$tables['entitlements']} e ON e.license_id = l.id AND e.entitlement_key = 'sites.live_limit' WHERE l.status = 'active' AND l.paid_until > %s AND e.value_json + 0 >= 10 ORDER BY l.id DESC LIMIT 1",
			gmdate( 'Y-m-d H:i:s' )
		),
		ARRAY_A
	);
	if ( ! $license ) {
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE status = 'active' AND paid_until > %s AND product_key = %s ORDER BY id DESC LIMIT 1", gmdate( 'Y-m-d H:i:s' ), $top_product ), ARRAY_A );
	}
	$key  = LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] );
	$home = '';
	add_filter( 'home_url', static function ( $url, $path ) use ( &$home ) {
		return '' !== $home && ( '' === $path || '/' === $path ) ? $home : $url;
	}, 10, 2 );
	// Free the license's live sites so the activations below succeed.
	$wpdb->query( $wpdb->prepare( "UPDATE {$tables['activations']} SET status = 'deactivated', deactivated_at = %s WHERE license_id = %d AND status = 'active'", gmdate( 'Y-m-d H:i:s' ), (int) $license['id'] ) );
	$n = 0;
	$client = null;
	$http( 'REST activate (incl. site-limit count)', static function () use ( &$home, &$n, &$client, $key, $license ) {
		++$n;
		$home   = 'https://load-bench-' . $n . '-' . wp_rand() . '.example';
		$option = 'wplc_load_bench_client_' . $n;
		delete_option( $option );
		$client = new LicenseClient( 'https://shop.test', (string) $license['product_key'], '1.0.0', $option );
		$res    = $client->activate( $key );
		if ( is_wp_error( $res ) ) {
			WP_CLI::warning( 'activate: ' . $res->get_error_code() . ' ' . $res->get_error_message() );
			return $res->get_error_code();
		}
		return 200;
	} );
	$http( 'REST entitlements check (incl. nonce insert)', static function () use ( &$client ) {
		$res = $client->check_entitlements();
		return is_wp_error( $res ) ? $res->get_error_code() : 200;
	} );
	$http( 'REST update check', static function () use ( &$client ) {
		$res = $client->check_update();
		return is_wp_error( $res ) ? $res->get_error_code() : 200;
	} );
	for ( $i = 1; $i <= $n; $i++ ) {
		delete_option( 'wplc_load_bench_client_' . $i );
	}
}

if ( '' !== $opt['json'] ) {
	file_put_contents( $opt['json'], wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	WP_CLI::log( "\nJSON written to {$opt['json']}" );
}
