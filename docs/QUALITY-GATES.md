# Quality gates

Every gate below runs in GitHub Actions on each pull request and on pushes to `main` (not on pushes to other branches, so a PR runs each job once). All commands work locally from the plugin root after `composer install`.

No `composer.lock` is committed: the dependencies are development tools only and `composer.json` resolves them for the PHP minimum (`config.platform.php` 8.5, MINVER-1), so CI and a development machine with an older PHP get the same versions. CI caches `vendor/` per PHP version and `composer.json` hash.

## Overview

| Gate | Workflow / job | Blocking | What it proves |
|---|---|---|---|
| Composer metadata | `phpunit.yml` / `unit (X)` | yes | `composer.json` is valid |
| PHP syntax | `phpunit.yml` / `unit (X)` | yes | Every PHP file parses on PHP 8.5 (and on the PHP 8.6 nightly build, experimental) |
| Unit tests | `phpunit.yml` / `unit (X)` | yes | Pure logic without WordPress, on PHP 8.5 (and on the PHP 8.6 nightly build, experimental) |
| Site URL rules without intl | `phpunit.yml` / `unit (X)` | yes | `SiteUrl::normalize()` behaves as documented when `idn_to_ascii()` is unavailable |
| Documents agree with the repository | `phpunit.yml` / `unit (X)` (`ConsistencyCheckTest`, runs `bin/check-consistency.php`) | yes | The counted overview of [TODO.md](TODO.md) matches its task lines; the job and cell numbers in this file and in TODO.md match `.github/workflows`; every `.po` file has exactly the texts of `languages/wp-license.pot`, all translated, and the template carries the plugin version |
| Integration tests | `phpunit.yml` / `integration (…)` (9 cells: WooCommerce 8.9.3, 9.4.3 and 11.2.2, each with HPOS and legacy orders, plus a second HPOS cell for 11.2.2 and one multisite cell, all on PHP 8.5 with MySQL 9.7 or MariaDB 12.3, plus one experimental cell on PHP 8.6), summarized by `wordpress-integration` | yes | Behavior inside WordPress + WooCommerce with a real database, see matrix in [COMPATIBILITY.md](COMPATIBILITY.md) |
| PHPCS security + compatibility | `quality.yml` / `phpcs` | yes (new findings) | Escaping, nonces, input sanitization, prepared SQL, PHP 8.5 compatibility |
| PHPCS coding standard | `quality.yml` / `phpcs` | no (report only) | WordPress-Extra and WordPress-Docs style |
| PHPStan level 5 | `quality.yml` / `phpstan` | yes (new errors) | Undefined methods/variables, wrong argument counts and types in `src/` and `wp-license.php` |
| Plugin ZIP | `package.yml` / `zip` | yes | `bin/build-zip.sh` (the release build) on PHP 8.5: versions agree, ZIP contents, class map covers `src/` |
| End-to-end run | `e2e.yml` / `e2e (WP latest, WC 11.2.2, PHP 8.5)` | yes | The 15 flows of `tests/e2e/` in a real WordPress + WooCommerce shop with MySQL 9.7, including concurrent requests ([tests/e2e/README.md](../tests/e2e/README.md)) |

`wordpress-integration` is a single job that fails unless every integration cell passed; use it (and `unit (8.5)`, `phpcs`, `phpstan`, `zip`, `e2e (…)`) as required checks.

A pull request or a push to `main` starts 16 jobs: 2 unit, 9 integration cells, `wordpress-integration`, `phpcs`, `phpstan`, `zip` and `e2e`. The browser pass of [BROWSER-ACCEPTANCE.md](BROWSER-ACCEPTANCE.md) is not one of them; it runs locally only.

**PHP and database minimums (MINVER-1, owner decision of 16 Nov 2026).** Every blocking job runs PHP 8.5, the declared minimum (`Requires PHP`, `Requirements::MIN_PHP`); the integration cells and the E2E run use the minimum database servers MySQL 9.7 (LTS, Docker image `mysql:9.7`) and MariaDB 12.3 (LTS, `mariadb:12.3`). `unit (8.6, experimental)` and the integration cell on PHP 8.6 run the nightly build of `shivammathur/setup-php` with `continue-on-error`, so they are shown but never fail the run or `wordpress-integration`; once PHP 8.6 is released (19 Nov 2026) and setup-php lists it as stable, make them ordinary cells. The development environment has PHP 8.3 only: the test bootstraps define the test-only constant `WPLC_TESTS_IGNORE_PHP_MINIMUM` so the suites still run there, and these jobs are the only place where the code runs on 8.5. Before MINVER-1 the newest PHP version ran as extra cells (INH-03). Their first run (16 Nov 2026) found a real difference: `mb_strtolower()` of PHP 8.5 knows the case pair U+A7DC/U+019B of Unicode 16, so the host normalization without intl accepted a capital letter that intl rejected; `SiteUrl::fallback_to_ascii()` now checks the input before lower-casing (`SiteUrlNormalizationTest::test_idn_fallback_rejects_capitals_whose_lower_case_lies_in_an_accepted_block`).

These numbers are checked: `php bin/check-consistency.php` fails when they differ from the workflow files. It cannot see a text that changed in the code after the last run of `bin/make-pot.sh`; regenerating the template before a release stays a manual step ([RELEASE-READINESS.md](RELEASE-READINESS.md)).

A red job on a commit that did not change code is a defect of the test suite, not noise: find the failing test and its cause before restarting the job (rule in [RELEASE-READINESS.md](RELEASE-READINESS.md)).

## Syntax and unit tests

```bash
composer lint          # php -l on every PHP file with the PHP on your PATH
composer test          # PHPUnit, tests/ without tests/integration
php -d disable_functions=idn_to_ascii vendor/bin/phpunit --filter SiteUrl
```

Unit tests load `tests/bootstrap.php`, which only defines constants. They must not need WordPress.

`tests/Api/SiteUrlNormalizationTest.php` pins down the URL identity rules (T-87, T-146; documented in [API-V1.md](API-V1.md#site_url-rules)). With intl it also checks, code point by code point, that the built-in IDN fallback never produces a different result than `idn_to_ascii()`. Rows named `SURPRISING …` document remaining behavior that is probably unintended; change them only together with `src/Api/V1/SiteUrl.php`:

- Path case and percent-encoding are not normalized; spaces and raw UTF-8 in the path are accepted.
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

The first line of output states what is actually running, for example `WordPress 6.5.5, WooCommerce 9.4.3, order storage: HPOS, PHP 8.5.0`. `00EnvironmentIntegrationTest.php` fails when the order storage (and in CI the WooCommerce version) is not the requested one.

Environment variables:

| Variable | Default | Meaning |
|---|---|---|
| `WP_TESTS_DIR` | `/tmp/wordpress-tests-lib` | WordPress test library |
| `WP_CORE_DIR` | `/tmp/wordpress` | WordPress core; WooCommerce is expected in `wp-content/plugins/woocommerce` |
| `WPLC_WC_DIR` | derived from `WP_CORE_DIR` | Another WooCommerce directory (must be a release build with `vendor/`) |
| `WPLC_TEST_HPOS` | `1` | `1` = HPOS, `0` = legacy order storage |
| `WP_MULTISITE` | `0` | `1` installs WordPress as a multisite network (read by the WordPress test library); `MultisiteIntegrationTest.php` only runs then |
| `WPLC_EXPECT_WP_VERSION`, `WPLC_EXPECT_WC_VERSION` | unset | When set, the environment test asserts these versions |

What the suites cover:

| File in `tests/integration/` | Covers |
|---|---|
| `00EnvironmentIntegrationTest.php` | Plugin booted behind WooCommerce, order storage mode, permanent tables |
| `00SchemaMigrationIntegrationTest.php` | Schema creation and repeatability |
| `ActivationTransferIntegrationTest.php` | A-22: a customer moves a site (with its staging site) from an ended license to a newer one, the installation keeps working on the new license, limits and ownership rules; the "Renew instead" notice in cart and checkout with its renewal link, filter and dismissal |
| `MultisiteIntegrationTest.php` | Network only (`WP_MULTISITE=1`): tables, schema version, licenses, REST answers, lock names and cron events per site; network/site activation and deactivation, site deletion, uninstall (docs/MULTISITE.md) |
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
- A test that exposes a defect in `src/` asserts the correct behavior and calls `markTestIncomplete( 'BUG: …' )` while the defect exists. It turns green without changes once the defect is fixed. A response the code returns but `docs/openapi-v1.json` does not declare fails the REST conformance tests (`SPEC GAP: …`). Incomplete tests do not fail the job; `vendor/bin/phpunit -c phpunit.integration.xml -v` lists them.
- Not covered: real parallel requests (two database connections), a live Stripe account, the WooCommerce Stripe gateway plugin (its amount helper is replaced by `helpers/StripeHelperStub.php`), and the "webhook secret not configured" branch (the constant cannot be undefined inside one PHP process).

## PHPCS

```bash
composer phpcs:blocking    # security + compatibility against phpcs-baseline.json (the CI gate)
composer phpcs             # full WordPress-Extra + WordPress-Docs report (advisory)
vendor/bin/phpcs --standard=phpcs.blocking.xml   # the raw blocking findings, including baselined ones
```

- `phpcs.blocking.xml`: `WordPress.Security.*`, `WordPress.DB.PreparedSQL`, `WordPress.DB.PreparedSQLPlaceholders` for the plugin code, and `PHPCompatibilityWP` with `testVersion 8.5-` for plugin code and tests.
- `phpcs.xml.dist`: the full standard with text domain `wp-license` and prefixes `wplc`/`WPLC`. PSR-4 file names and the `wplc/...` hook names are allowed.
- PHP_CodeSniffer has no baseline, so `bin/phpcs-blocking.php` compares the findings with `phpcs-baseline.json`, per file, sniff and source line (the reported line, trimmed, whitespace collapsed). A finding whose line text is not recorded fails the job, even when another finding of the same sniff in that file was removed; a reviewed line that only moved stays accepted. For a statement spread over several lines the recorded text is the line PHPCS reports (often just `$wpdb->prepare(`), so the check is only as precise as that line. A baseline in the former count format is still compared by count until it is rewritten. The baseline holds the findings that were reviewed and judged false positives (mostly `{$tables[…]}` table names from `Schema::table_names()` inside `$wpdb->prepare()` strings).
- A new finding must be fixed or carry a justified `// phpcs:ignore <Sniff> -- reason` comment. After a review that removes findings, run `composer phpcs:baseline` and commit the smaller baseline. Never update the baseline to make a new finding disappear.
- Limitation: PHPCompatibility 9.3 knows PHP up to 7.4, so with `testVersion 8.5-` it only reports what PHP removed up to then. Syntax newer than the minimum fails the syntax gate on PHP 8.5; functions newer than 8.5 are caught only if a unit or integration test on PHP 8.5 executes them.

## PHPStan

```bash
composer phpstan            # level 5, src/ and wp-license.php (the CI gate)
composer phpstan:baseline   # regenerate phpstan-baseline.neon after fixing baselined errors
```

Configuration: `phpstan.neon.dist` with `szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs` and `php-stubs/wp-cli-stubs`. `tests/phpstan-bootstrap.php` defines the plugin constants for the analysis. `phpstan-baseline.neon` holds the errors that existed when the gate was introduced (type-stub strictness, defensive checks, two PHPDoc mistakes); only new errors fail. Classes of the optional WooCommerce Stripe gateway (`WC_Stripe_Helper`, `WC_Stripe_API`) have no stubs and are baselined.

## After merging branches

The PHPStan baseline is a count per file; the PHPCS baseline lists source lines. When several branches changed `src/`, run `composer phpcs:blocking` and `composer phpstan` on the merge result, review what is reported as new, and only then regenerate the baselines.
