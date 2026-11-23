<?php
/**
 * Stored webhook endpoints.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create, change, delete and read the endpoints of the outgoing webhooks (table `webhook_endpoints`).
 *
 * The secret is generated here, returned once to the caller and stored encrypted with
 * SecretCrypto (the key ring of the installation secrets). Every change is written to the audit
 * log (object type `webhook`) without the secret.
 */
final class Endpoints {

	/** Autoloaded option: event names with at least one active endpoint, so an action without one costs no query. */
	public const INDEX_OPTION = 'wplc_webhook_events';

	/** Option: IDs of endpoints that were switched off after failures and still need the administrator's attention. */
	public const DISABLED_OPTION = 'wplc_webhook_auto_disabled';

	/** Reason stored when an endpoint was switched off after failed deliveries. */
	public const REASON_FAILURES = 'failures';

	/** Most endpoints per site. */
	public const MAX_ENDPOINTS = 20;

	/**
	 * Create an endpoint with a new secret.
	 *
	 * @param string            $name    Label.
	 * @param string            $url     Target URL (https).
	 * @param array<int,string> $events  Selected event names.
	 * @param bool              $active  Whether it receives events right away.
	 * @param int               $user_id Acting user.
	 * @return array{ok:bool,error:string,id:int,secret:string}
	 */
	public static function create( string $name, string $url, array $events, bool $active, int $user_id ): array {
		global $wpdb;

		$checked = self::validate( $url, $events );
		if ( '' !== $checked['error'] ) {
			return self::result( false, $checked['error'] );
		}
		if ( count( self::all() ) >= self::MAX_ENDPOINTS ) {
			return self::result( false, 'too_many' );
		}
		$secret    = Payload::generate_secret();
		$encrypted = SecretCrypto::encrypt( $secret );
		if ( false === $encrypted ) {
			return self::result( false, 'encryption_failed' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'name'             => self::clean_name( $name ),
				'url'              => trim( $url ),
				'secret_encrypted' => $encrypted,
				'events'           => implode( ',', $checked['events'] ),
				'active'           => $active ? 1 : 0,
				'created_by'       => max( 0, $user_id ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return self::result( false, 'database' );
		}
		$id = (int) $wpdb->insert_id;
		self::refresh_index();
		AuditLog::record( 'webhook_endpoint_created', 'webhook', $id, 'Webhook endpoint added.', null, self::summary( (int) $id ), $user_id );

		$result           = self::result( true, '', $id );
		$result['secret'] = $secret;

		return $result;
	}

	/**
	 * Change name, URL, events and the on/off switch of an endpoint.
	 *
	 * Switching an endpoint on clears the failure counter and the notice about it.
	 *
	 * @param int               $id      Endpoint ID.
	 * @param string            $name    Label.
	 * @param string            $url     Target URL.
	 * @param array<int,string> $events  Selected event names.
	 * @param bool              $active  On or off.
	 * @param int               $user_id Acting user.
	 * @return array{ok:bool,error:string,id:int,secret:string}
	 */
	public static function update( int $id, string $name, string $url, array $events, bool $active, int $user_id ): array {
		global $wpdb;

		$before = self::get( $id );
		if ( null === $before ) {
			return self::result( false, 'not_found' );
		}
		$checked = self::validate( $url, $events );
		if ( '' !== $checked['error'] ) {
			return self::result( false, $checked['error'], $id );
		}
		$data = array(
			'name'       => self::clean_name( $name ),
			'url'        => trim( $url ),
			'events'     => implode( ',', $checked['events'] ),
			'active'     => $active ? 1 : 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( $active && ! $before['active'] ) {
			$data['consecutive_failures'] = 0;
			$data['disabled_reason']      = '';
			$data['disabled_at']          = null;
		}
		if ( false === $wpdb->update( self::table(), $data, array( 'id' => $id ) ) ) {
			return self::result( false, 'database', $id );
		}
		if ( $active ) {
			self::forget_disabled( $id );
		}
		self::refresh_index();
		AuditLog::record( 'webhook_endpoint_updated', 'webhook', $id, 'Webhook endpoint changed.', self::public_fields( $before ), self::summary( $id ), $user_id );

		return self::result( true, '', $id );
	}

	/**
	 * Switch an endpoint on or off without changing anything else.
	 *
	 * @param int  $id      Endpoint ID.
	 * @param bool $active  On or off.
	 * @param int  $user_id Acting user.
	 * @return array{ok:bool,error:string,id:int,secret:string}
	 */
	public static function set_active( int $id, bool $active, int $user_id ): array {
		$endpoint = self::get( $id );
		if ( null === $endpoint ) {
			return self::result( false, 'not_found' );
		}

		return self::update( $id, $endpoint['name'], $endpoint['url'], $endpoint['events'], $active, $user_id );
	}

	/**
	 * Replace the secret of an endpoint; the new one is returned once.
	 *
	 * @param int $id      Endpoint ID.
	 * @param int $user_id Acting user.
	 * @return array{ok:bool,error:string,id:int,secret:string}
	 */
	public static function rotate_secret( int $id, int $user_id ): array {
		global $wpdb;

		if ( null === self::get( $id ) ) {
			return self::result( false, 'not_found' );
		}
		$secret    = Payload::generate_secret();
		$encrypted = SecretCrypto::encrypt( $secret );
		if ( false === $encrypted ) {
			return self::result( false, 'encryption_failed', $id );
		}
		if ( false === $wpdb->update( self::table(), array( 'secret_encrypted' => $encrypted, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ) ) {
			return self::result( false, 'database', $id );
		}
		AuditLog::record( 'webhook_secret_rotated', 'webhook', $id, 'Webhook secret replaced.', null, null, $user_id );

		$result           = self::result( true, '', $id );
		$result['secret'] = $secret;

		return $result;
	}

	/**
	 * Delete an endpoint and its delivery log.
	 *
	 * @param int $id      Endpoint ID.
	 * @param int $user_id Acting user.
	 * @return bool False when it did not exist.
	 */
	public static function delete( int $id, int $user_id ): bool {
		global $wpdb;

		$before = self::get( $id );
		if ( null === $before ) {
			return false;
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table_names()['webhook_deliveries'], array( 'endpoint_id' => $id ), array( '%d' ) );
		self::forget_disabled( $id );
		self::refresh_index();
		AuditLog::record( 'webhook_endpoint_deleted', 'webhook', $id, 'Webhook endpoint deleted.', self::public_fields( $before ), null, $user_id );

		return true;
	}

	/**
	 * One endpoint without its secret.
	 *
	 * @param int $id Endpoint ID.
	 * @return array{id:int,name:string,url:string,events:array<int,string>,active:bool,consecutive_failures:int,disabled_reason:string,disabled_at:string,last_success_at:string,last_failure_at:string,created_at:string}|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * All endpoints without their secrets, oldest first.
	 *
	 * @return array<int,array{id:int,name:string,url:string,events:array<int,string>,active:bool,consecutive_failures:int,disabled_reason:string,disabled_at:string,last_success_at:string,last_failure_at:string,created_at:string}>
	 */
	public static function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT %d', self::table(), self::MAX_ENDPOINTS * 5 ), ARRAY_A );

		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Active endpoints that subscribed to an event.
	 *
	 * @param string $event Event name.
	 * @return array<int,array{id:int,name:string,url:string,events:array<int,string>,active:bool,consecutive_failures:int,disabled_reason:string,disabled_at:string,last_success_at:string,last_failure_at:string,created_at:string}>
	 */
	public static function subscribed( string $event ): array {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || ! in_array( $event, $index, true ) ) {
			return array();
		}

		return array_values(
			array_filter(
				self::all(),
				static function ( array $endpoint ) use ( $event ): bool {
					return $endpoint['active'] && in_array( $event, $endpoint['events'], true );
				}
			)
		);
	}

	/**
	 * Plaintext secret of an endpoint, '' when it cannot be decrypted (key ring changed).
	 *
	 * @param int $id Endpoint ID.
	 * @return string
	 */
	public static function secret( int $id ): string {
		global $wpdb;

		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT secret_encrypted FROM %i WHERE id = %d', self::table(), $id ) );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		$plain = SecretCrypto::decrypt( $stored );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Record a successful delivery: the failure counter starts again.
	 *
	 * @param int $id Endpoint ID.
	 * @return void
	 */
	public static function record_success( int $id ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET consecutive_failures = 0, last_success_at = %s WHERE id = %d', self::table(), gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/**
	 * Record a delivery that failed after its last attempt; switch the endpoint off at the threshold.
	 *
	 * @param int $id        Endpoint ID.
	 * @param int $threshold Failed deliveries in a row after which the endpoint is switched off (0 = never).
	 * @return bool Whether this failure switched the endpoint off.
	 */
	public static function record_failure( int $id, int $threshold ): bool {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET consecutive_failures = consecutive_failures + 1, last_failure_at = %s WHERE id = %d', self::table(), $now, $id ) );
		if ( $threshold < 1 ) {
			return false;
		}
		// Only the request that crosses the threshold switches it off (conditional update).
		$switched = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET active = 0, disabled_reason = %s, disabled_at = %s, updated_at = %s WHERE id = %d AND active = 1 AND consecutive_failures >= %d',
				self::table(),
				self::REASON_FAILURES,
				$now,
				$now,
				$id,
				$threshold
			)
		);
		if ( 1 !== (int) $switched ) {
			return false;
		}
		$disabled   = get_option( self::DISABLED_OPTION, array() );
		$disabled   = is_array( $disabled ) ? array_map( 'intval', $disabled ) : array();
		$disabled[] = $id;
		update_option( self::DISABLED_OPTION, array_values( array_unique( $disabled ) ), false );
		self::refresh_index();
		AuditLog::record( 'webhook_endpoint_disabled', 'webhook', $id, sprintf( 'Webhook endpoint switched off after %d failed deliveries in a row.', $threshold ), null, self::summary( $id ), 0 );

		return true;
	}

	/**
	 * IDs of endpoints switched off after failures that are still off.
	 *
	 * @return array<int,int>
	 */
	public static function auto_disabled_ids(): array {
		$ids = get_option( self::DISABLED_OPTION, array() );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Remove an endpoint from the notice about switched-off endpoints.
	 *
	 * @param int $id Endpoint ID.
	 * @return void
	 */
	public static function forget_disabled( int $id ): void {
		$ids = self::auto_disabled_ids();
		if ( ! in_array( $id, $ids, true ) ) {
			return;
		}
		$ids = array_values( array_diff( $ids, array( $id ) ) );
		if ( $ids ) {
			update_option( self::DISABLED_OPTION, $ids, false );
		} else {
			delete_option( self::DISABLED_OPTION );
		}
	}

	/**
	 * Rebuild the autoloaded list of events that have an active endpoint.
	 *
	 * @return void
	 */
	public static function refresh_index(): void {
		$events = array();
		foreach ( self::all() as $endpoint ) {
			if ( $endpoint['active'] ) {
				$events = array_merge( $events, $endpoint['events'] );
			}
		}
		$events = array_values( array_intersect( EventCatalog::names(), $events ) );
		if ( $events ) {
			update_option( self::INDEX_OPTION, $events, true );
		} else {
			delete_option( self::INDEX_OPTION );
		}
	}

	/**
	 * Check URL and event selection of an endpoint.
	 *
	 * @param string $url    Target URL.
	 * @param mixed  $events Selected events.
	 * @return array{error:string,events:array<int,string>}
	 */
	public static function validate( string $url, $events ): array {
		$events = EventCatalog::sanitize_selection( $events );
		$error  = UrlGuard::check( $url )['error'];
		if ( UrlGuard::OK === $error && ! $events ) {
			$error = 'no_events';
		}

		return array(
			'error'  => $error,
			'events' => $events,
		);
	}

	/**
	 * Fields of an endpoint for the audit log (no secret).
	 *
	 * @param int $id Endpoint ID.
	 * @return array<string,mixed>|null
	 */
	private static function summary( int $id ): ?array {
		$endpoint = self::get( $id );

		return null === $endpoint ? null : self::public_fields( $endpoint );
	}

	/**
	 * Public fields of a shaped endpoint for the audit log.
	 *
	 * Only the host of the URL: services such as automation platforms put an access token into the
	 * path or the query of their webhook addresses.
	 *
	 * @param array<string,mixed> $endpoint Shaped endpoint.
	 * @return array<string,mixed>
	 */
	private static function public_fields( array $endpoint ): array {
		return array(
			'name'   => $endpoint['name'],
			'host'   => UrlGuard::host( (string) $endpoint['url'] ),
			'events' => implode( ',', $endpoint['events'] ),
			'active' => $endpoint['active'] ? 1 : 0,
		);
	}

	/**
	 * Typed endpoint from a row; the secret is left out.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{id:int,name:string,url:string,events:array<int,string>,active:bool,consecutive_failures:int,disabled_reason:string,disabled_at:string,last_success_at:string,last_failure_at:string,created_at:string}
	 */
	private static function shape( array $row ): array {
		return array(
			'id'                   => (int) $row['id'],
			'name'                 => (string) $row['name'],
			'url'                  => (string) $row['url'],
			'events'               => EventCatalog::sanitize_selection( (string) $row['events'] ),
			'active'               => 1 === (int) $row['active'],
			'consecutive_failures' => (int) $row['consecutive_failures'],
			'disabled_reason'      => (string) $row['disabled_reason'],
			'disabled_at'          => (string) ( $row['disabled_at'] ?? '' ),
			'last_success_at'      => (string) ( $row['last_success_at'] ?? '' ),
			'last_failure_at'      => (string) ( $row['last_failure_at'] ?? '' ),
			'created_at'           => (string) $row['created_at'],
		);
	}

	/**
	 * Label without markup, at most 190 characters.
	 *
	 * @param string $name Label.
	 * @return string
	 */
	private static function clean_name( string $name ): string {
		$name = sanitize_text_field( $name );

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 190 ) : substr( $name, 0, 190 );
	}

	/**
	 * Result of a change.
	 *
	 * @param bool   $ok    Success.
	 * @param string $error Error code.
	 * @param int    $id    Endpoint ID.
	 * @return array{ok:bool,error:string,id:int,secret:string}
	 */
	private static function result( bool $ok, string $error, int $id = 0 ): array {
		return array(
			'ok'     => $ok,
			'error'  => $error,
			'id'     => $id,
			'secret' => '',
		);
	}

	/**
	 * Table of the endpoints.
	 *
	 * @return string
	 */
	private static function table(): string {
		return Schema::table_names()['webhook_endpoints'];
	}
}
