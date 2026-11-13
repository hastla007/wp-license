<?php
/**
 * Runs the blocking PHPCS ruleset (security + PHP compatibility) against a baseline.
 *
 * PHP_CodeSniffer has no baseline feature. The existing findings were reviewed
 * and recorded in phpcs-baseline.json as a count per file and sniff. This
 * script fails when a file has MORE findings of a sniff than recorded, i.e. on
 * every new finding, and stays green for the reviewed ones.
 *
 * Usage:
 *   php bin/phpcs-blocking.php            Compare against the baseline (CI).
 *   php bin/phpcs-blocking.php --update   Rewrite the baseline after a review.
 *
 * @package WPLC
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wplc_root     = dirname( __DIR__ );
$wplc_baseline = $wplc_root . '/phpcs-baseline.json';
$wplc_update   = in_array( '--update', $argv, true );
$wplc_phpcs    = $wplc_root . '/vendor/bin/phpcs';

if ( ! is_file( $wplc_phpcs ) ) {
	fwrite( STDERR, "vendor/bin/phpcs is missing. Run composer install first.\n" );
	exit( 2 );
}

$wplc_command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $wplc_phpcs )
	. ' --standard=' . escapeshellarg( $wplc_root . '/phpcs.blocking.xml' )
	. ' --basepath=' . escapeshellarg( $wplc_root )
	. ' --report=json -q';
$wplc_output  = array();
$wplc_status  = 0;
exec( $wplc_command, $wplc_output, $wplc_status );
$wplc_report = json_decode( implode( "\n", $wplc_output ), true );
if ( ! is_array( $wplc_report ) || ! isset( $wplc_report['files'] ) ) {
	fwrite( STDERR, "PHPCS did not return a JSON report (exit status {$wplc_status}):\n" . implode( "\n", $wplc_output ) . "\n" );
	exit( 2 );
}

$wplc_current = array();
$wplc_details = array();
foreach ( $wplc_report['files'] as $wplc_file => $wplc_data ) {
	$wplc_file = str_replace( '\\', '/', (string) $wplc_file );
	foreach ( $wplc_data['messages'] as $wplc_message ) {
		$wplc_source = (string) $wplc_message['source'];
		if ( ! isset( $wplc_current[ $wplc_file ][ $wplc_source ] ) ) {
			$wplc_current[ $wplc_file ][ $wplc_source ] = 0;
		}
		++$wplc_current[ $wplc_file ][ $wplc_source ];
		$wplc_details[ $wplc_file ][ $wplc_source ][] = sprintf( '%s:%d: %s', $wplc_file, (int) $wplc_message['line'], (string) $wplc_message['message'] );
	}
}
ksort( $wplc_current );
foreach ( $wplc_current as $wplc_file => $wplc_sources ) {
	ksort( $wplc_current[ $wplc_file ] );
}

if ( $wplc_update ) {
	file_put_contents( $wplc_baseline, json_encode( $wplc_current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	$wplc_total = 0;
	foreach ( $wplc_current as $wplc_sources ) {
		$wplc_total += array_sum( $wplc_sources );
	}
	echo "phpcs-baseline.json updated: {$wplc_total} accepted finding(s) in " . count( $wplc_current ) . " file(s).\n";
	exit( 0 );
}

$wplc_accepted = is_file( $wplc_baseline ) ? json_decode( (string) file_get_contents( $wplc_baseline ), true ) : array();
if ( ! is_array( $wplc_accepted ) ) {
	fwrite( STDERR, "phpcs-baseline.json is not valid JSON.\n" );
	exit( 2 );
}

$wplc_new     = 0;
$wplc_fixed   = 0;
$wplc_known   = 0;
foreach ( $wplc_current as $wplc_file => $wplc_sources ) {
	foreach ( $wplc_sources as $wplc_source => $wplc_count ) {
		$wplc_allowed = isset( $wplc_accepted[ $wplc_file ][ $wplc_source ] ) ? (int) $wplc_accepted[ $wplc_file ][ $wplc_source ] : 0;
		if ( $wplc_count > $wplc_allowed ) {
			$wplc_new += $wplc_count - $wplc_allowed;
			echo "\nNEW: {$wplc_file} has {$wplc_count} x {$wplc_source} (baseline: {$wplc_allowed}). All occurrences:\n";
			foreach ( $wplc_details[ $wplc_file ][ $wplc_source ] as $wplc_line ) {
				echo '  ' . $wplc_line . "\n";
			}
		}
		$wplc_known += min( $wplc_count, $wplc_allowed );
	}
}
foreach ( $wplc_accepted as $wplc_file => $wplc_sources ) {
	foreach ( $wplc_sources as $wplc_source => $wplc_allowed ) {
		$wplc_count = isset( $wplc_current[ $wplc_file ][ $wplc_source ] ) ? (int) $wplc_current[ $wplc_file ][ $wplc_source ] : 0;
		if ( $wplc_count < (int) $wplc_allowed ) {
			$wplc_fixed += (int) $wplc_allowed - $wplc_count;
		}
	}
}

echo "\nBlocking PHPCS: {$wplc_new} new, {$wplc_known} baselined, {$wplc_fixed} fixed since the baseline.\n";
if ( $wplc_fixed > 0 ) {
	echo "Tighten the baseline with: php bin/phpcs-blocking.php --update\n";
}
if ( $wplc_new > 0 ) {
	echo "Fix the new finding(s), or add a justified // phpcs:ignore comment. Do not update the baseline to hide them.\n";
	exit( 1 );
}
exit( 0 );
