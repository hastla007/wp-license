<?php
/**
 * Versioned license events for other plugins in the shop.
 *
 * @package WPLC
 */

namespace WPLC\Events;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditReason;
use WPLC\License\LicenseKey;
use WPLC\Maintenance\DailyRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the event envelope and fires `wplc/event` and `wplc/event/{event}`.
 *
 * The contract is described in docs/LICENSE-EVENTS.md. In short: every event is
 * an array of scalar values with public identifiers only (the public license
 * reference `WPLC-XXXXXXXX`, product and plan key, the customer's WordPress
 * user ID); never a license key, token, email address, payment data or
 * database ID of the license. Events fire once, after the state change is
 * committed and the license lock is free (AfterCommit); the server stores
 * nothing and repeats nothing. A listener that throws is reported through
 * `wplc/job_failed` and does not disturb the license operation.
 */
final class LicenseEvents {

	/** Version of the event contract; equal to WPLC_EVENT_CONTRACT_VERSION in the plugin file. */
	public const CONTRACT_VERSION = '1.0.0';

	public const LICENSE_ISSUED         = 'license.license.issued';
	public const LICENSE_STATUS_CHANGED = 'license.license.status_changed';
	public const LICENSE_PLAN_CHANGED   = 'license.license.plan_changed';
	public const SITE_ACTIVATED         = 'license.site.activated';
	public const SITE_DEACTIVATED       = 'license.site.deactivated';
	public const SITE_LIMIT_REACHED     = 'license.site.limit_reached';
	public const RELEASE_PUBLISHED      = 'license.release.published';
	public const RELEASE_WITHDRAWN      = 'license.release.withdrawn';

	/**
	 * Every event with the version of its payload shape.
	 *
	 * @var array<string,int>
	 */
	public const EVENTS = array(
		self::LICENSE_ISSUED         => 1,
		self::LICENSE_STATUS_CHANGED => 1,
		self::LICENSE_PLAN_CHANGED   => 1,
		self::SITE_ACTIVATED         => 1,
		self::SITE_DEACTIVATED       => 1,
		self::SITE_LIMIT_REACHED     => 1,
		self::RELEASE_PUBLISHED      => 1,
		self::RELEASE_WITHDRAWN      => 1,
	);

	/** Who ended a site activation (`initiator` of license.site.deactivated). */
	public const INITIATORS = array( 'api', 'customer', 'support', 'system' );

	/**
	 * Register the listeners that turn the existing actions into events.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc/license_issued_followup', array( self::class, 'on_license_issued' ), 0, 2 );
		add_action( 'wplc/license_status_changed', array( self::class, 'on_status_changed' ), 0, 4 );
		add_action( 'wplc/license_plan_changed', array( self::class, 'on_plan_changed' ), 0, 1 );
	}

	/**
	 * Contract version in effect.
	 *
	 * @return string
	 */
	public static function contract_version(): string {
		return defined( 'WPLC_EVENT_CONTRACT_VERSION' ) ? (string) WPLC_EVENT_CONTRACT_VERSION : self::CONTRACT_VERSION;
	}

	/**
	 * `wplc/license_issued_followup` → license.license.issued.
	 *
	 * The follow-up can run again for a license whose key email is missing; the
	 * event then carries the same event_id, so the receiver recognises the repeat.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $order_id   Source order ID, zero for a license issued by hand.
	 * @return void
	 */
	public static function on_license_issued( $license_id, $order_id = 0 ): void {
		self::license_event( self::LICENSE_ISSUED, absint( $license_id ), array( 'order_id' => absint( $order_id ) ), true );
	}

	/**
	 * `wplc/license_status_changed` → license.license.status_changed.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $old_status Status before.
	 * @param mixed $new_status Status after.
	 * @param mixed $action     Lifecycle action (e.g. renewal_succeeded, license_expired).
	 * @return void
	 */
	public static function on_status_changed( $license_id, $old_status = '', $new_status = '', $action = '' ): void {
		self::license_event(
			self::LICENSE_STATUS_CHANGED,
			absint( $license_id ),
			array(
				'status' => sanitize_key( (string) $new_status ),
				'previous_status' => sanitize_key( (string) $old_status ),
				'action' => sanitize_key( (string) $action ),
			)
		);
	}

	/**
	 * `wplc/license_plan_changed` → license.license.plan_changed.
	 *
	 * @param mixed $license_id License ID.
	 * @return void
	 */
	public static function on_plan_changed( $license_id ): void {
		self::license_event( self::LICENSE_PLAN_CHANGED, absint( $license_id ) );
	}

	/**
	 * A new site activation was stored (license.site.activated).
	 *
	 * @param int    $license_id  License ID.
	 * @param string $environment Stored environment (production, staging, development).
	 * @return void
	 */
	public static function site_activated( int $license_id, string $environment ): void {
		self::license_event( self::SITE_ACTIVATED, $license_id, array( 'environment' => self::environment( $environment ) ) );
	}

	/**
	 * A site activation ended (license.site.deactivated).
	 *
	 * @param int    $license_id    License ID.
	 * @param string $environment   Stored environment.
	 * @param string $audit_action  Audit action of the deactivation.
	 * @param string $reason        Reason code or text of the deactivation.
	 * @param int    $actor_user_id Acting user, zero for the system or the API.
	 * @return void
	 */
	public static function site_deactivated( int $license_id, string $environment, string $audit_action, string $reason, int $actor_user_id ): void {
		self::license_event(
			self::SITE_DEACTIVATED,
			$license_id,
			array(
				'environment' => self::environment( $environment ),
				'initiator' => self::initiator( $audit_action, $reason, $actor_user_id ),
			)
		);
	}

	/**
	 * An activation was refused because a site limit was reached (license.site.limit_reached).
	 *
	 * @param int    $license_id  License ID.
	 * @param string $environment Environment that has no free place (production, staging, development).
	 * @param int    $limit       The limit that was reached.
	 * @return void
	 */
	public static function site_limit_reached( int $license_id, string $environment, int $limit ): void {
		self::license_event(
			self::SITE_LIMIT_REACHED,
			$license_id,
			array(
				'environment' => self::environment( $environment ),
				'limit' => max( 0, $limit ),
			)
		);
	}

	/**
	 * A release was published or withdrawn.
	 *
	 * @param string              $event   RELEASE_PUBLISHED or RELEASE_WITHDRAWN.
	 * @param array<string,mixed> $release Release row (product_key, version, channel).
	 * @return void
	 */
	public static function release_event( string $event, array $release ): void {
		if ( ! self::has_listener( $event ) ) {
			return;
		}
		self::dispatch(
			self::envelope(
				$event,
				array(
					'product_key' => (string) ( $release['product_key'] ?? '' ),
					'release_version' => (string) ( $release['version'] ?? '' ),
					'channel' => (string) ( $release['channel'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Build and dispatch an event about one license.
	 *
	 * @param string              $event         Event name.
	 * @param int                 $license_id    License ID.
	 * @param array<string,mixed> $extra         Additional scalar fields.
	 * @param bool                $stable_id     Derive the event_id from the license instead of a random one.
	 * @return void
	 */
	public static function license_event( string $event, int $license_id, array $extra = array(), bool $stable_id = false ): void {
		global $wpdb;

		if ( $license_id < 1 || ! self::has_listener( $event ) ) {
			return;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT license_uuid, customer_id, product_key, plan_key, status FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return;
		}
		$event_id = $stable_id ? self::stable_event_id( $event, (string) $row['license_uuid'] ) : '';
		self::dispatch( self::envelope( $event, array_merge( self::license_fields( $row ), $extra ), $event_id ) );
	}

	/**
	 * Public license fields of an event (no key, no email, no database ID).
	 *
	 * @param array<string,mixed> $row License row with license_uuid, customer_id, product_key, plan_key, status.
	 * @return array<string,int|string>
	 */
	public static function license_fields( array $row ): array {
		return array(
			'license' => LicenseKey::prefix( (string) ( $row['license_uuid'] ?? '' ) ),
			'product_key' => (string) ( $row['product_key'] ?? '' ),
			'plan_key' => (string) ( $row['plan_key'] ?? '' ),
			'status' => (string) ( $row['status'] ?? '' ),
			'customer_user_id' => (int) ( $row['customer_id'] ?? 0 ),
		);
	}

	/**
	 * Wrap fields in the envelope.
	 *
	 * @param string              $event    Event name (one of EVENTS).
	 * @param array<string,mixed> $fields   Event fields; non-scalar values are dropped.
	 * @param string              $event_id Event ID; empty for a random UUID v4.
	 * @return array<string,int|float|string|bool|null>
	 */
	public static function envelope( string $event, array $fields, string $event_id = '' ): array {
		$envelope = array(
			'event_id' => '' === $event_id ? self::uuid4() : $event_id,
			'event' => $event,
			'version' => self::EVENTS[ $event ] ?? 1,
			'contract' => self::contract_version(),
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		foreach ( $fields as $name => $value ) {
			if ( ! isset( $envelope[ $name ] ) && ( null === $value || is_scalar( $value ) ) ) {
				$envelope[ (string) $name ] = $value;
			}
		}

		return $envelope;
	}

	/**
	 * Fire the event after the commit (now, when nothing is pending).
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 * @return void
	 */
	public static function dispatch( array $envelope ): void {
		AfterCommit::run(
			static function () use ( $envelope ): void {
				self::deliver( $envelope );
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
		return 'production' === $environment ? 'live' : sanitize_key( $environment );
	}

	/**
	 * Whether anybody listens to this event.
	 *
	 * @param string $event Event name.
	 * @return bool
	 */
	private static function has_listener( string $event ): bool {
		if ( ! function_exists( 'has_action' ) ) {
			return false; // Outside WordPress (isolated unit tests of the callers).
		}

		return (bool) has_action( 'wplc/event' ) || (bool) has_action( "wplc/event/{$event}" );
	}

	/**
	 * Fire the actions; a listener that throws is reported and does not disturb the caller.
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 * @return void
	 */
	private static function deliver( array $envelope ): void {
		$event = (string) $envelope['event'];
		try {
			/**
			 * Fires once for every license event of the versioned event contract (docs/LICENSE-EVENTS.md).
			 *
			 * Runs after the change is committed and the license lock is released. The
			 * envelope holds scalar values and public identifiers only. Errors of listeners
			 * are reported through `wplc/job_failed` and do not stop the license operation.
			 *
			 * @since 0.2.0
			 *
			 * @param array<string,int|float|string|bool|null> $envelope Event envelope.
			 */
			do_action( 'wplc/event', $envelope );
		} catch ( \Throwable $error ) {
			DailyRunner::record_failure( 'license_event:' . $event, $error );
		}
		try {
			// Same envelope under the event's own name, e.g. `wplc/event/license.site.activated`.
			do_action( "wplc/event/{$event}", $envelope );
		} catch ( \Throwable $error ) {
			DailyRunner::record_failure( 'license_event:' . $event, $error );
		}
	}

	/**
	 * Random UUID v4.
	 *
	 * @return string
	 */
	private static function uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Name-based UUID (v5 layout) of an event that may be repeated for the same license.
	 *
	 * @param string $event        Event name.
	 * @param string $license_uuid License UUID (not disclosed: only a hash of it is used).
	 * @return string
	 */
	private static function stable_event_id( string $event, string $license_uuid ): string {
		$bytes    = substr( hash( 'sha1', 'wplc-event|' . $event . '|' . $license_uuid, true ), 0, 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x50 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
