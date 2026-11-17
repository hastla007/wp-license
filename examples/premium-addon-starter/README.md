# Premium add-on starter

A complete, working WordPress plugin skeleton for the **premium add-on** of a free core plugin, licensed and updated through WP License Captain. Copy it into a new repository per product (Voucher Captain Pro, Pass Captain Pro, Affiliate Captain Pro); the core plugins stay free, ungated and WordPress.org-compatible and never talk to the license server.

It covers section "B. Premium-Add-on" (B1–B11) of the core plugins' TODO.md. Everything runs without Composer at runtime; Composer is only used for PHPUnit.

| Path | Purpose |
| --- | --- |
| `premium-addon-starter.php` | Plugin header (`Requires Plugins`, `Update URI`, `Requires at least`, `Requires PHP`), PHP guard, bootstrap |
| `src/Config.php` | **Everything a product fills in**: product key, entitlement prefix, server URL, pinned server key(s), core plugin constant and minimum version |
| `src/Plugin.php` | Wiring on `plugins_loaded`, core version check with admin notice and clean bail-out, daily WP-Cron job |
| `src/Client/LicenseClient.php` | Vendored copy of the server's client (generated, never edited) |
| `src/License/LicenseService.php` | Activation, signed offline cache, 14-day limit, fail-closed decisions |
| `src/License/Entitlements.php` | `Entitlements::has( 'voucher.example_feature' )`, `Entitlements::limit( 'voucher.monthly_limit' )` |
| `src/Admin/SettingsPage.php` | Settings → *Product* license |
| `src/Update/Updater.php` | Update detection and verified download |
| `src/Uninstaller.php`, `uninstall.php` | Release the activation, delete credentials and cache |
| `bin/init-addon.php` | Create a product add-on from the starter (replaces all placeholders) |
| `bin/vendor-client.php` | Re-copy `LicenseClient` from the license server under the add-on namespace |
| `bin/build-release.php` | Release ZIP + SHA-256 (+ manifest) |
| `tests/` | PHPUnit tests with WordPress stubs and server-generated signed fixtures |
| `PRIVACY.md` | What is sent to the license server and what never is |
| `.github/workflows/ci.yml` | CI template for the product repository |

## Placeholders

| Placeholder | Meaning | Voucher Captain | Pass Captain | Affiliate Captain |
| --- | --- | --- | --- | --- |
| `premium-addon-starter` | slug, folder, main file, text domain | `voucher-captain-pro` | `pass-captain-pro` | `affiliate-captain-pro` |
| `Premium Add-on Starter` | plugin name | Voucher Captain Pro | Pass Captain Pro | Affiliate Captain Pro |
| `Vendor\Product\Premium` | PHP namespace | e.g. `VCAP\Pro` | e.g. `WPCAP\Pro` | e.g. `WPAC\Pro` |
| `premium_addon_starter` / `PREMIUM_ADDON_STARTER` | option/hook prefix / constant prefix | `vcap_pro` | `wpcap_pro` | `wpac_pro` |
| `core-plugin-slug` (`Requires Plugins`) | slug of the core plugin on WordPress.org | `voucher-captain` | check: `pass-captain` (the TODO says `wp-passmaster`, the repository name) | `affiliate-captain` |
| `Config::CORE_VERSION_CONSTANT` | core version constant | `VCAP_VERSION` | `WPCAP_VERSION` | `WPAC_VERSION` |
| `Config::CORE_MIN_VERSION` | lowest supported core version | the first core release with the add-on hooks (A4) | same | same |
| `Requires PHP`, `Config::MIN_PHP` | PHP minimum = the core plugin's, at least 8.5 (the license client since 2.2.0) | **8.5** | **8.5** | **8.5** |
| `Requires at least` | WordPress minimum, at least 7.0 (the license client since 2.2.0) | **7.0** | **7.0** | **7.0** |
| `Config::PRODUCT_KEY` | product key in the server catalog = add-on folder = text domain | `voucher-captain-pro` | `pass-captain-pro` | `affiliate-captain-pro` |
| `Config::ENTITLEMENT_PREFIX` | entitlement key prefix | `voucher.` | `pass.` | `affiliate.` |
| `Config::SERVER_URL`, `Update URI` | HTTPS URL of the shop running WP License Captain | | | |
| `Config::SERVER_PUBLIC_KEYS` | pinned Ed25519 key(s) of the server | output of `wp wplc signing-key --field=public_key` on the license server | | |

Owner decision of 16 Nov 2026 for all Captain plugins (MINVER-1): the minimum is the newest PHP version, 8.5, and the vendored license client (2.2.0) needs it. The starter therefore declares `Requires PHP: 8.5`; keep `Requires PHP` and `Config::MIN_PHP` equal to the core plugin's minimum and never below 8.5.

Owner decision of 17 Nov 2026, also for all Captain plugins, add-ons and connectors: WordPress 7.0 and WooCommerce 11.2 are the minimums. The starter declares `Requires at least: 7.0`; never lower it. An add-on that needs WooCommerce declares `WC requires at least: 11.2` or the core plugin's higher minimum.

### Product key (decided 14.11.2026)

The product keys are `voucher-captain-pro`, `pass-captain-pro` and `affiliate-captain-pro` (key = add-on folder = text domain), see `docs/ENTITLEMENTS.md`. The production add-ons are built in the repository hastla007/captain-pro, which copies the license client from `src/Client`; this starter stays a tested reference for the server interface. The key must be set on the license products in WooCommerce before the first sale and never changed afterwards: activations, update checks and releases are matched on the exact string.

## Creating a product add-on (B1–B11)

1. **B1 · Repository and skeleton.** Create the new repository (e.g. `voucher-captain-pro`) and generate the add-on into it from a license server checkout:

   ```bash
   cd wp-license/examples/premium-addon-starter
   php bin/init-addon.php --target=../../../voucher-captain-pro \
     --name="Voucher Captain Pro" --slug=voucher-captain-pro --namespace='VCAP\Pro' --prefix=vcap_pro \
     --product-key=voucher-captain-pro --entitlement-prefix=voucher. \
     --core-name="Voucher Captain" --core-slug=voucher-captain --core-constant=VCAP_VERSION --core-min=1.1.0 \
     --requires-php=8.5 --server-url=https://licenses.example.com \
     --public-key="$(wp wplc signing-key --field=public_key --path=/path/to/license-shop)"
   ```

   The header gets `Requires Plugins: <core slug>`, `Update URI: <shop>/<slug>` (so WordPress never mixes the add-on up with a WordPress.org plugin) and `Requires PHP`. On `plugins_loaded` the add-on checks the core plugin's version constant; if the core is missing or too old it shows an admin notice and loads nothing else. Fill in Author and Plugin URI by hand.
2. **B2 · Code from the old license PRs.** Do not copy the PR code: the starter already contains the license page, entitlement helper, offline cache and updater. Go through the audit findings that moved with the PRs and tick them off against the starter (see "Audit findings" below).
3. **B3 · Client on the current server state.** `src/Client/LicenseClient.php` is generated from the server's `src/Client/LicenseClient.php` with the add-on namespace and text domain (`php bin/vendor-client.php`, `--check` in CI). Never edit it; fix the server copy and re-vendor in every add-on.
4. **B4 · Product key.** Set `Config::PRODUCT_KEY` (see above) and the same key on the license products in WooCommerce. The product key is the add-on's folder and text domain (`voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`), never the key of the free core. Plans for every product: `single` 1 / `business` 5 / `agency` 25 live sites, same features in every plan.
5. **B5 · Premium features and entitlements.** Build each premium feature in the add-on (never cut it out of the core) and bind it to exactly one key from the license server's `docs/ENTITLEMENTS.md`, e.g.:

   ```php
   add_action( 'vcap_pro_loaded', function () {
   	if ( \VCAP\Pro\License\Entitlements::has( 'voucher.example_feature' ) ) {
   		// register the PDF template screen, routes and jobs
   	}
   } );
   ```

   Check the entitlement in every entry point (admin screen, REST route, AJAX, cron job, template), not only where the menu is registered. A missing key switches off only that feature; the core is untouched.
6. **B6 · Expiry, revocation, outage.** Built in: the cache is the complete signed envelope, verified with `verify_cached_envelope()` (HMAC and the pinned server signature) before each use, valid at most 14 days after the server's signed `server_time`. Server errors keep the cache; a signed expired/revoked/suspended answer replaces it at once; an activation unknown to the server drops it. A copy of the site (the stored `home` option names another address than the activated one, or the server signs `site_match: false`) unlocks nothing and sends nothing with the copied credentials; the license page says so. An answer whose signed `server_time` is older than the newest one already seen (`server_time_max`, five minutes tolerance) is refused as a replay, and an older envelope written back into the option is void. If WP-Cron does not run, an administrator's admin page view catches up 36 hours after the last successful check (one hour after a failed one). Only `active` and `past_due` (the server's renewal grace) grant access; the client does not compute grace itself. Data a premium feature created stays readable when the feature is off: gate creating and premium actions, not reading.
7. **B7 · Updater.** Built in: detection from WP-Cron and from WordPress' own update check (no logged-in admin needed) with `download: false` (no download grant on the server, the short default timeout; the update transient carries a fixed placeholder, never a download URL), a fresh one-time package URL right before download, redirects followed by hand to allow-listed HTTPS hosts only (default: the server host; add a remote storage host with the `<prefix>_update_hosts` filter), size limit (`Config::MAX_PACKAGE_BYTES`, filter `<prefix>_max_package_bytes`), SHA-256 compared before WordPress unpacks anything. Any failure returns a `WP_Error` and leaves the installed version untouched.
8. **B8 · Deactivation and uninstall.** Deactivating the plugin keeps the license (only the cron event is removed). Deleting it releases the activation at the server (best effort) and deletes the credentials, a pending activation, the cache and the update data on every site of a network. Sites are handled in batches of 100; the server is only asked during the first 20 seconds, then only local data is deleted, so an unreachable server cannot break the uninstall. Core plugin data is never touched.
9. **B9 · Privacy.** Adapt `PRIVACY.md` (it is shipped in the ZIP) and name the license server under "External services" in the add-on's readme.
10. **B10 · Tests.** `composer install && WPLC_SERVER_DIR=/path/to/wp-license vendor/bin/phpunit` (see "Tests"). CI on the PHP minimum (`.github/workflows/ci.yml`; set the matrix and a `WP_LICENSE_READ_TOKEN` secret if the server repository is private). Add one test per premium feature: off without entitlement, on with it.
11. **B11 · Release.** Tag `vX.Y.Z` (= `Version` header = version constant). CI runs `php bin/build-release.php` and attaches `<slug>-X.Y.Z.zip` and `.sha256`. Upload the ZIP under **WooCommerce → License releases** on the license server (it computes the checksum; compare with the `.sha256`), or import `dist/*.manifest.json` built with `--package-url=…` for a remotely stored package; publish. Then once per product in the test shop: buy, activate on a test site, check a premium feature, install the update through **Dashboard → Updates**, deactivate.

## Configuration in a test shop

Release builds carry the values in `Config`. For a staging license server set in `wp-config.php` of the test site:

```php
define( 'VCAP_PRO_SERVER_URL', 'https://licenses-staging.example.com' );
define( 'VCAP_PRO_SERVER_PUBLIC_KEYS', array( 'base64-public-key-of-the-staging-server' ) );
```

The filters `<prefix>_server_url`, `<prefix>_server_public_keys` and `<prefix>_update_hosts` do the same in code. `<prefix>_server_public_keys` is ignored unless `define( 'VCAP_PRO_LICENSE_DEV', true );` is set (development builds), so another plugin cannot pin a key of its own on a live site. Without a pinned key the add-on refuses to activate and unlocks nothing.

## License page

**Settings → *Product* license** (`manage_options`, nonce on every form, all output escaped): license key, environment (production / staging with the live site's installation ID / development), status, valid until, sites used / limit, granted features, environment, installation ID (needed as parent by a staging site), last check, last error; buttons *Check now* and *Deactivate on this site*. Rendering never calls the server; it shows the last verified snapshot. The API has no plan name, so the page lists the granted features instead.

## Tests

```bash
composer install
vendor/bin/phpunit                                     # standalone
WPLC_SERVER_DIR=/path/to/wp-license vendor/bin/phpunit # plus sync checks against the server code
```

Inside the license server repository the starter's tests run with `composer test` (or `composer test:starter`), so its CI exercises them, and flow `tests/e2e/flows/12-addon-starter.php` installs a generated add-on in the e2e shop and runs purchase, activation through the settings form, entitlements, refresh, outage, tampered cache, suspension, update detection without a user, a refused package with a wrong checksum, a verified update and uninstall against the real server.

- `tests/fixtures/*.json` are signed with the server's own `Response::envelope()` and `ResponseSigner` by `tests/bin/generate-fixtures.php` (fixed test keys, deterministic). `ServerSyncTest` regenerates them in memory and fails when the server format changed; it also checks that the vendored client equals the server copy.
- `tests/Support/FakeServer.php` answers live requests with envelopes built exactly like the server's (proven by `ServerSyncTest`).
- Covered: fail-closed gate, active / past-due / expired / revoked / foreign-product answers, answers for another installation or signed by another key, tampered cache with a valid HMAC but invalid server signature, cache older than 14 days, copies of the site (address changed, signed `site_match: false`), replayed older answers, catch-up without WP-Cron, server errors keeping the cache within its age, activation unknown to the server, a cache that cannot be stored, product mismatch at activation, key change, deactivation, update detection without a user (`download: false`, placeholder package), checksum mismatch, size limit, redirect hosts, uninstall (also batched on a network with a time budget).

## Audit findings carried over from the old license PRs

| Finding (core TODOs) | In the starter |
| --- | --- |
| Rights not bound to the product of the answer | only keys with `Config::ENTITLEMENT_PREFIX` count; the server's Ed25519 signature binds the answer to this installation; a license of another product is refused at activation (`product_mismatch`) |
| Admin shows the activation form on a server error | the page reads the stored snapshot, keeps *Check now* / *Deactivate* and shows the error separately |
| Activation reports success without checking storage | `LicenseClient` reads back the option (`wplc_credentials_not_saved`) |
| Revocation could survive a failed cache write | a new answer that cannot be stored deletes the old cache |
| License page blocks until a network timeout | no request while rendering |
| Update detection depends on a logged-in admin | no capability check in detection; cron job |
| No size limit for the update ZIP | `limit_response_size` + `Content-Length` + file size check |
| Credentials remain after uninstall | `Uninstaller` deletes them on every site |
| Repeated activation creates extra slots | `activate()` reuses working credentials; a new key first releases the old activation; the server replaces an activation of the same site URL |

## Known limits

- `check_entitlements()` sends only the product version, so the server's stored WordPress/PHP versions are only those of the activation (see `docs/CLIENT-INTEGRATION.md`, "Known gaps").
- The entitlement answer has no `product_key` field; product binding relies on the key prefix and the installation-bound server signature.
- A site that can write its own options can restore an older, still valid signed "active" answer for up to 14 days after it was issued. That is the accepted offline window of B6.
