<?php
/**
 * Minimum versions of PHP, the database server, WooCommerce, Action Scheduler and the Stripe gateway.
 *
 * @package WPLC
 */

namespace WPLC\Compatibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place for every "which version do we need" decision.
 *
 * The version numbers below were taken from the source of the named releases,
 * not from documentation. See docs/CONCEPT-CHECKOUT-RENEWAL.md for the references.
 */
final class Requirements {

	/**
	 * Oldest supported PHP version (owner decision of 16 Nov 2026, MINVER-1: the newest
	 * release line). Keep in sync with `Requires PHP` and the guard in wp-license.php,
	 * readme.txt, composer.json (`require.php`, `config.platform.php`), the PHPCS
	 * `testVersion` and the PHPStan `phpVersion`; RequirementsTest compares them.
	 */
	const MIN_PHP = '8.5';

	/**
	 * Oldest supported MySQL server (LTS). An older server only gets a warning.
	 */
	const MIN_MYSQL = '9.7';

	/**
	 * Oldest supported MariaDB server (LTS). An older server only gets a warning.
	 */
	const MIN_MARIADB = '12.3';

	/**
	 * TEST-ONLY constant: when the test bootstraps (tests/bootstrap.php,
	 * tests/integration-bootstrap.php, the tests/e2e and tests/browser harnesses) define it
	 * as `true`, the PHP minimum is not enforced, so the suites also run on the older PHP of
	 * a development machine. Never define it on a real site: the code is not tested below
	 * MIN_PHP there.
	 */
	const TEST_PHP_OPT_OUT_CONSTANT = 'WPLC_TESTS_IGNORE_PHP_MINIMUM';

	/**
	 * Version string of the database server, cached per request.
	 *
	 * @var string|null
	 */
	private static $db_server_info = null;

	/**
	 * Whether a test bootstrap switched the PHP minimum off (see TEST_PHP_OPT_OUT_CONSTANT).
	 *
	 * @return bool
	 */
	public static function php_minimum_ignored_for_tests(): bool {
		return defined( self::TEST_PHP_OPT_OUT_CONSTANT ) && true === constant( self::TEST_PHP_OPT_OUT_CONSTANT );
	}

	/**
	 * Whether a PHP version meets MIN_PHP.
	 *
	 * @param string $version PHP version; empty = the running one.
	 * @return bool
	 */
	public static function php_meets_minimum( string $version = '' ): bool {
		return self::version_at_least( '' !== $version ? $version : PHP_VERSION, self::MIN_PHP );
	}

	/**
	 * Whether the plugin may run on this PHP: the minimum is met or a test bootstrap opted out.
	 *
	 * @return bool
	 */
	public static function php_is_supported(): bool {
		return self::php_meets_minimum() || self::php_minimum_ignored_for_tests();
	}

	/**
	 * Version string reported by the database server, e.g. "9.7.0", "12.3.2-MariaDB-ubu2404"
	 * or "5.5.5-10.11.6-MariaDB".
	 *
	 * @return string Empty when unknown.
	 */
	public static function db_server_info(): string {
		if ( null === self::$db_server_info ) {
			global $wpdb;
			$info = '';
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
				$info = (string) $wpdb->db_server_info();
			}
			if ( '' === $info && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
				$info = (string) $wpdb->get_var( 'SELECT VERSION()' );
			}
			self::$db_server_info = $info;
		}
		return self::$db_server_info;
	}

	/**
	 * Server type and version from a version string. MariaDB is recognised by the word
	 * "MariaDB"; the replication prefix "5.5.5-" of MariaDB servers is skipped.
	 *
	 * @param string $info Version string.
	 * @return array{type:string,name:string,version:string,minimum:string}|null Null when no version can be read.
	 */
	public static function parse_db_server( string $info ): ?array {
		$info    = trim( $info );
		$mariadb = false !== stripos( $info, 'mariadb' );
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}
		if ( 1 !== preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', $info, $match ) ) {
			return null;
		}
		return array(
			'type'    => $mariadb ? 'mariadb' : 'mysql',
			'name'    => $mariadb ? 'MariaDB' : 'MySQL',
			'version' => $match[1] . '.' . $match[2] . '.' . ( $match[3] ?? '0' ),
			'minimum' => $mariadb ? self::MIN_MARIADB : self::MIN_MYSQL,
		);
	}

	/**
	 * Whether the connected server is a MariaDB that refuses to change a row changed by another
	 * transaction since this one's snapshot (`innodb_snapshot_isolation`, ON by default since
	 * MariaDB 11.6.2). With it, a read and a later UPDATE or locking read of a row another request
	 * changed in between fails with error 1020 and rolls the whole transaction back; MySQL keeps
	 * the old behaviour.
	 *
	 * @param string|null $info Version string; null = the connected server.
	 * @return bool
	 */
	public static function mariadb_snapshot_isolation_applies( ?string $info = null ): bool {
		$server = self::parse_db_server( null === $info ? self::db_server_info() : $info );

		return null !== $server && 'mariadb' === $server['type'] && version_compare( $server['version'], '11.6.2', '>=' );
	}

	/**
	 * Judge the database server against the minimums. Never blocks anything.
	 *
	 * @param string|null $info Version string; null = the connected server.
	 * @return array{known:bool,ok:bool,name:string,version:string,minimum:string,message:string}
	 */
	public static function database_status( ?string $info = null ): array {
		$server = self::parse_db_server( null === $info ? self::db_server_info() : $info );
		if ( null === $server ) {
			return array(
				'known'   => false,
				'ok'      => true,
				'name'    => '',
				'version' => '',
				'minimum' => '',
				'message' => '',
			);
		}
		$ok = self::version_at_least( $server['version'], $server['minimum'] );
		return array(
			'known'   => true,
			'ok'      => $ok,
			'name'    => $server['name'],
			'version' => $server['version'],
			'minimum' => $server['minimum'],
			'message' => $ok ? '' : sprintf(
				/* translators: 1: minimum MySQL version, 2: minimum MariaDB version, 3: MySQL or MariaDB, 4: version of this database server. */
				__( 'WP License Captain supports MySQL %1$s or later and MariaDB %2$s or later; this site uses %3$s %4$s. Nothing is blocked, but older database servers are not tested. Ask your host for an update.', 'wp-license' ),
				self::MIN_MYSQL,
				self::MIN_MARIADB,
				$server['name'],
				$server['version']
			),
		);
	}

	/**
	 * Register the Site Health test. Also runs without WooCommerce.
	 *
	 * @return void
	 */
	public static function register_site_health(): void {
		add_filter( 'site_status_tests', array( self::class, 'site_status_tests' ) );
	}

	/**
	 * Add the direct test "PHP and database versions" to Site Health.
	 *
	 * @param mixed $tests Registered tests.
	 * @return mixed
	 */
	public static function site_status_tests( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct']['wplc_runtime_versions'] = array(
				'label' => __( 'WP License Captain: PHP and database versions', 'wp-license' ),
				'test'  => array( self::class, 'site_health_test' ),
			);
		}
		return $tests;
	}

	/**
	 * Site Health result: PHP and database server against the minimums. An older database
	 * server is "recommended" (a warning), never "critical".
	 *
	 * @return array<string,mixed>
	 */
	public static function site_health_test(): array {
		$database = self::database_status();
		$php_ok   = self::php_meets_minimum();
		$ok       = $php_ok && $database['ok'];
		$details  = array(
			/* translators: 1: running PHP version, 2: minimum PHP version. */
			sprintf( __( 'PHP %1$s (minimum %2$s)', 'wp-license' ), PHP_VERSION, self::MIN_PHP ),
			$database['known']
				/* translators: 1: MySQL or MariaDB, 2: version of the database server, 3: minimum version. */
				? sprintf( __( '%1$s %2$s (minimum %3$s)', 'wp-license' ), $database['name'], $database['version'], $database['minimum'] )
				/* translators: 1: minimum MySQL version, 2: minimum MariaDB version. */
				: sprintf( __( 'Database server version unknown (minimum MySQL %1$s or MariaDB %2$s)', 'wp-license' ), self::MIN_MYSQL, self::MIN_MARIADB ),
		);
		$description = '<p>' . esc_html( implode( '; ', $details ) ) . '</p>';
		if ( ! $database['ok'] ) {
			$description .= '<p>' . esc_html( $database['message'] ) . '</p>';
		}
		if ( ! $php_ok ) {
			$description .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: minimum PHP version. */
					__( 'WP License Captain requires PHP %s or later.', 'wp-license' ),
					self::MIN_PHP
				)
			) . '</p>';
		}
		return array(
			'label'       => $ok
				? __( 'PHP and the database server meet the requirements of WP License Captain', 'wp-license' )
				: __( 'PHP or the database server is older than WP License Captain supports', 'wp-license' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'WP License Captain', 'wp-license' ),
				'color' => $ok ? 'blue' : 'orange',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'wplc_runtime_versions',
		);
	}

	/**
	 * Oldest supported WordPress (owner decision of 17 Nov 2026, uniform for all of the
	 * owner's plugins, add-ons and connectors). Keep in sync with `Requires at least` in
	 * wp-license.php and readme.txt and with the CI matrix; RequirementsTest compares them.
	 * WordPress itself refuses to activate the plugin below it; an already active copy shows
	 * an admin notice (render_woocommerce_version_notice()).
	 */
	const MIN_WP = '7.0';

	/**
	 * Oldest supported WooCommerce (owner decision of 17 Nov 2026, uniform for all of the
	 * owner's plugins, add-ons and connectors; `WC requires at least` in wp-license.php). Everything
	 * the plugin calls is older: the Additional Checkout Fields API (stable since 8.9.0),
	 * FeaturesUtil 7.1, `cart_checkout_blocks` and HPOS declarations, Action Scheduler 3.6
	 * with WooCommerce 7.9. The minimum is 11.2.2 (owner decision of 17 Nov 2026), not 11.2.0:
	 * 11.2.0 carried order and payment data of an earlier Store API checkout into a later one in
	 * the same PHP process.
	 */
	const MIN_WC = '11.2.2';

	/**
	 * Conditional visibility rules (`hidden` as JSON schema) for additional checkout fields.
	 */
	const MIN_WC_CONDITIONAL_FIELDS = '9.9.0';

	/**
	 * Oldest WooCommerce Stripe Gateway the adapter was verified against
	 * (introduces `wc_stripe_force_save_payment_method` with the order ID).
	 */
	const STRIPE_MIN_VERSION = '9.6.0';

	/**
	 * Newest WooCommerce Stripe Gateway the adapter was verified against.
	 */
	const STRIPE_VERIFIED_UP_TO = '11.2.0';

	/**
	 * Internal Stripe gateway symbols the adapter calls. Method `null` checks the class only.
	 *
	 * @var array<int,array{0:string,1:string|null}>
	 */
	const STRIPE_REQUIRED_SYMBOLS = array(
		array( 'WC_Stripe_API', 'request' ),
		array( 'WC_Stripe_API', 'retrieve' ),
		array( 'WC_Stripe_Helper', 'get_stripe_amount' ),
		array( 'WC_Stripe_Helper', 'get_stripe_settings' ),
		array( 'WC_Stripe_Helper', 'should_force_save_payment_method' ),
		array( 'WC_Payment_Tokens', 'get' ),
	);

	/**
	 * Register the admin notice for an outdated WooCommerce.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render_woocommerce_version_notice' ) );
	}

	/**
	 * Compare two version strings. Pre-release and build suffixes of the running
	 * version are ignored, so "9.9.0-rc.1" satisfies a minimum of "9.9.0".
	 *
	 * @param string $current Running version.
	 * @param string $minimum Required version.
	 * @return bool
	 */
	public static function version_at_least( string $current, string $minimum ): bool {
		if ( ! preg_match( '/^\d+(?:\.\d+)*/', trim( $current ), $match ) ) {
			return false;
		}
		return version_compare( $match[0], $minimum, '>=' );
	}

	/**
	 * Running WooCommerce version, or an empty string when unknown.
	 *
	 * @return string
	 */
	public static function woocommerce_version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	/**
	 * Whether the running WooCommerce meets the declared minimum.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_supported(): bool {
		return self::version_at_least( self::woocommerce_version(), self::MIN_WC );
	}

	/**
	 * Running WordPress version, or an empty string when unknown.
	 *
	 * @return string
	 */
	public static function wordpress_version(): string {
		global $wp_version;
		return isset( $wp_version ) && is_string( $wp_version ) ? $wp_version : '';
	}

	/**
	 * Whether the running WordPress meets the declared minimum. An unknown version counts
	 * as supported: WordPress always sets it, so "unknown" only happens outside WordPress.
	 *
	 * @return bool
	 */
	public static function wordpress_is_supported(): bool {
		$version = self::wordpress_version();
		return '' === $version || self::version_at_least( $version, self::MIN_WP );
	}

	/**
	 * Whether the block checkout can carry the auto-renewal opt-in.
	 *
	 * @return bool
	 */
	public static function supports_additional_checkout_fields(): bool {
		return self::woocommerce_is_supported() && function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	/**
	 * Whether additional checkout fields can be hidden by a rule.
	 *
	 * @return bool
	 */
	public static function supports_conditional_checkout_fields(): bool {
		return self::version_at_least( self::woocommerce_version(), self::MIN_WC_CONDITIONAL_FIELDS );
	}

	/**
	 * Tell administrators that WordPress or WooCommerce is older than the supported minimum.
	 *
	 * @return void
	 */
	public static function render_woocommerce_version_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! self::wordpress_is_supported() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required WordPress version, 2: installed WordPress version. */
					__( 'WP License Captain requires WordPress %1$s or later; version %2$s is installed. The plugin is not tested on this version. Please update WordPress.', 'wp-license' ),
					self::MIN_WP,
					self::wordpress_version()
				)
			);
			echo '</p></div>';
		}
		if ( self::woocommerce_is_supported() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
				__( 'WP License Captain requires WooCommerce %1$s or later; version %2$s is installed. Licenses keep working, but the automatic renewal option is not offered in the block checkout until WooCommerce is updated.', 'wp-license' ),
				self::MIN_WC,
				'' !== self::woocommerce_version() ? self::woocommerce_version() : __( 'unknown', 'wp-license' )
			)
		);
		echo '</p></div>';
	}


	/**
	 * Schedule a single action at most once per hook, args and group.
	 *
	 * @param int               $timestamp UTC timestamp.
	 * @param string            $hook      Action hook.
	 * @param array<int,mixed>  $args      Hook arguments.
	 * @param string            $group     Action Scheduler group.
	 * @return int Action ID, 0 when nothing was scheduled (already pending, or no Action Scheduler).
	 */
	public static function schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = 'wplc' ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}
		// Action Scheduler's own unique flag also counts the RUNNING action, so a job
		// could never reschedule itself (retries, lock contention). Only a PENDING
		// duplicate is skipped here; duplicates are harmless because every job takes
		// a lock and is idempotent.
		if ( self::has_pending_action( $hook, $args, $group ) ) {
			return 0;
		}
		return (int) as_schedule_single_action( $timestamp, $hook, $args, $group );
	}

	/**
	 * Enqueue an async action at most once per hook, args and group.
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int,mixed>  $args  Hook arguments.
	 * @param string            $group Action Scheduler group.
	 * @return int Action ID, 0 when nothing was enqueued.
	 */
	public static function enqueue_async_action( string $hook, array $args = array(), string $group = 'wplc' ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		// Action Scheduler's own unique flag also counts the RUNNING action, so a job
		// could never reschedule itself (retries, lock contention). Only a PENDING
		// duplicate is skipped here; duplicates are harmless because every job takes
		// a lock and is idempotent.
		if ( self::has_pending_action( $hook, $args, $group ) ) {
			return 0;
		}
		return (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Whether a pending action with the same hook, args and group exists.
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int,mixed>  $args  Hook arguments.
	 * @param string            $group Action Scheduler group.
	 * @return bool
	 */
	private static function has_pending_action( string $hook, array $args, string $group ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		return ! empty( $pending );
	}

	/**
	 * Judge the Stripe gateway by its version and the symbols that are missing.
	 *
	 * @param string             $version Value of WC_STRIPE_VERSION, '' when the gateway is not loaded.
	 * @param array<int,string>  $missing Missing classes or methods ("Class::method").
	 * @return array{ok:bool,reason:string}
	 */
	public static function evaluate_stripe( string $version, array $missing ): array {
		if ( '' === $version ) {
			return array(
				'ok'     => false,
				'reason' => __( 'The WooCommerce Stripe Gateway is not active.', 'wp-license' ),
			);
		}
		if ( ! self::version_at_least( $version, self::STRIPE_MIN_VERSION ) ) {
			return array(
				'ok'     => false,
				/* translators: 1: installed Stripe gateway version, 2: required version. */
				'reason' => sprintf( __( 'WooCommerce Stripe Gateway %1$s is too old for automatic renewals; version %2$s or later is required.', 'wp-license' ), $version, self::STRIPE_MIN_VERSION ),
			);
		}
		if ( $missing ) {
			return array(
				'ok'     => false,
				/* translators: 1: installed Stripe gateway version, 2: list of missing PHP classes or methods. */
				'reason' => sprintf( __( 'WooCommerce Stripe Gateway %1$s no longer provides the functions the renewal adapter needs (%2$s). Automatic renewals are switched off.', 'wp-license' ), $version, implode( ', ', $missing ) ),
			);
		}
		if ( version_compare( (string) preg_replace( '/[^0-9.].*$/', '', $version ), self::STRIPE_VERIFIED_UP_TO, '>' ) ) {
			return array(
				'ok'     => true,
				/* translators: 1: installed Stripe gateway version, 2: newest verified version. */
				'reason' => sprintf( __( 'WooCommerce Stripe Gateway %1$s is newer than the last verified version %2$s. Automatic renewals stay enabled; test a renewal after gateway updates.', 'wp-license' ), $version, self::STRIPE_VERIFIED_UP_TO ),
			);
		}
		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Whether the Stripe renewal adapter can be used on this site, and why not.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	public static function stripe_adapter_status(): array {
		$missing = array();
		foreach ( self::STRIPE_REQUIRED_SYMBOLS as $symbol ) {
			if ( ! class_exists( $symbol[0] ) ) {
				$missing[] = $symbol[0];
			} elseif ( ! method_exists( $symbol[0], $symbol[1] ) ) {
				$missing[] = $symbol[0] . '::' . $symbol[1];
			}
		}

		$status = self::evaluate_stripe( defined( 'WC_STRIPE_VERSION' ) ? (string) WC_STRIPE_VERSION : '', $missing );
		if ( $status['ok'] ) {
			$status = self::stripe_configuration_status( $status );
		}

		/**
		 * Filters whether the Stripe renewal adapter is usable.
		 *
		 * @since 0.1.0
		 *
		 * @param array{ok:bool,reason:string} $status Result of the built-in checks.
		 */
		$filtered = apply_filters( 'wplc_stripe_adapter_status', $status );
		if ( ! is_array( $filtered ) || ! isset( $filtered['ok'] ) ) {
			return $status;
		}
		return array(
			'ok'     => (bool) $filtered['ok'],
			'reason' => isset( $filtered['reason'] ) ? (string) $filtered['reason'] : '',
		);
	}

	/**
	 * Check the gateway settings: enabled, and allowed to save cards.
	 *
	 * Without "saved cards" the gateway ignores `wc_stripe_force_save_payment_method`,
	 * so there would never be a payment method to renew with.
	 *
	 * @param array{ok:bool,reason:string} $status Status so far.
	 * @return array{ok:bool,reason:string}
	 */
	private static function stripe_configuration_status( array $status ): array {
		try {
			$settings = \WC_Stripe_Helper::get_stripe_settings();
		} catch ( \Throwable $exception ) {
			$settings = null;
		}
		if ( ! is_array( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return array(
				'ok'     => false,
				'reason' => __( 'The Stripe payment method is not enabled in WooCommerce.', 'wp-license' ),
			);
		}
		// The gateway's own default for a missing value is "yes".
		if ( 'yes' !== ( $settings['saved_cards'] ?? 'yes' ) ) {
			return array(
				'ok'     => false,
				'reason' => __( 'Saved payment methods are switched off in the Stripe settings, so there is no card to renew with.', 'wp-license' ),
			);
		}
		return $status;
	}
}
