# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow semantic versioning once the first release is published.

## [Unreleased]

Consolidation of all earlier development branches into one code base. Preview: covered by unit, integration and end-to-end tests in a real WordPress/WooCommerce installation; not yet run against a Stripe test account or checked in a browser. See [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

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
