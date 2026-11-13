<?php
/**
 * Minimal WordPress function stubs for unit tests of pure admin/account helpers.
 *
 * Every stub is guarded so other test files may define their own.
 *
 * @package WPLC
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
