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
| Nonces, rate limits and download grants | Security/update tables | Replay defense, abuse limits, authorized package delivery | Exclude | Expire through scheduled bounded cleanup |

## Export contract

Register one exporter named `wp-license-captain`. Resolve the WordPress user by the submitted email, then page through licenses by `customer_id`. Export only the fields in the table above, plus active/deactivated site URLs that are still retained. Add the source order number when the associated order is readable through WooCommerce CRUD APIs. Do not export activation secrets, hashes, HMAC material, license key plaintext, payment token values, package URLs, nonce data, or other customers' information.

The exporter must be paginated and safe for empty accounts, deleted users, and stores with HPOS enabled. All order reads must use WooCommerce CRUD APIs.

## Eraser contract

Register one eraser named `wp-license-captain`. For an inactive/deactivated activation, erase its site URL, staging-parent relation, and unneeded environment/version fields when the configured retention period has elapsed. Delete expired nonces, rate-limit rows, and consumed/expired download grants in bounded batches.

Do not deactivate an active installation or erase an active license solely because a privacy erasure was requested; that would revoke a paid service and can break a customer's site. Instead, report the record as retained with the reason and the next review date. Keep WooCommerce order and refund data under WooCommerce's retention rules. When the license and order retention obligations expire, pseudonymize the WPLC customer link and remove activation details; do not rewrite WooCommerce orders directly.

The eraser must return WordPress's `items_removed`, `items_retained`, `messages`, and `done` fields accurately. Database errors must leave the request retryable and must not claim successful erasure.

## Operational requirements

- Provide a configurable retention period for inactive activation details, consumed download grants, and audit records; document the default before release.
- Preserve an audit event for admin initiated privacy processing without copying the personal data into the event.
- Use transactions or compare-and-set updates where supported; batch large deletes to avoid long-running requests.
- Add tests for export pagination, HPOS order lookup, active-license retention, inactive activation erasure, retries after database error, and secret exclusion.
- Do not claim GDPR compliance based only on these hooks. The merchant must publish the correct notice and validate its retention schedule with appropriate counsel.
