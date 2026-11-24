<?php
/**
 * Settings on the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Settings\Changes;
use WPLC\Settings\Effective;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc settings`: list, read, change, export, import and reset the settings of the
 * license server. Uses the same register and the same checks as the settings page; every
 * change is written to the audit log with the source `cli`. Secrets are not settings.
 */
final class SettingsCommand {

	/**
	 * List all settings with their value, default and filter.
	 *
	 * The column "effective" shows the value after the filters of the shop, where it can be told.
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : Only the settings of one group (updates, renewals, emails, rules, sites, sharing, account, legal, texts, operations).
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings list
	 *     wp wplc settings list --group=legal --format=json
	 *
	 * @subcommand list
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = self::rows( (string) ( $assoc_args['group'] ?? '' ) );
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'key', 'group', 'value', 'default', 'effective', 'filter' ) );
	}

	/**
	 * Print one setting in its stored form.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Key of the setting (see "wp wplc settings list").
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings get renewal_grace_days
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function get( array $args, array $assoc_args ): void {
		$key = (string) ( $args[0] ?? '' );
		if ( null === Registry::definition( $key ) ) {
			\WP_CLI::error( 'Unknown setting. Run "wp wplc settings list".' );
		}
		$value = Registry::get( $key );
		\WP_CLI::line( is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value );
	}

	/**
	 * Change one setting. The value passes the same check as on the settings page.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Key of the setting.
	 *
	 * <value>
	 * : New value. Lists: separate the entries with commas. Switches: 1 or 0.
	 *
	 * [--yes]
	 * : Needed to set uninstall_data to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings set renewal_grace_days 21
	 *     wp wplc settings set renewal_retry_days 2,5
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function set( array $args, array $assoc_args ): void {
		$result = self::change( array( (string) ( $args[0] ?? '' ) => (string) ( $args[1] ?? '' ) ), 'settings_changed', 'Setting changed on the command line.', ! empty( $assoc_args['yes'] ) );
		foreach ( $result['lines'] as $line ) {
			\WP_CLI::line( $line );
		}
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Print all settings as JSON (the format of the export of the settings page). Contains no secret.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings export > wplc-settings.json
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		\WP_CLI::line( (string) wp_json_encode( Changes::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Import settings from an export file. Without --apply only the differences are shown.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the JSON file.
	 *
	 * [--apply]
	 * : Change the settings. Without it nothing is changed.
	 *
	 * [--yes]
	 * : Needed when the file sets uninstall_data to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings import wplc-settings.json
	 *     wp wplc settings import wplc-settings.json --apply
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'The file cannot be read.' );
		}
		$parsed = Changes::parse_import( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file named by the operator.
		if ( ! $parsed['ok'] ) {
			\WP_CLI::error( 'Not a settings export of this plugin (' . $parsed['error'] . ').' );
		}
		if ( $parsed['ignored'] ) {
			\WP_CLI::warning( 'Left out (unknown to this version): ' . implode( ', ', $parsed['ignored'] ) );
		}
		$this->preview_or_apply( $parsed['values'], 'settings_imported', 'Settings imported from a file on the command line.', $assoc_args );
	}

	/**
	 * Reset a group, or all settings, to the defaults. Without --apply only the differences are shown.
	 *
	 * The trusted package hosts are never reset; change them with "wp wplc settings set package_hosts".
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : Group to reset (updates, renewals, emails, rules, sites, sharing, account, legal, texts, operations). Without it all settings are reset.
	 *
	 * [--apply]
	 * : Change the settings. Without it nothing is changed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings reset --group=renewals
	 *     wp wplc settings reset --group=renewals --apply
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function reset( array $args, array $assoc_args ): void {
		$group = (string) ( $assoc_args['group'] ?? '' );
		if ( '' !== $group && ! in_array( $group, Registry::GROUPS, true ) ) {
			\WP_CLI::error( 'Unknown group. Groups: ' . implode( ', ', Registry::GROUPS ) . '.' );
		}
		$values = Changes::defaults_of( $group );
		unset( $values['package_hosts'] );
		$this->preview_or_apply( $values, 'settings_reset', 'Reset to defaults on the command line (' . ( '' === $group ? 'all' : $group ) . ').', $assoc_args );
	}

	/**
	 * Send a test message to the recipients of the health alerts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc settings test-alert
	 *
	 * @subcommand test-alert
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function test_alert( array $args, array $assoc_args ): void {
		$result = \WPLC\Admin\HealthAlertMail::send_test();
		if ( 'no_recipient' === $result ) {
			\WP_CLI::error( 'No recipient is set. Set one with "wp wplc settings set health_alert_recipients you@example.com".' );
		}
		if ( 'sent' !== $result ) {
			\WP_CLI::error( 'WordPress could not hand the email over for sending.' );
		}
		\WP_CLI::success( 'Test message handed over for sending.' );
	}

	/**
	 * Show the differences, and apply them with --apply.
	 *
	 * @param array<string,mixed> $values     Key => value.
	 * @param string              $action     Audit action.
	 * @param string              $reason     Audit reason.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	private function preview_or_apply( array $values, string $action, string $reason, array $assoc_args ): void {
		$rows = array();
		foreach ( Changes::preview( $values, false ) as $row ) {
			if ( 'same' !== $row['state'] ) {
				$rows[] = array(
					'key'    => $row['key'],
					'now'    => $row['before'],
					'after'  => $row['after'],
					'state'  => $row['state'],
					'reason' => $row['error'],
				);
			}
		}
		if ( ! $rows ) {
			\WP_CLI::success( 'Nothing to change.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'now', 'after', 'state', 'reason' ) );
		if ( empty( $assoc_args['apply'] ) ) {
			\WP_CLI::line( 'Nothing was changed. Run again with --apply to change the rows marked "change".' );
			return;
		}
		$result = self::change( $values, $action, $reason, ! empty( $assoc_args['yes'] ) );
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Rows of `list`. Kept free of WP_CLI calls so it can be tested.
	 *
	 * @param string $group Group key, or '' for all.
	 * @return array<int,array<string,string>>
	 */
	public static function rows( string $group = '' ): array {
		$rows = array();
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( '' !== $group && $group !== $definition['group'] ) {
				continue;
			}
			$effective = Effective::value( (string) $key );
			$rows[]    = array(
				'key'       => (string) $key,
				'group'     => (string) $definition['group'],
				'label'     => Texts::label( (string) $key ),
				'value'     => Registry::display( (string) $key, Registry::get( (string) $key ) ),
				'default'   => Registry::display( (string) $key, $definition['default'] ),
				'effective' => null === $effective ? '' : Registry::display( (string) $key, $effective ),
				'filter'    => (string) $definition['filter'],
			);
		}

		return $rows;
	}

	/**
	 * Change settings. Kept free of WP_CLI calls so it can be tested.
	 *
	 * All values must be valid, or nothing is changed. A value that may not change (fixed, set by
	 * a constant, in conflict with another setting, or refused when stored) is reported with its
	 * reason; when no value changed because of that, the result is a failure (P1-M5).
	 *
	 * @param array<string,mixed> $values    Key => value.
	 * @param string              $action    Audit action.
	 * @param string              $reason    Audit reason.
	 * @param bool                $confirmed Whether --yes was given.
	 * @return array{ok:bool,message:string,lines:string[],applied:string[],warnings:string[]}
	 */
	public static function change( array $values, string $action, string $reason, bool $confirmed = false ): array {
		$lines   = array();
		$refused = array();
		$fail    = static function ( string $message ): array {
			return array(
				'ok'       => false,
				'message'  => $message . ' Nothing was changed.',
				'lines'    => array(),
				'applied'  => array(),
				'warnings' => array(),
			);
		};
		foreach ( Changes::preview( $values, false ) as $row ) {
			if ( in_array( $row['state'], array( 'invalid', 'unknown' ), true ) ) {
				return $fail( $row['key'] . ': ' . $row['error'] );
			}
			if ( 'change' === $row['state'] && 'uninstall_data' === $row['key'] && 'delete' === $row['value'] && ! $confirmed ) {
				return $fail( 'uninstall_data: deleting all license data with the plugin needs --yes.' );
			}
			if ( 'change' === $row['state'] ) {
				$lines[] = $row['key'] . ': ' . $row['before'] . ' -> ' . $row['after'];
			} elseif ( ! in_array( $row['state'], array( 'same', 'undecided' ), true ) ) {
				$refused[ $row['key'] ] = $row['error'];
			}
		}
		$result = Changes::apply( $values, $action, $reason, false );
		foreach ( $result['skipped'] as $key => $state ) {
			if ( ! isset( $refused[ $key ] ) ) {
				$refused[ $key ] = 'refused' === $state ? 'The value was refused when it was stored.' : 'Not changed (' . $state . ').';
			}
		}
		$lines = array_values(
			array_filter(
				$lines,
				static function ( string $line ) use ( $refused ): bool {
					return ! isset( $refused[ strstr( $line, ':', true ) ] );
				}
			)
		);
		$reasons = array();
		foreach ( $refused as $key => $why ) {
			$reasons[] = $key . ': ' . $why;
		}
		if ( ! $result['applied'] && $reasons ) {
			return $fail( implode( ' ', $reasons ) );
		}
		foreach ( $reasons as $line ) {
			$lines[] = 'Not changed: ' . $line;
		}

		return array(
			'ok'       => true,
			'message'  => $result['applied'] ? count( $result['applied'] ) . ' setting(s) changed.' : 'Nothing to change.',
			'lines'    => $lines,
			'applied'  => $result['applied'],
			'warnings' => array_values( $result['warnings'] ),
		);
	}
}
