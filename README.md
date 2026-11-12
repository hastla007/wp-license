# WP License Captain

WP License Captain is the WooCommerce-based license server for premium WordPress products. It is intended to manage product plans, issue licenses from paid orders, track site activations, return feature entitlements, and provide a controlled path for recurring renewals and premium updates.

> **Status: engineering preview, not production-ready.** PRs 01–12 form a stacked implementation branch. Review and merge them in order. The system still needs concrete payment gateway adapters, product-specific settings and updater integrations, a package release workflow, and a complete security/privacy review before it can be used to sell licenses.

## Current implementation scope

- WooCommerce product/plan mapping and idempotent license issuance.
- License state transitions, refund handling, admin actions, audit history, and customer self-service.
- Signed REST API v1 for activation, deactivation, and entitlement checks.
- Installation limits, staging-to-production pairing, and feature entitlement snapshots.
- An adapter contract for explicit-consent automatic renewals, retry scheduling, and grace expiry.
- Update metadata with signed responses, short-lived download grants, trusted HTTPS package hosts, and checksums.
- A shared PHP client for product plugin activation, request signing, response verification, and update checks.

## Not ready for commercial launch

- A limited Stripe card-token adapter is present, but it has not been sandbox-validated and still lacks the customer authentication recovery flow. PayPal renewals are not supported.
- The shared client still needs product-specific license screens, feature gates, cache policy, and WordPress upgrader integration in CouponMaster, PassMaster, AffiliateMaster, and future products.
- Release archives must be hosted and registered through the documented update filters. Product-side checksum enforcement must be implemented before premium updates are enabled.
- Gateway behavior, Checkout Blocks, migrations from real data, recovery flows, and privacy obligations need a deployment-specific review.
- Do not treat a successful code review or pull request merge as a substitute for operational validation.

## Requirements

- WordPress 6.5+
- WooCommerce active
- PHP 7.4+

## Developer documentation

- [Implementation plan](docs/IMPLEMENTATION-PLAN.md)
- [REST API v1](docs/API-V1.md)
- [Automatic renewals](docs/RENEWALS.md)
- [Update package integration](docs/UPDATES.md)
- [Product client integration](docs/CLIENT-INTEGRATION.md)
- [Release readiness checklist](docs/RELEASE-READINESS.md)
- [Security and key operations](docs/SECURITY-OPERATIONS.md)

## Privacy and security

WP License Captain stores license, customer account ID, activation URL/version metadata, and audit events. It must never store payment card numbers or CVV values. Product clients should not send shop orders, vouchers, tickets, affiliate-user records, or unrelated personal data to the license server. Keep WordPress authentication salts stable until a supported key-rotation and recovery procedure exists.
