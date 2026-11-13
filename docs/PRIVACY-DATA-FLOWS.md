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
| Notification log (license ID, notification type, period end, send time) | `wplc_reminder_log` | Send each reminder and status email exactly once | Exclude (no content, no address; the emails themselves are sent by the site's mailer) | Deleted by the retention job after 730 days |
| Nonces, rate limits and download grants | Security/update tables | Replay defense, abuse limits, authorized package delivery | Exclude | Expire through scheduled bounded cleanup |

## Export contract

Register one exporter named `wp-license-captain`. Resolve the WordPress user by the submitted email, then page through licenses by `customer_id`. Pages hold 20 licenses in stable `id` order; each license carries its first 100 installations (site URL, environment, status, product/WordPress/PHP version, activation, last-seen and deactivation time). Licenses with more installations are continued on additional pages of 100 installations each, under the same export item. `done` is reported only when licenses and installations are both exhausted, and a database error during the continuation pages leaves the request retryable. Export only the fields in the table above, plus active/deactivated site URLs that are still retained. Add the source order number when the associated order is readable through WooCommerce CRUD APIs. Do not export activation secrets, hashes, HMAC material, license key plaintext, payment token values, package URLs, nonce data, or other customers' information.

The exporter must be paginated and safe for empty accounts, deleted users, and stores with HPOS enabled. All order reads must use WooCommerce CRUD APIs.

## Eraser contract

Register one eraser named `wp-license-captain`. For an inactive/deactivated activation, erase its site URL, staging-parent relation, and unneeded environment/version fields when the configured retention period has elapsed. Delete expired nonces, rate-limit rows, and consumed/expired download grants in bounded batches.

Do not deactivate an active installation or erase an active license solely because a privacy erasure was requested; that would revoke a paid service and can break a customer's site. Instead, report the record as retained with the reason and the next review date. Keep WooCommerce order and refund data under WooCommerce's retention rules. When the license and order retention obligations expire, pseudonymize the WPLC customer link and remove activation details; do not rewrite WooCommerce orders directly.

The eraser must return WordPress's `items_removed`, `items_retained`, `messages`, and `done` fields accurately. Database errors must leave the request retryable and must not claim successful erasure.

## Operational requirements

- The automatic retention worker is described under "Automatic retention" below. It is implemented but has not yet been run against a production-sized database.
- Preserve an audit event for admin initiated privacy processing without copying the personal data into the event.
- Use transactions or compare-and-set updates where supported; batch large deletes to avoid long-running requests.
- Add tests for export pagination, HPOS order lookup, active-license retention, inactive activation erasure, retries after database error, and secret exclusion.
- Do not claim GDPR compliance based only on these hooks. The merchant must publish the correct notice and validate its retention schedule with appropriate counsel.

## Automatic retention

`WPLC\Maintenance\Retention` runs once a day on the `wplc_daily_lifecycle` cron action, after expiry and reminders. Each rule deletes at most 1000 rows per run (`DELETE ... LIMIT`), so a large backlog is removed over several days instead of in one long query.

| Rule key | Table | Deleted when | Default |
|---|---|---|---:|
| `audit_log` | `wplc_audit_log` | `created_at` is older than the period | 730 days |
| `event_inbox` | `wplc_event_inbox` | The event was processed (`processed_at` set) longer ago than the period. Rows with outcome `pending_reconciliation`, `manual_review` or `received` are never deleted, whatever their age | 180 days |
| `activations` | `wplc_activations` | The installation is not active, was deactivated longer ago than the period, **and** its license is `expired`, `revoked` or `cancelled`. Replay-protection rows of the deleted installation are removed with it. A deactivated installation that still has an active staging child is kept | 365 days |
| `reminder_log` | `wplc_reminder_log` | `sent_at` is older than the period | 730 days |

What is deliberately not touched: licenses, renewals, entitlements and WooCommerce orders (business and accounting records), active installations, and deactivated installations of licenses that are still in force (they are needed for the site limit and for support).

### Configuration

The periods come from the `wplc_retention_days` filter, which receives the default map. A value of `0` disables a rule. Missing keys keep their default; negative or non-numeric values disable the rule instead of deleting everything.

```php
add_filter(
	'wplc_retention_days',
	static function ( array $days ): array {
		$days['audit_log']   = 1095; // Keep audit history for three years.
		$days['event_inbox'] = 0;    // Never delete processed gateway events automatically.
		return $days;
	}
);
```

Deleted row counts are written to the WooCommerce log (source `wp-license`) without personal data. Deletion is permanent; the retention periods must match the privacy notice and the legal retention duties of the selling jurisdiction, which the operator has to confirm.

Do not set `reminder_log` shorter than the longest reminder lead time: the log is what prevents a reminder for the current period from being sent twice.

## Customer emails

The plugin sends six emails through WooCommerce's mailer to the account email address of the license owner: license issued, expiry reminder, upcoming automatic renewal, renewal payment failed, license expired, license renewed. They contain the product, plan, license reference (key prefix), dates and, for the renewal notice, the amount.

- The full license key is **not** included by default. A shop owner can opt in per email under WooCommerce → Settings → Emails → License issued; this sends the key over an unencrypted channel and should be an informed decision.
- Each email can be disabled individually in the same place. Disabling the upcoming-renewal notice may conflict with consumer-protection rules for automatic renewals.
- Emails are rendered in the customer's profile language when the translation is installed.
- The mail transport (SMTP provider, transactional mail service) is a data processor chosen by the operator and belongs in the privacy notice.
