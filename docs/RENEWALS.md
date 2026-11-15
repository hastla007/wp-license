# Renewals

Describes what the code does today. Nothing on this page has been run against a live shop or a Stripe test account yet: everything is **implemented, not yet verified in a shop** (see [TODO.md](TODO.md), T-02 and T-12). The rules below are covered by the integration tests `tests/integration/Renewal*IntegrationTest.php` with a test gateway adapter.

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

### Decisions of 14 November 2026

- **Card-only at launch.** Automatic renewal exists for cards saved through the WooCommerce Stripe gateway. SEPA Direct Debit, PayPal and all other methods renew manually through the reminder email and the account ([PAYPAL-COMPATIBILITY.md](PAYPAL-COMPATIBILITY.md)). A SEPA adapter is decided later.
- **Never another card.** When the card a license renews with no longer exists, the plugin does not charge any other card of the customer. `PaymentReference::resolve_token()` only accepts the stored token or the token row the gateway re-created for the same payment method reference. Otherwise the renewal is postponed with `renewal_payment_method_missing`, the action `wplc/renewal_payment_method_missing( $license_id, $renewal_id, $customer_id, $first )` fires, and on the first report of a period the customer gets the email "saved card no longer available" (`wplc_payment_method_missing`, switchable under WooCommerce → Settings → Emails).

### Advance notice before a charge (B-41)

A due renewal is only charged when the advance notice ("your license renews on …") was accepted by the mailer for the period that ends. `Notifications::charge_allowed()` answers the filter `wplc_renewal_charge_allowed`:

- notice on record (reminder log, type `renewal_notice`, status `sent`): the charge runs;
- no notice (email switched off, mailer refused it, the plugin was inactive a week before): the renewal is postponed for six hours with `last_error = renewal_notice_not_sent`, audit entry `renewal_blocked` and the action `wplc/renewal_blocked`. Nothing counts as a failed payment. The missing notice is sent at that moment, so the next attempt goes through; a charge never happens in the same run as its announcement. While the mailer keeps refusing, the renewal stays postponed and expires unattempted after the manual renewal window.
- The lead time is the setting "Renewal notice lead days" (default 7, at least 1). For a term shorter than twice the lead time the notice is sent at half the term instead of being dropped.
- `add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' )` switches the rule off for shops that announce the charge in another documented way.

### Deleted payment methods (B-57)

One listener (`Maintenance\Orphans::on_payment_token_deleted()`) decides, and `Maintenance\RenewalSwitch::off()` is the only code that switches automatic renewal off (audit action `renewal_disabled`):

| Who deletes the token row | What happens |
| --- | --- |
| The customer (My Account → Payment methods → Delete), a privacy erasure, the deletion of the account | Automatic renewal is switched off, the token and gateway references are removed, open automatic renewals are cancelled. The customer sees a notice and can switch renewal on again with another card. |
| The Stripe gateway while it lists the saved methods (it deletes and re-creates its token rows) | Nothing is switched off. `StripeRecurringAdapter::keep_license_on_token_sync()` detaches the license from the row (audit `payment_token_detached`); the charge finds the re-created row through the payment method reference. |
| Anything else (import, another plugin) | Nothing changes. If the method is really gone, the next charge reports `renewal_payment_method_missing`. |

The filter `wplc_disable_renewal_on_token_deletion( $disable, $token_id, $context )` overrides the decision (`context`: `customer`, `privacy`, `user_deleted`, `gateway_sync`, `other`).

### Charge

1. `RenewalScheduler::schedule_next()` creates the renewal row and schedules `wplc_process_renewal` for `paid_until` (Action Scheduler group `wplc`, WP-Cron as fallback). A license with a scheduled cancellation gets no row.
2. `RenewalProcessor::process()` does nothing while billing is paused (see "Paused billing"). It takes a per-renewal MySQL advisory lock (`GET_LOCK`). If the lock is busy, the job is re-queued with a growing delay (30 seconds up to one hour); from the fifth consecutive failure `wplc/renewal_lock_unavailable` fires.
3. Nothing is charged, and the renewal row becomes `cancelled` with a reason code in `last_error`, when the license is not `active` or `past_due`, when the customer cancelled, when `renewal_enabled` is off, or when the row's `period_start` is no longer the license's `paid_until` (the term was moved). The unpaid order of such a row is cancelled.
4. A job that runs before `retry_at` (duplicate job) re-queues itself and charges nothing.
5. The filter `wplc_renewal_charge_allowed` can postpone the charge (for example until the advance notice was sent).
6. The token and adapter are checked again. The renewal order is created once and reused for every attempt. An order that is already paid is booked instead of charged. An order waiting for an offline payment (`on-hold`) is waited for, see "Not a payment failure".
7. The adapter sends an off-session PaymentIntent. Afterwards the order is read again: if the customer paid it in the meantime, that payment stays on the order and the automatic charge is reported as a double payment (order note, audit `renewal_double_payment`, action `wplc/renewal_needs_review`).

### Booking a payment

Every paid automatic renewal goes through `RenewalProcessor::finish_paid()`:

1. The license is extended first, in one transaction with its entitlements, with a compare-and-set: it only applies while `paid_until` still equals the renewal's `period_start` and the status allows a renewal.
2. Then the renewal becomes `paid`, the next period is scheduled, the audit entry and an order note are written, and only then `wplc/license_status_changed` fires. An exception in a listener can therefore not lose the next period.
3. If step 1 does not apply (license suspended, term moved by an administrator), the payment is **not** reported as a renewal: the renewal becomes `review_required` (reason `license_changed_during_charge`), the order gets a note and the meta `_wplc_renewal_review = yes`, and `wplc/renewal_needs_review` fires. The shop books or refunds it under "Renewals to review".
4. A run that dies between the license update and step 2 is finished by the next reconciliation: it finds the license already at `period_end` and only completes the bookkeeping.

A scheduled cancellation survives every payment: the paid period is granted, `cancel_at_period_end` stays set, and no further period is scheduled. Withdrawing the cancellation schedules the renewal again.

### Not a payment failure

These cases do not count an attempt, do not set the license `past_due` and send no mail to the customer. The reason is stored in `renewals.last_error`.

| Case | Reason code | Next look |
| --- | --- | --- |
| Gateway integration missing (plugin deactivated) | `renewal_gateway_unavailable` | 6 hours |
| Product missing, order cannot be created or linked, amount invalid | `renewal_product_missing`, `renewal_order_*`, `renewal_amount_invalid` | 6 hours |
| Order total differs from the stored amount | `renewal_amount_mismatch` | 6 hours |
| Postponed by `wplc_renewal_charge_allowed` | `renewal_charge_not_allowed` or the code of the returned error | 6 hours |
| Customer has the order open on the pay page (changed in the last 15 minutes) | `renewal_order_in_use` | 15 minutes |
| Order waits for an offline payment the customer started | `renewal_awaiting_customer_payment` | 1 day |

The first four fire `wplc/renewal_blocked( $renewal_id, $code, $license_id )` so monitoring can alert. A gateway adapter can report a shop-side problem of its own by returning the error code `renewal_configuration_error`. A missing or foreign payment token still counts as a failed attempt (`renewal_method_unavailable`).

### Idempotency key per attempt

The gateway receives `{renewal key}:{attempt_count}` (`RenewalScheduler::attempt_idempotency_key()`). Stripe replays the stored answer for a known key, so a single key for all attempts would return the first decline forever. `attempt_count` advances only after a definite failure was recorded:

- Retry after a recorded decline: new key, new charge request.
- Check of an attempt whose result is unknown (crash, timeout): same key, Stripe replays the first result, the customer cannot be charged twice. A new key is never used before the outcome is known.

The Stripe adapter also writes the attempt counter into the PaymentIntent metadata (`wplc_attempt`, next to `order_id`, `wplc_renewal_id` and `wplc_license_id`; T-155), so a `payment_intent.payment_failed` webhook can be matched to the attempt. Before the request it records the counter on the order (`_wplc_stripe_attempt_marked`). A repeated request of an attempt whose first request was sent before this change (unknown result recorded, no marker recorded for that attempt) is sent without `wplc_attempt`, because Stripe rejects a reused idempotency key with changed parameters.

### Retries

After a definite failure the license becomes `past_due` (this sends the "payment failed" email) and the next attempt is scheduled for day 1, day 3 and day 7 after the period start (filter `wplc_renewal_retry_days`), but never sooner after the failed attempt than the distance between two retry days (1, 2 and 4 days; `RenewalProcessor::next_retry_at()`). After a backlog (plugin inactive, queue stopped, database restored) the attempts therefore keep their distance instead of being used up within minutes. After the last retry no further attempt is made; the row waits with `retry_at` = end of grace and the license expires then. An unknown result is not a definite failure and starts none of this.

An error whose data contains `retryable => false` (lost or stolen card, "do not try again") stops the retries at once: the renewal becomes `manual_action_required` and the customer has to pay the order.

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

- the renewal waits in `reconciling`; the attempt counter stays, so every check uses the same idempotency key,
- a check runs every hour, for at most 12 hours after the first request of the attempt (`RenewalProcessor::RESULT_UNKNOWN_WINDOW`; Stripe forgets idempotency keys after 24 hours) and never after the end of the grace period,
- the order is not set to `failed`,
- the license is **not** set `past_due` and no "payment failed" mail is sent, because the charge may have succeeded.

When the result is still unknown at the end of that window, or a check is no longer safe, the renewal goes to `review_required`:

Automatic attempts stop. The license is not changed. The renewal row stores the reason (`review_reason`, e.g. `result_unknown_timeout`, `renewal_cancelled`, `license_changed_while_unknown`) and the start of the case (`review_at`). Audit entry `renewal_review_required`, an order note with instructions, `wplc/renewal_needs_review`, and `wplc/health_alert` with metric `review_required_renewals` right away and in the daily check (see [MONITORING.md](MONITORING.md)).

The case is listed under **WooCommerce → Renewals to review** (T-154; details and rules in [SUPPORT-ACTIONS.md](SUPPORT-ACTIONS.md#renewals-to-review)). The administrator checks the payment in the gateway dashboard (the PaymentIntent, if known, is in `_wplc_payment_intent_id`) and then, with a reason:

- **Book renewal** if the money was taken: the renewal is booked, the license extended to the end of the charged period (or one period from now if that period is already over), and the order marked paid.
- **Mark as not paid** if it was not: the order becomes Failed and the attempt is recorded as a definite decline (audit `renewal_result_resolved`), the license becomes `past_due` with the usual email, and retries continue with a new idempotency key.
- **Close without booking** after a refund or another decision: renewal `closed`, license unchanged.

Setting the renewal order to Processing/Completed or Failed by hand still works as before and has the same effect as the first two actions (without the administrator's reason in the audit entry).

A Stripe `payment_intent.payment_failed` webhook for the attempt whose result was unknown also resolves an open case, like **Mark as not paid**: the attempt is recorded as a decline (audit `renewal_result_resolved`, reason `stripe_decline_reconciled`), the order becomes Failed with a note, the license `past_due` with the usual email, and the case leaves the list. This applies only to cases whose order still records the unknown result of that attempt (`_wplc_result_unknown_since`) and is unpaid and not a manual renewal; cases with a paid order (`license_changed_during_charge`, late manual payments) are never touched. Stripe reports the failure of a PaymentIntent as definite; the case existed only because that answer had not arrived. A Stripe success webhook books an open case like a paid order.

**Expiry is deferred** while a case is open: the daily job and the grace-end job do not expire the license, for at most 30 days after the case was opened (`LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS`). Access is not extended by this: it ends with the normal grace period (14 days after `paid_until`). After 30 days the license expires normally; the case stays listed and booking it reactivates the license.

**Paid, but the license changed during the charge** (audit reason `license_changed_during_charge`, T-22, B-50). The gateway confirmed the payment, but while the request was running an administrator suspended or revoked the license or moved its term, the original order was refunded, or the license expired. The license is not extended (a suspended or revoked license never is) and the renewal is not recorded as `paid`; it goes to `review_required` (reason `license_changed_during_charge`), the paid order gets a note and the meta `_wplc_renewal_review = yes`, and `wplc/renewal_needs_review` fires. In "Renewals to review": refund the payment on the order and **close** the case, or, if the customer keeps the license, **book** it (an expired license is reactivated; a suspended or revoked one must be reactivated first). A cancellation at period end made while the charge was running does not go to review: the charged period is booked, the cancellation stays, and the next period is not charged.

### Customer authentication (3-D Secure)

When Stripe requires authentication, or the PaymentIntent is `requires_action`, `requires_confirmation` or `processing`, the renewal becomes `manual_action_required` and automatic retries stop. The customer account shows "Complete renewal payment", which links to the pay page of the same order. Paying it extends the license through `reconcile_paid_renewal_order()`; for a `processing` PaymentIntent the `payment_intent.succeeded` webhook does.

### Stuck `processing` reconciliation

A renewal is set to `processing` before the gateway call. If the request dies there:

- a check is scheduled 20 minutes later (`wplc_reconcile_stuck_renewal`),
- the daily job also picks up rows that have been `processing` for more than 15 minutes,
- a job that finds the row in `processing` with a free lock never charges; it only schedules the check.

If the renewal order is paid, the renewal is booked. Otherwise the attempt is treated as "result unknown" (see above): the renewal becomes `reconciling` and later checks use the same idempotency key. The 12-hour window counts from the moment the row entered `processing`, so a row found by the daily job after more than 12 hours goes straight to `review_required`. No charge is made by the reconciliation itself.

### Daily jobs

All run on `wplc_daily_lifecycle`. Each one is isolated by `WPLC\Maintenance\DailyRunner` (the single wrapper for all daily jobs): an exception is logged (WooCommerce log, source `wp-license`), kept in the option `wplc_job_failures` and does not stop the jobs after it. A job that finished writes its heartbeat into the option `wplc_last_run` under its job name, for example `renewal_processor_requeue_overdue_renewals`. `LicenseLifecycle::run_guarded( $job, $callable )` delegates to the same wrapper for code that runs a job outside the daily hook.

| Job | What it does |
| --- | --- |
| `expire_due_licenses` | Expires licenses whose term ended. Works in batches with a time budget of 20 seconds (filter `wplc_expiry_time_budget`) and continues in a follow-up run (`wplc_expire_due_licenses`). Each license is read again before it is expired, and the update is conditional on that `paid_until`, so a license paid during the run stays active. |
| `reconcile_stuck_renewals` | See above. |
| `requeue_overdue_renewals` | Queues every renewal whose `retry_at` passed more than an hour ago (no upper limit; a large backlog is spread over time). |
| `repair_missing_schedules` | Creates the renewal row of the next period for renewing licenses that have none. |
| `reconcile_unbooked_paid_orders` | Books paid renewal orders whose booking job was lost (also orders paid while the plugin was inactive). |
| `requeue_pending_stripe_events` | Queues verified Stripe events again that were never processed. |
| `cleanup_stale_renewals` | Cancels manual renewals nobody paid once their window has passed, and open automatic renewals whose license ended or whose period is no longer current, together with their unpaid orders. |

### Grace period

`LicenseLifecycle::GRACE_DAYS` is 14. `LicenseLifecycle::has_access()` decides whether a license grants updates and features:

- `active` or `past_due` and before `paid_until`: access.
- After `paid_until`: access for 14 more days if the license is `past_due`, or if automatic renewal is on and not cancelled.
- Everything else: no access.

At the end of the grace period `wplc/renewal_grace_ended` expires the license. The daily job is the backstop: it expires `active` licenses without automatic renewal at `paid_until`, and any `active` license 15 days after `paid_until`. A license with a renewal under review is not expired by either job for up to 30 days after the review started (see "Review"). When a license expires or is revoked, its open automatic renewals are cancelled and their unpaid orders are closed, so they cannot be paid without effect; a renewal whose result is unknown goes to review instead. An expired license keeps a manual renewal the customer started.

### Renewals that were never attempted

A renewal whose grace period is over although no charge was ever attempted (queue backlog, plugin inactive, shop-side block) is not expired unattempted: for 30 days after the due date the processor still makes the attempt, and the daily expiry leaves such a license alone (it grants no access after the grace period, only its status stays `active`). If that late attempt fails, the license ends.

### Paused billing

`LicenseLifecycle::billing_paused()` and `RenewalProcessor::billing_paused()` delegate to `WPLC\Maintenance\SiteGuard::billing_paused()` (staging clone, restored database, `WPLC_PAUSE_BILLING`), which applies the filter `wplc_billing_paused` (arguments: paused, reason). While paused, no renewal is charged, no license is expired and none of the daily renewal jobs runs.

### Cancellation

The customer can cancel at period end in the account and withdraw the cancellation while the license still runs. A cancelled license is not charged and ends at `paid_until` without a grace period. The cancellation is kept when an open renewal order is paid afterwards (see "Booking a payment").

## Manual renewal

- **Window:** 30 days before to 30 days after `paid_until` (`LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS`), for licenses in status `active`, `past_due` or `expired`. Not offered when the period is already paid.
- **Start:** the account posts to `admin-post.php?action=wplc_start_renewal` (nonce `wplc_start_renewal_{license_id}`, logged-in owner only). The order is created or reused and the customer is redirected to its pay page.
- **Price:** the current regular price of the purchased product or variation (see "Renewal price"). When it cannot be read, the price of the license's own plan: the plan is found through the product (and variation) the license was bought with, not through the pair product key / plan key, which is not unique (`RenewalOrder::plan_for_license()`). Licenses without a source order are matched by key pair and billing interval; when several plans with different prices remain, the amount stored on the license is used.
- **Billing address:** with taxes enabled, a renewal cannot be started without a billing country; the customer is sent to "edit billing address".
- **Same key:** the license, its key and its activations stay; only `paid_until` moves.
- **New period:** one rule for every way of paying. While the license has not expired and the payment arrives within the grace period (14 days after `paid_until`), the new period continues from the old due date, like an automatic renewal: paying late gifts no days. For an expired license, or after the grace period, the period starts at the payment time: the gap without access is not billed.
- **Billing day:** a period that ends on the last day of a short month only because of clamping (31 January to 28 February) returns to the day of month the license started on (31 March).
- **Expired licenses:** a manual renewal payment is the only payment that reactivates an `expired` license, and only inside the window.
- **Automatic renewal setting:** unchanged. A manual renewal does not switch automatic renewal on and does not remove a scheduled cancellation.
- **Booking:** `RenewalProcessor::handle_paid_order()` reacts to `woocommerce_payment_complete` and the status changes to processing/completed. The license is extended first (compare-and-set on `paid_until`), then the renewal is marked paid; duplicate hooks are harmless.
- **Rejected payments (T-137):** if a paid order cannot be applied (window passed, e.g. a late bank transfer; license suspended or revoked; period changed; order line altered without authorisation; license lock unavailable), the license is not changed. The order gets a note and the meta `_wplc_renewal_review = yes`, audit `manual_renewal_rejected`, `wplc/renewal_needs_review` fires, and the renewal row becomes a review case (`review_required`, reason e.g. `manual_renewal_not_allowed`) listed under "Renewals to review". There the shop books it (a late payment reactivates an expired license for one period from the booking) or refunds the order and closes the case. Cases rejected before the review columns existed are taken over from the audit log by the migration.
- **Abandoned orders:** an unpaid manual renewal is cancelled with its order once the window of its period has passed.

## The renewal order

- `created_via` is `wplc_renewal`; the payment method and its title are set from the gateway.
- One line: the purchased product (or the product of a plan change scheduled for this renewal), named "… (license renewal)", with the visible item meta "License" (ID and key prefix) and "Service period (UTC)".
- Only the billing address is set. No shipping address: with "tax based on shipping address" WooCommerce then falls back to billing, so a saved shipping address in another country cannot change the tax.
- The VAT exemption (`is_vat_exempt`) and the VAT ID metas of the first order are copied while the billing country is unchanged (filter `wplc_renewal_order_copied_meta_keys`).
- Renewal orders do not reduce stock, stay payable when the product is out of stock, and do not grant download permissions again.
- An administrator (`manage_woocommerce`, filter `wplc_can_override_renewal_amount`) can change the amount on the order screen. Saving the items stores the new net amount as the expected one (`_wplc_renewal_amount_override` = user ID, order note, audit `renewal_amount_overridden`); a payment of that amount renews the license. Any other difference is not charged automatically and not booked.
- Orders of renewals that will not be charged any more (license ended, term changed by an administrator, cancellation) are cancelled when unpaid; an `on-hold` order keeps its status and gets a note.

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
| `_wplc_renewal_amount_override` | User ID of the administrator who changed the amount |
| `_wplc_duplicate_transaction_id` | Transaction of an automatic charge that succeeded although the order was already paid |
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
| `failed` | Last attempt failed definitely; `retry_at` is the next attempt or, after the last retry, the end of grace |
| `reconciling` | Result of the last attempt is unknown; checked hourly with the same idempotency key, license untouched |
| `review_required` | Result stayed unknown (or a check was no longer safe), the payment went through after the license was suspended/revoked or its term moved, or a paid manual renewal could not be applied; no automatic attempts, an administrator decides in "Renewals to review". Rows stored as `payment_review` by an earlier development version are moved here by schema step 21 |
| `closed` | A review case closed by an administrator without booking (refunded or decided otherwise); not charged again |
| `manual_action_required` | Customer must authenticate or pay; no automatic retries |
| `manual` | Created by a manual renewal, waiting for the customer's payment |
| `cancelled` | Not charged: cancelled, license ended, or period replaced by an admin term change |
| `expired` | Grace period ended without payment |
| `refunded` | The paid renewal order was refunded in full |

`last_error` holds the reason code of the last change, `last_attempt_at` the start of the last charge attempt, `gateway_attempt_id` the gateway transaction of a successful charge (`last_error` and `last_attempt_at` since schema 12).

## Refund of a renewal order

A **full** refund of a paid renewal order (`woocommerce_order_fully_refunded`, status `refunded`):

- sets the renewal to `refunded`,
- takes the refunded period back: `paid_until` and the entitlement end dates are reduced by the length of that period. This also works after a goodwill extension and when an older period is refunded before a newer one. Only when `paid_until` already lies before the end of the refunded period (an administrator shortened the term) nothing is rolled back,
- writes an order note saying what happened ("taken back" or "NOT rolled back"),
- switches automatic renewal off and cancels open renewal rows,
- does not revoke the license. It ends through the normal rules; with `paid_until` in the past, access ends immediately and the daily job sets it to `expired`.

A partial refund of a renewal order changes nothing on the license.

A refund of the original purchase order revokes the license (per refunded unit, or all licenses on a full refund). The audit entry names the user who refunded and the order and refund numbers. `LicenseLifecycle::admin_restore()` takes a revocation back (reason and acting user required; the caller must check a capability of its own; no admin screen calls it yet).

## Other order status changes

- A paid order that is set to `cancelled` or `failed` afterwards (bounced debit) **suspends** the licenses issued from it, or the license a paid renewal order extended, and fires `wplc/license_needs_review`. Suspension is reversible in the admin. Filter `wplc_suspend_license_on_order_status` (return false to keep the license active).
- When a customer account is deleted, automatic renewal is switched off for its licenses.

## Administrator actions

- `admin_extend()` ("change term") is refused while an automatic charge for the license is in flight (`LicenseLifecycle::last_refusal()` returns `renewal_in_progress`); a renewal stuck after a crash is resolved first. It cancels the open renewals of the old period, including a manual renewal the customer started, and their unpaid orders. With the optional fifth argument it only applies while `paid_until` still is what the administrator saw (`term_changed` otherwise).
- Reactivating a suspended license whose term ended while it was suspended moves it to `expired`; from there "change term" gives it a new term.
- Reactivation and "change term" schedule the renewal of the current period again.
- Status reasons are cut to the 255 characters of the column.

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

The account page shows "Switch to … at the next renewal" for each downgrade target (`wplc_schedule_downgrade`, nonce `wplc_schedule_downgrade_{license_id}`; licenses in `active` or `past_due`). Nothing is refunded and nothing changes now: the license stores the pending plan (`pending_plan_key`, `pending_product_id`, schema 17; audit `plan_change_scheduled`). The account shows "Changes to … with the next renewal" and a "Keep current plan" button (`wplc_cancel_plan_change`, audit `plan_change_cancelled`).

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

The stored price is a catalog price. How it is read is decided per license by `licenses.renewal_amount_includes_tax` (schema 12; the license issuer has to write it at purchase):

| Value | Meaning |
| --- | --- |
| `NULL` | Unknown (all licenses until the issuer writes the flag): gross if the shop enters prices including tax **today**, net otherwise (`wc_get_price_excluding_tax()`). A change of that shop setting changes what these licenses are charged. |
| `1` | The amount is gross. The shop base tax is removed to get the net line; for a customer taxed like the shop base the order total must equal the amount (one minor unit of rounding tolerated), otherwise the order is closed and the renewal is blocked with `renewal_amount_mismatch`. |
| `0` | The amount is net. Tax of the customer's location is added. |

Manual renewals use the current catalog price and read it by the current shop setting. WooCommerce calculates tax from the billing address of the order. The pre-tax line total is stored in `_wplc_renewal_net_minor` and compared (integer minor units, no floats) before an automatic charge and before a payment is booked. Amounts are compared with `WPLC\Billing\Money`.

## Requirements and limits

- A single writable MySQL primary: the locks use `GET_LOCK`.
- Action Scheduler is used when available, otherwise WP-Cron. The schedule must actually run.
- Classic checkout for the opt-in.
- Stripe webhooks are needed for disputes and for payments that settle later; see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).

## Known gaps

- Not verified against Stripe: card saving at first purchase, off-session charge, 3-D Secure, declines, timeouts.
- An unknown result is resolved by re-sending the same request (idempotent replay; the Stripe adapter looks the payment up first), by a Stripe webhook or by hand. A `payment_intent.payment_failed` webhook resolves it only for PaymentIntents that carry `wplc_attempt`; for older ones the hourly check or the review decides.
- A paid manual renewal that is rejected is not refunded automatically; "Renewals to review" links to the order, the refund is made there. The events `wplc/renewal_needs_review`, `wplc/renewal_blocked`, `wplc/license_needs_review` and `wplc/renewal_lock_unavailable` are passed on as `wplc/health_alert`.
- A suspended license whose paid period has ended cannot be reactivated by "Reactivate"; use "Restore with a new end date" (or close the review case).
- Partial refunds of renewal orders are ignored.
- Licenses with automatic renewal that were issued before schema 13 for a variation of a parent-mapped variable product keep the stored amount, which may be the lowest variation price. It is not raised automatically; their manual renewals use the variation price.
- The manual price (current catalog price) and the automatic price (amount agreed at purchase) of the same period can differ.
- The currency of a renewal is the one stored at purchase; a later change of the shop currency is not handled.
- A purchase with quantity N creates N licenses and N separate renewal charges.
- Deleting or trashing an order does not change its licenses.
