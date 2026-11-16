<?php
/**
 * Vendored by bin/vendor-client.php from WP License Captain src/Client/Messages.php
 * (source sha256 804145307faba490c00e18ec5fb0fb2de98a33655f9b306f15c4d2f55ebf0a76). Do not edit: change the server copy and re-vendor.
 *
 * Texts of the license client, by code.
 *
 * @package Vendor\Product\Premium\Client
 */

namespace Vendor\Product\Premium\Client;

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
		const VERSION = '2.1.0';

		/**
		 * Text domain literal used in this file.
		 */
		const SOURCE_DOMAIN = 'premium-addon-starter';

		/**
		 * Text domain of the add-on.
		 *
		 * @var string
		 */
		private $text_domain;

		/**
		 * Texts of this instance, built once per locale (memoized).
		 *
		 * @var array<string,string>|null
		 */
		private $texts;

		/**
		 * Locale the memoized texts were built for.
		 *
		 * @var string
		 */
		private $texts_locale = '';

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
			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
			$texts  = $this->texts;
			if ( null === $texts || $locale !== $this->texts_locale ) {
				$texts = self::texts();
				if ( ! function_exists( 'did_action' ) || did_action( 'init' ) ) {
					// Built once: the table has some hundred entries and every lookup used to rebuild it.
					// Before init the add-on's translations may not be loaded yet, so nothing is kept then.
					$this->texts        = $texts;
					$this->texts_locale = $locale;
				}
			}
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
				'error.generic'                       => __( 'The license server answered with the error "%s".', 'premium-addon-starter' ),
				'error.wplc_configuration_invalid'    => __( 'The license settings of this plugin are incomplete (server address, product key or signature key).', 'premium-addon-starter' ),
				'error.wplc_license_key_missing'      => __( 'Please enter your license key.', 'premium-addon-starter' ),
				'error.wplc_not_activated'            => __( 'This site has not been activated with a license key yet.', 'premium-addon-starter' ),
				'error.wplc_site_changed'             => __( 'The address of this site differs from the address the license was activated for. This looks like a copy of the site. Activate the license for this address.', 'premium-addon-starter' ),
				'error.wplc_credentials_not_saved'    => __( 'The activation data could not be stored in the database of this site. Check the database and try again.', 'premium-addon-starter' ),
				'error.wplc_credentials_not_removed'  => __( 'The activation data could not be deleted from the database of this site. Check the database and try again.', 'premium-addon-starter' ),
				'error.wplc_state_not_saved'          => __( 'The answer of the license server could not be stored in the database of this site. Check the database; the check is repeated automatically.', 'premium-addon-starter' ),
				'error.wplc_json_error'               => __( 'The request to the license server could not be prepared.', 'premium-addon-starter' ),
				'error.wplc_http_error'               => __( 'The license server answered with an unexpected error.', 'premium-addon-starter' ),
				'error.wplc_connection_failed'        => __( 'The license server could not be reached. Premium features keep working; the check is repeated automatically.', 'premium-addon-starter' ),
				'error.wplc_blocked_by_firewall'      => __( 'The request was answered by a firewall or security service instead of the license server. Please ask your host to allow outgoing requests to the license server.', 'premium-addon-starter' ),
				'error.wplc_response_invalid'         => __( 'The license server sent an answer this plugin cannot read.', 'premium-addon-starter' ),
				'error.wplc_signature_invalid'        => __( 'The answer of the license server could not be verified and was ignored.', 'premium-addon-starter' ),
				'error.wplc_server_signature_invalid' => __( 'The answer does not carry a valid signature of the license server and was ignored. Updating this plugin may solve this.', 'premium-addon-starter' ),
				'error.wplc_nonce_mismatch'           => __( 'The answer of the license server does not belong to this request and was ignored.', 'premium-addon-starter' ),
				'error.wplc_product_mismatch'         => __( 'The answer of the license server is for another product and was ignored.', 'premium-addon-starter' ),
				'error.wplc_response_stale'           => __( 'The answer of the license server is older than one already received and was ignored.', 'premium-addon-starter' ),
				'error.wplc_ed25519_unavailable'      => __( 'This web server cannot check the signature of the license server (the PHP sodium functions are missing). Premium features stay off until PHP provides them.', 'premium-addon-starter' ),
				// Errors of the license server (docs/API-V1.md, "Error codes").
				'error.invalid_activation'            => __( 'The activation request was rejected as incomplete. Check the license key.', 'premium-addon-starter' ),
				'error.invalid_key'                   => __( 'This license key is unknown. Please check it for typing errors.', 'premium-addon-starter' ),
				'error.product_mismatch'              => __( 'This license key belongs to another product.', 'premium-addon-starter' ),
				'error.license_expired'               => __( 'This license has expired. Renew it to activate this site.', 'premium-addon-starter' ),
				'error.license_suspended'             => __( 'This license is suspended. Please contact support.', 'premium-addon-starter' ),
				'error.license_revoked'               => __( 'This license has been revoked.', 'premium-addon-starter' ),
				'error.license_inactive'              => __( 'This license is not active.', 'premium-addon-starter' ),
				'error.license_not_found'             => __( 'The license of this activation no longer exists on the license server.', 'premium-addon-starter' ),
				'error.site_limit'                    => __( 'All sites of this license are in use. Release a site in your customer account or upgrade the license.', 'premium-addon-starter' ),
				'error.staging_limit'                 => __( 'The live site already has a staging site. Release it in your customer account first.', 'premium-addon-starter' ),
				'error.development_limit'             => __( 'Too many development sites are activated for this license. Release unused ones in your customer account.', 'premium-addon-starter' ),
				'error.development_site_invalid'      => __( 'A development activation is only possible on a local address (localhost, *.test, *.local).', 'premium-addon-starter' ),
				'error.staging_parent_required'       => __( 'A staging site must be linked to an activated live site of the same license.', 'premium-addon-starter' ),
				'error.staging_parent_invalid'        => __( 'The live site this staging site belongs to is not activated for this license.', 'premium-addon-starter' ),
				'error.staging_site_invalid'          => __( 'A staging activation cannot move to an address that is unrelated to its live site.', 'premium-addon-starter' ),
				'error.installation_conflict'         => __( 'The activation data of this site does not match the license server. Please activate again.', 'premium-addon-starter' ),
				'error.activation_conflict'           => __( 'The activation was interrupted by a parallel request. Please try again.', 'premium-addon-starter' ),
				'error.activation_deactivated'        => __( 'The activation of this site was released. Enter the license key to activate it again.', 'premium-addon-starter' ),
				'error.activation_not_found'          => __( 'The license server does not know the activation of this site. Enter the license key to activate it again.', 'premium-addon-starter' ),
				'error.installation_secret_invalid'   => __( 'The license server cannot read the activation of this site. Enter the license key to activate it again.', 'premium-addon-starter' ),
				'error.activation_mismatch'           => __( 'The stored activation does not belong to this installation.', 'premium-addon-starter' ),
				'error.site_mismatch'                 => __( 'This site is not the one the license was activated for.', 'premium-addon-starter' ),
				'error.site_move_refused'             => __( 'A live site activation cannot be moved to a staging, local or test address. Activate this copy as a staging site instead.', 'premium-addon-starter' ),
				'error.invalid_request_signature'     => __( 'The license server rejected the signature of this site.', 'premium-addon-starter' ),
				'error.request_expired'               => __( 'The clock of this server differs too much from the clock of the license server.', 'premium-addon-starter' ),
				'error.rate_limited'                  => __( 'Too many requests. Please try again in a few minutes.', 'premium-addon-starter' ),
				'error.retry_later'                   => __( 'The license is being changed right now. Please try again in a moment.', 'premium-addon-starter' ),
				// States shown in the status row and in notices.
				'state.unlicensed'                    => __( 'Not activated', 'premium-addon-starter' ),
				'state.active'                        => __( 'Active', 'premium-addon-starter' ),
				'state.grace'                         => __( 'Payment overdue (grace period)', 'premium-addon-starter' ),
				'state.expired'                       => __( 'Expired', 'premium-addon-starter' ),
				'state.suspended'                     => __( 'Suspended', 'premium-addon-starter' ),
				'state.revoked'                       => __( 'Revoked', 'premium-addon-starter' ),
				'state.inactive'                      => __( 'Not active', 'premium-addon-starter' ),
				'state.deactivated'                   => __( 'Released on the license server', 'premium-addon-starter' ),
				'state.site_changed'                  => __( 'Site address changed', 'premium-addon-starter' ),
				'state.stale'                         => __( 'Could not be verified for too long', 'premium-addon-starter' ),
				'state.misconfigured'                 => __( 'Not configured', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.unlicensed'                   => __( '%s: enter your license key to switch on the premium features and updates.', 'premium-addon-starter' ),
				/* translators: 1: name of the add-on, 2: date. */
				'notice.grace'                        => __( '%1$s: the renewal payment is overdue. The premium features stay available until %2$s.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.expired'                      => __( '%s: the license has expired. The premium features are switched off; your data is kept.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.suspended'                    => __( '%s: the license is suspended. Please contact support.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.revoked'                      => __( '%s: the license has been revoked. The premium features are switched off.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.inactive'                     => __( '%s: the license is not active. The premium features are switched off.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.deactivated'                  => __( '%s: the activation of this site was released. Enter the license key to activate it again.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.site_changed'                 => __( '%s: this site runs under another address than the one the license was activated for. Activate the license for this address.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.stale'                        => __( '%s: the license could not be verified for too long. The premium features are switched off until the license server can be reached again.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.misconfigured'                => __( '%s: the license settings of this plugin are incomplete.', 'premium-addon-starter' ),
				/* translators: 1: name of the add-on, 2: date. */
				'notice.offline'                      => __( '%1$s: the license server cannot be reached. The premium features stay available until %2$s; the check is repeated automatically.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.reactivate'                   => __( '%s: the license server no longer knows the activation of this site. Enter the license key again.', 'premium-addon-starter' ),
				/* translators: %s: name of the add-on. */
				'notice.update_uri_missing'           => __( '%s: the plugin header "Update URI" is missing, so updates cannot be delivered. Please report this to the vendor.', 'premium-addon-starter' ),
				'notice.open_settings'                => __( 'Open license settings', 'premium-addon-starter' ),
				// Settings screen.
				'ui.title'                            => __( 'License', 'premium-addon-starter' ),
				'ui.license_key'                      => __( 'License key', 'premium-addon-starter' ),
				'ui.activate'                         => __( 'Activate', 'premium-addon-starter' ),
				'ui.deactivate'                       => __( 'Remove license from this site', 'premium-addon-starter' ),
				'ui.check_now'                        => __( 'Check now', 'premium-addon-starter' ),
				'ui.activate_copy'                    => __( 'Activate this address', 'premium-addon-starter' ),
				'ui.move'                             => __( 'The site moved here: transfer the activation', 'premium-addon-starter' ),
				'ui.forget'                           => __( 'Remove the copied activation data from this site', 'premium-addon-starter' ),
				'ui.forget_local'                     => __( 'Remove the license data from this site only', 'premium-addon-starter' ),
				'ui.status'                           => __( 'Status', 'premium-addon-starter' ),
				'ui.paid_until'                       => __( 'Paid until', 'premium-addon-starter' ),
				'ui.access_until'                     => __( 'Premium features until', 'premium-addon-starter' ),
				'ui.sites'                            => __( 'Live sites in use', 'premium-addon-starter' ),
				'ui.environment'                      => __( 'Environment', 'premium-addon-starter' ),
				'ui.environment.production'           => __( 'Live site', 'premium-addon-starter' ),
				'ui.environment.staging'              => __( 'Staging site', 'premium-addon-starter' ),
				'ui.environment.development'          => __( 'Development site', 'premium-addon-starter' ),
				'ui.last_checked'                     => __( 'Last check', 'premium-addon-starter' ),
				'ui.last_error'                       => __( 'Last check failed', 'premium-addon-starter' ),
				'ui.never'                            => __( 'never', 'premium-addon-starter' ),
				'ui.no_end'                           => __( 'no end date', 'premium-addon-starter' ),
				'ui.key_hidden'                       => __( 'The license key is not stored on this site. Enter it again to change the license.', 'premium-addon-starter' ),
				'ui.saved.activated'                  => __( 'The license was activated for this site.', 'premium-addon-starter' ),
				'ui.saved.deactivated'                => __( 'The license was removed from this site.', 'premium-addon-starter' ),
				'ui.saved.deactivated_locally'        => __( 'The activation data was removed from this site. The activation of the original site was not changed.', 'premium-addon-starter' ),
				'ui.saved.forgotten'                  => __( 'The license data was removed from this site without asking the license server. If the server still lists this activation, release it in your customer account.', 'premium-addon-starter' ),
				'ui.saved.checked'                    => __( 'The license was checked.', 'premium-addon-starter' ),
				'ui.saved.moved'                      => __( 'The activation was transferred to this address.', 'premium-addon-starter' ),
				'ui.activated_address'                => __( 'Activated for', 'premium-addon-starter' ),
				'ui.current_address'                  => __( 'Address of this site', 'premium-addon-starter' ),
				/* translators: 1: address the license was activated for, 2: address of this site. */
				'ui.move_warning'                     => __( 'Only if the site really moved: transferring moves the activation from %1$s to %2$s. The site at %1$s then loses the license. For a staging or test copy, activate this address instead.', 'premium-addon-starter' ),
				'ui.move_not_live'                    => __( 'This site reports itself as a staging or development site.', 'premium-addon-starter' ),
				/* translators: %s: address the license was activated for. */
				'ui.move_confirm'                     => __( 'I confirm that the site moved and no longer runs at %s.', 'premium-addon-starter' ),
				'ui.move_unconfirmed'                 => __( 'Nothing was transferred. Confirm that the site moved before you transfer the activation.', 'premium-addon-starter' ),
				'ui.forbidden'                        => __( 'You are not allowed to manage the license of this plugin.', 'premium-addon-starter' ),
				'ui.utc'                              => __( 'UTC', 'premium-addon-starter' ),
				// Updater.
				'update.license_expired'              => __( 'Your license has expired. Renew it to receive this update.', 'premium-addon-starter' ),
				'update.license_suspended'            => __( 'Your license is suspended; updates are not available. Please contact support.', 'premium-addon-starter' ),
				'update.license_revoked'              => __( 'Your license has been revoked; updates are not available.', 'premium-addon-starter' ),
				'update.license_pending'              => __( 'Your license is not active yet; updates become available once it is.', 'premium-addon-starter' ),
				'update.license_inactive'             => __( 'Your license is not active; updates are not available.', 'premium-addon-starter' ),
				'update.not_activated'                => __( 'Enter your license key to receive updates.', 'premium-addon-starter' ),
				/* translators: 1: version that cannot be installed, 2: list of unmet requirements. */
				'update.blocked'                      => __( 'Version %1$s is available but cannot be installed on this site: %2$s.', 'premium-addon-starter' ),
				/* translators: %s: version number. */
				'update.requires_php'                 => __( 'PHP %s or newer is required', 'premium-addon-starter' ),
				/* translators: %s: version number. */
				'update.requires_wp'                  => __( 'WordPress %s or newer is required', 'premium-addon-starter' ),
				/* translators: %s: version number. */
				'update.requires_core'                => __( 'version %s or newer of the free base plugin is required', 'premium-addon-starter' ),
				'update.unavailable'                  => __( 'The update is no longer offered by the license server. Please check for updates again.', 'premium-addon-starter' ),
				/* translators: %s: reason. */
				'update.failed'                       => __( 'The update could not be prepared: %s', 'premium-addon-starter' ),
				'update.checksum'                     => __( 'The downloaded update does not match the checksum signed by the license server and was deleted.', 'premium-addon-starter' ),
				'update.wrong_product'                => __( 'The license server offered a package for another product.', 'premium-addon-starter' ),
				'update.folder'                       => __( 'The update package does not contain this plugin and was not installed.', 'premium-addon-starter' ),
				'update.insecure'                     => __( 'The update was offered over an insecure connection (not HTTPS) and was not downloaded.', 'premium-addon-starter' ),
				/* translators: %s: size limit in megabytes. */
				'update.too_large'                    => __( 'The update package is larger than %s MB and was not downloaded.', 'premium-addon-starter' ),
				'update.redirects'                    => __( 'The download of the update was redirected too often and was stopped.', 'premium-addon-starter' ),
				// Privacy policy suggestion.
				'privacy.title'                       => __( 'License check and updates', 'premium-addon-starter' ),
				/* translators: 1: name of the add-on, 2: host name of the license server. */
				'privacy.text'                        => __( '%1$s checks its license and looks for updates at %2$s. When the license is activated, once a day and when an update is checked or downloaded, this site sends: its address (URL), an installation ID created by this site, the product key of the plugin, the type of environment (live, staging or development site), the license key (once, on activation), for a staging site the installation ID of its live site, and the versions of the plugin, of the base plugin, of WordPress and of PHP. The license server sees the IP address of this web server. No data about visitors or customers of this site is sent. Legal basis: our legitimate interest in the use of licensed software and in security updates (Art. 6(1)(f) GDPR).', 'premium-addon-starter' ),
			);
		}
	}
}
