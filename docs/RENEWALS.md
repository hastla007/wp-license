# Renewals

Describes what the code does today. Nothing on this page has been run against a live shop or a Stripe test account yet: everything is **implemented, not yet verified in a shop** (see [TODO.md](TODO.md), T-02 and T-12).

Source: `src/Billing/*`, `src/License/LicenseLifecycle.php`, `src/License/LicenseStateMachine.php`, `src/License/PlanChange.php`, `src/Gateway/*`.

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

The Stripe adapter also writes the attempt counter into the PaymentIntent metadata (`wplc_attempt`, next to `order_id`, `wplc_renewal_id` and `wplc_license_id`; T-155), so a `payment_intent.payment_failed` webhook can be matched to the attempt. Before the request it records the counter on the order (`_wplc_stripe_attempt_marked`). A repeated request of an attempt whose first request was sent before this change (unknown result recorded, no marker recorded for that attempt) is sent without `wplc_attempt`, because Stripe rejects a reused idempotency key with changed parameters.

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
| Stripe `payment_intent.payment_failed` webhook for this attempt (`wplc_attempt` = `attempt_count`, amount and currency match) | Normal failure, as for a decline found by a check (audit `renewal_result_resolved`, reason `stripe_decline_reconciled`) |
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

A Stripe `payment_intent.payment_failed` webhook for the attempt whose result was unknown also resolves an open case, like **Mark as not paid**: the attempt is recorded as a decline (audit `renewal_result_resolved`, reason `stripe_decline_reconciled`), the order becomes Failed with a note, the license `past_due` with the usual email, and the case leaves the list. This applies only to cases whose order still records the unknown result of that attempt (`_wplc_result_unknown_since`) and is unpaid and not a manual renewal; cases with a paid order (`license_changed_during_charge`, late manual payments) are never touched. Stripe reports the failure of a PaymentIntent as definite; the case existed only because that answer had not arrived.

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

A refund of a plan upgrade order is described under "Plan changes" below.

## Plan changes

A license can change to another plan of the same product (T-110): for example Solo → Business (more live sites, more features) or back. The license, its key, its paid period (`paid_until`) and its site activations stay. What changes: the plan key, the entitlement snapshot (including `sites.live_limit`), and the product and amount used for future renewals (`renewal_product_id`, `renewal_amount_minor`).

Source: `src/License/PlanChange.php` (core, shared by all paths), `src/Billing/PlanUpgrade.php` (customer account, upgrade order, refund).

### Which plans are offered

Plans are WooCommerce products or variations mapped in the catalog (`src/Catalog/Catalog.php`). A license can change to every active mapping with

- the same product key and a different plan key,
- the same billing interval (a monthly license cannot become a yearly one this way),
- a simple product or a variation with a regular price in the license's currency and precision (a mapping on a variable parent has no price of its own and is skipped). The customer only sees products that can be bought.

The catalog has no ranking of plans: a plan whose current regular price is higher than the current regular price of the license's product (`renewal_product_id`; the stored amount if that product is gone) is an **upgrade**; a cheaper or equally priced one is a **downgrade**. A target is identified by its product or variation ID, because catalog rows get new IDs whenever a mapping is saved.

### Upgrade (customer, prorated, immediate)

The account page shows "Upgrade to …" for each upgrade target of an `active` license inside its paid period, with the amount due now and the price per period afterwards. Not offered while the renewal of the next period is being charged, its result is unknown, it is under review, or it waits for customer authentication.

**Price (T-109).** `PlanChange::upgrade_amount()`: `(new price − old price) × remaining time / period length`, in integer minor units with `Money::prorate()` (half up), never negative. Both prices are the current regular prices of the two products. Remaining time is `paid_until − now`; the period length is one billing interval ending at `paid_until` (`PlanChange::period_start_before()`, same end-of-month rule as the renewal periods). If nothing is left to pay (last seconds of the period), no upgrade is offered.

**Order.** `admin-post.php?action=wplc_start_upgrade` (nonce `wplc_start_upgrade_{license_id}`, owner only) creates one WooCommerce order (created via `wplc_plan_upgrade`) with one line: the target product at the prorated amount. Tax works as for manual renewals: the amount is a catalog price, converted with `wc_get_price_excluding_tax()` for the target product (`RenewalOrder::set_line()`), WooCommerce adds tax from the customer's address. A second click re-prices the same unpaid order (one open upgrade order per license); a paid but not yet applied order, or one waiting for an offline payment, blocks a new one. The customer pays on the normal pay page with any payment method.

**Payment.** `woocommerce_payment_complete` and the status changes to processing/completed (`PlanUpgrade::handle_paid_order()`) switch the plan under the renewal lock of the next period (if that renewal exists) and then the license lock. Before switching, the order is checked against the license: same customer, status `active`/`past_due`, `paid_until` unchanged and still in the future, plan, product and previous upgrade order unchanged, the target mapping still the same plan, the pre-tax line equal to the priced amount (`_wplc_renewal_net_minor`) and the currency unchanged, and the consent present for an automatically renewing license. The switch is a conditional update on exactly those values; `licenses.plan_order_id` records the order. A duplicate hook finds `plan_order_id` equal to the order and does nothing. If the lock is busy (a renewal is running), the application is queued again (`wplc_apply_plan_upgrade`).

After the switch:

- entitlements: the target plan's entitlements, ending at the unchanged `paid_until`; keys the new plan does not have are removed,
- `renewal_product_id` = target product, `renewal_amount_minor` = target price (the amount shown and consented to),
- a scheduled downgrade is dropped,
- an unpaid manual renewal order of the next period is re-priced with the new plan (paid and on-hold orders are not touched),
- audit `plan_upgraded` (reason `plan_upgraded_from_order`), order note, order meta `_wplc_upgrade_state = applied`, action `wplc/license_plan_changed`.

An upgrade order never issues a license (`LicenseIssuer` skips orders with `_wplc_upgrade_for_license`).

**Paid, but not applicable.** If a check fails (for example the term was changed by an administrator, a renewal was booked, the license was suspended, the plan was changed meanwhile), the license is not changed. The order gets a note, `_wplc_upgrade_state = review` and `_wplc_upgrade_review_reason`; audit `plan_upgrade_rejected`; the license detail page shows a warning with the order link. The shop refunds the order, or changes the plan with "Change plan" if the customer keeps it. These cases are not listed under "Renewals to review".

A payment that arrives days after the order was created (bank transfer) pays the amount that was quoted then, which covered more remaining days than are left: the customer pays slightly more, never less. The order is applied as long as the period has not changed.

**Refund.** A **full** refund of an applied upgrade order (`woocommerce_order_fully_refunded`, status `refunded`) restores the previous plan (entitlements of the previous plan's current catalog mapping, the previous renewal product and amount, the previous `plan_order_id`), audit `plan_upgrade_reverted` and `plan_upgrade_refunded`, `_wplc_upgrade_state = reverted`. This happens only while the upgraded period is still running (`paid_until` unchanged) and the plan was not changed since, and only if the previous mapping still exists. Otherwise the refund is only recorded (`plan_upgrade_refunded` with `reverted: false`, order note, state `refunded`): after a renewal at the new plan the customer has paid the new price for the current period. The license is never revoked by an upgrade refund. A **partial** refund changes nothing (audit `plan_upgrade_partially_refunded`, order note). A Stripe dispute of an upgrade order suspends the license like a dispute of its purchase.

### Automatic renewal after an upgrade (consent)

The automatic renewal charges `renewal_amount_minor` off-session. The customer agreed to the old amount only, so it may rise only with a new consent:

- For a license with automatic renewal switched on (`renewal_enabled = 1`, also while a cancellation is pending, which the customer could withdraw), the upgrade form shows a required checkbox: "I agree that my saved payment method is charged {price} {per year/month} automatically from the next renewal on {date}, until I cancel." The handler refuses the upgrade without it (`upgrade_consent_required`). The time of the consent is stored on the order (`_wplc_upgrade_recurring_consent_at`), the consented amount in `_wplc_upgrade_renewal_minor`; the audit entry `plan_upgrade_started` records `recurring_consent`, the order note on payment repeats amount and time.
- Without consent (administrator change, see below) a more expensive plan keeps the old automatic amount; a cheaper plan lowers it (`PlanChange::next_renewal_amount()`).

Legal note (not legal advice): for off-session charges the card networks and the EU/German consumer rules expect the customer to have agreed to the amount and frequency of the recurring payment. A higher amount than the one agreed at the original checkout is treated here as a new agreement and is only charged with the recorded consent above. The shop's terms should describe plan changes and their effect on the recurring amount; the shop may want to send its own confirmation email (hook `wplc/license_plan_changed`). Have the wording reviewed for your jurisdiction.

### Downgrade (at the next renewal)

The account page shows "Switch to … at the next renewal" for each downgrade target (`wplc_schedule_downgrade`, nonce `wplc_schedule_downgrade_{license_id}`; licenses in `active` or `past_due`). Nothing is refunded and nothing changes now: the license stores the pending plan (`pending_plan_key`, `pending_product_id`, schema 16; audit `plan_change_scheduled`). The account shows "Changes to … with the next renewal" and a "Keep current plan" button (`wplc_cancel_plan_change`, audit `plan_change_cancelled`).

The next renewal is billed with the pending plan (`RenewalOrder::price()`, `RenewalOrder::choose_pending_price()`):

- manual renewal: the current regular price of the pending plan's product, with that product on the order line;
- automatic renewal: that price, but never more than the stored amount (consent);
- if the pending product has no usable price (deleted, other currency), the renewal is billed with the current plan and the change waits.

When a renewal order is booked (automatic charge, manual payment, booking after review), `PlanChange::apply_pending_after_renewal()` switches the plan if that order was billed with the pending product: plan key, entitlements (ending at the new `paid_until`), `renewal_product_id`, `renewal_amount_minor` (the price charged), audit `plan_changed_at_renewal`. An order that was priced before the downgrade was scheduled (for example an automatic retry order, or a manual order the customer was already paying) renews the current plan; the change waits for the next renewal. A pending change whose catalog mapping was removed or changed is dropped at the next renewal (audit `plan_change_dropped`). A license that does not renew (cancelled, expired) never applies its pending change. An upgrade replaces a pending downgrade.

### Fewer live sites

A lower `sites.live_limit` never deactivates sites. Sites above the new limit stay active and keep working; new activations are refused (`site_limit`, HTTP 409) until the customer has released enough sites in the account or in the product. The account page says so before the change is scheduled. The same applies to an administrator's change.

### Administrator change

License detail → "Change plan" (see [SUPPORT-ACTIONS.md](SUPPORT-ACTIONS.md#change-plan)): any target of the same product and interval (also products that cannot be bought), "Now" or "With the next renewal", reason required. No order, no charge, no refund; the same core service and locks. "Now" keeps the paid period, the automatic amount is not raised (see consent above), audit `admin_plan_change`. "With the next renewal" stores the pending plan like a customer downgrade (any target, also a more expensive one; the automatic amount stays capped at the stored amount).

### Order metas of an upgrade order

| Meta | Meaning |
| --- | --- |
| `_wplc_upgrade_for_license` | License ID |
| `_wplc_upgrade_from_plan`, `_wplc_upgrade_from_product`, `_wplc_upgrade_from_renewal_minor`, `_wplc_upgrade_from_plan_order` | Plan, renewal product, renewal amount and upgrade order of the license when the order was priced (checked on payment, restored on refund) |
| `_wplc_upgrade_to_plan`, `_wplc_upgrade_to_product` | Target plan and product |
| `_wplc_upgrade_paid_until` | End of the period the price was prorated for |
| `_wplc_upgrade_renewal_minor` | Renewal amount from the next period (target price) |
| `_wplc_upgrade_recurring_consent_at` | UTC time of the consent to the new automatic amount |
| `_wplc_upgrade_state` | `applied`, `review`, `reverted` or `refunded` |
| `_wplc_upgrade_review_reason` | Reason code when the payment could not be applied |
| `_wplc_renewal_net_minor`, `_wplc_renewal_price_decimals` | Priced pre-tax line total, as for renewal orders |

### Limits

- Same billing interval only; no change between monthly and yearly.
- No credit for a downgrade and no immediate downgrade by the customer.
- Not verified in a shop or against Stripe yet.

## Taxes

The stored price is a catalog price: gross if the shop enters prices including tax, net otherwise. `RenewalOrder::set_line()` converts it with `wc_get_price_excluding_tax()` before adding the order line, then lets WooCommerce calculate tax from the customer's address. The pre-tax line total is stored in `_wplc_renewal_net_minor` and compared (integer minor units, no floats) before a payment is booked. Amounts are compared with `WPLC\Billing\Money`.

## Requirements and limits

- A single writable MySQL primary: the locks use `GET_LOCK`.
- Action Scheduler is used when available, otherwise WP-Cron. The schedule must actually run.
- Classic checkout for the opt-in.
- Stripe webhooks are needed for disputes and for payments that settle later; see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).

## Known gaps

- Not verified against Stripe: card saving at first purchase, off-session charge, 3-D Secure, declines, timeouts.
- An unknown result is only resolved by re-sending the same request (idempotent replay), by a Stripe webhook or by hand. The adapter contract has no read-only status lookup. A `payment_intent.payment_failed` webhook resolves it only for PaymentIntents that carry `wplc_attempt` (created after T-155); for older ones the hourly check or the review decides.
- A paid manual renewal that is rejected is not refunded automatically; "Renewals to review" links to the order, the refund is made there.
- A suspended license whose paid period has ended cannot be reactivated from the admin (reactivation needs a future `paid_until`, "Change term" is not offered for suspended licenses), so a review case for it can only be closed.
- Partial refunds of renewal orders are ignored.
- Licenses with automatic renewal that were issued before schema 13 for a variation of a parent-mapped variable product keep the stored amount, which may be the lowest variation price. It is not raised automatically; their manual renewals use the variation price.
