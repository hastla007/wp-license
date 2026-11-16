<?php
/**
 * Look at one license, or issue one by hand, from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc license`: views for support (A-74) and issuing a license by hand (PK-02).
 */
final class LicenseCommand {

	/**
	 * Show a license with its activations, renewals and latest audit entries.
	 *
	 * The license key itself is never printed.
	 *
	 * ## OPTIONS
	 *
	 * <license>
	 * : License ID, complete license key, or license UUID.
	 *
	 * [--format=<format>]
	 * : "text" (default) or "json".
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc license show 123
	 *     wp wplc license show WPLC-... --format=json
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function show( array $args, array $assoc_args ): void {
		$details = self::details( (string) ( $args[0] ?? '' ) );
		if ( null === $details ) {
			\WP_CLI::error( __( 'License not found.', 'wp-license' ) );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		foreach ( $details['license'] as $name => $value ) {
			\WP_CLI::line( sprintf( '%-22s %s', $name . ':', is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) );
		}
		foreach ( array( 'activations', 'renewals', 'audit' ) as $section ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( strtoupper( $section ) . ' (' . count( $details[ $section ] ) . ')' );
			foreach ( $details[ $section ] as $row ) {
				\WP_CLI::line( '  ' . implode( ' | ', array_map( 'strval', $row ) ) );
			}
		}
	}

	/**
	 * Issue a license by hand, without an order (PK-02).
	 *
	 * The same path as "Add license manually" in the admin: no order, nothing
	 * is charged, no automatic renewal; audited with the reason. Works for
	 * products that are not for sale, e.g. a project product kept private in
	 * WooCommerce (docs/ENTITLEMENTS.md, "Creating a project product"). The
	 * license key is not printed: the customer gets it by email (unless
	 * --skip-email) and finds it in their account.
	 *
	 * ## OPTIONS
	 *
	 * [--product-id=<id>]
	 * : WooCommerce product or variation ID with an active plan.
	 *
	 * [--plan=<plan-key>]
	 * : With --product-id: the plan key, when the product has more than one active plan.
	 *
	 * [--plan-id=<id>]
	 * : ID of an active catalog plan, instead of --product-id.
	 *
	 * --customer=<user>
	 * : User ID or email address of an existing user account.
	 *
	 * --expires=<date>
	 * : Last day of the term, YYYY-MM-DD. The license runs until the end of that day in the site timezone.
	 *
	 * [--reason=<text>]
	 * : Reason for the audit log.
	 *
	 * [--skip-email]
	 * : Do not send the license email with the key.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc license issue --product-id=812 --customer=client@example.com --expires=2027-12-31 --reason="Project contract"
	 *     wp wplc license issue --plan-id=14 --customer=42 --expires=2027-06-30 --yes
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function issue( array $args, array $assoc_args ): void {
		$request = self::issue_request( $assoc_args );
		if ( is_wp_error( $request ) ) {
			\WP_CLI::error( $request->get_error_message() );
		}
		$user = get_userdata( $request['customer_id'] );
		\WP_CLI::confirm(
			sprintf(
				/* translators: 1: product key, 2: plan key, 3: customer email address, 4: end date (YYYY-MM-DD). */
				__( 'Issue a license for %1$s (plan %2$s) to %3$s, valid until %4$s?', 'wp-license' ),
				(string) $request['plan']['product_key'],
				(string) $request['plan']['plan_key'],
				$user ? (string) $user->user_email : (string) $request['customer_id'],
				(string) $assoc_args['expires']
			),
			$assoc_args
		);

		$license_id = self::run_issue( $request, get_current_user_id() );
		if ( is_wp_error( $license_id ) ) {
			\WP_CLI::error( $license_id->get_error_message() );
		}
		$details = self::details( (string) $license_id );
		\WP_CLI::success(
			sprintf(
				/* translators: 1: license ID, 2: start of the license key (reference, not the key). */
				__( 'License %1$d issued (reference %2$s).', 'wp-license' ),
				$license_id,
				null === $details ? '' : (string) $details['license']['key_prefix']
			)
		);
	}

	/**
	 * Check the options of `wp wplc license issue`.
	 *
	 * @param array<string,mixed> $assoc_args Options.
	 * @return array{customer_id:int,plan:array<string,mixed>,paid_until:string,reason:string,send_email:bool}|\WP_Error
	 */
	public static function issue_request( array $assoc_args ) {
		global $wpdb;

		$tables      = Schema::table_names();
		$customer_id = \WPLC\Admin\LicenseAdmin::resolve_customer( (string) ( $assoc_args['customer'] ?? '' ) );
		if ( ! $customer_id ) {
			return new \WP_Error( 'unknown_customer', __( 'No user account matches --customer. Give a user ID or the email address of an existing account.', 'wp-license' ) );
		}

		$plan_id    = absint( $assoc_args['plan-id'] ?? 0 );
		$product_id = absint( $assoc_args['product-id'] ?? 0 );
		if ( ( $plan_id > 0 ) === ( $product_id > 0 ) ) {
			return new \WP_Error( 'plan_missing', __( 'Give either --product-id or --plan-id.', 'wp-license' ) );
		}
		if ( $plan_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$plans = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['plans']} WHERE id = %d AND active = 1", $plan_id ), ARRAY_A );
		} else {
			$plans = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
					"SELECT * FROM {$tables['plans']} WHERE active = 1 AND ( woocommerce_variation_id = %d OR ( woocommerce_product_id = %d AND woocommerce_variation_id = 0 ) ) ORDER BY id ASC",
					$product_id,
					$product_id
				),
				ARRAY_A
			);
			$plan_key = sanitize_key( (string) ( $assoc_args['plan'] ?? '' ) );
			if ( '' !== $plan_key && is_array( $plans ) ) {
				$plans = array_values(
					array_filter(
						$plans,
						static function ( array $row ) use ( $plan_key ): bool {
							return (string) $row['plan_key'] === $plan_key;
						}
					)
				);
			}
		}
		if ( ! is_array( $plans ) || ! $plans ) {
			return new \WP_Error( 'unknown_plan', __( 'No active plan matches. Mark the WooCommerce product as a license product first.', 'wp-license' ) );
		}
		if ( count( $plans ) > 1 ) {
			return new \WP_Error(
				'ambiguous_plan',
				sprintf(
					/* translators: %s: comma-separated plan keys. */
					__( 'The product has several active plans. Choose one with --plan: %s', 'wp-license' ),
					implode( ', ', array_column( $plans, 'plan_key' ) )
				)
			);
		}

		$date       = (string) ( $assoc_args['expires'] ?? '' );
		$paid_until = preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ? \WPLC\Admin\Format::local_date_to_utc_end_of_day( $date, wp_timezone() ) : '';
		if ( '' === $paid_until || $paid_until <= gmdate( 'Y-m-d H:i:s' ) ) {
			return new \WP_Error( 'invalid_date', __( 'Give --expires as a date in the future, YYYY-MM-DD.', 'wp-license' ) );
		}
		$reason = trim( sanitize_text_field( (string) ( $assoc_args['reason'] ?? '' ) ) );

		return array(
			'customer_id' => $customer_id,
			'plan'        => $plans[0],
			'paid_until'  => $paid_until,
			'reason'      => '' !== $reason ? $reason : __( 'Issued with WP-CLI', 'wp-license' ),
			'send_email'  => empty( $assoc_args['skip-email'] ),
		);
	}

	/**
	 * Issue the license of a checked request.
	 *
	 * @param array{customer_id:int,plan:array<string,mixed>,paid_until:string,reason:string,send_email:bool} $request Result of issue_request().
	 * @param int                                                                                              $actor_user_id User who issues it, zero for the command line.
	 * @return int|\WP_Error License ID.
	 */
	public static function run_issue( array $request, int $actor_user_id ) {
		$mail_hook = array( 'WPLC\\Notifications\\Notifications', 'on_license_issued' );
		$mail_prio = $request['send_email'] ? false : has_action( 'wplc/license_issued', $mail_hook );
		if ( false !== $mail_prio ) {
			remove_action( 'wplc/license_issued', $mail_hook, (int) $mail_prio );
		}
		try {
			$license_id = \WPLC\License\LicenseIssuer::issue_manual( $request['customer_id'], $request['plan'], $request['paid_until'], $actor_user_id, $request['reason'] );
		} finally {
			if ( false !== $mail_prio ) {
				add_action( 'wplc/license_issued', $mail_hook, (int) $mail_prio, 3 );
			}
		}

		return $license_id > 0 ? $license_id : new \WP_Error( 'issue_failed', __( 'The license could not be issued.', 'wp-license' ) );
	}

	/**
	 * Collect what support needs to know about one license.
	 *
	 * @param string $reference License ID, license key, or license UUID.
	 * @return array{license:array<string,mixed>,activations:array<int,array<string,mixed>>,renewals:array<int,array<string,mixed>>,audit:array<int,array<string,mixed>>}|null
	 */
	public static function details( string $reference ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return null;
		}
		if ( ctype_digit( $reference ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $reference ), ARRAY_A );
		} elseif ( wp_is_uuid( strtolower( $reference ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE license_uuid = %s LIMIT 1", strtolower( $reference ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE license_key_hash = %s LIMIT 1", LicenseKey::hash( $reference ) ), ARRAY_A );
		}
		if ( ! is_array( $license ) ) {
			return null;
		}

		$license_id = (int) $license['id'];
		$activations = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id,status,environment,site_url,staging_parent_id,product_version,wp_version,php_version,activated_at,last_seen_at,deactivated_at FROM {$tables['activations']} WHERE license_id = %d ORDER BY id ASC",
				$license_id
			),
			ARRAY_A
		);
		$renewals = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id,status,period_start,period_end,renewal_order_id,attempt_count,retry_at,grace_ends_at,updated_at FROM {$tables['renewals']} WHERE license_id = %d ORDER BY id DESC LIMIT 10",
				$license_id
			),
			ARRAY_A
		);
		$audit = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT created_at,action,actor_user_id,reason FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 20",
				'license',
				$license_id
			),
			ARRAY_A
		);

		return array(
			'license' => array(
				'id' => $license_id,
				'key_prefix' => (string) $license['key_prefix'],
				'key_verifiable' => LicenseKey::matches_hash( (string) $license['license_uuid'], (string) $license['license_key_secret_id'], (string) $license['license_key_hash'] ),
				'customer_id' => (int) $license['customer_id'],
				'product_key' => (string) $license['product_key'],
				'plan_key' => (string) $license['plan_key'],
				'status' => (string) $license['status'],
				'status_reason' => (string) $license['status_reason'],
				'has_access' => LicenseLifecycle::has_access( $license ),
				'paid_until' => (string) $license['paid_until'],
				'renewal_enabled' => ! empty( $license['renewal_enabled'] ),
				'cancel_at_period_end' => ! empty( $license['cancel_at_period_end'] ),
				'source_order_id' => (int) $license['source_order_id'],
				'created_at' => (string) $license['created_at'],
			),
			'activations' => is_array( $activations ) ? $activations : array(),
			'renewals' => is_array( $renewals ) ? $renewals : array(),
			'audit' => is_array( $audit ) ? $audit : array(),
		);
	}
}
