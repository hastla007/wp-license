# Renewals

Describes what the code does today. Nothing on this page has been run against a live shop or a Stripe test account yet: everything is **implemented, not yet verified in a shop** (see [TODO.md](TODO.md), T-02 and T-12). The rules below are covered by the integration tests `tests/integration/Renewal*IntegrationTest.php` with a test gateway adapter.

Source: `src/Billing/*`, `src/License/LicenseLifecycle.php`, `src/License/LicenseStateMachine.php`, `src/Gateway/*`.

## Two ways to renew

| | Automatic | Manual |
| --- | --- | --- |
| Started by | Scheduler at `paid_until` | Customer, button "Renew now" in the account |
| Payment method | Saved card through a gateway adapter (only Stripe exists) | Any payment method on the WooCommerce pay page |
| Price | Amount stored on the license at purchase (`renewal_amount_minor`) | Current catalog price of the license's own plan |
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
3. If step 1 does not apply (license suspended, term moved by an administrator), the payment is **not** reported as a renewal: the renewal becomes `payment_review`, the order gets a note and the meta `_wplc_renewal_review = yes`, and `wplc/renewal_needs_review` fires. The shop refunds or credits by hand.
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
- Repeat of an attempt whose result was never recorded (crash, timeout): same key, Stripe replays the first result, the customer cannot be charged twice.

### Retries

After a definite failure the license becomes `past_due` and the next attempt is scheduled for day 1, day 3 and day 7 after the period start (filter `wplc_renewal_retry_days`), but never sooner after the failed attempt than the distance between two retry days (1, 2 and 4 days; `RenewalProcessor::next_retry_at()`). After a backlog (plugin inactive, queue stopped, database restored) the attempts therefore keep their distance instead of being used up within minutes. After the last retry no further attempt is made; the row waits with `retry_at` = end of grace and the license expires then.

An error whose data contains `retryable => false` (lost or stolen card, "do not try again") stops the retries at once: the renewal becomes `manual_action_required` and the customer has to pay the order.

### Unknown result (`renewal_result_unknown`)

The adapter returns this code when Stripe gave no usable answer: exception, transport error, an error type other than `card_error`/`invalid_request_error`, or `idempotency_key_in_use`. Then:

- the attempt counter stays, so the repeat uses the same idempotency key,
- the repeat is scheduled one hour later,
- the order is not set to `failed`,
- the license is **not** set `past_due` and no "payment failed" mail is sent, because the charge may have succeeded,
- after 20 hours of unknown results (`RenewalProcessor::UNKNOWN_RESULT_WINDOW`) the renewal goes to `payment_review` instead of being repeated: Stripe forgets idempotency keys after 24 hours, a later repeat could charge twice.

### Customer authentication (3-D Secure)

When Stripe requires authentication, or the PaymentIntent is `requires_action`, `requires_confirmation` or `processing`, the renewal becomes `manual_action_required` and automatic retries stop. The customer account shows "Complete renewal payment", which links to the pay page of the same order. Paying it extends the license through `reconcile_paid_renewal_order()`; for a `processing` PaymentIntent the `payment_intent.succeeded` webhook does.

### Stuck `processing` reconciliation

A renewal is set to `processing` before the gateway call. If the request dies there:

- a check is scheduled 20 minutes later (`wplc_reconcile_stuck_renewal`),
- the daily job also picks up rows that have been `processing` for more than 15 minutes,
- a job that finds the row in `processing` with a free lock never charges; it only schedules the check.

If the renewal order is paid, the renewal is booked. Otherwise the attempt is treated as "result unknown" and repeated with the same idempotency key. No charge is made by the reconciliation itself.

### Daily jobs

All run on `wplc_daily_lifecycle`. Each one is wrapped in `LicenseLifecycle::run_guarded()`: an exception is logged (WooCommerce log, source `wplc`) and does not stop the jobs after it. The time and result of every run are stored in the option `wplc_job_heartbeats` (`job => array( time, ok )`).

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

At the end of the grace period `wplc/renewal_grace_ended` expires the license. The daily job is the backstop: it expires `active` licenses without automatic renewal at `paid_until`, and any `active` license 15 days after `paid_until`. When a license expires or is revoked, its open automatic renewals are cancelled and their unpaid orders are closed, so they cannot be paid without effect. An expired license keeps a manual renewal the customer started.

### Renewals that were never attempted

A renewal whose grace period is over although no charge was ever attempted (queue backlog, plugin inactive, shop-side block) is not expired unattempted: for 30 days after the due date the processor still makes the attempt, and the daily expiry leaves such a license alone (it grants no access after the grace period, only its status stays `active`). If that late attempt fails, the license ends.

### Paused billing

`LicenseLifecycle::billing_paused()` asks `WPLC\Maintenance\SiteGuard::billing_paused()` when that class exists (staging clone, restored database) and applies the filter `wplc_billing_paused`. While paused, no renewal is charged, no license is expired and none of the daily renewal jobs runs.

### Cancellation

The customer can cancel at period end in the account and withdraw the cancellation while the license still runs. A cancelled license is not charged and ends at `paid_until` without a grace period. The cancellation is kept when an open renewal order is paid afterwards (see "Booking a payment").

## Manual renewal

- **Window:** 30 days before to 30 days after `paid_until` (`LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS`), for licenses in status `active`, `past_due` or `expired`. Not offered when the period is already paid.
- **Start:** the account posts to `admin-post.php?action=wplc_start_renewal` (nonce `wplc_start_renewal_{license_id}`, logged-in owner only). The order is created or reused and the customer is redirected to its pay page.
- **Price:** the current catalog price of the license's own plan. The plan is found through the product (and variation) the license was bought with, not through the pair product key / plan key, which is not unique. For a variation bought under a plan mapped on the parent product it is the variation's regular price. Licenses without a source order are matched by key pair and billing interval; when several plans with different prices remain, the amount stored on the license is used. The lookup is public as `RenewalOrder::plan_for_license()`.
- **Billing address:** with taxes enabled, a renewal cannot be started without a billing country; the customer is sent to "edit billing address".
- **Same key:** the license, its key and its activations stay; only `paid_until` moves.
- **New period:** one rule for every way of paying. While the license has not expired and the payment arrives within the grace period (14 days after `paid_until`), the new period continues from the old due date, like an automatic renewal: paying late gifts no days. For an expired license, or after the grace period, the period starts at the payment time: the gap without access is not billed.
- **Billing day:** a period that ends on the last day of a short month only because of clamping (31 January to 28 February) returns to the day of month the license started on (31 March).
- **Expired licenses:** a manual renewal payment is the only payment that reactivates an `expired` license, and only inside the window.
- **Automatic renewal setting:** unchanged. A manual renewal does not switch automatic renewal on and does not remove a scheduled cancellation.
- **Booking:** `RenewalProcessor::handle_paid_order()` reacts to `woocommerce_payment_complete` and the status changes to processing/completed. The license is extended first (compare-and-set on `paid_until`), then the renewal is marked paid; duplicate hooks are harmless.
- **Rejected payments:** if a paid order cannot be applied (period changed, window passed, order line altered without authorisation, renewal already closed), the order gets a note and the meta `_wplc_renewal_review = yes`; the license is not changed. This needs manual handling by the shop.
- **Abandoned orders:** an unpaid manual renewal is cancelled with its order once the window of its period has passed.

## The renewal order

- `created_via` is `wplc_renewal`; the payment method and its title are set from the gateway.
- One line: the purchased product, named "… (license renewal)", with the visible item meta "License" (ID and key prefix) and "Service period (UTC)".
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
| `_wplc_renewal_review` | `yes` when a paid order could not be applied |
| `_wplc_renewal_amount_override` | User ID of the administrator who changed the amount |
| `_wplc_duplicate_transaction_id` | Transaction of an automatic charge that succeeded although the order was already paid |

## Renewal statuses

| Status | Meaning |
| --- | --- |
| `scheduled` | Automatic charge is planned |
| `processing` | A charge attempt is running |
| `paid` | Paid and booked |
| `failed` | Last attempt failed or its result is unknown; `retry_at` is the next attempt or, after the last retry, the end of grace |
| `manual_action_required` | Customer must authenticate or pay; no automatic retries |
| `manual` | Created by a manual renewal, waiting for the customer's payment |
| `payment_review` | A payment was taken but could not be applied, or its outcome stayed unknown. Never charged again automatically; the shop decides |
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
- A payment in `payment_review` or a rejected manual renewal is not refunded automatically, and no admin screen lists them (order note, audit log and the action `wplc/renewal_needs_review` only).
- Partial refunds of renewal orders are ignored.
- The manual price (current catalog price) and the automatic price (amount agreed at purchase) of the same period can differ.
- The currency of a renewal is the one stored at purchase; a later change of the shop currency is not handled.
- A purchase with quantity N creates N licenses and N separate renewal charges.
- Deleting or trashing an order does not change its licenses.
