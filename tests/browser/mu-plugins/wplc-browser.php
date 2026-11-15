<?php
/**
 * Browser acceptance helper (test only, copied into the E2E site by tests/browser/run-browser.sh).
 *
 * With the cookie `wplc_browser_rtl=1` the request runs right-to-left, as with an RTL
 * locale (no language pack needed): `is_rtl()` is true, `<html dir="rtl">`, and WordPress
 * loads the -rtl stylesheets.
 *
 * @package WPLC
 */

if ( ! empty( $_COOKIE['wplc_browser_rtl'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- test-only switch, compared for emptiness.
	$wplc_browser_rtl = static function () {
		if ( isset( $GLOBALS['wp_locale'] ) ) {
			$GLOBALS['wp_locale']->text_direction = 'rtl';
		}
		if ( isset( $GLOBALS['wp_styles'] ) ) {
			$GLOBALS['wp_styles']->text_direction = 'rtl';
		}
	};
	add_action( 'setup_theme', $wplc_browser_rtl, 1 );
	add_action( 'init', $wplc_browser_rtl, 1 );
	add_action( 'wp_default_styles', $wplc_browser_rtl, 99 );
}
