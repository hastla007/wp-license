=== WP License Captain ===
Contributors: hastla007
Tags: woocommerce, license-manager, software-updates
Requires at least: 7.0
Requires PHP: 8.5
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce license server for premium WordPress products. Engineering preview, not production-ready.

== Description ==

WP License Captain turns a WooCommerce shop into a license server for premium WordPress plugins: it issues licenses from paid orders, tracks site activations, tells the product which features are licensed, and authorizes update downloads.

= Status: engineering preview =

**This version is not production-ready. Do not use it to sell licenses, charge renewals or distribute paid updates.**

The code has unit tests for its pure logic, but it has not been accepted in a real shop: payment flows have not passed a gateway sandbox, the emails and scheduled jobs have only run in an automated test shop (WordPress with WooCommerce 11.2.2), not in a live one. WordPress 7.0, WooCommerce 11.2.2 and PHP 8.5 are the declared minimums, MySQL 9.7 and MariaDB 12.3 the supported database servers; the CI integration matrix runs WordPress 7.0 with WooCommerce 11.2.2 and the latest WordPress with WooCommerce 11.3.0 on PHP 8.5 with MySQL 9.7 and MariaDB 12.3; other WooCommerce versions have never been run. That is why this readme declares neither "Tested up to" nor a WooCommerce version range.

= What is implemented =

* **Catalog** – map WooCommerce products and variations to license products and plans (billing interval, site limit, feature entitlements), with CSV export.
* **License issuance** – one license per purchased unit when an order is paid, idempotent per order item. Buying a license requires a customer account.
* **Lifecycle** – active, past due, suspended, expired, revoked; revocation on refund, suspension on chargeback, expiry by a daily job, 14 days of grace while an automatic renewal is pending or failing. Every change is written to an audit log.
* **Customer account** – a "Licenses" page under My Account with the license key, status, activated sites, site deactivation and cancellation at the end of the paid period.
* **Admin** – license list with audited status changes, diagnostics, WP-CLI commands for key and secret rotation.
* **REST API v1** – signed requests for activation, deactivation and entitlement checks, with replay protection, rate limits and a staging-site allowance.
* **Updates** – signed update metadata, short-lived single-use download grants, an allow-list of HTTPS package hosts and SHA-256 checksums.
* **Automatic renewal (opt-in)** – a gateway adapter contract, renewal scheduling with retries, and a Stripe card adapter that has not been validated in the Stripe sandbox.
* **Customer emails** – license issued, expiry reminder (30 and 7 days before the end), notice before an automatic charge (a license is only charged after this notice was sent), renewal payment failed, saved card no longer available, license expired, license renewed, cancellation confirmed, license suspended, license revoked. Managed under WooCommerce → Settings → Emails, with templates that a theme can override. The license key is not included in emails unless the shop owner enables it.
* **Privacy** – personal data exporter and eraser for the WordPress privacy tools, and a daily retention job for audit entries, processed gateway events and old installation records.
* **PHP client** – a client library for product plugins (`src/Client/`): activation, request signing, verification of the server signature with a pinned key, cached license status and a WordPress updater.

= Not ready =

* Automatic renewals are untested against a real payment provider, including card authentication (3-D Secure) recovery. PayPal renewals are deliberately not supported.
* Renewal pricing and tax handling for renewal orders are not final.
* The automatic-renewal opt-in exists in the classic checkout and, as an additional checkout field, in the block-based checkout. The block checkout has automated tests only; it was not tried in a browser.
* The product editor fields are added to the classic product editor; the block-based product editor is not supported.
* Secrets are configured with constants in `wp-config.php`; the settings page (WooCommerce → Licenses → Settings) covers package hosts, reminder days and the lead time of the renewal notice.
* Product plugins need their own integration of the client (license screen, feature gates, updater).
* German translations are shipped (also used for de_AT, de_CH and the other German variants); all other languages see the English source strings.
* Deleting the plugin keeps its database tables on purpose, because they are business records.

== Requirements ==

* WordPress 7.0 or later
* PHP 8.5 or later (below 8.5 the plugin shows a notice and does not load)
* MySQL 9.7 or later or MariaDB 12.3 or later (an older database server gets a warning in Site Health and on the diagnostics page; nothing is blocked)
* WooCommerce 11.2.2 or later, active (CI integration matrix: 11.2.2 and 11.3.0)
* HTTPS for the site, and a working WP-Cron (a system cron is recommended)

== Installation ==

Install it in a staging or test shop only.

1. Install and activate WooCommerce.
2. Define the secret constants described in chapter 2 of the vendor manual (`docs/manual/02-installation-and-keys.md`) in `wp-config.php` before issuing the first license, and keep them stable.
3. Upload the WP License Captain ZIP and activate the plugin.
4. Edit a WooCommerce product and map it to a license product and plan.
5. Place a test order and check the license under My Account → Licenses.
6. Exclude the API and account pages from page and CDN caches as described in chapter 9 of the vendor manual (`docs/manual/09-operations.md`).

== Frequently Asked Questions ==

= Where is the documentation? =

The vendor manual ships in the plugin folder under `docs/manual/`; start with `docs/manual/README.md`. It is in English; a German edition will follow.

= Can I use this in my live shop? =

No. It is an engineering preview. See "Status" above.

= Does the license email contain the license key? =

Not by default. Email is not a secure channel, so the email shows a short license reference and links to the customer account, where the key is displayed. A shop owner can enable the full key under WooCommerce → Settings → Emails → License issued.

= How do I change the emails? =

Subjects, headings and additional text are edited under WooCommerce → Settings → Emails. To change the layout, copy a template from the plugin's `templates/emails/` directory to `wp-license/emails/` in your theme.

= What data does the plugin store? =

Licenses linked to the customer account, activated site URLs with product, WordPress and PHP versions, renewal records, an audit log and a log of sent notifications. It never stores card numbers. See chapter 9 of the vendor manual (`docs/manual/09-operations.md`) for the inventory and the retention periods.

= How do I update the plugin? Does it contact other servers? =

WordPress shows when a new release is published: during its normal update check the plugin asks the GitHub API (`api.github.com`) for the latest release of `hastla007/wp-license`. No site data is sent. Updates are never installed automatically: download the release ZIP and its `.sha256`, compare the checksum and upload the ZIP (see chapter 12 of the vendor manual, `docs/manual/12-upgrading.md`). To switch the check off, add `define( 'WPLC_DISABLE_UPDATE_CHECK', true );` to `wp-config.php`.

= What happens to the data when I delete the plugin? =

Scheduled jobs and the plugin's working state are removed. The `wplc_` database tables, the settings and the signing key are kept, because they are business records. To remove them as well, add `define( 'WPLC_REMOVE_ALL_DATA', true );` to `wp-config.php` before deleting the plugin. Uploaded packages and order meta are never removed automatically.

== Changelog ==

= 0.2.0 =
* Engineering preview, second consolidation: client library with pinned server key and WordPress updater, signed "deactivated" answers, release registry with package checks, Stripe payment references and webhook secrets per mode and site, staging guard, retention and privacy tools, customer account forms on the front end, graded admin capabilities.
* An automatic renewal is only charged after its advance notice was sent; when the saved card is gone no other card is charged and the customer is told by email.
* Database schema 27. Not production-ready.

= 0.1.0 =
* Engineering preview: catalog mapping, license issuance and lifecycle, customer account page, admin tools, signed REST API, update delivery, opt-in renewal contract with an unvalidated Stripe adapter, customer emails, privacy exporter and eraser, data retention job, PHP client.
* Not production-ready.
