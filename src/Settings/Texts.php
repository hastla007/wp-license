<?php
/**
 * Labels and help texts of the settings.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated texts for the settings page, previews and WP-CLI. Kept apart from the
 * definitions so that reading a value never loads translations.
 */
final class Texts {

	/**
	 * Titles of the groups (tabs of the settings page).
	 *
	 * @return array<string,string> Group key => title.
	 */
	public static function groups(): array {
		return array(
			'updates'    => __( 'Updates', 'wp-license' ),
			'renewals'   => __( 'Automatic renewal', 'wp-license' ),
			'emails'     => __( 'Customer emails', 'wp-license' ),
			'rules'      => __( 'Rules of this shop', 'wp-license' ),
			'sites'      => __( 'Customer sites', 'wp-license' ),
			'legal'      => __( 'Legal and privacy', 'wp-license' ),
			'texts'      => __( 'Texts and support', 'wp-license' ),
			'operations' => __( 'Operations', 'wp-license' ),
		);
	}

	/**
	 * The sentence added to the help of every setting with legal weight.
	 *
	 * @return string
	 */
	public static function legal_note(): string {
		return __( 'This value has legal weight. The default is not legal advice; have it checked for your country (legal review).', 'wp-license' );
	}

	/**
	 * Label of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string Empty for an unknown key.
	 */
	public static function label( string $key ): string {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ][0] : '';
	}

	/**
	 * Help text of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string Empty for an unknown key.
	 */
	public static function help( string $key ): string {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ][1] : '';
	}

	/**
	 * Label and help of every setting.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function all(): array {
		return array(
			'package_hosts'             => array(
				__( 'Additional trusted package hosts', 'wp-license' ),
				__( 'One host name per line, for example downloads.example.com. Update packages are only delivered from HTTPS URLs on these hosts. This site is always trusted.', 'wp-license' ),
			),
			'catalog_auto_import'       => array(
				__( 'Catalog file of release packages', 'wp-license' ),
				__( 'When a release is created from a ZIP, the file wplc-catalog.json in the plugin folder of the package is checked and imported into the license catalog. The release is created in any case; a file that is invalid or describes another product is not imported and reported. Plans and licenses are never changed.', 'wp-license' ),
			),
			'combined_renewals'         => array(
				__( 'Combine automatic renewals', 'wp-license' ),
				__( 'Only licenses with the same saved card, currency and billing interval are combined. Each license keeps its own renewal; a declined payment is retried per license. Manual renewals are never combined. Off by default.', 'wp-license' ),
			),
			'renewal_grace_days'        => array(
				__( 'Grace period after the paid period', 'wp-license' ),
				__( 'Days after the end of the paid period during which a license with a pending or failed automatic charge keeps its access. Default: 14. Keep it longer than the last retry day below, or the last attempts fall after access has ended. A changed value applies to renewals scheduled afterwards; renewals already scheduled keep their date.', 'wp-license' ),
			),
			'renewal_retry_days'        => array(
				__( 'Retry days of a failed charge', 'wp-license' ),
				__( 'Days after the start of the new period on which a failed automatic charge is tried again, separated by commas. The number of entries is the number of retries. Default: 1,3,7. Empty = no retry: the first failed charge is final.', 'wp-license' ),
			),
			'manual_renewal_days_before' => array(
				__( 'Manual renewal: days before the end', 'wp-license' ),
				__( 'From this many days before the end of the paid period a customer can renew by hand in the account, and the emails offer it. Default: 30. 0 = only from the day the period ends.', 'wp-license' ),
			),
			'manual_renewal_days_after' => array(
				__( 'Manual renewal: days after the end', 'wp-license' ),
				__( 'Until this many days after the end of the paid period an expired license can still be renewed and continues with the same key and the same sites. Default: 30. 0 = an expired license cannot be reactivated by the customer.', 'wp-license' ),
			),
			'reminder_days'             => array(
				__( 'Reminder lead days', 'wp-license' ),
				__( 'Days before the end of the paid period on which a reminder is sent, separated by commas. Default: 30,7.', 'wp-license' ),
			),
			'renewal_notice_days'       => array(
				__( 'Renewal notice lead days', 'wp-license' ),
				__( 'Days before an automatic charge on which the customer is told the date and the amount. Default: 7. A license is only charged after this email was accepted for sending; otherwise the charge is postponed and reported under Diagnostics.', 'wp-license' ),
			),
			'price_change_notice_days'  => array(
				__( 'Price change notice period', 'wp-license' ),
				__( 'Days between the email that announces a higher renewal price for an existing license and the first renewal at that price. Default: 60, at least 30. Have the period and the wording of the email checked by a lawyer.', 'wp-license' ),
			),
			'sender_note'               => array(
				__( 'Sender note', 'wp-license' ),
				__( 'Optional short text that license emails may show below their content, for example a support contact.', 'wp-license' ),
			),
			'max_quantity_per_line'     => array(
				__( 'Largest quantity per order line', 'wp-license' ),
				__( 'Largest quantity of one license product a customer can put into the cart and order. Every unit becomes a license of its own with its own key. Default: 100. 0 = no limit. An order you create yourself in the admin or through the REST API is not blocked; a larger quantity there is noted on the order and in the audit log.', 'wp-license' ),
			),
			'suspended_ends_after_days' => array(
				__( 'Suspended licenses count as ended after', 'wp-license' ),
				__( 'Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel.', 'wp-license' ),
			),
		);
	}

	/**
	 * Labels of the choices of a setting of type `choice`.
	 *
	 * @param string $key Setting key.
	 * @return array<string,string> Choice => label.
	 */
	public static function choices( string $key ): array {
		/**
		 * Labels by setting.
		 *
		 * @var array<string,array<string,string>> $all
		 */
		$all = array();

		return $all[ $key ] ?? array();
	}

	/**
	 * Translated message for a rejected value (Registry::validate() reports the code).
	 *
	 * @param string $key  Setting key.
	 * @param string $code Problem code of Registry::validate().
	 * @return string
	 */
	public static function problem( string $key, string $code ): string {
		$definition = Registry::definition( $key );
		$label      = self::label( $key );
		$min        = (int) ( $definition['min'] ?? 0 );
		$max        = (int) ( $definition['max'] ?? 0 );
		switch ( $code ) {
			case 'not_a_number':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter a whole number.', 'wp-license' ), $label );
			case 'out_of_range':
				/* translators: 1: name of a setting, 2: smallest value, 3: largest value. */
				return sprintf( __( '"%1$s" was not changed: enter whole numbers from %2$s to %3$s.', 'wp-license' ), $label, number_format_i18n( $min ), number_format_i18n( $max ) );
			case 'too_many':
				/* translators: 1: name of a setting, 2: largest number of entries. */
				return sprintf( __( '"%1$s" was not changed: at most %2$s entries are possible.', 'wp-license' ), $label, number_format_i18n( (int) ( $definition['max_items'] ?? 0 ) ) );
			case 'too_long':
				/* translators: 1: name of a setting, 2: largest number of characters. */
				return sprintf( __( '"%1$s" was not changed: the text is longer than %2$s characters.', 'wp-license' ), $label, number_format_i18n( (int) ( $definition['max_length'] ?? 500 ) ) );
			case 'not_an_email':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter valid email addresses.', 'wp-license' ), $label );
			case 'not_a_url':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter an address that starts with https://.', 'wp-license' ), $label );
			case 'invalid_entries':
			case 'invalid_hosts':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: at least one entry is not valid. Enter one entry per line, lowercase letters, digits, dots and hyphens only.', 'wp-license' ), $label );
		}

		/* translators: %s: name of a setting. */
		return sprintf( __( '"%s" was not changed: the value is not valid.', 'wp-license' ), $label );
	}
}
