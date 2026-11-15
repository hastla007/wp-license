# Compatibility

## WP License Captain: tested matrix

These are the combinations the integration suite runs against in CI (`.github/workflows/phpunit.yml`, job `integration`). Nothing outside this table is tested.

| WordPress | WooCommerce | PHP | Order storage | Installation |
|---|---|---|---|---|
| 6.5 (latest 6.5.x) | 9.4.3 | 7.4 | HPOS | single site |
| 6.5 (latest 6.5.x) | 9.4.3 | 7.4 | legacy posts | single site |
| latest stable | 11.2.2 | 8.3 | HPOS | single site |
| latest stable | 11.2.2 | 8.3 | legacy posts | single site |
| latest stable | 11.2.2 | 8.3 | HPOS | multisite (subdirectory network) |

- WordPress 6.5 and PHP 7.4 are the minimums declared in `wp-license.php`.
- WooCommerce 9.4.3 declares WordPress 6.5 as its own minimum and is the oldest WooCommerce version the plugin is tested with; the pinned recent version (11.2.2) declares WordPress 7.0, which is why it is paired with the latest WordPress. The plugin header does not declare a WooCommerce minimum yet; until it does, treat 9.4 as the minimum.
- "latest stable" WordPress is resolved at run time; the WooCommerce version is pinned in the workflow matrix and in `bin/install-test-env.sh` and must be raised by hand.
- Database in CI: MySQL 8.0.
- Unit tests and the syntax check additionally run on PHP 7.4, 8.1, 8.2 and 8.3 without WordPress.
- The plugin declares HPOS and block-based checkout compatibility (`wp-license.php`). In the block checkout the renewal opt-in is an additional checkout field (`BlockCheckoutConsent`, WooCommerce 8.9 or later); it is covered by integration tests through the Store API, not by a browser test.
- Plugin version: 0.2.0 in `wp-license.php` (`WPLC_VERSION`) and `readme.txt` (stable tag); the schema version is independent of it (`Schema::VERSION`).

- Multisite: the multisite cell runs the whole suite on a network plus `MultisiteIntegrationTest.php`; behavior and limits are in [MULTISITE.md](MULTISITE.md).

Not part of the matrix and therefore unverified: multisite with WordPress 6.5 or legacy order storage (the latter passed locally), subdomain networks, MariaDB, PHP 8.4 and newer, WooCommerce versions between 9.4 and the pinned recent version, the WooCommerce Stripe gateway plugin, and a live Stripe account.

How to run a cell locally: see [QUALITY-GATES.md](QUALITY-GATES.md#integration-tests).

### WooCommerce Subscriptions

WP License Captain renews licenses itself and does not integrate with WooCommerce Subscriptions (WCS). When WCS is active (P-20):

- Renewal and resubscribe orders created by WCS (`wcs_order_contains_renewal()`, `wcs_order_contains_resubscribe()`) never issue a license, so a mapped subscription product no longer creates a new key every period.
- The plugin screens and the product screens show a notice to users with `wplc_manage_catalog`; it lists license products that are subscription products (`subscription`, `variable-subscription`, `subscription_variation`, the first 200 license products are checked). Do not map subscription products to license plans: WCS would charge the subscription and the license renewal would be charged as well.
- Mapping such products is not refused yet; the catalog check is open.

## Connected products: declared minimums

Source is the `readme.txt` in each connected product repository (snapshot 2026-11-12). These are declared minimums, not test results of this repository.

| Product | WordPress minimum | PHP minimum | WooCommerce minimum | Declared tested WordPress |
|---|---:|---:|---:|---:|
| Voucher Captain (`wp-couponmaster`) | 6.4 | 8.1 | Not declared in readme | 7.1 |
| WP Pass Captain (`wp-passmaster`) | 6.4 | 8.2 | 8.2 | 7.1 |
| Affiliate Captain (`wpaffiliatemaster`) | 6.2 | 7.4 | Not declared in readme | 7.1 |

The client libraries of these products are not tested by this repository's CI.
