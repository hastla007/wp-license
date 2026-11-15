<?php
/**
 * Customer-driven move of a site activation to a newer license (A-22).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\SiteUrl;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves one site activation from a license that ended or is ending to another
 * license of the same customer and product that grants access.
 *
 * The installation keeps its ID and secret. Signed requests are authenticated
 * by installation ID and secret alone (Authenticator), and the entitlement
 * check reads the license through `activations.license_id`, so the installed
 * plugin continues on the new license without entering the new key. Active
 * staging sites of a moved live site move with it (they are licensed through
 * their live site). The live, staging and development limits of the target
 * license apply as for a new activation. There is no automatic transfer: the
 * customer moves each site with one click (owner decision pending).
 */
final class ActivationTransfer {

	/**
	 * Days before the end of the paid period from which a license that does not
	 * renew automatically counts as "ending".
	 */
	public const ENDING_DAYS = LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS;

	/**
	 * Most licenses of one customer and product considered as targets.
	 */
	private const MAX_CANDIDATES = 50;

	/**
	 * Whether sites may be moved away from this license: it ended (no access,
	 * suspended, revoked or expired) or is ending (cancelled for the end of the
	 * term, or no automatic renewal and the end is at most ENDING_DAYS away). Pure.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now     Unix timestamp.
	 * @return bool
	 */
	public static function is_ending( array $license, int $now ): bool {
		$status = (string) ( $license['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'past_due', 'expired', 'suspended', 'revoked' ), true ) ) {
			return false;
		}
		if ( ! LicenseLifecycle::has_access( $license, $now ) ) {
			return true;
		}
		if ( ! empty( $license['cancel_at_period_end'] ) ) {
			return true;
		}
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( false === $paid_until ) {
			return false;
		}

		return empty( $license['renewal_enabled'] ) && $paid_until <= $now + self::ENDING_DAYS * 86400;
	}

	/**
	 * Whether a license can take over the sites of another one. Pure.
	 *
	 * Same customer and product, grants access now, and runs longer than the
	 * source (no end, or a later end).
	 *
	 * @param array<string,mixed> $source Source license row.
	 * @param array<string,mixed> $target Target license row.
	 * @param int                 $now    Unix timestamp.
	 * @return bool
	 */
	public static function is_target( array $source, array $target, int $now ): bool {
		if ( (int) ( $source['id'] ?? 0 ) <= 0 || (int) ( $target['id'] ?? 0 ) <= 0 || (int) $source['id'] === (int) $target['id'] ) {
			return false;
		}
		if ( (int) ( $source['customer_id'] ?? 0 ) <= 0 || (int) $source['customer_id'] !== (int) ( $target['customer_id'] ?? 0 ) ) {
			return false;
		}
		if ( (string) ( $source['product_key'] ?? '' ) !== (string) ( $target['product_key'] ?? '' ) || '' === (string) $source['product_key'] ) {
			return false;
		}
		if ( ! LicenseLifecycle::has_access( $target, $now ) ) {
			return false;
		}

		return self::end_of( $target ) > self::end_of( $source );
	}

	/**
	 * Licenses a source license may move its sites to, from a list of candidates. Pure.
	 *
	 * @param array<string,mixed>            $source     Source license row.
	 * @param array<int,array<string,mixed>> $candidates License rows of the same customer.
	 * @param int                            $now        Unix timestamp.
	 * @return array<int,array<string,mixed>> Target rows, newest end first.
	 */
	public static function targets_from( array $source, array $candidates, int $now ): array {
		if ( ! self::is_ending( $source, $now ) ) {
			return array();
		}
		$targets = array();
		foreach ( $candidates as $candidate ) {
			if ( self::is_target( $source, $candidate, $now ) ) {
				$targets[] = $candidate;
			}
		}
		usort(
			$targets,
			static function ( array $a, array $b ): int {
				return array( self::end_of( $b ), (int) $b['id'] ) <=> array( self::end_of( $a ), (int) $a['id'] );
			}
		);

		return $targets;
	}

	/**
	 * Licenses of one customer that may take over sites, grouped by product key.
	 *
	 * One query for the customer account page.
	 *
	 * @param int $customer_id Customer.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function candidates_for_customer( int $customer_id ): array {
		global $wpdb;

		if ( $customer_id <= 0 ) {
			return array();
		}
		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE customer_id = %d AND status IN ( 'active', 'past_due' ) ORDER BY id DESC LIMIT %d",
				$tables['licenses'],
				$customer_id,
				self::MAX_CANDIDATES * 4
			),
			ARRAY_A
		);
		$grouped = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$grouped[ (string) $row['product_key'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Move one activation of a customer's license to another license of the customer.
	 *
	 * @param int $activation_id     Activation to move (live or development; a staging site moves with its live site).
	 * @param int $target_license_id License that takes over the site.
	 * @param int $customer_id       Acting customer, who must own both licenses.
	 * @return string Result code: moved, not_found, not_movable, not_allowed, same_site, limit, busy, failed.
	 */
	public static function move( int $activation_id, int $target_license_id, int $customer_id ): string {
		global $wpdb;

		if ( $activation_id <= 0 || $target_license_id <= 0 || $customer_id <= 0 ) {
			return 'not_found';
		}
		$tables = Schema::table_names();
		ActivationManager::use_primary_database();
		$source_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT a.license_id FROM %i a INNER JOIN %i l ON l.id = a.license_id WHERE a.id = %d AND a.status = %s AND l.customer_id = %d LIMIT 1',
				$tables['activations'],
				$tables['licenses'],
				$activation_id,
				'active',
				$customer_id
			)
		);
		if ( $source_id <= 0 ) {
			return 'not_found';
		}
		if ( $source_id === $target_license_id ) {
			return 'not_allowed';
		}

		// Both licenses change: lock them in ID order, as no other path holds two.
		$locks = array( ActivationManager::lock_name( min( $source_id, $target_license_id ) ), ActivationManager::lock_name( max( $source_id, $target_license_id ) ) );
		$held  = array();
		foreach ( $locks as $lock ) {
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
				self::release( $held );
				return 'busy';
			}
			$held[] = $lock;
		}

		try {
			return self::move_locked( $activation_id, $source_id, $target_license_id, $customer_id );
		} finally {
			self::release( $held );
		}
	}

	/**
	 * Move with both license locks held.
	 *
	 * @param int $activation_id Activation ID.
	 * @param int $source_id     Source license ID.
	 * @param int $target_id     Target license ID.
	 * @param int $customer_id   Acting customer.
	 * @return string
	 */
	private static function move_locked( int $activation_id, int $source_id, int $target_id, int $customer_id ): string {
		global $wpdb;

		$tables     = Schema::table_names();
		$activation = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND license_id = %d AND status = %s LIMIT 1', $tables['activations'], $activation_id, $source_id, 'active' ),
			ARRAY_A
		);
		if ( ! is_array( $activation ) ) {
			return 'not_found';
		}
		$environment = (string) $activation['environment'];
		if ( ! in_array( $environment, array( 'production', 'development' ), true ) ) {
			return 'not_movable';
		}
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $source_id ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $target_id ), ARRAY_A );
		if ( ! is_array( $source ) || ! is_array( $target ) || (int) $source['customer_id'] !== $customer_id ) {
			return 'not_found';
		}
		$now = time();
		if ( ! self::is_ending( $source, $now ) || ! self::is_target( $source, $target, $now ) ) {
			return 'not_allowed';
		}

		$active = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id,site_url,environment FROM %i WHERE license_id = %d AND status = %s', $tables['activations'], $target_id, 'active' ),
			ARRAY_A
		);
		if ( ! is_array( $active ) || '' !== $wpdb->last_error ) {
			return 'failed';
		}
		$site_key = SiteUrl::comparison_key( (string) $activation['site_url'] );
		$count    = 0;
		foreach ( $active as $row ) {
			if ( $row['environment'] !== $environment ) {
				continue;
			}
			if ( 'development' !== $environment && '' !== $site_key && SiteUrl::comparison_key( (string) $row['site_url'] ) === $site_key ) {
				// The site already runs on the target license (activated with the new key).
				return 'same_site';
			}
			++$count;
		}
		$limit = ActivationService::site_limit( $target_id, $target );
		if ( 'development' === $environment ) {
			$limit = ActivationService::development_site_limit( $target, $limit );
		}
		if ( $count >= $limit ) {
			return 'limit';
		}

		$children = array();
		if ( 'production' === $environment ) {
			$children = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( 'SELECT id FROM %i WHERE staging_parent_id = %d AND license_id = %d AND status = %s', $tables['activations'], $activation_id, $source_id, 'active' )
				)
			);
		}
		$ids          = array_merge( array( $activation_id ), $children );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated above; the values are passed as one array.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET license_id = %d WHERE license_id = %d AND status = %s AND id IN ({$placeholders})",
				array_merge( array( $tables['activations'], $target_id, $source_id, 'active' ), $ids )
			)
		);
		// phpcs:enable
		if ( false === $moved || (int) $moved < 1 ) {
			return 'failed';
		}

		$reason = AuditReason::encode(
			AuditReason::SITE_MOVED_BY_OWNER,
			array(
				'from' => $source_id,
				'to'   => $target_id,
			)
		);
		foreach ( $ids as $id ) {
			AuditLog::record(
				'site_transferred',
				'activation',
				$id,
				$reason,
				array( 'license_id' => $source_id ),
				array( 'license_id' => $target_id ),
				$customer_id
			);
		}
		$summary = array(
			'activation_id' => $activation_id,
			'site_url'      => (string) $activation['site_url'],
			'environment'   => $environment,
			'staging_moved' => count( $children ),
		);
		AuditLog::record( 'site_transferred_out', 'license', $source_id, $reason, $summary, array( 'license_id' => $target_id ), $customer_id );
		AuditLog::record( 'site_transferred_in', 'license', $target_id, $reason, array( 'license_id' => $source_id ), $summary, $customer_id );

		/**
		 * Fires after a customer moved a site activation to another of their licenses (A-22).
		 *
		 * @param int   $activation_id Moved activation.
		 * @param int   $source_id     License the site left.
		 * @param int   $target_id     License the site moved to.
		 * @param int[] $children      Staging activations that moved with it.
		 */
		do_action( 'wplc/activation_transferred', $activation_id, $source_id, $target_id, $children );

		return 'moved';
	}

	/**
	 * Release named locks.
	 *
	 * @param string[] $locks Lock names.
	 * @return void
	 */
	private static function release( array $locks ): void {
		global $wpdb;

		foreach ( array_reverse( $locks ) as $lock ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * End of a license for comparison: paid_until, or the largest integer without an end.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return int
	 */
	private static function end_of( array $license ): int {
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );

		return false === $paid_until ? PHP_INT_MAX : $paid_until;
	}
}
