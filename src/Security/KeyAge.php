<?php
/**
 * Age of the license, encryption and signing keys, and the reminder to rotate them.
 *
 * @package WPLC
 */

namespace WPLC\Security;

use WPLC\License\LicenseKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers when a key was first seen and reminds to rotate it after a number of months (SET-LIC-28).
 *
 * Keys live in wp-config.php and carry no creation date. The server stores, per kind of key, a
 * fingerprint of the key in use and the date it first saw it (option `wplc_key_first_seen`). The age
 * therefore counts from that date: for a key that existed before this was added, from the first day
 * the updated plugin ran. The fingerprint is the key ID and 16 hex characters of an HMAC of a fixed
 * text (license and encryption key) or the key ID of the public key (signing key); it reveals nothing
 * usable about the key.
 *
 * The reminder is off by default (setting `key_rotation_reminder_months` 0). A due key is reported
 * on the diagnostics page, as the health figure `keys_due_for_rotation` (action wplc/health_alert and
 * the health alert email) and as a notice for administrators. Rotating stays a task for wp-config.php
 * and WP-CLI; nothing is rotated automatically.
 */
final class KeyAge {

	/**
	 * Option: kind => fingerprint => first seen (UTC, Y-m-d H:i:s).
	 */
	public const OPTION = 'wplc_key_first_seen';

	/**
	 * Kinds of keys.
	 */
	public const KINDS = array( 'license', 'encryption', 'signing' );

	/**
	 * Fingerprints kept per kind (the newest; older ones are forgotten).
	 */
	private const KEEP = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Before the health figures of the daily run are counted (Diagnostics::emit_alerts).
		add_action( \WPLC\Maintenance\DailyRunner::HOOK, array( self::class, 'record_daily' ), 1, 0 );
	}

	/**
	 * The daily run: store the first-seen date of new keys.
	 *
	 * @return void
	 */
	public static function record_daily(): void {
		self::record();
	}

	/**
	 * Fingerprints of the keys in use now; '' for a kind without a dedicated key.
	 *
	 * The signing key is only read when it comes from the constant or is stored already, so that
	 * looking at the age never creates a key.
	 *
	 * @return array<string,string>
	 */
	public static function fingerprints(): array {
		$signing = '';
		if ( ResponseSigner::uses_constant() || '' !== (string) get_option( ResponseSigner::OPTION, '' ) ) {
			$status  = ResponseSigner::status();
			$signing = 'ok' === $status['state'] ? (string) $status['key_id'] : '';
		}

		return array(
			'license'    => LicenseKey::fingerprint(),
			'encryption' => SecretCrypto::fingerprint(),
			'signing'    => $signing,
		);
	}

	/**
	 * Store the first-seen date of every key in use that is not known yet.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return array<string,array<string,string>> The stored map.
	 */
	public static function record( $now = null ): array {
		$now     = is_int( $now ) ? $now : time();
		$stored  = self::stored();
		$changed = false;
		foreach ( self::fingerprints() as $kind => $fingerprint ) {
			if ( '' === $fingerprint || isset( $stored[ $kind ][ $fingerprint ] ) ) {
				continue;
			}
			$stored[ $kind ][ $fingerprint ] = gmdate( 'Y-m-d H:i:s', $now );
			if ( count( $stored[ $kind ] ) > self::KEEP ) {
				asort( $stored[ $kind ] );
				$stored[ $kind ] = array_slice( $stored[ $kind ], -self::KEEP, null, true );
			}
			$changed = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $stored, false );
		}

		return $stored;
	}

	/**
	 * The stored map, cleaned.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function stored(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( self::KINDS as $kind ) {
			$out[ $kind ] = array();
			if ( ! is_array( $raw ) || ! isset( $raw[ $kind ] ) || ! is_array( $raw[ $kind ] ) ) {
				continue;
			}
			foreach ( $raw[ $kind ] as $fingerprint => $seen ) {
				if ( is_string( $seen ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $seen ) ) {
					$out[ $kind ][ (string) $fingerprint ] = $seen;
				}
			}
		}

		return $out;
	}

	/**
	 * Months after which a key should be rotated: the setting, then the filter; 0 = no reminder.
	 *
	 * @return int 0 to 120.
	 */
	public static function reminder_months(): int {
		$months = \WPLC\Settings\Registry::int( 'key_rotation_reminder_months' );

		/**
		 * Filters after how many months a key should be rotated. Wins over the setting.
		 *
		 * @since 0.2.0
		 *
		 * @param int $months The setting "Remind to rotate keys after" (0 = no reminder).
		 */
		return max( 0, min( 120, (int) apply_filters( 'wplc_key_rotation_reminder_months', $months ) ) );
	}

	/**
	 * Whole calendar months between two times. Pure helper.
	 *
	 * @param int $from Unix time.
	 * @param int $to   Unix time.
	 * @return int
	 */
	public static function months_between( int $from, int $to ): int {
		if ( $to <= $from ) {
			return 0;
		}
		$diff = ( new \DateTimeImmutable( '@' . $from ) )->diff( new \DateTimeImmutable( '@' . $to ) );

		return (int) $diff->y * 12 + (int) $diff->m;
	}

	/**
	 * Age of every key in use.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return array<string,array{key_id:string,first_seen:string,months:int,due:bool}> Kinds without a dedicated key are left out.
	 */
	public static function ages( $now = null ): array {
		$now      = is_int( $now ) ? $now : time();
		$stored   = self::record( $now );
		$reminder = self::reminder_months();
		$out      = array();
		foreach ( self::fingerprints() as $kind => $fingerprint ) {
			if ( '' === $fingerprint || ! isset( $stored[ $kind ][ $fingerprint ] ) ) {
				continue;
			}
			$seen         = (int) strtotime( $stored[ $kind ][ $fingerprint ] . ' UTC' );
			$months       = self::months_between( $seen, $now );
			$out[ $kind ] = array(
				'key_id'     => 'signing' === $kind ? $fingerprint : (string) strstr( $fingerprint, ':', true ),
				'first_seen' => $stored[ $kind ][ $fingerprint ],
				'months'     => $months,
				'due'        => $reminder > 0 && $months >= $reminder,
			);
		}

		return $out;
	}

	/**
	 * Kinds of keys that are due for rotation.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return string[]
	 */
	public static function due( $now = null ): array {
		if ( 0 === self::reminder_months() ) {
			return array();
		}
		$due = array();
		foreach ( self::ages( $now ) as $kind => $age ) {
			if ( $age['due'] ) {
				$due[] = (string) $kind;
			}
		}

		return $due;
	}

	/**
	 * Name of a kind of key.
	 *
	 * @param string $kind license, encryption or signing.
	 * @return string
	 */
	public static function label( string $kind ): string {
		$labels = array(
			'license'    => __( 'License key secret', 'wp-license' ),
			'encryption' => __( 'Installation secret encryption key', 'wp-license' ),
			'signing'    => __( 'Response signing key', 'wp-license' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * How to rotate a kind of key.
	 *
	 * @param string $kind license, encryption or signing.
	 * @return string
	 */
	public static function how_to_rotate( string $kind ): string {
		$texts = array(
			'license'    => __( 'Add a new entry to WPLC_LICENSE_KEY_SECRETS, keep the old one, and point WPLC_LICENSE_KEY_ID to the new entry. Keys issued before keep working.', 'wp-license' ),
			'encryption' => __( 'Add a new entry to WPLC_INSTALLATION_ENCRYPTION_KEYS, keep the old one, point WPLC_INSTALLATION_ENCRYPTION_KEY_ID to it and run "wp wplc rotate-installation-secrets --apply".', 'wp-license' ),
			'signing'    => __( 'Prepare the next key with "wp wplc signing-key --prepare", ship its public key with a release of your add-ons, then switch with "wp wplc signing-key --rotate" (or replace WPLC_RESPONSE_SIGNING_KEY).', 'wp-license' ),
		);

		return $texts[ $kind ] ?? '';
	}
}
