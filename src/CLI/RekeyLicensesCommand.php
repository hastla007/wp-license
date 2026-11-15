<?php
/**
 * Replace the keys issued under a leaked license-key secret (B-155).
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\License\LicenseKey;
use WPLC\License\LicenseKeyRotation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc rekey-licenses`: give every license of one key ID a new key.
 */
final class RekeyLicensesCommand {

	/**
	 * Issue a new key for every license whose key was derived with one secret ID.
	 *
	 * Use this after a license-key secret leaked. Removing the secret from
	 * WPLC_LICENSE_KEY_SECRETS alone does not stop keys derived from it: the
	 * server finds a key by its stored hash. Each license gets a new UUID and a
	 * key derived with the current secret (WPLC_LICENSE_KEY_ID); its old key
	 * stops working at once. Activated sites keep working unless
	 * --release-sites is given. Revoked licenses are re-keyed too (without a
	 * notification), pending ones are skipped. No key is printed; each change
	 * is audited as `license_key_reissued`.
	 *
	 * Without --apply the command only counts. It ends with exit code 1 while
	 * licenses with that key ID are left.
	 *
	 * On a multisite network run the command once per site with --url=<site>.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<key-id>]
	 * : Key ID of the leaked secret. Without it the command lists the key IDs in use.
	 *
	 * [--apply]
	 * : Issue the new keys. Without this flag nothing is changed.
	 *
	 * [--notify]
	 * : Send each owner the new key by email (fires `wplc/license_key_reissued`).
	 *
	 * [--release-sites]
	 * : Also release every activated site of these licenses; they have to be activated again with the new key.
	 *
	 * [--max-rows=<number>]
	 * : Maximum licenses to re-key in this run. Default 0: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc rekey-licenses
	 *     wp wplc rekey-licenses --from=2026-01
	 *     wp wplc rekey-licenses --from=2026-01 --apply --notify
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$counts = LicenseKeyRotation::count_by_key_id();
		$from   = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
		if ( '' === $from ) {
			foreach ( $counts as $key_id => $licenses ) {
				/* translators: 1: license key ID, 2: number of licenses, 3: "current" or an empty string. */
				\WP_CLI::log( sprintf( __( 'Key ID %1$s: %2$d licenses %3$s', 'wp-license' ), $key_id, $licenses, LicenseKey::current_key_id() === (string) $key_id ? __( '(current)', 'wp-license' ) : '' ) );
			}
			return;
		}
		if ( ! preg_match( '/^[a-zA-Z0-9._-]{1,32}$/', $from ) ) {
			\WP_CLI::error( __( 'Provide a valid key ID with --from=<key-id>.', 'wp-license' ) );
		}
		$current = LicenseKey::current_key_id();
		if ( $current === $from ) {
			\WP_CLI::error( __( 'This key ID is still the current one (WPLC_LICENSE_KEY_ID). Add a new random secret to WPLC_LICENSE_KEY_SECRETS, make it current and deploy it first; otherwise the new keys would use the leaked secret again.', 'wp-license' ) );
		}
		if ( ! LicenseKey::is_configured( $current ) ) {
			/* translators: %s: current license key ID. */
			\WP_CLI::error( sprintf( __( 'The current key ID "%s" has no secret in WPLC_LICENSE_KEY_SECRETS. Fix wp-config.php first.', 'wp-license' ), $current ) );
		}

		$apply    = isset( $assoc_args['apply'] );
		$notify   = isset( $assoc_args['notify'] );
		$release  = isset( $assoc_args['release-sites'] );
		$max_rows = isset( $assoc_args['max-rows'] ) ? absint( $assoc_args['max-rows'] ) : 0;
		$found    = $counts[ $from ] ?? 0;

		if ( ! $apply ) {
			/* translators: 1: number of licenses, 2: leaked key ID, 3: current key ID. */
			\WP_CLI::log( sprintf( __( 'Mode: dry-run; licenses with key ID %2$s: %1$d; new keys would use key ID %3$s.', 'wp-license' ), $found, $from, $current ) );
			if ( $found > 0 ) {
				\WP_CLI::halt( 1 );
			}
			return;
		}

		$rekeyed = 0;
		$skipped = 0;
		$done    = 0;
		$last_id = 0;
		while ( 0 === $max_rows || $done < $max_rows ) {
			$ids = LicenseKeyRotation::license_ids( $from, $last_id, 0 === $max_rows ? 250 : min( 250, $max_rows - $done ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$last_id = $id;
				++$done;
				if ( LicenseKeyRotation::RESULT_REKEYED === LicenseKeyRotation::rekey( $id, $from, $release, $notify ) ) {
					++$rekeyed;
				} else {
					++$skipped;
				}
			}
		}
		$left = LicenseKeyRotation::count_by_key_id()[ $from ] ?? 0;

		\WP_CLI::log(
			sprintf(
				/* translators: 1: re-keyed licenses, 2: skipped licenses (pending or changed meanwhile), 3: licenses still on the leaked key ID, 4: leaked key ID. */
				__( 'Mode: apply; re-keyed: %1$d; skipped: %2$d; still on key ID %4$s: %3$d', 'wp-license' ),
				$rekeyed,
				$skipped,
				$left,
				$from
			)
		);
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			\WP_CLI::log( __( 'Multisite: this run covered only the tables of the current site. Repeat it for every site with --url=<site>.', 'wp-license' ) );
		}
		if ( $left > 0 ) {
			\WP_CLI::warning( __( 'Licenses with this key ID are left (pending issuance, --max-rows or a concurrent change). Run the command again.', 'wp-license' ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( __( 'No license uses this key ID any more. Remove its secret from WPLC_LICENSE_KEY_SECRETS once no backup you might restore still needs it.', 'wp-license' ) );
	}
}
