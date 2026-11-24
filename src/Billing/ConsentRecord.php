<?php
/**
 * Durable evidence rows in the consents table.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes evidence about the recurring charge of a license into `wplc_consents`.
 *
 * The table holds, per license, every statement the recurring charge rests on. The column
 * `source` tells them apart:
 *
 * - `classic`, `block`: consent to automatic renewal in the checkout (LicenseIssuer).
 * - `upgrade`: consent to the new automatic amount when upgrading the plan (PlanUpgrade).
 * - `price_notice`: the notice of a higher renewal price was sent (PriceChange); `consent_at`
 *   is the time the mailer accepted it and the text describes the change.
 *
 * All rows follow the retention rule `consents` (ten years from the end of the year in which
 * the license ended) and are exported with the license.
 */
final class ConsentRecord {

	/**
	 * Source of the consent to a new automatic amount given with a plan upgrade.
	 */
	public const SOURCE_UPGRADE = 'upgrade';

	/**
	 * Source of the record of a sent price-change notice.
	 */
	public const SOURCE_PRICE_NOTICE = 'price_notice';

	/**
	 * Evidence of a consent given in the current request: wording, hash, language, source, time.
	 *
	 * The same shape as RecurringConsent::evidence() plus `at`.
	 *
	 * @param string $text   Wording the customer agreed to.
	 * @param string $source Source key.
	 * @param int    $now    Unix timestamp.
	 * @return array{text:string,hash:string,locale:string,source:string,at:string}
	 */
	public static function evidence( string $text, string $source, int $now ): array {
		return array(
			'text' => $text,
			'hash' => hash( 'sha256', $text ),
			'locale' => function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale(),
			'source' => $source,
			'at' => gmdate( 'Y-m-d H:i:s', $now ),
		);
	}

	/**
	 * Insert one evidence row. Repeating a call with the same record writes nothing.
	 *
	 * @param array<string,mixed> $row license_id, order_id, customer_id, consent_at, source, locale, text,
	 *                                 amount_minor, currency_decimals, currency, billing_interval.
	 * @return bool True when the row exists after the call.
	 */
	public static function store( array $row ): bool {
		global $wpdb;

		$license_id = (int) ( $row['license_id'] ?? 0 );
		$at         = (string) ( $row['consent_at'] ?? '' );
		$when       = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $at, new \DateTimeZone( 'UTC' ) );
		if ( $license_id < 1 || ! $when ) {
			return false;
		}
		$text   = (string) ( $row['text'] ?? '' );
		$source = substr( sanitize_key( (string) ( $row['source'] ?? '' ) ), 0, 20 );
		$tables = Schema::table_names();
		// The table allows one row per license and second (UNIQUE KEY license_consent). The same record
		// again is a repeated call; a row of another kind in the same second moves this one by a second.
		$at = '';
		for ( $shift = 0; $shift < 5; $shift++ ) {
			$candidate = $when->modify( '+' . $shift . ' seconds' )->format( 'Y-m-d H:i:s' );
			$existing  = $wpdb->get_row( $wpdb->prepare( 'SELECT source, text_hash FROM %i WHERE license_id = %d AND consent_at = %s LIMIT 1', $tables['consents'], $license_id, $candidate ), ARRAY_A );
			if ( ! is_array( $existing ) ) {
				$at = $candidate;
				break;
			}
			if ( $source === (string) $existing['source'] && hash( 'sha256', $text ) === (string) $existing['text_hash'] ) {
				return true;
			}
		}
		if ( '' === $at ) {
			return false;
		}
		$inserted = $wpdb->insert(
			$tables['consents'],
			array(
				'license_id' => $license_id,
				'order_id' => max( 0, (int) ( $row['order_id'] ?? 0 ) ),
				'customer_id' => max( 0, (int) ( $row['customer_id'] ?? 0 ) ),
				'consent_at' => $at,
				'source' => $source,
				'locale' => substr( sanitize_text_field( (string) ( $row['locale'] ?? '' ) ), 0, 20 ),
				'text_hash' => hash( 'sha256', $text ),
				'consent_text' => $text,
				'amount_minor' => max( 0, (int) ( $row['amount_minor'] ?? 0 ) ),
				'currency_decimals' => max( 0, min( 6, (int) ( $row['currency_decimals'] ?? 2 ) ) ),
				'currency' => substr( strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? '' ) ) ), 0, 3 ),
				'billing_interval' => substr( sanitize_key( (string) ( $row['billing_interval'] ?? 'year' ) ), 0, 10 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			\WPLC\Infrastructure\Log::write( 'error', 'Consent evidence could not be stored (database error).', array( 'component' => 'consents', 'license_id' => $license_id ) );
			return false;
		}

		return true;
	}
}
