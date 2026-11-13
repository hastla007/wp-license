# Stripe webhooks

Implemented, not yet verified in a shop or against a Stripe test account.

Source: `src/Api/V1/StripeWebhook.php`, `src/Billing/RenewalProcessor.php`.

## Setup

1. Endpoint URL: `https://<shop>/wp-json/wplc/v1/webhooks/stripe` (method `POST`). The exact URL is shown under WooCommerce → License settings.
2. In the Stripe dashboard, add this URL as an endpoint and enable these events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.dispute.created`
3. Copy the endpoint's signing secret (`whsec_…`) into `wp-config.php`:

   ```php
   define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_...' );
   ```

   Without the constant every request is answered with 503. The secret is never stored in the database.

This endpoint is separate from the webhook of the WooCommerce Stripe gateway; both must exist.

## What the receiver does

- Verifies the `Stripe-Signature` header (HMAC-SHA256 over `{timestamp}.{body}`, 300 seconds tolerance). The body may be at most 1 MiB.
- Stores a minimized payload in the event inbox table: PaymentIntent ID, payment status, amount, currency, renewal/license/order IDs from the metadata, plus a SHA-256 of the raw body. For disputes: dispute ID, PaymentIntent ID, charge ID, reason, order ID. No customer or card data is stored.
- Queues the event for reconciliation (`wplc_process_stripe_webhook`) and answers immediately.

## Responses

| Status | Body / code | When |
| --- | --- | --- |
| 200 | `{"received":true,"reconciliation":"pending"}` | Event stored and queued |
| 200 | `{"received":true,"duplicate":true}` | Same event ID and same body received again |
| 200 | `{"received":true,"ignored":true}` | Other event types; PaymentIntents without `wplc_renewal_id` and `wplc_license_id` metadata (ordinary shop payments); disputes that do not belong to a license order |
| 400 | `wplc_stripe_webhook_body_invalid` | Empty or oversized body |
| 400 | `wplc_stripe_signature_invalid` | Signature wrong or older than 300 seconds |
| 400 | `wplc_stripe_event_invalid` | Not a Stripe event object |
| 400 | `wplc_stripe_event_metadata_invalid` | Renewal metadata incomplete (one of renewal, license, order ID or the PaymentIntent ID is missing) |
| 400 | `wplc_stripe_event_status_invalid` | `payment_intent.succeeded` whose status is not `succeeded` |
| 409 | `wplc_stripe_event_conflict` | Known event ID with a different body |
| 500 | `wplc_stripe_event_encoding_failed`, `wplc_stripe_event_store_failed` | Event could not be stored |
| 503 | `wplc_stripe_webhook_unconfigured` | `WPLC_STRIPE_WEBHOOK_SECRET` not defined |

Unrelated payments are acknowledged with 200 so Stripe neither retries them nor disables the endpoint.

## Reconciliation

Runs asynchronously (Action Scheduler group `wplc`, WP-Cron as fallback) under the same per-renewal lock as charging. The inbox row ends with one of these outcomes:

| Outcome | Meaning |
| --- | --- |
| `pending_reconciliation` | Stored, not processed yet |
| `processed` | Success event booked: renewal `paid`, license extended, order completed. Or: dispute handled, license suspended |
| `already_reconciled` | The renewal was already paid; nothing changed |
| `failure_observed` | Failure event recorded. It does not schedule retries; the charge attempt itself does |
| `ignored` | Dispute for an order without licenses |
| `invalid_payload` | Stored payload unusable |
| `manual_review` | See below |

Before a success event extends a license, all of these must match: renewal ↔ license ↔ order IDs, order metas, payment method `stripe` on license and order, amount received = order total, currency of event, order and license, and the order's pre-tax line = stored net renewal price.

The PaymentIntent ID is saved on the order (`_wplc_payment_intent_id`) so later disputes can be resolved to the order.

### Disputes

`charge.dispute.created` is resolved to an order by PaymentIntent ID (`_wplc_payment_intent_id`, `_stripe_intent_id`) or by transaction ID. Exactly one order must match. Every license paid by that order (first purchase or renewal) gets the action `wplc/chargeback`, which sets `active` and `past_due` licenses to `suspended`. An audit entry `chargeback_received` is written. Lifting the suspension is a manual decision on the license detail screen.

## Manual-review outcomes

An event ends as `manual_review` and extends nothing when:

- the IDs in the event do not match renewal, license and order (audit `stripe_webhook_mismatch`),
- the renewal is `cancelled`, `expired` or `refunded`, or the license is `revoked` or `suspended` (audit `stripe_webhook_manual_review`). Money may have been taken for a license that no longer runs: check in Stripe and refund or extend by hand,
- amount or currency differ from the order (audit `stripe_webhook_amount_mismatch`),
- the WooCommerce Stripe gateway is not loaded, so the amount cannot be compared,
- a success event carries a status other than `succeeded`.

`manual_review` events of the last 24 hours and events still pending are counted on the diagnostics page and trigger `wplc/health_alert`; see [MONITORING.md](MONITORING.md). There is no admin screen for the inbox; inspect the table `{prefix}wplc_event_inbox` and the audit log.

## Known gaps

- `payment_intent.payment_failed` events are compared against the order total like success events. Their received amount is 0, so they are expected to end as `manual_review` with an amount-mismatch audit entry instead of `failure_observed`. To be confirmed with a real event.
- Events locked out by a running charge are retried through WP-Cron after 30 seconds, also when Action Scheduler is in use.
