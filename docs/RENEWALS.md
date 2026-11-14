# Renewals

Describes what the code does today. Nothing on this page has been run against a live shop or a Stripe test account yet: everything is **implemented, not yet verified in a shop** (see [TODO.md](TODO.md), T-02 and T-12).

Source: `src/Billing/*`, `src/License/LicenseLifecycle.php`, `src/License/LicenseStateMachine.php`, `src/Gateway/*`.

## Two ways to renew

| | Automatic | Manual |
| --- | --- | --- |
| Started by | Scheduler at `paid_until` | Customer, button "Renew now" in the account |
| Payment method | Saved card through a gateway adapter (only Stripe exists) | Any payment method on the WooCommerce pay page |
| Price | Amount stored on the license at purchase (`renewal_amount_minor`) | Current regular price of the purchased product or variation when the order is created |
| Requires | Opt-in at checkout and a saved token | License in the renewal window |

See "Renewal price" below for how both amounts are determined.

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

Automatic attempts stop. The license is not changed. The renewal row stores the reason (`review_reason`, e.g. `result_unknown_timeout`, `renewal_cancelled`, `license_changed_while_unknown`) and the start of the case (`review_at`, schema 14). Audit entry `renewal_review_required`, an order note with instructions, and `wplc/health_alert` with metric `review_required_renewals` right away and in the daily check (see [MONITORING.md](MONITORING.md)).

The case is listed under **WooCommerce → Renewals to review** (T-154; details and rules in [SUPPORT-ACTIONS.md](SUPPORT-ACTIONS.md#renewals-to-review)). The administrator checks the payment in the gateway dashboard (the PaymentIntent, if known, is in `_wplc_payment_intent_id`) and then, with a reason:

- **Book renewal** if the money was taken: the renewal is booked, the license extended to the end of the charged period (or one period from now if that period is already over), and the order marked paid.
- **Mark as not paid** if it was not: the order becomes Failed and the attempt is recorded as a definite decline (audit `renewal_result_resolved`), the license becomes `past_due` with the usual email, and retries continue with a new idempotency key.
- **Close without booking** after a refund or another decision: renewal `closed`, license unchanged.

Setting the renewal order to Processing/Completed or Failed by hand still works as before and has the same effect as the first two actions (without the administrator's reason in the audit entry).

**Expiry is deferred** while a case is open: the daily job and the grace-end job do not expire the license, for at most 30 days after the case was opened (`LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS`). Access is not extended by this: it ends with the normal grace period (14 days after `paid_until`). After 30 days the license expires normally; the case stays listed and booking it reactivates the license.

**Paid, but the license changed during the charge** (audit reason `license_changed_during_charge`, T-22). The gateway confirmed the payment, but while the request was running an administrator suspended or revoked the license, the original order was refunded, or the license expired. The license is not extended (a suspended or revoked license never is) and the renewal is not recorded as `paid`; it goes to `review_required` (reason `license_changed_during_charge`), and the paid order gets a note naming the new license status. In "Renewals to review": refund the payment on the order and **close** the case, or, if the customer keeps the license, **book** it (an expired license is reactivated; a suspended or revoked one must be reactivated first). A cancellation at period end made while the charge was running does not go to review: the charged period is booked, the cancellation stays, and the next period is not charged.

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

At the end of the grace period `wplc/renewal_grace_ended` expires the license. The daily job is the backstop: it expires `active` licenses without automatic renewal at `paid_until`, and any `active` license 15 days after `paid_until`. A license with a renewal under review is not expired by either job for up to 30 days after the review started (see "Review").

### Cancellation

The customer can cancel at period end in the account and withdraw the cancellation while the license still runs. A cancelled license is not charged and ends at `paid_until` without a grace period.

## Manual renewal

- **Window:** 30 days before to 30 days after `paid_until` (`LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS`), for licenses in status `active`, `past_due` or `expired`. Not offered when the period is already paid.
- **Start:** the account posts to `admin-post.php?action=wplc_start_renewal` (nonce `wplc_start_renewal_{license_id}`, logged-in owner only). The order is created or reused and the customer is redirected to its pay page.
- **Price:** the current regular price of the purchased product or variation (see "Renewal price").
- **Same key:** the license, its key and its activations stay; only `paid_until` moves.
- **New period:** starts at the later of `paid_until` and the payment time. Renewing early loses no days; renewing late is not billed retroactively.
- **Expired licenses:** a manual renewal payment is the only payment that reactivates an `expired` license, and only inside the window.
- **Automatic renewal setting:** unchanged. A manual renewal does not switch automatic renewal on and does not remove a scheduled cancellation.
- **Booking:** `RenewalProcessor::handle_paid_order()` reacts to `woocommerce_payment_complete` and the status changes to processing/completed. Duplicate hooks are harmless: the renewal row is claimed with a conditional update.
- **Rejected payments (T-137):** if a paid order cannot be applied (window passed, e.g. a late bank transfer; license suspended or revoked; period changed; order line altered; license lock unavailable), the license is not changed. The order gets a note and the meta `_wplc_renewal_review = yes`, audit `manual_renewal_rejected`, and the renewal row becomes a review case (`review_required`, reason e.g. `manual_renewal_not_allowed`) listed under "Renewals to review". There the shop books it (a late payment reactivates an expired license for one period from the booking) or refunds the order and closes the case. Cases rejected before schema 14 are taken over from the audit log by the migration.

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
| `_wplc_review_closed` | `paid` or `unpaid`: the review of this order was closed without booking, and the order's state then. Such an order is never booked automatically; an `unpaid` one that is paid later goes back to review |

## Renewal price

### At purchase

When a license is issued, it stores the **regular price of the product or variation that was actually bought** (`renewal_amount_minor`, `renewal_currency_decimals`, `renewal_currency`) and the ID of that product or variation (`renewal_product_id`, schema 13). A running sale price is never stored. The plan price is only used when the bought product has no usable price, and for licenses issued by an administrator without an order (those record the plan's variation or product as `renewal_product_id`).

This matters for variable products that are mapped only on the parent product: the plan of such a mapping holds the lowest variation price, because WooCommerce reports the lowest variation price for a variable parent. Before T-132 every variation renewed at that lowest price (a variation bought for 79.00 renewed for 59.00).

### Automatic renewal

Charges exactly the stored amount. A later price change in the shop does not change it: the customer consented to off-session charges of that amount, and raising it without notice is not acceptable.

### Manual renewal

The customer sees the amount on the pay page before paying, so the manual renewal follows the shop's current price of what the customer bought:

1. The current regular price of `renewal_product_id`, if that product or variation still exists and has a price. A later regular-price change of the variation therefore applies to manual renewals. This keeps the earlier rule ("current catalog price") but applies it to the bought variation instead of the plan.
2. If the product or variation was deleted or has no price: the stored amount, i.e. the price of that variation at purchase. The plan price is not used, because for parent-level mappings it is the lowest variation price.

Licenses issued before schema 13 have `renewal_product_id = 0`. For them the product is resolved through the source order line (`source_order_id`, `source_order_item_id`). If that does not resolve either, the plan price is used as before, and the stored amount when the plan has no price.

The order line always uses the bought product (or, if it is gone, the plan's product), so its tax class applies.

## Renewal statuses

| Status | Meaning |
| --- | --- |
| `scheduled` | Automatic charge is planned |
| `processing` | A charge attempt is running |
| `paid` | Paid and booked |
| `failed` | Last attempt failed definitely; a retry may be scheduled |
| `reconciling` | Result of the last attempt is unknown; checked hourly with the same idempotency key, license untouched |
| `review_required` | Result stayed unknown (or a check was no longer safe), the payment went through after the license was suspended/revoked, or a paid manual renewal could not be applied; no automatic attempts, an administrator decides in "Renewals to review" |
| `closed` | A review case closed by an administrator without booking (refunded or decided otherwise); not charged again |
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
- A paid manual renewal that is rejected is not refunded automatically; "Renewals to review" links to the order, the refund is made there.
- A suspended license whose paid period has ended cannot be reactivated from the admin (reactivation needs a future `paid_until`, "Change term" is not offered for suspended licenses), so a review case for it can only be closed.
- Partial refunds of renewal orders are ignored.
- Licenses with automatic renewal that were issued before schema 13 for a variation of a parent-mapped variable product keep the stored amount, which may be the lowest variation price. It is not raised automatically; their manual renewals use the variation price.
