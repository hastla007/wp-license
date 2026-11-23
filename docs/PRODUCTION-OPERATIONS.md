# Production operations and launch gate

This runbook defines the operator work required before selling WP License Captain subscriptions. It does not claim that infrastructure, secrets, legal text, or payment accounts have been configured.

## Environments and secrets

- Maintain separate staging and production WordPress/WooCommerce installations and gateway credentials.
- Serve every license API and package redirect over HTTPS. Confirm certificates and renewal monitoring.
- Define the secret constants in `wp-config.php` before issuing the first production license. Use the key-ring form, so a later rotation only adds an entry (format, rotation and the move away from the WordPress-salt fallback: [Security and key operations](SECURITY-OPERATIONS.md)):

  | Constant | Purpose | What breaks when it is lost or changed |
  | --- | --- | --- |
  | `WPLC_LICENSE_KEY_SECRETS` (array key ID => secret) and `WPLC_LICENSE_KEY_ID` (ID used for new licenses) | Derives the license keys shown to customers | The shop shows no key, or a wrong key, for every license of the lost key ID. Stored key hashes stay valid, so a customer who still has the key can keep using it |
  | `WPLC_INSTALLATION_ENCRYPTION_KEYS` and `WPLC_INSTALLATION_ENCRYPTION_KEY_ID` | Encrypts the per-installation secrets | Every activated customer site fails authentication and must be activated again |
  | `WPLC_RESPONSE_SIGNING_KEY` | Ed25519 key the add-ons verify server responses with; the public key is built into the add-ons | Every delivered add-on rejects the server's responses until a new add-on release ships the new public key |
  | `WPLC_STRIPE_WEBHOOK_SECRET` | Verifies Stripe webhook deliveries | Every Stripe event is rejected. The secret belongs to exactly one webhook endpoint (one site, one Stripe mode) |

  The single-secret constants `WPLC_LICENSE_KEY_SECRET` and `WPLC_INSTALLATION_ENCRYPTION_KEY` still work, but a ring is needed for the first rotation anyway. Keys issued with one key ID need that ID in the ring for as long as such licenses exist; never remove or rename an entry because it "looks old".
- Store the constants in the hosting secret manager, restrict read access, and keep an offline copy together with the restore procedure. A database backup without the matching constants cannot be used: restore both together.
- Staging gets its **own** values for all four constants and its own Stripe test-mode webhook endpoint. A staging copy that keeps the production `WPLC_INSTALLATION_ENCRYPTION_KEYS` can decrypt production installation secrets; treat it like production or re-key it.
- Never put gateway secrets, license keys, installation secrets, or package storage credentials in Git, diagnostics, support tickets, or CI logs.
- Restrict CI secrets to protected environments; require review for production deployment.

## Staging copies and the billing pause

A copy of the shop database contains the same due renewals, saved payment methods and customers as the live shop. To keep a copy from charging real cards or mailing real customers, the plugin stores a fingerprint of the installation (home URL host and path, database name, table prefix) the first time it runs, and pauses billing when the fingerprint no longer matches.

Billing is paused when one of these is true:

| Reason | Trigger | How to end it |
| --- | --- | --- |
| `constant` | `define( 'WPLC_PAUSE_BILLING', true );` in `wp-config.php` | Remove the constant |
| `schema_downgrade` | The database was upgraded by a newer plugin version than the installed one | Install the newer plugin version again |
| `fingerprint` | Home URL host/path, database name or table prefix differ from the stored fingerprint (staging clone, restore on another host, domain move) | An administrator answers the notice: "This is the live shop – resume billing" or "This is a copy – keep billing paused" |
| `environment` | `wp_get_environment_type()` is not `production` (`WP_ENVIRONMENT_TYPE` set to `staging`, `development` or `local`) | Set the environment type to `production`, or define `WPLC_PAUSE_BILLING` as `false` on a staging site that is meant to bill test cards |

While paused, the plugin does not process queued renewal charges, retries and grace-period ends, does not expire licenses, does not send reminders or the status emails that follow a license change, and does not catch up on missed orders. Cleanup jobs (retention, expired security rows) still run. The API keeps answering, so add-ons on test sites can activate against a staging server. The `wplc_billing_paused` filter (arguments: paused, reason) has the last word.

Rules for operators:

- Set `WP_ENVIRONMENT_TYPE` to `staging` on every staging site, and add `define( 'WPLC_PAUSE_BILLING', true );` to its `wp-config.php` **before** importing a production database. The fingerprint check is the safety net, not the plan: a copy that keeps host, database name and prefix (restore onto the same server, blue/green switch) is not detectable.
- After a domain move of the live shop the notice appears once. Confirm "This is the live shop"; the confirmation is written to the audit log with the user.
- A pause that ends is treated as a processing gap (next section): renewals that became due meanwhile are attempted first and only expire afterwards.
- Scheduled charges of the Stripe gateway itself are not involved: the plugin starts every renewal charge. Whether Stripe retries or webhooks reach a staging URL has not been tested against a Stripe account.

## Processing gaps (plugin deactivated, cron stopped, restore)

When the plugin does not run for a while, orders are still paid and refunded, and renewals become due without an attempt. The plugin records such a gap when it is activated after a deactivation, when the daily run did not fire for two days or more, when a billing pause ends, and when it finds a paid order without license. After a gap:

- A catch-up job (`wplc_reconcile_gap`, also part of the daily run) looks at the orders and refunds of the last 30 days (`wplc_gap_reconcile_days` filter): paid orders with license products but without licenses get their licenses (and the key email), paid renewal orders are booked, refunds are applied. Every step is idempotent and written to the audit log (`gap_order_reconciled`, `gap_renewal_order_reconciled`, `gap_refund_reconciled`, `gap_full_refund_reconciled`).
- Licenses whose renewal was due during the gap are meant to be held back from expiry until the hold ends (as long as the gap lasted, at least one day, at most 15 days; `wplc_gap_hold_days`), so the renewal catch-up can charge them first. The flag is provided by `WPLC\Maintenance\GapGuard`; the expiry job has to consult it.
- Orders older than the look-back window are not caught up automatically. After a longer outage, raise `wplc_gap_reconcile_days` for one run or issue the licenses by hand.

## Database backup and recovery

- Back up the WordPress database, the package storage **and** the secret constants together before plugin/schema releases.
- Keep encrypted, access-controlled backups with documented retention and restore ownership.
- Test restoration to an isolated environment before launch and after storage/schema changes.
- Never repair production state by directly editing tables except under an approved incident procedure with a before/after backup and audit record.

### Restoring a backup into production

An older database does not know what happened after the backup: renewals that were charged, licenses that were issued, refunds, cancellations. Restoring it and letting the jobs run would charge paid periods again or expire paid licenses. Work in this order:

1. Put the shop into maintenance mode and add `define( 'WPLC_PAUSE_BILLING', true );` to `wp-config.php` before the restored database goes live.
2. Restore database, package storage and the matching secret constants. Flush the object cache (Redis/Memcached) afterwards; options such as the schema version are cached.
3. Open WooCommerce → License diagnostics (or run `wp eval 'var_export( \WPLC\Infrastructure\Database\Schema::status() );'`): installed and expected schema version must match and no structure problem may be listed. A database that is newer than the plugin files pauses billing on its own until the plugin is updated.
4. Reconcile payments made after the backup, before any charge runs: in the Stripe dashboard, list the payments since the backup time and compare them with the renewal orders in WooCommerce. For every payment without a paid renewal order in the restored database, recreate the state by hand (mark the renewal order paid or extend the license with "Change term", with the Stripe payment ID as reason). As a second line of defence the Stripe adapter searches Stripe for a PaymentIntent with this renewal's `metadata.wplc_renewal_id`, license, period and site before every first charge and books a payment it finds instead of charging again (B-10); it does not replace this step, because a search that Stripe refuses or that has not indexed the payment yet does not stop a first charge, and other gateways have no such search.
5. Reconcile orders and refunds made after the backup that exist in Stripe or the payment provider but no longer in WooCommerce (WooCommerce's own restore topic); licenses follow the orders.
6. Check cancellations: customers who cancelled automatic renewal after the backup are active again in the restored data. Compare with the cancellation emails or support tickets and cancel again.
7. Remove `WPLC_PAUSE_BILLING`. The end of the pause is recorded as a processing gap, so due renewals are attempted before anything expires. Watch the WooCommerce log (source `wp-license`) and the renewal list during the next daily run.
8. Record the incident and the manual corrections; they are visible in the audit history of the affected licenses.

### After a restore into staging or a clone

Follow "Staging copies and the billing pause" above: environment type, `WPLC_PAUSE_BILLING`, own secrets, flush the object cache. Customer email delivery of WooCommerce itself (order emails) is outside this plugin; use a mail trap on staging.

### Updating the license server plugin itself

The plugin is not on WordPress.org and does not install its own updates (A-42). It tells the shop that a new release exists:

- The header `Update URI: https://github.com/hastla007/wp-license` makes WordPress ask the filter `update_plugins_github.com` during its normal update check (twice a day). `WPLC\Infrastructure\SelfUpdateCheck` answers it from the latest published GitHub release of that repository (`https://api.github.com/repos/hastla007/wp-license/releases/latest`; drafts and pre-releases are not returned). The answer is cached for twelve hours (one hour after a failed check) in the site transient `wplc_self_update`.
- **Plugins** then shows "There is a new version of WP License Captain available" with a link to the release, and "Automatic update is unavailable for this plugin": the answer never contains a package, so nothing is downloaded or installed without a person. **Diagnostics** shows the row "Newest release" from the cache (the diagnostics page itself makes no request).
- To install: download `wp-license-captain-<version>.zip` and `.zip.sha256` from the release (built by `release.yml`), check `sha256sum -c`, take a backup, install on staging first, then upload the ZIP under **Plugins → Add New → Upload** and choose "Replace current with uploaded". Database migrations run on the next request (see "Database migrations").
- The check sends one HTTPS request without site data to `api.github.com` (the IP address of the server and the user agent `wp-license-captain`). Switch it off with `define( 'WPLC_DISABLE_UPDATE_CHECK', true );` or the filter `wplc_self_update_check_enabled`; then watch the repository's releases instead. A fork or mirror can point the check elsewhere with `wplc_self_update_release_api` (the answer must have the GitHub release format).
- If the repository is private, the API answers 404 and the row stays "Not known yet"; nothing else changes.

### Updates of the plugin from a license server (optional, off by default)

A copy of this plugin can get its own updates from the license server of its vendor, through the client library it ships (`src/Infrastructure/SelfLicense.php`; owner decision E8b, PROD-06 in [PRODUCT-PLAN.md](PRODUCT-PLAN.md)). Nothing of this is active unless it is configured in `wp-config.php`:

```php
define( 'WPLC_VENDOR_SERVER_URL', 'https://licenses.vendor.example' ); // HTTPS, never this site
define( 'WPLC_VENDOR_PRODUCT_KEY', 'product-key-of-the-server' );
define( 'WPLC_VENDOR_PUBLIC_KEYS', array( 'key-id' => 'base64 public key of that server' ) );
define( 'WPLC_VENDOR_ACCOUNT_URL', 'https://vendor.example/my-account/' ); // optional
```

(or the filter `wplc_vendor_license_config`, for a build that ships the values). The product key, the address and the public key of the server that will sell this plugin are not decided yet (INH-07 to INH-09 in [TODO.md](TODO.md)); until then there is nothing to enter and the feature stays off.

- **Not configured:** no screen, no scheduled event, no option, no request. The release notification from GitHub (above) works as before.
- **Configured:** the license screen of the client library appears under Settings (the key bought from the vendor is entered there), a daily check is scheduled (`wplc_vendor_license_check`), WordPress offers updates through the vendor's server, and the GitHub notification is switched off. Options and hooks use the prefix `wplc_vendor`, apart from the shop's data. The texts of that screen are in English for now.
- **The own license never gates function.** With a missing, expired or revoked own license, or an unreachable vendor server, the plugin sells, checks and renews the licenses of the shop exactly as before; only updates of the plugin itself stop (`SelfLicenseIntegrationTest::test_the_shop_works_fully_without_a_valid_own_license`).
- **No loop.** An address whose host is this site (also with or without `www.`, also set later through a filter) is refused: a server never updates itself from itself. The installation that sells the plugin runs it without this configuration.
- An address without HTTPS, an invalid product key or missing public keys leave the feature off.

### Rolling back the plugin

Installing an older plugin version over a newer database is detected: nothing is migrated backwards, administrators see a notice, and billing is paused until the newer version is installed again. Roll back the plugin only together with the database backup that belongs to it.

## Database migrations

- Tables are created and changed on activation and, after an update, by the first request in the admin, by WP-Cron or by WP-CLI. Front-end and API requests never migrate and never wait for a migration.
- A migration counts as done only when the database reported no error and every expected column exists with the expected type. Otherwise the previous schema version stays stored, administrators see a notice with the technical reason, and the next attempt follows after five minutes.
- Once a day the structure is compared with the expected one; missing tables or columns are created again even though the stored version is current (for example after tables were dropped by hand).
- `Schema::status()` returns installed version, expected version, structure problems and the last error for diagnostics.

## Scheduled jobs

- `wplc_daily_lifecycle` (WP-Cron, daily) runs catch-up, expiry, renewal re-queueing, reminders, retention and, last, the health alerts. Each job is isolated: an exception in one job is written to the WooCommerce log (source `wp-license`, "Job … failed and was skipped") and the next job runs. The last failure per job is kept in the option `wplc_job_failures`. Every job that finished writes its heartbeat into the one option `wplc_last_run` (job name => unix time; `daily_lifecycle_started` and `daily_lifecycle` bracket the whole run). `WPLC\Maintenance\DailyRunner` is the only wrapper that isolates jobs and writes heartbeats; `WPLC\Maintenance\SiteGuard::billing_paused()` is the only pause check.
- Each job gets its own request: when WP-Cron fires `wplc_daily_lifecycle` and Action Scheduler is available (it ships with WooCommerce), the run only does its bookkeeping and queues every job as its own async action `wplc_daily_job` (group `wplc`, argument: the job key such as `license_lifecycle_expire_due_licenses`), the health alerts and the closing heartbeat (`daily_lifecycle_finish`) last. A job that hits the PHP time limit is marked failed by Action Scheduler (WooCommerce → Status → Scheduled Actions) and the other jobs still run. Jobs are registered as before with `add_action( 'wplc_daily_lifecycle', $callback, $priority )`; the priority sets the order in the queue. Without Action Scheduler, or when the hook is fired outside a WP-Cron request (`do_action()` in code, WP-CLI without `DOING_CRON`), the jobs run in the same request with the time limit lifted. The filter `wplc_daily_run_split` turns the split off or on.
- The catch-up job checks paid orders newest first and keeps its position in the option `wplc_gap_reconcile_cursor` when its 20-second budget runs out; the next run checks the new orders and then continues where the previous one stopped. The two refund checks have 10 seconds each of their own.
- `wplc_cleanup_security_rows` (WP-Cron, hourly) deletes expired replay-protection, rate-limit, download-grant and package-stream rows in a loop with a time budget.
- A daily run that did not fire for two days is recorded as a processing gap. Use a real system cron for `wp-cron.php` on low-traffic sites and monitor the entry `daily_lifecycle` of the option `wplc_last_run` (shown on the diagnostics page).

## Multiple web nodes

- Uploaded packages are stored in the local file system (`wp-content/uploads/wplc-packages/` or the directory from the `wplc_package_storage_dir` filter). With more than one web node this directory must be on shared storage that every node mounts at the same path; otherwise a download fails on every node except the one that received the upload.
- Every package download occupies a PHP worker for the duration of the transfer. Size the worker pool for parallel update downloads after a release, or serve packages from external storage.
- Deploy the same secret constants to every node, and run the database on one writable primary (see the database requirements in `CONCEPT-CHECKOUT-RENEWAL.md`; with read/write splitting the locks and counters must be forced to the primary).

## Monitoring and incident response

Monitor and alert on:
- scheduled renewal backlog and repeated provider failures;
- webhook signature failures, duplicate processing and queue age;
- activation/entitlement API error rates, latency, replay/rate-limit events;
- package redirect, storage, checksum, and updater failures;
- database capacity, failed migrations, backup age, and restore-test age.

For incidents: assign an incident owner, record start/end and affected versions, preserve sanitized logs, pause risky releases, restore service or roll back, then reconcile affected licenses/orders through supported WooCommerce flows and record the correction in audit history.

## Customer emails and reminders

The plugin registers six customer emails with WooCommerce (WooCommerce → Settings → Emails): license issued, license expiry reminder, upcoming automatic renewal, license renewal payment failed, license expired, license renewed. Subject, heading, additional content and on/off are edited there; templates live in `templates/emails/` and can be overridden in the theme under `wp-license/emails/` (plain-text variants under `wp-license/emails/plain/`).

Operator checklist before launch (none of this is verified by automated tests):

- Send every email once in the staging shop, in HTML and plain text, and in each customer language you sell in. Check links (account page, pay link), dates and the renewal amount.
- Use an authenticated mail transport (SPF, DKIM, DMARC). A reminder that lands in spam is a lost renewal.
- Decide whether the license-issued email may contain the full key. Default is off; the email then shows the key prefix and links to the account page.
- Expiry reminders go out 30 and 7 days before `paid_until` for licenses without automatic renewal or with a scheduled cancellation. Change the days with the `wplc_reminder_lead_days` filter (or `reminder_lead_days` in the `wplc_settings` option, as a comma list). Lead days longer than half a billing period are skipped, so monthly licenses only get the 7-day reminder. For a product that is not for sale (private, draft, password protected or not purchasable, e.g. a project product licensed by hand) the reminder has no "Renew now" and asks the customer to contact the shop instead; the template gets this as `sold_in_shop` (empty string) in `$view`.
- Licenses with automatic renewal get one notice 7 days before the charge (`wplc_renewal_notice_lead_days` filter) with amount, date and how to cancel. The amount is the stored renewal amount of the license; confirm that it matches what the gateway will charge, including tax, before relying on it.
- Reminders are selected by the daily `wplc_daily_lifecycle` cron action in batches of 200 licenses, at most 1000 per run; a larger backlog continues five minutes later on the `wplc_send_reminders` single event. WP-Cron must therefore run reliably: use a real system cron on low-traffic sites. A missed day does not lose a reminder; it is sent on the next run while the license is still inside the lead window.
- Each reminder and status email is recorded in the `{prefix}wplc_reminder_log` table before sending and is sent once per license and period. The table is part of the plugin schema and is created with the other tables; a table that earlier versions created on first use is adopted with its rows. If it is missing, reminders are not sent and an error is written to the WooCommerce log (source `wp-license`); the daily structure check creates it again.
- Sending is best-effort. A mail failure never blocks checkout, renewal or cron, and a failed send is not retried automatically; monitor the `wp-license` log source for "was not accepted by the mailer".

## Data retention job

A daily job deletes old audit entries, gateway events, deactivated installations and old notification-log rows, and deactivates installations of ended licenses. It works in batches of 1000 rows and repeats until nothing is left or its time budget (20 seconds, `wplc_retention_time_budget`) is used up; a larger backlog continues with the next run. Periods, the filter to change them and what is never deleted are documented in [Privacy data flows](PRIVACY-DATA-FLOWS.md#automatic-retention). Before launch, confirm the periods against the privacy notice, and make sure backups are taken before the first run on an existing database, because deletion is permanent.

## Deactivating and deleting the plugin

- **Deactivate** stops the scheduled jobs and records the time. Tables, options and packages stay. While the plugin is off, nothing is processed: no licenses for paid orders, no refunds, no renewals, and the API answers 404, so add-ons fall back to their cached state. On the next activation the gap is caught up as described above.
- **Delete** (uninstall) additionally removes pending Action Scheduler jobs and the plugin's working state. It keeps the `{prefix}wplc_*` tables, the options `wplc_schema_version`, `wplc_reminder_log_version`, `wplc_settings`, `wplc_site_fingerprint`, `wplc_rewrite_version` and `wplc_response_signing_key`, the uploaded packages and all order meta. On a network this happens for every site.
- To remove tables and options as well, define `WPLC_REMOVE_ALL_DATA` as `true` in `wp-config.php` before deleting the plugin. Package files and order meta are never removed automatically. Without a backup of `wplc_response_signing_key` (or the constant) delivered add-ons can no longer verify a reinstalled server.
- If the tables were dropped by hand and the plugin is installed again, activation creates all tables again.

## Caching and proxies

API routes, the customer account and `admin-post.php` must be excluded from page and CDN caches, download URLs are single-use, and trusted proxies must be declared. See [Caching and reverse proxies](CACHING.md).

## Release and rollback checklist

1. Release to staging with a reversible database migration and a known-good previous plugin package.
2. Confirm CI passes on the supported PHP matrix; run WordPress/WooCommerce integration and gateway sandbox scenarios.
3. Publish immutable versioned ZIPs and manifest metadata (version, SHA-256, required PHP/WP, tested WP, changelog). Verify package host allow-lists.
4. Test activation, renewal success/failure, duplicate webhook, refund, cancellation-at-period-end, expired license, update authorization, expired grant refresh, invalid host and checksum mismatch.
5. Take and verify a pre-release backup. Deploy to a small pilot cohort; inspect diagnostics and monitoring.
6. Promote only after an owner signs off. For rollback, restore the previous plugin package and compatible database backup/migration path; do not downgrade schema blindly.
7. Communicate customer impact and open an incident record for any failed billing or update event.

## Customer, privacy and legal readiness

Before sales, publish accurate plan limits, renewal amount/interval, cancellation behavior, grace period, update/support entitlement, data retention, privacy notice and refund terms. Provide a customer route to cancel before renewal and a support process for lost license keys/domain changes. Confirm these texts and processes with qualified legal/accounting advice for the selling jurisdiction.

## Go / no-go

**No-go** while any of these are true: required PR stack is unreviewed/unmerged; production keys are not stable and backed up; PayPal renewals are advertised without a supported adapter; Stripe webhooks or manual-authentication recovery are unvalidated; packages are not immutable and checksum verified; restore and gateway sandbox runs have not passed; privacy, cancellation, tax/invoice and support ownership are unresolved.

Record named owners and dated evidence for every go/no-go item before launch.
