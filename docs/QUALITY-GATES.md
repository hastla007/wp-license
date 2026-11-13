# Quality gates

Every gate below runs in GitHub Actions on each pull request and on pushes to `main` (not on pushes to other branches, so a PR runs each job once). All commands work locally from the plugin root after `composer install`.

No `composer.lock` is committed: the dependencies are development tools only and the unit job runs on four PHP versions. CI caches `vendor/` per PHP version and `composer.json` hash.

## Overview

| Gate | Workflow / job | Blocking | What it proves |
|---|---|---|---|
| Composer metadata | `phpunit.yml` / `unit (X)` | yes | `composer.json` is valid |
| PHP syntax | `phpunit.yml` / `unit (X)` | yes | Every PHP file parses on PHP 7.4, 8.1, 8.2 and 8.3 |
| Unit tests | `phpunit.yml` / `unit (X)` | yes | Pure logic without WordPress, on PHP 7.4, 8.1, 8.2 and 8.3 |
| Site URL rules without intl | `phpunit.yml` / `unit (X)` | yes | `SiteUrl::normalize()` behaves as documented when `idn_to_ascii()` is unavailable |
| Integration tests | `phpunit.yml` / `integration (…)` (4 cells), summarized by `wordpress-integration` | yes | Behavior inside WordPress + WooCommerce with a real database, see matrix in [COMPATIBILITY.md](COMPATIBILITY.md) |
| PHPCS security + compatibility | `quality.yml` / `phpcs` | yes (new findings) | Escaping, nonces, input sanitization, prepared SQL, PHP 7.4 compatibility |
| PHPCS coding standard | `quality.yml` / `phpcs` | no (report only) | WordPress-Extra and WordPress-Docs style |
| PHPStan level 5 | `quality.yml` / `phpstan` | yes (new errors) | Undefined methods/variables, wrong argument counts and types in `src/` and `wp-license.php` |

`wordpress-integration` is a single job that fails unless every integration cell passed; use it (and `unit (7.4)` … `unit (8.3)`, `phpcs`, `phpstan`) as required checks.

## Syntax and unit tests

```bash
composer lint          # php -l on every PHP file with the PHP on your PATH
composer test          # PHPUnit, tests/ without tests/integration
php -d disable_functions=idn_to_ascii vendor/bin/phpunit --filter SiteUrl
```

Unit tests load `tests/bootstrap.php`, which only defines constants. They must not need WordPress.

`tests/Api/SiteUrlNormalizationTest.php` pins down the URL identity rules (T-87). Rows named `SURPRISING …` document current behavior that is probably unintended; change them only together with `src/Api/V1/SiteUrl.php`:

- `straße.de` becomes `strasse.de` (UTS #46 transitional mapping), which is a different domain than the one browsers resolve.
- Without the PHP intl extension every non-ASCII host is rejected; with it, it is converted to Punycode.
- Paths are compared byte for byte: case, `//`, `/./`, `/../` and percent-encoding are not normalized, spaces and raw UTF-8 are accepted, and there is no length limit although the database column holds 255 characters.
- IPv6 literals are lower-cased but not compressed; non-canonical IPv4 spellings (`192.168.001.010`, `0x7f.1`, `2130706433`, `999.1.1.1`) are accepted as host names.
- An empty port (`https://example.com:`) is accepted, a leading dot in the host is dropped, a NUL byte in the path becomes `_`.
- `https://example.com/?` and `https://example.com/#` are rejected on PHP 8 but accepted on PHP 7.4, because `parse_url()` reports empty components only since PHP 8.0.

## Integration tests

The suite runs inside the WordPress test library with WooCommerce loaded and installed, then this plugin loaded like a second active plugin (`tests/integration-bootstrap.php`). Each test runs in a database transaction that is rolled back.

One-time setup (needs `curl`, `unzip`, `svn`, a MySQL/MariaDB server and client):

```bash
# <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version]
bash bin/install-test-env.sh wordpress_test root root 127.0.0.1 latest 11.2.2
```

The script downloads WordPress and its test library with the pinned `install-wp-tests.sh` and unpacks the WooCommerce release ZIP from wordpress.org into the test site. It installs into `/tmp/wordpress` and `/tmp/wordpress-tests-lib`; set `WP_CORE_DIR` and `WP_TESTS_DIR` to use other directories (and pass the same values when running the tests). For another WordPress/WooCommerce pair use a fresh pair of directories and a different database name.

Run, once per order storage:

```bash
WPLC_TEST_HPOS=1 composer test:integration   # High-Performance Order Storage (default)
WPLC_TEST_HPOS=0 composer test:integration   # legacy post-based orders
```

The first line of output states what is actually running, for example `WordPress 6.5.5, WooCommerce 9.4.3, order storage: HPOS, PHP 7.4.33`. `00EnvironmentIntegrationTest.php` fails when the order storage (and in CI the WooCommerce version) is not the requested one.

Environment variables:

| Variable | Default | Meaning |
|---|---|---|
| `WP_TESTS_DIR` | `/tmp/wordpress-tests-lib` | WordPress test library |
| `WP_CORE_DIR` | `/tmp/wordpress` | WordPress core; WooCommerce is expected in `wp-content/plugins/woocommerce` |
| `WPLC_WC_DIR` | derived from `WP_CORE_DIR` | Another WooCommerce directory (must be a release build with `vendor/`) |
| `WPLC_TEST_HPOS` | `1` | `1` = HPOS, `0` = legacy order storage |
| `WPLC_EXPECT_WP_VERSION`, `WPLC_EXPECT_WC_VERSION` | unset | When set, the environment test asserts these versions |

What the suites cover:

| File in `tests/integration/` | Covers |
|---|---|
| `00EnvironmentIntegrationTest.php` | Plugin booted behind WooCommerce, order storage mode, permanent tables |
| `00SchemaMigrationIntegrationTest.php` | Schema creation and repeatability |
| `OrderLicenseIssuanceIntegrationTest.php` | T-81: paid order → one license per unit, duplicate hooks, unmapped products, variations, guest orders, renewal orders |
| `OrderRefundIntegrationTest.php` | T-81: partial refunds by quantity, full refunds with and without line items, amount-only refunds |
| `AuthorizationIntegrationTest.php` | T-23: foreign customer, missing/invalid nonce and missing capability for every `admin_post_wplc_*` handler |
| `RestConformanceIntegrationTest.php` | T-83: every route in `docs/openapi-v1.json`: status codes, error codes, required fields, envelope signatures |
| `ApiSecurityIntegrationTest.php` | Signature, timestamp window, replay, rate limit |
| `ConcurrencyIntegrationTest.php` | T-84: one-time download grants, nonce replay, status compare-and-set, expiry sweep |
| `StripeWebhookIntegrationTest.php` | T-85: signature verification, duplicates, conflicts, wrongly linked and late events, resuming a pending inbox entry |
| `AuditRedactionIntegrationTest.php`, `PersonalDataIntegrationTest.php` | Audit redaction, privacy export/erasure |

Conventions:

- Extend `WPLC_Integration_TestCase` (`tests/integration/helpers/IntegrationTestCase.php`). It provides WooCommerce fixtures, license/activation seeding, signed REST requests and `run_admin_post()`.
- Handlers that end with a redirect or a bare status header followed by `exit` are tested with `halt_instead_of_exit()`, which turns `wp_redirect()` and `status_header()` into a `WPLC_Test_Halt` exception.
- A test that exposes a defect in `src/` asserts the correct behavior and calls `markTestIncomplete( 'BUG: …' )` while the defect exists. It turns green without changes once the defect is fixed. `SPEC GAP: …` marks a response the code returns but `docs/openapi-v1.json` does not declare. Incomplete tests do not fail the job; `vendor/bin/phpunit -c phpunit.integration.xml -v` lists them.
- Not covered: real parallel requests (two database connections), a live Stripe account, the WooCommerce Stripe gateway plugin (its amount helper is replaced by `helpers/StripeHelperStub.php`), and the "webhook secret not configured" branch (the constant cannot be undefined inside one PHP process).

## PHPCS

```bash
composer phpcs:blocking    # security + compatibility against phpcs-baseline.json (the CI gate)
composer phpcs             # full WordPress-Extra + WordPress-Docs report (advisory)
vendor/bin/phpcs --standard=phpcs.blocking.xml   # the raw blocking findings, including baselined ones
```

- `phpcs.blocking.xml`: `WordPress.Security.*`, `WordPress.DB.PreparedSQL`, `WordPress.DB.PreparedSQLPlaceholders` for the plugin code, and `PHPCompatibilityWP` with `testVersion 7.4-` for plugin code and tests.
- `phpcs.xml.dist`: the full standard with text domain `wp-license` and prefixes `wplc`/`WPLC`. PSR-4 file names and the `wplc/...` hook names are allowed.
- PHP_CodeSniffer has no baseline, so `bin/phpcs-blocking.php` compares the number of findings per file and sniff with `phpcs-baseline.json`. More findings than recorded fail the job and all occurrences in that file are printed. The baseline holds the findings that were reviewed and judged false positives (mostly `{$tables[…]}` table names from `Schema::table_names()` inside `$wpdb->prepare()` strings).
- A new finding must be fixed or carry a justified `// phpcs:ignore <Sniff> -- reason` comment. After a review that removes findings, run `composer phpcs:baseline` and commit the smaller baseline. Never update the baseline to make a new finding disappear.
- Limitation: PHPCompatibility 9.3 knows PHP up to 7.4. Newer syntax (`match`, `?->`, named arguments, …) is caught by the syntax gate on PHP 7.4; newer functions are caught only if a unit or integration test on PHP 7.4 executes them.

## PHPStan

```bash
composer phpstan            # level 5, src/ and wp-license.php (the CI gate)
composer phpstan:baseline   # regenerate phpstan-baseline.neon after fixing baselined errors
```

Configuration: `phpstan.neon.dist` with `szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs` and `php-stubs/wp-cli-stubs`. `tests/phpstan-bootstrap.php` defines the plugin constants for the analysis. `phpstan-baseline.neon` holds the errors that existed when the gate was introduced (type-stub strictness, defensive checks, two PHPDoc mistakes); only new errors fail. Classes of the optional WooCommerce Stripe gateway (`WC_Stripe_Helper`, `WC_Stripe_API`) have no stubs and are baselined.

## After merging branches

Both baselines are counts per file. When several branches changed `src/`, run `composer phpcs:blocking` and `composer phpstan` on the merge result, review what is reported as new, and only then regenerate the baselines.
