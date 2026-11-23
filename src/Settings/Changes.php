<?php
/**
 * Preview and apply changes of several settings at once.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

use WPLC\Admin\Capabilities;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one way in which import, reset, country profiles and WP-CLI change settings:
 * first a preview (before and after, per setting), then apply() for the chosen keys.
 * Every value passes the check of the register; nothing is overwritten silently.
 */
final class Changes {

	/**
	 * Format number of export files.
	 */
	public const FORMAT = 1;

	/**
	 * Marker of export files.
	 */
	public const PLUGIN = 'wp-license';

	/**
	 * Compare candidate values with the stored settings.
	 *
	 * Row states: `change` (valid and different), `same` (valid, nothing to do), `invalid`
	 * (rejected by the register, `error` says why), `forbidden` (the current user may not
	 * change this setting), `locked` (the setting may no longer change, e.g. the license key
	 * prefix once a license was issued), `unknown` (no such setting), `undecided` (a suggestion
	 * without a value: the shop has to decide).
	 *
	 * @param array<string,mixed> $candidate  Key => value (null = no value suggested).
	 * @param bool                $check_caps Whether the capability of the current user counts (false for WP-CLI).
	 * @return array<int,array{key:string,label:string,before:string,after:string,value:mixed,state:string,error:string,legal:bool}>
	 */
	public static function preview( array $candidate, bool $check_caps = true ): array {
		$current = Registry::all();
		$rows    = array();
		foreach ( $candidate as $key => $value ) {
			$key        = (string) $key;
			$definition = Registry::definition( $key );
			$row        = array(
				'key'    => $key,
				'label'  => null === $definition ? $key : Texts::label( $key ),
				'before' => null === $definition ? '' : Registry::display( $key, $current[ $key ] ),
				'after'  => '',
				'value'  => null,
				'state'  => 'unknown',
				'error'  => '',
				'legal'  => null !== $definition && $definition['legal'],
			);
			if ( null === $definition ) {
				$row['error'] = 'Unknown setting.';
				$rows[]       = $row;
				continue;
			}
			if ( null === $value ) {
				$row['state'] = 'undecided';
				$rows[]       = $row;
				continue;
			}
			$result = Registry::validate( $key, $value );
			if ( ! $result['ok'] ) {
				$row['state'] = 'invalid';
				$row['error'] = $result['error'];
				$row['after'] = Registry::display( $key, is_scalar( $value ) || is_array( $value ) ? $value : '' );
				$rows[]       = $row;
				continue;
			}
			$row['value'] = $result['value'];
			$row['after'] = Registry::display( $key, $result['value'] );
			// Compared in stored form: two long texts that only differ after the shortened preview are still a change.
			$kept         = Registry::validate( $key, $current[ $key ] );
			$row['state'] = ( $kept['ok'] ? $kept['value'] : $current[ $key ] ) === $result['value'] ? 'same' : 'change';
			if ( 'change' === $row['state'] && $check_caps && ! Capabilities::can( (string) $definition['capability'] ) ) {
				$row['state'] = 'forbidden';
			}
			if ( 'change' === $row['state'] && Registry::is_locked( $key ) ) {
				// E-04: e.g. the license key prefix once a license was issued.
				$row['state'] = 'locked';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Apply values. Only rows whose state is `change` are written.
	 *
	 * @param array<string,mixed> $values     Key => value.
	 * @param string              $action     Audit action: settings_imported, settings_reset, settings_preset_applied or settings_changed.
	 * @param string              $reason     What was done, for the audit log (English).
	 * @param bool                $check_caps Whether the capability of the current user counts (false for WP-CLI).
	 * @return array{applied:string[],skipped:array<string,string>}
	 */
	public static function apply( array $values, string $action, string $reason, bool $check_caps = true ): array {
		$applied = array();
		$skipped = array();
		$new     = Registry::all();
		$before  = array();
		$after   = array();
		foreach ( self::preview( $values, $check_caps ) as $row ) {
			if ( 'change' === $row['state'] ) {
				$new[ $row['key'] ]    = $row['value'];
				$applied[]             = $row['key'];
				$before[ $row['key'] ] = $row['before'];
				$after[ $row['key'] ]  = $row['after'];
			} elseif ( 'same' !== $row['state'] ) {
				$skipped[ $row['key'] ] = $row['state'];
			}
		}
		if ( $applied ) {
			update_option( Registry::OPTION, $new );
			// The values that really arrived (a sanitize callback of the settings page may have refused one).
			$stored = Registry::all();
			foreach ( $applied as $index => $key ) {
				if ( $stored[ $key ] !== $new[ $key ] ) {
					unset( $applied[ $index ], $before[ $key ], $after[ $key ] );
					$skipped[ $key ] = 'refused';
				}
			}
			$applied = array_values( $applied );
		}
		if ( $applied ) {
			AuditLog::record( $action, 'settings', 0, $reason . ' Changed: ' . implode( ', ', $applied ), $before, $after, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		}

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}

	/**
	 * The export: every exportable setting in stored form. Secrets are never part of it.
	 *
	 * @return array{format:int,plugin:string,plugin_version:string,exported_at:string,settings:array<string,mixed>}
	 */
	public static function export(): array {
		$settings = array();
		$all      = Registry::all();
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( $definition['exportable'] && ! $definition['secret'] ) {
				$settings[ $key ] = $all[ $key ];
			}
		}

		return array(
			'format'         => self::FORMAT,
			'plugin'         => self::PLUGIN,
			'plugin_version' => defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : '',
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'settings'       => $settings,
		);
	}

	/**
	 * Read an export file. Keys that are unknown, secret or not exportable are listed and left out.
	 *
	 * @param string $json File content.
	 * @return array{ok:bool,error:string,values:array<string,mixed>,ignored:string[]}
	 */
	public static function parse_import( string $json ): array {
		$fail = static function ( string $error ): array {
			return array(
				'ok'      => false,
				'error'   => $error,
				'values'  => array(),
				'ignored' => array(),
			);
		};
		if ( strlen( $json ) > 262144 ) {
			return $fail( 'too_large' );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $fail( 'not_an_export' );
		}
		if ( self::PLUGIN !== ( $data['plugin'] ?? '' ) ) {
			return $fail( 'other_plugin' );
		}
		if ( self::FORMAT !== (int) ( $data['format'] ?? 0 ) ) {
			return $fail( 'unknown_format' );
		}
		$values  = array();
		$ignored = array();
		foreach ( $data['settings'] as $key => $value ) {
			$definition = Registry::definition( (string) $key );
			if ( null === $definition || $definition['secret'] || ! $definition['exportable'] || null === $value ) {
				$ignored[] = (string) $key;
				continue;
			}
			$values[ (string) $key ] = $value;
		}

		return array(
			'ok'      => true,
			'error'   => '',
			'values'  => $values,
			'ignored' => $ignored,
		);
	}

	/**
	 * The defaults of one group, or of all settings.
	 *
	 * @param string $group Group key, or '' for all.
	 * @return array<string,mixed>
	 */
	public static function defaults_of( string $group ): array {
		$values = array();
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( '' === $group || $group === $definition['group'] ) {
				$values[ $key ] = $definition['default'];
			}
		}

		return $values;
	}
}
