# Stripe webhook receipt

The license server exposes `POST /wp-json/wplc/v1/webhooks/stripe`. Configure the Stripe endpoint to send `payment_intent.succeeded` and `payment_intent.payment_failed` events to this URL. The receiving site must define `WPLC_STRIPE_WEBHOOK_SECRET` in a protected configuration file/environment before enabling the endpoint.

The receiver validates Stripe's `Stripe-Signature` timestamp and v1 HMAC-SHA256 signature with a 300-second tolerance, caps the raw request body at 1 MiB, and stores only minimized renewal identifiers/payment state plus the raw-body SHA-256 in the event inbox. No customer billing data or unredacted webhook body is stored. Event IDs are unique; an exact duplicate gets an accepted duplicate response and reuse of an ID with different bytes is rejected.

The inbox records events as `pending_reconciliation`. This receiver does not itself mark orders paid or extend licenses. Phase 27 must validate the renewal/order/license linkage and amount/currency before reconciliation. Until signature verification, event replay, duplicate/conflict behavior, and reconciliation are exercised in a Stripe test-mode sandbox, do not configure this endpoint for production billing.
