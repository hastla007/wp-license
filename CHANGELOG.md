# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow semantic versioning once the first release is published.

## [Unreleased]

Consolidation of all earlier development branches into one code base. Preview: covered by unit, integration and end-to-end tests in a real WordPress/WooCommerce installation; not yet run against a Stripe test account or checked in a browser. See [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

### Added

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
- Diagnostics: package storage check (is the private ZIP directory publicly readable?), metric for renewals with an unknown payment result, settings changes and catalog CSV imports in the audit log.
- `WPLC_PACKAGE_STORAGE_DIR` puts uploaded ZIP files outside the web root.
- Product-plugin client: pinned Ed25519 server keys and helpers for a verified offline cache.
- End-to-end test run as a GitHub Actions job.
- Admin page "Renewals to review" for renewals whose payment may have been taken without extending the license (unknown result, license changed during the charge, late manual payment), with book / mark as not paid / close actions.

### Changed

- Automatic renewal is now an opt-in at checkout and no longer a condition for buying. Without it the license runs for the paid period and can be renewed manually.
- Buying a license requires a customer account.
- Failed automatic renewals are retried on day 1, 3 and 7; the license keeps working for 14 days after the end date before it expires.
- The price of an automatic renewal is the product's regular price at purchase, not a temporary sale price.
- Deactivating a live site also deactivates its staging site.
- Activating a site that is already activated on the same license replaces the old activation instead of using another site slot.
- Server responses to product plugins are signed over the exact transmitted data and bound to the request.
- Rejected admin actions return to the screen with an explanation instead of an error page.
- The plugin loads without Composer.
- When the payment gateway gives no definite result for an automatic renewal, the license is left untouched, the customer gets no "payment failed" email, and the payment is checked again hourly with the same idempotency key; after 12 hours the renewal goes to admin review.
- A partial refund of a renewal order keeps the renewed term and is recorded; refunds adding up to the total take the period back.
- Audit reasons are stored as codes and shown in the admin's language.
- The activation limit per address counts only failed attempts, so a valid license key is not blocked by other people's mistakes.
- Site URLs: non-canonical IPv4 spellings are rejected, paths are normalized, internationalized domains work without the `intl` extension.
- The customer account shows the product name instead of the internal plan key.
- The "overdue renewals" alert counts only renewals that are really stuck.
- Classic checkout shows the automatic-renewal opt-in only while a payment method that can renew is selected.
- License emails show the bought variation instead of the internal plan key.

### Fixed

- Tax was added twice to renewal orders in shops that enter prices including tax.
- Every retry of a failed renewal payment received the first decline again from Stripe.
- A renewal interrupted between charging and booking stayed unfinished; such renewals are now picked up and completed without charging twice.
- Suspended and expired licenses could still be charged.
- A full refund without line items did not revoke the license.
- The Stripe webhook rejected ordinary shop payments, which could lead Stripe to disable the endpoint.
- The 14-day grace period after a failed renewal had no effect.
- The activation limit counted all customers behind a proxy or CDN as one address.
- Licenses for a variation of a variable product mapped only on the parent product renewed at the lowest variation price. A license now stores the regular price of the bought variation and renews at it (database schema 13).
- The sender note setting was saved but never shown in emails.
- The reminder log table was created outside the versioned schema (now schema 12).
- A refused package URL used up the one-time download link.

### Security

- Secrets for license keys and installation credentials can be set as constants in `wp-config.php` and rotated.
- One-time, short-lived download links; packages are only delivered from private storage or trusted HTTPS hosts.
- A full license key typed into the admin search no longer appears in URLs, browser history or server logs.
- Product plugins can reject data that was not signed by the license server, even if the site's own database was changed.

### Known limitations

- Automatic renewal works only with the classic checkout and the WooCommerce Stripe gateway, and is not verified against Stripe yet.
- No automatic renewal with PayPal.
- Product plugins are not yet updated to this server version.

## [0.1.0] - Engineering preview

### Added

- WooCommerce catalog mapping and order-based license issuance.
- License lifecycle, activation limits, customer account page and administrative controls.
- Authenticated REST API, renewal adapter contract, premium update delivery and shared client class.
