# Production operations and launch gate

This runbook defines the operator work required before selling WP License Captain subscriptions. It does not claim that infrastructure, secrets, legal text, or payment accounts have been configured.

## Environments and secrets

- Maintain separate staging and production WordPress/WooCommerce installations and gateway credentials.
- Serve every license API and package redirect over HTTPS. Confirm certificates and renewal monitoring.
- Set stable `WPLC_INSTALLATION_ENCRYPTION_KEY` and `WPLC_LICENSE_KEY_SECRET` before issuing the first production license. Store them in the hosting secret manager, restrict read access, and record the restore procedure offline. Do not rotate either value without the documented migration and recovery plan.
- Never put gateway secrets, license keys, installation secrets, or package storage credentials in Git, diagnostics, support tickets, or CI logs.
- Restrict CI secrets to protected environments; require review for production deployment.

## Database backup and recovery

- Back up the WordPress database and package storage together before plugin/schema releases.
- Keep encrypted, access-controlled backups with documented retention and restore ownership.
- Test restoration to an isolated environment before launch and after storage/schema changes.
- After restore, verify schema version, license counts, order links, activation counts, renewal queue state, audit events, and package metadata.
- Never repair production state by directly editing tables except under an approved incident procedure with a before/after backup and audit record.

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
- Expiry reminders go out 30 and 7 days before `paid_until` for licenses without automatic renewal or with a scheduled cancellation. Change the days with the `wplc_reminder_lead_days` filter (or `reminder_lead_days` in the `wplc_settings` option, as a comma list). Lead days longer than half a billing period are skipped, so monthly licenses only get the 7-day reminder.
- Licenses with automatic renewal get one notice 7 days before the charge (`wplc_renewal_notice_lead_days` filter) with amount, date and how to cancel. The amount is the stored renewal amount of the license; confirm that it matches what the gateway will charge, including tax, before relying on it.
- Reminders are selected by the daily `wplc_daily_lifecycle` cron action in batches of 200 licenses, at most 1000 per run; a larger backlog continues five minutes later on the `wplc_send_reminders` single event. WP-Cron must therefore run reliably: use a real system cron on low-traffic sites. A missed day does not lose a reminder; it is sent on the next run while the license is still inside the lead window.
- Each reminder and status email is recorded in the `{prefix}wplc_reminder_log` table before sending and is sent once per license and period. The table is part of the plugin schema; a table that earlier versions created on first use is adopted with its rows. If the schema migration has not completed, reminders are not sent and an error is written to the WooCommerce log (source `wp-license`).
- Sending is best-effort. A mail failure never blocks checkout, renewal or cron, and a failed send is not retried automatically; monitor the `wp-license` log source for "was not accepted by the mailer".

## Data retention job

A daily job deletes old audit entries, processed gateway events, deactivated installations of ended licenses and old notification-log rows in batches of 1000 rows per table. Periods, the filter to change them and what is never deleted are documented in [Privacy data flows](PRIVACY-DATA-FLOWS.md#automatic-retention). Before launch, confirm the periods against the privacy notice, and make sure backups are taken before the first run on an existing database, because deletion is permanent.

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
