<?php
/**
 * Append-only audit log for license actions.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Admin\Capabilities;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores safe summaries of license state transitions.
 */
final class AuditLog {

	/**
	 * Sources an entry can come from (A-76), stored in audit_log.source.
	 *
	 * @var string[]
	 */
	public const SOURCES = array( 'cli', 'cron', 'webhook', 'rest', 'admin', 'customer', 'system' );

	/**
	 * Longest stored source (column width).
	 */
	public const SOURCE_MAX_LENGTH = 20;

	/**
	 * Schema version that added audit_log.source.
	 */
	public const SOURCE_SCHEMA = '23';

	/**
	 * REST route of the Stripe webhook (StripeWebhook::register_routes()).
	 */
	public const WEBHOOK_ROUTE = '/wplc/v1/webhooks/stripe';

	/**
	 * Nesting depth of Action Scheduler actions running in this request.
	 *
	 * @var int
	 */
	private static $action_depth = 0;

	/**
	 * Routes of the REST requests being dispatched, innermost last.
	 *
	 * @var string[]
	 */
	private static $rest_routes = array();

	/**
	 * Track Action Scheduler actions and REST routes for the source of an entry.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'action_scheduler_begin_execute', array( self::class, 'begin_action' ), 0 );
		add_action( 'action_scheduler_after_execute', array( self::class, 'end_action' ), PHP_INT_MAX );
		add_action( 'action_scheduler_failed_execution', array( self::class, 'end_action' ), PHP_INT_MAX );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'begin_rest_request' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'end_rest_request' ), PHP_INT_MAX, 1 );
	}

	/**
	 * An Action Scheduler action starts.
	 *
	 * @return void
	 */
	public static function begin_action(): void {
		++self::$action_depth;
	}

	/**
	 * An Action Scheduler action ended (successfully or not).
	 *
	 * @return void
	 */
	public static function end_action(): void {
		self::$action_depth = max( 0, self::$action_depth - 1 );
	}

	/**
	 * Remember the route of the REST request whose callback runs next.
	 *
	 * @param mixed $response Response so far (passed through).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public static function begin_rest_request( $response, $handler = null, $request = null ) {
		self::$rest_routes[] = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		return $response;
	}

	/**
	 * The callback of the current REST request returned.
	 *
	 * @param mixed $response Response (passed through).
	 * @return mixed
	 */
	public static function end_rest_request( $response ) {
		array_pop( self::$rest_routes );
		return $response;
	}

	/**
	 * Source of an entry from the request context. Pure.
	 *
	 * Order: WP-CLI, then cron (WP-Cron or an Action Scheduler action, also when
	 * Action Scheduler runs in an async or REST request), then REST (the Stripe
	 * webhook route as "webhook"), then a logged-in user: staff in wp-admin is
	 * "admin", any other logged-in user "customer" (also a customer's
	 * admin-post/admin-ajax request), and "system" otherwise.
	 *
	 * @param array{cli?:bool,cron?:bool,action?:bool,rest?:bool,route?:string,admin?:bool,user_id?:int,staff?:bool} $context Context.
	 * @return string
	 */
	public static function classify_source( array $context ): string {
		if ( ! empty( $context['cli'] ) ) {
			return 'cli';
		}
		if ( ! empty( $context['cron'] ) || ! empty( $context['action'] ) ) {
			return 'cron';
		}
		$route = isset( $context['route'] ) ? (string) $context['route'] : '';
		if ( ! empty( $context['rest'] ) || '' !== $route ) {
			return self::WEBHOOK_ROUTE === rtrim( $route, '/' ) ? 'webhook' : 'rest';
		}
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		if ( $user_id > 0 ) {
			return ! empty( $context['admin'] ) && ! empty( $context['staff'] ) ? 'admin' : 'customer';
		}

		return 'system';
	}

	/**
	 * Context of the current request for classify_source().
	 *
	 * @return array{cli:bool,cron:bool,action:bool,rest:bool,route:string,admin:bool,user_id:int,staff:bool}
	 */
	public static function current_context(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$route   = self::$rest_routes ? (string) end( self::$rest_routes ) : '';
		if ( '' === $route && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = $GLOBALS['wp']->query_vars['rest_route'];
		}

		return array(
			'cli'     => defined( 'WP_CLI' ) && WP_CLI,
			'cron'    => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
			'action'  => self::$action_depth > 0,
			'rest'    => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ),
			'route'   => $route,
			'admin'   => function_exists( 'is_admin' ) && is_admin(),
			'user_id' => $user_id,
			'staff'   => $user_id > 0 && Capabilities::can( Capabilities::VIEW_LICENSES ),
		);
	}

	/**
	 * Source of an entry recorded now, after the `wplc_audit_source` filter.
	 *
	 * @param string $action      Action identifier.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string
	 */
	public static function current_source( string $action = '', string $object_type = '', int $object_id = 0 ): string {
		$source = self::classify_source( self::current_context() );

		/**
		 * Filter the source stored with an audit entry (A-76).
		 *
		 * Built-in values: cli, cron, webhook, rest, admin, customer, system.
		 * The value is stored as a key of at most 20 characters; an empty
		 * result keeps the detected source.
		 *
		 * @param mixed  $source      Detected source (a key such as `cron`; return a string, anything else is ignored).
		 * @param string $action      Action identifier.
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 */
		$filtered = apply_filters( 'wplc_audit_source', $source, $action, $object_type, $object_id );

		return self::normalize_source( is_string( $filtered ) ? $filtered : '', $source );
	}

	/**
	 * Stored form of a source: a key of at most 20 characters, else the fallback.
	 *
	 * @param string $source   Source.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function normalize_source( string $source, string $fallback = 'system' ): string {
		$source = substr( strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $source ) ), 0, self::SOURCE_MAX_LENGTH );

		return '' === $source ? $fallback : $source;
	}

	/**
	 * Record an audit event without secrets or payment data.
	 *
	 * @param string $action Action identifier.
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param string $reason Human-readable reason.
	 * @param mixed  $before Previous state summary.
	 * @param mixed  $after New state summary.
	 * @param int    $actor_user_id User ID, or zero for system events.
	 * @return void
	 */
	public static function record(
		string $action,
		string $object_type,
		int $object_id,
		string $reason = '',
		$before = null,
		$after = null,
		int $actor_user_id = 0
	): void {
		global $wpdb;

		$tables = Schema::table_names();
		$data   = array(
			'actor_user_id' => max( 0, $actor_user_id ),
			'action'        => sanitize_key( $action ),
			'object_type'   => sanitize_key( $object_type ),
			'object_id'     => max( 0, $object_id ),
			'reason'        => self::redact_text( sanitize_textarea_field( $reason ) ),
			'before_json'   => self::encode_summary( $before ),
			'after_json'    => self::encode_summary( $after ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
		$format = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
		// Until schema 23 is installed (a front-end or REST request never
		// migrates), the column may not exist yet: the entry is kept without it.
		if ( self::source_column_installed() ) {
			$data['source'] = self::current_source( $action, $object_type, $object_id );
			$format[]       = '%s';
		}
		$wpdb->insert( $tables['audit_log'], $data, $format );
	}

	/**
	 * Whether the installed schema has audit_log.source (schema 23).
	 *
	 * @return bool
	 */
	public static function source_column_installed(): bool {
		return function_exists( 'get_option' ) && version_compare( (string) get_option( Schema::VERSION_OPTION, '0' ), self::SOURCE_SCHEMA, '>=' );
	}

	/**
	 * Apply the write-time redaction again before a stored summary is shown.
	 *
	 * Rows written by older versions or by add-ons that bypassed record()
	 * might still contain sensitive fields; admin screens pass every decoded
	 * snapshot through here (defense in depth).
	 *
	 * @param mixed $value Decoded summary.
	 * @return mixed
	 */
	public static function redact_for_display( $value ) {
		return self::redact_value( $value );
	}

	/**
	 * Apply the write-time text redaction again before a stored reason is shown.
	 *
	 * @param string $value Stored text.
	 * @return string
	 */
	public static function redact_text_for_display( string $value ): string {
		return self::redact_text( $value );
	}

	/**
	 * Encode a small state summary.
	 *
	 * @param mixed $value Summary value.
	 * @return string|null
	 */
	private static function encode_summary( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$json = wp_json_encode( self::redact_value( $value ) );
		return false === $json ? null : $json;
	}

	/**
	 * Redact known sensitive fields from structured summaries.
	 *
	 * @param mixed $value Summary value.
	 * @return mixed
	 */
	private static function redact_value( $value ) {
		// The site URL of an activation is kept (A-77): support needs it to
		// follow address changes, and it is shown next to the activation anyway.
		$sensitive = '/(?:license.?key|secret|token|password|authorization|email|phone|address)/i';

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? self::redact_text( $value ) : $value;
		}

		$redacted = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && preg_match( $sensitive, $key ) ) {
				$redacted[ $key ] = '[REDACTED]';
			} else {
				$redacted[ $key ] = self::redact_value( $item );
			}
		}

		return $redacted;
	}

	/**
	 * Redact inline credentials, license keys, and email addresses.
	 *
	 * @param string $value Text to sanitize.
	 * @return string
	 */
	private static function redact_text( string $value ): string {
		$value = preg_replace( '/(license.?key|api.?key|secret|token|password|authorization)\\s*[:=]\\s*[^\\s,;]+/i', '$1=[REDACTED]', $value );
		// A short key (T-106) as typed with spaces or without hyphens; then every key with hyphens.
		$value = preg_replace( '/\\b' . LicenseKey::prefix_alternatives() . '[ -]?(?:[A-Za-z0-9]{5}[ -]?){4}[A-Za-z0-9]{5}\\b/i', '[REDACTED-LICENSE-KEY]', (string) $value );
		$value = preg_replace( '/\\b' . LicenseKey::prefix_alternatives() . '-[A-Za-z0-9-]{6,}\\b/i', '[REDACTED-LICENSE-KEY]', $value );
		$value = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', '[REDACTED-EMAIL]', $value );

		return is_string( $value ) ? $value : '';
	}
}
