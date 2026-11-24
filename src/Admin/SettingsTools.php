<?php
/**
 * Tools below the settings form: country profiles, export, import, test alert, reset.
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
	 * Test emails one user may send per hour (SET-LIC-43); the same limit counts for test health alerts (P4-L4).
	 */
	public const TEST_EMAILS_PER_HOUR = 10;

	/**
	 * Settings the guided step "Decide the rules of your shop" asks for besides the country profile (SET-LIC-45).
	 */
	public const GUIDED_SETTINGS = array( 'staging_sites_per_live', 'renewal_grace_days' );

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
	 * Transient of the current user's last connection test of the object storage (SET-LIC-30).
	 *
	 * @return string
	 */
	private static function object_storage_key(): string {
		return 'wplc_s3_check_' . (int) get_current_user_id();
	}

	/**
	 * The result of the current user's last connection test, shown once.
	 *
	 * @return array{ok:bool,message:string}|null
	 */
	public static function object_storage_result(): ?array {
		$result = get_transient( self::object_storage_key() );
		if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
			return null;
		}
		delete_transient( self::object_storage_key() );

		return array(
			'ok'      => ! empty( $result['ok'] ),
			'message' => (string) $result['message'],
		);
	}

	/**
	 * The prepared change of the current user.
	 *
	 * `before` holds the stored value of each setting when the preview was prepared (P3-A7).
	 *
	 * @return array{source:string,name:string,values:array<string,mixed>,ignored:string[],before:array<string,mixed>}|null
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
			'before'  => isset( $pending['before'] ) && is_array( $pending['before'] ) ? $pending['before'] : array(),
		);
	}

	/**
	 * A setting in the form it is compared in: the stored value, brought into the valid form.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function comparable( string $key ) {
		$value = Registry::get( $key );
		$kept  = Registry::validate( $key, $value );

		return $kept['ok'] ? $kept['value'] : $value;
	}

	/**
	 * Settings of a prepared change whose stored value changed after the preview was prepared (P3-A7).
	 *
	 * @param array{values:array<string,mixed>,before:array<string,mixed>} $pending Prepared change.
	 * @return string[] Keys.
	 */
	public static function changed_since_preview( array $pending ): array {
		$changed = array();
		foreach ( array_keys( $pending['values'] ) as $key ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $pending['before'] ) && null !== Registry::definition( $key ) && self::comparable( $key ) !== $pending['before'][ $key ] ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Take one of the actions a user may start per hour (test emails, test health alerts).
	 *
	 * The hour starts with the first action; later ones do not extend it.
	 *
	 * @param string $kind `emails` or `alerts`.
	 * @return bool False when the limit of this hour is reached.
	 */
	private static function take_hourly_slot( string $kind ): bool {
		$key    = 'wplc_test_' . $kind . '_' . (int) get_current_user_id();
		$window = get_transient( $key );
		$now    = time();
		$window = is_array( $window ) && isset( $window['count'], $window['until'] ) && (int) $window['until'] > $now
			? array( 'count' => (int) $window['count'], 'until' => (int) $window['until'] )
			: array( 'count' => 0, 'until' => $now + HOUR_IN_SECONDS );
		if ( $window['count'] >= self::TEST_EMAILS_PER_HOUR ) {
			return false;
		}
		++$window['count'];
		set_transient( $key, $window, max( 1, $window['until'] - $now ) );

		return true;
	}

	/**
	 * Send a test health alert from the settings page, at most TEST_EMAILS_PER_HOUR per user and hour (P4-L4).
	 *
	 * @return string Message for the screen.
	 */
	public static function send_test_alert(): string {
		if ( ! HealthAlertMail::recipients() ) {
			return self::test_alert_message( 'no_recipient' );
		}
		if ( ! self::take_hourly_slot( 'alerts' ) ) {
			return sprintf(
				/* translators: %s: number of messages. */
				__( 'No test message was sent: you sent %s test messages within the last hour. Try again later.', 'wp-license' ),
				number_format_i18n( self::TEST_EMAILS_PER_HOUR )
			);
		}

		return self::test_alert_message( HealthAlertMail::send_test() );
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
			case 'test_alert':
				self::finish( self::send_test_alert() );
				return;
			case 'test_email':
				$email   = isset( $_POST['email'] ) ? sanitize_key( wp_unslash( $_POST['email'] ) ) : '';
				$address = isset( $_POST['address'] ) ? sanitize_email( wp_unslash( $_POST['address'] ) ) : '';
				$locale  = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
				self::finish( self::send_test_email( $email, $address, $locale ) );
				return;
			case 'guided':
				$answers = array();
				foreach ( self::GUIDED_SETTINGS as $key ) {
					if ( isset( $_POST[ $key ] ) ) {
						$answers[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
					}
				}
				$profile = isset( $_POST['profile'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['profile'] ) ) ) : '';
				self::finish( self::answer_guided_questions( $answers, $profile ) );
				return;
			case 's3_check':
				// SET-LIC-30: one signed HEAD request for the bucket; neither the secret nor the signed URL is in the result.
				$check = \WPLC\Update\S3Storage::check();
				set_transient(
					self::object_storage_key(),
					array(
						'ok'      => (bool) $check['ok'],
						'message' => (string) $check['message'],
					),
					10 * MINUTE_IN_SECONDS
				);
				wp_safe_redirect( admin_url( 'admin.php?page=' . Settings::PAGE . '&tab=updates#wplc-object-storage' ) );
				exit;
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
	 * Message for the result of a test health alert.
	 *
	 * @param string $result Result of HealthAlertMail::send_test().
	 * @return string
	 */
	public static function test_alert_message( string $result ): string {
		if ( 'sent' === $result ) {
			return __( 'The test message was handed over for sending to the recipients of the health alerts.', 'wp-license' );
		}
		if ( 'no_recipient' === $result ) {
			return __( 'No test message was sent: enter at least one address under "Send health alerts to" and save first.', 'wp-license' );
		}

		return __( 'WordPress could not hand the test message over for sending. Check the email setup of this site.', 'wp-license' );
	}

	/**
	 * Save the answers of the guided step "Decide the rules of your shop" (SET-LIC-45).
	 *
	 * The numbers are applied at once through the register (with the capability of each setting);
	 * a chosen country profile becomes the usual suggestion below the form, where nothing is ticked
	 * in advance. The step counts as done when no answer was refused.
	 *
	 * @param array<string,string> $answers Setting key => submitted value (only GUIDED_SETTINGS count).
	 * @param string               $profile Key of a country profile, or '' for none.
	 * @return string Message for the screen.
	 */
	public static function answer_guided_questions( array $answers, string $profile ): string {
		$answers = array_intersect_key( $answers, array_flip( self::GUIDED_SETTINGS ) );
		$refused = array();
		foreach ( Changes::preview( $answers ) as $row ) {
			if ( ! in_array( $row['state'], array( 'change', 'same', 'forbidden' ), true ) ) {
				$refused[] = $row['key'];
			}
		}
		if ( $refused ) {
			// Nothing is saved while one answer is not valid.
			return sprintf(
				/* translators: %s: list of setting names. */
				__( 'Not saved, because the value is not valid: %s. Check the numbers and answer again.', 'wp-license' ),
				implode( ', ', array_map( array( Texts::class, 'label' ), $refused ) )
			);
		}
		$result = $answers ? Changes::apply( $answers, 'settings_changed', 'Getting started: rules of the shop answered.' ) : array(
			'applied' => array(),
			'skipped' => array(),
		);
		if ( '' !== $profile && ! isset( Presets::all()[ $profile ] ) ) {
			$profile = '';
		}
		update_option( Settings::RULES_DECIDED_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
		$message = __( 'Your answers were saved.', 'wp-license' );
		if ( $result['applied'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: list of setting names. */
				__( 'Changed: %s.', 'wp-license' ),
				implode( ', ', array_map( array( Texts::class, 'label' ), $result['applied'] ) )
			);
		}
		if ( '' !== $profile ) {
			self::prepare_preset( $profile );
			$message .= ' ' . __( 'Check the suggestion of the country profile below and apply the rows you want; nothing is ticked in advance.', 'wp-license' );
		}

		return $message;
	}

	/**
	 * The questions of the step "Decide the rules of your shop" (SET-LIC-45).
	 *
	 * @param bool $answered Whether they were answered before (then they are folded away).
	 * @return void
	 */
	public static function render_guided_questions( bool $answered ): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		echo '<details class="wplc-guided"' . ( $answered ? '' : ' open' ) . '><summary>' . esc_html( $answered ? __( 'Change the answers', 'wp-license' ) : __( 'Answer the questions', 'wp-license' ) ) . '</summary>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="guided">';

		// 1. Country profile.
		echo '<fieldset><legend><strong>' . esc_html__( '1. Which country profile fits your shop?', 'wp-license' ) . '</strong></legend>';
		echo '<label for="wplc-guided-profile" class="screen-reader-text">' . esc_html__( 'Country profile', 'wp-license' ) . '</label><select id="wplc-guided-profile" name="profile" aria-describedby="wplc-guided-profile-help"><option value="">' . esc_html__( 'None: keep the current values', 'wp-license' ) . '</option>';
		foreach ( Presets::labels() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description" id="wplc-guided-profile-help">' . esc_html__( 'Suggests retention, consent and notice periods. You see every value before anything changes; no value of a profile has been through a legal review yet.', 'wp-license' ) . '</p></fieldset>';

		// 2. Staging and 3. grace period: the settings themselves, with their limits and capability.
		$questions = array(
			'staging_sites_per_live' => __( '2. How many staging copies may a customer run next to each live site?', 'wp-license' ),
			'renewal_grace_days'     => __( '3. How many days does a customer keep access when an automatic payment fails?', 'wp-license' ),
		);
		foreach ( $questions as $key => $question ) {
			$definition = Registry::definition( $key );
			if ( null === $definition ) {
				continue;
			}
			$allowed = Capabilities::can( (string) $definition['capability'] );
			$id      = 'wplc-guided-' . $key;
			echo '<fieldset><legend><strong>' . esc_html( $question ) . '</strong></legend>';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( Texts::label( $key ) ) . '</label> ';
			echo '<input type="number" id="' . esc_attr( $id ) . '"' . ( $allowed ? ' name="' . esc_attr( $key ) . '"' : ' readonly' ) . ' value="' . esc_attr( (string) Registry::int( $key ) ) . '" min="' . esc_attr( (string) (int) $definition['min'] ) . '" max="' . esc_attr( (string) (int) $definition['max'] ) . '" step="1" class="small-text" aria-describedby="' . esc_attr( $id . '-help' ) . '">';
			echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( Texts::help( $key ) );
			if ( ! $allowed ) {
				echo ' ' . esc_html( Settings::readonly_note( (string) $definition['capability'] ) );
			}
			echo '</p></fieldset>';
		}
		submit_button( __( 'Save the answers', 'wp-license' ), 'primary', 'submit', false, array( 'id' => 'wplc-guided-submit' ) );
		echo '</form></details>';
	}

	/**
	 * Languages a test email can be sent in: English and every installed language (SET-LIC-43).
	 *
	 * @return string[] WordPress locales.
	 */
	public static function test_email_locales(): array {
		$locales = array_merge( array( 'en_US' ), function_exists( 'get_available_languages' ) ? (array) get_available_languages() : array() );

		return array_values( array_unique( array_filter( array_map( 'strval', $locales ) ) ) );
	}

	/**
	 * Send a license email with sample data to a chosen address and language (SET-LIC-43).
	 *
	 * At most TEST_EMAILS_PER_HOUR per user and hour, so the button cannot be used to send mass mail.
	 *
	 * @param string $email_key Short key of the email.
	 * @param string $address   Recipient.
	 * @param string $locale    Locale; must be one of test_email_locales().
	 * @return string Message for the screen.
	 */
	public static function send_test_email( string $email_key, string $address, string $locale ): string {
		$titles = \WPLC\Notifications\Notifications::email_titles();
		if ( ! isset( $titles[ $email_key ] ) ) {
			return __( 'No test email was sent: choose one of the license emails.', 'wp-license' );
		}
		if ( ! is_email( $address ) ) {
			return __( 'No test email was sent: enter a valid email address.', 'wp-license' );
		}
		if ( ! in_array( $locale, self::test_email_locales(), true ) ) {
			return __( 'No test email was sent: choose one of the installed languages.', 'wp-license' );
		}
		if ( ! self::take_hourly_slot( 'emails' ) ) {
			return sprintf(
				/* translators: %s: number of emails. */
				__( 'No test email was sent: you sent %s test emails within the last hour. Try again later.', 'wp-license' ),
				number_format_i18n( self::TEST_EMAILS_PER_HOUR )
			);
		}
		$result = \WPLC\Notifications\Notifications::send_test_email( $email_key, $address, $locale );
		if ( 'sent' !== $result ) {
			return __( 'WordPress could not hand the test email over for sending. Check the email setup of this site.', 'wp-license' );
		}
		\WPLC\License\AuditLog::record( 'test_email_sent', 'settings', 0, 'Test email "' . $email_key . '" sent in ' . $locale . '.', null, null, (int) get_current_user_id() );

		return sprintf(
			/* translators: 1: title of the email, 2: email address, 3: name and code of the language. */
			__( 'The test email "%1$s" was handed over for sending to %2$s (language: %3$s). It contains sample data only.', 'wp-license' ),
			$titles[ $email_key ],
			$address,
			self::locale_name( $locale )
		);
	}

	/**
	 * The name of a language for the choice of the test email (P5-M16), e.g. "Deutsch (Sie) (de_DE_formal)".
	 *
	 * Uses the list of WordPress translations when it is cached (never a request to
	 * WordPress.org), else the language names of PHP (intl), else the code alone.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function locale_name( string $locale ): string {
		$name = '';
		if ( 'en_US' === $locale ) {
			$name = 'English (United States)';
		} else {
			$cached = function_exists( 'get_site_transient' ) ? get_site_transient( 'available_translations' ) : false;
			if ( is_array( $cached ) && isset( $cached[ $locale ]['native_name'] ) && is_string( $cached[ $locale ]['native_name'] ) ) {
				$name = $cached[ $locale ]['native_name'];
			} elseif ( class_exists( '\Locale' ) ) {
				$parts = explode( '_', $locale );
				$base  = implode( '_', array_slice( $parts, 0, 2 ) );
				$name  = (string) \Locale::getDisplayName( $base, $base );
				if ( count( $parts ) > 2 && '' !== $name ) {
					$name .= ' (' . implode( ', ', array_slice( $parts, 2 ) ) . ')';
				}
			}
		}

		return '' === $name || $name === $locale ? $locale : $name . ' (' . $locale . ')';
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
		self::store_pending( 'preset', $code, array_diff_key( $profiles[ $code ]['values'], array( 'uninstall_data' => true ) ) );

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
		if ( isset( $values['uninstall_data'] ) && 'keep' !== $values['uninstall_data'] ) {
			// S10 / P4-L3: deleting all data is only chosen in its own field, with its own confirmation.
			unset( $values['uninstall_data'] );
		}
		if ( ! $values ) {
			return __( 'Nothing was changed: no row was ticked.', 'wp-license' );
		}
		// P3-A7: a value that changed after the preview was prepared is not overwritten.
		$changed = array_values( array_intersect( self::changed_since_preview( $pending ), array_keys( $values ) ) );
		$values  = array_diff_key( $values, array_flip( $changed ) );
		$actions = array(
			'preset' => array( 'settings_preset_applied', 'Country profile ' . $pending['name'] . ' applied as chosen.' ),
			'reset'  => array( 'settings_reset', 'Reset to defaults (' . $pending['name'] . ').' ),
			'import' => array( 'settings_imported', 'Settings imported from a file.' ),
		);
		$action = $actions[ $pending['source'] ] ?? array( 'settings_changed', 'Settings changed.' );
		$result = $values ? Changes::apply( $values, $action[0], $action[1] ) : array(
			'applied'  => array(),
			'skipped'  => array(),
			'warnings' => array(),
		);
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
		if ( $changed ) {
			$message .= ' ' . sprintf(
				/* translators: %s: list of setting names. */
				__( 'Not changed, because the value was changed after the preview was prepared: %s. Prepare the preview again to see the current values.', 'wp-license' ),
				implode( ', ', array_map( array( Texts::class, 'label' ), $changed ) )
			);
		}
		foreach ( $result['warnings'] as $warning ) {
			$message .= ' ' . $warning;
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
		$before = array();
		foreach ( array_keys( $values ) as $key ) {
			if ( null !== Registry::definition( (string) $key ) ) {
				$before[ (string) $key ] = self::comparable( (string) $key );
			}
		}
		set_transient(
			self::pending_key(),
			array(
				'source'  => $source,
				'name'    => $name,
				'values'  => $values,
				'ignored' => $ignored,
				'before'  => $before,
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
		wp_safe_redirect( self::return_url() );
		exit;
	}

	/**
	 * The settings page to return to, on the tab the form was sent from (P5-M15).
	 *
	 * @return string
	 */
	public static function return_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() checked the nonce; the tab only picks the page to return to.
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$url = 'admin.php?page=' . Settings::PAGE;
		if ( in_array( $tab, Registry::GROUPS, true ) && Registry::keys_of_group( $tab ) ) {
			$url .= '&tab=' . $tab;
		}

		return admin_url( $url . '#wplc-settings-tools' );
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
		// The button "Test the connection" next to the object storage fields submits this form (SET-LIC-30).
		echo '<form id="wplc-object-storage-check" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="tool" value="s3_check">';
		wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, true );
		echo '</form>';
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
		submit_button( __( 'Show suggestion', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-preset-submit' ) );
		echo '</form>';

		// Export and import.
		echo '<h3>' . esc_html__( 'Export and import', 'wp-license' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'The export is a JSON file with all settings of this page. It contains no secret: keys and passwords are not settings and stay in wp-config.php. An import shows every difference before anything is changed.', 'wp-license' ) . '</p>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="export">';
		submit_button( __( 'Export settings', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-export-submit' ) );
		echo '</form>';
		self::open_form( true );
		echo '<input type="hidden" name="tool" value="import"><p><label for="wplc-import-file">' . esc_html__( 'Export file', 'wp-license' ) . '</label><br><input type="file" id="wplc-import-file" name="file" accept=".json,application/json"></p>';
		echo '<p><label for="wplc-import-json">' . esc_html__( 'Or paste the content of the file', 'wp-license' ) . '</label><br><textarea id="wplc-import-json" name="json" rows="3" cols="50" class="large-text code"></textarea></p>';
		submit_button( __( 'Preview import', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-import-submit' ) );
		echo '</form>';

		// Health alerts (SET-LIC-22).
		echo '<h3>' . esc_html__( 'Test the health alerts', 'wp-license' ) . '</h3>';
		/* translators: %s: number of messages. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Sends a test message to the addresses under "Send health alerts to", also when an alert email already left today. At most %s test messages per hour.', 'wp-license' ), number_format_i18n( self::TEST_EMAILS_PER_HOUR ) ) ) . '</p>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="test_alert">';
		submit_button( __( 'Send test message', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-test-alert-submit' ) );
		echo '</form>';

		// Test email (SET-LIC-43).
		$titles = \WPLC\Notifications\Notifications::email_titles();
		if ( $titles ) {
			$user = wp_get_current_user();
			echo '<h3>' . esc_html__( 'Send a test email', 'wp-license' ) . '</h3>';
			/* translators: %s: number of emails. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Sends one license email with the sample data of the preview to any address, in any installed language, also when the email is switched off for customers. The subject starts with "[Test]". At most %s test emails per hour.', 'wp-license' ), number_format_i18n( self::TEST_EMAILS_PER_HOUR ) ) ) . '</p>';
			self::open_form();
			echo '<input type="hidden" name="tool" value="test_email">';
			echo '<p><label for="wplc-test-email">' . esc_html__( 'Email', 'wp-license' ) . '</label><br><select id="wplc-test-email" name="email">';
			foreach ( $titles as $email_key => $title ) {
				echo '<option value="' . esc_attr( $email_key ) . '">' . esc_html( $title ) . '</option>';
			}
			echo '</select></p>';
			echo '<p><label for="wplc-test-address">' . esc_html__( 'Send to', 'wp-license' ) . '</label><br><input type="email" id="wplc-test-address" name="address" class="regular-text" required value="' . esc_attr( (string) $user->user_email ) . '"></p>';
			echo '<p><label for="wplc-test-locale">' . esc_html__( 'Language', 'wp-license' ) . '</label><br><select id="wplc-test-locale" name="locale">';
			$site_locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
			foreach ( self::test_email_locales() as $locale ) {
				echo '<option value="' . esc_attr( $locale ) . '"' . selected( $locale, $site_locale, false ) . '>' . esc_html( self::locale_name( $locale ) ) . '</option>';
			}
			echo '</select></p>';
			submit_button( __( 'Send test email', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-test-email-submit' ) );
			echo '</form>';
		}

		// Change log (SET-LIC-15).
		echo '<p><a href="' . esc_url( AuditLogAdmin::url( array( 'object_type' => 'settings' ) ) ) . '">' . esc_html__( 'Show all changes of the settings in the audit log', 'wp-license' ) . '</a></p>';

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
		submit_button( __( 'Preview reset', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-reset-submit' ) );
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
		// P5-M15: back to the tab the form was sent from.
		echo '<input type="hidden" name="tab" value="' . esc_attr( Settings::current_tab() ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
	}

	/**
	 * Render the preview of a prepared change with its apply form.
	 *
	 * @param array{source:string,name:string,values:array<string,mixed>,ignored:string[],before:array<string,mixed>} $pending Prepared change.
	 * @return void
	 */
	private static function render_preview( array $pending ): void {
		$rows     = Changes::preview( $pending['values'] );
		$changed  = self::changed_since_preview( $pending );
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
			'locked'    => __( 'fixed: licenses were already issued with this value', 'wp-license' ),
			'constant'  => __( 'set by a constant in wp-config.php, which wins', 'wp-license' ),
			'conflict'  => __( 'not possible: another page of the customer account already has this address', 'wp-license' ),
			'unknown'   => __( 'unknown setting, is left out', 'wp-license' ),
			'undecided' => __( 'please decide: no value is suggested for this country', 'wp-license' ),
		);
		self::open_form();
		echo '<input type="hidden" name="tool" value="apply"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Apply', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Setting', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Now', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Afterwards', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Note', 'wp-license' ) . '</th></tr></thead><tbody>';
		$changes = 0;
		foreach ( $rows as $row ) {
			$id    = 'wplc-apply-' . $row['key'];
			$state = 'change' === $row['state'] && in_array( $row['key'], $changed, true ) ? 'changed_since' : $row['state'];
			$note  = 'changed_since' === $state ? __( 'changed after this preview was prepared: prepare it again', 'wp-license' ) : ( $states[ $state ] ?? $state );
			if ( $row['legal'] && ( $preset ? '' === $reviewed : 'change' === $state ) ) {
				// P3-A8: a row with legal weight is never ticked in advance and always says so.
				$note .= ' · ' . __( 'unchecked', 'wp-license' );
			}
			echo '<tr data-wplc-state="' . esc_attr( $state ) . '"><td>';
			if ( 'change' === $state ) {
				++$changes;
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="keys[]" value="' . esc_attr( $row['key'] ) . '"' . checked( ! $preset && ! $row['legal'], true, false ) . '>';
				// P5-M13: a label only where there is a box to label.
				echo '</td><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $row['label'] ) . '</label></th>';
			} else {
				echo '</td><th scope="row">' . esc_html( $row['label'] ) . '</th>';
			}
			echo '<td>' . esc_html( self::preview_value( $row['key'], $row['before'] ) ) . '</td><td>' . esc_html( 'undecided' === $row['state'] ? '?' : self::preview_value( $row['key'], $row['after'] ) ) . '</td><td>' . esc_html( $note ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $changes > 0 ) {
			echo '<p><label for="wplc-apply-confirm"><input type="checkbox" id="wplc-apply-confirm" name="confirm" value="1" required> ' . esc_html__( 'I have checked the values and want to apply the ticked rows.', 'wp-license' ) . '</label></p>';
			submit_button( __( 'Apply ticked rows', 'wp-license' ), 'primary', 'submit', false, array( 'id' => 'wplc-apply-submit' ) );
		} else {
			echo '<p>' . esc_html__( 'There is nothing to apply.', 'wp-license' ) . '</p>';
		}
		echo '</form>';
		self::open_form();
		echo '<input type="hidden" name="tool" value="discard">';
		submit_button( __( 'Discard the preview', 'wp-license' ), 'secondary', 'submit', false, array( 'id' => 'wplc-discard-submit' ) );
		echo '</form>';
	}

	/**
	 * A value in a preview row: "–" for an empty one, with what empty means where it means more (P3-A8).
	 *
	 * @param string $key   Setting key.
	 * @param string $shown Value as Registry::display() shows it.
	 * @return string
	 */
	public static function preview_value( string $key, string $shown ): string {
		if ( '' !== $shown ) {
			return $shown;
		}
		$definition = Registry::definition( $key );
		if ( null !== $definition && 'builtin_text' === ( $definition['empty_note'] ?? '' ) ) {
			return __( '– (empty: the built-in wording in the language of the customer)', 'wp-license' );
		}

		return '–';
	}
}
