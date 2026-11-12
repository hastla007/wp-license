# Automatic renewals

WP License Captain only charges a saved payment method through a registered `RecurringGatewayAdapter`. A token alone is not proof of permission to make off-session charges; the checkout consent and gateway adapter must both be present. See WooCommerce's [Subscriptions payment token guidance](https://woocommerce.com/document/subscriptions/develop/payment-gateway-integration/).

## Adapter contract

A gateway extension registers instances through `wplc_recurring_gateway_adapters`. Each adapter identifies its WooCommerce gateway and must explicitly report whether it supports checkout consent and the saved token. `charge()` must send the supplied idempotency key to the provider and return success only after the provider confirms payment. Do not store card numbers or security codes in WP License Captain.

Renewal processing takes a per-renewal database advisory lock before creating an order or calling a gateway; the same renewal cannot be processed concurrently by duplicate scheduler jobs. The `wplc_payment_token_id_for_order` filter supplies a WooCommerce token ID for an order. The issuer verifies the token's customer ownership and adapter support before enabling automatic renewal. The Stripe adapter in phase 14 supports only the official WooCommerce Stripe gateway's saved card PaymentMethods, and only for logged-in customers who already have a customer-owned card token and a Stripe customer ID. It sends an off-session PaymentIntent through the gateway API layer with the stable renewal idempotency key. Unsupported Stripe methods are rejected. A `requires_action` response stops automatic retries and marks the renewal for manual action; a customer-facing authentication/recovery page is not yet implemented. The adapter depends on WooCommerce Stripe gateway internals and must be revalidated against each supported release. PayPal remains unsupported; do not enable PayPal renewals until its separate Vault/Subscriptions contract is implemented and sandbox-validated. Classic checkout hooks are implemented; Checkout Blocks need a compatible adapter integration before they can be marketed as supported.

## Renewal behavior

A renewal row is idempotently keyed by license and period. The processor creates one WooCommerce renewal order and reuses it across retry attempts. It retries on days 1, 3, and 7 from the original due time, then ends the license after the 14-day grace period. Cancellation at period end disables future charges. Adapter errors are written to the audit log as error codes; do not put payment credentials or raw gateway payloads in logs.

## Operational requirements

- Configure Action Scheduler (recommended) or WP-Cron and monitor pending renewal actions.
- Configure and test a gateway adapter in a staging store before selling automatic renewal.
- Confirm consent language, renewal price disclosure, cancellation flow, and applicable legal requirements for the sales region.
- Payment state confirmation must be idempotent. Reconcile ambiguous provider timeouts against the provider before retrying a charge.


## Stripe webhook endpoint

The limited Stripe adapter accepts events at \`/wp-json/wplc/v1/stripe/webhook\`. Configure the Stripe endpoint signing secret as the server constant \`WPLC_STRIPE_WEBHOOK_SECRET\`; do not place it in an option, source control, or log. The handler verifies the timestamped \`v1\` HMAC against the raw request body and rejects stale signatures. Set a narrow Stripe event selection that includes \`payment_intent.succeeded\`.

Every verified event is stored in the existing event inbox under its unique Stripe event ID and raw-payload SHA-256. Duplicate deliveries return success without repeating the renewal. Successful WP License Captain PaymentIntents are matched by renewal, order, and license metadata under the renewal lock; the WooCommerce order, renewal row, license lifecycle, next renewal, and audit log are reconciled together. Unrelated Stripe payment intents are recorded and ignored. Failed events remain on the scheduled retry path.

Before enabling this endpoint in a live shop, run the signature, replay, out-of-order, duplicate, order mismatch, and payment-timeout recovery cases against a Stripe test-mode endpoint. Secret rotation must update the endpoint secret and server configuration in the same maintenance window.


## PayPal support boundary

The current official WooCommerce PayPal Payments integration documents automatic renewals through PayPal Vaulting or PayPal Subscriptions as requiring WooCommerce Subscriptions. WP License Captain does not currently use WooCommerce Subscriptions and its custom renewal orders do not satisfy that integration contract. Therefore no PayPal recurring adapter is registered, and checkout consent must not advertise automatic PayPal renewal. Revisit this only with a supported gateway API contract and merchant sandbox credentials; do not treat a saved PayPal token by itself as authorization or proof that off-session charging works.

References: [WooCommerce PayPal Payments: Save Payment Methods](https://woocommerce.com/document/woocommerce-paypal-payments/save-payment-methods/) and [Subscriptions](https://woocommerce.com/document/woocommerce-paypal-payments/subscriptions/).
