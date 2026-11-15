# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow semantic versioning once the first release is published.

## [Unreleased]

Consolidation of all earlier development branches into one code base. Preview: covered by unit, integration and end-to-end tests in a real WordPress/WooCommerce installation; not yet run against a Stripe test account or checked in a browser. See [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

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
- Diagnostics: package storage check (is the private ZIP directory publicly readable?), metric for renewals with an unknown payment result, settings changes and catalog CSV imports in the audit log.
- `WPLC_PACKAGE_STORAGE_DIR` puts uploaded ZIP files outside the web root.
- Product-plugin client: pinned Ed25519 server keys and helpers for a verified offline cache.
- End-to-end test run as a GitHub Actions job.
- Premium add-on starter (`examples/premium-addon-starter/`) for the product add-ons, tested end to end against the server.
- Load-test tooling (`tests/load/`) and indexes for large shops (database schema 15).
- Multisite integration tests and a CI cell for networks; release ZIP built on every pull request.
- Bulk actions and new filters on the license list; a read-only license audit log page.
- Customer account page "Sites" with all active sites across licenses (from PR #39).
- Responses carry `payload_signature_ed25519` and the access fields (`access`, `access_until`, `grace_until`, `activation_status`, `product_key`) read by the add-on client library.
- Admin page "Renewals to review" for renewals whose payment may have been taken without extending the license (unknown result, license changed during the charge, late manual payment), with book / mark as not paid / close actions.

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
- When the payment gateway gives no definite result for an automatic renewal, the license is left untouched, the customer gets no "payment failed" email, and the payment is checked again hourly with the same idempotency key; after 12 hours the renewal goes to admin review.
- A partial refund of a renewal order keeps the renewed term and is recorded; refunds adding up to the total take the period back.
- Audit reasons are stored as codes and shown in the admin's language.
- The activation limit per address counts only failed attempts, so a valid license key is not blocked by other people's mistakes.
- Site URLs: non-canonical IPv4 spellings are rejected, paths are normalized, internationalized domains work without the `intl` extension.
- The customer account shows the product name instead of the internal plan key.
- The "overdue renewals" alert counts only renewals that are really stuck.
- Classic checkout shows the automatic-renewal opt-in only while a payment method that can renew is selected.
- License emails show the bought variation instead of the internal plan key.
- Both parallel development lines are merged into one plugin (database schema 21). Where both solved the same problem, one behaviour remains:
  - Renewals that need an administrator have one status, `review_required`, with the "Renewals to review" screen. Rows of the former status `payment_review` are moved by schema step 21. An unknown payment result waits in `reconciling` for up to 12 hours.
  - Download grants and package stream links are single use on first presentation by default. The reuse window for interrupted downloads (`wplc_download_reuse_seconds`) is opt-in; HEAD requests never use a link up. Customer account downloads of local packages are sent in the response to the form, without a link.
  - The admin license search is posted and kept as an opaque token (`wplc_search`); neither the term nor the license ID appears in a URL.
  - Settings changes are audited as `settings_updated` with the names of all changed keys and the values of package hosts and reminder days.
  - The audit log keeps the site address of an activation (it is needed to follow address changes).
  - A suspended license whose term ended while it was suspended moves to `expired` when it is reactivated.
  - Site URLs with dot segments (`/./`, `/../`) are rejected instead of resolved.
  - The add-on starter uses the client library 2.0 (`LicenseClient`, `OptionStore`, `Messages`).

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
- Network deactivation and uninstall on multisite cleaned up only one site; tables of deleted sites could stay behind.
- After a manual change of a license's key ID the customer account showed a wrong license key.

### Security

- Secrets for license keys and installation credentials can be set as constants in `wp-config.php` and rotated.
- One-time, short-lived download links; packages are only delivered from private storage or trusted HTTPS hosts.
- A full license key typed into the admin search no longer appears in URLs, browser history or server logs.
- Product plugins can reject data that was not signed by the license server, even if the site's own database was changed.
- One-time download links are used up on first use, last 5 minutes and are bound to the installation.
- Rotating the installation encryption key also re-encrypts the response signing key; before, retiring the old key silently switched the server signature off. `wp wplc signing-key --prepare` publishes the next public key before it is used.
- Client library 2.0.1 (`src/Client`, re-vendored into the add-on starter), after an external audit:
  - The offline window is measured from the signed `server_time` inside the verified payload only; a stored `fetched_at` in the future voids the snapshot and triggers a new check, so editing the option no longer keeps features unlocked for good.
  - The server signature of the stored snapshot is checked on every read; the unsigned `verified_at` no longer skips it for 12 hours without ext-sodium. A PHP with no Ed25519 implementation at all accepts nothing (`wplc_ed25519_unavailable`).
  - Update packages are only downloaded over HTTPS (also after redirects; `http://` only for localhost) and are capped at 100 MB (filter `{prefix}_license_max_package_bytes`).
  - `deactivate()` keeps the credentials on an unsigned `site_mismatch` or `activation_not_found`; only a signed refusal removes them.
  - The filter `{prefix}_license_public_keys` (and the starter's `<prefix>_server_public_keys`) only works when `{PREFIX}_LICENSE_DEV` is defined, so another plugin cannot pin a key.
  - Uninstall cleans every site of a multisite in batches (was capped at 500); the privacy text names the product key and the environment that are sent.
  - Texts are built once per request; `is_activated()` and the other state checks no longer create a `WP_Error`.

### Known limitations

- Automatic renewal works only with the classic checkout and the WooCommerce Stripe gateway, and is not verified against Stripe yet.
- No automatic renewal with PayPal.
- Product plugins are not yet updated to this server version.

## [0.1.0] - Engineering preview

### Added

- WooCommerce catalog mapping and order-based license issuance.
- License lifecycle, activation limits, customer account page and administrative controls.
- Authenticated REST API, renewal adapter contract, premium update delivery and shared client class.
