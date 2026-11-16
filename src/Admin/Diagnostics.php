<?php
/**
 * Read-only support diagnostics for WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Api\V1\ClientIp;
use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Infrastructure\Database\SiteCleanupHelper;
use WPLC\License\LicenseLifecycle;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStorageCheck;
use WPLC\License\LicenseKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays environment facts without exposing license or payment secrets. */
final class Diagnostics {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-diagnostics';

	/**
	 * Option with the heartbeat of the background jobs: job name => UTC timestamp.
	 */
	public const LAST_RUN_OPTION = 'wplc_last_run';

	/**
	 * Option with rejected Stripe webhook requests per UTC hour (A-72): 'YmdH' => minutes.
	 */
	public const WEBHOOK_REJECTED_OPTION = 'wplc_webhook_rejected';

	/**
	 * Heartbeat names written by this class.
	 */
	public const JOB_DAILY_STARTED = 'daily_lifecycle_started';
	public const JOB_DAILY         = 'daily_lifecycle';
	public const JOB_FIRST_SEEN    = 'first_seen';

	/**
	 * The daily run is reported as missing after this many seconds (26 hours).
	 */
	public const DAILY_RUN_MAX_AGE = 93600;

	/**
	 * A renewal that stays in "processing" longer than this is reported as stuck.
	 */
	public const STUCK_PROCESSING_SECONDS = 3600;

	/**
	 * Number of recent license keys checked against the configured secret.
	 */
	public const KEY_SAMPLE_SIZE = 50;

	/**
	 * Events of other packages that are passed on as health alerts: hook => metric key.
	 *
	 * @var array<string,string>
	 */
	private const EVENT_ALERTS = array(
		'wplc/renewal_blocked'                  => 'renewal_blocked',
		'wplc/renewal_needs_review'             => 'renewal_needs_review',
		'wplc/license_needs_review'             => 'license_needs_review',
		'wplc/renewal_lock_unavailable'         => 'renewal_lock_unavailable',
		'wplc/response_signing_key_unavailable' => 'response_signing_key_unavailable',
		'wplc/price_change_not_applied'         => 'price_change_not_applied',
	);

	/** Register the support page. */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 30 );
		add_action( 'admin_notices', array( self::class, 'render_daily_run_notice' ) );
		add_action( 'admin_post_wplc_storage_recheck', array( self::class, 'handle_storage_recheck' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		// The heartbeat brackets of the daily chain (mark_daily_started/_finished) are written by
		// Maintenance\DailyRunner, which also isolates the jobs and writes one heartbeat per job.
		// The alarm runs after the work it reports on.
		add_action( 'wplc_daily_lifecycle', array( self::class, 'emit_alerts' ), PHP_INT_MAX - 1 );
		// Events that need a human are passed on to the monitoring hook right away.
		foreach ( array_keys( self::EVENT_ALERTS ) as $hook ) {
			add_action( $hook, array( self::class, 'forward_event_alert' ), 99, 0 );
		}
	}

	/**
	 * Load the license screen stylesheet on the diagnostics page, so its tables scroll inside
	 * themselves on narrow screens (A-126).
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
	}

	/**
	 * Pass an event of another package on as `wplc/health_alert` (metric, 1, 1).
	 *
	 * A receiver that throws must not break the renewal or request that raised the event.
	 *
	 * @return void
	 */
	public static function forward_event_alert(): void {
		$hook = (string) current_filter();
		if ( ! isset( self::EVENT_ALERTS[ $hook ] ) ) {
			return;
		}
		try {
			/** This action is documented in src/Admin/Diagnostics.php */
			do_action( 'wplc/health_alert', self::EVENT_ALERTS[ $hook ], 1, 1 );
		} catch ( \Throwable $error ) {
			global $wp_current_filter;
			if ( is_array( $wp_current_filter ) && 'wplc/health_alert' === end( $wp_current_filter ) ) {
				array_pop( $wp_current_filter );
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'A wplc/health_alert receiver failed: ' . $error->getMessage(), array( 'source' => 'wp-license' ) );
			}
		}
	}

	/**
	 * Record that a background job ran now.
	 *
	 * Other packages call this at the end of their job, e.g. Diagnostics::mark_run( 'renewals' ).
	 * The value is shown on the diagnostics page as "last run".
	 *
	 * @param string $job Job name: lowercase letters, digits and underscores.
	 * @return void
	 */
	public static function mark_run( string $job ): void {
		$job = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $job ) ), 0, 64 );
		if ( '' === $job ) {
			return;
		}
		$runs         = self::last_runs();
		$runs[ $job ] = time();
		if ( count( $runs ) > 60 ) {
			asort( $runs );
			$runs = array_slice( $runs, -60, null, true );
		}
		// Autoloaded (P-52): the admin notice reads it on every shop screen, and it holds
		// at most 60 small entries. An option stored as not autoloaded by an older
		// version switches over on its next write.
		update_option( self::LAST_RUN_OPTION, $runs, true );
	}

	/**
	 * Count a Stripe webhook request with an invalid signature (A-72).
	 *
	 * The caller throttles to one call a minute (the request is unauthenticated,
	 * so every call must not cost a database write); the count is therefore the
	 * number of minutes with at least one rejected request. Kept per UTC hour for 24 hours.
	 *
	 * @param int|null $now Current Unix time.
	 * @return void
	 */
	public static function count_rejected_webhook( ?int $now = null ): void {
		$now     = null === $now ? time() : $now;
		$buckets = self::rejected_webhook_buckets( $now );
		$hour    = gmdate( 'YmdH', $now );
		$buckets[ $hour ] = ( $buckets[ $hour ] ?? 0 ) + 1;
		update_option( self::WEBHOOK_REJECTED_OPTION, $buckets, false );
	}

	/**
	 * Minutes with rejected Stripe webhook requests in the last 24 hours (A-72).
	 *
	 * @param int|null $now Current Unix time.
	 * @return int
	 */
	public static function rejected_webhook_minutes( ?int $now = null ): int {
		return (int) array_sum( self::rejected_webhook_buckets( null === $now ? time() : $now ) );
	}

	/**
	 * Stored hourly buckets of the last 24 hours.
	 *
	 * @param int $now Current Unix time.
	 * @return array<string,int>
	 */
	private static function rejected_webhook_buckets( int $now ): array {
		$stored  = get_option( self::WEBHOOK_REJECTED_OPTION, array() );
		$oldest  = gmdate( 'YmdH', $now - 23 * HOUR_IN_SECONDS );
		$buckets = array();
		foreach ( is_array( $stored ) ? $stored : array() as $hour => $count ) {
			$hour = (string) $hour;
			if ( 1 === preg_match( '/^\d{10}$/', $hour ) && $hour >= $oldest && is_numeric( $count ) && (int) $count > 0 ) {
				$buckets[ $hour ] = (int) $count;
			}
		}

		return $buckets;
	}

	/**
	 * Titles of the license emails that are switched off in the WooCommerce email settings (A-132).
	 *
	 * @return string[]
	 */
	public static function disabled_license_emails(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$disabled = array();
		try {
			foreach ( (array) WC()->mailer()->get_emails() as $registry_key => $email ) {
				if ( 0 === strpos( (string) $registry_key, 'WPLC_Email_' ) && ! $email->is_enabled() ) {
					$disabled[] = wp_strip_all_tags( (string) $email->get_title() );
				}
			}
		} catch ( \Throwable $error ) {
			return array();
		}

		return $disabled;
	}

	/**
	 * All recorded heartbeats.
	 *
	 * @return array<string,int> Job name => UTC timestamp.
	 */
	public static function last_runs(): array {
		$stored = get_option( self::LAST_RUN_OPTION, array() );
		$runs   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $job => $timestamp ) {
			if ( is_string( $job ) && is_numeric( $timestamp ) && (int) $timestamp > 0 ) {
				$runs[ $job ] = (int) $timestamp;
			}
		}

		return $runs;
	}

	/**
	 * Timestamp of the last run of a job.
	 *
	 * @param string $job Job name.
	 * @return int UTC timestamp, zero when the job never ran.
	 */
	public static function last_run( string $job ): int {
		$runs = self::last_runs();

		return isset( $runs[ $job ] ) ? $runs[ $job ] : 0;
	}

	/** Heartbeat at the start of the daily run. */
	public static function mark_daily_started(): void {
		self::mark_run( self::JOB_DAILY_STARTED );
	}

	/** Heartbeat after the last task of the daily run. */
	public static function mark_daily_finished(): void {
		self::mark_run( self::JOB_DAILY );
	}

	/**
	 * State of the daily run for the admin notice and the diagnostics page.
	 *
	 * @param int|null $now Current timestamp; defaults to time().
	 * @return array{state:string,last:int,started:int} state: ok, unknown (too early to tell), missing or incomplete.
	 */
	public static function daily_run_state( ?int $now = null ): array {
		$now      = null === $now ? time() : $now;
		$runs     = self::last_runs();
		$finished = isset( $runs[ self::JOB_DAILY ] ) ? $runs[ self::JOB_DAILY ] : 0;
		$started  = isset( $runs[ self::JOB_DAILY_STARTED ] ) ? $runs[ self::JOB_DAILY_STARTED ] : 0;
		$first    = isset( $runs[ self::JOB_FIRST_SEEN ] ) ? $runs[ self::JOB_FIRST_SEEN ] : 0;
		$state    = 'ok';

		if ( $finished > 0 && $now - $finished <= self::DAILY_RUN_MAX_AGE ) {
			$state = 'ok';
		} elseif ( $started > $finished ) {
			// Started without reaching the last task: still running, broken off, or long ago.
			$age   = $now - $started;
			$state = $age <= 600 ? 'unknown' : ( $age <= self::DAILY_RUN_MAX_AGE ? 'incomplete' : 'missing' );
		} elseif ( $finished > 0 ) {
			$state = 'missing';
		} else {
			// Never ran: only a problem once the plugin has been watched for longer than a day.
			$state = $first > 0 && $now - $first > self::DAILY_RUN_MAX_AGE ? 'missing' : 'unknown';
		}

		return array(
			'state'   => $state,
			'last'    => $finished,
			'started' => $started,
		);
	}

	/**
	 * Warn when the daily run (expiry, retries, reminders, cleanup) stopped.
	 *
	 * @return void
	 */
	public static function render_daily_run_notice(): void {
		if ( ! Capabilities::can( 'view_diagnostics' ) || ! self::is_plugin_or_shop_screen() ) {
			return;
		}
		if ( ! self::last_run( self::JOB_FIRST_SEEN ) ) {
			self::mark_run( self::JOB_FIRST_SEEN );
		}

		$run = self::daily_run_state();
		if ( 'missing' !== $run['state'] && 'incomplete' !== $run['state'] ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'WP License Captain: the daily background run is not working.', 'wp-license' );
		echo '</strong> ';
		if ( 'incomplete' === $run['state'] ) {
			echo esc_html__( 'The last run started but did not finish; one of its tasks failed.', 'wp-license' );
		} elseif ( $run['last'] > 0 ) {
			/* translators: %s: date and time of the last run. */
			echo esc_html( sprintf( __( 'It last finished on %s, more than 26 hours ago.', 'wp-license' ), Format::date( gmdate( 'Y-m-d H:i:s', $run['last'] ), true ) ) );
		} else {
			echo esc_html__( 'It has never run on this site.', 'wp-license' );
		}
		echo ' ' . esc_html__( 'Until it runs again, licenses do not expire, failed payments are not retried and reminders are not sent. Check WP-Cron or the server cron job and the PHP error log.', 'wp-license' );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Open diagnostics', 'wp-license' ) . '</a></p></div>';
	}

	/**
	 * Whether the current screen belongs to the plugin, WooCommerce or the plugin list.
	 *
	 * @return bool
	 */
	public static function is_plugin_or_shop_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || empty( $screen->id ) ) {
			return false;
		}

		$id        = (string) $screen->id;
		$post_type = (string) $screen->post_type;
		if ( in_array( $id, array( 'plugins', 'plugins-network', 'dashboard' ), true ) || in_array( $post_type, array( 'product', 'shop_order', 'shop_coupon' ), true ) ) {
			return true;
		}
		foreach ( array( 'woocommerce', 'wc-', 'wplc', 'wp-license' ) as $needle ) {
			if ( false !== strpos( $id, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return aggregate queue metrics without exposing customer or payment data.
	 *
	 * @return array<string,int>
	 */
	public static function metrics(): array {
		global $wpdb;
		$tables = Schema::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$stuck  = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_PROCESSING_SECONDS );
		$review = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN (%s,%s)", $tables['renewals'], \WPLC\Billing\RenewalProcessor::STATUS_REVIEW, \WPLC\Billing\RenewalProcessor::LEGACY_STATUS_REVIEW ) );

		return array(
			'pending_webhooks'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = %s AND outcome = %s", $tables['event_inbox'], 'stripe', 'pending_reconciliation' ) ),
			'manual_review_webhooks'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = %s AND outcome = %s AND processed_at >= %s", $tables['event_inbox'], 'stripe', 'manual_review', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ),
			'overdue_renewals'           => self::count_stuck_renewals( $tables['renewals'], time() ),
			'review_required_renewals'   => $review,
			// Former name of the same metric on one development line; kept for existing alert filters.
			'payment_review_renewals'    => $review,
			'expired_download_grants'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE consumed_at IS NULL AND expires_at <= %s", $tables['download_tokens'], $now ) ),
			'stuck_processing_renewals'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = %s AND updated_at <= %s", $tables['renewals'], 'processing', $stuck ) ),
			'renewing_without_renewal'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i l WHERE l.renewal_enabled = 1 AND l.cancel_at_period_end = 0 AND l.status IN ('active','past_due') AND NOT EXISTS ( SELECT 1 FROM %i r WHERE r.license_id = l.id AND r.status IN ('scheduled','processing','failed','manual_action_required','reconciling','review_required','payment_review') ) AND l.updated_at <= %s",
					$tables['licenses'],
					$tables['renewals'],
					gmdate( 'Y-m-d H:i:s', time() - 600 )
				)
			),
			'paid_without_extension'     => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i r INNER JOIN %i l ON l.id = r.license_id WHERE r.status = %s AND l.status <> %s AND ( l.paid_until IS NULL OR l.paid_until < r.period_end ) AND r.id = ( SELECT MAX( r2.id ) FROM %i r2 WHERE r2.license_id = r.license_id AND r2.status = %s )",
					$tables['renewals'],
					$tables['licenses'],
					'paid',
					'revoked',
					$tables['renewals'],
					'paid'
				)
			),
			'failed_scheduled_actions'   => self::failed_scheduled_actions(),
			'orders_with_issuance_problem' => max( 0, self::orders_with_issuance_problem() ),
			'failed_jobs'                => count( \WPLC\Maintenance\DailyRunner::failures( DAY_IN_SECONDS ) ),
			'release_problems'           => count( \WPLC\Update\ReleaseRegistry::problems( false ) ),
			'unmapped_license_products'  => self::unmapped_license_products(),
			'rejected_webhook_minutes'   => self::rejected_webhook_minutes(),
		);
	}

	/**
	 * Failed Action Scheduler actions of the plugin's group.
	 *
	 * @return int Number of failed actions (at most 500), -1 when Action Scheduler is unavailable.
	 */
	public static function failed_scheduled_actions(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return -1;
		}
		try {
			$ids = as_get_scheduled_actions(
				array(
					'group'    => 'wplc',
					'status'   => 'failed',
					'per_page' => 500,
				),
				'ids'
			);
		} catch ( \Throwable $error ) {
			return -1;
		}

		return count( (array) $ids );
	}

	/**
	 * Products ticked as license product that have no plan in the catalog: they sell without issuing a license.
	 *
	 * @return int
	 */
	public static function unmapped_license_products(): int {
		return count( \WPLC\Catalog\Catalog::flagged_without_mapping( 500 ) );
	}

	/**
	 * Orders the issuer could not turn into licenses (meta LicenseIssuer::META_PROBLEM), at most 200 counted.
	 *
	 * @return int -1 when the orders cannot be queried.
	 */
	public static function orders_with_issuance_problem(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return -1;
		}
		$query = array(
			'limit'  => 200,
			'return' => 'ids',
			'type'   => 'shop_order',
		);
		$util  = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $util ) && call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- diagnostics page and daily alert only; few orders carry the key.
			$query['meta_query'] = array(
				array(
					'key'     => \WPLC\License\LicenseIssuer::META_PROBLEM,
					'compare' => 'EXISTS',
				),
			);
		} else {
			// The legacy order storage does not accept meta_query; these two arguments reach WP_Query.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- diagnostics page and daily alert only.
			$query['meta_key']     = \WPLC\License\LicenseIssuer::META_PROBLEM;
			$query['meta_compare'] = 'EXISTS';
		}
		try {
			return count( (array) wc_get_orders( $query ) );
		} catch ( \Throwable $error ) {
			return -1;
		}
	}

	/**
	 * Check the most recent license keys against the configured secret.
	 *
	 * A key that cannot be reproduced is shown to the customer as "not available"
	 * (wrong or rotated secret, changed salts).
	 *
	 * @param bool $fresh Skip the five-minute cache.
	 * @return array{checked:int,verified:int}
	 */
	public static function key_sample( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( 'wplc_key_sample' );
		if ( is_array( $cached ) && isset( $cached['checked'], $cached['verified'] ) ) {
			return array(
				'checked'  => (int) $cached['checked'],
				'verified' => (int) $cached['verified'],
			);
		}

		// The check itself belongs to the key class (B-64); this method only caches it.
		$result = LicenseKey::verification_sample( self::KEY_SAMPLE_SIZE );
		set_transient( 'wplc_key_sample', $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Take and release a named database lock, like the activation and renewal code does.
	 *
	 * Fails on database proxies that spread one request over several connections.
	 *
	 * @return bool
	 */
	public static function lock_self_test(): bool {
		/**
		 * Lets the package that owns the lock helper answer the self-test with its own implementation.
		 *
		 * @since 0.1.0
		 *
		 * @param bool|null $result True or false to answer the test; null runs the built-in test.
		 */
		$override = apply_filters( 'wplc_lock_self_test', null );
		if ( is_bool( $override ) ) {
			return $override;
		}

		// The same lock scope and connection the activation and renewal code uses (B-165).
		return \WPLC\License\ActivationManager::lock_self_test();
	}

	/**
	 * Which database layer serves this site, and whether it splits reads from writes (B-24).
	 *
	 * HyperDB and LudicrousDB (both a `db.php` drop-in) send reads to replicas. The
	 * activation lock and the site-limit counts must run on the primary; the plugin
	 * asks for that with `send_reads_to_master()` (ActivationManager::use_primary_database()),
	 * which has not been run against a replicated setup. A layer that splits without
	 * offering that switch can let the site limit be exceeded. Pure apart from the defaults.
	 *
	 * @param object|null $db      Database object; defaults to $wpdb.
	 * @param bool|null   $drop_in Whether `wp-content/db.php` exists; detected when null.
	 * @return array{class:string,drop_in:bool,splitting:bool,primary_switch:bool,warning:bool}
	 */
	public static function database_layer( $db = null, ?bool $drop_in = null ): array {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		if ( null === $drop_in ) {
			$drop_in = defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' );
		}
		$class = is_object( $db ) ? get_class( $db ) : '';
		// Both drop-ins register their servers with add_database(); their class names are hyperdb and LudicrousDB.
		$splitting      = is_object( $db ) && ( method_exists( $db, 'add_database' ) || in_array( strtolower( $class ), array( 'hyperdb', 'ludicrousdb' ), true ) );
		$primary_switch = is_object( $db ) && method_exists( $db, 'send_reads_to_master' );

		return array(
			'class'          => $class,
			'drop_in'        => $drop_in,
			'splitting'      => $splitting,
			'primary_switch' => $primary_switch,
			'warning'        => $splitting,
		);
	}

	/**
	 * How the client address of API requests is determined on this server.
	 *
	 * @param array<string,mixed>|null $server Server variables; defaults to $_SERVER.
	 * @return array{remote:string,client:string,header:string,proxies:bool,warning:bool}
	 */
	public static function client_address( ?array $server = null ): array {
		$server  = null === $server ? $_SERVER : $server;
		$remote  = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? trim( $server['REMOTE_ADDR'] ) : '';
		$header  = defined( 'WPLC_TRUSTED_PROXY_HEADER' ) ? (string) constant( 'WPLC_TRUSTED_PROXY_HEADER' ) : '';
		$proxies = defined( 'WPLC_TRUSTED_PROXIES' );
		$trusted = null;
		if ( $proxies ) {
			// Mirrors ClientIp::current(): a defined but malformed list trusts nobody.
			$configured = constant( 'WPLC_TRUSTED_PROXIES' );
			$trusted    = is_array( $configured ) ? $configured : array();
		}
		// The address the API uses for rate limits.
		$client = ClientIp::resolve( $server, $header, $trusted );
		// A private or loopback peer means a proxy or load balancer sits in front of PHP.
		$private = '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		return array(
			'remote'  => $remote,
			'client'  => $client,
			'header'  => $header,
			'proxies' => $proxies,
			'warning' => $private && ( '' === $header || ! $proxies ),
		);
	}

	/**
	 * Emit threshold events for external monitoring integrations.
	 *
	 * A receiver that throws must not end the request for the other receivers.
	 *
	 * @return void
	 */
	public static function emit_alerts(): void {
		try {
			$metrics = self::metrics();
		} catch ( \Throwable $error ) {
			return;
		}
		$thresholds = self::thresholds();
		foreach ( $thresholds as $metric => $threshold ) {
			if ( isset( $metrics[ $metric ] ) && is_numeric( $threshold ) && $metrics[ $metric ] >= (int) $threshold ) {
				try {
					do_action( 'wplc/health_alert', sanitize_key( (string) $metric ), $metrics[ $metric ], (int) $threshold, self::screen_url( (string) $metric ) );
				} catch ( \Throwable $error ) {
					// WordPress does not unwind its hook stack when a callback throws.
					global $wp_current_filter;
					if ( is_array( $wp_current_filter ) && 'wplc/health_alert' === end( $wp_current_filter ) ) {
						array_pop( $wp_current_filter );
					}
					// Logged and shown under "failed jobs"; the remaining alerts are still sent.
					\WPLC\Maintenance\DailyRunner::record_failure( 'diagnostics_emit_alerts', $error );
				}
			}
		}
	}

	/**
	 * Emit one threshold event right away, outside the daily run.
	 *
	 * Used where waiting for the daily check would be too slow, e.g. a renewal
	 * whose payment result stayed unknown. Respects the configured thresholds.
	 *
	 * @param string $metric Metric key.
	 * @param int    $value Current value.
	 * @return void
	 */
	public static function emit_alert( string $metric, int $value ): void {
		$thresholds = self::thresholds();
		if ( isset( $thresholds[ $metric ] ) && is_numeric( $thresholds[ $metric ] ) && $value >= (int) $thresholds[ $metric ] ) {
			do_action( 'wplc/health_alert', sanitize_key( $metric ), $value, (int) $thresholds[ $metric ], self::screen_url( $metric ) );
		}
	}

	/**
	 * Admin screen where the cause of a metric is handled, passed to `wplc/health_alert` as the 4th argument.
	 *
	 * @param string $metric Metric key.
	 * @return string Absolute admin URL.
	 */
	public static function screen_url( string $metric ): string {
		if ( 'review_required_renewals' === $metric ) {
			return RenewalReviewAdmin::list_url();
		}

		return admin_url( 'admin.php?page=wplc-diagnostics' );
	}

	/**
	 * Alert thresholds per metric key, after the filter.
	 *
	 * @return array<string,mixed>
	 */
	private static function thresholds(): array {
		$thresholds = apply_filters(
			'wplc_health_alert_thresholds',
			array(
				'pending_webhooks'          => 1,
				'manual_review_webhooks'    => 1,
				'overdue_renewals'          => 1,
				'stuck_processing_renewals' => 1,
				'paid_without_extension'    => 1,
				'failed_scheduled_actions'  => 1,
				'unmapped_license_products' => 1,
				'review_required_renewals'  => 1,
				'orders_with_issuance_problem' => 1,
				'failed_jobs'               => 1,
				'release_problems'          => 1,
				'rejected_webhook_minutes'  => 5,
			)
		);

		return is_array( $thresholds ) ? $thresholds : array();
	}

	/**
	 * Seconds a queued renewal job may lag before the renewal counts as stuck.
	 *
	 * Matches the daily requeue sweep (RenewalProcessor::requeue_overdue_renewals()),
	 * which only picks up rows whose retry time is more than an hour ago.
	 */
	public const STUCK_RENEWAL_TOLERANCE = 3600;

	/**
	 * Count renewals that are really stuck (metric `overdue_renewals`).
	 *
	 * Same rules as is_stuck_renewal(); keep the two in step.
	 *
	 * @param string $table Renewals table name.
	 * @param int    $now Current Unix time.
	 * @return int
	 */
	private static function count_stuck_renewals( string $table, int $now ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', $now - self::STUCK_RENEWAL_TOLERANCE );
		// Rows without grace_ends_at: the grace period ends grace_days() after period_start.
		$period_start_cutoff = gmdate( 'Y-m-d H:i:s', $now - self::STUCK_RENEWAL_TOLERANCE - LicenseLifecycle::grace_days() * 86400 );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE"
				. ' ( status IN (%s,%s,%s) AND retry_at IS NOT NULL AND retry_at <= %s )'
				. ' OR ( status = %s AND updated_at <= %s )'
				. ' OR ( status IN (%s,%s,%s) AND retry_at IS NULL AND ( grace_ends_at <= %s OR ( grace_ends_at IS NULL AND period_start <= %s ) ) )',
				$table,
				'scheduled',
				'failed',
				'reconciling',
				$cutoff,
				'processing',
				$cutoff,
				'scheduled',
				'failed',
				'manual_action_required',
				$cutoff,
				$period_start_cutoff
			)
		);
	}

	/**
	 * Whether a renewal row is stuck rather than in a known waiting state.
	 *
	 * Stuck means one of:
	 * - `scheduled`, `failed` or `reconciling` with a retry time more than the
	 *   tolerance ago: the queued attempt or hourly check should have run;
	 * - `processing` for longer than the tolerance: the stuck check (after
	 *   20 minutes) should have resolved it;
	 * - `scheduled`, `failed` or `manual_action_required` without a retry time
	 *   whose grace period ended more than the tolerance ago: the grace-end job
	 *   should have expired the license, which closes the row.
	 *
	 * Not stuck: a retry or check in the future or within the tolerance, a
	 * `failed` renewal without retries left that waits for the end of its grace
	 * period, `manual_action_required` before the grace end, `manual`, and
	 * `review_required` (own metric `review_required_renewals`).
	 *
	 * @param array<string,mixed> $renewal Renewal row (status, retry_at, updated_at, grace_ends_at, period_start).
	 * @param int                 $now Current Unix time.
	 * @return bool
	 */
	public static function is_stuck_renewal( array $renewal, int $now ): bool {
		$cutoff = $now - self::STUCK_RENEWAL_TOLERANCE;
		$status = (string) ( $renewal['status'] ?? '' );
		$retry_at = self::row_time( $renewal, 'retry_at' );

		if ( null !== $retry_at && in_array( $status, array( 'scheduled', 'failed', 'reconciling' ), true ) ) {
			return $retry_at <= $cutoff;
		}
		if ( 'processing' === $status ) {
			$updated_at = self::row_time( $renewal, 'updated_at' );
			return null !== $updated_at && $updated_at <= $cutoff;
		}
		if ( null === $retry_at && in_array( $status, array( 'scheduled', 'failed', 'manual_action_required' ), true ) ) {
			$grace_end = self::row_time( $renewal, 'grace_ends_at' );
			if ( null === $grace_end ) {
				$period_start = self::row_time( $renewal, 'period_start' );
				$grace_end = null === $period_start ? null : $period_start + LicenseLifecycle::grace_days() * 86400;
			}
			return null !== $grace_end && $grace_end <= $cutoff;
		}

		return false;
	}

	/**
	 * Read a UTC datetime column of a row as Unix time.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $key Column.
	 * @return int|null Null when empty or unparseable.
	 */
	private static function row_time( array $row, string $key ): ?int {
		$value = $row[ $key ] ?? null;
		if ( null === $value || '' === $value ) {
			return null;
		}
		$timestamp = strtotime( (string) $value . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/** Forget the cached package storage probe and return to the page. */
	public static function handle_storage_recheck(): void {
		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view diagnostics.', 'wp-license' ) );
		check_admin_referer( 'wplc_storage_recheck' );
		PackageStorageCheck::forget();
		wp_safe_redirect( admin_url( 'admin.php?page=wplc-diagnostics' ) );
		exit;
	}

	/**
	 * Render the package storage section: location, source and HTTP self-check.
	 *
	 * @return void
	 */
	private static function render_storage(): void {
		$status = PackageStorageCheck::status();
		$sources = array(
			'constant' => __( 'WPLC_PACKAGE_STORAGE_DIR constant', 'wp-license' ),
			'filter' => __( 'wplc_package_storage_dir filter', 'wp-license' ),
			'default' => __( 'Default (uploads directory)', 'wp-license' ),
		);
		$rows = array(
			__( 'Directory', 'wp-license' ) => $status['dir'],
			__( 'Configured by', 'wp-license' ) => $sources[ $status['source'] ] ?? $status['source'],
			__( 'Writable', 'wp-license' ) => $status['writable'] ? __( 'Yes', 'wp-license' ) : __( 'No', 'wp-license' ),
			__( 'Public access', 'wp-license' ) => PackageStorageCheck::result_label( $status['probe'] ),
		);
		if ( $status['checked_at'] > 0 ) {
			$rows[ __( 'Last checked (UTC)', 'wp-license' ) ] = gmdate( 'Y-m-d H:i:s', $status['checked_at'] );
		}

		echo '<h2>' . esc_html__( 'Package storage', 'wp-license' ) . '</h2>';
		if ( '' !== $status['constant_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( LocalPackageStorage::dir_error_message( $status['constant_error'] ) ) . ' ' . esc_html__( 'The constant is ignored and the default directory is used.', 'wp-license' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $status['web_reachable'] ) {
			$rule = null !== $status['url'] ? PackageStorageCheck::nginx_rule( $status['url'] ) : '';
			echo '<p>' . esc_html__( 'The directory lies inside the web root. Apache and IIS honour the generated .htaccess and web.config; nginx does not. Recommended: set WPLC_PACKAGE_STORAGE_DIR in wp-config.php to a directory outside the web root, or add this rule to the nginx server block:', 'wp-license' ) . '</p>';
			if ( '' !== $rule ) {
				echo '<pre><code>' . esc_html( $rule ) . '</code></pre>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wplc_storage_recheck">';
			wp_nonce_field( 'wplc_storage_recheck' );
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Check again', 'wp-license' ) . '</button></form>';
		}
	}

	/** Add a submenu for users who may read diagnostics. */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License diagnostics', 'wp-license' ),
			__( 'License diagnostics', 'wp-license' ),
			Capabilities::cap( 'view_diagnostics' ),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License diagnostics', 'wp-license' ) );
	}

	/**
	 * Build the diagnostics rows.
	 *
	 * @return array<int,array{label:string,value:string,state:string,help:string}> state: '', ok or warning.
	 */
	public static function rows(): array {
		global $wpdb;

		$metrics = self::metrics();
		$rows    = array();
		$add     = static function ( string $label, $value, string $state = '', string $help = '' ) use ( &$rows ): void {
			$rows[] = array(
				'label' => $label,
				'value' => (string) $value,
				'state' => $state,
				'help'  => $help,
			);
		};
		$count   = static function ( string $label, int $value, string $help ) use ( $add ): void {
			$add( $label, (string) $value, $value > 0 ? 'warning' : 'ok', $value > 0 ? $help : '' );
		};

		$add( __( 'Plugin version', 'wp-license' ), defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : __( 'Unknown', 'wp-license' ) );
		// A-42: cached answer of the release check only; this page does not ask GitHub.
		$release = \WPLC\Infrastructure\SelfUpdateCheck::enabled() ? \WPLC\Infrastructure\SelfUpdateCheck::latest( false ) : null;
		if ( ! \WPLC\Infrastructure\SelfUpdateCheck::enabled() ) {
			$add( __( 'Newest release', 'wp-license' ), __( 'Check switched off (WPLC_DISABLE_UPDATE_CHECK)', 'wp-license' ) );
		} elseif ( null === $release ) {
			$add( __( 'Newest release', 'wp-license' ), __( 'Not known yet. WordPress checks for updates twice a day.', 'wp-license' ) );
		} elseif ( defined( 'WPLC_VERSION' ) && version_compare( $release['version'], (string) WPLC_VERSION, '>' ) ) {
			/* translators: 1: version number, 2: address of the release page. */
			$add( __( 'Newest release', 'wp-license' ), sprintf( __( '%1$s is available: %2$s', 'wp-license' ), $release['version'], $release['url'] ), 'warning', __( 'Download the ZIP and its .sha256 from the release page, compare the checksum and install the ZIP by upload (docs/manual/12-upgrading.md).', 'wp-license' ) );
		} else {
			$add( __( 'Newest release', 'wp-license' ), $release['version'], 'ok' );
		}
		$add( __( 'PHP', 'wp-license' ), PHP_VERSION );
		$add( __( 'WordPress', 'wp-license' ), get_bloginfo( 'version' ) );
		$add( __( 'WooCommerce', 'wp-license' ), defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Unavailable', 'wp-license' ) );
		$add( __( 'Database', 'wp-license' ), method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : __( 'Unknown', 'wp-license' ) );

		$schema    = (string) get_option( Schema::VERSION_OPTION, '0' );
		$schema_ok = 0 === version_compare( $schema, Schema::VERSION );
		$help      = '';
		if ( version_compare( $schema, Schema::VERSION, '<' ) ) {
			$help = __( 'The database migration did not finish. Deactivate and reactivate the plugin, then check the PHP error log.', 'wp-license' );
		} elseif ( ! $schema_ok ) {
			// B-101: the plugin was rolled back to an older version.
			$help = __( 'The database was already updated by a newer plugin version. This older version may not handle the data correctly; install the newer version again.', 'wp-license' );
		}
		$add( __( 'License schema (installed / expected)', 'wp-license' ), $schema . ' / ' . Schema::VERSION, $schema_ok ? 'ok' : 'warning', $help );
		// Written by the migration when a step fails.
		$migration_error = get_option( Schema::DIAGNOSTICS_ERROR_OPTION, '' );
		if ( is_string( $migration_error ) && '' !== $migration_error ) {
			$add( __( 'Last migration error', 'wp-license' ), $migration_error, 'warning' );
		}
		$missing = self::missing_tables();
		$add(
			__( 'License tables', 'wp-license' ),
			$missing ? implode( ', ', $missing ) : __( 'All present', 'wp-license' ),
			$missing ? 'warning' : 'ok',
			$missing ? __( 'These tables are missing. Deactivate and reactivate the plugin.', 'wp-license' ) : ''
		);
		if ( is_multisite() ) {
			self::add_site_cleanup_row( $add );
		}
		// Target/actual comparison of every column (Schema::status()).
		$structure = Schema::status()['problems'];
		$add(
			__( 'Table structure', 'wp-license' ),
			$structure ? implode( '; ', array_slice( $structure, 0, 10 ) ) : __( 'Matches this plugin version', 'wp-license' ),
			$structure ? 'warning' : 'ok',
			$structure ? __( 'Columns are missing or have another type than this version expects. The plugin repairs this in the next admin or cron request; if the row stays, check the database user\'s ALTER privilege.', 'wp-license' ) : ''
		);

		// Billing pause (staging copy, restored database, constant) and processing gaps.
		$pause_reasons = array(
			'constant'         => __( 'WPLC_PAUSE_BILLING is set in wp-config.php', 'wp-license' ),
			'schema_downgrade' => __( 'the database belongs to a newer plugin version', 'wp-license' ),
			'fingerprint'      => __( 'the site address or database changed (copy or restore); confirm the notice at the top of the admin pages', 'wp-license' ),
			'environment'      => __( 'the environment type is not "production"', 'wp-license' ),
		);
		$pause_reason  = \WPLC\Maintenance\SiteGuard::pause_reason();
		$paused        = \WPLC\Maintenance\SiteGuard::billing_paused();
		$pause_text    = __( 'Running', 'wp-license' );
		if ( $paused ) {
			/* translators: %s: reason. */
			$pause_text = sprintf( __( 'Paused: %s', 'wp-license' ), isset( $pause_reasons[ $pause_reason ] ) ? $pause_reasons[ $pause_reason ] : __( 'the filter wplc_billing_paused', 'wp-license' ) );
		} elseif ( '' !== $pause_reason ) {
			$pause_text = __( 'Running (the pause was lifted by the filter wplc_billing_paused)', 'wp-license' );
		}
		$add(
			__( 'Charges, expiry and customer emails', 'wp-license' ),
			$pause_text,
			$paused ? 'warning' : 'ok',
			$paused ? __( 'While paused nothing is charged, no license expires and no reminder or status email is sent. The API keeps answering.', 'wp-license' ) : ''
		);
		$hold_until = \WPLC\Maintenance\GapGuard::active_until();
		if ( $hold_until > 0 ) {
			$add(
				__( 'Processing gap', 'wp-license' ),
				/* translators: %s: date and time. */
				sprintf( __( 'Licenses that became due during the gap are not expired before %s', 'wp-license' ), Format::date( gmdate( 'Y-m-d H:i:s', $hold_until ), true ) ),
				'warning',
				__( 'The plugin was inactive, billing was paused or the daily run stood still. Renewals and orders of that time are caught up first.', 'wp-license' )
			);
		}

		$add(
			__( 'Server time (UTC) / site time', 'wp-license' ),
			gmdate( 'Y-m-d H:i:s' ) . ' / ' . wp_date( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ')'
		);
		$db_time = (string) $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' );
		$drift   = '' !== $db_time ? abs( (int) strtotime( $db_time . ' UTC' ) - time() ) : 0;
		$add(
			__( 'Database time (UTC)', 'wp-license' ),
			'' !== $db_time ? $db_time : __( 'Unknown', 'wp-license' ),
			$drift > 120 ? 'warning' : 'ok',
			$drift > 120 ? __( 'The clocks of the web server and the database differ by more than two minutes. Signed API requests and expiry dates depend on them.', 'wp-license' ) : ''
		);

		$add( __( 'HTTPS', 'wp-license' ), is_ssl() ? __( 'Enabled', 'wp-license' ) : __( 'Not detected', 'wp-license' ), is_ssl() ? 'ok' : 'warning' );
		$add( __( 'OpenSSL', 'wp-license' ), extension_loaded( 'openssl' ) ? __( 'Available', 'wp-license' ) : __( 'Unavailable', 'wp-license' ), extension_loaded( 'openssl' ) ? 'ok' : 'warning' );
		// T-31: only whether object storage is configured, the bucket and the endpoint host. Never a key.
		$object_storage = \WPLC\Update\S3Storage::config();
		if ( 'configured' === $object_storage['state'] ) {
			/* translators: 1: bucket name, 2: host name of the storage endpoint. */
			$add( __( 'Object storage (S3-compatible)', 'wp-license' ), sprintf( __( 'Bucket %1$s on %2$s', 'wp-license' ), $object_storage['bucket'], $object_storage['endpoint_host'] ), 'ok' );
		} elseif ( 'invalid' === $object_storage['state'] ) {
			$add( __( 'Object storage (S3-compatible)', 'wp-license' ), __( 'Invalid configuration', 'wp-license' ), 'warning', \WPLC\Update\S3Storage::error_message( $object_storage['error'] ) );
		} else {
			$add( __( 'Object storage (S3-compatible)', 'wp-license' ), __( 'Not configured', 'wp-license' ) );
		}

		// Background jobs.
		$run    = self::daily_run_state();
		$labels = array(
			'ok'         => __( 'Running', 'wp-license' ),
			'unknown'    => __( 'Not run yet', 'wp-license' ),
			'missing'    => __( 'Not run for more than 26 hours', 'wp-license' ),
			'incomplete' => __( 'Started, but did not finish', 'wp-license' ),
		);
		$add(
			__( 'Last daily run', 'wp-license' ),
			$labels[ $run['state'] ] . ( $run['last'] > 0 ? ' — ' . Format::date( gmdate( 'Y-m-d H:i:s', $run['last'] ), true ) : '' ),
			in_array( $run['state'], array( 'missing', 'incomplete' ), true ) ? 'warning' : ( 'ok' === $run['state'] ? 'ok' : '' ),
			in_array( $run['state'], array( 'missing', 'incomplete' ), true ) ? __( 'Licenses do not expire, failed payments are not retried and reminders are not sent. Check WP-Cron or the server cron job and the PHP error log.', 'wp-license' ) : ''
		);
		$next = wp_next_scheduled( 'wplc_daily_lifecycle' );
		$add(
			__( 'Next daily run', 'wp-license' ),
			$next ? Format::date( gmdate( 'Y-m-d H:i:s', (int) $next ), true ) : __( 'Not scheduled', 'wp-license' ),
			$next ? 'ok' : 'warning',
			$next ? '' : __( 'Deactivate and reactivate the plugin to schedule it again.', 'wp-license' )
		);
		foreach ( self::last_runs() as $job => $timestamp ) {
			if ( in_array( $job, array( self::JOB_DAILY, self::JOB_DAILY_STARTED, self::JOB_FIRST_SEEN, 'stripe_webhook', 'stripe_webhook_live', 'stripe_webhook_test', 'stripe_webhook_rejected' ), true ) ) {
				continue;
			}
			/* translators: %s: internal job name. */
			$add( sprintf( __( 'Last run: %s', 'wp-license' ), $job ), Format::date( gmdate( 'Y-m-d H:i:s', $timestamp ), true ) );
		}

		foreach ( \WPLC\Maintenance\DailyRunner::failures() as $job => $failure ) {
			$add(
				/* translators: %s: internal job name. */
				sprintf( __( 'Last failure: %s', 'wp-license' ), (string) $job ),
				Format::date( gmdate( 'Y-m-d H:i:s', (int) $failure['time'] ), true ) . ' — ' . (string) $failure['message'],
				time() - (int) $failure['time'] < DAY_IN_SECONDS ? 'warning' : '',
				__( 'The job threw an error and was skipped; the jobs after it still ran. The full message is in the WooCommerce log (source wp-license).', 'wp-license' )
			);
		}

		$scheduler = function_exists( 'as_enqueue_async_action' );
		$add(
			__( 'Action Scheduler', 'wp-license' ),
			$scheduler ? __( 'Available', 'wp-license' ) : __( 'Unavailable', 'wp-license' ),
			$scheduler ? 'ok' : 'warning',
			$scheduler ? '' : __( 'Scheduled work falls back to WP-Cron, which is less reliable.', 'wp-license' )
		);
		if ( $metrics['failed_scheduled_actions'] >= 0 ) {
			$count( __( 'Failed scheduled actions (group wplc)', 'wp-license' ), $metrics['failed_scheduled_actions'], __( 'Open WooCommerce → Status → Scheduled Actions, filter by "Failed" and search for "wplc" to see the error of each action.', 'wp-license' ) );
		}

		// Payments.
		$stripe = Requirements::stripe_adapter_status();
		$add(
			__( 'Automatic renewal (Stripe)', 'wp-license' ),
			$stripe['ok'] ? ( '' !== $stripe['reason'] ? $stripe['reason'] : __( 'Available', 'wp-license' ) ) : $stripe['reason'],
			$stripe['ok'] ? 'ok' : 'warning'
		);
		// A-132: an email that never went out is either not due yet or switched off; this row tells which.
		$disabled = self::disabled_license_emails();
		$add(
			__( 'License emails switched off', 'wp-license' ),
			$disabled ? implode( ', ', $disabled ) : __( 'None', 'wp-license' ),
			$disabled ? 'warning' : 'ok',
			$disabled ? __( 'These emails are switched off under WooCommerce → Settings → Emails and are not sent to customers (each skipped email is logged under the source wp-license).', 'wp-license' ) : ''
		);
		$add( __( 'Stripe gateway version', 'wp-license' ), defined( 'WC_STRIPE_VERSION' ) ? (string) WC_STRIPE_VERSION : __( 'Not loaded', 'wp-license' ) );
		$secret = \WPLC\Api\V1\StripeWebhook::is_configured();
		$add( __( 'Stripe webhook secret', 'wp-license' ), $secret ? __( 'Set', 'wp-license' ) : __( 'Not set. Stripe events are rejected.', 'wp-license' ), $secret ? 'ok' : 'warning' );
		$add( __( 'Last valid Stripe event', 'wp-license' ), self::last_stripe_event() );
		$webhook_runs = self::last_runs();
		foreach ( array( 'stripe_webhook_live' => __( 'Last Stripe event in live mode', 'wp-license' ), 'stripe_webhook_test' => __( 'Last Stripe event in test mode', 'wp-license' ) ) as $job => $label ) {
			if ( isset( $webhook_runs[ $job ] ) ) {
				$add( $label, Format::date( gmdate( 'Y-m-d H:i:s', $webhook_runs[ $job ] ), true ) );
			}
		}
		if ( isset( $webhook_runs['stripe_webhook_rejected'] ) ) {
			$add(
				__( 'Last rejected Stripe webhook request', 'wp-license' ),
				Format::date( gmdate( 'Y-m-d H:i:s', $webhook_runs['stripe_webhook_rejected'] ), true ),
				time() - $webhook_runs['stripe_webhook_rejected'] < DAY_IN_SECONDS ? 'warning' : '',
				__( 'A request to the webhook URL had no valid signature. If this repeats, the signing secret in wp-config.php does not match the endpoint in the Stripe dashboard (live and test mode have different secrets).', 'wp-license' )
			);
		}
		$count( __( 'Minutes with rejected Stripe webhook requests (24 hours)', 'wp-license' ), $metrics['rejected_webhook_minutes'], __( 'Requests to the webhook URL without a valid signature, counted at most once a minute. A few can be scans of the URL; many mean that the signing secret does not match the endpoint in the Stripe dashboard.', 'wp-license' ) );
		$count( __( 'Pending Stripe events', 'wp-license' ), $metrics['pending_webhooks'], __( 'Events that were received but not processed yet. They are retried by the background queue.', 'wp-license' ) );
		$count( __( 'Stripe events needing review (24 hours)', 'wp-license' ), $metrics['manual_review_webhooks'], __( 'The event could not be matched to a renewal. Compare the payment in the Stripe dashboard with the renewal order.', 'wp-license' ) );
		$count( __( 'Stuck renewals (job not run, grace period over)', 'wp-license' ), $metrics['overdue_renewals'], __( 'Renewals whose queued attempt or check should have run more than an hour ago, or whose grace period ended without the license being expired. Renewals waiting for their next retry are not counted.', 'wp-license' ) );
		$count( __( 'Renewals stuck in processing (over 1 hour)', 'wp-license' ), $metrics['stuck_processing_renewals'], __( 'A payment attempt started and never reported back. Check the renewal order and the payment in Stripe before anything is retried.', 'wp-license' ) );
		$count( __( 'Renewals needing review (unknown payment result, payment not applied)', 'wp-license' ), $metrics['review_required_renewals'], __( 'The payment provider did not give a final answer and the automatic check could not resolve it. Compare the renewal order with the payment in the provider\'s dashboard, then extend the license by hand or cancel the order. Nothing is charged again automatically.', 'wp-license' ) );
		$count( __( 'Licenses with automatic renewal but no open renewal', 'wp-license' ), $metrics['renewing_without_renewal'], __( 'These licenses would not be charged at the end of their term. The daily run schedules missing renewals.', 'wp-license' ) );
		$count( __( 'Paid renewals without extension', 'wp-license' ), $metrics['paid_without_extension'], __( 'The latest renewal of these licenses is paid, but the license does not run until the end of the paid period. Extend the term by hand or refund the order.', 'wp-license' ) );
		$count( __( 'Unused expired download grants', 'wp-license' ), $metrics['expired_download_grants'], '' );

		// Catalog and keys.
		$count( __( 'License products without a plan', 'wp-license' ), $metrics['unmapped_license_products'], __( 'These products are ticked as license product but have no product key and plan. They are sold without a license. Open the product and complete the license fields.', 'wp-license' ) );
		if ( self::orders_with_issuance_problem() >= 0 ) {
			$count( __( 'Paid orders without their licenses', 'wp-license' ), $metrics['orders_with_issuance_problem'], __( 'A license could not be created for these orders; the reason is in the order notes. Issuing is retried daily.', 'wp-license' ) );
		}
		foreach ( \WPLC\Update\ReleaseRegistry::problems( true ) as $problem ) {
			$add(
				__( 'Update packages', 'wp-license' ),
				(string) $problem['message'] . ( (int) $problem['release_id'] > 0 ? ' (#' . (int) $problem['release_id'] . ')' : '' ),
				'warning'
			);
		}
		$sample = self::key_sample( true );
		$add(
			__( 'License keys verifiable with the current secret', 'wp-license' ),
			/* translators: 1: number of verifiable keys, 2: number of checked keys. */
			sprintf( _n( '%1$s of the %2$s most recent key', '%1$s of the %2$s most recent keys', $sample['checked'], 'wp-license' ), number_format_i18n( $sample['verified'] ), number_format_i18n( $sample['checked'] ) ),
			$sample['verified'] === $sample['checked'] ? 'ok' : 'warning',
			$sample['verified'] === $sample['checked'] ? '' : __( 'The secret or the salts were changed. Customers with these keys see "not available" and their sites cannot be activated again. Restore the previous secret as an additional entry in WPLC_LICENSE_KEY_SECRETS.', 'wp-license' )
		);
		$fallback = KeyConfigurationNotice::is_fallback_active();
		$add(
			__( 'Secrets in wp-config.php', 'wp-license' ),
			$fallback ? __( 'Not configured; the WordPress salts are used', 'wp-license' ) : __( 'Configured', 'wp-license' ),
			$fallback ? 'warning' : 'ok'
		);
		$signer        = \WPLC\Security\ResponseSigner::status();
		$signer_states = array(
			'sodium_unavailable'     => __( 'the PHP extension sodium is missing', 'wp-license' ),
			'constant_invalid'       => __( 'WPLC_RESPONSE_SIGNING_KEY is not a valid key', 'wp-license' ),
			'undecryptable'          => __( 'the stored key cannot be decrypted with the configured secrets', 'wp-license' ),
			'corrupt'                => __( 'the stored key is damaged', 'wp-license' ),
			'missing'                => __( 'the stored key is gone', 'wp-license' ),
			'encryption_unavailable' => __( 'the key could not be encrypted for storage', 'wp-license' ),
			'storage_failed'         => __( 'the key could not be stored', 'wp-license' ),
			'busy'                   => __( 'another request is creating the key', 'wp-license' ),
			'unavailable'            => __( 'no key available', 'wp-license' ),
		);
		$signer_ok     = 'ok' === $signer['state'];
		$add(
			__( 'Response signing key (Ed25519, public)', 'wp-license' ),
			$signer_ok
				? $signer['public_key'] . ' (' . ( '' !== $signer['key_id'] ? $signer['key_id'] . ', ' : '' ) . ( 'constant' === $signer['source'] ? 'wp-config.php' : __( 'stored in the database', 'wp-license' ) ) . ')'
				/* translators: %s: reason. */
				: sprintf( __( 'Unavailable: %s', 'wp-license' ), isset( $signer_states[ $signer['state'] ] ) ? $signer_states[ $signer['state'] ] : $signer['state'] ),
			$signer_ok ? 'ok' : 'warning',
			$signer_ok
				? ( 'constant' === $signer['source'] ? '' : __( 'The key is stored encrypted in the database. For a production server, keep it in wp-config.php as WPLC_RESPONSE_SIGNING_KEY ("wp wplc signing-key"), so a restored or copied database cannot change it.', 'wp-license' ) )
				: __( 'API responses are sent without the Ed25519 signature. Add-ons that require it treat the license server as unreachable.', 'wp-license' )
		);
		$old_secrets = \WPLC\CLI\RotateInstallationSecretsCommand::pending_count();
		$add(
			__( 'Installation secrets encrypted with an old key', 'wp-license' ),
			(string) $old_secrets,
			$old_secrets > 0 ? 'warning' : 'ok',
			$old_secrets > 0 ? __( 'Run "wp wplc rotate-installation-secrets" before you remove the previous encryption key from wp-config.php.', 'wp-license' ) : ''
		);
		foreach ( array( 0 => __( 'Rejected API requests today (UTC)', 'wp-license' ), 1 => __( 'Rejected API requests yesterday (UTC)', 'wp-license' ) ) as $days_ago => $label ) {
			$rejected = \WPLC\Api\V1\ApiLog::counts( $days_ago );
			$parts    = array();
			foreach ( $rejected as $code => $number ) {
				$parts[] = $code . ': ' . (int) $number;
			}
			$add( $label, $parts ? implode( ', ', $parts ) : __( 'None', 'wp-license' ) );
		}

		// Infrastructure.
		$lock = self::lock_self_test();
		$add(
			__( 'Database locks (GET_LOCK)', 'wp-license' ),
			$lock ? __( 'Working', 'wp-license' ) : __( 'Not working', 'wp-license' ),
			$lock ? 'ok' : 'warning',
			$lock ? '' : __( 'Activations and renewals rely on named database locks. They fail behind database proxies that spread a request over several connections and on clusters with several writing nodes.', 'wp-license' )
		);
		$layer = self::database_layer();
		if ( $layer['splitting'] ) {
			$layer_help = $layer['primary_switch']
				? __( 'This database layer sends reads to replicas. The plugin routes the activation lock and the site-limit counts to the primary with send_reads_to_master(), but this has not been tested with replicas. Route the wplc_activations and wplc_licenses tables and all GET_LOCK calls to the primary, or the site limit may be exceeded (see docs/manual/09-operations.md).', 'wp-license' )
				: __( 'This database layer sends reads to replicas and offers no send_reads_to_master() switch. The activation lock and the site-limit counts may run on a replica, so the site limit can be exceeded. Route the wplc_activations and wplc_licenses tables and all GET_LOCK calls to the primary (see docs/manual/09-operations.md).', 'wp-license' );
			/* translators: %s: PHP class name of the database layer. */
			$add( __( 'Database layer', 'wp-license' ), sprintf( __( '%s (read/write splitting)', 'wp-license' ), $layer['class'] ), 'warning', $layer_help );
		} elseif ( $layer['drop_in'] ) {
			/* translators: %s: PHP class name of the database layer. */
			$add( __( 'Database layer', 'wp-license' ), sprintf( __( '%s (db.php drop-in)', 'wp-license' ), $layer['class'] ) );
		} else {
			$add( __( 'Database layer', 'wp-license' ), $layer['class'] );
		}
		$address = self::client_address();
		$add(
			__( 'Client address of this request', 'wp-license' ),
			/* translators: 1: address used for rate limits, 2: address of the direct peer. */
			sprintf( __( '%1$s (direct peer: %2$s)', 'wp-license' ), '' !== $address['client'] ? $address['client'] : '—', '' !== $address['remote'] ? $address['remote'] : '—' ),
			$address['warning'] ? 'warning' : 'ok',
			$address['warning'] ? __( 'The direct peer is a private address, so a proxy or load balancer sits in front of this server, but WPLC_TRUSTED_PROXY_HEADER and WPLC_TRUSTED_PROXIES are not both defined. All customers then share one rate limit.', 'wp-license' ) : ''
		);
		$add(
			__( 'Proxy constants', 'wp-license' ),
			sprintf(
				/* translators: 1: header name or "not set", 2: "set" or "not set". */
				__( 'WPLC_TRUSTED_PROXY_HEADER: %1$s; WPLC_TRUSTED_PROXIES: %2$s', 'wp-license' ),
				'' !== $address['header'] ? $address['header'] : __( 'not set', 'wp-license' ),
				$address['proxies'] ? __( 'set', 'wp-license' ) : __( 'not set', 'wp-license' )
			)
		);
		$proxy_warning = \WPLC\Api\V1\ClientIp::configuration_warning();
		if ( '' !== $proxy_warning ) {
			$add( __( 'Proxy header ignored', 'wp-license' ), __( 'Not used', 'wp-license' ), 'warning', $proxy_warning );
		}

		return $rows;
	}

	/**
	 * Date of the last Stripe event with a valid signature.
	 *
	 * @return string
	 */
	private static function last_stripe_event(): string {
		global $wpdb;

		foreach ( array( 'wplc_last_stripe_event', 'wplc_stripe_last_event' ) as $option ) {
			$stored = get_option( $option, null );
			if ( is_numeric( $stored ) && (int) $stored > 0 ) {
				return Format::date( gmdate( 'Y-m-d H:i:s', (int) $stored ), true );
			}
		}
		$runs = self::last_runs();
		if ( isset( $runs['stripe_webhook'] ) ) {
			return Format::date( gmdate( 'Y-m-d H:i:s', $runs['stripe_webhook'] ), true );
		}

		// Only events with a valid signature reach the inbox.
		$tables   = Schema::table_names();
		$received = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(received_at) FROM %i WHERE source = %s", $tables['event_inbox'], 'stripe' ) );

		return is_string( $received ) && '' !== $received ? Format::date( $received, true ) : __( 'None received yet', 'wp-license' );
	}

	/**
	 * Multisite: whether a deleted site's tables are dropped also without the plugin (P-32).
	 *
	 * @param callable $add Row callback of rows().
	 * @return void
	 */
	private static function add_site_cleanup_row( callable $add ): void {
		$label  = __( 'Site cleanup helper (must-use)', 'wp-license' );
		$status = SiteCleanupHelper::status();
		if ( SiteCleanupHelper::STATUS_INSTALLED === $status ) {
			$add( $label, __( 'Installed', 'wp-license' ), 'ok' );
			return;
		}
		if ( SiteCleanupHelper::STATUS_OUTDATED === $status ) {
			$add( $label, __( 'Older copy installed', 'wp-license' ), 'warning', __( 'Run wp wplc schema install-mu-helper to replace it with the version shipped with this plugin.', 'wp-license' ) );
			return;
		}
		if ( SiteCleanupHelper::STATUS_FOREIGN === $status ) {
			/* translators: %s: file name. */
			$add( $label, sprintf( __( 'Another file named %s is in the must-use directory', 'wp-license' ), SiteCleanupHelper::FILE_NAME ), 'warning' );
			return;
		}
		if ( function_exists( 'is_plugin_active_for_network' ) && defined( 'WPLC_FILE' ) && is_plugin_active_for_network( plugin_basename( WPLC_FILE ) ) ) {
			$add( $label, __( 'Not installed (not needed while the plugin is network-activated)', 'wp-license' ) );
			return;
		}
		$add( $label, __( 'Not installed', 'wp-license' ), 'warning', __( 'If this site is deleted from the network admin while the plugin is not active on the main site, its wplc_* tables stay behind. Install the optional helper with wp wplc schema install-mu-helper (docs/manual/09-operations.md).', 'wp-license' ) );
	}

	/**
	 * Plugin tables that do not exist.
	 *
	 * @return string[]
	 */
	private static function missing_tables(): array {
		global $wpdb;

		$missing = array();
		foreach ( Schema::table_names() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/** Render environment checks; never show keys, tokens, or payment data. */
	public static function render(): void {
		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view diagnostics.', 'wp-license' ) );

		echo '<div class="wrap wplc-diagnostics"><h1>' . esc_html__( 'WP License Captain diagnostics', 'wp-license' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only environment details for support. No license key, installation secret, token, or payment credential is displayed.', 'wp-license' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( self::rows() as $row ) {
			echo '<tr><th scope="row" style="width:340px">' . esc_html( $row['label'] ) . '</th><td>';
			if ( 'ok' === $row['state'] ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'OK:', 'wp-license' ) . '</span> ';
			} elseif ( 'warning' === $row['state'] ) {
				echo '<span class="dashicons dashicons-warning" style="color:#d63638" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Warning:', 'wp-license' ) . '</span> ';
			}
			echo esc_html( $row['value'] );
			if ( '' !== $row['help'] ) {
				echo '<p class="description">' . esc_html( $row['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$open_reviews = \WPLC\Billing\RenewalReview::open_count();
		if ( $open_reviews > 0 ) {
			echo '<p><a class="button" href="' . esc_url( RenewalReviewAdmin::list_url() ) . '">' . esc_html__( 'Open "Renewals to review"', 'wp-license' ) . '</a></p>';
		}
		self::render_review_events();
		self::render_storage();
		echo '</div>';
	}

	/**
	 * List the latest Stripe events that need a human (A-72). Payload and customer data are not shown.
	 *
	 * @return void
	 */
	private static function render_review_events(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_event_id, event_type, received_at, processed_at, outcome FROM %i WHERE source = %s AND outcome IN (%s, %s) ORDER BY id DESC LIMIT 50",
				$tables['event_inbox'],
				'stripe',
				'manual_review',
				'pending_reconciliation'
			),
			ARRAY_A
		);
		echo '<h2>' . esc_html__( 'Stripe events that need attention', 'wp-license' ) . '</h2>';
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'None.', 'wp-license' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Look the event up in the Stripe dashboard by its ID and compare the payment with the renewal order of the license. The latest 50 are shown.', 'wp-license' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Received', 'wp-license' ), __( 'Stripe event ID', 'wp-license' ), __( 'Type', 'wp-license' ), __( 'Outcome', 'wp-license' ), __( 'Processed', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			echo '<tr><td>' . esc_html( Format::date( $event['received_at'], true ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $event['source_event_id'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $event['event_type'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $event['outcome'] ) . '</code></td>';
			echo '<td>' . esc_html( Format::date( $event['processed_at'], true ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
