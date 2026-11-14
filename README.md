# WP License Captain

WooCommerce plugin that turns a shop into a license server for premium WordPress plugins: it sells licenses, tracks site activations, tells product plugins which features a customer has paid for, renews licenses and delivers updates.

> **Status: preview. Not ready to sell with.** Unit and integration tests pass in CI, and an end-to-end harness (`tests/e2e/`) runs purchases, manual and automatic renewals (with a test payment adapter), updates and the admin and account pages in a real WordPress/WooCommerce installation. Not verified yet: anything against Stripe (test mode), the screens in a browser (JavaScript, block checkout, emails in a mail client), the product add-ons talking to the server, and concurrent requests. Open work: [docs/TODO.md](docs/TODO.md). Release gates: [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

## Features

**Selling and licenses**

- Mark WooCommerce products or variations as license products with plan, billing interval (monthly/yearly), site limit, support level and entitlements; CSV export/import of the mapping.
- One license per purchased unit when the order is paid. The purchase requires a customer account.
- License states `pending`, `active`, `past_due`, `suspended`, `revoked`, `expired` with fixed transition rules and an audit log.
- Refund of the purchase revokes the license; a Stripe dispute suspends it.

**Renewals** ([docs/RENEWALS.md](docs/RENEWALS.md))

- Manual renewal by the customer with any payment method, 30 days before to 30 days after the end date, same license key.
- Automatic renewal as an opt-in at the classic checkout, charged to the saved Stripe card, with retries on day 1, 3 and 7 and a 14-day grace period. The block checkout is not supported for the opt-in.
- Stripe webhook endpoint for reconciliation and disputes ([docs/STRIPE-WEBHOOKS.md](docs/STRIPE-WEBHOOKS.md)).
- No PayPal automatic renewal; PayPal customers renew manually.

**Customer account**

- "Licenses" page: key with copy button, status, end date, sites in use, deactivate a site, cancel or resume automatic renewal, renew now, download the latest release.
- Emails through WooCommerce: license issued, expiry reminder, upcoming automatic renewal, payment failed, license renewed, license expired.

**Product plugins** ([docs/CLIENT-INTEGRATION.md](docs/CLIENT-INTEGRATION.md), [docs/API-V1.md](docs/API-V1.md))

- REST API v1: activate, deactivate, check entitlements, check for updates. Requests are signed per installation; responses are signed and bound to the request.
- Live, staging and development activations; one staging site per live site.
- PHP client class for product plugins (`src/Client/LicenseClient.php`).

**Updates** ([docs/UPDATES.md](docs/UPDATES.md))

- Release management in the admin: upload a ZIP to private storage or register an HTTPS package URL with checksum; publish, withdraw.
- Update check for licensed installations with one-time download links.

**Admin** ([docs/SUPPORT-ACTIONS.md](docs/SUPPORT-ACTIONS.md))

- License list with search (including full key and customer email), license detail with activations, renewals, entitlements and audit history.
- Suspend, revoke, reactivate, change term, release a site, add a license manually.
- Settings and diagnostics pages, health alert hook ([docs/MONITORING.md](docs/MONITORING.md)).
- Personal data export and erasure through the WordPress privacy tools; scheduled data retention.

**Languages:** English and German (`de_DE`, `de_DE_formal`). Regenerate with `bin/make-pot.sh`.

## Requirements

- WordPress 6.5 or later
- WooCommerce (active); the tested version range is not determined yet
- PHP 7.4 or later
- MySQL/MariaDB with a single writable primary (the renewal locks use `GET_LOCK`)
- A working cron (system cron for `wp-cron.php` recommended)
- For automatic renewals: the official WooCommerce Stripe gateway and the classic checkout
- In `wp-config.php` for production: `WPLC_LICENSE_KEY_SECRETS`, `WPLC_INSTALLATION_ENCRYPTION_KEYS`, and `WPLC_STRIPE_WEBHOOK_SECRET` when Stripe is used ([docs/SECURITY-OPERATIONS.md](docs/SECURITY-OPERATIONS.md))

## Tests

```bash
composer install
composer lint                # PHP syntax check of every file
composer test                # unit tests, no WordPress needed
```

Integration tests run against WordPress, WooCommerce and a MySQL database. `bin/install-test-env.sh` installs the test environment; its header explains the arguments:

```bash
bin/install-test-env.sh <db-name> <db-user> <db-pass>
WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress composer test:integration
```

Coding standard and static analysis: `composer phpcs:blocking`, `composer phpstan`. See [docs/QUALITY-GATES.md](docs/QUALITY-GATES.md).

## Documentation

Start here:

- [To-do list (single task list)](docs/TODO.md)
- [Release readiness checklist](docs/RELEASE-READINESS.md)
- [Changelog](CHANGELOG.md)

Concepts:

- [Product rules and architecture](docs/IMPLEMENTATION-PLAN.md)
- [Checkout and renewal concept](docs/CONCEPT-CHECKOUT-RENEWAL.md)
- [Entitlements](docs/ENTITLEMENTS.md)

Billing:

- [Renewals](docs/RENEWALS.md)
- [Stripe webhooks](docs/STRIPE-WEBHOOKS.md)
- [PayPal compatibility](docs/PAYPAL-COMPATIBILITY.md)

API and product plugins:

- [REST API v1](docs/API-V1.md) and [OpenAPI description](docs/openapi-v1.json)
- [Client integration](docs/CLIENT-INTEGRATION.md)
- [Product repository status](docs/CLIENT-REPOS.md)
- [Product compatibility](docs/PRODUCT-COMPATIBILITY.md)

Updates:

- [Update delivery](docs/UPDATES.md)
- [Package storage](docs/UPDATE-STORAGE.md)

Operations:

- [Support actions in the admin](docs/SUPPORT-ACTIONS.md)
- [Monitoring](docs/MONITORING.md)
- [Production operations](docs/PRODUCTION-OPERATIONS.md)
- [Security and key operations](docs/SECURITY-OPERATIONS.md)
- [Caching](docs/CACHING.md)
- [Catalog CSV](docs/CATALOG-CSV.md)
- [Multisite](docs/MULTISITE.md)
- [Compatibility](docs/COMPATIBILITY.md)
- [Privacy and data flows](docs/PRIVACY-DATA-FLOWS.md)
- [Quality gates](docs/QUALITY-GATES.md)
- [Launch evidence](docs/LAUNCH-EVIDENCE.md)

Some of the older documents under "Operations" have not been revised since the consolidation and may describe an earlier state; the code and TODO.md take precedence.

## Privacy and security

The plugin stores licenses, the customer's user ID, activated site URLs with plugin/WordPress/PHP versions, renewal records, minimized Stripe event data and an audit log. It stores no card numbers. License keys are not stored in plain text; they are derived from a secret. Until `WPLC_LICENSE_KEY_SECRETS` and `WPLC_INSTALLATION_ENCRYPTION_KEYS` are defined, keys depend on the WordPress salts, and changing the salts invalidates every issued key; the admin shows a warning in that case.

## License

GPL-2.0-or-later.
