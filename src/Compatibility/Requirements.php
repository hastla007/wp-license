<?php
/**
 * Minimum versions of WooCommerce, Action Scheduler and the Stripe gateway.
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
	 * Oldest supported WooCommerce: the Additional Checkout Fields API became
	 * stable in 8.9.0 (`woocommerce_register_additional_checkout_field()`, one
	 * order meta per field, `woocommerce_set_additional_field_value`). Everything
	 * else the plugin calls is older (FeaturesUtil 7.1, `cart_checkout_blocks`
	 * and HPOS declarations, Action Scheduler 3.6 with WooCommerce 7.9).
	 */
	const MIN_WC = '8.9.0';

	/**
	 * Conditional visibility rules (`hidden` as JSON schema) for additional checkout fields.
	 */
	const MIN_WC_CONDITIONAL_FIELDS = '9.9.0';

	/**
	 * Action Scheduler release that added the `$unique` argument to the `as_*` functions.
	 */
	const MIN_AS_UNIQUE_ARGUMENT = '3.6.0';

	/**
	 * Action Scheduler release in which `$unique` compares hook, group AND args.
	 * Before 4.0.0 only hook and group are compared, so a "unique" action for one
	 * license blocks the same hook for every other license.
	 */
	const MIN_AS_UNIQUE_PER_ARGS = '4.0.0';

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
	 * Tell administrators that WooCommerce is older than the supported minimum.
	 *
	 * @return void
	 */
	public static function render_woocommerce_version_notice(): void {
		if ( self::woocommerce_is_supported() || ! current_user_can( 'activate_plugins' ) ) {
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
	 * Running Action Scheduler version, or an empty string when unknown.
	 *
	 * @return string
	 */
	public static function action_scheduler_version(): string {
		if ( ! class_exists( 'ActionScheduler_Versions' ) ) {
			return '';
		}
		$version = \ActionScheduler_Versions::instance()->latest_version();
		return is_string( $version ) ? $version : '';
	}

	/**
	 * How a "schedule once per hook and args" request must be carried out.
	 *
	 * - `native`: pass `$unique = true`; Action Scheduler compares hook, group and args atomically.
	 * - `emulated`: never pass `$unique`. Either the argument does not exist (below 3.6.0) or it
	 *   ignores the args (3.6.0 to 3.9.x) and would drop actions of other licenses. The caller
	 *   checks for an existing action first, which is not atomic.
	 *
	 * @param string $action_scheduler_version Running Action Scheduler version, '' when unknown.
	 * @return string
	 */
	public static function unique_mode( string $action_scheduler_version ): string {
		return self::version_at_least( $action_scheduler_version, self::MIN_AS_UNIQUE_PER_ARGS ) ? 'native' : 'emulated';
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
		if ( 'native' === self::unique_mode( self::action_scheduler_version() ) ) {
			return (int) as_schedule_single_action( $timestamp, $hook, $args, $group, true );
		}
		if ( self::has_scheduled_action( $hook, $args, $group ) ) {
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
		if ( 'native' === self::unique_mode( self::action_scheduler_version() ) ) {
			return (int) as_enqueue_async_action( $hook, $args, $group, true );
		}
		if ( self::has_scheduled_action( $hook, $args, $group ) ) {
			return 0;
		}
		return (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Whether a pending or running action with the same hook, args and group exists.
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int,mixed>  $args  Hook arguments.
	 * @param string            $group Action Scheduler group.
	 * @return bool
	 */
	private static function has_scheduled_action( string $hook, array $args, string $group ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, $args, $group );
		}
		return function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( $hook, $args, $group );
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
			} elseif ( null !== $symbol[1] && ! method_exists( $symbol[0], $symbol[1] ) ) {
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
