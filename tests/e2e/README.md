# End-to-end run in a real WordPress + WooCommerce

The unit tests cover pure logic and the integration suite covers single classes. This
harness runs the whole plugin in a real shop: activation, product editor, checkout,
the REST API through the bundled `LicenseClient` over real HTTP, release uploads and
downloads, refunds, admin and customer screens, renewals, reminders, privacy tools,
deactivation and uninstall.

## Run

```bash
tests/e2e/bin/run-e2e.sh            # set up the site, reset its database, run every flow
tests/e2e/bin/run-e2e.sh 04 05      # only some flows (prefix of the file name); needs the earlier flows' state
WPLC_E2E_KEEP_DB=1 tests/e2e/bin/run-e2e.sh 07-renewals-auto   # re-run one flow on the existing database
```

Output is one `PASS`/`FAIL` line per assertion, a summary per flow, and the exit code is
non-zero when an assertion fails, a flow script aborts, or plugin code wrote to
`wp-content/debug.log` during a flow (`WP_DEBUG` and `WP_DEBUG_LOG` are on).

A full run takes about four minutes after the first setup.

### Requirements

- PHP CLI (7.4+; mysqli, curl, zip), `curl`, `tar`, a `mysql`/`mariadb` client.
- A MySQL/MariaDB server. The database named by `WPLC_E2E_DB_NAME` (default `wplc_e2e`)
  is **dropped and recreated** on every run without `WPLC_E2E_KEEP_DB=1`.
- Network access to wordpress.org, or `composer` and `git` for the fallback sources (below).

### Settings (environment variables)

| Variable | Default | Meaning |
| --- | --- | --- |
| `WPLC_E2E_SITE` | `${TMPDIR:-/tmp}/wplc-e2e-site` | Site directory (WordPress, WooCommerce and tools are cached here) |
| `WPLC_E2E_DB_NAME` / `_USER` / `_PASS` / `_HOST` | `wplc_e2e` / `root` / `root` / `127.0.0.1` | Database |
| `WPLC_E2E_WP_VERSION` | `latest` | WordPress version |
| `WPLC_E2E_WC_VERSION` | `11.2.2` | WooCommerce version |
| `WPLC_E2E_PORT` | `8765` | Port of the local PHP server |
| `WPLC_E2E_KEEP_DB` | `0` | `1` = keep the database and site state, only copy the plugin and run flows |

## What the driver sets up

1. **WP-CLI** (phar) into `$WPLC_E2E_SITE/.e2e-tools`.
2. **WordPress**: `wp core download`. If wordpress.org cannot be reached, the same release from
   the Composer mirror `johnpbloch/wordpress-core`.
3. **WooCommerce**: the release ZIP from downloads.wordpress.org. If that host cannot be
   reached, the Git tag of github.com/woocommerce/woocommerce (`plugins/woocommerce`,
   `composer install --no-dev`, generated feature config). That build has **no compiled
   JavaScript/CSS**. The driver therefore writes empty asset registries for the classic admin
   scripts and the mu-plugin switches the React admin off; WooCommerce *settings* screens
   cannot be opened in this build (the flows do not need them).
4. `wp-config.php` with `WP_DEBUG`, `WP_DEBUG_LOG`, no outgoing HTTP except the local server,
   and test-only `WPLC_*` key constants.
5. `wp core install` with URL **https://shop.test**, pretty permalinks, WooCommerce pages,
   currency EUR, base country DE, offline gateways enabled, "coming soon" mode off.
6. The plugin is **copied without `vendor/`, `.git` and `tests/`** to
   `wp-content/plugins/wp-license`, so every run proves the fallback autoloader.
7. `mu-plugins/wplc-e2e.php` is copied to the site (see below).
8. A PHP built-in server on `127.0.0.1:$WPLC_E2E_PORT` with `router.php`.

### HTTPS

The plugin requires HTTPS (the client refuses `http://` servers, package URLs must be HTTPS).
No TLS certificate is involved:

- WordPress is installed as `https://shop.test`.
- `router.php` sets `HTTPS=on` and `Host: shop.test` for every request of the PHP server, so
  the server side sees a normal HTTPS request.
- The mu-plugin routes every `wp_remote_request()` for `https://shop.test/…` to
  `http://127.0.0.1:$PORT/…` (filter `pre_http_request`).

So the flows (WP-CLI process) and the shop (PHP server process) are separate PHP processes
talking real HTTP; request signatures, nonces, cookies, redirects and file streams are real.

## The mu-plugin (test only)

- HTTP routing as described above.
- **Mail capture**: `pre_wp_mail` stores every mail in the options table
  (`wplc_e2e_mails()`), nothing is sent.
- **Fake recurring gateway** `wplc_e2e_fake`: a WooCommerce gateway that supports
  tokenization plus an adapter implementing `WPLC\Gateway\RecurringGatewayAdapter`, registered
  through `wplc_recurring_gateway_adapters`. The option `wplc_e2e_fake_mode` selects the
  result of the next charge: `success`, `decline`, `manual` (`renewal_manual_action_required`)
  or `unknown` (`renewal_result_unknown`). Every charge call is recorded in the option
  `wplc_e2e_fake_charges` (order, total, token, idempotency key).

## Flows

| File | Covers |
| --- | --- |
| `01-activation.php` | Activation hook, fallback autoloader, 13 tables, schema option, repeatable migration, cron/Action Scheduler, REST routes, every admin screen over HTTP |
| `02-catalog.php` | Product editor POST (simple product, variations via admin-ajax, whole variable product, parent-level mapping), price changes, invalid input, CSV export/import round trip |
| `03-purchase.php` | Forced registration, checkout, quantity 2 → 2 licenses + entitlements, repeated hooks, "license issued" email (no full key by default), My Account |
| `04-client-server.php` | `LicenseClient`: activate, signed entitlement check, both response signatures, replay/expiry/tamper checks, site limit, staging, development, deactivation cascade, replacing a site, customer deactivation |
| `05-updates.php` | Release with uploaded ZIP, publish, update check, one-time download → stream, SHA-256, token reuse, customer download (owner / other customer), withdraw, manifest import |
| `06-lifecycle.php` | Renewal consent at checkout, cancel at period end + resume, partial and full refund, admin suspend/reactivate/extend/release site, manual license |
| `07-renewals-auto.php` | Automatic renewal: success, decline + retries + grace + expiry, manual action required, customer pays failed order, cancellation while past due, unknown outcome |
| `07-renewals-manual.php` | `wplc_start_renewal`, one order, pay page POST, duplicate hooks, expired license inside/outside the window, late payment |
| `07-renewals-tax.php` | 20 % tax, prices including and excluding tax, 9 prices × 2 customer countries: renewal total = purchase total (manual and automatic) |
| `08-reminders.php` | Settings screen save, expiry reminder exactly once across daily runs, renewal notice, reminders off |
| `09-privacy.php` | Exporter and eraser through the WordPress privacy AJAX endpoints |
| `10-deactivate-uninstall.php` | Deactivation and uninstall clear scheduled work and keep data; overdue renewal after re-activation |

Flows share state through the option `wplc_e2e_state` (IDs of products, customers, orders).
Helpers are in `lib.php`.

### Simulated time

Nothing waits for real time. Where a flow needs "the period ended an hour ago" or "grace
ended yesterday" it edits dates in the e2e database with `e2e_move_paid_until()` and
`e2e_age_renewal()` (both in `lib.php`) and then lets the plugin's own code run. Jobs are
executed through the Action Scheduler runner regardless of their scheduled date
(`e2e_run_action()`). Assertions that depend on this say `[time simulated]`.

## Not covered

- Stripe (no account): the Stripe adapter, the Stripe webhook route and dispute handling.
- Real mail delivery (mails are captured before sending) and mail client rendering.
- Anything that needs a browser: JavaScript of the product editor and account page, the block
  checkout, the classic checkout form POST (orders are created through `WC_Checkout::create_order()`
  with the real hooks), WooCommerce settings screens.
- Multisite, object caches, real web servers (Apache/nginx rules for the package directory).

## Adding it to CI

Not wired into the workflows on purpose (they belong to the Tests/CI work). A job needs a
MySQL/MariaDB service and then only:

```yaml
- uses: shivammathur/setup-php@v2
  with: { php-version: '8.3', extensions: mysqli, curl, zip, tools: composer }
- run: tests/e2e/bin/run-e2e.sh
  env:
    WPLC_E2E_DB_HOST: 127.0.0.1
    WPLC_E2E_DB_USER: root
    WPLC_E2E_DB_PASS: root
    WPLC_E2E_SITE: ${{ runner.temp }}/wplc-e2e-site
```

On a CI runner wordpress.org is reachable, so the official WordPress and WooCommerce ZIPs
are used and the workarounds for the source build do not apply.
