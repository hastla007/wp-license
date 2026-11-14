<?php
/**
 * Texts of the license client, by code.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Messages', false ) ) {

	/**
	 * All texts the client shows to the administrator of the customer site.
	 *
	 * The license server answers with a `code` and a fixed English message for
	 * logs. The client never shows that message: it looks the code up here.
	 *
	 * The source strings carry the text domain literal of this file. The export
	 * script (`bin/export-client.php`) rewrites it to the text domain of the
	 * add-on, so that the add-on's own `.pot` contains the strings. A copy that
	 * was not rewritten still translates through the text domain passed to the
	 * constructor, provided the add-on ships translations for these strings.
	 */
	final class Messages {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.0.0';

		/**
		 * Text domain literal used in this file.
		 */
		const SOURCE_DOMAIN = 'wp-license';

		/**
		 * Text domain of the add-on.
		 *
		 * @var string
		 */
		private $text_domain;

		/**
		 * Constructor.
		 *
		 * @param string $text_domain Text domain of the add-on.
		 */
		public function __construct( string $text_domain = '' ) {
			$this->text_domain = '' === $text_domain ? self::SOURCE_DOMAIN : $text_domain;
		}

		/**
		 * Text for a code; an empty string when the code is unknown.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public function get( string $code ): string {
			$texts = self::texts();
			if ( ! isset( $texts[ $code ] ) ) {
				return '';
			}
			$text = $texts[ $code ];
			if ( self::SOURCE_DOMAIN !== $this->text_domain && function_exists( 'translate' ) ) {
				// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- the text domain of the add-on is only known at run time.
				$text = translate( $text, $this->text_domain );
			}

			return $text;
		}

		/**
		 * Text for an error of the license server or of the client.
		 *
		 * @param string $code Error code.
		 * @return string
		 */
		public function error( string $code ): string {
			$text = $this->get( 'error.' . $code );
			if ( '' !== $text ) {
				return $text;
			}

			return sprintf( $this->get( 'error.generic' ), $code );
		}

		/**
		 * All source texts.
		 *
		 * @return array<string,string>
		 */
		private static function texts(): array {
			return array(
				// Errors of the client itself.
				/* translators: %s: error code of the license server. */
				'error.generic'                       => __( 'The license server answered with the error "%s".', 'wp-license' ),
				'error.wplc_configuration_invalid'    => __( 'The license settings of this plugin are incomplete (server address, product key or signature key).', 'wp-license' ),
				'error.wplc_license_key_missing'      => __( 'Please enter your license key.', 'wp-license' ),
				'error.wplc_not_activated'            => __( 'This site has not been activated with a license key yet.', 'wp-license' ),
				'error.wplc_site_changed'             => __( 'The address of this site differs from the address the license was activated for. This looks like a copy of the site. Activate the license for this address.', 'wp-license' ),
				'error.wplc_credentials_not_saved'    => __( 'The activation data could not be stored in the database of this site. Check the database and try again.', 'wp-license' ),
				'error.wplc_json_error'               => __( 'The request to the license server could not be prepared.', 'wp-license' ),
				'error.wplc_http_error'               => __( 'The license server answered with an unexpected error.', 'wp-license' ),
				'error.wplc_connection_failed'        => __( 'The license server could not be reached. Premium features keep working; the check is repeated automatically.', 'wp-license' ),
				'error.wplc_blocked_by_firewall'      => __( 'The request was answered by a firewall or security service instead of the license server. Please ask your host to allow outgoing requests to the license server.', 'wp-license' ),
				'error.wplc_response_invalid'         => __( 'The license server sent an answer this plugin cannot read.', 'wp-license' ),
				'error.wplc_signature_invalid'        => __( 'The answer of the license server could not be verified and was ignored.', 'wp-license' ),
				'error.wplc_server_signature_invalid' => __( 'The answer does not carry a valid signature of the license server and was ignored. Updating this plugin may solve this.', 'wp-license' ),
				'error.wplc_nonce_mismatch'           => __( 'The answer of the license server does not belong to this request and was ignored.', 'wp-license' ),
				'error.wplc_product_mismatch'         => __( 'The answer of the license server is for another product and was ignored.', 'wp-license' ),
				'error.wplc_response_stale'           => __( 'The answer of the license server is older than one already received and was ignored.', 'wp-license' ),
				// Errors of the license server (docs/API-V1.md, "Error codes").
				'error.invalid_activation'            => __( 'The activation request was rejected as incomplete. Check the license key.', 'wp-license' ),
				'error.invalid_key'                   => __( 'This license key is unknown. Please check it for typing errors.', 'wp-license' ),
				'error.product_mismatch'              => __( 'This license key belongs to another product.', 'wp-license' ),
				'error.license_expired'               => __( 'This license has expired. Renew it to activate this site.', 'wp-license' ),
				'error.license_suspended'             => __( 'This license is suspended. Please contact support.', 'wp-license' ),
				'error.license_revoked'               => __( 'This license has been revoked.', 'wp-license' ),
				'error.license_inactive'              => __( 'This license is not active.', 'wp-license' ),
				'error.license_not_found'             => __( 'The license of this activation no longer exists on the license server.', 'wp-license' ),
				'error.site_limit'                    => __( 'All sites of this license are in use. Release a site in your customer account or upgrade the license.', 'wp-license' ),
				'error.staging_limit'                 => __( 'The live site already has a staging site. Release it in your customer account first.', 'wp-license' ),
				'error.development_limit'             => __( 'Too many development sites are activated for this license. Release unused ones in your customer account.', 'wp-license' ),
				'error.development_site_invalid'      => __( 'A development activation is only possible on a local address (localhost, *.test, *.local).', 'wp-license' ),
				'error.staging_parent_required'       => __( 'A staging site must be linked to an activated live site of the same license.', 'wp-license' ),
				'error.staging_parent_invalid'        => __( 'The live site this staging site belongs to is not activated for this license.', 'wp-license' ),
				'error.staging_site_invalid'          => __( 'A staging activation cannot move to an address that is unrelated to its live site.', 'wp-license' ),
				'error.installation_conflict'         => __( 'The activation data of this site does not match the license server. Please activate again.', 'wp-license' ),
				'error.activation_conflict'           => __( 'The activation was interrupted by a parallel request. Please try again.', 'wp-license' ),
				'error.activation_deactivated'        => __( 'The activation of this site was released. Enter the license key to activate it again.', 'wp-license' ),
				'error.activation_not_found'          => __( 'The license server does not know the activation of this site. Enter the license key to activate it again.', 'wp-license' ),
				'error.installation_secret_invalid'   => __( 'The license server cannot read the activation of this site. Enter the license key to activate it again.', 'wp-license' ),
				'error.activation_mismatch'           => __( 'The stored activation does not belong to this installation.', 'wp-license' ),
				'error.site_mismatch'                 => __( 'This site is not the one the license was activated for.', 'wp-license' ),
				'error.invalid_request_signature'     => __( 'The license server rejected the signature of this site.', 'wp-license' ),
				'error.request_expired'               => __( 'The clock of this server differs too much from the clock of the license server.', 'wp-license' ),
				'error.rate_limited'                  => __( 'Too many requests. Please try again in a few minutes.', 'wp-license' ),
				'error.retry_later'                   => __( 'The license is being changed right now. Please try again in a moment.', 'wp-license' ),
				// States shown in the status row and in notices.
				'state.unlicensed'                    => __( 'Not activated', 'wp-license' ),
				'state.active'                        => __( 'Active', 'wp-license' ),
				'state.grace'                         => __( 'Payment overdue (grace period)', 'wp-license' ),
				'state.expired'                       => __( 'Expired', 'wp-license' ),
				'state.suspended'                     => __( 'Suspended', 'wp-license' ),
				'state.revoked'                       => __( 'Revoked', 'wp-license' ),
				'state.inactive'                      => __( 'Not active', 'wp-license' ),
				'state.deactivated'                   => __( 'Released on the license server', 'wp-license' ),
				'state.site_changed'                  => __( 'Site address changed', 'wp-license' ),
				'state.stale'                         => __( 'Could not be verified for too long', 'wp-license' ),
				'state.misconfigured'                 => __( 'Not configured', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.unlicensed'                   => __( '%s: enter your license key to switch on the premium features and updates.', 'wp-license' ),
				/* translators: 1: name of the add-on, 2: date. */
				'notice.grace'                        => __( '%1$s: the renewal payment is overdue. The premium features stay available until %2$s.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.expired'                      => __( '%s: the license has expired. The premium features are switched off; your data is kept.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.suspended'                    => __( '%s: the license is suspended. Please contact support.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.revoked'                      => __( '%s: the license has been revoked. The premium features are switched off.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.inactive'                     => __( '%s: the license is not active. The premium features are switched off.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.deactivated'                  => __( '%s: the activation of this site was released. Enter the license key to activate it again.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.site_changed'                 => __( '%s: this site runs under another address than the one the license was activated for. Activate the license for this address.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.stale'                        => __( '%s: the license could not be verified for too long. The premium features are switched off until the license server can be reached again.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.misconfigured'                => __( '%s: the license settings of this plugin are incomplete.', 'wp-license' ),
				/* translators: 1: name of the add-on, 2: date. */
				'notice.offline'                      => __( '%1$s: the license server cannot be reached. The premium features stay available until %2$s; the check is repeated automatically.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.reactivate'                   => __( '%s: the license server no longer knows the activation of this site. Enter the license key again.', 'wp-license' ),
				/* translators: %s: name of the add-on. */
				'notice.update_uri_missing'           => __( '%s: the plugin header "Update URI" is missing, so updates cannot be delivered. Please report this to the vendor.', 'wp-license' ),
				'notice.open_settings'                => __( 'Open license settings', 'wp-license' ),
				// Settings screen.
				'ui.title'                            => __( 'License', 'wp-license' ),
				'ui.license_key'                      => __( 'License key', 'wp-license' ),
				'ui.activate'                         => __( 'Activate', 'wp-license' ),
				'ui.deactivate'                       => __( 'Remove license from this site', 'wp-license' ),
				'ui.check_now'                        => __( 'Check now', 'wp-license' ),
				'ui.activate_copy'                    => __( 'Activate this address', 'wp-license' ),
				'ui.move'                             => __( 'The site moved here: transfer the activation', 'wp-license' ),
				'ui.forget'                           => __( 'Remove the copied activation data from this site', 'wp-license' ),
				'ui.status'                           => __( 'Status', 'wp-license' ),
				'ui.paid_until'                       => __( 'Paid until', 'wp-license' ),
				'ui.access_until'                     => __( 'Premium features until', 'wp-license' ),
				'ui.sites'                            => __( 'Live sites in use', 'wp-license' ),
				'ui.environment'                      => __( 'Environment', 'wp-license' ),
				'ui.environment.production'           => __( 'Live site', 'wp-license' ),
				'ui.environment.staging'              => __( 'Staging site', 'wp-license' ),
				'ui.environment.development'          => __( 'Development site', 'wp-license' ),
				'ui.last_checked'                     => __( 'Last check', 'wp-license' ),
				'ui.last_error'                       => __( 'Last check failed', 'wp-license' ),
				'ui.never'                            => __( 'never', 'wp-license' ),
				'ui.no_end'                           => __( 'no end date', 'wp-license' ),
				'ui.key_hidden'                       => __( 'The license key is not stored on this site. Enter it again to change the license.', 'wp-license' ),
				'ui.saved.activated'                  => __( 'The license was activated for this site.', 'wp-license' ),
				'ui.saved.deactivated'                => __( 'The license was removed from this site.', 'wp-license' ),
				'ui.saved.deactivated_locally'        => __( 'The activation data was removed from this site. The activation of the original site was not changed.', 'wp-license' ),
				'ui.saved.checked'                    => __( 'The license was checked.', 'wp-license' ),
				'ui.saved.moved'                      => __( 'The activation was transferred to this address.', 'wp-license' ),
				'ui.forbidden'                        => __( 'You are not allowed to manage the license of this plugin.', 'wp-license' ),
				'ui.utc'                              => __( 'UTC', 'wp-license' ),
				// Updater.
				'update.license_expired'              => __( 'Your license has expired. Renew it to receive this update.', 'wp-license' ),
				'update.license_suspended'            => __( 'Your license is suspended; updates are not available. Please contact support.', 'wp-license' ),
				'update.license_revoked'              => __( 'Your license has been revoked; updates are not available.', 'wp-license' ),
				'update.license_pending'              => __( 'Your license is not active yet; updates become available once it is.', 'wp-license' ),
				'update.license_inactive'             => __( 'Your license is not active; updates are not available.', 'wp-license' ),
				'update.not_activated'                => __( 'Enter your license key to receive updates.', 'wp-license' ),
				/* translators: 1: version that cannot be installed, 2: list of unmet requirements. */
				'update.blocked'                      => __( 'Version %1$s is available but cannot be installed on this site: %2$s.', 'wp-license' ),
				/* translators: %s: version number. */
				'update.requires_php'                 => __( 'PHP %s or newer is required', 'wp-license' ),
				/* translators: %s: version number. */
				'update.requires_wp'                  => __( 'WordPress %s or newer is required', 'wp-license' ),
				/* translators: %s: version number. */
				'update.requires_core'                => __( 'version %s or newer of the free base plugin is required', 'wp-license' ),
				'update.unavailable'                  => __( 'The update is no longer offered by the license server. Please check for updates again.', 'wp-license' ),
				/* translators: %s: reason. */
				'update.failed'                       => __( 'The update could not be prepared: %s', 'wp-license' ),
				'update.checksum'                     => __( 'The downloaded update does not match the checksum signed by the license server and was deleted.', 'wp-license' ),
				'update.wrong_product'                => __( 'The license server offered a package for another product.', 'wp-license' ),
				'update.folder'                       => __( 'The update package does not contain this plugin and was not installed.', 'wp-license' ),
				// Privacy policy suggestion.
				'privacy.title'                       => __( 'License check and updates', 'wp-license' ),
				/* translators: 1: name of the add-on, 2: host name of the license server. */
				'privacy.text'                        => __( '%1$s checks its license and looks for updates at %2$s. When the license is activated, once a day and when an update is checked or downloaded, this site sends: its address (URL), an installation ID created by this site, the license key (once, on activation), and the versions of the plugin, of WordPress and of PHP. The license server sees the IP address of this web server. No data about visitors or customers of this site is sent.', 'wp-license' ),
			);
		}
	}
}
