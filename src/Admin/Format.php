<?php
/**
 * Shared presentation helpers for the admin screens and the customer account.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated status labels and site-timezone date handling.
 */
final class Format {

	/**
	 * Translated labels for license statuses.
	 *
	 * @return array<string,string>
	 */
	public static function license_status_labels(): array {
		return array(
			'pending'   => __( 'Pending', 'wp-license' ),
			'active'    => __( 'Active', 'wp-license' ),
			'past_due'  => __( 'Payment overdue', 'wp-license' ),
			'suspended' => __( 'Suspended', 'wp-license' ),
			'revoked'   => __( 'Revoked', 'wp-license' ),
			'expired'   => __( 'Expired', 'wp-license' ),
		);
	}

	/**
	 * Translated label for one license status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function license_status_label( string $status ): string {
		return self::label_from( self::license_status_labels(), $status );
	}

	/**
	 * Translated labels for site activation statuses.
	 *
	 * @return array<string,string>
	 */
	public static function activation_status_labels(): array {
		return array(
			'active'      => __( 'Active', 'wp-license' ),
			'deactivated' => __( 'Deactivated', 'wp-license' ),
		);
	}

	/**
	 * Translated label for one activation status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function activation_status_label( string $status ): string {
		return self::label_from( self::activation_status_labels(), $status );
	}

	/**
	 * Translated labels for renewal statuses.
	 *
	 * @return array<string,string>
	 */
	public static function renewal_status_labels(): array {
		return array(
			'scheduled'              => __( 'Scheduled', 'wp-license' ),
			'processing'             => __( 'Processing', 'wp-license' ),
			'paid'                   => __( 'Paid', 'wp-license' ),
			'succeeded'              => __( 'Paid', 'wp-license' ),
			'failed'                 => __( 'Failed', 'wp-license' ),
			'manual_action_required' => __( 'Customer action required', 'wp-license' ),
			'payment_review'         => __( 'Payment result unclear – check manually', 'wp-license' ),
			'cancelled'              => __( 'Cancelled', 'wp-license' ),
			'manual'                 => __( 'Awaiting customer payment', 'wp-license' ),
			'refunded'               => __( 'Refunded', 'wp-license' ),
			'expired'                => __( 'Expired', 'wp-license' ),
		);
	}

	/**
	 * Translated label for one renewal status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function renewal_status_label( string $status ): string {
		return self::label_from( self::renewal_status_labels(), $status );
	}

	/**
	 * Translated labels for activation environments.
	 *
	 * @return array<string,string>
	 */
	public static function environment_labels(): array {
		return array(
			'production'  => __( 'Live', 'wp-license' ),
			'staging'     => __( 'Staging', 'wp-license' ),
			'development' => __( 'Development', 'wp-license' ),
		);
	}

	/**
	 * Translated label for one activation environment.
	 *
	 * @param string $environment Stored environment.
	 * @return string
	 */
	public static function environment_label( string $environment ): string {
		return self::label_from( self::environment_labels(), $environment );
	}

	/**
	 * Renewal mode of a license: auto, manual or cancelling.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string One of auto, manual, cancelling.
	 */
	public static function renewal_mode( array $license ): string {
		if ( ! empty( $license['cancel_at_period_end'] ) ) {
			return 'cancelling';
		}
		return ! empty( $license['renewal_enabled'] ) ? 'auto' : 'manual';
	}

	/**
	 * Translated label for the renewal mode of a license.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	public static function renewal_mode_label( array $license ): string {
		$labels = array(
			'auto'       => __( 'Automatic', 'wp-license' ),
			'manual'     => __( 'Manual', 'wp-license' ),
			'cancelling' => __( 'Cancels at period end', 'wp-license' ),
		);
		return $labels[ self::renewal_mode( $license ) ];
	}

	/**
	 * Format a stored UTC datetime in the site timezone.
	 *
	 * @param mixed  $utc_datetime 'Y-m-d H:i:s' in UTC, or empty.
	 * @param bool   $with_time    Whether to append the time.
	 * @param string $empty        Text for a missing date.
	 * @return string
	 */
	public static function date( $utc_datetime, bool $with_time = false, string $empty = '—' ): string {
		$timestamp = self::utc_timestamp( $utc_datetime );
		if ( null === $timestamp ) {
			return $empty;
		}

		$format = (string) get_option( 'date_format', 'Y-m-d' );
		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format', 'H:i' );
		}
		$formatted = wp_date( $format, $timestamp );

		return is_string( $formatted ) ? $formatted : $empty;
	}

	/**
	 * Parse a stored UTC datetime.
	 *
	 * @param mixed $utc_datetime 'Y-m-d H:i:s' in UTC.
	 * @return int|null Unix timestamp, or null for empty/invalid input.
	 */
	public static function utc_timestamp( $utc_datetime ): ?int {
		if ( ! is_string( $utc_datetime ) || '' === $utc_datetime || 0 === strpos( $utc_datetime, '0000-00-00' ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc_datetime, new \DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof \DateTimeImmutable || $date->format( 'Y-m-d H:i:s' ) !== $utc_datetime ) {
			return null;
		}
		return $date->getTimestamp();
	}

	/**
	 * Convert a calendar date entered in the site timezone to the UTC end of that day.
	 *
	 * @param string        $date     Date as 'Y-m-d' (HTML date input).
	 * @param \DateTimeZone $timezone Site timezone.
	 * @return string 'Y-m-d H:i:s' in UTC, or an empty string for an invalid date.
	 */
	public static function local_date_to_utc_end_of_day( string $date, \DateTimeZone $timezone ): string {
		$date = trim( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		$local = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		if ( ! $local instanceof \DateTimeImmutable || $local->format( 'Y-m-d' ) !== $date ) {
			return '';
		}

		return $local
			->setTime( 23, 59, 59 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Calendar date in the site timezone for a stored UTC datetime (for date inputs).
	 *
	 * @param mixed         $utc_datetime 'Y-m-d H:i:s' in UTC.
	 * @param \DateTimeZone $timezone     Site timezone.
	 * @return string 'Y-m-d' or an empty string.
	 */
	public static function utc_to_local_date( $utc_datetime, \DateTimeZone $timezone ): string {
		$timestamp = self::utc_timestamp( $utc_datetime );
		if ( null === $timestamp ) {
			return '';
		}
		$date = new \DateTimeImmutable( '@' . $timestamp );
		return $date->setTimezone( $timezone )->format( 'Y-m-d' );
	}

	/**
	 * Look up a label; unknown values are made readable instead of shown raw.
	 *
	 * @param array<string,string> $labels Label map.
	 * @param string               $value  Stored value.
	 * @return string
	 */
	private static function label_from( array $labels, string $value ): string {
		if ( isset( $labels[ $value ] ) ) {
			return $labels[ $value ];
		}
		return '' === $value ? '—' : ucfirst( str_replace( '_', ' ', $value ) );
	}
}
