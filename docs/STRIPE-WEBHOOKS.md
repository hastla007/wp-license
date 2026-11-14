# Stripe webhooks

Implemented, not yet verified in a shop or against a Stripe test account. The integration tests send constructed events through the real REST route; no event on this page came from Stripe. The steps to verify it are in [STRIPE-TEST-PLAN.md](STRIPE-TEST-PLAN.md).

Source: `src/Api/V1/StripeWebhook.php`, `src/Gateway/StripeEventHandler.php`, `src/Billing/RenewalProcessor.php`.

## Setup

1. Endpoint URL: `https://<shop>/wp-json/wplc/v1/webhooks/stripe` (method `POST`). The exact URL is shown under WooCommerce → License settings.
2. In the Stripe dashboard, add this URL as an endpoint and enable these events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payment_intent.canceled`
   - `charge.dispute.created`
   - `charge.dispute.closed`
   - `charge.dispute.funds_reinstated`
   - `radar.early_fraud_warning.created`
3. Copy the endpoint's signing secret (`whsec_…`) into `wp-config.php`:

   ```php
   define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_...' );      // endpoint of the live mode
   define( 'WPLC_STRIPE_WEBHOOK_SECRET_TEST', 'whsec_...' ); // endpoint of the test mode (optional)
   ```

   Without a secret every request is answered with 503. Secrets are never stored in the database.

This endpoint is separate from the webhook of the WooCommerce Stripe gateway; both must exist.

### Test and live mode

Stripe has one endpoint list per mode, each endpoint with its own secret.

- Only `WPLC_STRIPE_WEBHOOK_SECRET` defined: it is accepted for events of both modes (behaviour before the test secret existed).
- Both constants defined: an event must be signed with the secret of its own mode (`livemode` of the event). Otherwise it is rejected with `wplc_stripe_event_mode_mismatch`.
- A test-mode event is acknowledged and dropped (`"reason":"test_event_in_live_mode"`) while the Stripe gateway runs in live mode. A test payment never extends a license of a shop that sells for real money. Live events are processed in every gateway mode: they are real money.

### Multisite

Every site of a network has its own endpoint URL and therefore its own secret:

```php
define( 'WPLC_STRIPE_WEBHOOK_SECRETS', array(
	1 => 'whsec_live_of_site_1',
	4 => array( 'live' => 'whsec_live_of_site_4', 'test' => 'whsec_test_of_site_4' ),
) );
```

The key is the blog ID. Once this constant is defined, a site without an entry answers 503; the two single constants are ignored. The filter `wplc_stripe_webhook_secrets` (`array{live:string,test:string}`, blog ID) can supply the secrets from elsewhere.

Sites that share one Stripe account receive each other's events. Every renewal PaymentIntent carries `metadata.wplc_site` (a hash of home URL and blog ID, filter `wplc_stripe_site_id`); an event with another site's identifier is acknowledged and dropped (`"reason":"other_site"`). The same protects a staging copy and the production site from each other. PaymentIntents created before this metadata existed carry no identifier and are processed as before.

## What the receiver does

- Verifies the `Stripe-Signature` header (HMAC-SHA256 over `{timestamp}.{body}`, 300 seconds tolerance). The body may be at most 1 MiB.
- Records a heartbeat after every correctly signed request (`stripe_webhook`, plus `stripe_webhook_live` or `stripe_webhook_test`) and, at most once a minute, after a rejected signature (`stripe_webhook_rejected`); see `Diagnostics::last_run()`.
- Stores a minimized payload in the event inbox table: PaymentIntent ID, payment status, amount, currency, renewal/license/order IDs from the metadata, the charge ID when the event carries one, plus a SHA-256 of that stored payload. For disputes: dispute ID, PaymentIntent ID, charge ID, reason, order ID, dispute status. For early fraud warnings: warning ID, PaymentIntent ID, charge ID, fraud type, order ID. No customer or card data is stored.
- Queues the event for reconciliation (`wplc_process_stripe_webhook`) and answers immediately.

## Responses

| Status | Body / code | When |
| --- | --- | --- |
| 200 | `{"received":true,"reconciliation":"pending"}` | Event stored and queued |
| 200 | `{"received":true,"duplicate":true}` | Same event ID received again with the same stored content. The raw body may differ (other API version, new fields) |
| 200 | `{"received":true,"ignored":true}` | Other event types; PaymentIntents without `wplc_renewal_id` and `wplc_license_id` metadata (ordinary shop payments); disputes and fraud warnings that do not belong to a license order |
| 200 | `{"received":true,"ignored":true,"reason":"other_site"}` | Renewal of another site that shares the Stripe account |
| 200 | `{"received":true,"ignored":true,"reason":"test_event_in_live_mode"}` | Test-mode event while the gateway runs live |
| 400 | `wplc_stripe_webhook_body_invalid` | Empty or oversized body |
| 400 | `wplc_stripe_signature_invalid` | Signature wrong or older than 300 seconds |
| 400 | `wplc_stripe_event_invalid` | Not a Stripe event object |
| 400 | `wplc_stripe_event_metadata_invalid` | Renewal metadata incomplete (one of renewal, license, order ID or the PaymentIntent ID is missing) |
| 400 | `wplc_stripe_event_status_invalid` | `payment_intent.succeeded` whose status is not `succeeded` |
| 400 | `wplc_stripe_event_mode_mismatch` | Event signed with the secret of the other mode |
| 409 | `wplc_stripe_event_conflict` | Known event ID whose stored content would differ (other amount, status or IDs) |
| 500 | `wplc_stripe_event_encoding_failed`, `wplc_stripe_event_store_failed` | Event could not be stored |
| 503 | `wplc_stripe_webhook_unconfigured` | No signing secret for this site |

Unrelated payments are acknowledged with 200 so Stripe neither retries them nor disables the endpoint.

Error messages are fixed English text and are not translated; `code` is the contract.

## Reconciliation

Runs asynchronously (Action Scheduler group `wplc`, WP-Cron as fallback) under the same per-renewal lock as charging. The inbox row ends with one of these outcomes:

| Outcome | Meaning |
| --- | --- |
| `pending_reconciliation` | Stored, not processed yet |
| `processed` | Success event booked: renewal `paid`, license extended, order completed. Or: dispute handled, license suspended. Or: dispute follow-up or fraud warning recorded |
| `already_reconciled` | The renewal was already paid by this PaymentIntent; nothing changed |
| `failure_observed` | `payment_intent.payment_failed` or `payment_intent.canceled` recorded. It does not schedule retries; the charge attempt itself does |
| `ignored` | Dispute or fraud warning for an order without licenses |
| `invalid_payload` | Stored payload unusable |
| `manual_review` | See below |

Before a success event extends a license, all of these must match: renewal ↔ license ↔ order IDs, order metas, payment method `stripe` on license and order, amount received = order total, currency of event, order and license, and the order's pre-tax line = stored net renewal price.

The PaymentIntent ID is saved on the order (`_wplc_payment_intent_id`) so later disputes can be resolved to the order. A renewal booked by the webhook gets the charge (`ch_…`) as transaction ID and the gateway's own metas (`_stripe_intent_id`, `_stripe_charge_captured`, `_stripe_customer_id`), exactly like a renewal booked by the charge itself, so refunds from WooCommerce and from the Stripe dashboard find the order.

### Double payment

A success event for an order that is already paid by a different PaymentIntent (`_stripe_intent_id` of the order differs from the event) means two payments for one period: typically the automatic charge and the customer on the pay page. The event ends as `manual_review`, the order gets a note and the meta `_wplc_duplicate_payment_intent_id`, the audit log gets `renewal_double_payment`, and `wplc/renewal_needs_review( $order_id, $renewal_id, $problem )` fires. Nothing is refunded automatically.

### Disputes

`charge.dispute.created` is resolved to an order by PaymentIntent ID (`_wplc_payment_intent_id`, `_stripe_intent_id`) or by transaction ID. Exactly one order must match. Every license paid by that order (first purchase or renewal) gets the action `wplc/chargeback`, which sets `active` and `past_due` licenses to `suspended`. An audit entry `chargeback_received` is written. Lifting the suspension is a manual decision on the license detail screen.

The follow-up events are resolved the same way. None of them changes a license; each writes an order note and an audit entry and fires an action:

| Event | Audit action | Action |
| --- | --- | --- |
| `charge.dispute.closed`, status `won` | `dispute_won` | `wplc/dispute_won( $license_id, $order_id, $dispute_id )` per license. The license is **not** reactivated: whether the customer gets it back is the shop's decision |
| `charge.dispute.closed`, other status (`lost`, `warning_closed`) | `dispute_closed` | `wplc/dispute_closed( $order_id, $license_ids, $dispute_id, $status )` |
| `charge.dispute.funds_reinstated` | `dispute_funds_reinstated` | `wplc/dispute_funds_reinstated( $order_id, $license_ids, $dispute_id )` |
| `radar.early_fraud_warning.created` | `stripe_early_fraud_warning` | `wplc/stripe_early_fraud_warning( $order_id, $license_ids, $warning_id, $fraud_type )`. Alert only; refunding now usually avoids the dispute |

## Manual-review outcomes

An event ends as `manual_review` and extends nothing when:

- the IDs in the event do not match renewal, license and order (audit `stripe_webhook_mismatch`),
- the renewal is `cancelled`, `expired` or `refunded`, or the license is `revoked` or `suspended` (audit `stripe_webhook_manual_review`). Money may have been taken for a license that no longer runs: check in Stripe and refund or extend by hand,
- amount or currency differ from the order (audit `stripe_webhook_amount_mismatch`),
- the WooCommerce Stripe gateway is not loaded, so the amount cannot be compared,
- a success event carries a status other than `succeeded`,
- the order was already paid by another PaymentIntent (audit `renewal_double_payment`, see above).

`manual_review` events of the last 24 hours and events still pending are counted on the diagnostics page and trigger `wplc/health_alert`; see [MONITORING.md](MONITORING.md). There is no admin screen for the inbox; inspect the table `{prefix}wplc_event_inbox` and the audit log.

## The gateway's own webhook

The WooCommerce Stripe gateway resolves `payment_intent.*` events to an order through the metadata. Renewal PaymentIntents carry the same metadata as the gateway's own payments (`order_id`, `order_key`, `site_url`, `signature`, `payment_type=recurring`), so its refund and dispute handling recognises the order. To keep it from also processing the result of every automatic attempt (note "SCA authentication failed", status change, mail to the shop), the plugin answers the gateway's filter `wc_stripe_allowed_payment_processing_statuses` with an empty list inside the gateway's webhook request for a renewal order whose only PaymentIntent is the adapter's. As soon as the customer starts paying the order on the pay page, the gateway stores its own intent on the order and gets its events again. Read from the gateway source (9.6.0, 11.2.0), not run.

## Known gaps

- Nothing here was verified with events sent by Stripe.
- Events locked out by a running charge are retried through WP-Cron after 30 seconds, also when Action Scheduler is in use.
- The settings and diagnostics screens use `StripeWebhook::is_configured()`, so a site configured through `WPLC_STRIPE_WEBHOOK_SECRETS` or with the test secret alone is shown correctly; diagnostics also list the last valid event per mode and the last rejected request.
- The mode of the last event is recorded as a heartbeat (`stripe_webhook_live`, `stripe_webhook_test`) but not shown on the diagnostics page yet.
