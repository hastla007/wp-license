<?php
/**
 * The one register of all stored settings.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every stored setting is described here once: key, type, default, limits, group,
 * capability, documented filter and whether it may be exported.
 *
 * The settings page, export and import, reset, WP-CLI and the change log read this
 * register; no other class reads the option `wplc_settings` (a unit test searches `src/`).
 * Labels and help texts live in Texts so that reading a value never loads translations.
 *
 * Precedence everywhere: built-in default, then the stored setting, then the filter
 * named in `filter` (the filter wins). The filter is applied by the class that uses
 * the value, never here.
 *
 * Secrets are not settings: they stay in wp-config.php. The flag `secret` exists so
 * that a later setting of that kind is kept out of exports and the change log.
 */
final class Registry {

	/**
	 * Option that stores all settings as one array.
	 */
	public const OPTION = 'wplc_settings';

	/**
	 * Groups (tabs of the settings page), in display order.
	 */
	public const GROUPS = array( 'updates', 'renewals', 'emails', 'rules', 'sites', 'sharing', 'account', 'legal', 'texts', 'operations' );

	/**
	 * Longest text a change log entry or a preview shows.
	 */
	public const PREVIEW_LENGTH = 120;

	/**
	 * Cached definitions.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $definitions = null;

	/**
	 * All definitions, keyed by setting key, in display order.
	 *
	 * Fields of a definition:
	 * - type: int, bool, string, text, int_list, string_list, hosts, choice
	 * - default: stored form of the default value
	 * - min, max: bounds of an int or of each entry of an int_list
	 * - max_items, max_length, choices, pattern (for entries of a string_list), ltrim (characters removed
	 *   from the start of each entry of a string_list, e.g. the dot of ".example.com")
	 * - resettable: the field offers "reset to default" of its own (lists with a built-in default)
	 * - clamp: an int outside its bounds is moved to the nearest bound instead of being rejected
	 *   (only the settings that existed before the register; their forms behaved like that)
	 * - empty_default: an empty int_list is replaced by the default (true) or stored as empty (false)
	 * - group: one of GROUPS
	 * - capability: key of Admin\Capabilities needed to change the value
	 * - filter: name of the documented filter that wins over the setting, or ''
	 * - exportable: part of export and import files
	 * - secret: never exported, never written to the change log with its value
	 * - legal: the value has legal weight; the help text asks for a legal check
	 * - constant: name of a constant in wp-config.php that wins over the stored value; while it is
	 *   defined the field is read-only and a save keeps the stored value (SET-LIC-30)
	 * - pattern (string): the text must match it; an empty text is always accepted
	 * - format: for a string, `email`, `emails`, `url` or `key_prefix`
	 * - locked: callable that returns true while the value may no longer change (the field is
	 *   read-only, a save, import, reset or WP-CLI call keeps the stored value; see is_locked())
	 * - empty_note: what an empty value means, for previews that show "–"; `builtin_text` = the
	 *   built-in wording in the language of the customer is used
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		$raw = Definitions::all();
		$out = array();
		foreach ( $raw as $key => $definition ) {
			$out[ $key ] = $definition + array(
				'key'           => $key,
				'type'          => 'string',
				'default'       => '',
				'group'         => 'rules',
				'capability'    => 'manage_settings',
				'filter'        => '',
				'exportable'    => true,
				'secret'        => false,
				'legal'         => false,
				'clamp'         => false,
				'empty_default' => false,
				'constant'      => '',
			);
		}
		self::$definitions = $out;

		return $out;
	}

	/**
	 * One definition, or null for an unknown key.
	 *
	 * @param string $key Setting key.
	 * @return array<string,mixed>|null
	 */
	public static function definition( string $key ): ?array {
		$definitions = self::definitions();

		return $definitions[ $key ] ?? null;
	}

	/**
	 * Default values of all settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::definitions() as $key => $definition ) {
			$defaults[ $key ] = $definition['default'];
		}

		return $defaults;
	}

	/**
	 * Keys of one group, in display order.
	 *
	 * @param string $group Group key.
	 * @return string[]
	 */
	public static function keys_of_group( string $group ): array {
		$keys = array();
		foreach ( self::definitions() as $key => $definition ) {
			if ( $group === $definition['group'] ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * The stored option as an array of known keys. The only place that reads the option.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		return is_array( $stored ) ? array_intersect_key( $stored, self::definitions() ) : array();
	}

	/**
	 * All settings: defaults overlaid with what is stored.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		return array_merge( self::defaults(), self::stored() );
	}

	/**
	 * Read one setting: the stored value, else `$default`, else the built-in default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value when the setting is not stored; null uses the built-in default.
	 * @return mixed Null for an unknown key.
	 */
	public static function get( string $key, $default = null ) {
		$stored = self::stored();
		if ( array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		$definition = self::definition( $key );

		return null === $definition ? null : $definition['default'];
	}

	/**
	 * The constant that wins over a setting, when it is defined (SET-LIC-30).
	 *
	 * @param string $key Setting key.
	 * @return string Name of the defined constant, or '' when the setting decides.
	 */
	public static function locking_constant( string $key ): string {
		$definition = self::definition( $key );
		$constant   = null === $definition ? '' : (string) $definition['constant'];

		return '' !== $constant && defined( $constant ) ? $constant : '';
	}

	/**
	 * Whether a setting may no longer change (definition field `locked`), e.g. the license key
	 * prefix once a license was issued (E-04).
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_locked( string $key ): bool {
		$definition = self::definition( $key );
		if ( null === $definition || empty( $definition['locked'] ) || ! is_callable( $definition['locked'] ) ) {
			return false;
		}

		return (bool) call_user_func( $definition['locked'] );
	}

	/**
	 * Read a whole-number setting; a stored value of the wrong kind counts as the default.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function int( string $key ): int {
		$value      = self::get( $key );
		$definition = self::definition( $key );
		if ( ! is_numeric( $value ) ) {
			$value = null === $definition ? 0 : $definition['default'];
		}
		$value = (int) $value;
		if ( null !== $definition && isset( $definition['min'], $definition['max'] ) ) {
			$value = max( (int) $definition['min'], min( (int) $definition['max'], $value ) );
		}

		return $value;
	}

	/**
	 * Read a switch.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function bool( string $key ): bool {
		return ! empty( self::get( $key ) );
	}

	/**
	 * Read a text setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function string( string $key ): string {
		$value = self::get( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read a list setting as an array (an int_list is stored as "30,7", a string_list as an array).
	 *
	 * @param string $key Setting key.
	 * @return array<int,int|string>
	 */
	public static function list( string $key ): array {
		$definition = self::definition( $key );
		$value      = self::get( $key );
		if ( null === $definition ) {
			return array();
		}
		$result = self::validate( $key, $value );
		$value  = $result['ok'] ? $result['value'] : $definition['default'];
		if ( 'int_list' === $definition['type'] ) {
			return '' === $value ? array() : array_map( 'intval', explode( ',', (string) $value ) );
		}

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Check and normalize a value for a setting.
	 *
	 * Accepts the stored form and the form a text field, a file or the command line delivers
	 * (lists as text with commas or line breaks). A value that cannot be accepted is reported,
	 * not silently replaced: `ok` is false and `error` says why, in English, for logs and WP-CLI;
	 * `code` names the kind of problem so that a screen can show a translated message.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $raw Candidate value.
	 * @return array{ok:bool,value:mixed,code:string,error:string}
	 */
	public static function validate( string $key, $raw ): array {
		$definition = self::definition( $key );
		if ( null === $definition ) {
			return self::failure( 'unknown', 'Unknown setting.' );
		}

		switch ( $definition['type'] ) {
			case 'bool':
				if ( is_bool( $raw ) || is_int( $raw ) || null === $raw ) {
					return self::success( empty( $raw ) ? 0 : 1 );
				}
				$text = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '?';
				if ( in_array( $text, array( '1', 'true', 'yes', 'on' ), true ) ) {
					return self::success( 1 );
				}
				if ( in_array( $text, array( '0', 'false', 'no', 'off', '' ), true ) ) {
					return self::success( 0 );
				}

				return self::failure( 'not_a_switch', 'Expected yes or no (1 or 0).' );

			case 'int':
				$text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( is_bool( $raw ) || '' === $text || ! ctype_digit( $text ) || strlen( $text ) > 9 ) {
					return self::failure( 'not_a_number', 'Expected a whole number.' );
				}
				$value = (int) $text;
				$min   = (int) ( $definition['min'] ?? 0 );
				$max   = (int) ( $definition['max'] ?? PHP_INT_MAX );
				if ( $value < $min || $value > $max ) {
					if ( $definition['clamp'] ) {
						return self::success( max( $min, min( $max, $value ) ) );
					}

					return self::failure( 'out_of_range', sprintf( 'Expected a whole number from %d to %d.', $min, $max ) );
				}

				return self::success( $value );

			case 'choice':
				$text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( ! in_array( $text, (array) ( $definition['choices'] ?? array() ), true ) ) {
					return self::failure( 'not_a_choice', 'Expected one of: ' . implode( ', ', (array) ( $definition['choices'] ?? array() ) ) . '.' );
				}

				return self::success( $text );

			case 'string':
			case 'text':
				if ( null !== $raw && ! is_scalar( $raw ) ) {
					return self::failure( 'not_text', 'Expected text.' );
				}
				$text = (string) $raw;
				if ( 'text' === $definition['type'] ) {
					$text = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $text ) : trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
				} else {
					$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
				}
				$format = (string) ( $definition['format'] ?? '' );
				if ( 'key_prefix' === $format ) {
					// E-04: four characters, upper case; a trailing hyphen is the separator, not part of the prefix.
					$text = strtoupper( rtrim( trim( $text ), '-' ) );
					if ( 1 !== preg_match( '/^[A-Z][A-Z0-9]{3}$/D', $text ) ) {
						return self::failure( 'not_a_key_prefix', 'Expected four characters: a letter, then letters or digits.' );
					}

					return self::success( $text );
				}
				$max    = (int) ( $definition['max_length'] ?? 500 );
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
				if ( $length > $max ) {
					if ( $definition['clamp'] ) {
						$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
					} else {
						return self::failure( 'too_long', sprintf( 'Expected at most %d characters.', $max ) );
					}
				}
				if ( '' !== $text && 'email' === $format && ! self::is_email( $text ) ) {
					return self::failure( 'not_an_email', 'Expected an email address.' );
				}
				if ( '' !== $text && 'emails' === $format ) {
					$addresses = array();
					foreach ( (array) preg_split( '/[\s,;]+/', $text ) as $address ) {
						$address = (string) $address;
						if ( '' === $address ) {
							continue;
						}
						if ( ! self::is_email( $address ) ) {
							return self::failure( 'not_an_email', 'Expected email addresses, separated by commas.' );
						}
						$addresses[ strtolower( $address ) ] = $address;
					}
					if ( count( $addresses ) > (int) ( $definition['max_items'] ?? 10 ) ) {
						return self::failure( 'too_many', sprintf( 'Expected at most %d entries.', (int) ( $definition['max_items'] ?? 10 ) ) );
					}
					$text = implode( ', ', $addresses );
				}
				if ( '' !== $text && isset( $definition['pattern'] ) && 1 !== preg_match( (string) $definition['pattern'], $text ) ) {
					return self::failure( 'invalid_format', 'The value does not have the expected form.' );
				}
				if ( '' !== $text && 'url' === $format && 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $text ) ) {
					return self::failure( 'not_a_url', 'Expected an address that starts with https:// or http://.' );
				}

				return self::success( $text );

			case 'int_list':
				return self::validate_int_list( $definition, $raw );

			case 'string_list':
				return self::validate_string_list( $definition, $raw );

			case 'hosts':
				$lines = is_array( $raw ) ? implode( "\n", array_filter( $raw, 'is_string' ) ) : ( is_string( $raw ) ? $raw : '' );
				$hosts = self::parse_hosts( $lines );
				if ( $hosts['invalid'] ) {
					return self::failure( 'invalid_hosts', 'Not a host name: ' . implode( ', ', array_slice( $hosts['invalid'], 0, 5 ) ) . '.' );
				}
				$limit = (int) ( $definition['max_items'] ?? 50 );
				if ( count( $hosts['valid'] ) > $limit ) {
					// P1-M7: never cut the list silently.
					return self::failure( 'too_many', sprintf( 'Expected at most %d entries.', $limit ) );
				}

				return self::success( $hosts['valid'] );
		}

		return self::failure( 'unknown_type', 'Unknown type.' );
	}

	/**
	 * Check a list of whole numbers ("30, 7" or an array).
	 *
	 * @param array<string,mixed> $definition Definition.
	 * @param mixed               $raw        Candidate.
	 * @return array{ok:bool,value:mixed,code:string,error:string}
	 */
	private static function validate_int_list( array $definition, $raw ): array {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', array_filter( $raw, 'is_scalar' ) );
		}
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			return self::failure( 'not_a_list', 'Expected whole numbers, separated by commas.' );
		}
		$min    = (int) ( $definition['min'] ?? 0 );
		$max    = (int) ( $definition['max'] ?? PHP_INT_MAX );
		$values = array();
		$bad    = array();
		foreach ( (array) preg_split( '/[\s,;]+/', trim( (string) $raw ) ) as $part ) {
			$part = (string) $part;
			if ( '' === $part ) {
				continue;
			}
			if ( ! ctype_digit( $part ) || strlen( $part ) > 9 || (int) $part < $min || (int) $part > $max ) {
				$bad[] = $part;
				continue;
			}
			$values[ (int) $part ] = (int) $part;
		}
		if ( $bad && ! $definition['clamp'] ) {
			return self::failure( 'out_of_range', sprintf( 'Expected whole numbers from %d to %d, separated by commas. Not accepted: %s.', $min, $max, implode( ', ', array_slice( $bad, 0, 5 ) ) ) );
		}
		if ( 'desc' === ( $definition['order'] ?? 'asc' ) ) {
			rsort( $values );
		} else {
			sort( $values );
		}
		$limit = (int) ( $definition['max_items'] ?? 10 );
		if ( count( $values ) > $limit ) {
			if ( ! $definition['clamp'] ) {
				return self::failure( 'too_many', sprintf( 'Expected at most %d entries.', $limit ) );
			}
			$values = array_slice( $values, 0, $limit );
		}
		if ( ! $values && $definition['empty_default'] ) {
			return self::success( $definition['default'] );
		}

		return self::success( implode( ',', $values ) );
	}

	/**
	 * Entries of a submitted list of whole numbers that the accepted value leaves out
	 * (not a number, outside the limits, or beyond the largest number of entries).
	 *
	 * Only lists that drop such entries instead of rejecting the value have any; repeated
	 * entries are not counted.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $raw      Submitted value (text or array).
	 * @param mixed  $accepted The value validate() accepted.
	 * @return string[]
	 */
	public static function discarded( string $key, $raw, $accepted ): array {
		$definition = self::definition( $key );
		if ( null === $definition || 'int_list' !== $definition['type'] || ! is_string( $accepted ) ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			$raw = implode( ',', array_filter( $raw, 'is_scalar' ) );
		}
		if ( ! is_scalar( $raw ) ) {
			return array();
		}
		$kept    = '' === $accepted ? array() : array_map( 'intval', explode( ',', $accepted ) );
		$dropped = array();
		foreach ( (array) preg_split( '/[\s,;]+/', trim( (string) $raw ) ) as $part ) {
			$part = (string) $part;
			if ( '' === $part ) {
				continue;
			}
			if ( ! ctype_digit( $part ) || strlen( $part ) > 9 || ! in_array( (int) $part, $kept, true ) ) {
				$dropped[ $part ] = $part;
			}
		}

		return array_values( array_slice( $dropped, 0, 10 ) );
	}

	/**
	 * Check a list of short texts (one per line, or an array).
	 *
	 * @param array<string,mixed> $definition Definition.
	 * @param mixed               $raw        Candidate.
	 * @return array{ok:bool,value:mixed,code:string,error:string}
	 */
	private static function validate_string_list( array $definition, $raw ): array {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_filter( $raw, 'is_scalar' ) );
		}
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			return self::failure( 'not_a_list', 'Expected one entry per line.' );
		}
		$pattern = (string) ( $definition['pattern'] ?? '/^[a-z0-9][a-z0-9.-]{0,62}$/' );
		$strip   = (string) ( $definition['ltrim'] ?? '' );
		$values  = array();
		$bad     = array();
		foreach ( (array) preg_split( '/[\r\n,;\s]+/', (string) $raw ) as $part ) {
			$part = strtolower( trim( (string) $part ) );
			if ( '' !== $strip ) {
				$part = ltrim( $part, $strip );
			}
			if ( '' === $part ) {
				continue;
			}
			if ( 1 !== preg_match( $pattern, $part ) ) {
				$bad[] = $part;
				continue;
			}
			$values[ $part ] = $part;
		}
		if ( $bad ) {
			return self::failure( 'invalid_entries', 'Not accepted: ' . implode( ', ', array_slice( $bad, 0, 5 ) ) . '.' );
		}
		$limit = (int) ( $definition['max_items'] ?? 50 );
		if ( count( $values ) > $limit ) {
			return self::failure( 'too_many', sprintf( 'Expected at most %d entries.', $limit ) );
		}

		return self::success( array_values( $values ) );
	}

	/**
	 * Whether a text looks like an email address.
	 *
	 * @param string $text Candidate.
	 * @return bool
	 */
	private static function is_email( string $text ): bool {
		return function_exists( 'is_email' ) ? (bool) is_email( $text ) : false !== filter_var( $text, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Split text into valid and rejected host names. Every valid host is returned; the largest
	 * number of hosts is checked by validate() (and the settings form), which reports it.
	 *
	 * @param string $raw One host per line.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_hosts( string $raw ): array {
		$valid   = array();
		$invalid = array();
		$lines   = preg_split( '/[\r\n,]+/', $raw );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( self::is_valid_hostname( $line ) ) {
				$valid[ $line ] = $line;
			} else {
				$invalid[] = $line;
			}
		}

		return array(
			'valid'   => array_values( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Validate a DNS host name (no scheme, port, path, wildcard or IP address).
	 *
	 * @param string $host Lowercase candidate.
	 * @return bool
	 */
	public static function is_valid_hostname( string $host ): bool {
		if ( strlen( $host ) > 253 || false === strpos( $host, '.' ) ) {
			return false;
		}

		return 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host );
	}

	/**
	 * A value as short text for previews, the change log and the command line.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value in stored form.
	 * @return string
	 */
	public static function display( string $key, $value ): string {
		$definition = self::definition( $key );
		if ( null !== $definition && $definition['secret'] ) {
			return '***';
		}
		if ( null !== $definition && 'bool' === $definition['type'] ) {
			return empty( $value ) ? '0' : '1';
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) > self::PREVIEW_LENGTH ) {
			$text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::PREVIEW_LENGTH ) : substr( $text, 0, self::PREVIEW_LENGTH ) ) . '…';
		}

		return $text;
	}

	/**
	 * Forget the cached definitions (tests).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$definitions = null;
	}

	/**
	 * Result of an accepted value.
	 *
	 * @param mixed $value Normalized value.
	 * @return array{ok:bool,value:mixed,code:string,error:string}
	 */
	private static function success( $value ): array {
		return array(
			'ok'    => true,
			'value' => $value,
			'code'  => '',
			'error' => '',
		);
	}

	/**
	 * Result of a rejected value.
	 *
	 * @param string $code  Kind of problem.
	 * @param string $error English message.
	 * @return array{ok:bool,value:mixed,code:string,error:string}
	 */
	private static function failure( string $code, string $error ): array {
		return array(
			'ok'    => false,
			'value' => null,
			'code'  => $code,
			'error' => $error,
		);
	}
}
