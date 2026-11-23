<?php
/**
 * Tools below the settings form: country profiles, export, import, reset.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Settings\Changes;
use WPLC\Settings\Presets;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every tool works in two steps: it prepares a preview (before and after, per setting)
 * and changes nothing; the shop then ticks the rows, confirms and applies. A country
 * profile is a suggestion whose rows are not ticked in advance.
 */
final class SettingsTools {

	public const NONCE_ACTION = 'wplc_settings_tools';

	public const ACTION = 'wplc_settings_tool';

	/**
	 * Seconds a prepared preview stays available.
	 */
	private const PENDING_TTL = 900;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_settings_page_tools', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Transient of the current user's prepared change.
	 *
	 * @return string
	 */
	private static function pending_key(): string {
		return 'wplc_settings_pending_' . (int) get_current_user_id();
	}

	/**
	 * Transient of the message for the next page view.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'wplc_settings_tool_result_' . (int) get_current_user_id();
	}

	/**
	 * The prepared change of the current user.
	 *
	 * @return array{source:string,name:string,values:array<string,mixed>,ignored:string[]}|null
	 */
	public static function pending(): ?array {
		$pending = get_transient( self::pending_key() );
		if ( ! is_array( $pending ) || ! isset( $pending['source'], $pending['values'] ) || ! is_array( $pending['values'] ) ) {
			return null;
		}

		return array(
			'source'  => (string) $pending['source'],
			'name'    => (string) ( $pending['name'] ?? '' ),
			'values'  => $pending['values'],
			'ignored' => isset( $pending['ignored'] ) && is_array( $pending['ignored'] ) ? array_map( 'strval', $pending['ignored'] ) : array(),
		);
	}

	/**
	 * Handle a tool request (admin-post).
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change license settings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		switch ( $tool ) {
			case 'export':
				self::send_export();
				return;
			case 'preset':
				$code = isset( $_POST['profile'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['profile'] ) ) ) : '';
				self::finish( self::prepare_preset( $code ) );
				return;
			case 'reset':
				$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
				self::finish( self::prepare_reset( $group ) );
				return;
			case 'import':
				$json = '';
				if ( isset( $_FILES['file']['tmp_name'], $_FILES['file']['error'] ) && UPLOAD_ERR_OK === (int) $_FILES['file']['error'] && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path of the upload, checked with is_uploaded_file().
					$json = (string) file_get_contents( $_FILES['file']['tmp_name'], false, null, 0, 262145 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload; parsed as JSON and checked value by value.
				} elseif ( isset( $_POST['json'] ) ) {
					$json = (string) wp_unslash( $_POST['json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed as JSON and checked value by value.
				}
				self::finish( self::prepare_import( $json ) );
				return;
			case 'discard':
				delete_transient( self::pending_key() );
				self::finish( '' );
				return;
			case 'apply':
				$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['keys'] ) ) : array();
				self::finish( self::apply_pending( $keys, ! empty( $_POST['confirm'] ) ) );
				return;
		}
		self::finish( '' );
	}

	/**
	 * Prepare the suggestion of a country profile.
	 *
	 * @param string $code Profile key.
	 * @return string Message for the screen ('' for none).
	 */
	public static function prepare_preset( string $code ): string {
		$profiles = Presets::all();
		if ( ! isset( $profiles[ $code ] ) ) {
			return __( 'Choose a country profile.', 'wp-license' );
		}
		self::store_pending( 'preset', $code, $profiles[ $code ]['values'] );

		return '';
	}

	/**
	 * Prepare a reset to the defaults.
	 *
	 * The trusted package hosts are never part of a reset: remove them in their own field.
	 *
	 * @param string $group Group key, or 'all'.
	 * @return string Message for the screen ('' for none).
	 */
	public static function prepare_reset( string $group ): string {
		if ( 'all' !== $group && ! in_array( $group, Registry::GROUPS, true ) ) {
			return __( 'Choose what to reset.', 'wp-license' );
		}
		$values = Changes::defaults_of( 'all' === $group ? '' : $group );
		unset( $values['package_hosts'] );
		self::store_pending( 'reset', $group, $values );

		return '';
	}

	/**
	 * Prepare an import.
	 *
	 * @param string $json Content of an export file.
	 * @return string Message for the screen ('' for none).
	 */
	public static function prepare_import( string $json ): string {
		if ( '' === trim( $json ) ) {
			return __( 'Choose an export file or paste its content.', 'wp-license' );
		}
		$parsed = Changes::parse_import( $json );
		if ( ! $parsed['ok'] ) {
			$messages = array(
				'too_large'      => __( 'The file is too large to be a settings export.', 'wp-license' ),
				'other_plugin'   => __( 'This file is not a settings export of this plugin.', 'wp-license' ),
				'unknown_format' => __( 'This export has a format this version does not know. Update the plugin, or export again from the other shop.', 'wp-license' ),
			);

			return $messages[ $parsed['error'] ] ?? __( 'This is not a settings export: the file could not be read.', 'wp-license' );
		}
		self::store_pending( 'import', '', $parsed['values'], $parsed['ignored'] );

		return '';
	}

	/**
	 * Apply the chosen rows of the prepared change.
	 *
	 * @param string[] $keys      Keys the user ticked.
	 * @param bool     $confirmed Whether the confirmation box was ticked.
	 * @return string Message for the screen.
	 */
	public static function apply_pending( array $keys, bool $confirmed ): string {
		$pending = self::pending();
		if ( null === $pending ) {
			return __( 'The preview has expired. Nothing was changed; prepare it again.', 'wp-license' );
		}
		if ( ! $confirmed ) {
			return __( 'Nothing was changed: tick the confirmation below the preview first.', 'wp-license' );
		}
		$values = array_intersect_key( $pending['values'], array_flip( $keys ) );
		$values = array_filter(
			$values,
			static function ( $value ) {
				return null !== $value;
			}
		);
		if ( ! $values ) {
			return __( 'Nothing was changed: no row was ticked.', 'wp-license' );
		}
		$actions = array(
			'preset' => array( 'settings_preset_applied', 'Country profile ' . $pending['name'] . ' applied as chosen.' ),
			'reset'  => array( 'settings_reset', 'Reset to defaults (' . $pending['name'] . ').' ),
			'import' => array( 'settings_imported', 'Settings imported from a file.' ),
		);
		$action  = $actions[ $pending['source'] ] ?? array( 'settings_changed', 'Settings changed.' );
		$result  = Changes::apply( $values, $action[0], $action[1] );
		delete_transient( self::pending_key() );

		$message = sprintf(
			/* translators: %s: number of settings. */
			_n( '%s setting was changed.', '%s settings were changed.', count( $result['applied'] ), 'wp-license' ),
			number_format_i18n( count( $result['applied'] ) )
		);
		if ( $result['skipped'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: list of setting names. */
				__( 'Not changed: %s.', 'wp-license' ),
				implode( ', ', array_map( array( Texts::class, 'label' ), array_keys( $result['skipped'] ) ) )
			);
		}

		return $message;
	}

	/**
	 * Remember a prepared change for the current user.
	 *
	 * @param string              $source  preset, reset or import.
	 * @param string              $name    Profile key or group.
	 * @param array<string,mixed> $values  Key => value.
	 * @param string[]            $ignored Keys of an import that were left out.
	 * @return void
	 */
	private static function store_pending( string $source, string $name, array $values, array $ignored = array() ): void {
		set_transient(
			self::pending_key(),
			array(
				'source'  => $source,
				'name'    => $name,
				'values'  => $values,
				'ignored' => $ignored,
			),
			self::PENDING_TTL
		);
	}

	/**
	 * Send the export as a download. Contains no secret: secrets are not settings.
	 *
	 * @return void
	 */
	private static function send_export(): void {
		$json = (string) wp_json_encode( Changes::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		\WPLC\License\AuditLog::record( 'settings_exported', 'settings', 0, 'Settings exported to a file.', null, null, (int) get_current_user_id() );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wplc-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Store the message and return to the settings page.
	 *
	 * @param string $message Message, '' for none.
	 * @return void
	 */
	private static function finish( string $message ): void {
		if ( '' !== $message ) {
			set_transient( self::result_key(), $message, 10 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . Settings::PAGE . '#wplc-settings-tools' ) );
		exit;
	}

	/**
	 * Render the tools, or the preview of a prepared change.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		echo '<div id="wplc-settings-tools" class="wplc-settings-tools"><h2>' . esc_html__( 'Country profiles, export, import and reset', 'wp-license' ) . '</h2>';
		$message = get_transient( self::result_key() );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( self::result_key() );
			echo '<div class="notice notice-info inline" role="status"><p>' . esc_html( $message ) . '</p></div>';
		}

		$pending = self::pending();
		if ( null !== $pending ) {
			self::render_preview( $pending );
			echo '</div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Each of these tools first shows what would change, value by value. Nothing is changed until you tick the rows, confirm and apply.', 'wp-license' ) . '</p>';

		// Country profile.
		echo '<h3>' . esc_html__( 'Country profile', 'wp-license' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'A country profile suggests values for the settings with legal weight (retention, consent, notice periods). It is a suggestion only. No value of a profile has been through a legal review yet: every value is marked "unchecked", and where nothing is fixed for a country the profile says "please decide". Have the values checked for your country before you rely on them.', 'wp-license' ) . '</p>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="preset"><label for="wplc-profile" class="screen-reader-text">' . esc_html__( 'Country profile', 'wp-license' ) . '</label><select id="wplc-profile" name="profile">';
		foreach ( Presets::labels() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Show suggestion', 'wp-license' ), 'secondary', 'submit', false );
		echo '</form>';

		// Export and import.
		echo '<h3>' . esc_html__( 'Export and import', 'wp-license' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'The export is a JSON file with all settings of this page. It contains no secret: keys and passwords are not settings and stay in wp-config.php. An import shows every difference before anything is changed.', 'wp-license' ) . '</p>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="export">';
		submit_button( __( 'Export settings', 'wp-license' ), 'secondary', 'submit', false );
		echo '</form>';
		self::open_form( true );
		echo '<input type="hidden" name="tool" value="import"><p><label for="wplc-import-file">' . esc_html__( 'Export file', 'wp-license' ) . '</label><br><input type="file" id="wplc-import-file" name="file" accept=".json,application/json"></p>';
		echo '<p><label for="wplc-import-json">' . esc_html__( 'Or paste the content of the file', 'wp-license' ) . '</label><br><textarea id="wplc-import-json" name="json" rows="3" cols="50" class="large-text code"></textarea></p>';
		submit_button( __( 'Preview import', 'wp-license' ), 'secondary', 'submit', false );
		echo '</form>';

		// Reset.
		echo '<h3>' . esc_html__( 'Reset to defaults', 'wp-license' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Sets a group of settings, or all of them, back to the built-in defaults. The trusted package hosts are never reset here; remove them in their own field.', 'wp-license' ) . '</p>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="reset"><label for="wplc-reset-group" class="screen-reader-text">' . esc_html__( 'Group to reset', 'wp-license' ) . '</label><select id="wplc-reset-group" name="group">';
		foreach ( Texts::groups() as $group => $title ) {
			if ( Registry::keys_of_group( $group ) ) {
				echo '<option value="' . esc_attr( $group ) . '">' . esc_html( $title ) . '</option>';
			}
		}
		echo '<option value="all">' . esc_html__( 'All settings', 'wp-license' ) . '</option></select> ';
		submit_button( __( 'Preview reset', 'wp-license' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Open a tool form with action and nonce.
	 *
	 * @param bool $upload Whether the form uploads a file.
	 * @return void
	 */
	private static function open_form( bool $upload = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
	}

	/**
	 * Render the preview of a prepared change with its apply form.
	 *
	 * @param array{source:string,name:string,values:array<string,mixed>,ignored:string[]} $pending Prepared change.
	 * @return void
	 */
	private static function render_preview( array $pending ): void {
		$rows     = Changes::preview( $pending['values'] );
		$preset   = 'preset' === $pending['source'];
		$profiles = $preset ? Presets::all() : array();
		$reviewed = $preset && isset( $profiles[ $pending['name'] ] ) ? (string) $profiles[ $pending['name'] ]['reviewed'] : '';
		$titles   = array(
			/* translators: %s: name of a country profile. */
			'preset' => sprintf( __( 'Suggestion of the country profile "%s"', 'wp-license' ), Presets::labels()[ $pending['name'] ] ?? $pending['name'] ),
			'reset'  => __( 'Preview: reset to defaults', 'wp-license' ),
			'import' => __( 'Preview: import', 'wp-license' ),
		);
		echo '<h3>' . esc_html( $titles[ $pending['source'] ] ?? __( 'Preview', 'wp-license' ) ) . '</h3>';
		if ( $preset ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( '' === $reviewed ? __( 'Unchecked: no value of this profile has been through a legal review. It is a suggestion, not legal advice. Nothing is ticked in advance.', 'wp-license' ) : sprintf( /* translators: %s: date. */ __( 'Legal review of this profile: %s. It remains a suggestion; nothing is ticked in advance.', 'wp-license' ), $reviewed ) ) . '</p></div>';
		}
		if ( $pending['ignored'] ) {
			/* translators: %s: list of keys. */
			echo '<p>' . esc_html( sprintf( __( 'Left out, because this version does not know them: %s.', 'wp-license' ), implode( ', ', $pending['ignored'] ) ) ) . '</p>';
		}

		$states = array(
			'change'    => __( 'would change', 'wp-license' ),
			'same'      => __( 'no change', 'wp-license' ),
			'invalid'   => __( 'not valid, is left out', 'wp-license' ),
			'forbidden' => __( 'only an administrator may change this', 'wp-license' ),
			'unknown'   => __( 'unknown setting, is left out', 'wp-license' ),
			'undecided' => __( 'please decide: no value is suggested for this country', 'wp-license' ),
		);
		self::open_form();
		echo '<input type="hidden" name="tool" value="apply"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Apply', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Setting', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Now', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Afterwards', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Note', 'wp-license' ) . '</th></tr></thead><tbody>';
		$changes = 0;
		foreach ( $rows as $row ) {
			$id   = 'wplc-apply-' . $row['key'];
			$note = $states[ $row['state'] ] ?? $row['state'];
			if ( $preset && $row['legal'] && '' === $reviewed ) {
				$note .= ' · ' . __( 'unchecked', 'wp-license' );
			}
			echo '<tr data-wplc-state="' . esc_attr( $row['state'] ) . '"><td>';
			if ( 'change' === $row['state'] ) {
				++$changes;
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="keys[]" value="' . esc_attr( $row['key'] ) . '"' . checked( ! $preset, true, false ) . '>';
			}
			echo '</td><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $row['label'] ) . '</label></th>';
			echo '<td>' . esc_html( '' === $row['before'] ? '–' : $row['before'] ) . '</td><td>' . esc_html( 'undecided' === $row['state'] ? '?' : ( '' === $row['after'] ? '–' : $row['after'] ) ) . '</td><td>' . esc_html( $note ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $changes > 0 ) {
			echo '<p><label for="wplc-apply-confirm"><input type="checkbox" id="wplc-apply-confirm" name="confirm" value="1" required> ' . esc_html__( 'I have checked the values and want to apply the ticked rows.', 'wp-license' ) . '</label></p>';
			submit_button( __( 'Apply ticked rows', 'wp-license' ), 'primary', 'submit', false );
		} else {
			echo '<p>' . esc_html__( 'There is nothing to apply.', 'wp-license' ) . '</p>';
		}
		echo '</form>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="discard">';
		submit_button( __( 'Discard the preview', 'wp-license' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
