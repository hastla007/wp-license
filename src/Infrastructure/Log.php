<?php
/**
 * One place that writes the plugin's lines to the WooCommerce log.
 *
 * @package WPLC
 */

namespace WPLC\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured logging (A-133).
 *
 * Every line goes to the WooCommerce log with the source `wp-license`, the
 * correlation ID of the current PHP request (`request_id`) and the object IDs
 * the caller passes (`license_id`, `renewal_id`, `order_id`, `event_id`, ...).
 * Searching the log for one `request_id` shows everything one renewal run,
 * order hook or payment event did; searching for a `renewal_id` connects the
 * runs. Email addresses are masked. Lines of the level `debug` are written only
 * when the constant `WPLC_DEBUG` is true or the filter `wplc_log_debug` says so.
 */
final class Log {

	/** Log source shown in WooCommerce → Status → Logs. */
	public const SOURCE = 'wp-license';

	private const LEVELS = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

	/**
	 * Correlation ID of this PHP request (null until first used).
	 *
	 * @var string|null
	 */
	private static $request_id = null;

	/**
	 * Correlation ID of this PHP request: 12 hex characters, the same for every line.
	 *
	 * @return string
	 */
	public static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = bin2hex( random_bytes( 6 ) );
		}

		return self::$request_id;
	}

	/**
	 * Start a new correlation ID, e.g. for each job of a queue runner that handles several in one request.
	 *
	 * @param string $id Optional ID to continue (letters, digits, `-`, `_`; at most 64); empty: a new random one.
	 * @return string The ID now in use.
	 */
	public static function begin( string $id = '' ): string {
		$id               = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $id ), 0, 64 );
		self::$request_id = '' !== $id ? $id : bin2hex( random_bytes( 6 ) );

		return self::$request_id;
	}

	/**
	 * Whether debug lines are written.
	 *
	 * @return bool
	 */
	public static function debug_enabled(): bool {
		$enabled = defined( 'WPLC_DEBUG' ) && (bool) constant( 'WPLC_DEBUG' );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters whether the plugin writes debug lines to the WooCommerce log (A-133).
			 *
			 * @param bool $enabled Default: the constant WPLC_DEBUG.
			 */
			$enabled = (bool) apply_filters( 'wplc_log_debug', $enabled );
		}

		return $enabled;
	}

	/**
	 * Build the line and its context. Pure apart from the correlation ID.
	 *
	 * @param string              $message Message; must not contain keys or secrets.
	 * @param array<int|string,mixed> $context Object IDs and other scalar facts.
	 * @return array{0:string,1:array<string,mixed>}
	 */
	public static function prepare( string $message, array $context = array() ): array {
		// Mailer and gateway errors can quote an address; addresses do not belong in a log file.
		$message = (string) preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[email]', $message );
		$clean   = array();
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && ( is_scalar( $value ) || null === $value ) ) {
				$clean[ $key ] = is_string( $value ) ? (string) preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[email]', $value ) : $value;
			}
		}
		$clean['source']     = self::SOURCE;
		$clean['request_id'] = self::request_id();

		return array( $message, $clean );
	}

	/**
	 * Write one line. Never throws.
	 *
	 * @param string              $level   PSR-3 level; unknown levels are written as `info`.
	 * @param string              $message Message; must not contain keys or secrets.
	 * @param array<string,mixed> $context Object IDs (`license_id`, `renewal_id`, `order_id`, ...).
	 * @return bool Whether the line was handed to the logger.
	 */
	public static function write( string $level, string $message, array $context = array() ): bool {
		$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';
		if ( 'debug' === $level && ! self::debug_enabled() ) {
			return false;
		}
		try {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return false;
			}
			list( $message, $context ) = self::prepare( $message, $context );
			wc_get_logger()->log( $level, $message, $context );

			return true;
		} catch ( \Throwable $error ) {
			unset( $error );

			return false;
		}
	}
}
