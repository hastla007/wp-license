<?php
/**
 * "License issued" customer email.
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
 * Tells the customer that a license is ready and where to find the key.
 *
 * Email is not a confidential channel, so the full key is only included when
 * the shop owner explicitly enables it.
 */
final class LicenseIssuedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_issued',
			'wplc-license-issued.php',
			__( 'License issued', 'wp-license' ),
			__( 'Sent to the customer when a license has been created for a paid order.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license is ready', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license is ready', 'wp-license' );
	}

	/**
	 * Add the opt-in for sending the full license key.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['include_full_key'] = array(
			'title' => __( 'License key', 'wp-license' ),
			'type' => 'checkbox',
			'label' => __( 'Include the full license key in this email', 'wp-license' ),
			'description' => __( 'Off by default: email is not a secure channel. Without this option the email shows the key prefix and links to the customer account, where the key is displayed.', 'wp-license' ),
			'default' => 'no',
		);
	}

	/**
	 * Hand the full key to the template only when the shop owner opted in.
	 *
	 * @param bool $plain_text Whether the plain template is rendered.
	 * @return array<string,mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		$args = parent::template_args( $plain_text );
		if ( 'yes' === $this->get_option( 'include_full_key', 'no' ) && isset( $this->wplc_context['license_key'] ) && is_string( $this->wplc_context['license_key'] ) ) {
			$args['license_key'] = $this->wplc_context['license_key'];
		}
		return $args;
	}
}
