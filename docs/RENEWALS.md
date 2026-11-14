# Renewals

Describes what the code does today. Nothing on this page has been run against a live shop or a Stripe test account yet: everything is **implemented, not yet verified in a shop** (see [TODO.md](TODO.md), T-02 and T-12).

Source: `src/Billing/*`, `src/License/LicenseLifecycle.php`, `src/License/LicenseStateMachine.php`, `src/Gateway/*`.

## Two ways to renew

| | Automatic | Manual |
| --- | --- | --- |
| Started by | Scheduler at `paid_until` | Customer, button "Renew now" in the account |
| Payment method | Saved card through a gateway adapter (only Stripe exists) | Any payment method on the WooCommerce pay page |
| Price | Amount stored on the license at purchase (`renewal_amount_minor`) | Plan price in the catalog at the time the order is created |
| Requires | Opt-in at checkout and a saved token | License in the renewal window |

Both use the same renewal row per license period (unique key `sha256("wplc:{license_id}:{period_start}")`) and the same WooCommerce order, so a period can be paid only once. The license key never changes.

## Automatic renewal

### Consent (opt-in)

- The classic checkout shows an unchecked checkbox when the cart contains a license product and an enabled payment method has a renewal adapter (`RecurringConsent::render_checkbox()`).
- When it is ticked and the chosen gateway supports renewals, the order gets the meta `_wplc_recurring_consent_at` (UTC timestamp). If the gateway does not support renewals, only an order note is written and the license does not renew.
- With consent, the Stripe gateway is asked to save the card (`wc_stripe_force_save_source`, `wc_stripe_force_save_payment_method`).
- The block checkout renders no checkbox. Licenses bought there never renew automatically. The settings page shows a warning when the checkout page uses the block checkout.
- A license purchase requires a customer account; guest checkout is switched off for carts with a license product.

### Saved token

When the license is issued, `renewal_enabled` is set only if all of these hold: consent meta present, a payment token found for the order (`wplc_payment_token_id_for_order`), the token belongs to the order's customer, an adapter is registered for the order's gateway, and the adapter accepts the token. Otherwise the license is issued for one period without automatic renewal.

The Stripe adapter (`StripeRecurringAdapter`) accepts only saved card PaymentMethods (`pm_…`) of the official WooCommerce Stripe gateway for customers with a Stripe customer ID (`cus_…`). It uses internal classes of that gateway (`WC_Stripe_API`, `WC_Stripe_Helper`), which are not a public API. There is no PayPal adapter.

### Charge

1. `RenewalScheduler::schedule_next()` creates the renewal row and schedules `wplc_process_renewal` for `paid_until` (Action Scheduler group `wplc`, WP-Cron as fallback).
2. `RenewalProcessor::process()` takes a per-renewal MySQL advisory lock (`GET_LOCK`). If the lock is busy, the job is re-queued 30 seconds later.
3. Nothing is charged when the license is not `active` or `past_due`, when the customer cancelled, or when `renewal_enabled` is off; the renewal row becomes `cancelled`.
4. The token and adapter are checked again. The renewal order is created once and reused for every attempt. An order that is already paid is booked instead of charged.
5. The adapter sends an off-session PaymentIntent. On success the order is completed, `paid_until` moves to the end of the period, and the next period is scheduled.

### Idempotency key per attempt

The gateway receives `{renewal key}:{attempt_count}` (`RenewalScheduler::attempt_idempotency_key()`). Stripe replays the stored answer for a known key, so a single key for all attempts would return the first decline forever. `attempt_count` advances only after a definite failure was recorded:

- Retry after a recorded decline: new key, new charge request.
- Check of an attempt whose result is unknown (crash, timeout): same key, Stripe replays the first result, the customer cannot be charged twice. A new key is never used before the outcome is known.

### Retries

After a definite failure the license becomes `past_due` (this sends the "payment failed" email) and the next attempt is scheduled at day 1, day 3 and day 7 after the period start. After the third retry fails, no further attempt is made and the license expires when the grace period ends. An unknown result is not a definite failure and starts none of this.

### Unknown result (`renewal_result_unknown`)

The adapter returns this code when Stripe gave no usable answer: exception, transport error, an error type other than `card_error`/`invalid_request_error`, or `idempotency_key_in_use`. The stuck-`processing` reconciliation (below) produces the same result. The payment may have been taken, so it is not treated as a decline:

- the renewal becomes `reconciling`; the attempt counter stays, so every check uses the same idempotency key,
- the order is not set to `failed` and gets an order note,
- the license keeps its status: it does not become `past_due`, no "payment failed" email is sent, and it keeps access (after `paid_until` through the normal grace rule for licenses with automatic renewal),
- the customer cannot start a manual renewal for that period ("Renew now" is hidden, the start is refused), so the customer cannot pay twice,
- audit entry `renewal_result_unknown` (once per attempt), order meta `_wplc_result_unknown_since` = `{attempt_count}|{unix time of the first request}`.

The outcome is resolved by the first of these:

| Event | Result |
| --- | --- |
| Hourly check (`wplc_process_renewal`) sends the same key; the gateway replays success | Renewal `paid`, license extended |
| The check gets a definite decline | Normal failure: order `failed`, attempt counted, license `past_due` + email, retries with a new key |
| The check gets an unknown result again | Stays `reconciling`, next check one hour later; the window is not restarted |
| Stripe `payment_intent.succeeded` webhook | Renewal `paid`, license extended (see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)) |
| The renewal order is marked paid (e.g. by an administrator) | Booked through `reconcile_paid_renewal_order()` |
| 12 hours after the first request (`RenewalProcessor::RESULT_UNKNOWN_WINDOW`), or the end of the grace period if earlier | Renewal `review_required`, nothing is sent again |

Checks only re-send the request while it is safe. A renewal in `reconciling` goes to `review_required` without a request when the window has passed, the license is no longer `active`/`past_due`, the customer cancelled or automatic renewal was switched off, the payment method or adapter is no longer available, or the renewal order is gone, closed or no longer matches. A revoke, expiry, administrator term change or refund of the license moves an open `reconciling` renewal to `review_required` as well, instead of cancelling it. The 12-hour bound keeps every check well inside the 24 hours Stripe keeps idempotency keys; after that the same key would be a new charge.

### Review (`review_required`)

Automatic attempts stop. The license is not changed. Audit entry `renewal_review_required` (reason e.g. `result_unknown_timeout`, `renewal_cancelled`), an order note with instructions, and `wplc/health_alert` with metric `review_required_renewals` right away and in the daily check (see [MONITORING.md](MONITORING.md)).

The administrator checks the payment in the gateway dashboard (the PaymentIntent, if known, is in `_wplc_payment_intent_id`) and then sets the renewal order:

- **Processing or Completed** if the money was taken: the renewal is booked and the license extended.
- **Failed** if it was not: `wplc_resolve_failed_renewal_order` records a definite decline (audit `renewal_result_resolved`), the license becomes `past_due` with the usual email, and retries continue with a new idempotency key.

Until then the normal rules apply: an `active` license is expired by the daily job 15 days after `paid_until`. Resolve reviews within the grace period.

### Customer authentication (3-D Secure)

When Stripe requires authentication, or the PaymentIntent is `requires_action`, `requires_confirmation` or `processing`, the renewal becomes `manual_action_required` and automatic retries stop. The customer account shows "Complete renewal payment", which links to the pay page of the same order. Paying it extends the license through `reconcile_paid_renewal_order()`; for a `processing` PaymentIntent the `payment_intent.succeeded` webhook does.

### Stuck `processing` reconciliation

A renewal is set to `processing` before the gateway call. If the request dies there:

- a check is scheduled 20 minutes later (`wplc_reconcile_stuck_renewal`),
- the daily job also picks up rows that have been `processing` for more than 15 minutes (up to 50 per run).

If the renewal order is paid, the renewal is booked. Otherwise the attempt is treated as "result unknown" (see above): the renewal becomes `reconciling` and later checks use the same idempotency key. The 12-hour window counts from the moment the row entered `processing`, so a row found by the daily job after more than 12 hours goes straight to `review_required`. No charge is made by the reconciliation itself.

### Grace period

`LicenseLifecycle::GRACE_DAYS` is 14. `LicenseLifecycle::has_access()` decides whether a license grants updates and features:

- `active` or `past_due` and before `paid_until`: access.
- After `paid_until`: access for 14 more days if the license is `past_due`, or if automatic renewal is on and not cancelled.
- Everything else: no access.

At the end of the grace period `wplc/renewal_grace_ended` expires the license. The daily job is the backstop: it expires `active` licenses without automatic renewal at `paid_until`, and any `active` license 15 days after `paid_until`.

### Cancellation

The customer can cancel at period end in the account and withdraw the cancellation while the license still runs. A cancelled license is not charged and ends at `paid_until` without a grace period.

## Manual renewal

- **Window:** 30 days before to 30 days after `paid_until` (`LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS`), for licenses in status `active`, `past_due` or `expired`. Not offered when the period is already paid.
- **Start:** the account posts to `admin-post.php?action=wplc_start_renewal` (nonce `wplc_start_renewal_{license_id}`, logged-in owner only). The order is created or reused and the customer is redirected to its pay page.
- **Price:** the plan's price in the catalog. The catalog stores the product's regular price (not a running sale price) whenever the product is saved. If the plan no longer exists, the amount stored on the license is used.
- **Same key:** the license, its key and its activations stay; only `paid_until` moves.
- **New period:** starts at the later of `paid_until` and the payment time. Renewing early loses no days; renewing late is not billed retroactively.
- **Expired licenses:** a manual renewal payment is the only payment that reactivates an `expired` license, and only inside the window.
- **Automatic renewal setting:** unchanged. A manual renewal does not switch automatic renewal on and does not remove a scheduled cancellation.
- **Booking:** `RenewalProcessor::handle_paid_order()` reacts to `woocommerce_payment_complete` and the status changes to processing/completed. Duplicate hooks are harmless: the renewal row is claimed with a conditional update.
- **Rejected payments:** if a paid order cannot be applied (period changed, window passed, order line altered), the order gets a note and the meta `_wplc_renewal_review = yes`; the license is not changed. This needs manual handling by the shop.

### Order metas

| Meta | Meaning |
| --- | --- |
| `_wplc_renewal_for_license` | License ID |
| `_wplc_renewal_id` | Renewal row ID |
| `_wplc_idempotency_key` | Renewal key of the period |
| `_wplc_manual_renewal` | `yes` for a customer-initiated renewal |
| `_wplc_renewal_net_minor` | Pre-tax line total in minor units when the order was priced |
| `_wplc_renewal_price_decimals` | Precision of that amount |
| `_wplc_payment_intent_id` | Stripe PaymentIntent of the last automatic attempt |
| `_wplc_result_unknown_since` | `{attempt_count}\|{unix time}`: first request of the attempt whose result is unknown |
| `_wplc_renewal_review` | `yes` when a paid order could not be applied |

## Renewal statuses

| Status | Meaning |
| --- | --- |
| `scheduled` | Automatic charge is planned |
| `processing` | A charge attempt is running |
| `paid` | Paid and booked |
| `failed` | Last attempt failed definitely; a retry may be scheduled |
| `reconciling` | Result of the last attempt is unknown; checked hourly with the same idempotency key, license untouched |
| `review_required` | Result stayed unknown (or a check was no longer safe); no automatic attempts, an administrator decides |
| `manual_action_required` | Customer must authenticate or pay; no automatic retries |
| `manual` | Created by a manual renewal, waiting for the customer's payment |
| `cancelled` | Not charged: cancelled, license ended, or period replaced by an admin term change |
| `expired` | Grace period ended without payment |
| `refunded` | The paid renewal order was refunded in full |

## Refund of a renewal order

A **full** refund of a paid renewal order (`woocommerce_order_fully_refunded`, status `refunded`):

- sets the renewal to `refunded`,
- rolls `paid_until` and the entitlement end dates back to the start of the refunded period, if that renewal is the latest one,
- switches automatic renewal off and cancels open renewal rows,
- does not revoke the license. It ends through the normal rules; with `paid_until` in the past, access ends immediately and the daily job sets it to `expired`.

A partial refund of a renewal order changes nothing on the license.

A refund of the original purchase order revokes the license (per refunded unit, or all licenses on a full refund).

## Taxes

The stored price is a catalog price: gross if the shop enters prices including tax, net otherwise. `RenewalOrder::set_line()` converts it with `wc_get_price_excluding_tax()` before adding the order line, then lets WooCommerce calculate tax from the customer's address. The pre-tax line total is stored in `_wplc_renewal_net_minor` and compared (integer minor units, no floats) before a payment is booked. Amounts are compared with `WPLC\Billing\Money`.

## Requirements and limits

- A single writable MySQL primary: the locks use `GET_LOCK`.
- Action Scheduler is used when available, otherwise WP-Cron. The schedule must actually run.
- Classic checkout for the opt-in.
- Stripe webhooks are needed for disputes and for payments that settle later; see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).

## Known gaps

- Not verified against Stripe: card saving at first purchase, off-session charge, 3-D Secure, declines, timeouts.
- An unknown result is only resolved by re-sending the same request (idempotent replay), by the Stripe success webhook or by hand. The adapter contract has no read-only status lookup, and a `payment_intent.payment_failed` webhook does not resolve a `reconciling` renewal (it cannot be matched to the attempt safely).
- There is no admin screen listing `review_required` renewals; use the diagnostics counter, the audit log and the order notes.
- A paid manual renewal that is rejected is not refunded automatically.
- Partial refunds of renewal orders are ignored.
