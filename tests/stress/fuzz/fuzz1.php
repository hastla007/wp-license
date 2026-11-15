<?php
// Pure classes only: runs with the unit bootstrap, no WordPress and no database.
require dirname( __DIR__, 2 ) . '/bootstrap.php';
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return 'salt-' . $s; } }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
use WPLC\Billing\Money; use WPLC\Api\V1\SiteUrl; use WPLC\Api\V1\ClientIp; use WPLC\License\LicenseKey; use WPLC\License\LicenseStateMachine as SM; use WPLC\License\LicenseLifecycle as LL;
use WPLC\Billing\RenewalScheduler; use WPLC\Billing\ManualRenewal; use WPLC\Notifications\ReminderSchedule as RS; use WPLC\Notifications\Placeholders; use WPLC\Security\SecretCrypto; use WPLC\Security\ResponseSigner;
error_reporting( E_ALL ); set_error_handler( function ( $n, $s, $f, $l ) { throw new ErrorException( $s, 0, $n, $f, $l ); } );
$fail = array(); $cnt = array();
function F( $area, $msg ) { global $fail; $fail[ $area ][] = $msg; }
function C( $area, $n = 1 ) { global $cnt; $cnt[ $area ] = ( $cnt[ $area ] ?? 0 ) + $n; }
mt_srand( 12345 );

// ---- Money
// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- a hexadecimal string is one of the inputs under test.
foreach ( array( '', ' ', '0.0', '0', '00', '007.50', '1e3', '1E3', '1,5', '1.234,56', '1,234.56', ' 12.5 ', "12.5\n", "\t12.5", '-1', '+1', '.5', '5.', '0x10', '１２', '12.5 EUR', '9223372036854775807', '9223372036854775808', '999999999999.999999', '999999999999.9999995', '1234567890123', '0.005', '0.0049999', '0.995', '99.995', 'NaN', 'INF', '1 000', "1\0" ) as $in ) {
	foreach ( array( -1, 0, 2, 3, 6, 7 ) as $d ) { try { $r = Money::to_minor_units( $in, $d ); C( 'Money::to_minor_units edge' ); $GLOBALS['mm'][] = json_encode( $in ) . " d=$d => " . json_encode( $r ); } catch ( Throwable $e ) { F( 'Money', "to_minor_units(" . json_encode( $in ) . ",$d) threw " . $e->getMessage() ); } }
}
for ( $i = 0; $i < 100000; $i++ ) {
	$d = mt_rand( 0, 6 ); $minor = mt_rand( 0, 1 ) ? mt_rand( 0, 999999 ) : mt_rand( 0, PHP_INT_MAX >> mt_rand( 0, 40 ) );
	$dec = Money::to_decimal( $minor, $d ); $back = Money::to_minor_units( $dec, $d ); C( 'Money round trip' );
	if ( $back !== $minor ) { if ( strlen( explode( '.', $dec )[0] ) > 12 ) { C( 'Money >12 whole digits (documented false)' ); continue; } F( 'Money', "roundtrip $minor d=$d dec=$dec back=" . json_encode( $back ) ); }
}
// half-up vs bcmath-free reference using string arithmetic
for ( $i = 0; $i < 100000; $i++ ) {
	$d = mt_rand( 0, 6 ); $whole = (string) mt_rand( 0, 999999 ); $fl = mt_rand( 0, 9 ); $frac = ''; for ( $k = 0; $k < $fl; $k++ ) { $frac .= mt_rand( 0, 9 ); }
	$in = $whole . ( $fl ? '.' . $frac : '' ); $r = Money::to_minor_units( $in, $d ); C( 'Money rounding vs reference' );
	$padded = str_pad( $frac, $d + 1, '0' ); $ref = (int) ( $whole . substr( $padded, 0, $d ) ) + ( (int) $padded[ $d ] >= 5 ? 1 : 0 );
	if ( $r !== $ref ) { F( 'Money', "to_minor_units($in,$d)=" . json_encode( $r ) . " ref=$ref" ); }
}
foreach ( array( array( PHP_INT_MAX, 1, 2 ), array( PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MAX ), array( 100, 1, 3 ), array( 100, 2, 3 ), array( 1, 1, 2 ), array( 3, 1, 2 ), array( 0, 5, 0 ), array( -1, 1, 1 ), array( 5, -1, 1 ), array( 5, 10, 5 ), array( 1, PHP_INT_MAX, PHP_INT_MAX ), array( 3037000500, 3037000500, 3037000501 ) ) as $a ) { try { $GLOBALS['mm'][] = 'prorate(' . implode( ',', $a ) . ')=' . json_encode( Money::prorate( ...$a ) ); C( 'Money::prorate edge' ); } catch ( Throwable $e ) { F( 'Money', 'prorate(' . implode( ',', $a ) . ') threw ' . $e->getMessage() ); } }
for ( $i = 0; $i < 100000; $i++ ) { $amt = mt_rand( 0, 10000000 ); $tot = mt_rand( 1, 40000000 ); $rem = mt_rand( 0, $tot ); $r = Money::prorate( $amt, $rem, $tot ); C( 'Money::prorate random' ); $ref = intdiv( 2 * $amt * $rem + $tot, 2 * $tot ); if ( $r !== $ref || $r < 0 || $r > $amt ) { F( 'Money', "prorate($amt,$rem,$tot)=$r ref=$ref" ); } }
foreach ( array( PHP_INT_MIN, -1, -100, 0, 5 ) as $m ) { foreach ( array( 0, 2, 6 ) as $d ) { $GLOBALS['mm'][] = "to_decimal($m,$d)=" . Money::to_decimal( $m, $d ); } }

// ---- SiteUrl idempotence
$hosts = array( 'example.com', 'EXAMPLE.com.', 'münchen.de', 'xn--mnchen-3ya.de', 'straße.de', '[::1]', '[0:0:0:0:0:0:0:1]', '[FE80::1]', '[fe80::1%25eth0]', '127.0.0.1', '0x7f.1', '192.168.001.010', 'a_b.com', '-a.com', 'a..b.com', 'localhost', 'пример.рф', 'ex ample.com', "exa\x01mple.com", 'example.com:80', 'example.com:443', 'example.com:0', 'example.com:65536', 'example.com:8080', 'user@example.com', 'u:p@example.com', '', '.', '[]', 'a.b.c.d.e.f.g', str_repeat( 'a', 63 ) . '.com', str_repeat( 'a', 64 ) . '.com', "ex\u{200B}ample.com", "ｅｘａｍｐｌｅ.com", 'EXAMPLE。com', '1.2.3.4.', '[::ffff:1.2.3.4]', '[::1]:8080' );
$paths = array( '', '/', '//', '/shop', '/shop/', '/shop//', '/Shop', '/a%2Fb', '/a%2fb', '/ä', '/😀', '/a b', '/a /', ' /', "/a\x01", "/a\tb", '/a\\b', '/../x', '/./x', '/a?x=1', '/a#f', '/%00', "/a\x7f", '/' . str_repeat( 'ä', 120 ), '/' . str_repeat( 'a', 230 ), '/a/ ', "/a\u{00A0}", '/a%20', "/a/\n" );
$schemes = array( 'https://', 'http://', 'HTTPS://', 'Http://', 'ftp://', '//', '', 'https:/', 'https:///', ' https://', "https://\t" );
$nonidem = array();
foreach ( $schemes as $s ) { foreach ( $hosts as $h ) { foreach ( $paths as $p ) {
	$u = $s . $h . $p; C( 'SiteUrl normalize idempotence (matrix)' );
	try { $n1 = SiteUrl::normalize( $u ); $n2 = '' === $n1 ? '' : SiteUrl::normalize( $n1 ); } catch ( Throwable $e ) { F( 'SiteUrl', 'threw on ' . json_encode( $u ) . ': ' . $e->getMessage() ); continue; }
	if ( $n1 !== $n2 ) { $nonidem[ json_encode( $n1 ) . ' -> ' . json_encode( $n2 ) ] = json_encode( $u ); }
	if ( strlen( $n1 ) > 255 ) { F( 'SiteUrl', 'len>255' ); }
	if ( '' !== $n1 && preg_match( '/[\x00-\x20\x7f]/', $n1 ) ) { $GLOBALS['ctl'][ json_encode( $n1 ) ] = json_encode( $u ); }
} } }
for ( $i = 0; $i < 100000; $i++ ) { $len = mt_rand( 1, 40 ); $u = 'http' . ( mt_rand( 0, 1 ) ? 's' : '' ) . '://'; $alpha = "abcXYZ019.-_:/[]@%?# \t\\äß😀\x01\x7f"; $chars = preg_split( '//u', $alpha, -1, PREG_SPLIT_NO_EMPTY ); for ( $k = 0; $k < $len; $k++ ) { $u .= $chars[ mt_rand( 0, count( $chars ) - 1 ) ]; }
	C( 'SiteUrl normalize idempotence (random)' ); try { $n1 = SiteUrl::normalize( $u ); $n2 = '' === $n1 ? '' : SiteUrl::normalize( $n1 ); } catch ( Throwable $e ) { F( 'SiteUrl', 'threw on ' . json_encode( $u ) . ': ' . $e->getMessage() ); continue; }
	if ( $n1 !== $n2 && count( $nonidem ) < 400 ) { $nonidem[ json_encode( $n1 ) . ' -> ' . json_encode( $n2 ) ] = json_encode( $u ); } }
$GLOBALS['nonidem'] = $nonidem;

// ---- ClientIp
$ips = array( '1.2.3.4', ' 1.2.3.4 ', '1.2.3.4:80', '[::1]', '[::1]:443', '::ffff:1.2.3.4', '[::ffff:1.2.3.4]:80', '::FFFF:0102:0304', '0:0:0:0:0:ffff:102:304', '256.1.1.1', '1.2.3', '01.02.03.04', '1.2.3.4:999999', 'fe80::1%eth0', '::', '0.0.0.0', 'unknown', '', 'for=1.2.3.4', '"1.2.3.4"', "1.2.3.4\n", '1.2.3.4, 5.6.7.8', '::1:80', '2001:db8::1', '[2001:db8::1' );
foreach ( $ips as $ip ) { try { $n = ClientIp::normalize( $ip ); C( 'ClientIp::normalize' ); if ( '' !== $n && ClientIp::normalize( $n ) !== $n ) { F( 'ClientIp', "normalize not idempotent $ip" ); } $b = ClientIp::rate_limit_bucket( $ip ); $GLOBALS['ipn'][] = json_encode( $ip ) . ' => ' . json_encode( $n ) . ' bucket ' . json_encode( $b ); } catch ( Throwable $e ) { F( 'ClientIp', 'normalize threw ' . json_encode( $ip ) . $e->getMessage() ); } }
$cidr = array( array( '1.2.3.4', '0.0.0.0/0', true ), array( '1.2.3.4', '1.2.3.4/32', true ), array( '1.2.3.5', '1.2.3.4/32', false ), array( '1.2.3.5', '1.2.3.4/31', true ), array( '::1', '::/0', true ), array( '::1', '::1/128', true ), array( '::2', '::1/128', false ), array( '1.2.3.4', '::/0', false ), array( '1.2.3.4', '1.2.3.4/33', false ), array( '1.2.3.4', '1.2.3.4/-1', false ), array( '1.2.3.4', '1.2.3.4/', false ), array( '1.2.3.4', '1.2.3.4/08', true ), array( '::ffff:10.0.0.1', '10.0.0.0/8', true ), array( '10.0.0.1', '::ffff:10.0.0.0/104', true ), array( '10.0.0.1', '::ffff:0:0/96', true ), array( '10.1.2.3', '10.0.0.0/8 ', true ), array( '10.1.2.3', ' 10.0.0.0 / 8', false ), array( '1.2.3.4', '1.2.3.4/99999999999999999999', false ) );
foreach ( $cidr as $c ) { try { $r = ClientIp::in_range( $c[0], $c[1] ); C( 'ClientIp::in_range CIDR' ); if ( $r !== $c[2] ) { $GLOBALS['cidrdiff'][] = "in_range({$c[0]}, {$c[1]}) = " . json_encode( $r ) . ' expected ' . json_encode( $c[2] ); } } catch ( Throwable $e ) { F( 'ClientIp', 'in_range threw ' . json_encode( $c ) . $e->getMessage() ); } }
$hdrs = array( '1.1.1.1', '1.1.1.1, 10.0.0.1', 'garbage, 10.0.0.1', '1.1.1.1, garbage', ',,,', '10.0.0.1, 10.0.0.2', str_repeat( '9.9.9.9, ', 50000 ) . '10.0.0.5', '1.1.1.1,10.0.0.1 ', ' ', "1.1.1.1\r\nX: y", '8.8.8.8, 1.1.1.1, 10.0.0.9', '[2001:db8::1]:443, 10.0.0.1', '::ffff:10.0.0.7, 10.0.0.1', '10.0.0.1, 1.1.1.1' );
foreach ( $hdrs as $h ) { try { $r = ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => $h ), 'X-Forwarded-For', array( '10.0.0.0/8' ) ); $r2 = ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => $h ), 'X-Forwarded-For', null ); C( 'ClientIp::resolve header', 2 ); $GLOBALS['hdr'][] = json_encode( substr( $h, 0, 50 ) ) . ' => trusted-list:' . $r . ' | trust-all:' . $r2; } catch ( Throwable $e ) { F( 'ClientIp', 'resolve threw ' . $e->getMessage() ); } }
$GLOBALS['hdr'][] = 'array header => ' . ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => array( '1.1.1.1' ) ), 'X-Forwarded-For', null );
$GLOBALS['hdr'][] = 'untrusted peer => ' . ClientIp::resolve( array( 'REMOTE_ADDR' => '9.9.9.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1' ), 'X-Forwarded-For', array( '10.0.0.0/8' ) );
$GLOBALS['hdr'][] = 'empty proxies list => ' . ClientIp::resolve( array( 'REMOTE_ADDR' => '9.9.9.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1' ), 'X-Forwarded-For', array() );
$GLOBALS['hdr'][] = 'proxy list 0.0.0.0/0, all hops trusted => ' . json_encode( ClientIp::resolve( array( 'REMOTE_ADDR' => '9.9.9.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 2.2.2.2' ), 'X-Forwarded-For', array( '0.0.0.0/0' ) ) );

// ---- LicenseKey
$u = '0b1c2d3e-4f50-4a61-8b72-9c8d7e6f5a4b';
$k = LicenseKey::for_uuid( $u ); $lk = array();
$lk[] = 'upper uuid same key: ' . json_encode( LicenseKey::for_uuid( strtoupper( $u ) ) === $k );
$lk[] = 'hash trims/uppercases: ' . json_encode( LicenseKey::hash( " \t" . strtolower( $k ) . "\n" ) === LicenseKey::hash( $k ) );
$lk[] = 'hash inner space differs: ' . json_encode( LicenseKey::hash( substr( $k, 0, 10 ) . ' ' . substr( $k, 10 ) ) !== LicenseKey::hash( $k ) );
$lk[] = 'lookalike O/0: ' . json_encode( LicenseKey::hash( str_replace( '0', 'O', $k ) ) !== LicenseKey::hash( $k ) );
$lk[] = 'unknown key id: ' . json_encode( LicenseKey::for_uuid( $u, 'nope' ) );
$lk[] = 'empty key id: ' . json_encode( LicenseKey::for_uuid( $u, '' ) );
$lk[] = 'non-uuid: ' . substr( LicenseKey::for_uuid( "not a uuid\n<script>" ), 0, 40 );
$lk[] = 'empty uuid: ' . substr( LicenseKey::for_uuid( '' ), 0, 20 );
$lk[] = 'whitespace uuid differs from trimmed: ' . json_encode( LicenseKey::for_uuid( " $u " ) !== $k );
$lk[] = 'prefix non-uuid: ' . json_encode( LicenseKey::prefix( '<b>x</b>--' ) ) . ' ' . json_encode( LicenseKey::prefix( '' ) ) . ' ' . json_encode( LicenseKey::prefix( 'äöü😀abcdefgh' ) );
$lk[] = 'matches_hash garbage: ' . json_encode( array( LicenseKey::matches_hash( $u, 'test-2026', LicenseKey::hash( $k ) ), LicenseKey::matches_hash( $u, 'test-2026', '' ), LicenseKey::matches_hash( $u, 'test-2025', LicenseKey::hash( $k ) ), LicenseKey::matches_hash( $u, 'test-2026', strtoupper( LicenseKey::hash( $k ) ) ), LicenseKey::matches_hash( $u, 'test-2026', LicenseKey::hash( $k ) . "\n" ) ) );
$t0 = microtime( true ); LicenseKey::hash( str_repeat( 'A', 20000000 ) ); LicenseKey::for_uuid( str_repeat( 'a', 5000000 ) ); $lk[] = '20MB input ms: ' . round( ( microtime( true ) - $t0 ) * 1000 ); C( 'LicenseKey edge', 14 );
$GLOBALS['lk'] = $lk;

// ---- StateMachine matrix
$states = array( 'pending', 'active', 'past_due', 'suspended', 'expired', 'revoked', '', 'ACTIVE', 'unknown' ); $actions = array( 'order_refund', 'renewal_failed', 'renewal_succeeded', 'chargeback', 'license_expired', 'admin_status_change', 'admin_extend', '', 'bogus' );
$out_edges = array(); $selfloops = array();
foreach ( $states as $f ) { foreach ( $states as $t ) { foreach ( $actions as $a ) { C( 'StateMachine triples' ); if ( SM::allows( $f, $t, $a ) ) { $out_edges[ $f ][ $t ][] = $a; if ( $f === $t ) { $selfloops[] = "$f --$a--> $t"; } } } } }
$GLOBALS['sm'] = array( 'self' => $selfloops, 'edges' => $out_edges );
foreach ( $states as $s ) { foreach ( array( 0, -1, 1, PHP_INT_MAX, PHP_INT_MAX - 30 * 86400 + 1 ) as $pu ) { foreach ( array( 0, -1, PHP_INT_MAX ) as $pa ) { try { SM::can_apply_manual_renewal( $s, $pu, $pa ); C( 'StateMachine manual renewal edge' ); } catch ( Throwable $e ) { F( 'StateMachine', "can_apply_manual_renewal($s,$pu,$pa) threw " . get_class( $e ) . ' ' . $e->getMessage() ); } } } }
$GLOBALS['smx'][] = 'expired, paid_until=PHP_INT_MAX-100, paid_at=PHP_INT_MAX => ' . json_encode( SM::can_apply_manual_renewal( 'expired', PHP_INT_MAX - 100, PHP_INT_MAX ) );

// ---- has_access / entitlement_cutoff
$now = 1800000000; $ha = array();
$dates = array( 'null' => null, 'empty' => '', 'zero' => '0000-00-00 00:00:00', 'garbage' => 'not a date', 'now-1' => gmdate( 'Y-m-d H:i:s', $now - 1 ), 'now' => gmdate( 'Y-m-d H:i:s', $now ), 'now+1' => gmdate( 'Y-m-d H:i:s', $now + 1 ), 'grace-1' => gmdate( 'Y-m-d H:i:s', $now - 14 * 86400 + 1 ), 'grace' => gmdate( 'Y-m-d H:i:s', $now - 14 * 86400 ), '2038' => '2038-01-19 03:14:08', '9999' => '9999-12-31 23:59:59', 'date only' => '2030-01-01', 'iso T' => '2030-01-01T00:00:00', 'tz suffix' => '2030-01-01 00:00:00+02:00', 'relative!' => 'tomorrow', 'relative2' => '+1 year', 'int 0' => 0, 'int' => 1900000000, 'str 0' => '0', 'now word' => 'now' );
foreach ( array( 'pending', 'active', 'past_due', 'suspended', 'expired', 'revoked', '', 'Active' ) as $st ) { foreach ( array( 0, 1 ) as $re ) { foreach ( array( 0, 1 ) as $ca ) { foreach ( $dates as $dl => $dv ) {
	$l = array( 'status' => $st, 'renewal_enabled' => $re, 'cancel_at_period_end' => $ca, 'paid_until' => $dv ); C( 'has_access/entitlement_cutoff matrix' );
	try { $acc = LL::has_access( $l, $now ); $cut = LL::entitlement_cutoff( $l, $now ); } catch ( Throwable $e ) { F( 'Lifecycle', "has_access threw st=$st date=$dl: " . $e->getMessage() ); continue; }
	if ( $acc && ! in_array( $st, array( 'active', 'past_due' ), true ) ) { F( 'Lifecycle', "access for status $st" ); }
	if ( $acc && in_array( $dl, array( 'garbage', 'zero', 'relative!', 'relative2', 'now word', 'empty', 'null', 'int 0', 'str 0', 'tz suffix', 'int' ), true ) ) { $ha[ "$dl / $st" ] = "access=true (renew=$re cancel=$ca) cutoff=$cut"; }
	if ( ! preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $cut ) ) { F( 'Lifecycle', "cutoff format $cut" ); }
} } } }
$GLOBALS['ha'] = $ha;

// ---- RenewalScheduler::period_end
$bad = 0; $drift = array();
foreach ( array( 'month', 'year', '', 'week', 'MONTH' ) as $iv ) {
	for ( $y = 2024; $y < 2044; $y++ ) { for ( $m = 1; $m <= 12; $m++ ) { $dim = cal_days_in_month( CAL_GREGORIAN, $m, $y ); for ( $d = 1; $d <= $dim; $d++ ) { foreach ( array( '00:00:00', '23:59:59', '02:30:00' ) as $tm ) {
		$ts = strtotime( sprintf( '%04d-%02d-%02d %s UTC', $y, $m, $d, $tm ) ); $e = RenewalScheduler::period_end( $ts, $iv ); C( 'RenewalScheduler::period_end' );
		$len = $e - $ts; $exp_min = 'month' === $iv ? 28 * 86400 : 365 * 86400; $exp_max = 'month' === $iv ? 31 * 86400 : 366 * 86400;
		if ( $len < $exp_min || $len > $exp_max || gmdate( 'H:i:s', $e ) !== $tm ) { $bad++; if ( $bad < 5 ) { F( 'RenewalScheduler', "period_end($y-$m-$d $tm, '$iv') len=" . ( $len / 86400 ) ); } }
	} } } }
}
// monotonic
foreach ( array( 'month', 'year' ) as $iv ) { $prev = 0; for ( $ts = strtotime( '2024-01-01 UTC' ); $ts < strtotime( '2044-01-01 UTC' ); $ts += 3 * 3600 + 7 ) { $e = RenewalScheduler::period_end( $ts, $iv ); C( 'period_end monotonic' ); if ( $e < $prev ) { F( 'RenewalScheduler', "non-monotonic at " . gmdate( 'c', $ts ) . " $iv: " . gmdate( 'c', $e ) . ' < ' . gmdate( 'c', $prev ) ); break; } $prev = $e; } }
foreach ( array( -1, 0, PHP_INT_MAX, 253402300799, 253402300800, 2147483647, 2147483648 ) as $ts ) { try { $GLOBALS['pe'][] = "period_end($ts,year)=" . RenewalScheduler::period_end( $ts, 'year' ) . ' ' . @gmdate( 'Y-m-d H:i:s', RenewalScheduler::period_end( $ts, 'year' ) ); } catch ( Throwable $e ) { $GLOBALS['pe'][] = "period_end($ts) THROWS " . get_class( $e ) . ': ' . $e->getMessage(); } }
$GLOBALS['pe'][] = 'idempotency_key attempt -1: ' . RenewalScheduler::attempt_idempotency_key( 'k', -1 );

// ---- ManualRenewal::in_window, next_paid_until
$w = 30 * 86400; $pu = 1800000000; $puS = gmdate( 'Y-m-d H:i:s', $pu ); $mw = array();
foreach ( array( $pu - $w - 1 => false, $pu - $w => true, $pu => true, $pu + $w => true, $pu + $w + 1 => false ) as $t => $exp ) { C( 'ManualRenewal::in_window' ); if ( ManualRenewal::in_window( array( 'id' => 1, 'status' => 'active', 'paid_until' => $puS ), $t ) !== $exp ) { F( 'ManualRenewal', "in_window at offset " . ( $t - $pu ) ); } }
foreach ( array( 'suspended', 'revoked', 'pending', '' ) as $st ) { if ( ManualRenewal::in_window( array( 'id' => 1, 'status' => $st, 'paid_until' => $puS ), $pu ) ) { F( 'ManualRenewal', "window open for $st" ); } }
foreach ( array( null, '', 'garbage', '0000-00-00 00:00:00', '1970-01-01 00:00:00', 'tomorrow' ) as $dv ) { C( 'ManualRenewal::in_window' ); $mw[] = 'in_window paid_until=' . json_encode( $dv ) . ' now=time => ' . json_encode( ManualRenewal::in_window( array( 'id' => 1, 'status' => 'active', 'paid_until' => $dv ), time() ) ); }
$mw[] = 'in_window id=0 => ' . json_encode( ManualRenewal::in_window( array( 'id' => 0, 'status' => 'active', 'paid_until' => $puS ), $pu ) ) . ', id="0" => ' . json_encode( ManualRenewal::in_window( array( 'id' => '0', 'status' => 'active', 'paid_until' => $puS ), $pu ) );
$mw[] = 'next_paid_until("", year, 0) => ' . ManualRenewal::next_paid_until( '', 'year', 0 ) . '; garbage => ' . ManualRenewal::next_paid_until( 'garbage', 'year', 1800000000 ) . '; paid late => ' . ManualRenewal::next_paid_until( $puS, 'month', $pu + 29 * 86400 ) . ' (paid_until ' . $puS . ')';
$GLOBALS['mw'] = $mw;

// ---- ReminderSchedule
$rsx = array();
// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- a hexadecimal string is one of the inputs under test.
foreach ( array( '30, 7,,abc,-1,9999999999', '7,7,7', ' 7 , 30 ', '0', '365,366', '1e1', '0x1e', '７', '30;7', "30\n7", '+7', '007', '7.0', array( 7, '30', 7.5, true, null, array( 1 ), '5 ' ), null, 30, 7.0, true, new stdClass() ) as $v ) { try { $rsx[] = json_encode( is_object( $v ) ? 'object' : $v ) . ' => ' . json_encode( RS::parse_lead_days( $v ) ); C( 'ReminderSchedule::parse_lead_days' ); } catch ( Throwable $e ) { F( 'ReminderSchedule', 'parse threw ' . $e->getMessage() ); } }
$nowR = strtotime( '2026-06-15 12:00:00 UTC' );
foreach ( array( 'month', 'year' ) as $iv ) { foreach ( array( 0, 1 ) as $auto ) { for ( $dleft = -2; $dleft <= 40; $dleft++ ) { foreach ( array( 0, 43199, 43200, 86399 ) as $sec ) {
	$puR = strtotime( '2026-06-15 00:00:00 UTC' ) + $dleft * 86400 + $sec; $lic = array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $puR ), 'billing_interval' => $iv, 'renewal_enabled' => $auto, 'cancel_at_period_end' => 0, 'next_payment_at' => gmdate( 'Y-m-d H:i:s', $puR ) );
	try { $r = RS::due_reminder( $lic, $nowR, array( 30, 7 ), 7 ); C( 'ReminderSchedule::due_reminder' ); } catch ( Throwable $e ) { F( 'ReminderSchedule', 'due threw ' . $e->getMessage() ); continue; }
	if ( $r && $puR <= $nowR ) { F( 'ReminderSchedule', 'reminder for past' ); }
	if ( $r && $r['days_left'] < 0 ) { F( 'ReminderSchedule', 'negative days_left' ); }
	if ( $r && 0 === $r['days_left'] ) { $rsx['days_left0'] = 'days_left=0 possible: paid_until ' . $lic['paid_until'] . " type {$r['type']} lead {$r['lead']}"; }
} } } }
$rsx[] = 'monthly license, lead 30/7: ' . json_encode( array_map( function ( $d ) use ( $nowR ) { $r = RS::due_reminder( array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $nowR + $d * 86400 ), 'billing_interval' => 'month' ), $nowR, array( 30, 7 ), 7 ); return "$d:" . ( $r ? $r['marker'] : '-' ); }, array( 30, 15, 14, 8, 7, 1 ) ) );
$rsx[] = 'monthly auto-renew notice_days 7/14/15/16: ' . json_encode( array_map( function ( $n ) use ( $nowR ) { $r = RS::due_reminder( array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $nowR + 3 * 86400 ), 'billing_interval' => 'month', 'renewal_enabled' => 1 ), $nowR, array(), $n ); return "$n:" . ( $r ? 'notice' : '-' ); }, array( 7, 14, 15, 16, 30 ) ) );
$rsx[] = 'garbage next_payment_at: ' . json_encode( RS::due_reminder( array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $nowR + 3 * 86400 ), 'renewal_enabled' => 1, 'next_payment_at' => 'garbage' ), $nowR, array(), 7 ) );
$rsx[] = 'next_payment_at far before paid_until (past): ' . json_encode( RS::due_reminder( array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $nowR + 3 * 86400 ), 'renewal_enabled' => 1, 'next_payment_at' => gmdate( 'Y-m-d H:i:s', $nowR - 86400 ) ), $nowR, array(), 7 ) );
$rsx[] = 'max_window_days garbage: ' . RS::max_window_days( array( 'x', -5, 99999 ), -3 );
$GLOBALS['rsx'] = $rsx;

// ---- Placeholders
$pl = array();
$pl[] = Placeholders::render( '{product} {plan} {unknown} {PRODUCT} {{product}} {amount', array( 'product' => "A<b>B</b>\r\nBcc: x@y.z &amp; &lt;script&gt; {plan}", 'plan' => array( 'x' ), 'amount' => 12.5 ) );
$pl[] = json_encode( Placeholders::plain( "a\xC2\xA0b\x00c &#x0a;d &nbsp; <script>alert(1)</script> \xff\xfe" ) );
$pl[] = json_encode( Placeholders::plain( "Subject\u{2028}line\u{2029}two\u{0085}three" ) );
$pl[] = json_encode( Placeholders::plain( '&lt;img src=x onerror=1&gt;' ) );
C( 'Placeholders', 4 ); $GLOBALS['pl'] = $pl;

// ---- SecretCrypto
$sc = array();
foreach ( array( '', 'a', "\0\0\0", random_bytes( 64 ), str_repeat( 'x', 100000 ), "üñí😀", '0' ) as $pt ) { $enc = SecretCrypto::encrypt( $pt ); C( 'SecretCrypto roundtrip' ); if ( false === $enc ) { $sc[] = 'encrypt false for len ' . strlen( $pt ); continue; } $dec = SecretCrypto::decrypt( $enc ); if ( $dec !== $pt ) { $sc[] = 'ROUNDTRIP MISMATCH len ' . strlen( $pt ) . ' => ' . json_encode( $dec ); } }
$enc = SecretCrypto::encrypt( 'the-secret-value-0123456789abcdef' ); $wrong = 0; $thr = 0;
for ( $i = 0; $i < 20000; $i++ ) { $m = $enc; $mode = $i % 6;
	if ( 0 === $mode ) { $m = substr( $enc, 0, mt_rand( 0, strlen( $enc ) - 1 ) ); } elseif ( 1 === $mode ) { $p = mt_rand( 0, strlen( $enc ) - 1 ); $m[ $p ] = chr( mt_rand( 0, 255 ) ); } elseif ( 2 === $mode ) { $m = random_bytes( mt_rand( 1, 120 ) ); } elseif ( 3 === $mode ) { $m = 'v2:test-2026:' . substr( $enc, 13 ); } elseif ( 4 === $mode ) { $m = 'v1:test-2025:' . substr( $enc, 13 ); } else { $m = base64_encode( random_bytes( mt_rand( 1, 90 ) ) ); }
	try { $d = SecretCrypto::decrypt( $m ); C( 'SecretCrypto mutated decrypt' ); if ( false !== $d && $m !== $enc ) { $wrong++; if ( $wrong < 4 ) { $sc[] = 'decrypt accepted mutated envelope mode ' . $mode . ': ' . json_encode( substr( $m, 0, 30 ) ) . ' => ' . json_encode( $d ) . ' same_plain=' . json_encode( 'the-secret-value-0123456789abcdef' === $d ); } } } catch ( Throwable $e ) { $thr++; if ( $thr < 4 ) { $sc[] = 'decrypt THREW mode ' . $mode . ' ' . get_class( $e ) . ': ' . $e->getMessage(); } } }
$sc[] = "mutations accepted: $wrong, thrown: $thr";
$sc[] = 'needs_rotation: current=' . json_encode( SecretCrypto::needs_rotation( $enc ) ) . ' other=' . json_encode( SecretCrypto::needs_rotation( 'v1:test-2025:abc' ) ) . ' garbage=' . json_encode( SecretCrypto::needs_rotation( 'zzz' ) ) . ' newline=' . json_encode( SecretCrypto::needs_rotation( $enc . "\n" ) );
$sc[] = 'decrypt with trailing newline: ' . json_encode( SecretCrypto::decrypt( $enc . "\n" ) ) . ' ; leading space: ' . json_encode( SecretCrypto::decrypt( ' ' . $enc ) );
$GLOBALS['sc'] = $sc;

// ---- ResponseSigner
$rs = array(); $kp = sodium_crypto_sign_keypair(); $sk = sodium_crypto_sign_secretkey( $kp ); $pk = base64_encode( sodium_crypto_sign_publickey( $kp ) ); $msg = ResponseSigner::message( 'ABC', '{"a":1}' ); $sig = ResponseSigner::sign_with( $msg, $sk );
$rs[] = 'valid: ' . json_encode( ResponseSigner::verify( $msg, $sig, $pk ) );
foreach ( array( '', '!!!', substr( $sig, 0, -1 ), $sig . '=', $sig . "\n", ' ' . $sig, base64_encode( random_bytes( 63 ) ), base64_encode( random_bytes( 65 ) ), base64_encode( str_repeat( "\0", 64 ) ), strtr( $sig, '+/', '-_' ) ) as $bs ) { try { $v = ResponseSigner::verify( $msg, $bs, $pk ); C( 'ResponseSigner::verify malformed' ); if ( $v ) { $rs[] = 'ACCEPTED malformed sig ' . json_encode( $bs ); } } catch ( Throwable $e ) { $rs[] = 'verify threw ' . $e->getMessage(); } }
foreach ( array( '', 'x', base64_encode( random_bytes( 31 ) ), base64_encode( random_bytes( 33 ) ), base64_encode( str_repeat( "\0", 32 ) ), base64_encode( str_repeat( "\xff", 32 ) ) ) as $bk ) { try { $v = ResponseSigner::verify( $msg, $sig, $bk ); C( 'ResponseSigner::verify malformed' ); if ( $v ) { $rs[] = 'ACCEPTED bad key'; } } catch ( Throwable $e ) { $rs[] = 'verify threw ' . $e->getMessage(); } }
$rs[] = 'all-zero sig + all-zero (small-order) key on any message: ' . json_encode( ResponseSigner::verify( 'anything', base64_encode( str_repeat( "\0", 64 ) ), base64_encode( str_repeat( "\0", 32 ) ) ) );
$rs[] = 'message ambiguity: ' . json_encode( ResponseSigner::message( "a\nb", 'c' ) === ResponseSigner::message( 'a', "b\nc" ) );
foreach ( array( '', 'not base64!', base64_encode( 'short' ), base64_encode( random_bytes( 32 ) ), base64_encode( random_bytes( 64 ) ), base64_encode( random_bytes( 48 ) ), ' ' . base64_encode( random_bytes( 32 ) ) . "\n" ) as $cfg ) { try { $r = ResponseSigner::secret_key_from( $cfg ); C( 'ResponseSigner::secret_key_from' ); $extra = ''; if ( is_string( $r ) && 64 === strlen( $r ) ) { try { $s2 = ResponseSigner::sign_with( 'm', $r ); $extra = ' sig-verifies=' . json_encode( ResponseSigner::verify( 'm', $s2, ResponseSigner::public_key_of( $r ) ) ); } catch ( Throwable $e ) { $extra = ' SIGN THREW ' . $e->getMessage(); } } $rs[] = 'secret_key_from(len ' . strlen( (string) base64_decode( trim( $cfg ) ) ) . ') => ' . ( null === $r ? 'null' : strlen( $r ) . ' bytes' ) . $extra; } catch ( Throwable $e ) { $rs[] = 'secret_key_from threw ' . get_class( $e ) . ' ' . $e->getMessage(); } }
$GLOBALS['rs'] = $rs;

echo "==== FAILURES\n"; foreach ( $fail as $a => $list ) { echo "[$a] " . count( $list ) . "\n"; foreach ( array_slice( $list, 0, 12 ) as $l ) { echo "   $l\n"; } }
echo "==== COUNTS\n"; foreach ( $cnt as $a => $n ) { echo "  $a: $n\n"; }
foreach ( array( 'mm', 'nonidem', 'ctl', 'ipn', 'cidrdiff', 'hdr', 'lk', 'smx', 'ha', 'pe', 'mw', 'rsx', 'pl', 'sc', 'rs' ) as $g ) { echo "==== $g\n"; $v = $GLOBALS[ $g ] ?? array(); $n = 0; foreach ( $v as $kk => $vv ) { if ( ++$n > 70 ) { echo '   ... ' . count( $v ) . " total\n"; break; } echo '   ' . ( is_int( $kk ) ? '' : "$kk  <=  " ) . ( is_string( $vv ) ? substr( $vv, 0, 230 ) : json_encode( $vv ) ) . "\n"; } }
echo "==== sm self loops\n"; print_r( $GLOBALS['sm']['self'] ); foreach ( $GLOBALS['sm']['edges'] as $f => $ts ) { echo "  $f -> " . implode( ', ', array_map( function ( $t ) use ( $ts ) { return $t . '[' . implode( '|', $ts[ $t ] ) . ']'; }, array_keys( $ts ) ) ) . "\n"; }
