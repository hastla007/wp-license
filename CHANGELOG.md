# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow semantic versioning once the first release is published.

## [Unreleased]

Consolidation of all earlier development branches into one code base. Preview: covered by unit tests and lint, not yet run in a real shop or against a Stripe test account. See [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

### Added

- Customer account → **Sites**: every site the customer activated, across all licenses and products, in one table with product, environment, installed and latest version, last contact and license state. Flags what needs attention (license not active, payment overdue, license ends without renewal, update available, no contact for 14 days), with search, filters, CSV export and the existing site deactivation. Replaces the entitlements `voucher.central_license_overview` and `pass.client_license_management`.
- Manual renewal: customers can renew a license in their account with any payment method, from 30 days before to 30 days after the end date. The license key and site activations stay the same. An expired license becomes active again when renewed inside this period.
- Customer emails: license issued, reminder before the license ends, notice before an automatic renewal is charged, payment failed, license renewed, license expired.
- Customer account: copy button for the license key, translated status names, dates in the shop's format and timezone, confirmation before deactivating a site, cancel and resume automatic renewal, download of the latest plugin version.
- Admin: license list with sorting, filters and search by full license key, customer email, license ID or product; license detail screen with activations, renewals, entitlements and audit history.
- Admin actions: add a license manually, change the term of a license, reactivate an expired license, release a site activation. Each requires a reason and is recorded in the audit log.
- Release management: upload plugin ZIP files to private storage or register a package URL, publish and withdraw versions. Licensed sites are offered the highest published version.
- Settings page with the status of the Stripe webhook secret, key configuration, checkout type and trusted package hosts.
- Admin warning while license keys still depend on the WordPress security salts.
- Stripe disputes suspend the affected license.
- Refunding a renewal order takes back the renewed period.
- Automatic deletion of old audit, event and activation records after configurable retention periods.
- German translation (formal), translation template and `bin/make-pot.sh`.

### Changed

- Entitlement catalog: removed `voucher.central_license_overview`, `pass.client_license_management` (now the Sites page of the customer account), `pass.team_assignment` and `affiliate.bonuses` (free in the core plugins). Licenses that stored one of them keep the value; see `docs/ENTITLEMENTS.md`, "Removed keys".
- Entitlement catalog: added `affiliate.automated_payouts` (automatic payouts through PayPal in Affiliate Captain Pro).
- Automatic renewal is now an opt-in at checkout and no longer a condition for buying. Without it the license runs for the paid period and can be renewed manually.
- Buying a license requires a customer account.
- Failed automatic renewals are retried on day 1, 3 and 7; the license keeps working for 14 days after the end date before it expires.
- The price of an automatic renewal is the product's regular price at purchase, not a temporary sale price.
- Deactivating a live site also deactivates its staging site.
- Activating a site that is already activated on the same license replaces the old activation instead of using another site slot.
- Server responses to product plugins are signed over the exact transmitted data and bound to the request.
- Rejected admin actions return to the screen with an explanation instead of an error page.
- The plugin loads without Composer.

### Fixed

- Tax was added twice to renewal orders in shops that enter prices including tax.
- Every retry of a failed renewal payment received the first decline again from Stripe.
- A renewal interrupted between charging and booking stayed unfinished; such renewals are now picked up and completed without charging twice.
- Suspended and expired licenses could still be charged.
- A full refund without line items did not revoke the license.
- The Stripe webhook rejected ordinary shop payments, which could lead Stripe to disable the endpoint.
- The 14-day grace period after a failed renewal had no effect.
- The activation limit counted all customers behind a proxy or CDN as one address.

### Security

- Secrets for license keys and installation credentials can be set as constants in `wp-config.php` and rotated.
- One-time, short-lived download links; packages are only delivered from private storage or trusted HTTPS hosts.

### Known limitations

- Automatic renewal works only with the classic checkout and the WooCommerce Stripe gateway, and is not verified against Stripe yet.
- No automatic renewal with PayPal.
- Product plugins are not yet updated to this server version.

## [0.1.0] - Engineering preview

### Added

- WooCommerce catalog mapping and order-based license issuance.
- License lifecycle, activation limits, customer account page and administrative controls.
- Authenticated REST API, renewal adapter contract, premium update delivery and shared client class.
