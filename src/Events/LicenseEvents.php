<?php
/**
 * License actions of the hook contract for other plugins in the shop.
 *
 * @package WPLC
 */

namespace WPLC\Events;

use WPLC\License\AuditReason;
use WPLC\Maintenance\DailyRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires the actions the suite specification asked the license server for
 * (`wplc/activation_created`, `wplc/activation_removed`,
 * `wplc/site_limit_reached`, `wplc/release_published`, plus
 * `wplc/release_withdrawn`), see docs/LICENSE-EVENTS.md.
 *
 * Plain PHP actions with scalar IDs only: no envelope, no event ID (the
 * consumer builds both), never a license key or part of it, a site address,
 * an email address or payment data. They fire after the change is committed
 * and the license and activation locks are released (AfterCommit). A
 * listener that throws is reported through `wplc/job_failed` and does not
 * disturb the operation that raised the action.
 */
final class LicenseEvents {

	/** Version of the hook contract; equal to WPLC_CONTRACT_VERSION in the plugin file. */
	public const CONTRACT_VERSION = '1.0.0';

	/** Who ended an activation (`$initiator` of `wplc/activation_removed`). */
	public const INITIATORS = array( 'api', 'customer', 'support', 'system' );

	/**
	 * Contract version in effect.
	 *
	 * @return string
	 */
	public static function contract_version(): string {
		return defined( 'WPLC_CONTRACT_VERSION' ) ? (string) WPLC_CONTRACT_VERSION : self::CONTRACT_VERSION;
	}

	/**
	 * A new site activation was stored.
	 *
	 * @param int    $license_id    License ID.
	 * @param int    $activation_id Activation ID.
	 * @param string $environment   Stored environment (production, staging, development).
	 * @return void
	 */
	public static function activation_created( int $license_id, int $activation_id, string $environment ): void {
		$environment = self::environment( $environment );
		self::fire(
			'activation_created',
			static function () use ( $license_id, $activation_id, $environment ): void {
				/**
				 * Fires after a new site activation was stored (suite event `license.activation.created`).
				 *
				 * Not for a repeated activation of a known installation. Fires after the commit and
				 * after the activation lock is released. No key, no site address.
				 *
				 * @since 0.2.0
				 *
				 * @param int    $license_id    License ID.
				 * @param int    $activation_id Activation ID.
				 * @param string $environment   live, staging or development.
				 */
				do_action( 'wplc/activation_created', $license_id, $activation_id, $environment );
			}
		);
	}

	/**
	 * A site activation ended.
	 *
	 * @param int    $license_id    License ID.
	 * @param int    $activation_id Activation ID.
	 * @param string $environment   Stored environment.
	 * @param string $audit_action  Audit action of the deactivation.
	 * @param string $reason        Reason code or text of the deactivation (not passed on).
	 * @param int    $actor_user_id Acting user, zero for the system or the API.
	 * @return void
	 */
	public static function activation_removed( int $license_id, int $activation_id, string $environment, string $audit_action, string $reason, int $actor_user_id ): void {
		$environment = self::environment( $environment );
		$initiator   = self::initiator( $audit_action, $reason, $actor_user_id );
		self::fire(
			'activation_removed',
			static function () use ( $license_id, $activation_id, $environment, $initiator ): void {
				/**
				 * Fires after a site activation ended (suite event `license.activation.removed`).
				 *
				 * Also for the staging copy that ends with its live site. Fires after the commit and
				 * after the activation lock is released. No key, no site address, no reason text.
				 *
				 * @since 0.2.0
				 *
				 * @param int    $license_id    License ID.
				 * @param int    $activation_id Activation ID.
				 * @param string $environment   live, staging or development.
				 * @param string $initiator     api, customer, support or system.
				 */
				do_action( 'wplc/activation_removed', $license_id, $activation_id, $environment, $initiator );
			}
		);
	}

	/**
	 * An activation was refused because a site limit was reached.
	 *
	 * @param int    $license_id  License ID.
	 * @param string $environment Environment without a free place (production, staging, development).
	 * @param int    $limit       The limit that was reached.
	 * @return void
	 */
	public static function site_limit_reached( int $license_id, string $environment, int $limit ): void {
		$environment = self::environment( $environment );
		$limit       = max( 0, $limit );
		self::fire(
			'site_limit_reached',
			static function () use ( $license_id, $environment, $limit ): void {
				/**
				 * Fires when an activation was refused because a site limit was reached (suite event `license.license.site_limit_reached`).
				 *
				 * One call per refused request (`409 site_limit`, `staging_limit`, `development_limit`),
				 * after the activation lock is released.
				 *
				 * @since 0.2.0
				 *
				 * @param int    $license_id  License ID.
				 * @param string $environment live, staging or development.
				 * @param int    $limit       The limit that was reached.
				 */
				do_action( 'wplc/site_limit_reached', $license_id, $environment, $limit );
			}
		);
	}

	/**
	 * A release was published.
	 *
	 * @param array<string,mixed> $release Release row (id, product_key, version, channel).
	 * @return void
	 */
	public static function release_published( array $release ): void {
		list( $release_id, $product_key, $version, $channel ) = self::release_fields( $release );
		self::fire(
			'release_published',
			static function () use ( $release_id, $product_key, $version, $channel ): void {
				/**
				 * Fires after a release was published (suite event `license.release.published`).
				 *
				 * Fires after the status change is stored. No package address, no checksum.
				 *
				 * @since 0.2.0
				 *
				 * @param int    $release_id  Release ID.
				 * @param string $product_key Product key.
				 * @param string $version     Release version.
				 * @param string $channel     Update channel.
				 */
				do_action( 'wplc/release_published', $release_id, $product_key, $version, $channel );
			}
		);
	}

	/**
	 * A published release was withdrawn.
	 *
	 * @param array<string,mixed> $release Release row (id, product_key, version, channel).
	 * @return void
	 */
	public static function release_withdrawn( array $release ): void {
		list( $release_id, $product_key, $version, $channel ) = self::release_fields( $release );
		self::fire(
			'release_withdrawn',
			static function () use ( $release_id, $product_key, $version, $channel ): void {
				/**
				 * Fires after a published release was withdrawn (not in the suite catalog v1).
				 *
				 * Fires after the status change is stored. No package address, no checksum.
				 *
				 * @since 0.2.0
				 *
				 * @param int    $release_id  Release ID.
				 * @param string $product_key Product key.
				 * @param string $version     Release version.
				 * @param string $channel     Update channel.
				 */
				do_action( 'wplc/release_withdrawn', $release_id, $product_key, $version, $channel );
			}
		);
	}

	/**
	 * Who ended an activation, from the audit action and reason of the deactivation.
	 *
	 * @param string $audit_action  Audit action.
	 * @param string $reason        Reason code or text.
	 * @param int    $actor_user_id Acting user.
	 * @return string One of INITIATORS.
	 */
	public static function initiator( string $audit_action, string $reason, int $actor_user_id ): string {
		if ( 'admin_site_released' === $audit_action ) {
			return 'support';
		}
		if ( 'site_deactivated_license_ended' === $audit_action ) {
			return 'system';
		}
		if ( 'site_replaced' === $audit_action || AuditReason::DEACTIVATED_BY_INSTALLATION === $reason ) {
			return 'api';
		}
		if ( AuditReason::DEACTIVATED_BY_OWNER === $reason ) {
			return 'customer';
		}

		return $actor_user_id > 0 ? 'support' : 'system';
	}

	/**
	 * Public name of a stored environment.
	 *
	 * @param string $environment production, staging or development.
	 * @return string live, staging or development.
	 */
	public static function environment( string $environment ): string {
		if ( 'production' === $environment ) {
			return 'live';
		}

		return in_array( $environment, array( 'staging', 'development' ), true ) ? $environment : 'live';
	}

	/**
	 * Scalar fields of a release row.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @return array{0:int,1:string,2:string,3:string}
	 */
	private static function release_fields( array $release ): array {
		return array(
			(int) ( $release['id'] ?? 0 ),
			(string) ( $release['product_key'] ?? '' ),
			(string) ( $release['version'] ?? '' ),
			(string) ( $release['channel'] ?? '' ),
		);
	}

	/**
	 * Run the action after the commit; a listener that throws is reported, not passed on.
	 *
	 * @param string   $name     Short action name (for the failure report).
	 * @param callable $callback Fires the action.
	 * @return void
	 */
	private static function fire( string $name, callable $callback ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return; // Outside WordPress (isolated unit tests of the callers).
		}
		AfterCommit::run(
			static function () use ( $name, $callback ): void {
				try {
					$callback();
				} catch ( \Throwable $error ) {
					DailyRunner::record_failure( 'action:wplc/' . $name, $error );
				}
			}
		);
	}
}
