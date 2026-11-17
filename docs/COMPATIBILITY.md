# Compatibility

## WP License Captain: tested matrix

These are the combinations the integration suite runs against in CI (`.github/workflows/phpunit.yml`, job `integration`). Nothing outside this table is tested.

| WordPress | WooCommerce | PHP | Database | Order storage | Installation |
|---|---|---|---|---|---|
| 7.0 (latest 7.0.x) | 11.2.2 | 8.5 | MySQL 9.7 | HPOS | single site |
| 7.0 (latest 7.0.x) | 11.2.2 | 8.5 | MariaDB 12.3 | legacy posts | single site |
| latest stable | 11.3.0 | 8.5 | MySQL 9.7 | legacy posts | single site |
| latest stable | 11.3.0 | 8.5 | MariaDB 12.3 | HPOS | single site |
| latest stable | 11.3.0 | 8.5 | MariaDB 12.3 | HPOS | multisite (subdirectory network) |
| latest stable | 11.3.0 | 8.6 (nightly until 19 Nov 2026) | MySQL 9.7 | HPOS | single site, experimental (`continue-on-error`) |

- WordPress 7.0, PHP 8.5 and WooCommerce 11.2.2 are the minimums declared in `wp-license.php` (`Requires at least: 7.0`, `Requires PHP: 8.5`, `WC requires at least: 11.2.2`; `Requirements::MIN_WP`, `Requirements::MIN_PHP`, `Requirements::MIN_WC`). Below PHP 8.5 the plugin shows an admin notice and loads nothing else. WordPress refuses to activate it below 7.0; an already active copy shows an admin notice. Below WooCommerce 11.2.2 activation is refused; an already active copy shows an admin notice, licenses keep working and the block checkout offers no renewal opt-in.
- The WordPress and WooCommerce minimums follow the owner decision of 17 Nov 2026, uniform for all Captain plugins, add-ons and connectors (on top of MINVER-1). The cells with WordPress 7.0 and WooCommerce 11.2.2 run the minimums together; the two cells with the latest WordPress and WooCommerce 11.3.0 the newest pair. "7.0" installs the newest 7.0.x release the wordpress.org version API lists (with the test library of the 7.0 branch).
- Supported database servers: MySQL 9.7 (LTS) or later and MariaDB 12.3 (LTS) or later (`Requirements::MIN_MYSQL`, `Requirements::MIN_MARIADB`; owner decision of 16 Nov 2026, MINVER-1). An older server is not blocked: Site Health (test "WP License Captain: PHP and database versions") and the diagnostics page show a warning. MariaDB is recognised by the word "MariaDB" in the server version (`$wpdb->db_server_info()`).
- "latest stable" WordPress is resolved at run time; the WooCommerce versions are pinned in the workflow matrix (the newest one also as `DEFAULT_WC_VERSION` in `bin/install-test-env.sh`) and must be raised by hand.
- Database in CI: the Docker images `mysql:9.7` and `mariadb:12.3` (both present on Docker Hub on 16 Nov 2026), one per cell as listed. The E2E run uses `mysql:9.7`.
- Unit tests and the syntax check run on PHP 8.5 without WordPress, plus PHP 8.6 as an experimental cell.
- The plugin declares HPOS and block-based checkout compatibility (`wp-license.php`). In the block checkout the renewal opt-in is an additional checkout field (`BlockCheckoutConsent`); it is covered by integration tests through the Store API, not by a browser test. It is shown for license products only (a visibility rule); with more than `BlockCheckoutConsent::MAX_RULE_PRODUCTS` license products no rule is attached and the opt-in stands in every cart, its value then ignored for orders without a license (B-114). The filter `wplc_block_checkout_consent_without_rule` switches the opt-in off in that case ([RENEWALS.md](RENEWALS.md#consent-opt-in)).
- Plugin version: 0.2.0 in `wp-license.php` (`WPLC_VERSION`) and `readme.txt` (stable tag); the schema version is independent of it (`Schema::VERSION`).

- Multisite: the multisite cell runs the whole suite on a network plus `MultisiteIntegrationTest.php`; behavior and limits are in [MULTISITE.md](MULTISITE.md).

Not part of the matrix and therefore unverified: multisite with WordPress 7.0 or legacy order storage (the latter passed locally), subdomain networks, database servers older than MySQL 9.7 or MariaDB 12.3, PHP older than 8.5 (not supported), WooCommerce releases other than 11.2.2 and 11.3.0, WordPress 7.0 with WooCommerce 11.3.0 and the latest WordPress with WooCommerce 11.2.2, the WooCommerce Stripe gateway plugin, and a live Stripe account.

How to run a cell locally: see [QUALITY-GATES.md](QUALITY-GATES.md#integration-tests).

### WooCommerce Subscriptions

WP License Captain renews licenses itself and does not integrate with WooCommerce Subscriptions (WCS). When WCS is active (P-20):

- Renewal and resubscribe orders created by WCS (`wcs_order_contains_renewal()`, `wcs_order_contains_resubscribe()`) never issue a license, so a mapped subscription product no longer creates a new key every period.
- The plugin screens and the product screens show a notice to users with `wplc_manage_catalog`; it lists license products that are subscription products (`subscription`, `variable-subscription`, `subscription_variation`, the first 200 license products are checked). Do not map subscription products to license plans: WCS would charge the subscription and the license renewal would be charged as well.
- Mapping such products is refused: the product editor and the catalog CSV import report "WooCommerce Subscriptions products cannot be license products" (`Catalog::save_mapping()` fails with `subscription_product`; also for a variation of a variable subscription). Mappings created before this check keep issuing licenses on the first order; the notice lists them so they can be moved to a simple or variable product.

## Connected products: declared minimums

Source is the plugin header and `readme.txt` in each connected product repository (snapshot 2026-11-16; details in [PRODUCT-COMPATIBILITY.md](PRODUCT-COMPATIBILITY.md)). These are declared minimums, not test results of this repository.

| Product | WordPress minimum | PHP minimum | WooCommerce minimum | Declared tested WordPress |
|---|---:|---:|---:|---:|
| Voucher Captain (`wp-couponmaster`) | 6.4 | 8.1 | 8.9 | 7.1 |
| Pass Captain (`wp-passmaster`) | 6.4 | 8.2 | 8.2 (optional) | 7.1 |
| Affiliate Captain (`wpaffiliatemaster`) | 6.3 | 7.4 | 8.0 (optional) | 7.1 |

The client libraries of these products are not tested by this repository's CI.
