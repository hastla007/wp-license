# Stripe webhook receipt

The license server exposes `POST /wp-json/wplc/v1/webhooks/stripe`. Configure the Stripe endpoint to send `payment_intent.succeeded` and `payment_intent.payment_failed` events to this URL. The receiving site must define `WPLC_STRIPE_WEBHOOK_SECRET` in a protected configuration file/environment before enabling the endpoint.

The receiver validates Stripe's `Stripe-Signature` timestamp and v1 HMAC-SHA256 signature with a 300-second tolerance, caps the raw request body at 1 MiB, and stores only minimized renewal identifiers/payment state plus the raw-body SHA-256 in the event inbox. No customer billing data or unredacted webhook body is stored. Event IDs are unique; an exact duplicate gets an accepted duplicate response and reuse of an ID with different bytes is rejected.

The inbox records events as `pending_reconciliation`. This receiver does not itself mark orders paid or extend licenses. Phase 27 must validate the renewal/order/license linkage and amount/currency before reconciliation. Until signature verification, event replay, duplicate/conflict behavior, and reconciliation are exercised in a Stripe test-mode sandbox, do not configure this endpoint for production billing.


Verified events are queued for renewal reconciliation. Success processing checks Stripe PaymentIntent state, renewal/license/order metadata, order payment method, amount, and currency under the same per-renewal database lock as scheduled charging. Duplicate success events do not extend the same renewal twice; mismatches are marked for manual review. Failure events are recorded without rescheduling retries because the synchronous adapter owns retry scheduling.

For a customer action-required payment, the WooCommerce license account page exposes the existing failed renewal order's secure WooCommerce pay URL to that order's customer only. Successful Stripe customer payment is reconciled asynchronously and extends the license once. Gateway-specific SCA presentation and actual sandbox behavior still require testing with the installed WooCommerce Stripe version.
