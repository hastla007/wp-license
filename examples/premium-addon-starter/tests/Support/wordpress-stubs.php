<?php
/**
 * Minimal WordPress stand-ins for the unit tests (no WordPress needed).
 *
 * State lives in $GLOBALS['wp_stub']: options, transients, filters, actions, http (callable answering requests),
 * requests (log), current_user_can, cron.
 *
 * @package Vendor\Product\Premium
 */

// phpcs:ignoreFile -- test doubles.

/** Reset all stub state. */
function wp_stub_reset(): void {
	$GLOBALS['wp_stub'] = array(
		'options'          => array(),
		'transients'       => array(),
		'filters'          => array(),
		'requests'         => array(),
		'http'             => null,
		'can'              => true,
		'cron'             => array(),
		'fail_update'      => array(),
		'redirect'         => null,
	);
}
wp_stub_reset();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $url, $protocols = null ) {
	$scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
	if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
		return '';
	}
	return (string) $url;
}
function wp_kses_post( $html ) {
	return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function wp_generate_uuid4() {
	$b = random_bytes( 16 );
	$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
	$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
}
function wp_is_uuid( $uuid, $version = null ) {
	return is_string( $uuid ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid );
}
function home_url( $path = '' ) {
	return 'https://customer.example' . $path;
}
function admin_url( $path = '' ) {
	return 'https://customer.example/wp-admin/' . $path;
}
function get_bloginfo( $show = '' ) {
	return '6.8';
}
function wp_json_encode( $value, $options = 0, $depth = 512 ) {
	return json_encode( $value, $options, $depth );
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_stub']['options'] ) ? $GLOBALS['wp_stub']['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	if ( in_array( $name, $GLOBALS['wp_stub']['fail_update'], true ) ) {
		return false;
	}
	if ( array_key_exists( $name, $GLOBALS['wp_stub']['options'] ) && $GLOBALS['wp_stub']['options'][ $name ] === $value ) {
		return false;
	}
	$GLOBALS['wp_stub']['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['wp_stub']['options'][ $name ] );
	return true;
}
function get_transient( $name ) {
	return $GLOBALS['wp_stub']['transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['wp_stub']['transients'][ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['wp_stub']['transients'][ $name ] );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_stub']['filters'][ $hook ][] = $callback;
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wp_stub']['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['wp_stub']['filters'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}
function current_user_can( $capability ) {
	return (bool) $GLOBALS['wp_stub']['can'];
}
function get_current_user_id() {
	return 1;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['wp_stub']['cron'][ $hook ] );
	return 1;
}
function wp_next_scheduled( $hook ) {
	return $GLOBALS['wp_stub']['cron'][ $hook ] ?? false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['wp_stub']['cron'][ $hook ] = $timestamp;
	return true;
}
/** Multisite when $GLOBALS['wp_stub']['sites'] lists site IDs; switches are logged in 'switched'. */
function is_multisite() {
	return ! empty( $GLOBALS['wp_stub']['sites'] );
}
function get_sites( $args = array() ) {
	$GLOBALS['wp_stub']['get_sites'][] = $args;
	return array_slice( (array) ( $GLOBALS['wp_stub']['sites'] ?? array() ), (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) ?: null );
}
function switch_to_blog( $site_id ) {
	$GLOBALS['wp_stub']['switched'][] = (int) $site_id;
	return true;
}
function restore_current_blog() {
	return true;
}
function wp_doing_ajax() {
	return ! empty( $GLOBALS['wp_stub']['ajax'] );
}
function wp_nonce_field( $action = -1 ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $action ) . '">';
}
function submit_button( $text = '' ) {
	echo '<button type="submit">' . esc_html( $text ) . '</button>';
}
function get_admin_page_title() {
	return 'License';
}
function wp_tempnam( $name = '' ) {
	return (string) tempnam( sys_get_temp_dir(), 'starter' );
}
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

/**
 * HTTP: $GLOBALS['wp_stub']['http'] is a callable( string $url, array $args ) returning
 * array( 'code' => int, 'body' => string, 'headers' => array ) or a WP_Error.
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['wp_stub']['requests'][] = array( 'url' => $url, 'args' => $args );
	$handler = $GLOBALS['wp_stub']['http'];
	if ( ! is_callable( $handler ) ) {
		return new WP_Error( 'http_request_failed', 'No HTTP handler (offline).' );
	}
	$response = $handler( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
		$body = (string) ( $response['body'] ?? '' );
		if ( ! empty( $args['limit_response_size'] ) ) {
			$body = substr( $body, 0, (int) $args['limit_response_size'] );
		}
		file_put_contents( $args['filename'], $body );
	}
	return array(
		'response' => array( 'code' => (int) ( $response['code'] ?? 200 ) ),
		'body'     => (string) ( $response['body'] ?? '' ),
		'headers'  => array_change_key_case( (array) ( $response['headers'] ?? array() ) ),
	);
}
function wp_safe_remote_get( $url, $args = array() ) {
	$args['method'] = 'GET';
	return wp_remote_request( $url, $args );
}
// The license client sends its API requests with wp_safe_remote_request() (client 2.1.0).
function wp_safe_remote_request( $url, $args = array() ) {
	return wp_remote_request( $url, $args );
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : '';
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}
function wp_remote_retrieve_header( $response, $header ) {
	return is_array( $response ) ? (string) ( $response['headers'][ strtolower( $header ) ] ?? '' ) : '';
}
function maybe_serialize( $data ) {
	return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
}
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = trim( strip_tags( (string) $text ) );
	return $remove_breaks ? (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text;
}
