# Compatibility

## WP License Captain: tested matrix

These are the combinations the integration suite runs against in CI (`.github/workflows/phpunit.yml`, job `integration`). Nothing outside this table is tested.

| WordPress | WooCommerce | PHP | Order storage |
|---|---|---|---|
| 6.5 (latest 6.5.x) | 9.4.3 | 7.4 | HPOS |
| 6.5 (latest 6.5.x) | 9.4.3 | 7.4 | legacy posts |
| latest stable | 11.2.2 | 8.3 | HPOS |
| latest stable | 11.2.2 | 8.3 | legacy posts |

- WordPress 6.5 and PHP 7.4 are the minimums declared in `wp-license.php`.
- WooCommerce 9.4.3 declares WordPress 6.5 as its own minimum and is the oldest WooCommerce version the plugin is tested with; the pinned recent version (11.2.2) declares WordPress 7.0, which is why it is paired with the latest WordPress. The plugin header does not declare a WooCommerce minimum yet; until it does, treat 9.4 as the minimum.
- "latest stable" WordPress is resolved at run time; the WooCommerce version is pinned in the workflow matrix and in `bin/install-test-env.sh` and must be raised by hand.
- Database in CI: MySQL 8.0.
- Unit tests and the syntax check additionally run on PHP 7.4, 8.1, 8.2 and 8.3 without WordPress.
- The plugin declares HPOS compatibility and declares the block-based checkout as incompatible (`wp-license.php`); the block checkout is not tested.

Not part of the matrix and therefore unverified: multisite, MariaDB, PHP 8.4 and newer, WooCommerce versions between 9.4 and the pinned recent version, the WooCommerce Stripe gateway plugin, and a live Stripe account.

How to run a cell locally: see [QUALITY-GATES.md](QUALITY-GATES.md#integration-tests).

## Connected products: declared minimums

Source is the `readme.txt` in each connected product repository (snapshot 2026-11-12). These are declared minimums, not test results of this repository.

| Product | WordPress minimum | PHP minimum | WooCommerce minimum | Declared tested WordPress |
|---|---:|---:|---:|---:|
| Voucher Captain (`wp-couponmaster`) | 6.4 | 8.1 | Not declared in readme | 7.1 |
| WP Pass Captain (`wp-passmaster`) | 6.4 | 8.2 | 8.2 | 7.1 |
| Affiliate Captain (`wpaffiliatemaster`) | 6.2 | 7.4 | Not declared in readme | 7.1 |

The client libraries of these products are not tested by this repository's CI.
