# 8. Renewals and Stripe

A license runs for one billing period (month or year) and is renewed either **manually** by the customer or **automatically** by charging a card saved through Stripe. Both ways use the same renewal record per period and the same WooCommerce order, so a period can be paid only once. The license key never changes.

| | Automatic | Manual |
| --- | --- | --- |
| Started by | The scheduler at the end of the paid period | The customer, "Renew now" in the account |
| Payment method | Card saved through the WooCommerce Stripe gateway | Any payment method on the WooCommerce pay page |
| Price | The renewal amount stored on the license | The same stored amount (price guarantee) |
| Requires | Opt-in at checkout and a saved card | License inside the renewal window |

## Manual renewal

- **Window:** 30 days before to 30 days after the end of the paid period, for licenses that are `active`, `past_due` or `expired`. The account shows "Renew now"; it creates (or reuses) a renewal order and sends the customer to its pay page.
- **New period:** a payment within the grace period continues from the old end date (paying late gifts no days). For an expired license, or after the grace period, the new period starts at the payment.
- **Expired licenses:** a manual renewal inside the window is the only payment that reactivates an expired license.
- With taxes enabled, a renewal cannot start without a billing country.
- A paid renewal that cannot be applied (for example a bank transfer after the window, a license suspended in the meantime) changes nothing and becomes a case under **WooCommerce → Renewals to review** (below).
- An unpaid manual renewal order is cancelled once the window of its period has passed.
- **Cash on delivery:** WooCommerce sets such an order to "processing" before any money arrived. A renewal (and a plan upgrade) paid that way counts when you set the order to "completed", like a first purchase. The setting **Payment methods paid on delivery** (License settings → Rules of this shop, default `cod`) names the payment methods this applies to; the filter `wplc_issue_after_completion_gateways` decides last.

## Automatic renewal

### Consent and saved card

- The classic checkout shows an unchecked opt-in checkbox when the cart contains a license product and a payment method that can renew is enabled. The block checkout offers the same opt-in as an additional checkout field (only for carts with a license product). The settings page warns when your checkout cannot offer the opt-in.
- With consent, the Stripe gateway is asked to save the card. The license renews automatically only when the consent, a saved Stripe card (`pm_…`) of the order's customer and a Stripe customer ID exist; otherwise it is issued for one period without automatic renewal.
- **Card only.** Automatic renewal exists for cards saved through the official WooCommerce Stripe gateway. PayPal, SEPA Direct Debit and every other method renew manually through the reminder email and the account. Do not advertise automatic PayPal renewals.
- **Never another card.** When the saved card is gone, no other card of the customer is charged. The customer gets the email "Payment method missing" and can renew manually or opt in again.

### The charge

1. At the end of the paid period the scheduler (Action Scheduler, WP-Cron as fallback) runs the renewal. It creates the renewal order once and reuses it for every attempt.
2. **Advance notice first.** A renewal is only charged when the "Upcoming automatic renewal" email for that period was accepted by the mailer. Otherwise the charge is postponed by six hours and the missing notice is sent; a charge never happens in the same run as its announcement. Shops that announce charges in another documented way can switch the rule off with `add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );`.
3. Right before the charge the license is read again: a cancellation, revocation, refund or switch-off that happened in the meantime is never followed by a charge.
4. The card is charged off-session with an idempotency key per attempt, so a repeated request can never charge twice.

### Retries and the grace period

- After a declined payment the license becomes `past_due` (the customer gets "Renewal payment failed") and is retried on day 1, 3 and 7 after the due date (filter `wplc_renewal_retry_days`). A decline that must not be retried (lost or stolen card) stops at once; the customer has to pay the order.
- During the **grace period of 14 days** (filter `wplc_renewal_grace_days`, 1 to 90; keep it longer than the last retry day) a `past_due` license, or one with automatic renewal pending, keeps access. At its end the license expires.
- A payment whose result is unknown (timeout, crash) is not a decline: the license keeps its status, the customer cannot pay twice, and the hourly check repeats the same request until Stripe answers definitely. After 12 hours without an answer the renewal goes to review.
- 3-D Secure: when the bank asks for authentication, automatic retries stop and the customer account shows "Complete renewal payment", which leads to the pay page of the same order.

### Combining renewals of one customer

Off by default. With **WooCommerce → License settings → Combine automatic renewals** switched on (or `add_filter( 'wplc_combined_renewals', '__return_true' );`), licenses of one customer that renew automatically on the same day (UTC) with the same saved card, currency and billing interval are charged with **one order and one payment** (one line per license) instead of one charge per license. Card issuers then see one charge instead of several identical ones, and the customer gets one renewal email naming all licenses.

- Each license keeps its own renewal record. If the payment is declined, every license is `past_due` and is retried on its own; one "payment failed" email names all of them.
- If the result is unknown, all licenses wait together; the same request is repeated, never a second charge.
- Manual renewal ("Renew now") is always per license. Licenses with a pending plan change, another card, currency or interval are not combined.
- A full refund of the combined order takes back every license; refunding one line in full takes back that license only. A dispute concerns every license of the order.

### Cancellation

The customer cancels in the account (or on the public cancellation page) and can withdraw the cancellation while the license runs. A cancelled license is not charged and ends at the end of its paid period, without a grace period. Payment methods deleted by the customer, privacy erasures and deleted accounts switch automatic renewal off as well.

## Renewal price

- The price is the amount stored on the license at purchase. **Every renewal, automatic or manual, is billed with it**, also when you change the product price later. A plan change sets the amount of the new plan (chapter 4).
- Whether the stored amount is gross or net is recorded per license; WooCommerce adds tax from the customer's billing address.
- **Currency.** A license renews in the currency of its order and is never converted. Changing the shop currency later does not change existing licenses: a license bought in EUR keeps renewing in EUR at its stored amount. With a multi-currency plugin, a license bought in the visitor's currency stores that currency and the price paid for one unit (before coupons, without tax). Every renewal order carries the license currency; if another plugin changes it, or the payment method does not accept the currency (filter `wplc_renewal_currency_supported`), nothing is charged and the renewal shows the reason `renewal_currency_mismatch` or `renewal_currency_unsupported` until the problem is solved. Upgrades and downgrades are offered only for plans priced in the license currency.
- **Lowering** the amount of a license works at once (license detail → Change renewal amount).
- **Raising it needs an announced price change** with notice:

### A higher renewal price with notice

Announce it for one license on the license page ("New renewal price with notice"), for every license of a product and plan under **WooCommerce → Renewal price change**, or on the command line:

```sh
wp wplc price-change announce --product=acme-reports-pro --plan=single --currency=EUR \
    --amount=59.00 --effective=2027-03-01 --reason="Price list 2027"          # dry run
wp wplc price-change announce … --apply                                       # writes and sends the emails
```

- The date must be at least the notice period ahead (setting "Price change notice period": 60 days by default, never below 30, at most 365). The license must be `active` or `past_due` and the new amount higher than the stored one.
- The customer gets "New renewal price announced" with the current price, the new price, the date and how to cancel the automatic renewal.
- The new amount applies from the later of the announced date and the notice plus the notice period. **Without a recorded notice the new amount never applies**; the health alert `price_change_not_applied` tells you.
- Until it applies, an announcement can be withdrawn (`wp wplc price-change withdraw --license=<id> --reason="…"`); a notified customer gets "Announced renewal price withdrawn".
- Have the notice period and the wording of the email checked for your jurisdiction before the first announcement.

## Refunds and disputes

- A **refund** of the purchase order revokes the refunded licenses (per refunded unit). A **full** refund of a renewal order takes back that period and switches automatic renewal off; the license is not revoked. Partial refunds of renewal orders change nothing, except a refund of a whole line of a combined renewal order, which takes back that line's license.
- A paid order that is set to `cancelled` or `failed` afterwards (a bounced debit) suspends the licenses it paid for; reactivate them in the admin when the money arrives (filter `wplc_suspend_license_on_order_status`).
- A **Stripe dispute** (chargeback) suspends every license paid by that order. Winning the dispute does not reactivate it automatically: that is your decision on the license detail screen.
- An **early fraud warning** from Stripe switches the automatic renewal of the order's licenses off, so the reported card is not charged again; the licenses stay usable until the end of the paid period. Refunding now usually avoids the dispute.

## Renewals to review

**WooCommerce → Renewals to review** lists renewals where money may have been taken but the license was not extended: an automatic payment whose result stayed unknown, a payment that arrived while the license changed (suspended, revoked, term moved, cancelled during the charge), or a manual payment that could not be applied. The menu entry shows the number of open cases. Actions, each with a reason:

- **Book renewal:** the payment was taken; the license is extended (an expired license is reactivated).
- **Mark as not paid** (unknown result only): recorded as a decline; retries continue.
- **Close without booking:** after you refunded the order in WooCommerce, or decided otherwise. Nothing is refunded here.

While a case is open the license is not expired for up to 30 days.

## The Stripe webhook

The webhook is the authoritative reconciliation of payment events. It is separate from the webhook of the WooCommerce Stripe gateway; **both must exist**.

1. Endpoint URL: `https://shop.example/wp-json/wplc/v1/webhooks/stripe` (method `POST`). **WooCommerce → License settings** shows the exact URL with a copy button.
2. In the Stripe dashboard add this endpoint with these events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payment_intent.canceled`
   - `charge.dispute.created`
   - `charge.dispute.closed`
   - `charge.dispute.funds_reinstated`
   - `radar.early_fraud_warning.created`
3. Copy the endpoint's signing secret into `wp-config.php`:

   ```php
   define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_...' );      // endpoint of the live mode
   define( 'WPLC_STRIPE_WEBHOOK_SECRET_TEST', 'whsec_...' ); // endpoint of the test mode (optional)
   ```

   Without a secret every request is answered with 503.

**Test and live mode.** Stripe keeps one endpoint list per mode, each with its own secret. With both constants defined, an event must be signed with the secret of its own mode. A test-mode event is acknowledged and dropped while the Stripe gateway runs in live mode, so a test payment never extends a real license.

**Several sites on one Stripe account** (staging and production, or a network) receive each other's events. Every renewal payment carries a site identifier in its metadata; events of another site are acknowledged and ignored.

**What the receiver does:** it verifies the `Stripe-Signature` (300 seconds tolerance, body at most 1 MiB), stores a minimized copy of the event (IDs, amounts, status; no customer or card data), queues it for reconciliation and answers at once. Unrelated shop payments are acknowledged with 200, so Stripe neither retries them nor disables the endpoint. Before a success event extends a license, renewal, license and order IDs, payment method, amount and currency must match; otherwise the event ends in manual review and the diagnostics page counts it.

**Checking it:** the diagnostics page shows the time of the last valid event per mode, pending events, events that need review and minutes with rejected signatures. A rising count of rejected signatures usually means the secret does not belong to the endpoint. A stored event can be processed again with `wp wplc repair webhook <inbox-id>`.

## Billing pause

Charges, expiries and reminders stop while billing is paused: on a staging copy, after a restore, with `WPLC_PAUSE_BILLING` or when the environment type is not `production`. See chapter 9 before you copy or restore the shop database.

## Hooks for renewals

| Hook | Purpose |
| --- | --- |
| `wplc_renewal_grace_days` | Grace period in days (1 to 90, default 14). |
| `wplc_renewal_retry_days` | Retry days after the due date (default 1, 3, 7). |
| `wplc_renewal_requires_advance_notice` | `false`: charge without the advance-notice rule. |
| `wplc_renewal_charge_allowed` | Postpone a charge for your own reason. |
| `wplc_combined_renewals` | Combine due automatic renewals of one customer into one order and charge (default: the setting, off). |
| `wplc_renewal_currency_supported` | Refuse a renewal in a currency your payment method does not accept. |
| `wplc_renewal_price_in_order_currency` | Supply the regular price of a license bought in another currency (multi-currency plugins). |
| `wplc_reminder_lead_days`, `wplc_renewal_notice_lead_days` | Lead days of reminders and of the renewal notice. |
| `wplc_price_change_notice_days` | Notice period of a price change (30 to 365). |
| `wplc_stripe_webhook_secrets` | Supply the webhook secrets from elsewhere (per site and mode). |
| `wplc/renewal_needs_review`, `wplc/renewal_blocked`, `wplc/license_status_changed` (actions) | React to renewal events; the first two are also passed on as health alerts (chapter 9). |
