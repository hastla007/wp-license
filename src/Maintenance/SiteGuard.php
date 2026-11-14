<?php
/**
 * Staging guard: pauses billing on a copy of the shop database.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether this installation may charge customers and send billing mails.
 *
 * A copy of the shop database (staging clone, restore on another host) contains
 * the same due renewals, payment tokens and customers as the live shop. Without
 * a guard it would charge real cards and send "payment failed", "expired" and
 * reminder mails. The guard stores a fingerprint of the installation (home URL
 * host and path, database name, table prefix) on the first run and pauses
 * billing whenever the current fingerprint differs, until an administrator
 * confirms which installation this is.
 *
 * Billing is paused when one of these is true (first match is the reason):
 * - `constant`:         `WPLC_PAUSE_BILLING` is defined as true.
 * - `schema_downgrade`: the database was written by a newer plugin version.
 * - `fingerprint`:      the stored fingerprint differs from this installation.
 * - `environment`:      `wp_get_environment_type()` is not "production"
 *                       (not applied when `WPLC_PAUSE_BILLING` is defined as false).
 *
 * Callers (charge path, expiry and reminder jobs, customer mails) ask
 * {@see SiteGuard::billing_paused()} immediately before the side effect.
 */
final class SiteGuard {

	/**
	 * Autoloaded option with the confirmed fingerprint of this installation.
	 */
	public const OPTION = 'wplc_site_fingerprint';

	/**
	 * Autoloaded option: fingerprint hash for which an administrator chose "keep paused".
	 */
	public const KEEP_OPTION = 'wplc_site_guard_keep_paused';

	/**
	 * Option with the unix time at which the daily run first saw billing paused.
	 */
	public const PAUSED_SINCE_OPTION = 'wplc_billing_paused_since';

	/**
	 * Name of the admin-post action and of the nonce of the confirmation forms.
	 */
	public const ACTION = 'wplc_site_guard';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'remember' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_decision' ) );
	}

	/**
	 * Whether charges, job status changes and customer billing mails must not happen.
	 *
	 * @return bool
	 */
	public static function billing_paused(): bool {
		$reason = self::pause_reason();

		/**
		 * Filter whether billing is paused on this installation.
		 *
		 * Return false on a staging site that is meant to charge test cards, or
		 * true to pause for a reason of your own.
		 *
		 * @param bool   $paused Whether billing is paused.
		 * @param string $reason '', 'constant', 'schema_downgrade', 'fingerprint' or 'environment'.
		 */
		return (bool) apply_filters( 'wplc_billing_paused', '' !== $reason, $reason );
	}

	/**
	 * Why billing is paused, before the `wplc_billing_paused` filter.
	 *
	 * @return string '' when nothing pauses billing, otherwise 'constant', 'schema_downgrade', 'fingerprint' or 'environment'.
	 */
	public static function pause_reason(): string {
		$stored = get_option( self::OPTION, null );

		return self::decide(
			defined( 'WPLC_PAUSE_BILLING' ) ? (bool) constant( 'WPLC_PAUSE_BILLING' ) : null,
			Schema::is_downgraded(),
			is_array( $stored ) && isset( $stored['hash'] ) ? (string) $stored['hash'] : '',
			self::current()['hash'],
			function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production'
		);
	}

	/**
	 * Pure decision: the first matching reason wins.
	 *
	 * @param bool|null $constant         Value of WPLC_PAUSE_BILLING, null when undefined.
	 * @param bool      $downgraded       Whether the schema is newer than the code.
	 * @param string    $stored_hash      Confirmed fingerprint hash, '' before the first run.
	 * @param string    $current_hash     Fingerprint hash of this installation.
	 * @param string    $environment_type Result of wp_get_environment_type().
	 * @return string
	 */
	public static function decide( ?bool $constant, bool $downgraded, string $stored_hash, string $current_hash, string $environment_type ): string {
		if ( true === $constant ) {
			return 'constant';
		}
		if ( $downgraded ) {
			return 'schema_downgrade';
		}
		if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $current_hash ) ) {
			return 'fingerprint';
		}
		if ( 'production' !== $environment_type && false !== $constant ) {
			return 'environment';
		}

		return '';
	}

	/**
	 * Host and path of a home URL: lower case, without scheme, port, "www." and trailing slash.
	 *
	 * Pure. Switching to HTTPS or dropping "www." is the same shop; another
	 * host or another sub-directory is not.
	 *
	 * @param string $home_url Home URL.
	 * @return string
	 */
	public static function normalize_home( string $home_url ): string {
		$home_url = strtolower( trim( $home_url ) );
		$home_url = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $home_url );
		$home_url = (string) preg_replace( '#[?\#].*$#', '', $home_url );
		$parts    = explode( '/', $home_url, 2 );
		$host     = (string) preg_replace( '/:\d+$/', '', $parts[0] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$path = isset( $parts[1] ) ? trim( $parts[1], '/' ) : '';

		return '' === $path ? $host : $host . '/' . $path;
	}

	/**
	 * Build a fingerprint from its parts. Pure.
	 *
	 * @param string $home_url     Home URL.
	 * @param string $db_name      Database name.
	 * @param string $table_prefix Table prefix of the site.
	 * @return array{home:string,db:string,prefix:string,hash:string}
	 */
	public static function fingerprint( string $home_url, string $db_name, string $table_prefix ): array {
		$home = self::normalize_home( $home_url );

		return array(
			'home'   => $home,
			'db'     => $db_name,
			'prefix' => $table_prefix,
			'hash'   => hash( 'sha256', $home . '|' . $db_name . '|' . $table_prefix ),
		);
	}

	/**
	 * Fingerprint of the running installation.
	 *
	 * @return array{home:string,db:string,prefix:string,hash:string}
	 */
	public static function current(): array {
		global $wpdb;

		return self::fingerprint(
			(string) get_option( 'home', '' ),
			defined( 'DB_NAME' ) ? (string) DB_NAME : '',
			is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : ''
		);
	}

	/**
	 * Store the fingerprint on the first run. Later differences are never stored automatically.
	 *
	 * @return void
	 */
	public static function remember(): void {
		if ( null !== get_option( self::OPTION, null ) ) {
			return;
		}
		$current                 = self::current();
		$current['stored_at']    = gmdate( 'Y-m-d H:i:s' );
		$current['confirmed_by'] = 0;
		add_option( self::OPTION, $current, '', true );
	}

	/**
	 * Apply an administrator's answer to the "is this the live shop?" question.
	 *
	 * @param string $decision "resume" (this installation is the live shop) or "keep" (stay paused).
	 * @param int    $user_id  Acting user.
	 * @return bool False for an unknown decision.
	 */
	public static function apply_decision( string $decision, int $user_id ): bool {
		$stored  = get_option( self::OPTION, null );
		$current = self::current();
		$before  = array(
			'home_host_path' => is_array( $stored ) && isset( $stored['home'] ) ? (string) $stored['home'] : '',
			'fingerprint'    => is_array( $stored ) && isset( $stored['hash'] ) ? substr( (string) $stored['hash'], 0, 12 ) : '',
		);
		$after   = array(
			'home_host_path' => $current['home'],
			'fingerprint'    => substr( $current['hash'], 0, 12 ),
		);

		if ( 'resume' === $decision ) {
			$current['stored_at']    = gmdate( 'Y-m-d H:i:s' );
			$current['confirmed_by'] = $user_id;
			update_option( self::OPTION, $current, true );
			delete_option( self::KEEP_OPTION );

			// Renewals that were due while billing was paused must not expire unattempted.
			$paused_since = (int) get_option( self::PAUSED_SINCE_OPTION, 0 );
			if ( $paused_since > 0 ) {
				GapGuard::record( $paused_since, time(), 'billing_paused' );
			}
			delete_option( self::PAUSED_SINCE_OPTION );

			AuditLog::record( 'billing_resumed', 'site', 0, 'An administrator confirmed this installation as the live shop.', $before, $after, $user_id );
			return true;
		}

		if ( 'keep' === $decision ) {
			update_option( self::KEEP_OPTION, $current['hash'], true );
			AuditLog::record( 'billing_pause_kept', 'site', 0, 'An administrator kept billing paused on this copy of the shop.', $before, $after, $user_id );
			return true;
		}

		return false;
	}

	/**
	 * Handle the confirmation forms of the admin notice.
	 *
	 * @return void
	 */
	public static function handle_decision(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		self::apply_decision( $decision, get_current_user_id() );

		$target = wp_get_referer();
		wp_safe_redirect( $target ? $target : admin_url() );
		exit;
	}

	/**
	 * Remember when the pause started (called by the daily run) so that the gap can be bridged on resume.
	 *
	 * @param int $now Unix timestamp.
	 * @return void
	 */
	public static function track_pause( int $now ): void {
		$since = (int) get_option( self::PAUSED_SINCE_OPTION, 0 );
		if ( self::billing_paused() ) {
			if ( $since <= 0 ) {
				update_option( self::PAUSED_SINCE_OPTION, $now, false );
			}
			return;
		}
		if ( $since > 0 ) {
			// Paused by constant, filter or environment and released without the notice.
			GapGuard::record( $since, $now, 'billing_paused' );
			delete_option( self::PAUSED_SINCE_OPTION );
		}
	}

	/**
	 * Explain a pause to administrators and ask which installation this is.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$reason = self::pause_reason();
		if ( '' === $reason || 'schema_downgrade' === $reason ) {
			// The downgrade has its own notice (Schema::render_admin_notice()).
			return;
		}

		if ( 'fingerprint' !== $reason ) {
			if ( ! self::is_plugin_related_screen() ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			if ( 'constant' === $reason ) {
				esc_html_e( 'WP License Captain: renewal charges, expiry and customer billing emails are paused because WPLC_PAUSE_BILLING is set in wp-config.php.', 'wp-license' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: %s: environment type such as staging, development or local. */
						__( 'WP License Captain: renewal charges, expiry and customer billing emails are paused because this site runs as "%s" (WP_ENVIRONMENT_TYPE). Only a production site bills customers.', 'wp-license' ),
						function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ''
					)
				);
			}
			echo '</p></div>';
			return;
		}

		$stored  = get_option( self::OPTION, array() );
		$current = self::current();
		$kept    = hash_equals( (string) get_option( self::KEEP_OPTION, '' ), $current['hash'] );
		if ( $kept && ! self::is_plugin_related_screen() ) {
			return;
		}

		echo '<div class="notice ' . ( $kept ? 'notice-warning' : 'notice-error' ) . '"><p><strong>';
		esc_html_e( 'WP License Captain has paused billing on this site.', 'wp-license' );
		echo '</strong></p><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: address the license data was created on, 2: address of this site. */
				__( 'The license data was created on "%1$s", this site is "%2$s" (address, database or table prefix differ). It looks like a copy of the shop (staging clone or restored backup). Until you decide, no renewal is charged, no license expires and no billing email is sent from this site.', 'wp-license' ),
				is_array( $stored ) && isset( $stored['home'] ) ? (string) $stored['home'] : '',
				$current['home']
			)
		);
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION, '_wpnonce', true, true );
		echo '<p><button type="submit" name="decision" value="resume" class="button button-primary">';
		esc_html_e( 'This is the live shop – resume billing', 'wp-license' );
		echo '</button> ';
		if ( ! $kept ) {
			echo '<button type="submit" name="decision" value="keep" class="button">';
			esc_html_e( 'This is a copy – keep billing paused', 'wp-license' );
			echo '</button>';
		}
		echo '</p></form></div>';
	}

	/**
	 * Whether the current admin screen belongs to the plugin, WooCommerce or the plugin list.
	 *
	 * @return bool
	 */
	private static function is_plugin_related_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? (string) $screen->id : '';

		return '' === $id || 'plugins' === $id || 'dashboard' === $id || false !== strpos( $id, 'wplc' ) || false !== strpos( $id, 'woocommerce' );
	}
}
