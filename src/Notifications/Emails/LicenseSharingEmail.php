<?php
/**
 * Base class of the co-user and transfer emails (B-139).
 *
 * @package WPLC
 */

namespace WPLC\Notifications\Emails;

use WPLC\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WC_Email' ) ) {
	return;
}

/**
 * Sent to an explicit address (an invited person, or a party of a transfer), not to
 * the license recipient. The templates receive the extra values in `$sharing`:
 * accept_url, expires (formatted date), inviter (display name of the owner), role
 * ('previous' or 'new' for the transfer confirmation) and other_party (display name).
 * The license key is never part of these emails.
 */
abstract class LicenseSharingEmail extends LicenseEmail {

	/**
	 * The address handed over by the trigger.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $context Trigger context with `recipient`.
	 * @return string
	 */
	protected function recipient_address( array $license, array $context ): string {
		unset( $license );
		$address = isset( $context['recipient'] ) && is_string( $context['recipient'] ) ? trim( $context['recipient'] ) : '';

		return is_email( $address ) ? $address : '';
	}

	/**
	 * Variables handed to the templates.
	 *
	 * @param bool $plain_text Whether the plain template is rendered.
	 * @return array<string,mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		$context = $this->wplc_context;
		$expires = isset( $context['expires_at'] ) ? strtotime( (string) $context['expires_at'] . ' UTC' ) : false;

		return array_merge(
			parent::template_args( $plain_text ),
			array(
				'sharing' => array(
					'accept_url'  => isset( $context['accept_url'] ) ? (string) $context['accept_url'] : '',
					'expires'     => false !== $expires && $expires > 0 ? Notifications::format_date( (int) $expires ) : '',
					'inviter'     => isset( $context['inviter'] ) ? (string) $context['inviter'] : '',
					'role'        => isset( $context['role'] ) ? (string) $context['role'] : '',
					'other_party' => isset( $context['other_party'] ) ? (string) $context['other_party'] : '',
				),
			)
		);
	}

	/**
	 * Sample values for the email preview.
	 *
	 * @return void
	 */
	public function set_preview_data(): void {
		parent::set_preview_data();
		$this->wplc_context = array(
			'accept_url'  => function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'wplc-licenses' ) : (string) home_url( '/' ),
			'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS ),
			'inviter'     => __( 'Sample Agency', 'wp-license' ),
			'role'        => 'new',
			'other_party' => __( 'Sample Agency', 'wp-license' ),
		);
	}
}
