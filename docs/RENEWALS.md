# Automatic renewals

WP License Captain only charges a saved payment method through a registered `RecurringGatewayAdapter`. A token alone is not proof of permission to make off-session charges; the checkout consent and gateway adapter must both be present. See WooCommerce's [Subscriptions payment token guidance](https://woocommerce.com/document/subscriptions/develop/payment-gateway-integration/).

## Adapter contract

A gateway extension registers instances through `wplc_recurring_gateway_adapters`. Each adapter identifies its WooCommerce gateway and must explicitly report whether it supports checkout consent and the saved token. `charge()` must send the supplied idempotency key to the provider and return success only after the provider confirms payment. Do not store card numbers or security codes in WP License Captain.

Renewal processing takes a per-renewal database advisory lock before creating an order or calling a gateway; the same renewal cannot be processed concurrently by duplicate scheduler jobs. The `wplc_payment_token_id_for_order` filter supplies a WooCommerce token ID for an order. The issuer verifies the token's customer ownership and adapter support before enabling automatic renewal. No Stripe or PayPal adapter ships in this milestone; license checkout therefore requires a separately installed and reviewed adapter. Classic checkout hooks are implemented; Checkout Blocks need a compatible adapter integration before they can be marketed as supported.

## Renewal behavior

A renewal row is idempotently keyed by license and period. The processor creates one WooCommerce renewal order and reuses it across retry attempts. It retries on days 1, 3, and 7 from the original due time, then ends the license after the 14-day grace period. Cancellation at period end disables future charges. Adapter errors are written to the audit log as error codes; do not put payment credentials or raw gateway payloads in logs.

## Operational requirements

- Configure Action Scheduler (recommended) or WP-Cron and monitor pending renewal actions.
- Configure and test a gateway adapter in a staging store before selling automatic renewal.
- Confirm consent language, renewal price disclosure, cancellation flow, and applicable legal requirements for the sales region.
- Payment state confirmation must be idempotent. Reconcile ambiguous provider timeouts against the provider before retrying a charge.
