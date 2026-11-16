# Compatibility

## WP License Captain: tested matrix

These are the combinations the integration suite runs against in CI (`.github/workflows/phpunit.yml`, job `integration`). Nothing outside this table is tested.

| WordPress | WooCommerce | PHP | Database | Order storage | Installation |
|---|---|---|---|---|---|
| 6.5 (latest 6.5.x) | 8.9.3 | 8.5 | MySQL 9.7 | HPOS | single site |
| 6.5 (latest 6.5.x) | 8.9.3 | 8.5 | MariaDB 12.3 | legacy posts | single site |
| 6.5 (latest 6.5.x) | 9.4.3 | 8.5 | MariaDB 12.3 | HPOS | single site |
| 6.5 (latest 6.5.x) | 9.4.3 | 8.5 | MySQL 9.7 | legacy posts | single site |
| latest stable | 11.2.2 | 8.5 | MySQL 9.7 | HPOS | single site |
| latest stable | 11.2.2 | 8.5 | MySQL 9.7 | legacy posts | single site |
| latest stable | 11.2.2 | 8.5 | MariaDB 12.3 | HPOS | single site |
| latest stable | 11.2.2 | 8.5 | MariaDB 12.3 | HPOS | multisite (subdirectory network) |
| latest stable | 11.2.2 | 8.6 (nightly until 19 Nov 2026) | MySQL 9.7 | HPOS | single site, experimental (`continue-on-error`) |

- WordPress 6.5, PHP 8.5 and WooCommerce 8.9 are the minimums declared in `wp-license.php` (`Requires PHP: 8.5`, `WC requires at least: 8.9`, checked at run time by the PHP guard in `wp-license.php` and `Requirements::MIN_WC`). Below PHP 8.5 the plugin shows an admin notice and loads nothing else.
- Supported database servers: MySQL 9.7 (LTS) or later and MariaDB 12.3 (LTS) or later (`Requirements::MIN_MYSQL`, `Requirements::MIN_MARIADB`; owner decision of 16 Nov 2026, MINVER-1). An older server is not blocked: Site Health (test "WP License Captain: PHP and database versions") and the diagnostics page show a warning. MariaDB is recognised by the word "MariaDB" in the server version (`$wpdb->db_server_info()`).
- The WordPress and WooCommerce minimums stayed as they were with the PHP minimum of 8.5; whether WordPress 6.5 with WooCommerce 8.9.3 and 9.4.3 passes on PHP 8.5 is shown by the first CI run of the four 6.5 cells. If they cannot be made green, the minimum is raised to the oldest version that is (A-91).
- WooCommerce 8.9.3 is the last release of the declared minimum line (it needs WordPress 6.4 or later). Both 8.9.3 cells passed in CI on `main` on PHP 7.4 and MySQL 8.0 (for example commit `c269e07`, PHPUnit run 424 of 15 Nov 2026), before MINVER-1; they were never run locally, because the download was not reachable in the development environment.
- WooCommerce 9.4.3 declares WordPress 6.5 as its own minimum and is the newest WooCommerce line that still supports it; the pinned recent version (11.2.2) declares WordPress 7.0, which is why it is paired with the latest WordPress.
- "latest stable" WordPress is resolved at run time; the WooCommerce version is pinned in the workflow matrix and in `bin/install-test-env.sh` and must be raised by hand.
- Database in CI: the Docker images `mysql:9.7` and `mariadb:12.3` (both present on Docker Hub on 16 Nov 2026), one per cell as listed. The E2E run uses `mysql:9.7`.
- Unit tests and the syntax check run on PHP 8.5 without WordPress, plus PHP 8.6 as an experimental cell.
- The plugin declares HPOS and block-based checkout compatibility (`wp-license.php`). In the block checkout the renewal opt-in is an additional checkout field (`BlockCheckoutConsent`, WooCommerce 8.9 or later); it is covered by integration tests through the Store API, not by a browser test. Limit of WooCommerce 8.9 to 9.8 (B-114): an additional field cannot be hidden there, so the opt-in stands in every cart, also without a license product (its value is then ignored); from 9.9 it is shown for license products only. The filter `wplc_block_checkout_consent_without_rule` switches the opt-in off on the older versions ([RENEWALS.md](RENEWALS.md#consent-opt-in)).
- Plugin version: 0.2.0 in `wp-license.php` (`WPLC_VERSION`) and `readme.txt` (stable tag); the schema version is independent of it (`Schema::VERSION`).

- Multisite: the multisite cell runs the whole suite on a network plus `MultisiteIntegrationTest.php`; behavior and limits are in [MULTISITE.md](MULTISITE.md).

Not part of the matrix and therefore unverified: multisite with WordPress 6.5 or legacy order storage (the latter passed locally), subdomain networks, database servers older than MySQL 9.7 or MariaDB 12.3, PHP older than 8.5 (not supported), WooCommerce versions between 8.9.3 and 9.4.3 and between 9.4.3 and the pinned recent version, the WooCommerce Stripe gateway plugin, and a live Stripe account.

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
