# Release readiness checklist

Go/no-go gate for the first public release. A box is ticked only when the item was verified and the evidence is recorded in [LAUNCH-EVIDENCE.md](LAUNCH-EVIDENCE.md). Verified so far: unit and integration tests in CI and the end-to-end harness (`tests/e2e/`) without Stripe and without a browser. Nothing here is ticked until it has also passed the gate's real-world check.

The work items themselves live in [TODO.md](TODO.md), the single task list. This page does not repeat them; each gate names the tasks that must be closed first.

## Gates

### 1. Build and installation

- [ ] All tasks of TODO section 1 closed (T-12 Stripe test mode, T-130 browser acceptance, T-131 product add-ons).
- [ ] CI green on the release commit: lint, unit tests, integration tests, coding standards, static analysis (T-80).
- [ ] Release ZIP built by CI, installed on a clean WordPress with WooCommerce, activated without errors or notices (T-95).
- [ ] Update from the previous schema version runs without data loss (T-60).
- [ ] The declared WooCommerce minimum (8.9) has run the integration suite once, or the minimum is raised to the oldest version CI runs (9.4). Versions 8.9 to 9.3 have never run (A-91).
- [ ] German and English admin and customer texts checked on screen; `languages/` is in the ZIP (T-76).
- [ ] The plugin header keeps `Update URI: https://github.com/hastla007/wp-license` (P-33). WordPress then never asks WordPress.org for updates of this plugin, so a foreign plugin with the slug `wp-license` can never be offered as an update. The URI identifies the vendor's repository; WordPress itself fetches nothing from it. The plugin itself answers `update_plugins_github.com` for its own file with the latest GitHub release, without a package (A-42): the shop sees that an update exists and installs the verified ZIP by hand (docs/PRODUCTION-OPERATIONS.md, "Updating the license server plugin itself").

### 2. Purchase and renewal

- [ ] All [KRITISCH] purchase and renewal tasks closed (T-12, T-42, T-132).
- [ ] The six [KRITISCH] findings of the 13 November audit stay fixed and are re-checked on real screens (B-154): A-01 variable product mapped on the parent only (guest gets an ownerless license), A-02 a new customer can buy with WooCommerce default settings, A-03 manual renewal uses the price of the purchased plan, A-04 renewal orders keep the tax exemption and VAT ID, A-05 saving in the variations tab keeps the license fields, A-06 long version strings do not block an activation. Their tests are listed in TODO.md section 7.
- [ ] Purchase → license → email → account verified in the test shop with real WooCommerce screens.
- [ ] Automatic renewal verified in the Stripe test mode: success, decline, 3-D Secure, timeout, removed card (T-12).
- [ ] Manual renewal verified with at least two payment methods, including a net-price and a gross-price shop.
- [ ] Refund of a purchase and of a renewal order, cancellation, dispute and grace-period expiry verified (T-50, T-51).
- [ ] Stripe webhook endpoint configured and verified with real events as described in [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md) (T-12, T-51).

### 3. Updates and product plugins

- [ ] All [KRITISCH] update and product-plugin tasks closed (T-131, T-134, T-135, T-42).
- [ ] A release published in the admin is installed through the WordPress updater of each product plugin; wrong checksum, expired link and inactive license are refused.
- [ ] Customer download of the first ZIP from the account verified.
- [ ] Rollback verified: withdraw a release, installations get the previous version offered.

### 4. Security and privacy

- [ ] All [KRITISCH] security tasks closed (T-22 concurrency, T-134 server signature in the client).
- [ ] `WPLC_LICENSE_KEY_SECRETS` and `WPLC_INSTALLATION_ENCRYPTION_KEYS` defined on the production server; the admin notice about salt fallback is gone.
- [ ] Key rotation and recovery rehearsed on a restored database copy (T-86).
- [ ] Personal data export and erasure verified through the WordPress privacy tools; retention periods decided; deletion queries run against a database (T-92).
- [ ] The anonymisation period for ended licenses decided and set (`wplc_retention_days`, key `anonymize_licenses`; the settings page shows it), or the decision to keep it off is recorded (B-45).
- [ ] Independent review of the REST API authentication completed; concurrency verified (T-22).
- [ ] `tests/stress/run.sh all` ends with exit code 0 against the release commit (every race scenario reports PASS, B-196).

### 5. Operations and legal

- [ ] Production server, backups and a tested restore in place (T-90).
- [ ] Diagnostics on the production server: "Database locks" working and "Database layer" without a read/write-splitting warning, or the `wplc_*` tables routed to the primary (B-24).
- [ ] Someone watches new releases of the plugin: Diagnostics "Newest release" or the GitHub releases of the repository (A-42).
- [ ] A subscriber for `wplc/health_alert` and monitoring of scheduled actions in place as described in [MONITORING.md](MONITORING.md) (T-91).
- [ ] System cron runs `wp-cron.php`; Action Scheduler queue is processed.
- [ ] Page cache and CDN exclusions set as described in [CACHING.md](CACHING.md) (T-107).
- [ ] Legal review done: cancellation button, renewal information duties, withdrawal for digital content, privacy notice, invoices and VAT for renewals (T-93).
- [ ] Withdrawal right for digital content decided (B-39): either the plugin's own checkout consent (required by default, wording via `wplc_withdrawal_consent_text`) or Germanized / German Market with their digital-product checkbox switched on for every license product (the plugin then stays silent). Invoice layout checked for the license and service period lines on first and renewal orders (B-42). See [CONCEPT-CHECKOUT-RENEWAL.md](CONCEPT-CHECKOUT-RENEWAL.md), sections "Widerrufsrecht" and "Rechnungsangaben".
- [ ] Support procedures written and owners named for release, support, incident and rollback (T-94).

### 6. Sign-off

- [ ] Every remaining [KRITISCH] and [WICHTIG] task in TODO.md is closed or explicitly accepted as a known limitation in the release notes.
- [ ] LAUNCH-EVIDENCE.md filled in (T-96).
- [ ] Version, changelog and `readme.txt` updated; release owner has signed off.

## Rule

Do not advertise a capability (automatic renewal, premium updates, a payment method) before its gate is ticked.
