# WP License Captain

WooCommerce plugin that turns a shop into a license server for premium WordPress plugins: it sells licenses, tracks site activations, tells product plugins which features a customer has paid for, renews licenses and delivers updates.

**Product boundary (stated by the owner on 15 Nov 2026, not worked out yet):** WP License Captain is an independent server plugin sold separately; it is not part of any Captain Pro plan. A software vendor can implement the client directly in its WordPress product against the documented API or use the PHP client library in `src/Client/`. A dedicated client plugin, such as the License Captain Client we use for our own products, is another integration option. Captain Pro add-ons carry a client component only to validate our own PRO licenses; this internal implementation detail is not a customer-facing PRO benefit. Buyers of Captain Pro do not need to operate their own License Captain Server. What exists today is this plugin and the library in `src/Client/`, which the add-ons bundle; a "License Captain Client" plugin does not exist, and selling the server to other vendors has no tasks, price or support model yet. The open questions are listed under "Offene Entscheidungen" in [docs/TODO.md](docs/TODO.md) (R-21).

> **Status: preview. Not ready to sell with.** In CI, on every pull request and push to `main`: unit tests on PHP 7.4 to 8.3 (PHP 8.5 runs as an informative job), integration tests in seven WordPress/WooCommerce combinations (WooCommerce 8.9.3, 9.4.3 and 11.2.2, HPOS and legacy order storage, one multisite cell) and one informative cell on PHP 8.5, an end-to-end run (`tests/e2e/`, 15 flows: purchases, manual and automatic renewals with a test payment adapter, updates, admin and account pages, and concurrent requests against a real database with eight server processes), PHPStan, the blocking PHPCS rules and the release ZIP. Run locally only, with headless Chromium: a browser pass over the classic checkout, the account, the admin pages and the product editor (`tests/browser/`, [docs/BROWSER-ACCEPTANCE.md](docs/BROWSER-ACCEPTANCE.md)). Not verified yet: anything against Stripe (the adapter and the webhook are tested against a fake of the Stripe API only), the block checkout in a browser (covered through the Store API only), a person looking at the screens and at the ten emails in a mail client, the premium add-ons talking to a deployed server, and a production server. Open work: [docs/TODO.md](docs/TODO.md). Release gates: [docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md).

## Features

**Selling and licenses**

- Mark WooCommerce products or variations as license products with plan, billing interval (monthly/yearly), site limit, support level and entitlements; CSV export/import of the mapping.
- One license per purchased unit when the order is paid. The purchase requires a customer account.
- Checkout consent to the immediate start of the license (EU withdrawal right for digital content), required by default and recorded on the order; off when Germanized or German Market is active ([docs/CONCEPT-CHECKOUT-RENEWAL.md](docs/CONCEPT-CHECKOUT-RENEWAL.md)). Order lines show the license and the service period.
- License states `pending`, `active`, `past_due`, `suspended`, `revoked`, `expired` with fixed transition rules and an audit log.
- Refund of the purchase revokes the license; a Stripe dispute suspends it.

**Renewals** ([docs/RENEWALS.md](docs/RENEWALS.md))

- Manual renewal by the customer with any payment method, 30 days before to 30 days after the end date, same license key.
- Automatic renewal as an opt-in at the classic checkout, charged to the saved Stripe card, with retries on day 1, 3 and 7 and a 14-day grace period. The opt-in is also offered in the block checkout as an additional checkout field (WooCommerce 8.9+; automated tests only). A license is charged only after the advance notice was sent, with a card saved through Stripe only, and never with another card when the saved one is gone.
- Stripe webhook endpoint for reconciliation and disputes ([docs/STRIPE-WEBHOOKS.md](docs/STRIPE-WEBHOOKS.md)).
- No PayPal automatic renewal; PayPal customers renew manually.

**Customer account**

- "Licenses" page: key with copy button, status, end date, sites in use, deactivate a site, cancel or resume automatic renewal, renew now, download the latest release.
- The same list anywhere as shortcode `[wplc_licenses]` or block "My licenses"; release notes of a product as shortcode `[wplc_changelog product="product-key" limit="10"]` or block "Changelog"; the plans of a product with price (shop tax display), term, live-site limit, features and a "Buy" button as shortcode `[wplc_pricing product="product-key" highlight="plan-key" show_features="yes"]` or block "Pricing table" (only published, purchasable WooCommerce products with an active plan are listed).
- Ten emails through WooCommerce: license issued, expiry reminder, upcoming automatic renewal, payment failed, payment method missing, license renewed, license expired, cancellation confirmed, license suspended, license revoked.

**Product plugins** ([docs/CLIENT-INTEGRATION.md](docs/CLIENT-INTEGRATION.md), [docs/API-V1.md](docs/API-V1.md))

- REST API v1: activate, deactivate, check entitlements, check for updates. Requests are signed per installation; responses are signed and bound to the request.
- Live, staging and development activations; one staging site per live site.
- PHP client library for product plugins in `src/Client/` (`LicenseManager` facade, `LicenseClient` transport with pinned Ed25519 keys, `Updater` for WordPress updates); `bin/export-client.php` copies it into an add-on.

**Updates** ([docs/UPDATES.md](docs/UPDATES.md))

- Release management in the admin: upload a ZIP to private storage or register an HTTPS package URL with checksum; publish, withdraw.
- Update check for licensed installations with one-time download links.

**Admin** ([docs/SUPPORT-ACTIONS.md](docs/SUPPORT-ACTIONS.md))

- License list with search (including full key and customer email), license detail with activations, renewals, entitlements and audit history.
- Suspend, revoke, reactivate, change term, release a site, add a license manually.
- Settings and diagnostics pages, health alert hook ([docs/MONITORING.md](docs/MONITORING.md)).
- Personal data export and erasure through the WordPress privacy tools; scheduled data retention.

**Languages:** English and German (`de_DE`, `de_DE_formal`). Austrian, Swiss, Luxembourg, Liechtenstein and Belgian German without their own file load `de_DE_formal` (`*_informal` variants `de_DE`; filter `wplc_translation_fallback_locale`). Regenerate with `bin/make-pot.sh`.

## Requirements

- WordPress 6.5 or later
- WooCommerce 8.9 or later, active (the CI integration matrix runs 8.9.3, 9.4.3 and 11.2.2, see [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md))
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
