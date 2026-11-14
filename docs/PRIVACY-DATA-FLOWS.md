# Privacy data flows and WordPress erasure policy

This document defines the technical scope for WP License Captain's WordPress privacy exporter and eraser. The site operator remains responsible for the applicable privacy notice and retention schedule.

## Data inventory

| Data | Storage | Purpose | Export | Erasure handling |
| --- | --- | --- | --- | --- |
| WordPress customer ID | `wplc_licenses.customer_id` | Link a license to its account and WooCommerce order | Export as a linked license record; do not expose internal database IDs | Retain while the license, support, refund, or legal order record requires the relationship; pseudonymize only after applicable retention expires |
| License UUID, prefix, product, plan, status, term dates | `wplc_licenses` | License administration, support, entitlements, renewals | Export UUID, prefix, product/plan, status, start/paid-until/cancel dates | Do not export or store the plaintext license key. Preserve a verifier while the license is in force |
| Installation URL, environment and activity dates | `wplc_activations` | Enforce site limit, staging pairing, support and API authorization | Export for the identified user's installations | Keep active rows while required to provide the license. After deactivation, remove URL and unnecessary version detail under the configured retention schedule |
| Encrypted installation secret and its verifier | `wplc_activations` | Authenticate signed product requests | Never export | Delete when the activation is deleted; never log or expose |
| Renewal period, amount, currency, order link and state | `wplc_renewals` and WooCommerce order | Billing, reconciliation, refunds and support | Export dates, amount/currency, state and order reference | Retain with the order while required for billing/accounting and dispute handling |
| Gateway token reference and consent state | `wplc_licenses` and WooCommerce token provider | Authorized recurring payment | Export only that a payment method reference/consent exists; never export token values | Revoke/delete via the gateway's token API and WPLC reference when consent is withdrawn or the license closes |
| Audit history | `wplc_audit_log` | Security and administrative accountability | Export relevant customer-facing status events only | Retain for the documented audit period; redact personal values at write time |
| Notification log (license ID, notification type, period end, send time) | `wplc_reminder_log` | Send each reminder and status email exactly once | Export type, period and send time (no content, no address; the emails themselves are sent by the site's mailer) | Deleted by the retention job after 730 days; deleted with an ended license on erasure |
| Download grants and package downloads (license ID, creation and download time, source) | `wplc_download_tokens`, `wplc_package_streams` | Authorized package delivery | Export creation and download time; never the token or the package location | Deleted by the hourly cleanup a few days after expiry; deleted with an ended license on erasure |
| Nonces and rate limits | Security tables | Replay defense, abuse limits | Exclude (hashes only, no relation to a person beyond the installation) | Deleted by the hourly cleanup after expiry |

## Export (implemented)

One exporter named `wp-license-captain`. The WordPress user is resolved by the submitted email; every query is limited to licenses whose `customer_id` is that user. The export is paged in phases, in this order:

1. **Licenses**, 20 per page in `id` order: reference (key prefix), product, plan, status, start, paid-through, cancellation flag, automatic renewal on/off, billing interval, renewal amount and currency, next payment date, whether a payment method is on file (yes/no and the provider ID, never a token), the source order number, the time of the consent to automatic renewal stored on that order, and the first 100 installations (site URL, environment, status, product/WordPress/PHP version, activation, last-seen and deactivation time).
2. **Further installations** of licenses with more than 100.
3. **Renewals**: period, status, number of payment attempts, order number, order total and currency.
4. **License history**: audit entries about the person's licenses and installations and entries the person triggered (date, event code, object type, note). Notes are redacted when they are written (no keys, secrets, email addresses or site URLs).
5. **Email log**: which license email was sent for which period and when.
6. **Downloads**: when a download link was created and used, and for package downloads the product and version.

Phases 2 to 6 hold 100 rows per page. `done` is reported on the last page only. A database error returns a `WP_Error`, so WordPress shows a failed request that can be retried instead of an incomplete export. Never exported: activation secrets, hashes, HMAC material, license key plaintext, payment token values, package URLs, nonce data, other customers' information.

Not reachable by the exporter: licenses that were already detached from the account (after an erasure, after the account was deleted, or by the anonymisation rule). They no longer point to a person.

## Erasure (implemented)

One eraser named `wp-license-captain`. It does not depend on the page number WordPress passes: every call handles the next batch of records that still match (50 licenses per call), so a repeated page cannot skip records.

| Record | What happens |
| --- | --- |
| Ended licenses (`expired`, `revoked`, `cancelled`) | Detached from the account (`customer_id` 0, payment token reference removed). Their installations — also installations that were still active — are deactivated and deleted; email-log rows, download grants and package-download rows of the license are deleted. Audit entry `license_erased`. |
| Licenses in force | Retained, with their active installations: erasing them would stop a paid service and break the customer's sites. **Automatic renewal is switched off**: the payment token reference is removed, open renewals are cancelled, audit entry `auto_renewal_disabled`. The license ends with its paid period and is erased by a later request or by the anonymisation rule. Deactivated installations of these licenses are deleted. |
| Audit entries the person triggered | The user ID is removed from the entry (`actor_user_id` 0). The entry itself stays for the audit period. |
| WooCommerce orders, refunds, payment tokens | Not touched here; WooCommerce's own eraser and retention settings apply. |

The messages returned to WordPress state how many licenses were detached, for how many automatic renewal was switched off and how many were retained. A record that cannot be changed because of a database error is reported as retained with a message; the request ends instead of running forever and can be started again.

## Deleted accounts, payment methods and orders

- **Customer account deleted** (`deleted_user`): the licenses of the account are detached (`customer_id` 0), automatic renewal is switched off, deactivated installations are deleted and the user ID is removed from audit entries. The licenses are **not** revoked; they run until the end of the paid period. Audit entries `license_owner_deleted` and `auto_renewal_disabled`.
- **Saved payment method deleted** (`woocommerce_payment_token_deleted`): automatic renewal is switched off for the licenses that renew with it, instead of running into a failed charge and a dunning email. The customer can renew manually. Disable with the `wplc_disable_renewal_on_token_deletion` filter if another component moves the license to a different payment method.
- **Order trashed or deleted**: the licenses bought with it are not changed; an audit entry `source_order_removed` keeps them explainable.

## Suggested privacy policy text

The plugin registers a suggested section under Settings → Privacy → Policy guide (`wp_add_privacy_policy_content`). It describes the license record, the technical data sent by licensed plugins (site address, installation identifier, plugin, WordPress and PHP version; used for the site limit, compatible updates and support), automatic renewal, the history, and export and erasure. It is a starting point that the operator has to adapt and have checked. Add-on vendors should state the same technical data in the privacy information of the add-on, because the data is sent from the customer's site.

## Operational requirements

- The automatic retention worker is described under "Automatic retention" below. Its queries are covered by integration tests against MySQL; it has not yet been run against a production-sized database.
- Privacy processing is recorded in the audit log without copying the personal data into the entry.
- Do not claim GDPR compliance based only on these hooks. The merchant must publish the correct notice and validate its retention schedule with appropriate counsel.

## Automatic retention

`WPLC\Maintenance\Retention` runs once a day on the `wplc_daily_lifecycle` cron action, after expiry and reminders. Each rule works in batches (`... LIMIT 1000`) and repeats until nothing is left or the time budget of the run is used up (20 seconds, `wplc_retention_time_budget` filter); a larger backlog continues with the next run. The job also runs while billing is paused.

| Rule key | Table | What happens when | Default |
|---|---|---|---:|
| `audit_log` | `wplc_audit_log` | Deleted when `created_at` is older than the period | 730 days |
| `event_inbox` | `wplc_event_inbox` | Deleted when the event was processed (`processed_at` set) longer ago than the period and its outcome is final | 180 days |
| `event_inbox_open` | `wplc_event_inbox` | Deleted when an event that was never resolved (outcome `pending_reconciliation`, `manual_review` or `received`) was received longer ago than the period. Until then such rows are kept so that they can still be worked on | 730 days |
| `activations` | `wplc_activations` | Deleted when the installation is not active, was deactivated longer ago than the period and its license is `expired`, `revoked` or `cancelled`. Replay-protection rows go with it. A deactivated installation that still has an active staging child is kept | 365 days |
| `inactive_activations` | `wplc_activations` | The same for deactivated installations of licenses that are still in force (and of licenses that no longer exist). Deactivated installations do not count towards the site limit | 365 days |
| `ended_license_activations` | `wplc_activations` | Still active installations of an `expired` or `cancelled` license are **deactivated** (not deleted) once the paid period ended longer ago than the period. The default matches the 30 days in which a late manual renewal can still reactivate the license, so the customer's sites keep working until then. Installations of a `revoked` license are deactivated immediately | 30 days |
| `reminder_log` | `wplc_reminder_log` | Deleted when `sent_at` is older than the period | 730 days |
| `anonymize_licenses` | `wplc_licenses`, `wplc_activations` | For licenses that ended longer ago than the period: owner and payment token reference are removed and all installations are deleted. The license row stays as a business record (product, plan, dates, order link). Audit entry `license_anonymized` | **0 (off)** |

`anonymize_licenses` is off by default because the right period depends on the operator's legal retention duties (warranty, tax records, disputes). Without it, the data of an ended license stays linked to the customer account until an erasure request, the deletion of the account, or until the operator enables the rule. Enable it with a period that the privacy notice states, for example three years after the end of the license.

What is deliberately not touched: license rows, renewals, entitlements and WooCommerce orders (business and accounting records), and active installations of licenses in force.

Short-lived rows are handled by a separate hourly sweep on `wplc_cleanup_security_rows`: expired request nonces, rate-limit buckets older than a day, download grants that expired more than `wplc_download_grant_retention_days` (default 7) days ago and package-stream rows that expired more than a day ago. The sweep repeats in batches of 1000 for up to 10 seconds per run, so these tables do not grow with the number of installations.

### Configuration

The periods come from the `wplc_retention_days` filter, which receives the default map. A value of `0` disables a rule. Missing keys keep their default; negative or non-numeric values disable the rule instead of deleting everything.

```php
add_filter(
	'wplc_retention_days',
	static function ( array $days ): array {
		$days['audit_log']          = 1095; // Keep audit history for three years.
		$days['event_inbox']        = 0;    // Never delete processed gateway events automatically.
		$days['anonymize_licenses'] = 1095; // Detach licenses three years after they ended.
		return $days;
	}
);
```

Processed row counts are written to the WooCommerce log (source `wp-license`) without personal data. Deletion is permanent; the retention periods must match the privacy notice and the legal retention duties of the selling jurisdiction, which the operator has to confirm.

Do not set `reminder_log` shorter than the longest reminder lead time: the log is what prevents a reminder for the current period from being sent twice.

## Customer emails

The plugin sends six emails through WooCommerce's mailer to the account email address of the license owner: license issued, expiry reminder, upcoming automatic renewal, renewal payment failed, license expired, license renewed. They contain the product, plan, license reference (key prefix), dates and, for the renewal notice, the amount.

- The full license key is **not** included by default. A shop owner can opt in per email under WooCommerce → Settings → Emails → License issued; this sends the key over an unencrypted channel and should be an informed decision.
- Each email can be disabled individually in the same place. Disabling the upcoming-renewal notice may conflict with consumer-protection rules for automatic renewals.
- Emails are rendered in the customer's profile language when the translation is installed.
- The mail transport (SMTP provider, transactional mail service) is a data processor chosen by the operator and belongs in the privacy notice.
