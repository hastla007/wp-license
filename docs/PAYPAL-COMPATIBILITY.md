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
