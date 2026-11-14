# PayPal recurring payments: compatibility boundary

WP License Captain does not currently charge a WooCommerce PayPal token for its own license renewals.

The official WooCommerce PayPal Payments extension implements scheduled renewal handling through WooCommerce subscription objects and the corresponding subscription integration. Its PayPal Subscriptions module also checks for WooCommerce Subscriptions before registering its subscription behavior. A saved PayPal Vault token must therefore not be treated as a generic permission to submit an arbitrary off-session order charge.

References:

- [WooCommerce PayPal Payments repository](https://github.com/woocommerce/woocommerce-paypal-payments)
- [PayPal Subscriptions API](https://developer.paypal.com/docs/subscriptions/)
- [PayPal subscription webhooks](https://developer.paypal.com/subscriptions/webhooks/)

## Current behavior

The registry only exposes adapters that explicitly register. No PayPal adapter is registered. A license can still be bought with PayPal: the purchase is never blocked. If the customer ticks the automatic renewal option and pays with PayPal, no consent is stored, the order gets a note, and the license runs for the paid period and is renewed manually. This applies to the classic and the block checkout. The plugin never claims that a PayPal purchase will renew by itself.

## Required design before enabling PayPal

A PayPal integration must use a PayPal-approved vaulted payment source or PayPal Subscriptions flow, record the customer approval/mandate and external subscription ID, verify webhook signatures, process duplicate and out-of-order events through the event inbox, and map PayPal billing state to WooCommerce renewal orders and WP License Captain license periods. It must be validated against the actual WooCommerce PayPal Payments release and merchant account configuration.

Do not ship a filter-only adapter or invoke private plugin methods as a substitute for that integration. If the store uses the native PayPal Subscriptions module, the product model and order flow must explicitly support its subscription object; the current license product is not a WooCommerce Subscription product.

## Decision: which payment methods renew automatically (B-63, decided 14 November 2026)

Today exactly one case renews automatically: a card saved through the official WooCommerce Stripe gateway, charged off-session. Everything else is renewed by the customer on the pay page (any payment method), after the usual reminders.

| Payment method at purchase | Automatic renewal today | What would be needed |
| --- | --- | --- |
| Card through Stripe | Yes | – |
| Link, Amazon Pay, Apple/Google Pay without a saved card (Stripe) | No. The order gets a note and the customer a hint (`wplc_renewal_unavailable_customer_hint`); no other card on file is taken instead | Decide whether a wallet token may be charged off-session; depends on the wallet's own mandate rules |
| SEPA Direct Debit (Stripe) | No | An adapter of its own: mandate reference and mandate text at checkout, a renewal state "collection running" (a debit settles after days), pre-notification deadlines, and the reversal of a renewal when the debit is returned weeks later |
| Klarna and other pay-later methods (Stripe) | No | Not designed for unattended repeat charges; would need the provider's recurring product |
| PayPal | No, see above | A PayPal-approved vault or subscription flow |

**Decided by the owner on 14 November 2026:**

1. **Automatic renewal at launch is card-only (Stripe).** SEPA Direct Debit, PayPal and every other payment method renew manually: the customer gets the reminder emails and pays the renewal in the account with any payment method the shop offers. Whether a SEPA adapter is built is decided later; until then no further adapter exists and the table above describes the product.
2. **When the stored card is gone, no other card of the customer is ever charged.** The renewal is not attempted with another saved method, even if the customer has one on file. The customer gets the email "saved card no longer available" (`wplc_payment_method_missing`, once per period) and either adds a card and switches automatic renewal on again under "Licenses", or renews manually.

Technical precaution that is already in place: a PaymentIntent in the state `processing` (which card payments normally never reach, but asynchronous methods do) is no longer treated as "the customer must authenticate". The renewal waits (`renewal_payment_processing`, no failed attempt, no mail) and is booked when Stripe reports success, either through `payment_intent.succeeded` or at the next look.
