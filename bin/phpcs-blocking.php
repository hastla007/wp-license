<?php
/**
 * Runs the blocking PHPCS ruleset (security + PHP compatibility) against a baseline.
 *
 * PHP_CodeSniffer has no baseline feature. The existing findings were reviewed
 * and recorded in phpcs-baseline.json per file and sniff, each with the source
 * line it was reported on (trimmed, whitespace collapsed) and how often. A
 * finding is new when its file, sniff and line text are not in the baseline (or
 * occur more often than recorded). Moving a reviewed line keeps it accepted;
 * replacing it by another line with the same sniff does not (P-56). A baseline
 * in the former format (a plain count per file and sniff) is still read and
 * compared by count until it is rewritten with --update.
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
$wplc_lines   = array();
foreach ( $wplc_report['files'] as $wplc_file => $wplc_data ) {
	$wplc_file = str_replace( '\\', '/', (string) $wplc_file );
	if ( ! isset( $wplc_lines[ $wplc_file ] ) ) {
		$wplc_read                = is_readable( $wplc_root . '/' . $wplc_file ) ? file( $wplc_root . '/' . $wplc_file, FILE_IGNORE_NEW_LINES ) : false;
		$wplc_lines[ $wplc_file ] = is_array( $wplc_read ) ? $wplc_read : array();
	}
	foreach ( $wplc_data['messages'] as $wplc_message ) {
		$wplc_source    = (string) $wplc_message['source'];
		$wplc_line      = (int) $wplc_message['line'];
		$wplc_signature = isset( $wplc_lines[ $wplc_file ][ $wplc_line - 1 ] ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $wplc_lines[ $wplc_file ][ $wplc_line - 1 ] ) ) : '';
		if ( ! isset( $wplc_current[ $wplc_file ][ $wplc_source ][ $wplc_signature ] ) ) {
			$wplc_current[ $wplc_file ][ $wplc_source ][ $wplc_signature ] = 0;
		}
		++$wplc_current[ $wplc_file ][ $wplc_source ][ $wplc_signature ];
		$wplc_details[ $wplc_file ][ $wplc_source ][ $wplc_signature ][] = sprintf( '%s:%d: %s', $wplc_file, $wplc_line, (string) $wplc_message['message'] );
	}
}
ksort( $wplc_current );
foreach ( $wplc_current as $wplc_file => $wplc_sources ) {
	ksort( $wplc_current[ $wplc_file ] );
	foreach ( $wplc_sources as $wplc_source => $wplc_signatures ) {
		ksort( $wplc_current[ $wplc_file ][ $wplc_source ] );
	}
}

if ( $wplc_update ) {
	file_put_contents( $wplc_baseline, json_encode( $wplc_current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	$wplc_total = 0;
	foreach ( $wplc_current as $wplc_sources ) {
		foreach ( $wplc_sources as $wplc_signatures ) {
			$wplc_total += array_sum( $wplc_signatures );
		}
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
$wplc_legacy  = false;
foreach ( $wplc_current as $wplc_file => $wplc_sources ) {
	foreach ( $wplc_sources as $wplc_source => $wplc_signatures ) {
		$wplc_accepted_source = isset( $wplc_accepted[ $wplc_file ][ $wplc_source ] ) ? $wplc_accepted[ $wplc_file ][ $wplc_source ] : array();
		if ( ! is_array( $wplc_accepted_source ) ) {
			// Former format: a count per file and sniff.
			$wplc_legacy  = true;
			$wplc_count   = array_sum( $wplc_signatures );
			$wplc_allowed = (int) $wplc_accepted_source;
			if ( $wplc_count > $wplc_allowed ) {
				$wplc_new += $wplc_count - $wplc_allowed;
				echo "\nNEW: {$wplc_file} has {$wplc_count} x {$wplc_source} (baseline: {$wplc_allowed}). All occurrences:\n";
				foreach ( $wplc_details[ $wplc_file ][ $wplc_source ] as $wplc_occurrences ) {
					foreach ( $wplc_occurrences as $wplc_line ) {
						echo '  ' . $wplc_line . "\n";
					}
				}
			}
			$wplc_known += min( $wplc_count, $wplc_allowed );
			continue;
		}
		foreach ( $wplc_signatures as $wplc_signature => $wplc_count ) {
			$wplc_allowed = isset( $wplc_accepted_source[ $wplc_signature ] ) ? (int) $wplc_accepted_source[ $wplc_signature ] : 0;
			if ( $wplc_count > $wplc_allowed ) {
				$wplc_new += $wplc_count - $wplc_allowed;
				echo "\nNEW: {$wplc_source} ({$wplc_count} x, baseline: {$wplc_allowed}):\n";
				foreach ( $wplc_details[ $wplc_file ][ $wplc_source ][ $wplc_signature ] as $wplc_line ) {
					echo '  ' . $wplc_line . "\n";
				}
				echo '  line: ' . $wplc_signature . "\n";
			}
			$wplc_known += min( $wplc_count, $wplc_allowed );
		}
	}
}
foreach ( $wplc_accepted as $wplc_file => $wplc_sources ) {
	foreach ( (array) $wplc_sources as $wplc_source => $wplc_allowed_source ) {
		if ( ! is_array( $wplc_allowed_source ) ) {
			$wplc_count  = isset( $wplc_current[ $wplc_file ][ $wplc_source ] ) ? array_sum( $wplc_current[ $wplc_file ][ $wplc_source ] ) : 0;
			$wplc_fixed += max( 0, (int) $wplc_allowed_source - $wplc_count );
			continue;
		}
		foreach ( $wplc_allowed_source as $wplc_signature => $wplc_allowed ) {
			$wplc_count  = isset( $wplc_current[ $wplc_file ][ $wplc_source ][ $wplc_signature ] ) ? (int) $wplc_current[ $wplc_file ][ $wplc_source ][ $wplc_signature ] : 0;
			$wplc_fixed += max( 0, (int) $wplc_allowed - $wplc_count );
		}
	}
}

if ( $wplc_legacy ) {
	echo "\nphpcs-baseline.json still has the former count format; rewrite it with: php bin/phpcs-blocking.php --update\n";
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
