<?php
/**
 * Plugin settings page and option access.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → License settings. Secrets stay in wp-config.php constants.
 *
 * What a setting is (key, type, default, limits, group, capability) stands in
 * Settings\Registry; this class is the page, the form and the change log.
 */
final class Settings {

	/**
	 * Option name and settings group.
	 */
	public const OPTION = Registry::OPTION;

	/**
	 * Package hosts the last sanitize() call of this request stored or kept (null before the first call).
	 *
	 * @var string[]|null
	 */
	private static $accepted_hosts = null;

	/**
	 * What the last sanitize() call of this request returned (null before the first call).
	 *
	 * WordPress sanitizes a second time when update_option() adds the option for the first
	 * time; that pass gets this result back and returns it unchanged (P1-M4).
	 *
	 * @var array<string,mixed>|null
	 */
	private static $accepted = null;

	/**
	 * Problems of single fields found by the running sanitize() call: key => translated message.
	 *
	 * @var array<string,string>
	 */
	private static $field_errors = array();

	/**
	 * Rejected values and their problems of the last form save of the current user, read once per page view.
	 *
	 * @var array{values:array<string,mixed>,errors:array<string,string>}|null
	 */
	private static $rejected = null;

	/**
	 * Seconds the rejected values of a form save are kept for the next page view.
	 */
	private const REJECTED_TTL = 300;

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-settings';

	/**
	 * Register the page, the option and the runtime filters fed by it.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 20 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION, array( self::class, 'capability' ) );
		add_filter( 'wplc_update_package_hosts', array( self::class, 'filter_package_hosts' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'audit_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'audit_updated' ), 10, 2 );
		add_action( \WPLC\Maintenance\DailyRunner::HOURLY_HOOK, array( self::class, 'expire_debug_log' ) );
		if ( defined( 'WPLC_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( WPLC_FILE ), array( self::class, 'plugin_action_links' ) );
		}
	}

	/**
	 * "Getting started" link in the plugin list: the entry point after activation (A-121).
	 *
	 * @param mixed $links Action links.
	 * @return mixed
	 */
	public static function plugin_action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( self::capability() ) ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Getting started', 'wp-license' ) . '</a>' );

		return $links;
	}

	/**
	 * Audit the first save of the option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 * @return void
	 */
	public static function audit_added( $option, $value ): void {
		self::track_debug_log( self::defaults(), $value );
		self::audit_change( self::defaults(), $value );
	}

	/**
	 * Audit a change of the option. WordPress fires this only when the value changed.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function audit_updated( $old_value, $value ): void {
		self::track_debug_log( $old_value, $value );
		self::audit_change( $old_value, $value );
	}

	/**
	 * Start or stop the time of the setting "Verbose log" when its value changes (SET-LIC-29).
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	private static function track_debug_log( $old_value, $value ): void {
		$old = is_array( $old_value ) && isset( $old_value['debug_log'] ) ? (string) $old_value['debug_log'] : 'off';
		$new = is_array( $value ) && isset( $value['debug_log'] ) ? (string) $value['debug_log'] : 'off';
		if ( $old === $new ) {
			return;
		}
		if ( isset( \WPLC\Infrastructure\Log::VERBOSE_PERIODS[ $new ] ) ) {
			update_option( \WPLC\Infrastructure\Log::VERBOSE_UNTIL_OPTION, time() + \WPLC\Infrastructure\Log::VERBOSE_PERIODS[ $new ], false );
		} else {
			delete_option( \WPLC\Infrastructure\Log::VERBOSE_UNTIL_OPTION );
		}
	}

	/**
	 * Switch the setting "Verbose log" back to off once its time has run out (hourly, SET-LIC-29).
	 *
	 * Debug lines already stop at the end of the time (Log::verbose_until()); this makes the
	 * setting say so too, with an entry in the audit log.
	 *
	 * @return void
	 */
	public static function expire_debug_log(): void {
		if ( 'off' === Registry::string( 'debug_log' ) || \WPLC\Infrastructure\Log::verbose_until() > 0 ) {
			return;
		}
		\WPLC\Settings\Changes::apply( array( 'debug_log' => 'off' ), 'settings_changed', 'The verbose log ran out and was switched off.', false );
	}

	/**
	 * Write one `settings_updated` audit entry (object type `settings`, ID 0).
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	private static function audit_change( $old_value, $value ): void {
		$diff = self::settings_diff( is_array( $old_value ) ? $old_value : array(), is_array( $value ) ? $value : array() );
		if ( ! $diff['changed'] ) {
			return;
		}
		AuditLog::record(
			'settings_updated',
			'settings',
			0,
			'Changed: ' . implode( ', ', $diff['changed'] ),
			$diff['before'],
			$diff['after'],
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
		);
	}

	/**
	 * Compare two settings arrays for the audit log.
	 *
	 * Missing keys count as their default. Old and new value of every changed
	 * setting are recorded; free text is shortened (Registry::PREVIEW_LENGTH) and a
	 * setting flagged as secret appears by name only.
	 *
	 * @param array<string,mixed> $old Previous settings.
	 * @param array<string,mixed> $new New settings.
	 * @return array{changed:string[],before:array<string,mixed>,after:array<string,mixed>}
	 */
	public static function settings_diff( array $old, array $new ): array {
		$defaults = self::defaults();
		$old      = array_merge( $defaults, $old );
		$new      = array_merge( $defaults, $new );
		$keys     = array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) );
		sort( $keys );

		$changed = array();
		$before  = array();
		$after   = array();
		foreach ( $keys as $key ) {
			$key       = (string) $key;
			$old_value = $old[ $key ] ?? null;
			$new_value = $new[ $key ] ?? null;
			if ( $old_value === $new_value ) {
				continue;
			}
			$changed[] = $key;
			$definition = Registry::definition( $key );
			if ( null === $definition || $definition['secret'] ) {
				continue;
			}
			$as_text        = in_array( $definition['type'], array( 'string', 'text' ), true );
			$before[ $key ] = $as_text ? Registry::display( $key, $old_value ) : $old_value;
			$after[ $key ]  = $as_text ? Registry::display( $key, $new_value ) : $new_value;
		}

		return array(
			'changed' => $changed,
			'before'  => $before,
			'after'   => $after,
		);
	}

	/**
	 * Default values of all stored settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return Registry::defaults();
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key (see Settings\Definitions).
	 * @param mixed  $default Value when the setting is not stored; null uses the built-in default.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		return Registry::get( $key, $default );
	}

	/**
	 * Reminder lead times as integers, longest first.
	 *
	 * @return int[]
	 */
	public static function reminder_days(): array {
		return array_map( 'intval', explode( ',', self::sanitize_reminder_days( (string) self::get( 'reminder_days' ) ) ) );
	}

	/**
	 * Capability required to view and save the settings.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::cap( 'manage_settings' );
	}

	/**
	 * Whether the current user may change the trusted package hosts.
	 *
	 * The hosts decide from where update packages reach customer sites, so by
	 * default only administrators may change them (B-49).
	 *
	 * @return bool
	 */
	public static function can_manage_package_hosts(): bool {
		return Capabilities::can( 'manage_package_hosts' );
	}

	/**
	 * Fingerprint of a host list; the form sends back the one it was rendered with.
	 *
	 * @param mixed $hosts Stored host list.
	 * @return string
	 */
	public static function hosts_fingerprint( $hosts ): string {
		$hosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();

		return substr( hash( 'sha256', implode( "\n", $hosts ) ), 0, 20 );
	}

	/**
	 * Whether the value being sanitized comes from the settings form (options.php).
	 *
	 * @return bool
	 */
	private static function is_form_submission(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce before the option is sanitized.
		return isset( $_POST['option_page'] ) && self::OPTION === sanitize_key( wp_unslash( $_POST['option_page'] ) );
	}

	/**
	 * Report a problem on the settings screen.
	 *
	 * With a setting key the message is a link to its field, and an error is also shown at
	 * the field (P5-I2). WordPress prints the message as HTML; it is escaped here.
	 *
	 * @param string $code    Error code.
	 * @param string $message Translated message (plain text).
	 * @param string $type    error or warning.
	 * @param string $key     Setting key of the field the message is about, or ''.
	 * @return void
	 */
	private static function settings_error( string $code, string $message, string $type = 'error', string $key = '' ): void {
		if ( '' !== $key && 'error' === $type ) {
			self::$field_errors[ $key ] = $message;
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, '' === $key ? esc_html( $message ) : '<a href="#wplc_' . esc_attr( $key ) . '">' . esc_html( $message ) . '</a>', $type );
		}
	}

	/**
	 * Transient with the rejected values of the current user's last form save.
	 *
	 * @return string
	 */
	private static function rejected_key(): string {
		return 'wplc_settings_rejected_' . ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	/**
	 * The problem of a field after the last form save, with the value that was submitted (P5-I2).
	 *
	 * Read once per page view; the values are kept for a few minutes for the current user only.
	 *
	 * @param string $key Setting key.
	 * @return array{message:string,value:mixed}|null
	 */
	public static function field_problem( string $key ): ?array {
		if ( null === self::$rejected ) {
			self::$rejected = array(
				'values' => array(),
				'errors' => array(),
			);
			$stored = function_exists( 'get_transient' ) ? get_transient( self::rejected_key() ) : false;
			if ( is_array( $stored ) ) {
				delete_transient( self::rejected_key() );
				self::$rejected = array(
					'values' => isset( $stored['values'] ) && is_array( $stored['values'] ) ? $stored['values'] : array(),
					'errors' => isset( $stored['errors'] ) && is_array( $stored['errors'] ) ? array_map( 'strval', $stored['errors'] ) : array(),
				);
			}
		}
		if ( ! isset( self::$rejected['errors'][ $key ] ) ) {
			return null;
		}

		return array(
			'message' => self::$rejected['errors'][ $key ],
			'value'   => self::$rejected['values'][ $key ] ?? null,
		);
	}

	/**
	 * Forget what this request remembered about earlier saves and page views (tests).
	 *
	 * @return void
	 */
	public static function reset_request_state(): void {
		self::$accepted       = null;
		self::$accepted_hosts = null;
		self::$field_errors   = array();
		self::$rejected       = null;
	}

	/**
	 * The `aria-invalid` and `aria-describedby` attributes of a field (P5-I2).
	 *
	 * @param string $key Setting key.
	 * @return string Attributes with a leading space, escaped.
	 */
	private static function field_aria( string $key ): string {
		$id = 'wplc_' . $key;
		if ( null === self::field_problem( $key ) ) {
			return ' aria-describedby="' . esc_attr( $id . '_help' ) . '"';
		}

		return ' aria-invalid="true" aria-describedby="' . esc_attr( $id . '_error ' . $id . '_help' ) . '"';
	}

	/**
	 * Print the problem of a field below it, when the last save had one (P5-I2).
	 *
	 * @param string $key Setting key.
	 * @return void
	 */
	private static function render_field_error( string $key ): void {
		$problem = self::field_problem( $key );
		if ( null !== $problem ) {
			echo '<p class="wplc-field-error" id="' . esc_attr( 'wplc_' . $key . '_error' ) . '"><strong>' . esc_html( $problem['message'] ) . '</strong></p>';
		}
	}

	/**
	 * The value a field shows: the rejected value of the last save, or the stored one (P5-I2).
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $stored Stored value.
	 * @return mixed
	 */
	private static function shown_value( string $key, $stored ) {
		$problem = self::field_problem( $key );

		return null !== $problem && null !== $problem['value'] ? $problem['value'] : $stored;
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License settings', 'wp-license' ),
			__( 'License settings', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License settings', 'wp-license' ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		// Sections and fields come from the register; a field with a renderer of its own
		// (render_<key>_field) keeps it, every other field uses the generic one.
		$titles = Texts::groups();
		foreach ( Registry::GROUPS as $group ) {
			$keys = Registry::keys_of_group( $group );
			if ( ! $keys ) {
				continue;
			}
			add_settings_section( 'wplc_' . $group, $titles[ $group ] ?? $group, '__return_false', self::PAGE );
			foreach ( $keys as $key ) {
				$own = 'render_' . $key . '_field';
				add_settings_field(
					'wplc_' . $key,
					Texts::label( $key ),
					method_exists( self::class, $own ) ? array( self::class, $own ) : array( self::class, 'render_field' ),
					self::PAGE,
					'wplc_' . $group,
					array(
						'label_for' => 'wplc_' . $key,
						'key'       => $key,
					)
				);
			}
		}
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * The result is the stored option with the submitted keys applied: a key that
	 * is missing from the input keeps its stored value, so a partial write or an
	 * old form cannot wipe other settings (B-134). The trusted package hosts are
	 * only changed from the form when the user may change them and the form was
	 * rendered with the list that is stored now; an old browser tab can therefore
	 * not bring back a host that was removed in the meantime. Every other key is
	 * checked with the rules of the register; a value that cannot be accepted is
	 * reported on the screen and the stored value stays.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		if ( null !== self::$accepted && $input === self::$accepted ) {
			// P1-M4: second pass of the same save (update_option() adds the option for the first time
			// and sanitizes again, with our own result). Checks and messages ran once already.
			return self::$accepted;
		}
		$input              = is_array( $input ) ? $input : array();
		$current            = Registry::all();
		$out                = array();
		$rejected           = array();
		self::$field_errors = array();

		foreach ( Registry::definitions() as $key => $definition ) {
			$key = (string) $key;
			if ( 'package_hosts' === $key ) {
				$out[ $key ] = self::sanitize_package_hosts( $input, $current[ $key ] );
				if ( isset( self::$field_errors[ $key ] ) && array_key_exists( $key, $input ) ) {
					$rejected[ $key ] = $input[ $key ];
				}
				continue;
			}

			// What is stored now, brought into the valid form (an old or hand-edited value never survives a save).
			$kept        = Registry::validate( $key, $current[ $key ] );
			$out[ $key ] = $kept['ok'] ? $kept['value'] : $definition['default'];
			if ( ! empty( $definition['resettable'] ) && ! empty( $input[ $key . '_reset' ] ) ) {
				// SET-LIC-09: "Reset to default" of a list field wins over the text submitted with it.
				if ( ! self::is_form_submission() || Capabilities::can( (string) $definition['capability'] ) ) {
					$out[ $key ] = $definition['default'];
				}
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			if ( self::is_form_submission() && ! Capabilities::can( (string) $definition['capability'] ) ) {
				continue;
			}
			if ( '' !== Registry::locking_constant( $key ) ) {
				// SET-LIC-30: a constant in wp-config.php wins; the stored value stays.
				continue;
			}
			$result = Registry::validate( $key, $input[ $key ] );
			if ( $result['ok'] && $result['value'] !== $out[ $key ] && Registry::is_locked( $key ) ) {
				// E-04: the license key prefix is fixed once a license was issued; the stored value stays.
				self::settings_error( 'wplc_locked_' . $key, Texts::problem( $key, 'locked' ), 'error', $key );
				$rejected[ $key ] = $input[ $key ];
				continue;
			}
			if ( $result['ok'] && 'uninstall_data' === $key && 'delete' === $result['value'] && 'delete' !== $out[ $key ] && self::is_form_submission() && empty( $input['uninstall_data_confirm'] ) ) {
				// S10: deleting data is only chosen with an explicit confirmation.
				self::settings_error( 'wplc_uninstall_not_confirmed', __( '"When the plugin is deleted" was not changed: tick the confirmation next to it to choose "Delete tables and options".', 'wp-license' ), 'error', $key );
				$rejected[ $key ] = $input[ $key ];
				continue;
			}
			if ( $result['ok'] ) {
				$out[ $key ] = $result['value'];
				$dropped     = Registry::discarded( $key, $input[ $key ], $result['value'] );
				if ( $dropped ) {
					// SET-LIC-37: entries that a list leaves out are reported, not dropped silently.
					self::settings_error(
						'wplc_discarded_' . $key,
						sprintf(
							/* translators: 1: name of a setting, 2: list of values, 3: smallest value, 4: largest value, 5: largest number of entries. */
							__( '"%1$s": not kept: %2$s. Enter whole numbers from %3$s to %4$s, at most %5$s.', 'wp-license' ),
							Texts::label( $key ),
							implode( ', ', $dropped ),
							number_format_i18n( (int) ( $definition['min'] ?? 0 ) ),
							number_format_i18n( (int) ( $definition['max'] ?? 0 ) ),
							number_format_i18n( (int) ( $definition['max_items'] ?? 0 ) )
						),
						'warning',
						$key
					);
				}
				continue;
			}
			// SET-LIC-01: a value that cannot be accepted is reported and the stored value stays.
			self::settings_error( 'wplc_invalid_' . $key, Texts::problem( $key, $result['code'] ), 'error', $key );
			$rejected[ $key ] = $input[ $key ];
		}

		// SET-LIC-25: two pages of the customer account cannot share an address; the stored addresses stay.
		// The same check guards import, reset and WP-CLI (Settings\Changes).
		$taken = self::account_slug_conflict( $out );
		if ( '' !== $taken ) {
			/* translators: %s: address part of a page, e.g. "orders". */
			$message = sprintf( __( 'The addresses of the customer account were not changed: "%s" is already the address of another page of the account. Choose an address of its own for each page.', 'wp-license' ), $taken );
			$first   = '';
			foreach ( \WPLC\Settings\Changes::ACCOUNT_SLUGS as $slug_key ) {
				$kept = Registry::validate( $slug_key, $current[ $slug_key ] );
				$kept = $kept['ok'] ? $kept['value'] : Registry::defaults()[ $slug_key ];
				if ( $kept !== $out[ $slug_key ] ) {
					$rejected[ $slug_key ]           = $input[ $slug_key ] ?? $out[ $slug_key ];
					self::$field_errors[ $slug_key ] = $message;
					$first                           = '' === $first ? $slug_key : $first;
				}
				$out[ $slug_key ] = $kept;
			}
			self::settings_error( 'wplc_account_slug_taken', $message, 'error', $first );
		}

		foreach ( self::combination_warnings( $out ) as $code => $message ) {
			self::settings_error( $code, $message, 'warning', 'wplc_grace_before_last_retry' === $code ? 'renewal_grace_days' : '' );
		}

		if ( self::is_form_submission() && function_exists( 'set_transient' ) ) {
			if ( self::$field_errors ) {
				// P5-I2: the next page view shows the problem at each field, with the value that was submitted.
				set_transient(
					self::rejected_key(),
					array(
						'values' => array_intersect_key( $rejected, self::$field_errors ),
						'errors' => self::$field_errors,
					),
					self::REJECTED_TTL
				);
			} else {
				delete_transient( self::rejected_key() );
			}
		}
		self::$accepted = $out;

		return $out;
	}

	/**
	 * An address of the customer account that two pages would share (SET-LIC-25).
	 *
	 * @param array<string,mixed> $values Settings in stored form.
	 * @return string The address in conflict, or '' when every page has its own.
	 */
	public static function account_slug_conflict( array $values ): string {
		return \WPLC\Settings\Changes::account_slug_conflict( $values );
	}

	/**
	 * Warnings about values that are each valid but do not fit together. The values are saved all the same.
	 *
	 * @param array<string,mixed> $values Settings in stored form.
	 * @return array<string,string> Code => translated message.
	 */
	public static function combination_warnings( array $values ): array {
		return \WPLC\Settings\Changes::combination_warnings( $values );
	}

	/**
	 * The trusted package hosts after a save.
	 *
	 * @param array<string,mixed> $input   Submitted values.
	 * @param mixed               $current Stored list.
	 * @return string[]
	 */
	private static function sanitize_package_hosts( array $input, $current ): array {
		$current_hosts = is_array( $current ) ? array_values( array_filter( $current, 'is_string' ) ) : array();
		$hosts_out     = $current_hosts;
		if ( array_key_exists( 'package_hosts', $input ) ) {
			$raw_hosts = $input['package_hosts'];
			if ( is_array( $raw_hosts ) ) {
				// Already sanitized (WordPress may run the callback twice when the option is first added).
				$raw_hosts = implode( "\n", array_filter( $raw_hosts, 'is_string' ) );
			}
			$hosts = self::parse_hosts( is_string( $raw_hosts ) ? $raw_hosts : '' );
			if ( $hosts['invalid'] ) {
				self::settings_error(
					'wplc_invalid_hosts',
					sprintf(
						/* translators: %d: number of rejected lines. */
						_n(
							'%d package host line was not a valid host name and was ignored. Enter host names only, without https:// or a path.',
							'%d package host lines were not valid host names and were ignored. Enter host names only, without https:// or a path.',
							count( $hosts['invalid'] ),
							'wp-license'
						),
						count( $hosts['invalid'] )
					),
					'error',
					'package_hosts'
				);
			}
			$max_hosts = (int) ( Registry::definition( 'package_hosts' )['max_items'] ?? 50 );

			if ( count( $hosts['valid'] ) > $max_hosts ) {
				// P1-M7: a list beyond the limit is rejected and reported, never cut silently.
				self::settings_error( 'wplc_invalid_package_hosts', Texts::problem( 'package_hosts', 'too_many' ), 'error', 'package_hosts' );
			} elseif ( is_array( $input['package_hosts'] ) && null !== self::$accepted_hosts && $hosts['valid'] === self::$accepted_hosts ) {
				// Second pass of the same save: WordPress sanitizes again when it adds the option
				// for the first time, now with our own result and without the form's fingerprint.
				// Only the list this request already accepted passes; a posted array does not.
				$hosts_out = self::$accepted_hosts;
			} elseif ( $hosts['valid'] !== $current_hosts ) {
				$seen = isset( $input['package_hosts_seen'] ) && is_string( $input['package_hosts_seen'] ) ? $input['package_hosts_seen'] : '';
				if ( ! self::is_form_submission() ) {
					// WP-CLI, tests and other code: no browser tab can be stale.
					$hosts_out = $hosts['valid'];
				} elseif ( ! self::can_manage_package_hosts() ) {
					self::settings_error( 'wplc_hosts_forbidden', __( 'The trusted package hosts were not changed: only an administrator may change them.', 'wp-license' ) );
				} elseif ( ! hash_equals( self::hosts_fingerprint( $current_hosts ), $seen ) ) {
					self::settings_error( 'wplc_hosts_stale', __( 'The trusted package hosts were not changed: someone else changed them after you opened this page. Check the list below and save again.', 'wp-license' ), 'error', 'package_hosts' );
				} else {
					$hosts_out = $hosts['valid'];
				}
			}
		}

		self::$accepted_hosts = $hosts_out;

		return $hosts_out;
	}

	/**
	 * Split a textarea into valid and rejected host names.
	 *
	 * @param string $raw One host per line.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_hosts( string $raw ): array {
		return Registry::parse_hosts( $raw );
	}

	/**
	 * Validate a DNS host name (no scheme, port, path, wildcard or IP address).
	 *
	 * @param string $host Lowercase candidate.
	 * @return bool
	 */
	public static function is_valid_hostname( string $host ): bool {
		return Registry::is_valid_hostname( $host );
	}

	/**
	 * Normalize a comma separated list of reminder lead days.
	 *
	 * @param string $raw For example "30, 7".
	 * @return string Unique values between 1 and 365, longest first; the default when nothing valid remains.
	 */
	public static function sanitize_reminder_days( string $raw ): string {
		$result = Registry::validate( 'reminder_days', $raw );

		return $result['ok'] ? (string) $result['value'] : (string) Registry::defaults()['reminder_days'];
	}

	/**
	 * Add the configured hosts to the update package allow-list.
	 *
	 * @param mixed $hosts Hosts from earlier filters.
	 * @return array<int,mixed>
	 */
	public static function filter_package_hosts( $hosts ) {
		$hosts      = is_array( $hosts ) ? $hosts : array();
		$configured = self::get( 'package_hosts' );
		if ( ! is_array( $configured ) ) {
			return $hosts;
		}
		foreach ( $configured as $host ) {
			if ( is_string( $host ) && self::is_valid_hostname( strtolower( $host ) ) && ! in_array( strtolower( $host ), $hosts, true ) ) {
				$hosts[] = strtolower( $host );
			}
		}
		return $hosts;
	}

	/**
	 * Load the copy-button script on the settings page.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		// B-124: the version follows the file, so an update is not hidden by browser or CDN caches.
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/js/wplc-ui.js' ), true );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change license settings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap wplc-settings"><h1>' . esc_html__( 'License settings', 'wp-license' ) . '</h1>';
		self::render_notices();
		self::render_setup_steps();

		echo '<h2>' . esc_html__( 'Status', 'wp-license' ) . '</h2>';
		// P5-M14: a data table (row headers), so no role="presentation".
		echo '<table class="widefat striped"><tbody>';
		foreach ( self::status_rows() as $row ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $row['label'] ) . '</th><td>';
			if ( isset( $row['copy'] ) ) {
				echo '<label class="screen-reader-text" for="wplc-webhook-url">' . esc_html( $row['label'] ) . '</label>';
				echo '<input type="text" readonly class="regular-text code" id="wplc-webhook-url" value="' . esc_attr( $row['copy'] ) . '"> ';
				echo '<button type="button" class="button" data-wplc-copy="wplc-webhook-url" data-wplc-copied="' . esc_attr__( 'Copied', 'wp-license' ) . '" data-wplc-copy-failed="' . esc_attr__( 'Copying failed. Select the address and copy it by hand.', 'wp-license' ) . '">' . esc_html__( 'Copy', 'wp-license' ) . '</button>';
				echo ' <span role="status" aria-live="polite" data-wplc-copy-status="wplc-webhook-url"></span>';
			} else {
				$state = $row['state'] ?? '';
				// SET-LIC-38: screen readers hear the state; the icon alone is hidden from them (as on the diagnostics page).
				if ( 'ok' === $state ) {
					echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( self::state_text( $state ) ) . '</span> ';
				} elseif ( 'warning' === $state ) {
					echo '<span class="dashicons dashicons-warning" style="color:#d63638" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( self::state_text( $state ) ) . '</span> ';
				}
				echo esc_html( $row['value'] );
			}
			if ( ! empty( $row['help'] ) ) {
				echo '<p class="description">' . esc_html( $row['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Secrets are never stored in the database. Define them as constants in wp-config.php.', 'wp-license' ) . '</p>';
		self::render_capability_overview();

		$tab = self::current_tab();
		self::render_tabs( $tab );
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION );
		self::render_sections( $tab );
		submit_button();
		echo '</form>';
		/**
		 * Fires below the settings form: tools that work on the settings (country profiles, export, import, reset).
		 *
		 * @since 0.2.0
		 *
		 * @param string $tab Current tab: a group key, or '' for all settings.
		 */
		do_action( 'wplc_settings_page_tools', $tab );
		echo '</div>';
	}

	/**
	 * The messages of the last save: the problems of the settings, then "Settings saved." after a
	 * save without any message (P5-I1). WordPress stores that one under `general`, which
	 * settings_errors( self::OPTION ) leaves out; it is shown once, on the page view after the save.
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		settings_errors( self::OPTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( empty( $_GET['settings-updated'] ) ) {
			return;
		}
		foreach ( get_settings_errors( 'general' ) as $error ) {
			if ( 'settings_updated' === $error['code'] && 'success' === $error['type'] ) {
				echo '<div id="setting-error-settings_updated" class="notice notice-success settings-error is-dismissible" role="status"><p><strong>' . esc_html__( 'Settings saved.', 'wp-license' ) . '</strong></p></div>';
				break;
			}
		}
		// Only once: a second call in this request, or a reload of the address, shows nothing.
		$GLOBALS['wp_settings_errors'] = array_values(
			array_filter(
				(array) ( $GLOBALS['wp_settings_errors'] ?? array() ),
				static function ( $error ) {
					return ! ( is_array( $error ) && 'general' === ( $error['setting'] ?? '' ) && 'settings_updated' === ( $error['code'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * Why a field is read-only for the current user.
	 *
	 * @param string $capability Capability key of the setting.
	 * @return string
	 */
	public static function readonly_note( string $capability ): string {
		if ( 'manage_policy' === $capability ) {
			return __( 'This is a manufacturer rule that acts on every customer site: only users with the capability wplc_manage_policy (by default administrators) may change it.', 'wp-license' );
		}

		return __( 'Only an administrator may change this value.', 'wp-license' );
	}

	/**
	 * Rows of the overview "Who may do what" (SET-LIC-31).
	 *
	 * @return array<int,array{capability:string,allows:string,default:string,granted:bool}>
	 */
	public static function capability_rows(): array {
		$rows         = array();
		$descriptions = Capabilities::descriptions();
		foreach ( Capabilities::defaults() as $capability => $default ) {
			$rows[] = array(
				'capability' => $capability,
				'allows'     => $descriptions[ $capability ] ?? '',
				'default'    => 'manage_options' === $default ? __( 'Administrators', 'wp-license' ) : ( 'manage_woocommerce' === $default ? __( 'Shop managers and administrators', 'wp-license' ) : $default ),
				'granted'    => function_exists( 'current_user_can' ) && current_user_can( Capabilities::cap( $capability ) ),
			);
		}

		return $rows;
	}

	/**
	 * Settings that need a capability other than wplc_manage_settings, by capability.
	 *
	 * @return array<string,string[]> Capability key => labels of the settings.
	 */
	public static function restricted_settings(): array {
		$out = array();
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( 'manage_settings' !== $definition['capability'] ) {
				$out[ (string) $definition['capability'] ][] = Texts::label( (string) $key );
			}
		}

		return $out;
	}

	/**
	 * Print the overview "Who may do what": every capability of the plugin, its default and
	 * whether the current user holds it, and the settings that need more than wplc_manage_settings.
	 *
	 * @return void
	 */
	private static function render_capability_overview(): void {
		echo '<details class="wplc-capabilities"><summary><strong>' . esc_html__( 'Who may do what', 'wp-license' ) . '</strong></summary>';
		echo '<p class="description">' . esc_html__( 'The capabilities of the plugin are not stored on roles; each one follows a WordPress capability unless a role stores it explicitly (for example with a role editor plugin or wp cap add). Developers can change the mapping with the filter wplc_capability.', 'wp-license' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Capability', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Allows', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'By default', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'You', 'wp-license' ) . '</th></tr></thead><tbody>';
		foreach ( self::capability_rows() as $row ) {
			echo '<tr><td><code>' . esc_html( $row['capability'] ) . '</code></td><td>' . esc_html( $row['allows'] ) . '</td><td>' . esc_html( $row['default'] ) . '</td><td>' . esc_html( $row['granted'] ? __( 'yes', 'wp-license' ) : __( 'no', 'wp-license' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		foreach ( self::restricted_settings() as $capability => $labels ) {
			/* translators: 1: capability name, 2: list of settings. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Settings that need %1$s: %2$s.', 'wp-license' ), 'wplc_' . $capability, implode( ', ', $labels ) ) ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * The state of a status row as a word (SET-LIC-38).
	 *
	 * @param string $state ok or warning.
	 * @return string Empty for a row without a state.
	 */
	public static function state_text( string $state ): string {
		if ( 'ok' === $state ) {
			/* translators: State of a row of the status table: everything is fine. */
			return __( 'OK:', 'wp-license' );
		}
		if ( 'warning' === $state ) {
			/* translators: State of a row of the status table: something needs to be done. */
			return __( 'Attention:', 'wp-license' );
		}

		return '';
	}

	/**
	 * Keys of the setup steps, in the order a new vendor needs them (A-121, PROD-09).
	 */
	public const SETUP_STEPS = array( 'secrets', 'public_key', 'catalog', 'product', 'rules', 'release', 'test', 'webhook' );

	/**
	 * Option that records when the questions of the step "Decide the rules of your shop" were answered (SET-LIC-45).
	 */
	public const RULES_DECIDED_OPTION = 'wplc_setup_rules_decided';

	/**
	 * What the site already has, one flag per setup step (PROD-09). Reads only; creates no key.
	 *
	 * - secrets: license key secrets and installation encryption keys come from wp-config.php.
	 * - public_key: the response signing key comes from wp-config.php (WPLC_RESPONSE_SIGNING_KEY) and is usable, so
	 *   the public key the add-on pins cannot change with a restored or copied database.
	 * - catalog: at least one catalog file was imported (by upload, WP-CLI or a release package); the copies shipped
	 *   in catalogs/ do not count.
	 * - product: at least one active plan. release: at least one published release. test: at least one license.
	 * - rules: the questions of the guided step (country profile, staging, grace period) were answered (SET-LIC-45).
	 * - webhook: a Stripe webhook signing secret is configured.
	 *
	 * @return array<string,bool> Step key => done.
	 */
	public static function setup_state(): array {
		global $wpdb;

		$tables  = Schema::table_names();
		$catalog = false;
		foreach ( \WPLC\Catalog\CatalogStore::all() as $product ) {
			if ( \WPLC\Catalog\CatalogStore::SOURCE_BUNDLED !== (string) ( $product['source'] ?? '' ) ) {
				$catalog = true;
				break;
			}
		}

		return array(
			'secrets'    => ! self::uses_fallback( '\\WPLC\\License\\LicenseKey', 'uses_fallback_secret', array( 'WPLC_LICENSE_KEY_SECRETS', 'WPLC_LICENSE_KEY_SECRET' ) )
				&& ! self::uses_fallback( '\\WPLC\\Security\\SecretCrypto', 'uses_fallback_key', array( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', 'WPLC_INSTALLATION_ENCRYPTION_KEY' ) ),
			// Only the constant is read: status() of a stored key would create one on an empty installation.
			'public_key' => \WPLC\Security\ResponseSigner::uses_constant() && 'ok' === \WPLC\Security\ResponseSigner::status()['state'],
			'catalog'    => $catalog,
			'product'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE active = 1 LIMIT 1', $tables['plans'] ) ) > 0,
			'rules'      => '' !== (string) get_option( self::RULES_DECIDED_OPTION, '' ),
			'release'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s LIMIT 1', $tables['releases'], 'published' ) ) > 0,
			'test'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $tables['licenses'] ) ) > 0,
			'webhook'    => \WPLC\Api\V1\StripeWebhook::is_configured(),
		);
	}

	/**
	 * Steps from activation to the first sold license, with the state of this site (A-121, PROD-09).
	 *
	 * @return array<int,array{key:string,title:string,text:string,done:bool,link:string,link_text:string}>
	 */
	public static function setup_steps(): array {
		return self::setup_steps_for( self::setup_state() );
	}

	/**
	 * The setup checklist for a given state. Pure apart from translation; `link` is relative to wp-admin/.
	 *
	 * @param array<string,bool> $state Step key => done (see setup_state()); a missing key counts as not done.
	 * @return array<int,array{key:string,title:string,text:string,done:bool,link:string,link_text:string}>
	 */
	public static function setup_steps_for( array $state ): array {
		$diagnostics = 'admin.php?page=' . Diagnostics::PAGE;
		$steps       = array(
			'secrets'    => array(
				'title'     => __( 'Define the secrets in wp-config.php', 'wp-license' ),
				'text'      => __( 'Set WPLC_LICENSE_KEY_SECRETS (with WPLC_LICENSE_KEY_ID) and WPLC_INSTALLATION_ENCRYPTION_KEYS before the first license is sold. Keys issued without them depend on the WordPress salts and stop working when the salts change.', 'wp-license' ),
				'link'      => $diagnostics,
				'link_text' => __( 'Open diagnostics', 'wp-license' ),
			),
			'public_key' => array(
				'title'     => __( 'Pin the public key in your add-on', 'wp-license' ),
				'text'      => __( 'Put the response signing key into wp-config.php as WPLC_RESPONSE_SIGNING_KEY ("wp wplc signing-key --generate") and give its public key to the license client of your add-on (option public_keys).', 'wp-license' ),
				'link'      => $diagnostics,
				'link_text' => __( 'Show the public key', 'wp-license' ),
			),
			'catalog'    => array(
				'title'     => __( 'Import your catalog file', 'wp-license' ),
				'text'      => __( 'Describe your product and its entitlements in a wplc-catalog.json file (docs/manual/03-catalog-files.md) and import it here, or ship it in the plugin folder of your release package.', 'wp-license' ),
				'link'      => 'admin.php?page=' . CatalogFileAdmin::PAGE,
				'link_text' => __( 'Open catalog files', 'wp-license' ),
			),
			'product'    => array(
				'title'     => __( 'Turn a product into a license product', 'wp-license' ),
				'text'      => __( 'Edit the WooCommerce product, tick "License product", enter the product key of your catalog file (for example acme-pro) and a plan key (for example single), and tick the entitlements of the plan. Keep both keys unchanged once the product is sold.', 'wp-license' ),
				'link'      => 'edit.php?post_type=product',
				'link_text' => __( 'Open products', 'wp-license' ),
			),
			'rules'      => array(
				'title'     => __( 'Decide the rules of your shop', 'wp-license' ),
				'text'      => __( 'Answer three questions before the first sale: which country profile fits your shop (retention, consent and notice periods), how many staging copies a customer may run next to each live site, and how long a customer keeps access when an automatic payment fails. Every answer can be changed later in the settings below.', 'wp-license' ),
				'link'      => '',
				'link_text' => '',
			),
			'release'    => array(
				'title'     => __( 'Publish the first release', 'wp-license' ),
				'text'      => __( 'Upload the add-on package and publish it, so that licensed sites can download it and receive updates.', 'wp-license' ),
				'link'      => 'admin.php?page=' . ReleaseAdmin::PAGE,
				'link_text' => __( 'Open license releases', 'wp-license' ),
			),
			'test'       => array(
				'title'     => __( 'Make a test purchase', 'wp-license' ),
				'text'      => __( 'Buy the product with a test customer, check the license under Licenses and activate it in the add-on on a test site. The diagnostics page shows whether the daily background run works.', 'wp-license' ),
				'link'      => 'admin.php?page=' . LicenseAdmin::PAGE,
				'link_text' => __( 'Open licenses', 'wp-license' ),
			),
			'webhook'    => array(
				'title'     => __( 'Connect the Stripe webhook', 'wp-license' ),
				'text'      => __( 'In the Stripe dashboard, add an endpoint with the webhook URL shown under "Status" below and the events listed in the documentation (docs/manual/08-renewals-and-stripe.md), then put its signing secret into WPLC_STRIPE_WEBHOOK_SECRET. Without it, renewals, refunds and disputes reported by Stripe are rejected.', 'wp-license' ),
				'link'      => '',
				'link_text' => '',
			),
		);

		$list = array();
		foreach ( self::SETUP_STEPS as $key ) {
			$list[] = array( 'key' => $key ) + $steps[ $key ] + array( 'done' => ! empty( $state[ $key ] ) );
		}

		return $list;
	}

	/**
	 * "Getting started" checklist; folded away once every step is done (A-121, PROD-09).
	 *
	 * @return void
	 */
	private static function render_setup_steps(): void {
		$steps = self::setup_steps();
		$done  = count( array_filter( array_column( $steps, 'done' ) ) );
		$open  = $done < count( $steps );

		// P5-M14: the heading stands before the box; a heading inside <summary> loses its role.
		echo '<h2 id="wplc-setup-title">' . esc_html__( 'Getting started', 'wp-license' ) . '</h2>';
		echo '<details class="wplc-setup" aria-labelledby="wplc-setup-title" data-wplc-setup-done="' . esc_attr( (string) $done ) . '"' . ( $open ? ' open' : '' ) . '><summary>';
		/* translators: 1: number of finished setup steps, 2: number of all setup steps. */
		echo '<span>' . esc_html( sprintf( __( '%1$s of %2$s steps done', 'wp-license' ), number_format_i18n( $done ), number_format_i18n( count( $steps ) ) ) ) . '</span></summary>';
		echo '<ol>';
		foreach ( $steps as $step ) {
			echo '<li data-wplc-step="' . esc_attr( $step['key'] ) . '" data-wplc-step-done="' . ( $step['done'] ? '1' : '0' ) . '"><strong>' . esc_html( $step['title'] ) . '</strong> ';
			if ( $step['done'] ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> <span>' . esc_html__( 'Done', 'wp-license' ) . '</span>';
			} else {
				echo '<span>' . esc_html__( 'To do', 'wp-license' ) . '</span>';
			}
			echo '<br><span class="description">' . esc_html( $step['text'] ) . '</span>';
			if ( '' !== $step['link'] ) {
				echo ' <a href="' . esc_url( admin_url( $step['link'] ) ) . '">' . esc_html( $step['link_text'] ) . '</a>';
			}
			if ( 'rules' === $step['key'] ) {
				// SET-LIC-45: the questions are part of the step; answered, they fold away and can be opened again.
				SettingsTools::render_guided_questions( $step['done'] );
			}
			echo '</li>';
		}
		echo '</ol></details>';
	}

	/**
	 * Build the read-only status rows.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function status_rows(): array {
		$rows = array();

		$schema = (string) get_option( 'wplc_schema_version', '0' );
		$rows[] = array(
			'label' => __( 'Database schema version', 'wp-license' ),
			/* translators: 1: installed schema version, 2: schema version expected by the code. */
			'value' => sprintf( __( '%1$s (expected: %2$s)', 'wp-license' ), $schema, Schema::VERSION ),
			'state' => version_compare( $schema, Schema::VERSION, '>=' ) ? 'ok' : 'warning',
		);

		// Live and test secrets, per site on a network: the webhook class knows all sources.
		$webhook_secret = \WPLC\Api\V1\StripeWebhook::is_configured();
		$rows[]         = array(
			'label' => __( 'Stripe webhook secret', 'wp-license' ),
			'value' => $webhook_secret ? __( 'A webhook signing secret is configured in wp-config.php.', 'wp-license' ) : __( 'No webhook signing secret is configured (WPLC_STRIPE_WEBHOOK_SECRET in wp-config.php). Stripe events are rejected.', 'wp-license' ),
			'state' => $webhook_secret ? 'ok' : 'warning',
		);
		$rows[]         = array(
			'label' => __( 'Stripe webhook URL', 'wp-license' ),
			'copy'  => rest_url( 'wplc/v1/webhooks/stripe' ),
			'help'  => __( 'Enter this URL as the endpoint in the Stripe dashboard.', 'wp-license' ),
		);

		$license_fallback = self::uses_fallback(
			'\\WPLC\\License\\LicenseKey',
			'uses_fallback_secret',
			array( 'WPLC_LICENSE_KEY_SECRETS', 'WPLC_LICENSE_KEY_SECRET' )
		);
		$rows[]           = array(
			'label' => __( 'License key secret', 'wp-license' ),
			'value' => $license_fallback
				? __( 'Not configured. Keys are derived from the WordPress salts; rotating the salts invalidates every issued key. Define WPLC_LICENSE_KEY_SECRETS.', 'wp-license' )
				: __( 'Configured through a constant.', 'wp-license' ),
			'state' => $license_fallback ? 'warning' : 'ok',
		);

		$crypto_fallback = self::uses_fallback(
			'\\WPLC\\Security\\SecretCrypto',
			'uses_fallback_key',
			array( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', 'WPLC_INSTALLATION_ENCRYPTION_KEY' )
		);
		$rows[]          = array(
			'label' => __( 'Installation secret encryption key', 'wp-license' ),
			'value' => $crypto_fallback
				? __( 'Not configured. The WordPress salts are used; define WPLC_INSTALLATION_ENCRYPTION_KEYS.', 'wp-license' )
				: __( 'Configured through a constant.', 'wp-license' ),
			'state' => $crypto_fallback ? 'warning' : 'ok',
		);

		$sample = Diagnostics::key_sample();
		if ( $sample['checked'] > 0 ) {
			$rows[] = array(
				'label' => __( 'License keys verifiable with the current secret', 'wp-license' ),
				/* translators: 1: number of verifiable keys, 2: number of checked keys. */
				'value' => sprintf( _n( '%1$s of the %2$s most recent key', '%1$s of the %2$s most recent keys', $sample['checked'], 'wp-license' ), number_format_i18n( $sample['verified'] ), number_format_i18n( $sample['checked'] ) ),
				'state' => $sample['verified'] === $sample['checked'] ? 'ok' : 'warning',
				'help'  => $sample['verified'] === $sample['checked'] ? '' : __( 'Some customers see "not available" instead of their key. The secret or the salts were changed. Restore the previous secret as an additional entry in WPLC_LICENSE_KEY_SECRETS, or run "wp wplc recover-license-key-ids".', 'wp-license' ),
			);
		}

		// A-52: say why automatic renewal is unavailable.
		$stripe = Requirements::stripe_adapter_status();
		$rows[] = array(
			'label' => __( 'Automatic renewal (Stripe)', 'wp-license' ),
			'value' => $stripe['ok']
				? ( '' !== $stripe['reason'] ? $stripe['reason'] : __( 'Available. Customers can opt in at checkout.', 'wp-license' ) )
				/* translators: %s: reason, a full sentence. */
				: sprintf( __( 'Switched off. %s Customers are not offered automatic renewal and renew manually in their account.', 'wp-license' ), $stripe['reason'] ),
			'state' => $stripe['ok'] ? 'ok' : 'warning',
		);

		$scheduler = function_exists( 'as_schedule_single_action' );
		$rows[]    = array(
			'label' => __( 'Action Scheduler', 'wp-license' ),
			'value' => $scheduler ? __( 'Available', 'wp-license' ) : __( 'Unavailable. Scheduled work falls back to WP-Cron, which is less reliable.', 'wp-license' ),
			'state' => $scheduler ? 'ok' : 'warning',
		);

		$block_checkout   = self::uses_block_checkout();
		$block_supported  = function_exists( 'woocommerce_register_additional_checkout_field' );
		$checkout_message = __( 'Classic checkout', 'wp-license' );
		if ( $block_checkout ) {
			$checkout_message = $block_supported
				? __( 'Block checkout. The option for automatic renewal is offered as an additional checkout field.', 'wp-license' )
				: __( 'The checkout page uses the block checkout, but this WooCommerce version has no additional checkout fields. Licenses bought here never renew automatically.', 'wp-license' );
		}
		$rows[] = array(
			'label' => __( 'Checkout type', 'wp-license' ),
			'value' => $checkout_message,
			'state' => $block_checkout && ! $block_supported ? 'warning' : 'ok',
		);

		$rows[] = array(
			// P5-M8: the same name as the field of the setting.
			'label' => Texts::label( 'renewal_grace_days' ),
			/* translators: %d: number of days. */
			'value' => sprintf( _n( '%d day', '%d days', LicenseLifecycle::grace_days(), 'wp-license' ), LicenseLifecycle::grace_days() ),
		);

		// B-45: ended licenses are detached from the customer ten years after the end of the year (setting retention_anonymize_licenses, filter wplc_retention_days).
		$anonymize = \WPLC\Maintenance\Retention::configured_days()['anonymize_licenses'];
		$rows[]    = array(
			'label' => __( 'Anonymisation of ended licenses', 'wp-license' ),
			'value' => $anonymize > 0
				/* translators: %s: number of days. */
				? sprintf( _n( '%s day after the end of the year in which the license ended', '%s days after the end of the year in which the license ended', $anonymize, 'wp-license' ), number_format_i18n( $anonymize ) )
				: __( 'Off. Ended licenses stay linked to the customer account until an erasure request or the deletion of the account. Set a period that matches your privacy notice under "Legal and privacy" below.', 'wp-license' ),
			'state' => $anonymize > 0 ? 'ok' : '',
		);

		return $rows;
	}

	/**
	 * Whether a secret falls back to the WordPress salts.
	 *
	 * @param string   $class     Class that may report its own state.
	 * @param string   $method    Static reporting method (provided by another work package).
	 * @param string[] $constants Constants that configure the secret.
	 * @return bool
	 */
	private static function uses_fallback( string $class, string $method, array $constants ): bool {
		if ( class_exists( $class ) && method_exists( $class, $method ) ) {
			return (bool) call_user_func( array( $class, $method ) );
		}
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) && ! empty( constant( $constant ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the WooCommerce checkout page contains the checkout block.
	 *
	 * @return bool
	 */
	private static function uses_block_checkout(): bool {
		if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$page_id = (int) wc_get_page_id( 'checkout' );

		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Render a field from its definition in the register (every setting without a renderer of its own).
	 *
	 * @param mixed $args Arguments of add_settings_field(): `key`.
	 * @return void
	 */
	public static function render_field( $args ): void {
		$key        = is_array( $args ) && isset( $args['key'] ) ? (string) $args['key'] : '';
		$definition = Registry::definition( $key );
		if ( null === $definition ) {
			return;
		}
		$id       = 'wplc_' . $key;
		$name     = self::OPTION . '[' . $key . ']';
		$value    = Registry::get( $key );
		$constant = Registry::locking_constant( $key );
		$locked   = Registry::is_locked( $key );
		$readonly = $locked || ! Capabilities::can( (string) $definition['capability'] ) || '' !== $constant;
		if ( '' !== $constant ) {
			// SET-LIC-30: the field shows the value of the constant, which wins (only settings that are no secret have one).
			$value = constant( $constant );
			$value = 'bool' === $definition['type'] ? ( empty( $value ) ? 0 : 1 ) : ( is_scalar( $value ) ? (string) $value : '' );
		}
		// A read-only field has no name: a missing key keeps the stored value.
		$name_attr = $readonly ? '' : ' name="' . esc_attr( $name ) . '"';
		$lock      = $readonly ? ' readonly' : '';
		$help      = self::field_aria( $key );
		$refilled  = false;
		if ( ! $readonly && null !== self::field_problem( $key ) ) {
			// P5-I2: the field shows the value that was submitted and rejected, so it can be corrected.
			$value    = self::shown_value( $key, $value );
			$refilled = true;
		}

		switch ( $definition['type'] ) {
			case 'bool':
				if ( $readonly ) {
					// P5-M12: a read-only text field stays reachable with the keyboard and reads its help; `disabled` would hide both.
					echo '<input type="text" id="' . esc_attr( $id ) . '" value="' . esc_attr( empty( $value ) ? __( 'Off', 'wp-license' ) : __( 'On', 'wp-license' ) ) . '" class="small-text" readonly' . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
					break;
				}
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="1"' . checked( ! empty( $value ), true, false ) . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				break;
			case 'int':
				if ( '' !== $constant ) {
					$number = (string) (int) $value;
				} else {
					$number = $refilled && is_scalar( $value ) ? (string) $value : (string) Registry::int( $key );
				}
				echo '<input type="number" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="' . esc_attr( $number ) . '" class="small-text" min="' . esc_attr( (string) (int) ( $definition['min'] ?? 0 ) ) . '" max="' . esc_attr( (string) (int) ( $definition['max'] ?? 0 ) ) . '" step="1"' . $lock . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				if ( 'days' === ( $definition['unit'] ?? '' ) ) {
					echo ' ' . esc_html__( 'days', 'wp-license' );
				}
				break;
			case 'choice':
				$labels = Texts::choices( $key );
				if ( $readonly ) {
					// P5-M12: as text, see above.
					echo '<input type="text" id="' . esc_attr( $id ) . '" value="' . esc_attr( $labels[ (string) $value ] ?? (string) $value ) . '" class="regular-text" readonly' . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
					break;
				}
				echo '<select id="' . esc_attr( $id ) . '"' . $name_attr . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				foreach ( (array) ( $definition['choices'] ?? array() ) as $choice ) {
					echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( is_scalar( $value ) ? (string) $value : '', (string) $choice, false ) . '>' . esc_html( $labels[ $choice ] ?? (string) $choice ) . '</option>';
				}
				echo '</select>';
				break;
			case 'text':
			case 'string_list':
			case 'hosts':
				$text = is_array( $value ) ? implode( "\n", array_filter( $value, 'is_scalar' ) ) : (string) $value;
				$rows = 'text' === $definition['type'] ? 4 : max( 3, min( 12, substr_count( $text, "\n" ) + 2 ) );
				echo '<textarea id="' . esc_attr( $id ) . '"' . $name_attr . ' rows="' . esc_attr( (string) $rows ) . '" cols="50" class="large-text' . ( 'text' === $definition['type'] ? '' : ' code' ) . '"' . ( isset( $definition['max_length'] ) ? ' maxlength="' . esc_attr( (string) (int) $definition['max_length'] ) . '"' : '' ) . $lock . $help . '>' . esc_textarea( $text ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				if ( ! empty( $definition['resettable'] ) && ! $readonly ) {
					echo '<br><label for="' . esc_attr( $id ) . '_reset"><input type="checkbox" id="' . esc_attr( $id ) . '_reset" name="' . esc_attr( self::OPTION . '[' . $key . '_reset]' ) . '" value="1"> ' . esc_html__( 'Reset this list to the default when saving', 'wp-license' ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" class="regular-text"' . $lock . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
		self::render_field_error( $key );

		echo '<p class="description" id="' . esc_attr( $id ) . '_help">' . esc_html( Texts::help( $key ) );
		if ( $definition['legal'] ) {
			echo ' <strong>' . esc_html( Texts::legal_note() ) . '</strong>';
		}
		self::render_filter_note( $key );
		if ( '' !== $constant ) {
			/* translators: %s: name of a PHP constant. */
			echo ' <strong>' . esc_html( sprintf( __( 'Set in wp-config.php with %s; the constant wins over this field.', 'wp-license' ), $constant ) ) . '</strong>';
		}
		if ( $locked ) {
			// E-04: why the field cannot be changed any more.
			echo ' ' . esc_html__( 'Fixed: licenses were already issued with this value, and their keys contain it. Changing it would make every issued key invalid.', 'wp-license' );
		} elseif ( $readonly ) {
			echo ' ' . esc_html( self::readonly_note( (string) $definition['capability'] ) );
		}
		echo '</p>';
		if ( '' === $constant ) {
			self::render_effective_value( $key );
		}
	}

	/**
	 * The last field of the object storage, followed by its state and the connection test (SET-LIC-30).
	 *
	 * The button submits the form of the settings tools below the settings form (a form cannot be nested).
	 *
	 * @return void
	 */
	public static function render_s3_path_style_field(): void {
		self::render_field( array( 'key' => 's3_path_style' ) );
		$config = \WPLC\Update\S3Storage::config();
		echo '<div id="wplc-object-storage" class="wplc-object-storage">';
		if ( 'configured' === $config['state'] ) {
			/* translators: 1: bucket name, 2: host name of the storage endpoint. */
			$text  = sprintf( __( 'In use: bucket %1$s on %2$s.', 'wp-license' ), $config['bucket'], $config['endpoint_host'] );
			$state = 'ok';
		} elseif ( 'invalid' === $config['state'] ) {
			$text  = \WPLC\Update\S3Storage::error_message( $config['error'] );
			$state = 'warning';
		} else {
			$text  = __( 'Not in use. Update packages are delivered from this site or from the trusted package hosts.', 'wp-license' );
			$state = '';
		}
		echo '<p data-wplc-object-storage="' . esc_attr( $config['state'] ) . '"><strong>' . esc_html__( 'State of the object storage:', 'wp-license' ) . '</strong> ';
		if ( '' !== $state ) {
			echo '<span class="screen-reader-text">' . esc_html( self::state_text( $state ) ) . '</span>';
		}
		echo esc_html( $text ) . '</p>';
		$result = SettingsTools::object_storage_result();
		if ( null !== $result ) {
			echo '<div class="notice ' . ( $result['ok'] ? 'notice-success' : 'notice-warning' ) . ' inline" role="status"><p>' . esc_html( $result['message'] ) . '</p></div>';
		}
		if ( 'off' !== $config['state'] ) {
			echo '<p><button type="submit" class="button" form="wplc-object-storage-check">' . esc_html__( 'Test the connection', 'wp-license' ) . '</button> <span class="description">' . esc_html__( 'Sends one signed request for the bucket. Save changed fields first.', 'wp-license' ) . '</span></p>';
		}
		echo '</div>';
	}

	/**
	 * Render the choice of what happens to the data when the plugin is deleted (S10).
	 *
	 * @return void
	 */
	public static function render_uninstall_data_field(): void {
		self::render_field( array( 'key' => 'uninstall_data' ) );
		if ( ! Capabilities::can( 'manage_package_hosts' ) ) {
			return;
		}
		echo '<p><label for="wplc_uninstall_data_confirm"><input type="checkbox" id="wplc_uninstall_data_confirm" name="' . esc_attr( self::OPTION ) . '[uninstall_data_confirm]" value="1"> ' . esc_html__( 'Yes, delete all license data when the plugin is deleted. I have a backup. (Needed only when you switch to "Delete tables and options".)', 'wp-license' ) . '</label></p>';
		if ( defined( 'WPLC_REMOVE_ALL_DATA' ) && true === WPLC_REMOVE_ALL_DATA ) {
			echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html__( 'In effect: delete. The constant WPLC_REMOVE_ALL_DATA is set in wp-config.php.', 'wp-license' ) . '</p>';
		}
	}

	/**
	 * The sentence that names the filter of a setting, inside its help text (with a leading space).
	 *
	 * @param string $key Setting key.
	 * @return void
	 */
	private static function render_filter_note( string $key ): void {
		$definition = Registry::definition( $key );
		if ( null !== $definition && '' !== $definition['filter'] ) {
			/* translators: %s: name of a PHP filter. */
			echo ' ' . esc_html( sprintf( __( 'Code of the shop can change the value with the filter %s; the filter decides last.', 'wp-license' ), $definition['filter'] ) );
		}
	}

	/**
	 * Say so when code of the shop (a filter) changes the stored value (SET-LIC-32).
	 *
	 * @param string $key Setting key.
	 * @return void
	 */
	private static function render_effective_value( string $key ): void {
		$effective = \WPLC\Settings\Effective::value( $key );
		if ( null === $effective ) {
			return;
		}
		$stored = Registry::display( $key, Registry::get( $key ) );
		$shown  = Registry::display( $key, $effective );
		if ( $stored === $shown ) {
			return;
		}
		/* translators: %s: the value that is in effect. */
		echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html( sprintf( __( 'In effect: %s (set by code of the shop, not by this field).', 'wp-license' ), '' === $shown ? __( 'empty', 'wp-license' ) : $shown ) ) . '</p>';
	}

	/**
	 * The tab the page shows: a group key, or '' for all groups.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, Registry::GROUPS, true ) && Registry::keys_of_group( $tab ) ? $tab : '';
	}

	/**
	 * Tabs: all settings, or one group (SET-LIC-32).
	 *
	 * @param string $current Current tab.
	 * @return void
	 */
	private static function render_tabs( string $current ): void {
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		$titles = Texts::groups();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Groups of settings', 'wp-license' ) . '">';
		echo '<a class="nav-tab' . ( '' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '"' . ( '' === $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'All settings', 'wp-license' ) . '</a>';
		foreach ( Registry::GROUPS as $group ) {
			if ( ! Registry::keys_of_group( $group ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $group === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $group, $base ) ) . '"' . ( $group === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $titles[ $group ] ?? $group ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Print the sections of the form: all of them, or the one of the current tab.
	 *
	 * A tab posts only its own fields; sanitize() keeps every key that is missing.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private static function render_sections( string $tab ): void {
		global $wp_settings_sections, $wp_settings_fields;
		if ( '' === $tab || ! isset( $wp_settings_sections[ self::PAGE ]['wplc_' . $tab ] ) ) {
			do_settings_sections( self::PAGE );
			return;
		}
		$section = $wp_settings_sections[ self::PAGE ]['wplc_' . $tab ];
		echo '<h2>' . esc_html( (string) $section['title'] ) . '</h2>';
		if ( isset( $wp_settings_fields[ self::PAGE ]['wplc_' . $tab ] ) ) {
			echo '<table class="form-table" role="presentation">';
			do_settings_fields( self::PAGE, 'wplc_' . $tab );
			echo '</table>';
		}
	}

	/**
	 * Render the package host textarea.
	 *
	 * @return void
	 */
	public static function render_package_hosts_field(): void {
		$hosts = self::get( 'package_hosts' );
		$hosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();
		if ( ! self::can_manage_package_hosts() ) {
			// Not part of the form: the stored list is kept when the field is missing.
			echo '<textarea id="wplc_package_hosts" rows="4" cols="50" class="large-text code" readonly aria-describedby="wplc_package_hosts_help">' . esc_textarea( implode( "\n", $hosts ) ) . '</textarea>';
			echo '<p class="description" id="wplc_package_hosts_help">' . esc_html__( 'Only an administrator may change the trusted package hosts, because update packages for all customer sites are delivered from them.', 'wp-license' ) . '</p>';
			return;
		}
		$shown = self::shown_value( 'package_hosts', $hosts );
		$shown = is_array( $shown ) ? implode( "\n", array_filter( $shown, 'is_scalar' ) ) : ( is_scalar( $shown ) ? (string) $shown : '' );
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[package_hosts_seen]" value="' . esc_attr( self::hosts_fingerprint( $hosts ) ) . '">';
		echo '<textarea id="wplc_package_hosts" name="' . esc_attr( self::OPTION ) . '[package_hosts]" rows="4" cols="50" class="large-text code"' . self::field_aria( 'package_hosts' ) . '>' . esc_textarea( $shown ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_aria() escapes.
		self::render_field_error( 'package_hosts' );
		echo '<p class="description" id="wplc_package_hosts_help">';
		echo esc_html(
			sprintf(
				/* translators: 1: host name of this site, 2: largest number of hosts. */
				__( 'One host name per line, for example downloads.example.com, at most %2$s. Update packages are only delivered from HTTPS URLs on these hosts. This site (%1$s) is always trusted.', 'wp-license' ),
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				number_format_i18n( (int) ( Registry::definition( 'package_hosts' )['max_items'] ?? 50 ) )
			)
		);
		self::render_filter_note( 'package_hosts' );
		echo '</p>';
	}

	/**
	 * Render the reminder lead days as one number field per reminder (SET-LIC-37).
	 *
	 * @return void
	 */
	public static function render_reminder_days_field(): void {
		$definition = (array) Registry::definition( 'reminder_days' );
		$days       = Registry::list( 'reminder_days' );
		echo '<fieldset id="wplc_reminder_days" aria-describedby="wplc_reminder_days_help"><legend class="screen-reader-text">' . esc_html( Texts::label( 'reminder_days' ) ) . '</legend>';
		for ( $i = 0; $i < (int) ( $definition['max_items'] ?? 6 ); $i++ ) {
			$id = 'wplc_reminder_days_' . $i;
			/* translators: %s: number of the reminder (1, 2, ...). */
			echo '<label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html( sprintf( __( 'Reminder %s: days before the end', 'wp-license' ), number_format_i18n( $i + 1 ) ) ) . '</label>';
			echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::OPTION . '[reminder_days][' . $i . ']' ) . '" value="' . esc_attr( isset( $days[ $i ] ) ? (string) $days[ $i ] : '' ) . '" min="' . esc_attr( (string) (int) ( $definition['min'] ?? 1 ) ) . '" max="' . esc_attr( (string) (int) ( $definition['max'] ?? 365 ) ) . '" step="1" class="small-text"> ';
		}
		echo esc_html__( 'days', 'wp-license' ) . '</fieldset>';
		echo '<p class="description" id="wplc_reminder_days_help">' . esc_html__( 'Days before the end of the paid period on which a reminder is sent, one per field; leave the other fields empty. Default: 30 and 7. All fields empty = the default.', 'wp-license' );
		self::render_filter_note( 'reminder_days' );
		echo '</p>';
		self::render_effective_value( 'reminder_days' );
	}

	/**
	 * Render the choice of the verbose log with the time until it switches itself off (SET-LIC-29).
	 *
	 * @return void
	 */
	public static function render_debug_log_field(): void {
		self::render_field( array( 'key' => 'debug_log' ) );
		$until = \WPLC\Infrastructure\Log::verbose_until();
		if ( $until > 0 ) {
			/* translators: %s: date and time. */
			echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html( sprintf( __( 'On until %s. Then it switches itself off.', 'wp-license' ), Format::date( gmdate( 'Y-m-d H:i:s', $until ), true ) ) ) . '</p>';
		} elseif ( 'off' !== Registry::string( 'debug_log' ) ) {
			echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html__( 'The time has run out; the verbose log is off. Choose "Off", save, and choose a time again to switch it on.', 'wp-license' ) . '</p>';
		}
		if ( defined( 'WPLC_DEBUG' ) && (bool) constant( 'WPLC_DEBUG' ) ) {
			echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html__( 'In effect: on. The constant WPLC_DEBUG is set in wp-config.php.', 'wp-license' ) . '</p>';
		}
	}

	/**
	 * Render the switch for the catalog import from release packages (PROD-03).
	 *
	 * @return void
	 */
	public static function render_catalog_auto_import_field(): void {
		self::render_switch( 'catalog_auto_import', __( 'Import the catalog file from release packages', 'wp-license' ), __( 'When a release is created from a ZIP, the file wplc-catalog.json in the plugin folder of the package is checked and imported into the license catalog. The release is created in any case; a file that is invalid or describes another product is not imported and reported. Plans and licenses are never changed.', 'wp-license' ) );
	}

	/**
	 * A switch with a label of its own, its help, the filter note and the value in effect (P1-M6).
	 *
	 * @param string $key   Setting key (type bool).
	 * @param string $label Text next to the box.
	 * @param string $help  Help text.
	 * @return void
	 */
	private static function render_switch( string $key, string $label, string $help ): void {
		$id    = 'wplc_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::shown_value( $key, self::get( $key ) );
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . self::field_aria( $key ) . '> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_aria() escapes.
		echo esc_html( $label ) . '</label>';
		self::render_field_error( $key );
		echo '<p class="description" id="' . esc_attr( $id ) . '_help">' . esc_html( $help );
		self::render_filter_note( $key );
		echo '</p>';
		self::render_effective_value( $key );
	}

	/**
	 * A number field with its help, the filter note and the value in effect (P1-M6).
	 *
	 * @param string $key  Setting key (type int).
	 * @param int    $min  Smallest value.
	 * @param int    $max  Largest value.
	 * @param string $help Help text.
	 * @param bool   $days Whether "days" follows the field.
	 * @return void
	 */
	private static function render_number( string $key, int $min, int $max, string $help, bool $days = false ): void {
		$id    = 'wplc_' . $key;
		$value = self::shown_value( $key, (int) self::get( $key ) );
		echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" class="small-text" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1"' . self::field_aria( $key ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_aria() escapes.
		if ( $days ) {
			echo ' ' . esc_html__( 'days', 'wp-license' );
		}
		self::render_field_error( $key );
		echo '<p class="description" id="' . esc_attr( $id ) . '_help">' . esc_html( $help );
		self::render_filter_note( $key );
		echo '</p>';
		self::render_effective_value( $key );
	}

	/**
	 * Render the switch for combined automatic renewals (B-191).
	 *
	 * @return void
	 */
	public static function render_combined_renewals_field(): void {
		self::render_switch( 'combined_renewals', __( 'Charge the licenses of one customer that renew automatically on the same day with one order and one payment', 'wp-license' ), __( 'Only licenses with the same saved card, currency and billing interval are combined. Each license keeps its own renewal; a declined payment is retried per license. Manual renewals are never combined. Off by default.', 'wp-license' ) );
	}

	/**
	 * Render the notice period of a renewal price change (INH-05).
	 *
	 * @return void
	 */
	public static function render_price_change_notice_days_field(): void {
		self::render_number( 'price_change_notice_days', \WPLC\Billing\PriceChange::MIN_NOTICE_DAYS, \WPLC\Billing\PriceChange::MAX_NOTICE_DAYS, __( 'Days between the email that announces a higher renewal price for an existing license and the first renewal at that price. Default: 60, at least 30. Have the period and the wording of the email checked by a lawyer.', 'wp-license' ) );
	}

	/**
	 * Render the lead time of the automatic renewal notice (B-41).
	 *
	 * @return void
	 */
	public static function render_renewal_notice_days_field(): void {
		self::render_number( 'renewal_notice_days', 1, 60, __( 'Days before an automatic charge on which the customer is told the date and the amount. Default: 7. A license is only charged after this email was accepted for sending; otherwise the charge is postponed and reported under Diagnostics.', 'wp-license' ) );
	}

	/**
	 * Render the largest quantity of a license product in one order line.
	 *
	 * @return void
	 */
	public static function render_max_quantity_per_line_field(): void {
		self::render_number( 'max_quantity_per_line', 0, \WPLC\Catalog\QuantityLimit::MAX_SETTING, Texts::help( 'max_quantity_per_line' ) );
	}

	/**
	 * Render the number of days after which a suspended license counts as ended.
	 *
	 * @return void
	 */
	public static function render_suspended_ends_after_days_field(): void {
		self::render_number( 'suspended_ends_after_days', 0, \WPLC\License\EndedLicenses::MAX_DAYS, __( 'Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel.', 'wp-license' ), true );
	}

	/**
	 * Render the sender note textarea.
	 *
	 * @return void
	 */
	public static function render_sender_note_field(): void {
		$note = self::shown_value( 'sender_note', self::get( 'sender_note' ) );
		echo '<textarea id="wplc_sender_note" name="' . esc_attr( self::OPTION ) . '[sender_note]" rows="3" cols="50" class="large-text" maxlength="500"' . self::field_aria( 'sender_note' ) . '>' . esc_textarea( is_scalar( $note ) ? (string) $note : '' ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_aria() escapes.
		self::render_field_error( 'sender_note' );
		echo '<p class="description" id="wplc_sender_note_help">' . esc_html__( 'Optional short text that license emails may show below their content, for example a support contact.', 'wp-license' ) . '</p>';
	}
}
