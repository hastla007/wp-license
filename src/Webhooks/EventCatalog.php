<?php
/**
 * The license events that can be sent as outgoing webhooks.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the actions of the versioned hook contract (docs/LICENSE-EVENTS.md) to webhook events.
 *
 * Only the eight contract actions are offered: they pass scalar record IDs, never a key, a site
 * address, an email address or payment data, and they fire after the commit (AfterCommit). The
 * actions that pass the plaintext key (`wplc/license_issued`, `wplc/license_key_reissued`,
 * `wplc/license_key_resend_requested`) are never offered. Pure: no WordPress functions.
 */
final class EventCatalog {

	/** Event sent by the "send test event" button and `wp wplc webhook test`. */
	public const TEST_EVENT = 'webhook.test';

	/**
	 * Event name => action of the hook contract and the names of its arguments, in order.
	 *
	 * @var array<string,array{hook:string,args:array<int,string>}>
	 */
	private const EVENTS = array(
		'license.issued'             => array(
			'hook' => 'wplc/license_issued_followup',
			'args' => array( 'license_id', 'order_id' ),
		),
		'license.status_changed'     => array(
			'hook' => 'wplc/license_status_changed',
			'args' => array( 'license_id', 'old_status', 'new_status', 'action' ),
		),
		'license.plan_changed'       => array(
			'hook' => 'wplc/license_plan_changed',
			'args' => array( 'license_id' ),
		),
		'license.site_limit_reached' => array(
			'hook' => 'wplc/site_limit_reached',
			'args' => array( 'license_id', 'environment', 'limit' ),
		),
		'activation.created'         => array(
			'hook' => 'wplc/activation_created',
			'args' => array( 'license_id', 'activation_id', 'environment' ),
		),
		'activation.removed'         => array(
			'hook' => 'wplc/activation_removed',
			'args' => array( 'license_id', 'activation_id', 'environment', 'initiator' ),
		),
		'release.published'          => array(
			'hook' => 'wplc/release_published',
			'args' => array( 'release_id', 'product_key', 'version', 'channel' ),
		),
		'release.withdrawn'          => array(
			'hook' => 'wplc/release_withdrawn',
			'args' => array( 'release_id', 'product_key', 'version', 'channel' ),
		),
	);

	/** Arguments that are integers; all others are strings. */
	private const INT_ARGS = array( 'license_id', 'order_id', 'activation_id', 'release_id', 'limit' );

	/**
	 * Every event name an endpoint can subscribe to, in display order.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		return array_keys( self::EVENTS );
	}

	/**
	 * Whether a name is a subscribable event.
	 *
	 * @param string $event Event name.
	 * @return bool
	 */
	public static function exists( string $event ): bool {
		return isset( self::EVENTS[ $event ] );
	}

	/**
	 * Action of the hook contract behind an event, '' for an unknown event.
	 *
	 * @param string $event Event name.
	 * @return string
	 */
	public static function hook( string $event ): string {
		return self::EVENTS[ $event ]['hook'] ?? '';
	}

	/**
	 * Event name of an action, '' for an action that is not offered.
	 *
	 * @param string $hook Action name.
	 * @return string
	 */
	public static function event_for_hook( string $hook ): string {
		foreach ( self::EVENTS as $event => $definition ) {
			if ( $hook === $definition['hook'] ) {
				return $event;
			}
		}

		return '';
	}

	/**
	 * Action name => number of arguments, for registering the listeners.
	 *
	 * @return array<string,int>
	 */
	public static function hooks(): array {
		$hooks = array();
		foreach ( self::EVENTS as $definition ) {
			$hooks[ $definition['hook'] ] = count( $definition['args'] );
		}

		return $hooks;
	}

	/**
	 * Named, typed event data from the positional arguments of the action.
	 *
	 * Extra arguments are dropped and missing ones become 0 or ''. Only scalars come out.
	 *
	 * @param string           $event Event name.
	 * @param array<int,mixed> $args  Arguments of the action.
	 * @return array<string,int|string>
	 */
	public static function data( string $event, array $args ): array {
		$data = array();
		foreach ( self::EVENTS[ $event ]['args'] ?? array() as $index => $name ) {
			$value         = $args[ $index ] ?? null;
			$data[ $name ] = in_array( $name, self::INT_ARGS, true )
				? ( is_numeric( $value ) ? (int) $value : 0 )
				: ( is_scalar( $value ) ? (string) $value : '' );
		}

		return $data;
	}

	/**
	 * Keep the known events of a selection, in catalog order and without duplicates.
	 *
	 * @param mixed $events Selected event names (list or comma-separated string).
	 * @return array<int,string>
	 */
	public static function sanitize_selection( $events ): array {
		if ( is_string( $events ) ) {
			$events = explode( ',', $events );
		}
		$events = is_array( $events ) ? array_map( static fn( $event ): string => trim( is_scalar( $event ) ? (string) $event : '' ), $events ) : array();

		return array_values( array_intersect( self::names(), $events ) );
	}
}
