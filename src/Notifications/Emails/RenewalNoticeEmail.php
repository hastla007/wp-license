<?php
/**
 * Advance notice of an automatic renewal charge.
 *
 * @package WPLC
 */

namespace WPLC\Notifications\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WC_Email' ) ) {
	return;
}

/**
 * Announces amount and date of the next automatic charge and how to cancel.
 */
final class RenewalNoticeEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_renewal_notice',
			'wplc-renewal-notice.php',
			__( 'Upcoming automatic renewal', 'wp-license' ),
			self::description_text( \WPLC\Notifications\Notifications::notice_days() )
		);
	}

	/**
	 * Description in the email list, with the lead time in force (P2-M8).
	 *
	 * @param int $days Setting "Renewal notice lead days" after its filter.
	 * @return string
	 */
	public static function description_text( int $days ): string {
		$text = sprintf(
			/* translators: %d: number of days before the charge. */
			_n( 'Sent %d day before a license with automatic renewal is charged.', 'Sent %d days before a license with automatic renewal is charged.', $days, 'wp-license' ),
			$days
		);

		return $text . ' ' . __( 'Required: without this email no automatic charge is made.', 'wp-license' );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license renews on {charge_date}', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Upcoming automatic renewal', 'wp-license' );
	}
}
