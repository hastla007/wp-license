<?php
/**
 * Base class of the customer license emails.
 *
 * @package WPLC
 */

namespace WPLC\Notifications\Emails;

use WPLC\Notifications\Notifications;
use WPLC\Notifications\Placeholders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WC_Email only exists once WooCommerce has loaded its mailer (never in unit tests).
if ( ! class_exists( '\\WC_Email' ) ) {
	return;
}

/**
 * Shared rendering and sending for all license emails.
 *
 * Shop owners manage these under WooCommerce → Settings → Emails. Templates
 * can be overridden in the theme under wp-license/emails/.
 */
abstract class LicenseEmail extends \WC_Email {

	/**
	 * Display values of the license the current email is about.
	 *
	 * @var array<string,string>
	 */
	protected $wplc_view = array();

	/**
	 * Extra data handed over by the trigger.
	 *
	 * @var array<string,mixed>
	 */
	protected $wplc_context = array();

	/**
	 * Set the properties every license email shares.
	 *
	 * @param string $id          Email ID, also used for the option name.
	 * @param string $template    Template file name without directory.
	 * @param string $title       Title in the settings list.
	 * @param string $description Description in the settings list.
	 */
	public function __construct( string $id, string $template, string $title, string $description ) {
		$this->id             = $id;
		$this->customer_email = true;
		$this->title          = $title;
		$this->description    = $description;
		$this->template_base  = WPLC_PATH . 'templates/';
		$this->template_html  = 'emails/' . $template;
		$this->template_plain = 'emails/plain/' . $template;
		$this->placeholders   = Placeholders::map( array() );

		parent::__construct();
	}

	/**
	 * Send the email to the license owner in the owner's language.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $context Extra data for the template.
	 * @return bool True when the mailer accepted the message.
	 */
	public function trigger( array $license, array $context = array() ): bool {
		$user = get_userdata( (int) ( $license['customer_id'] ?? 0 ) );
		if ( ! $user || ! is_email( $user->user_email ) || ! $this->is_enabled() ) {
			return false;
		}

		$switched = function_exists( 'switch_to_locale' ) && function_exists( 'get_user_locale' )
			? (bool) switch_to_locale( get_user_locale( (int) $user->ID ) )
			: false;

		try {
			$this->object       = $license;
			$this->recipient    = (string) $user->user_email;
			$this->wplc_context = $context;
			$this->wplc_view    = Notifications::view( $license, $context );
			$this->placeholders = array_merge( $this->placeholders, Placeholders::map( $this->wplc_view ) );

			$recipient = $this->get_recipient();
			if ( ! $recipient ) {
				return false;
			}

			return (bool) $this->send( $recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		} finally {
			// Never keep a plaintext key or customer data on the long-lived mailer object.
			$this->wplc_context = array();
			$this->wplc_view    = array();
			$this->object       = false;
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Variables handed to the templates.
	 *
	 * @param bool $plain_text Whether the plain template is rendered.
	 * @return array<string,mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		return array(
			'view' => $this->wplc_view,
			'license_key' => '',
			'email_heading' => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin' => false,
			'plain_text' => $plain_text,
			'email' => $this,
		);
	}

	/**
	 * Render the HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'wp-license/', $this->template_base );
	}

	/**
	 * Render the plain-text body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'wp-license/', $this->template_base );
	}

	/**
	 * Default text below the main content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return '';
	}
}
