# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow semantic versioning once the first release is published.

## [Unreleased]

Consolidation of all earlier development branches into one code base. Preview: covered by unit, integration and end-to-end tests in a real WordPress/WooCommerce installation; not yet run against a Stripe test account or checked in a browser. See [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

### Added (16 Nov 2026, project products, client 2.1.1 and the client package)

- **Project products (PK-01 to PK-05, decision 17).** Catalog files whose prefix contains an underscore (`learnboating_form`, `boatingschool_form`) live next to `learnboating`; the product editor maps groups only through the catalog, without the old substring fallback. A connector package with `wplc-catalog.json` can be the first release of a product. A private WooCommerce product with a plan can be licensed by hand, but is not offered in the pricing table, other customers' accounts or the cart. New `wp wplc license issue` issues a license by hand (it never prints the key). A connector and a Pro add-on on the same site keep separate options, activations and site limits. Integration tests: `ProjectProductIntegrationTest`, `ProductIsolationIntegrationTest`, `ProjectConnectorClientIntegrationTest`.
- **Expiry reminders for products that cannot be bought** no longer say "Renew now"; they ask the customer to get in touch before the end date (`Catalog::is_for_sale()`, view key `sold_in_shop`).
- **Client library 2.1.1 (AL-4):** a Site Health test "License check" per add-on (good while the last successful check is at most 36 hours old; recommended when a check failed, is overdue or the offline period runs low; critical when the license is no longer confirmed or was released). Without key or secret.
- **Client library package (PROD-08).** `bin/package-client.php` builds `license-client-<version>.zip` with a checksum: the library, the export script, a README with the API v1 compatibility promise, its own changelog and a contract test (`bin/client-contract-test.php`) that vendors run against their server. `bin/build-zip.sh` builds it and the release workflow attaches it.

- **Vendor-neutral code (PROD-04).** Placeholders, help texts, WP-CLI examples and comments use neutral examples such as `acme-pro` instead of the owner's products. `tests/Contract/VendorNeutralSourceTest.php` scans `src/` (except the add-on library `src/Client/`), `templates/` and `assets/` for every product key, name and entitlement of `catalogs/*.json`.
- **Guided setup for a new vendor (PROD-09).** The "Getting started" checklist under License settings has seven steps (secrets, public key in `wp-config.php`, catalog file, license product, first release, test purchase, Stripe webhook), each with a hint and a link; its state is read from the site on every visit. A new E2E flow (`01-vendor-onboarding`) runs the steps with an invented vendor on an empty installation.

### Fixed (16 Nov 2026, product isolation)

- **A download token could deliver another product's URL package (PK-03).** `ReleaseRegistry::grant_is_deliverable()` now refuses a URL that matches only releases of other products, and `PackageStream::serve()` checks that the release belongs to the product of the license.

### Removed (16 Nov 2026, dead code, A-136)

- `Requirements::unique_mode()` with two constants, `SiteUrl::identity_candidates()`/`legacy_normalize()` (stored addresses still match through `comparison_key()`, covered by a test), `StripeRecurringAdapter::request_metadata()`, `StripeWebhook::verify_signature()` and three unused constants.

### Added (16 Nov 2026, catalog file from the release package)

- **The catalog travels with the release (PROD-03).** When a release is created from a ZIP, the server reads `<plugin folder>/wplc-catalog.json` from the package and imports it into the license catalog (source `release`). The release is never refused because of the file: an invalid file, a file for another product or an oversized file is not imported, the uploader sees the reason and the audit log records `catalog_release_import_refused`. A package older than an existing release does not turn the catalog back. Removed keys that active plans still grant are named in a warning (`catalog_keys_removed_in_use`); plans and licenses are never changed. The archive is read in memory, one entry, at most 256 KB. New setting "Import the catalog file from release packages" (on by default) and filter `wplc_catalog_auto_import`.

### Added (16 Nov 2026, own update path, off by default)

- **Updates of the plugin from a license server (E8b, PROD-06).** A copy of the plugin can get its own updates from the license server of its vendor through the client library it ships (`src/Infrastructure/SelfLicense.php`). Configuration with the constants `WPLC_VENDOR_SERVER_URL`, `WPLC_VENDOR_PRODUCT_KEY`, `WPLC_VENDOR_PUBLIC_KEYS` and `WPLC_VENDOR_ACCOUNT_URL` or the filter `wplc_vendor_license_config`. **Off unless configured:** without it nothing is registered and no new request is made. The own license gates updates and support only: the shop's licenses are sold, checked and renewed the same with or without it. An endpoint on the same site is refused. See `docs/PRODUCTION-OPERATIONS.md`.

### Added (16 Nov 2026, renewal price change with notice)

- **The price guarantee can end (INH-05).** A higher renewal price can be announced for existing licenses: for one license on the license page, for every license of a product and plan under WooCommerce → Renewal price change, or with `wp wplc price-change announce|withdraw`. The customer gets the new email "License renewal: new price announced" with the current price, the new price, the date and how to cancel. Until the date every renewal, automatic and manual, costs the stored amount; the new amount is only charged when the email was accepted by the mailer at least the notice period before (setting "Price change notice period", default 60 days, floor 30). Without a recorded notice the old amount stays and the shop is alerted (`wplc/price_change_not_applied`, health metric `price_change_not_applied`). An announcement can be withdrawn; the customer is told with a second new email. Details and the interplay with plan changes, suspended licenses and paused billing: `docs/RENEWALS.md`.
- **Not legally reviewed:** the notice period and the wording of the two emails (T-93).
- Privacy: the personal data export lists an announced renewal price (amount, date, time of the announcement and of the notice); erasure, account deletion and the anonymisation rule remove the announcement together with the owner (INH-11).
- **Database schema 25:** five nullable columns on the licenses table (`price_change_*`).
- New filter `wplc_price_change_notice_days`, new action `wplc/price_change_not_applied`, new audit actions `renewal_price_change_announced|notified|applied|withdrawn|dropped|not_applied`. 72 new texts, translated in `de_DE` and `de_DE_formal`.

### Changed (16 Nov 2026, the entitlement catalog is data)

- **Catalog files (T-150, PROD-01).** The server code no longer contains products, entitlement keys or their labels. They come from catalog files (`wplc-catalog.json`, format 1; `docs/CATALOG-FILE.md`, JSON schema `docs/catalog-file.schema.json`) that the add-on of each product ships and the shop imports: under WooCommerce → License catalog files (capability `wplc_manage_catalog`), with `wp wplc catalog import|validate|list|export|remove`, and on upgrade by schema step 24, which imports the files shipped in `catalogs/` (the seven Captain products; they move to the build of captain-pro). Imports by a person are audited (`catalog_file_imported`, `catalog_product_removed`); importing an unchanged file changes nothing.
- Removing a product from the catalog needs a ticked confirmation in the form (no JavaScript involved) and never changes plans or licenses.
- **Database schema 24** (catalog). No table changes; new option `wplc_entitlement_catalog` (not autoloaded), removed with the other data on uninstall with `WPLC_REMOVE_ALL_DATA`.
- Behaviour is unchanged for an existing shop: the same keys are offered in the product editor and the pricing table, planned products and keys are not offered (`wplc_offer_planned_entitlements`), the filters `wplc_entitlement_catalog` and `wplc_known_product_keys` still work, and a plan keeps keys the catalog does not know in the advanced JSON field. Visible differences: the heading of a group in the product editor is the product name from the file ("Pass Captain Pro" instead of "Pass Captain"), groups follow the order of import, and `course-captain-pro` (`course.`) is a fourth planned product key.
- **For developers:** `EntitlementCatalog::PRODUCT_KEYS` and `EntitlementCatalog::PLANNED_PRODUCT_KEYS` are gone; use `EntitlementCatalog::known_product_keys()` and `::planned_product_keys()`. New classes `WPLC\Catalog\CatalogFile` (validator) and `WPLC\Catalog\CatalogStore`.
- Translations: the 25 labels of Captain functions and products left `languages/`; 50 texts of the new screen and command were added (1,602 texts, `de_DE` and `de_DE_formal` complete).
- New document `docs/PRODUCT-PLAN.md` (German): what selling the license server to other vendors requires, as 13 work packages, and the questions for the owner.

### Fixed (16 Nov 2026, found by the PHP 8.5 job)

- Site URL normalization without the intl extension: a host with a capital letter from outside the handled scripts whose lower-case form lies inside them was accepted or rejected depending on the Unicode version of the running PHP (PHP 8.5 lower-cases U+A7DC to U+019B), while the path with intl rejects it. The fallback now checks the input before lower-casing and rejects such a host on every PHP version. Also rejected without intl now: U+2C7E, U+2C7F, the Kelvin sign and the Angstrom sign (intl maps them; a site without intl must write the host in lower case or as Punycode).
- CI: PHP 8.5 runs as a fifth unit job and an eighth integration cell.

### Changed (16 Nov 2026, owner decisions)

- **Price guarantee for renewals (INH-01).** A manual renewal now bills the amount stored on the license at purchase, exactly like the automatic renewal. Before, it used the current regular price of the purchased product, so a price change in the shop reached customers who renew by hand but not those who renew automatically. A license without a stored amount is still priced from the catalog when the customer renews by hand. A plan change scheduled for the next renewal is billed at the price of the new plan, as before. The support action "Change renewal amount" is now also offered for licenses without automatic renewal. **Behaviour change for shops:** raising a product price no longer changes what existing licenses pay.
- **Entitlement catalog (R-24).** The keys `affiliate.priority_support`, `voucher.priority_support` and `pass.priority_support` are removed; support is the plan attribute `support.level`. A plan or license that stores one of the keys keeps it in the advanced JSON field.
- **German (B-120).** `de_DE` is now an informal ("du") translation, as in WordPress and WooCommerce; 358 of 1,577 texts differ from `de_DE_formal`, which stays formal ("Sie"). Shops that want the formal texts set the site language to "Deutsch (Sie)". `de_AT`, `de_CH` and other German variants keep loading `de_DE_formal`.
- Documentation: the Stripe webhook is named as the authoritative reconciliation of payment events (`docs/STRIPE-WEBHOOKS.md`).

### Changed (16 Nov 2026)

- Entitlement catalog: the product editor offers only keys of functions a released premium add-on ships. Removed are 17 keys that named functions of the free core plugins (eight `affiliate.*`, eight `voucher.*`, `voucher.staff_roles`); a plan or license that still stores one keeps it (advanced JSON field). Six keys of planned functions (`affiliate.store_credit`, `affiliate.stripe_connect`, `affiliate.leaderboard`, `affiliate.multi_tier`, `affiliate.multiple_programs`, `voucher.white_label`) are offered only with the new filter `wplc_offer_planned_entitlements`. `pass.e_invoicing` is labelled "E-invoicing (XRechnung XML)". The planned product keys `workflow-captain-pro`, `form-captain-pro` and `review-captain-pro` are known but neither suggested nor sellable.
- Documentation: one plan model for every product (`single`/`business`/`agency`, 1/5/25 live sites, same features); download links are valid for five minutes.

### Fixed (16 Nov 2026, client library 2.1.0)

- **Client library 2.1.0** (`src/Client`, the copy every add-on ships): storage is confirmed in both directions.
  - A signed answer that could not be stored no longer leaves the older stored state in charge. Before, `LicenseManager` ignored a failed write: an answer that suspended or revoked the license, or removed a right, had no effect until the offline window of the older answer ended. Now the request that received the answer follows it, a stored older state that granted more is deleted (the add-on reports "stale" until an answer can be stored), and `refresh()` returns the new error `wplc_state_not_saved`. An older state that grants the same is kept, so a database hiccup does not switch a paid add-on off.
  - `OptionStore::delete()`, `LicenseClient::forget()` and `LicenseManager::forget_locally()` return `bool` (before: nothing) and confirm by reading back. `deactivate()` returns the new error `wplc_credentials_not_removed` when the activation was released but the database kept the credentials or the cached rights; before, it reported success.
  - Two new texts in `Messages` (`error.wplc_credentials_not_removed`, `error.wplc_state_not_saved`); add-ons translate them in their own text domain.
  - **Action for add-ons:** `hastla007/captain-pro` must refresh its vendored copy with `bin/sync-client.sh /path/to/wp-license` and rebuild. A class that extends `OptionStore` (test doubles) must declare `delete(): bool`.
  - The examples in the doc comments name an existing entitlement key (`pass.waiting_room`) instead of the removed `voucher.qr_redemption`; a test checks it.

### Changed (16 Nov 2026, translations and checks)

- Translations: `languages/wp-license.pot` regenerated for 0.2.0 (1,573 texts), `de_DE` and `de_DE_formal` brought in line and recompiled. 130 entries left the catalog: 113 texts of the client library `src/Client` (they are shown by the add-ons in their own text domain, never by this plugin; `bin/make-pot.sh` has excluded them since the 15 Nov audit, the files had not been regenerated since) and 17 labels of the catalog keys removed on 16 Nov. The German texts of the client library remain available in the history of this repository (`languages/wp-license-de_DE_formal.po` before this change) for the add-ons that want to ship them.
- `bin/make-pot.sh` no longer reads `build/`, `dist/` (output of `bin/build-zip.sh`, which doubled every source reference when present) and `examples/`, and carries the version and creation date of the template into the `.po` headers.
- Entitlement catalog: the six keys of the planned Workflow Captain Pro (`workflow.suite_integrations`, `workflow.webhooks`, `workflow.event_correlation`, `workflow.advanced_logic`, `workflow.recipe_templates`, `workflow.extended_logs`) are reserved in `EntitlementCatalog::planned()`. They are not offered in the product editor.
- Removed two methods that only tests still called: `Catalog::accepts_product_key()` and `StripeRecurringAdapter::attempt_marker_allowed()`.
- Documentation: `docs/RENEWALS.md` said the block checkout shows no opt-in; it describes the additional checkout field and its limit on WooCommerce 8.9 to 9.8 now (also in `docs/COMPATIBILITY.md`).
- Rehearsals repeated on version 0.2.0 with schema 23 (release ZIP on an empty WordPress, a real multisite network, key rotation and restore on a database copy); records in `docs/LAUNCH-EVIDENCE.md`. They have to be repeated with the release commit.
- New `bin/check-consistency.php` with `ConsistencyCheckTest` (unit job): the counted overview of `docs/TODO.md`, the CI job numbers in `docs/QUALITY-GATES.md` and `docs/TODO.md`, and the translations against the template.

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
- Entitlement catalog, Voucher Captain Pro (decision of 16 Nov 2026): the add-on sells only its own functions, `voucher.bulk_orders` (company bulk orders), `voucher.wallet_passes`, `voucher.sms_delivery`, `voucher.white_label` and `voucher.priority_support` (Agency). Plans `single`, `business` and `agency` (1/5/25 live sites) contain the same functions, as for Pass Captain Pro. Removed, because they ship in the free Voucher core: `voucher.custom_branding`, `voucher.pdf_templates`, `voucher.automatic_delivery`, `voucher.qr_redemption`, `voucher.partial_balance`, `voucher.staff_roles`, `voucher.scheduled_delivery`, `voucher.bulk_import_export`, `voucher.reports`. Licenses that stored one of them keep the value; see `docs/ENTITLEMENTS.md`, "Removed keys". The three new labels are translated in the `.po` files; the `.mo` files still have to be rebuilt with `bin/make-pot.sh` (needs WP-CLI).
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
  - `deactivate()` keeps the credentials on an unsigned `site_mismatch` or `activation_not_found`; only a signed refusal removes them. When the server really no longer knows the installation, the settings screen offers "Remove the license data from this site only" (`LicenseManager::forget_locally()`). The add-on starter follows the same rule.
  - The filter `{prefix}_license_public_keys` (and the starter's `<prefix>_server_public_keys`) only works when `{PREFIX}_LICENSE_DEV` is defined, so another plugin cannot pin a key.
  - Uninstall cleans every site of a multisite in batches (was capped at 500); the privacy text names the product key and the environment that are sent.
  - Texts are built once per request; `is_activated()` and the other state checks no longer create a `WP_Error`.

### Audit panel (15 Nov 2026)

Eight reviewers (security admin/frontend, REST pentest, billing QA, client QA, performance, standards/multisite, Stripe, privacy/crypto) found 64 issues (`docs/TODO.md` §10, P-01–P-64); all were worked on, five only partly.

- **Money:**
  - P-02: license and renewal are re-checked under lock right before every charge, so nothing is charged after a confirmed cancellation, revocation, expiry or switch-off. A cancellation during a running charge opens a review case.
  - P-03: the Stripe idempotency key of an attempt is frozen before sending and reused while `reconciling`, also after a card or amount change. A refused Stripe search no longer counts as "nothing found".
  - P-04 / P-06: a plan change stores the gross/net flag with the renewal amount (no more 177.31 instead of 149.00). Upgrade/downgrade and credit use the license's contract price, not today's catalog price.
  - P-05 / P-15 / P-18 / P-19 / P-60 (review cases and bookings):
    - A refund or cancellation closes the review case.
    - Refunding one of two payments for the same period keeps the renewal.
    - "Book after review" stores the Stripe reference.
    - Money that cannot be booked raises a review case and an alert.
    - An order marked paid by hand without proof goes to review, and amount increases on automatic orders are refused.
  - P-16 / P-17 / P-58 / P-59 / P-61 (Stripe events and upgrade orders):
    - A card removed at Stripe is reported to the customer.
    - Open upgrade orders expire, and a cancelled or failed paid upgrade reverts the plan.
    - An early fraud warning switches renewal off.
    - A success without a charge ID is never a decline.
    - A new order gets its own Stripe key.
  - P-43–P-46: late attempt after grace counts from the charge date, rows without `grace_ends_at` are not expired at once, a failed refund revocation is retried, a tax rate change no longer creates an order every 6 h.
- **API:**
  - P-09 / P-10: the activation limiter per key prefix counts per prefix and client address, so a public prefix can't lock out the owner. New installations per license and hour are capped (`wplc_new_activations_per_hour`).
  - P-11: at most two open download grants per installation and package.
  - P-12: signed routes read parameters only from the signed JSON body (`400 request_parameters_unsigned`).
  - P-13 / P-22: `.dev` and labels on other domains are not staging, and a live activation can't move to a staging address (`409 site_move_refused`).
  - P-40–P-42 / P-50: no replay at the window edge. A per-IP limit applies before authentication, with the same answer for an unknown installation and a bad signature. The proxy header is ignored without `WPLC_TRUSTED_PROXIES`, with a diagnostics warning. `last_seen_at` is written at most hourly.
- **Admin, account, privacy:**
  - P-07: granular `wplc_*` capabilities everywhere.
  - P-08 / P-37: public cancellation only for the current owner, with atomic limits and asynchronous mail.
  - P-20: WooCommerce Subscriptions renewals issue no license.
  - P-27 / P-28: the duplicate audit page is removed; the kept one has a license filter and a redacted, audited CSV export. CSV exports are streamed.
  - P-35 / P-36 / P-64: the privacy eraser redacts audit snapshots, cancels open manual renewals, finds data by billing email and clears Stripe references. The exporter has the missing fields, and the policy text names Stripe metadata and external package hosts.
  - P-38: owner change issues a new key by default.
  - P-39: "Sites" deactivates through the account.
  - P-55: plural forms.
  - P-62 / P-63: the follow-up mail only sends a key that matches its hash; UUIDs come from `random_bytes`.
- **Client library 2.0.2 and starter:**
  - P-01: network mode form posts to `wp-admin/admin-post.php`.
  - P-14: `verified_server_data()` requires the v2 signature with nonce.
  - P-21: multisite per-site updates use one site's credentials.
  - P-22: "transfer activation" asks for confirmation.
  - P-23: an immediate re-check after the clock is set back.
  - P-24: the site identity comes from the unfiltered `home` option.
  - P-25: Ed25519 results are cached without sodium.
  - P-26: the starter gets the 2.0.1 hardenings.
  - P-47–P-49: no texts before `init`, late filters, new `settings_url` option.
- **Operations:**
  - P-29 / P-30: gap reconcile works with a cursor, and every daily job is its own Action Scheduler action.
  - P-31 / P-53: schema 22 adds indexes on `licenses.payment_token_id` and `event_inbox (source, received_at)`; the structure check repairs missing indexes.
  - P-32: site deletion drops the site's `wplc_*` tables; `wp wplc schema orphans` finds leftovers.
  - P-33: `Update URI` header.
  - P-34: `signing-key --rotate` and `activation release --license` ask for confirmation.
  - P-51 / P-52 / P-54 / P-56 / P-57: yesterday's API counters are kept, less admin overhead, uninstall removes cron events with arguments, the PHPCS gate compares per source line, readme says schema 22.

### Add-on audits (16 Nov 2026)

- **Client library 2.0.3** (add-on audits of 16 Nov 2026, captain-pro `docs/AUDIT-LIZENZ-PRS.md`; `docs/TODO.md` section 16):
  - Signed activation, entitlement and update answers must carry `product_key`; without it they are `wplc_response_invalid`. Only the signed "deactivated" answer, which grants nothing, may omit it. A stored snapshot without it grants nothing.
  - The `activation_id` of an activation answer must be a positive integer; otherwise nothing is stored and `activate()` returns `wplc_response_invalid` (was: `-5` stored, `"abc"` stored as 0 and reported as activated).
  - `LicenseManager` checks that the state was written. When the two development lines were merged (16 Nov 2026), this follows R-26 (see above): the older state is deleted only when it granted more than the answer that could not be stored, and the error is `wplc_state_not_saved`.
  - `OptionStore::delete()`, `LicenseClient::forget()` and `LicenseManager::forget_locally()` return whether the data is gone (were `void`); `deactivate()` and the form actions report `wplc_credentials_not_removed` (new text) instead of "removed" while the credentials stay. Subclasses of `OptionStore` (only the test kit) must declare `delete(): bool`.
  - Updater (P11): a failed update check no longer replaces the last valid offer; it stays until its own expiry (12 h after the successful check) with the error in `last_error`, and the check is repeated after an hour. WordPress therefore keeps showing an update that a cron check could not confirm. A signed refusal ends the offer at once.
  - Re-vendored into the add-on starter.
- **Client library 2.1.0** (owner decisions of 16 Nov 2026 on AL-1, AL-3 to AL-6; `docs/TODO.md` section 16):
  - Offline window (AL-1): a stored snapshot is also `stale` as soon as the local UTC time is later than its signed `cache_expires_at` plus a tolerance of one day (`LicenseManager::OFFLINE_TOLERANCE`, filter `{prefix}_license_offline_tolerance`, at most seven days), whatever the unsigned fields `fetched_at`, `clock_high` and `server_time_max` say. Resetting those fields in the database no longer makes an old signed snapshot usable again. A clock that is ahead by more than the tolerance shortens the window; one that is ahead by more than 15 days makes the snapshot `stale` after every check. New `LicenseStatus::$reason` (`clock_ahead`) with its own notice and line on the license screen.
  - Signed `409 site_mismatch` (AL-6): the client verifies the new `data.signed` of the server; a verified answer removes only the local credentials (`status: removed_locally`, `reason: site_mismatch`, `LicenseClient::is_signed_site_mismatch()`). An unsigned 409 of an older server keeps them, as before.
  - API requests use `wp_safe_remote_request()` instead of `wp_remote_request()` (AL-6). A license server on a local or private address (development, tests) needs the development build (`{PREFIX}_LICENSE_DEV`) or the new filter `{prefix}_license_allow_local_server`; only the host and port of the configured server addresses are then let through, for the duration of each request. New `LicenseClient` argument `allow_local_server`.
  - Site Health (AL-4): a section per add-on under Tools → Site Health → Info with state and reason, last check, last error, end of the offline window, environment, shortened installation ID, server host, signature check, key fingerprints and library version; no key, secret or snapshot.
  - "Remove license from this site" needs a confirmation (AL-5): checkbox `wplc_confirm_deactivate` on the license screen; `run_form_action( 'deactivate', '', true )` for callers without the form. `run_form_action( 'deactivate' )` without the confirmation now returns an error and sends nothing (except on a copy of the site, which only removes local data). See `docs/CLIENT-INTEGRATION.md`, "The license form and `run_form_action()`".
  - No translation before `init` (captain-pro finding): errors created before `init` carry their code as message instead of a translated text, so WordPress 6.7 no longer reports translations loaded too early when an add-on asks for its license on `plugins_loaded`. New `LicenseManager::error_text()` and `Messages::ready()`; the license form uses them.
  - New texts for the add-ons to translate (listed in `docs/CLIENT-INTEGRATION.md`, "Translations"). Re-vendored into the add-on starter, whose development build may reach a local license server.
- **Server, signed `409 site_mismatch`** (AL-6): `DELETE /activations/{id}` from an address the activation does not belong to answers with `data.signed`, an envelope bound to the request nonce with `site_match: false` (the stored address is not disclosed). `docs/API-V1.md` and `docs/openapi-v1.json` (`SiteMismatchData`) describe it. `Authenticator::deactivated_refusal()` builds the signed refusal of released activations in one place.
- **Tests** (AL-3): released activations reach the client as signed refusals when they are released through `ActivationManager::deactivate()` (customer account, support, end of the license), as unit interop test against the server code and as integration tests through the real REST routes.
- **Translations:** the Voucher Captain Pro catalog labels "Company bulk orders", "Wallet passes (Apple, Google)" and "SMS delivery" are translated in `de_DE` and `de_DE_formal` (`.po`; the `.mo` files are rebuilt by `bin/make-pot.sh`, which needs WP-CLI).

### Open items from the 13 Nov audits (15 Nov 2026, wave 2)

- **Renewals and orders:**
  - Every renewal status change writes its own audit entry (A-73), including on owner changes.
  - Removing a line from a paid order, or lowering its quantity, suspends the surplus licenses. The suspension is reversible; switch it off with the filter `wplc_suspend_license_on_order_item_change` (B-36).
  - Orphaned unpaid renewal orders are closed daily (B-190).
  - A period that ends in a shorter month runs until 23:59:59 UTC of its last day. A later purchase never ends earlier than an earlier one (B-178).
- **Catalog and keys:**
  - `wp wplc sync-entitlements` copies changed plan entitlements to existing licenses. It is a dry run by default, and reductions only happen with `--allow-reductions` (A-23).
  - `wp wplc rekey-licenses --from=<key-id>` re-keys all licenses issued under a leaked key secret (B-155).
  - "URL" releases without a resolver are no longer delivered, so the filter `wplc_require_package_resolver` now defaults to true (A-32).
  - WooCommerce Subscriptions products can't be mapped as license products (P-20).
- **Checkout and EU law:**
  - Licence products ask for consent to immediate performance (withdrawal right for digital content). The consent is required by default, stored on the order with time and wording, confirmed in the order emails, and off when Germanized or German Market is active (B-39). The legal wording is the shop owner's decision.
  - First orders show "License" and "Service period (UTC)" on the line item (B-42).
  - Checkout boxes stay ticked when WooCommerce reloads the payment section (A-118).
  - The block opt-in no longer repeats its long label in the confirmation and emails (B-114).
  - New block "My licenses", plus the shortcode `[wplc_changelog]` and a "Changelog" block (B-117).
- **Admin and support:**
  - Support can lower the renewal amount (A-11).
  - Support can cancel automatic renewal at the end of the term, and withdraw the cancellation (A-21).
  - Rejected Stripe signatures are counted and raise an alert (A-72).
  - New "Getting started" checklist (A-121).
  - Readable audit action names, with the timezone shown (A-122).
  - The search finds order numbers, and orders link to their licenses. The manual-license form keeps its input and lets you skip the email. Suspending a license and releasing a site ask for confirmation (A-123).
  - Diagnostics name switched-off license emails (A-132) and warn about read/write-splitting database layers (B-24).
  - A notice appears for new releases of this plugin. It reads GitHub, is cached for 12 h, never installs anything, and is switched off with `WPLC_DISABLE_UPDATE_CHECK` (A-42).
- **Quality and operations:**
  - Unified logging with a `request_id` per request or action, masked emails and `WPLC_DEBUG` (A-133).
  - `docs/EXTENSION-POINTS.md` lists every action and filter and is kept current by a test (A-134).
  - The grace period is defined in one place, with the filter `wplc_renewal_grace_days` (A-135).
  - Stress runs report pass or fail through the exit code (B-196).
  - Flaky API tests are fixed: rate limits count per calendar minute, and that boundary caused the failures (B-195, T-148).
  - The output-buffer loop in downloads is fixed (B-104).
  - No file-wide `phpcs:disable` remains; the baseline is down to 56 entries (P-56).
  - de_AT, de_CH and other German variants without their own file use the German translation (B-120).

### Remaining items without owner decisions (15 Nov 2026, wave 3)

- **Audit source (A-76):** every audit entry stores where it came from: CLI, cron/Action Scheduler, Stripe webhook, REST, admin, customer or internal (filter `wplc_audit_source`). The source can be filtered in the audit log and appears in the CSV export and in the license history. Database schema 23.
- **Moving sites (A-22):** customers can move a site from an ended or ending license to a newer license of the same product in their account. The plugin keeps working without a new key, and the limits apply. Cart and checkout show a "Renew instead" hint to customers who already have an active or recently expired license. Nothing is moved automatically.
- **Pricing table (B-117):** new block and shortcode `[wplc_pricing product="…"]` listing a product's plans with price (following the shop's tax display), term, site limit, features and a buy button.
- **Pass Captain Pro catalog:** e-invoicing, card terminal, waiting room, write API and white label, in every plan; plans differ by number of sites. This follows the owner decision of 14 Nov 2026, and the former Pass keys moved to the free core.
- **Browser acceptance (T-130, partly):** `tests/browser/run-browser.sh` runs Chromium through checkout, the account, all admin pages and the product editor at 1280/375 px and right-to-left.
  - It fixed overflowing admin tables on phones (A-126).
  - The "Sites" page now loads its stylesheet and the confirmation before "Deactivate".
  - Long site addresses now wrap on phones.
- **Multisite (P-32):** an optional must-use helper (`wp wplc schema install-mu-helper`) drops a deleted site's tables even when the plugin was only active there.
- **Release checks (A-91):** CI also runs the declared minimum WordPress 6.5 / WooCommerce 8.9.3 / PHP 7.4. Version statements across README, readme.txt and the docs are consistent and checked by a test.
- **Scheduling:** recurring events are no longer rescheduled once the plugin is inactive in the database. Before, a request that loaded the plugin before an uninstall could bring them back.
- **Cleanup:** unused methods, constants and imports from the merged branches removed (A-136, partly).

### Behaviour changes in 0.2.0 (B-197)

Compared with 0.1.0:
- Charges only happen after the advance notice.
- An unknown payment result waits for up to 12 h in `reconciling` and then goes to review.
- Downloads are single-use by default.
- Signed routes only read the signed JSON body.
- A wrong request signature answers like an unknown installation.
- A paid order that is cancelled without a refund suspends its licenses.
- Plan changes are priced from the contract price.
- Period ends at month ends move to the end of the last day.
- "URL" releases need a resolver.
- Licence products require the withdrawal consent at checkout.
- Client library 2.0.2 requires the v2 response signature.

### Known limitations

- Automatic renewal works only with the classic checkout and the WooCommerce Stripe gateway, and is not verified against Stripe yet.
- No automatic renewal with PayPal.
- Product plugins are not yet updated to this server version.

## [0.1.0] - Engineering preview

### Added

- WooCommerce catalog mapping and order-based license issuance.
- License lifecycle, activation limits, customer account page and administrative controls.
- Authenticated REST API, renewal adapter contract, premium update delivery and shared client class.
