# Launch evidence register

Complete this register before enabling paid production checkout. Every evidence row needs a named owner, date, environment/version, result, and link to the run or approval record. Do not store credentials or personal customer data in this file.

| Gate | Required evidence | Owner | Date | Result / evidence link |
|---|---|---|---|---|
| PR review and merge order | All required PRs reviewed, merge conflicts resolved, deployment tag recorded |  |  |  |
| WooCommerce checkout | Order → license, monthly/yearly, duplicate order event, tax/invoice behavior |  |  |  |
| Stripe test mode | Success, decline, timeout, duplicate event, SCA recovery, refund | Agent probe run (owner sign-off open) | 2026-11-25 | **Partial.** Success, decline, timeout, duplicate event, refund, dispute passed against a Stripe test account (section "T-12" below); SCA recovery in the browser, delivery by Stripe itself and a human sign-off are open. |
| PayPal scope | Auto-renew remains disabled unless a supported adapter is approved and sandboxed |  |  |  |
| Product clients | Activation, feature gates, site/staging limit, offline expiry, update install per product |  |  |  |
| Package security | Immutable ZIP, manifest SHA-256, one-use grant, host allow-list, rollback |  |  |  |
| Database / recovery | Upgrade from previous schema, backup restore, renewal and event reconciliation |  |  |  |
| Security / privacy | Secret storage/rotation, access review, retention, incident contact |  |  |  |
| Customer terms | Pricing, recurring consent, cancellation, refund, privacy and support copy approved |  |  |  |
| Operations | Alert delivery, on-call owner, restore runbook, rollback owner |  |  |  |

**Decision:** No-go until every mandatory row has a passing result or a documented product decision. A documentation template is not evidence of a completed test or legal approval.

## Rehearsal records

Rehearsals on copies of the e2e test shop. They prove the procedures and tools; they are not evidence that production keys, backups or releases have been handled.

### T-86: key rotation and key-ID recovery (WP-CLI) on a database copy

| Item | Value |
|---|---|
| Date | 2026-11-14 |
| Environment | WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3 CLI + `php -S` with `tests/e2e/router.php`, MariaDB 10.11; copy of the e2e site and database (`wplc_ops`: 96 licenses, 24 activations, 197 audit rows) |
| Clients | Bundled `LicenseClient` over real HTTP (`tests/e2e` mu-plugin routes `https://shop.test`): 11 existing e2e installations (unpinned), 2 installations pinning the server's Ed25519 key, activation probes on 3 free licenses (activate, entitlement check, deactivate) |
| Key ring before | `WPLC_LICENSE_KEY_SECRETS` / `WPLC_INSTALLATION_ENCRYPTION_KEYS` = `{e2e-2026}`, signing key in the option (`b59ba29ed5aee05f`) |
| Result | **Pass after fixes.** Two defects found and fixed (below); runbook in docs/SECURITY-OPERATIONS.md rewritten from the rehearsal. |

Steps and observed results (secrets redacted, outputs summarized):

1. **Baseline and backup.** All 96 license keys derivable and saved as "customer copies"; 7 existing installations authenticate (`ok`), 4 have no active activation (`activation_not_found`, unchanged throughout); probes and pinned installations `ok`. `mysqldump --single-transaction` of `wplc_ops`, plus `wp-config.php`; checksums of `licenses(id,license_key_secret_id,license_key_hash)` and `activations(id,installation_secret_encrypted)` recorded.
2. **Add `2026-11` to both rings and make it current.** Customer keys 96/96 identical; installations unchanged; probes `ok`. Authenticated installations were re-encrypted lazily (9 active rows moved to `v1:2026-11`).
3. **`wp wplc rotate-installation-secrets`** dry run: `Inspected: 27; needing rotation: 15`; `--apply --batch-size=5`: 15 rotated, 15 audit rows `installation_secret_rotated`; dry run again: `needing rotation: 0`. `recover-license-key-ids --to=2026-11`: `already verified: 96; unmatched: 0`.
4. **New license** (manual issue) stored with key ID `2026-11`, activates, checks and deactivates through the client. Existing licenses keep `e2e-2026` (by design).
5. **Retire the old encryption key** as the runbook then allowed ("dry run reports nothing left"). **Defect 1:** the stored response signing key was still encrypted with `e2e-2026`; the server stopped sending `signature_ed25519`, both pinned installations and all 3 activation probes failed with `wplc_server_signature_invalid`, and the 3 failed activations had still created activation rows (site slots used up). `wp wplc signing-key --rotate` would have replaced the key clients pin. Fixed (see below); with the fix the dry run prints `Response signing key: CANNOT BE DECRYPTED` plus a warning, `signing-key` and `signing-key --rotate` refuse with a restore hint. Recovery: old key back into the ring → `Response signing key: needs rotation` → `--apply`: `rotated` (audit `response_signing_key_reencrypted`) → old key retired again → signing key id unchanged `b59ba29ed5aee05f`, all checks `ok`. The 3 orphaned activations were released.
6. **Signing-key rotation with pinning.** `wp wplc signing-key --prepare` → `next_key_id: f23270539159b55c` (a second `--prepare` returned the same key). Before the switch: clients pinning old+new `ok`, pinning only new rejected. `wp wplc signing-key --rotate` → `key_id: f23270539159b55c` (audit `response_signing_key_rotated` `b59ba29ed5aee05f` → `f23270539159b55c`); clients pinning old+new `ok`, activation probes `ok`, clients pinning only old rejected (`wplc_server_signature_invalid`, expected).
7. **Mistake: old license secret filed under a wrong ID** (`e2e-2026-old`). Customer account: "Currently unavailable" for all 96; activation with the customers' keys still `ok` (hash lookup); installations unaffected. `recover-license-key-ids --to=2026-11`: `unmatched: 96`, warning, nothing changed. `--to=e2e-2026-old`: `recoverable: 96`; `--apply --max-rows=10` then `--after-id=…`: 96 rows corrected, 96 audit rows `license_key_id_recovered`; keys shown again. Operator slip observed: resuming with `--after-id=11` instead of the printed `last ID: 10` skipped one row; the next dry run showed `recoverable: 1` and a run from `--after-id=10` fixed it (documented).
8. **Mistake: old license secret removed.** 96 keys unavailable, activations still work; `recover-license-key-ids` with any configured ID: `unmatched: 96`, nothing changed (only restoring the secret helps; documented).
9. **Mistake: `license_key_secret_id` changed by SQL** (rows set to `2026-11`). **Defect 2:** the customer account showed a *different, invalid* key for those licenses (derived with the wrong secret). Fixed: the account only shows a key that reproduces the stored verifier, otherwise "Currently unavailable". `recover-license-key-ids --to=e2e-2026-old --apply` restored 20 (then 3) rows; account shows the right key again.
10. **Mistake: current key ID typo** (`WPLC_LICENSE_KEY_ID` not in the ring): issuance fails (manual license returns 0; paid orders would get no license). It was silent; now logged to WooCommerce logs (`source wp-license`): `License not issued: the current license key ID "e2e-2027" (WPLC_LICENSE_KEY_ID) has no secret in WPLC_LICENSE_KEY_SECRETS.` `recover-license-key-ids --to=<unconfigured>` now errors instead of reporting every row unmatched.
11. **Restore from the dump.** Row counts and both checksums identical to the backup (96/24/197, `e0be7613…`, `27f13d51…`). With the *current* key ring (old encryption key retired): 7 installations `installation_secret_invalid`, no response signature, keys unavailable — a restored database needs the key ring of its time. With the backed-up `wp-config.php` (identical by `diff`): 96/96 keys identical, all 11 installations as at baseline, pinned clients and probes `ok`, `rotate-installation-secrets` dry run `needing rotation: 0`, `Response signing key: already current`, signing key id `b59ba29ed5aee05f`.

No PHP notice from the plugin in `wp-content/debug.log` during the rehearsal.

Code changes from this rehearsal:

- `wp wplc rotate-installation-secrets` also re-encrypts the stored response signing key and a prepared next key, reports their state, and warns when one cannot be decrypted; `ResponseSigner` re-encrypts the stored key lazily on use (`ResponseSigner::reencrypted()`, `reencrypt_option()`).
- `wp wplc signing-key --prepare` (next key with `next_public_key`/`next_key_id`), `--rotate` promotes it; `--rotate` refuses to replace an undecryptable key.
- Customer account shows a license key only when it matches the stored hash (`LicenseKey::verified_for_license()`).
- `recover-license-key-ids` rejects a `--to` key ID that is not configured; issuance logs a missing current key ID.

### T-95: release workflow run locally and ZIP installed on an empty WordPress

| Item | Value |
|---|---|
| Date | 2026-11-14 |
| Source | Clean export of the branch head (`git archive`), fake tag `v0.1.0` = plugin version 0.1.0 |
| Build environment | PHP 8.3 CLI, Composer 2.8.12, zip/unzip (no GitHub runner: the `run:` steps of the workflow were extracted and executed in order; `actions/*` and `softprops/action-gh-release` steps not executed) |
| Target | Fresh WordPress 7.1.4 (`wp core install`, new database), WooCommerce 11.2.2 activated, served through `php -S` with `tests/e2e/router.php` |
| Result | **Pass** for build, contents, activation, schema, admin pages and uninstall. Not covered: a real tag push on GitHub (release creation and upload), PHP 7.4 syntax check and unit tests of the release job (run on GitHub only; PHP 7.4 is not installed here). |

1. **Original workflow, steps as written:** version check passed (`Tag: 0.1.0 / plugin header: 0.1.0 / WPLC_VERSION: 0.1.0`), `composer install --no-dev --optimize-autoloader` worked but, without a committed `composer.lock`, first resolved all 43 require-dev packages against Packagist to install nothing; ZIP with 143 entries, contents check and `sha256sum -c` passed.
2. **Review findings, fixed:** no tests before a release is published; `contents: write` for the whole workflow; `readme.txt` Stable tag not compared with the tag; the dependency step depended on Packagist and an unpinned dev graph; pre-release tags were published as full releases; the contents check did not verify compiled translations, the class map, or root development files; no build on pull requests. The build moved to `bin/build-zip.sh` (shared by `release.yml` and the new `package.yml` pull-request workflow): checks tag = header = `WPLC_VERSION` = Stable tag, stages the allow-list, generates the optimized autoloader inside the stage (no network while `composer.json` has no runtime packages; the checkout's development `vendor/` is never touched), runs `php -l`, zips, writes the `.sha256`, verifies contents. `release.yml`: `test` job (PHP 7.4 unit tests) before `build`; `contents: write` only for `build`; `prerelease` for tags with `-`.
3. **New workflow, steps as written** (`release.yml` job `build`, tag `v0.1.0`): `Version: 0.1.0 / plugin header: 0.1.0 / WPLC_VERSION: 0.1.0 / readme.txt Stable tag: 0.1.0`, `Generated optimized autoload files containing 73 classes`, `72 classes of src/ in the class map`, `wp-license-captain-0.1.0.zip: OK`, 140 entries; outputs `version=0.1.0`, `prerelease=false`. Tag `0.1.1` against version 0.1.0 fails with the mismatch error (exit 1). `package.yml` job `zip`: same build, pass.
4. **ZIP contents:** single top-level `wp-license/`; `wp-license.php`, `uninstall.php`, `readme.txt`, `src/` (72 classes), `vendor/autoload.php` + `vendor/composer/*` (class map only, no packages), `assets/`, `templates/`, `languages/` (`de_DE`, `de_DE_formal` `.mo` and `.po`, `.pot`); no `tests/`, `docs/`, `tools/`, `bin/`, dotfiles, `composer.json`, PHPUnit/PHPStan/PHPCS files. SHA-256 of the installed ZIP: `3008c897b22a5f69e54c726c111afd879176bf0d0c308e28928328133322d1cc` (local build, for reference only).
5. **Installation on an empty site:** `wp plugin install wp-license-captain-0.1.0.zip --activate` → `Plugin 'wp-license' activated.` `wp-content/debug.log` (`WP_DEBUG_LOG`): no entry from the plugin (only WordPress.org connection warnings of the offline sandbox). Schema: 14 `wp_wplc_*` tables, `wplc_schema_version` = 14. REST namespace `wplc/v1` lists all 8 routes.
6. **Admin pages** (logged-in admin over HTTP, `/wp-admin/admin.php?page=…`): `wplc-licenses`, `wplc-releases`, `wplc-renewal-review`, `wplc-settings`, `wplc-catalog-csv`, `wplc-diagnostics`, `plugins.php` and the product editor (`post-new.php?post_type=product`, license fields present) all HTTP 200 without PHP errors in the page or in `debug.log` from the plugin. The non-dismissible "secrets are not configured" notice is shown, as intended on a site without key constants. (WooCommerce's own settings screen fails in this sandbox because its WooCommerce copy is a source build without compiled admin assets, see tests/e2e/README.md; unrelated to the plugin.)
7. **Deactivate and uninstall:** `wp plugin deactivate wp-license`, `wp plugin uninstall wp-license` → `Uninstalled and deleted 'wp-license' plugin.`; plugin directory removed, no `wplc_*` cron events left, no notice in `debug.log`; the 14 tables and the options `wplc_schema_version`, `wplc_rewrite_version`, `wplc_response_signing_key` are kept by design (business records, see `uninstall.php` and docs/MULTISITE.md).

### R-25 (interim): the three rehearsals repeated on version 0.2.0, schema 23

Run on 16 Nov 2026 on the working branch, commit `cea8836` (not a release commit, no tag). These runs show that the procedures still work on the current schema. They do not replace the repetition with the release commit and the real tag push that the release gates ask for (docs/RELEASE-READINESS.md, R-25).

#### T-95 repeated: ZIP built from a clean export and installed on an empty WordPress

| Item | Value |
|---|---|
| Source | `git archive` of commit `cea8836`, plugin version 0.2.0 |
| Build | `bin/build-zip.sh 0.2.0` (the `run:` step of `release.yml` job `build`), PHP 8.3 CLI, Composer 2 |
| Target | Fresh WordPress 7.1.4 (`wp core install`, new database), WooCommerce 11.2.2 activated, served by `php -S` over plain HTTP |
| Result | **Pass** for build, contents, activation, schema, admin pages and uninstall. Not covered, as on 14 Nov: a tag push on GitHub (release creation and upload) and the PHP 7.4 steps of the release job. |

1. **Build:** `Version: 0.2.0 / plugin header: 0.2.0 / WPLC_VERSION: 0.2.0 / readme.txt Stable tag: 0.2.0`, `Generated optimized autoload files containing 128 classes`, `127 classes of src/ in the class map`, `wp-license-captain-0.2.0.zip: OK`, 213 entries. `bin/build-zip.sh 0.2.1` against version 0.2.0 fails with the mismatch error.
2. **Contents:** one top-level `wp-license/` with `wp-license.php`, `uninstall.php`, `readme.txt`, `src/`, `mu-plugins/`, `assets/`, `templates/`, `languages/` (`.pot`, and `.po` + `.mo` for `de_DE` and `de_DE_formal`) and `vendor/`; no `tests/`, `docs/`, `bin/`, dotfiles, `composer.json` or tool configuration. SHA-256 of this local build, for reference only: `cdd7e42e44d7bc0c2f2183062bd69e337b1571041d143ecd565bc0d7757546c0`.
3. **Installation:** `wp plugin install wp-license-captain-0.2.0.zip --activate` → `Plugin 'wp-license' activated.` No entry from the plugin in `wp-content/debug.log`. Schema: 15 `wp_wplc_*` tables, `wplc_schema_version` = 23. REST namespace `wplc/v1` with 7 routes (activations, activation by ID, entitlement check, update check, update download, package download, Stripe webhook).
4. **Admin pages** as logged-in administrator over HTTP: `wplc-licenses`, `wplc-releases`, `wplc-renewal-review`, `wplc-settings`, `wplc-catalog-csv`, `wplc-diagnostics`, `wplc-audit-log`, `plugins.php` and the product editor (license fields present) answer HTTP 200 without a PHP error in the page; `debug.log` has no entry that names a file of the plugin. WooCommerce's own settings screen fails in this sandbox for the reason noted on 14 Nov (source build of WooCommerce without compiled admin assets).
5. **Deactivate and uninstall:** `Deactivated 1 of 1 plugins.`, `Uninstalled 1 of 1 plugins.`; plugin directory removed, no `wplc_*` cron event left, no notice. Kept by design: the 15 tables and the options `wplc_schema_version`, `wplc_reminder_log_version`, `wplc_rewrite_version`, `wplc_site_fingerprint`, `wplc_sites_endpoint_rules`, `wplc_response_signing_key`, `wplc_response_signing_key_id` (the list in `uninstall.php`).

#### T-61 repeated: a real multisite network

| Item | Value |
|---|---|
| Environment | WordPress 7.1.4 network (`wp core multisite-install`, subdirectories, new database), WooCommerce 11.2.2 network-activated, three sites; the plugin from the ZIP of the step above, `--activate-network` |
| Result | **Pass.** |

1. **Tables per site:** after the network activation site 1 has 15 `wp_wplc_*` tables, sites 2 and 3 none. The first WP-CLI request on site 2 and on site 3 creates their 15 tables (`wp wplc schema status`: `Schema installed: 23; expected: 23`, `Every table, column and index exists.`).
2. **Nothing is shared:** a license issued on site 1 (`wp wplc license show 1` there) is unknown on site 2 (`Error: License not found.`); license rows per site 1 / 0 / 0. Each site has its own recurring events.
3. **Deleting a site:** `wp site delete 3` removes every table of that site, including its 15 `wp_3_wplc_*` tables; site 2 keeps its tables.
4. **Network deactivation:** the recurring events are gone and `wplc_deactivated_at` is set on both remaining sites.
5. **Uninstall:** the working state is removed on both sites (`wplc_deactivated_at`, `wplc_last_run` gone); tables, the license of site 1 and `wplc_schema_version` stay, as documented in docs/MULTISITE.md.

The only database errors in `debug.log` are WooCommerce's own on a new site before its tables exist; no entry names a file of the plugin.

#### T-86 repeated: key rotation and recovery on a database copy

| Item | Value |
|---|---|
| Environment | WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3 CLI, MariaDB 10.11; copy of the database of the e2e shop after a full e2e run (99 licenses, 20 activations, 271 audit rows), schema 23, plugin 0.2.0 |
| Checked with | WP-CLI and a read-only probe on the server side: every license key derived and compared with its stored verifier, every installation secret decrypted, state of the response signing key, checksums of `licenses(id, license_key_secret_id, license_key_hash)` and `activations(id, installation_secret_encrypted)` |
| Result | **Pass**, no defect found. Not repeated from 14 Nov: clients over HTTP with pinned public keys during the rotation (the client path itself ran in the e2e flows 04 and 12 on the same code, without rotated keys), and the mistakes of steps 8 and 9 (secret removed, key ID changed by SQL). |

1. **Baseline and backup:** 99 of 99 keys shown, 20 of 20 installation secrets decrypted, signing key `ok` (key ID `4e6e437a9716e903`); `mysqldump --single-transaction` and a copy of `wp-config.php`.
2. **`2026-11` added to both rings and made current:** the 99 keys are unchanged (same digest), the 20 secrets decrypt and count as "on an old key".
3. **`wp wplc rotate-installation-secrets`:** dry run `Inspected: 20; needing rotation: 20`, exit code 1; `--apply --batch-size=5`: `rotated: 20`, 20 audit rows `installation_secret_rotated`; dry run again: `needing rotation: 0`, `Response signing key: current`, exit code 0. `recover-license-key-ids --to=2026-11`: `already verified: 99; unmatched: 0`.
4. **New license** (manual issue): stored with key ID `2026-11`, key shown.
5. **Old encryption key removed from the ring:** 20 of 20 secrets still decrypt, the signing key stays `ok` with the same key ID (the defect of 14 Nov does not come back), dry run exit code 0.
6. **Signing key:** `wp wplc signing-key --prepare` prints `next_key_id: ac274ff6a4b5177a`, a second call the same; `--rotate --yes` switches to it (audit `response_signing_key_rotated`).
7. **Mistake, old license secret filed under a wrong ID** (`e2e-2026-old`): 1 of 100 keys shown. `recover-license-key-ids --to=2026-11`: `unmatched: 99`, warning, nothing changed. `--to=e2e-2026-old`: `recoverable: 99`; `--apply --max-rows=10` (warning: 90 rows not inspected, continue with `--after-id=10`), then `--apply --after-id=10`: 89 more; dry run: `already verified: 100`, exit code 0; 100 of 100 keys shown.
8. **Mistake, typo in the current key ID** (`e2e-2027`): a manual issue returns 0 and the WooCommerce log (`source wp-license`) says `License not issued: the current license key ID "e2e-2027" (WPLC_LICENSE_KEY_ID) has no secret in WPLC_LICENSE_KEY_SECRETS.`; `recover-license-key-ids --to=e2e-2027` refuses the unconfigured ID.
9. **Restore from the dump.** With the key ring of the end of the rehearsal: 0 of 99 keys shown, 0 of 20 secrets decrypt, signing key `undecryptable`; `rotate-installation-secrets` warns, exit code 1; `signing-key` refuses with the restore hint. With the backed-up `wp-config.php`: counts and both checksums as at baseline, 99 of 99 keys with the baseline digest, 20 of 20 secrets, signing key `ok` with key ID `4e6e437a9716e903`, dry run exit code 0. A restored database needs the key ring of its time; the runbook in docs/SECURITY-OPERATIONS.md says so.

No entry in `wp-content/debug.log` names a file of the plugin.

### R-25 (interim 2): T-95 repeated on schema 25

Run on 16 Nov 2026 on the working branch, commit `1774567` (wave 4: project products, client 2.1.1, client package; not a release commit, no tag). Same procedure as the T-95 repetition above. It does not replace the repetition with the release commit and the real tag push (R-25).

| Item | Value |
|---|---|
| Source | `git archive` of commit `1774567`, plugin version 0.2.0 |
| Build | `bin/build-zip.sh 0.2.0`, PHP 8.3 CLI, Composer 2 |
| Target | Fresh WordPress 7.1.4 (`wp core install`, new database), WooCommerce 11.2.2 activated, served by `php -S` with `tests/e2e/router.php` over plain HTTP |
| Result | **Pass** for build, contents, activation, schema, admin pages and uninstall. Not covered, as before: a tag push on GitHub (release creation and upload) and the PHP 7.4 steps of the release job. |

1. **Build:** `Version: 0.2.0 / plugin header: 0.2.0 / WPLC_VERSION: 0.2.0 / readme.txt Stable tag: 0.2.0`, `Generated optimized autoload files containing 139 classes`, `138 classes of src/ in the class map`, `wp-license-captain-0.2.0.zip: OK`, 236 entries. New since the last run: the same build also writes `license-client-2.1.1.zip` with its `.sha256` (PROD-08; 11 files: `src/Client` with six classes and `load.php`, `bin/export-client.php`, `bin/client-contract-test.php`, `README.md`, `CHANGELOG-CLIENT.md`), checksum `OK`. `bin/build-zip.sh 0.2.1` against version 0.2.0 fails with exit code 1.
2. **Contents:** one top-level `wp-license/` with `wp-license.php`, `uninstall.php`, `readme.txt`, `src/`, `catalogs/`, `mu-plugins/`, `assets/`, `templates/`, `languages/` and `vendor/`; no `tests/`, `docs/`, `bin/`, dotfiles, `composer.json` or tool configuration. SHA-256 of this local build, for reference only: `dc44c938e7a396556a9cdccf019e426ed8b2635f7479a7930e55787901574a1b`.
3. **Installation:** `wp plugin install wp-license-captain-0.2.0.zip --activate` → `Plugin 'wp-license' activated.` Schema: 15 `wp_wplc_*` tables (corrected; the first count of 16 was wrong), `wplc_schema_version` = 25, `wp wplc schema status`: `Schema installed: 25; expected: 25`, `Every table, column and index exists.` REST namespace `wplc/v1` with 7 routes. No entry in `wp-content/debug.log` names a file of the plugin.
4. **Admin pages** as logged-in administrator over HTTP: `wplc-licenses`, `wplc-releases`, `wplc-renewal-review`, `wplc-settings`, `wplc-catalog-csv`, `wplc-catalog-files`, `wplc-diagnostics`, `wplc-audit-log`, `plugins.php` and the product editor answer HTTP 200 without a PHP error in the page. (The very first admin request answered with WooCommerce's one-time redirect after activation; the same page then answered 200.)
5. **Deactivate and uninstall:** `Deactivated 1 of 1 plugins.`, `Uninstalled 1 of 1 plugins.`; plugin directory removed, no `wplc_*` cron event left, no entry in `debug.log`. Kept by design (the list in `uninstall.php`): the 15 tables and the options `wplc_entitlement_catalog`, `wplc_reminder_log_version`, `wplc_response_signing_key`, `wplc_response_signing_key_id`, `wplc_rewrite_version`, `wplc_schema_version`, `wplc_site_fingerprint`, `wplc_sites_endpoint_rules`.

### R-25 (interim 3): T-61 and T-86 repeated on schema 25

Run on 16 Nov 2026 on the working branch, commit `7494bfe` (wave 4 and 5, plugin version 0.2.0, schema 25; not a release commit, no tag). Same procedures as the schema-23 repetitions above. They do not replace the repetition with the release commit and the real tag push (R-25).

Table count: schema 25 has **15** plugin tables (`Schema::table_statements()` in src/Infrastructure/Database/Schema.php holds 15 `CREATE TABLE` statements, the same 15 as schema 23; schemas 24 and 25 add catalog data and columns, no table). Both runs below counted 15 per site. The "16 tables" in the record "R-25 (interim 2)" above and in docs/RELEASE-READINESS.md (T-95 item) was a counting error, not a different state; both places are corrected.

#### T-61 repeated on schema 25

| Item | Value |
|---|---|
| Environment | WordPress 7.1.4 network (`wp core multisite-install`, subdirectories, new database `wplc_r25_ms`), WooCommerce 11.2.2 network-activated, three sites; PHP 8.3 CLI, MariaDB 10.11 |
| Plugin | ZIP from `git archive` of `7494bfe` built with `bin/build-zip.sh 0.2.0` (252 entries, checksum `OK`; SHA-256 of this local build, for reference only: `5f2a3b2f29150da134bbb5f91bf5dc57ff8d9d18fc2a77d29fd5fcb2f19ffd52`), installed with `wp plugin install … --activate-network` |
| Result | **Pass.** No step failed; same behaviour as on schema 23. |

1. **Tables per site:** after the network activation site 1 has 15 `wp_wplc_*` tables, sites 2 and 3 none. The first WP-CLI request on site 2 and on site 3 creates their 15 tables; `wp wplc schema status` on each of the three sites: `Schema installed: 25; expected: 25`, `Every table, column and index exists.`; `wplc_schema_version` = 25 on each site.
2. **Nothing is shared:** a license issued on site 1 with `wp wplc license issue --skip-email` (product mapped to a plan on site 1) is shown there by `wp wplc license show 1`; on sites 2 and 3 the same command answers `Error: License not found.`; license rows per site 1 / 0 / 0. Each site has its own recurring events (`wplc_cleanup_security_rows`, `wplc_daily_lifecycle` on each of the three).
3. **Deleting a site:** `wp site delete 3` removes all 65 tables of that site, including its 15 `wp_3_wplc_*` tables; sites 1 and 2 keep their 15 each.
4. **Network deactivation:** `Network deactivated 1 of 1 plugins.`; no `wplc_*` event left on either remaining site, `wplc_deactivated_at` set on both.
5. **Uninstall:** `Uninstalled 1 of 1 plugins.`, plugin directory removed. On both sites the working state is gone (`wplc_deactivated_at`, `wplc_schema_verified`); kept as documented in docs/MULTISITE.md: the 15 tables per site, the license of site 1 and the options `wplc_entitlement_catalog`, `wplc_reminder_log_version`, `wplc_rewrite_version`, `wplc_schema_version`, `wplc_site_fingerprint`, `wplc_sites_endpoint_rules`. No network option (`wp_sitemeta`) of the plugin.

The only database errors in `debug.log` are WooCommerce's own on sites 2 and 3 before its tables exist (`wc_webhooks`, `woocommerce_attribute_taxonomies`); no entry names a file of the plugin.

#### T-86 repeated on schema 25

| Item | Value |
|---|---|
| Environment | WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3 CLI, MariaDB 10.11; `mysqldump --single-transaction` of the e2e shop's database after a full e2e run, loaded into `wplc_r25_keys` (99 licenses, 20 activations, 292 audit rows), copied WordPress with the e2e key constants (key ID `e2e-2026` in both rings), plugin from the ZIP of the T-61 run |
| Schema | The copy was already at schema 25 (the e2e site runs the same code), so loading the plugin ran no migration: `wp wplc schema status` → `Schema installed: 25; expected: 25`, `Every table, column and index exists.` The upgrade 23 → 25 of a populated database is therefore not shown by this run. |
| Checked with | WP-CLI and the same kind of read-only probe on the server side as on schema 23: every license key derived and compared with its stored verifier (count shown, digest over the shown keys), every installation secret decrypted, state and key ID of the stored response signing key, checksums of `licenses(id, license_key_secret_id, license_key_hash)` and `activations(id, installation_secret_encrypted)` |
| Result | **Pass**, no step failed, no defect found. Not repeated, as on schema 23: clients over HTTP with pinned public keys during the rotation, and the mistakes of steps 8 and 9 of 14 Nov (secret removed, key ID changed by SQL). |

1. **Baseline and backup:** 99 of 99 keys shown (digest `7794a487…`), 20 of 20 installation secrets decrypt, all on `e2e-2026`; signing key `ok`, key ID `93d7c3561fe0fe56`, stored encrypted with `e2e-2026`. `rotate-installation-secrets` dry run: `Inspected: 20; needing rotation: 0`, `Response signing key: current`, exit code 0. `mysqldump --single-transaction` of the copy and a copy of `wp-config.php` and the key file.
2. **`2026-11` added to both rings and made current:** the 99 keys are unchanged (same digest), the 20 secrets decrypt and count as "on an old key"; checksums unchanged.
3. **`wp wplc rotate-installation-secrets`:** dry run `Inspected: 20; needing rotation: 20`, `Response signing key: pending`, `Rows still using an old key: 21` (20 rows plus the signing key), exit code 1; `--apply --batch-size=5`: `rotated: 20`, `Response signing key: rotated`, exit code 0, 20 audit rows `installation_secret_rotated`; dry run again: `needing rotation: 0`, `Response signing key: current`, exit code 0. `recover-license-key-ids --to=2026-11`: `already verified: 99; recoverable: 0; unmatched: 0`, exit code 0.
4. **New license** (`wp wplc license issue --skip-email`): license 106 stored with key ID `2026-11`, key shown (100 of 100).
5. **Old encryption key removed from the ring:** 20 of 20 secrets still decrypt, the signing key stays `ok` with key ID `93d7c3561fe0fe56`, dry run `needing rotation: 0`, exit code 0.
6. **Signing key:** `wp wplc signing-key --prepare` prints `next_key_id: c59c0e48c06f3deb`, a second call the same; `--rotate --yes` switches to it (`key_id: c59c0e48c06f3deb`, audit `response_signing_key_rotated` from `93d7c3561fe0fe56` to `c59c0e48c06f3deb`).
7. **Mistake, old license secret filed under a wrong ID** (`e2e-2026-old`): 1 of 100 keys shown, installation secrets unaffected. `recover-license-key-ids --to=2026-11`: `unmatched: 99`, warning `Unmatched rows were not modified`, exit code 1, nothing changed. `--to=e2e-2026-old`: `recoverable: 99`, exit code 1 (dry run); `--apply --max-rows=10`: 10 recovered, warning `90 rows after ID 11 were not inspected (--max-rows). Continue with --after-id=11.`; `--apply --after-id=11`: 89 more, exit code 0; dry run: `already verified: 100`, exit code 0; 99 audit rows `license_key_id_recovered`; 100 of 100 keys shown, the 99 old ones with the baseline digest. (On schema 23 the resume point was ID 10; the license IDs of this copy start at 2.)
8. **Mistake, typo in the current key ID** (`e2e-2027`): `wp wplc license issue` ends with `Error: The license could not be issued.`, exit code 1, no license row added; the WooCommerce log (`source wp-license`) says `License not issued: the current license key ID "e2e-2027" (WPLC_LICENSE_KEY_ID) has no secret in WPLC_LICENSE_KEY_SECRETS.`; `recover-license-key-ids --to=e2e-2027` refuses the unconfigured ID (exit code 1). Difference to schema 23: the issue now runs through the `wp wplc license issue` command, which fails visibly; the reason itself is only in the WooCommerce log, not in the command's error.
9. **Restore from the dump.** Counts 99/20/292 and both checksums as at baseline. With the key ring of the end of the rehearsal: 0 of 99 keys shown, 0 of 20 secrets decrypt, signing key `undecryptable`; `rotate-installation-secrets` warns per row (`failed: 20`, `Response signing key: failed`, warning to put the previous encryption key back), exit code 1; `signing-key` refuses with the restore hint. With the backed-up configuration (`wp-config.php` identical by `diff`, key file identical): 99 of 99 keys with the baseline digest, 20 of 20 secrets, signing key `ok` with key ID `93d7c3561fe0fe56`, dry run exit code 0, schema status 25/25.

No entry in `wp-content/debug.log` names a file of the plugin (only WordPress.org connection warnings of the offline sandbox).

### Release workflow rehearsed locally with the client package and the manual (16 Nov 2026)

Commit `57253e7` (working branch, not a release commit). The `run:` steps of `.github/workflows/release.yml` job `build` were executed in order on a `git archive` export, with `GITHUB_REF_NAME` and `GITHUB_OUTPUT` set as the runner sets them; the `actions/*` and `softprops/action-gh-release` steps were not executed, their path lists were resolved by the shell against the built files instead.

| Item | Value |
|---|---|
| Result | **Pass.** Not covered: a real tag push (release creation and upload on GitHub) and PHP 7.4 itself (not installed here; the new scripts and tests pass the PHPCompatibilityWP scan for 7.4-). |

1. **Tag `v0.2.0`:** outputs `version=0.2.0`, `prerelease=false`. `bin/build-zip.sh 0.2.0` builds `wp-license-captain-0.2.0.zip` (252 entries, contents check passed) and `license-client-2.1.1.zip` (11 files), each with a `.sha256`; `sha256sum -c` passes for both.
2. **Path lists of the upload and release steps:** `dist/wp-license-captain-0.2.0.zip`, `dist/wp-license-captain-0.2.0.zip.sha256`, `dist/license-client-*.zip` and `dist/license-client-*.zip.sha256` each match exactly one file, so `if-no-files-found: error` and `fail_on_unmatched_files: true` do not trip.
3. **Manual in the ZIP:** `wp-license/docs/` holds only `manual/` (13 files) and `catalog-file.schema.json`.
4. **Tag `v0.2.0-rc.1` on version 0.2.0:** outputs `prerelease=true`; the build stops with the version mismatch error (exit code 1), as intended: a pre-release tag needs the matching version in the plugin header, `WPLC_VERSION` and `readme.txt`.

### R-25 (interim 4): schema upgrade 23 → 25 on a populated database

Run on 16 Nov 2026 on the working branch. The earlier R-25 records installed on empty databases or on copies already at schema 25; this run fills a shop with the last schema-23 code and then installs the current code over it. Not a release commit, no tag.

| Item | Value |
|---|---|
| Old version | `git archive` of commit `78fd47b` (the last commit with `Schema::VERSION = '23'`, parent of `64be1e7` which introduced schema 24), plugin version 0.2.0, `bin/build-zip.sh 0.2.0`: 213 entries, checksum `OK` (SHA-256 of this local build, for reference only: `bc9d94abe6b0f272aa5d7a098c35331005a0f81d334249e94ba3fbafd4c328de`) |
| New version | `git archive` of commit `debce4d` (plugin version 0.2.0, schema 25), `bin/build-zip.sh 0.2.0`: 252 entries, checksum `OK` (SHA-256 for reference only: `ec80d30c29fd825e9d09585966767600991fa7e0a2f4992c032e2e01d48505b4`) |
| Target | Fresh WordPress 7.1.4, WooCommerce 11.2.2, new database `wplc_upg`, PHP 8.3 CLI, MariaDB 10.11; test key constants of the e2e site (key ID `e2e-2026` in both rings); `WP_DEBUG` and `WP_DEBUG_LOG` on; site URL `https://shop.test` served by `php -S` with `tests/e2e/router.php`, a two-filter mu-plugin routes the client's requests to it (as the e2e harness does) |
| Checked with | WP-CLI, `wp eval-file` scripts using the plugin's own classes, the bundled `LicenseClient` over real HTTP with the pinned response signing key, logged-in admin requests over HTTP; checksums are SHA-256 over every row of a table, restricted to the schema-23 columns, ordered by the first column |
| Result | **Pass.** No step failed, no defect found. Not covered: a package download after the upgrade (the update check with version and checksum was covered), automatic renewals through a payment gateway, a multisite network, and an upgrade triggered by a front-end or REST request (by design those never migrate). |

1. **Schema-23 install:** `wp plugin install … --activate` of the old ZIP → `wplc_schema_version` = 23, `wp wplc schema status`: `Schema installed: 23; expected: 23`, `Every table, column and index exists.`
2. **Data written with the old code:** three license products through `Catalog::save_mapping` (`voucher-captain-pro` plan `pro` yearly, 1 site; `pass-captain-pro` plan `monthly`, 2 sites; `affiliate-captain-pro` as variable product with plans `business` (3 sites) and `agency` (25 sites)), each with entitlement keys and one custom JSON value; two WooCommerce orders (bank transfer, `payment_complete`, then completed) for two customers → 5 licenses; one license issued by hand (`LicenseIssuer::issue_manual`, with reason); 7 activations through `LicenseClient::activate()` from 7 simulated sites (one of them a staging site linked to its production site); release `voucher-captain-pro` 1.1.0 with a package ZIP, created and published with `wp wplc release create --publish`; one manual renewal (license 4 moved to 5 days before its end by SQL — time simulation — then `ManualRenewal::start()`, renewal order 17 pending). Every client's `check_entitlements()` answered with a verified snapshot, the update check offered 1.1.0 with the registry checksum.
3. **Baseline (schema 23):** products 3, plans 4, licenses 6, entitlements 22, activations 7, releases 1, renewals 1, audit rows 17 (`catalog_plan_created` 4, `site_activated` 7, `license_issued_manually` 1, `manual_renewal_started` 1, `release_created` 1, `release_product_slug_changed` 1, `release_published` 1, `response_signing_key_created` 1). Checksums (first 12 hex digits): products `21df9ef30b5b`, plans `3d32eaea4ab6`, licenses `a1a46368803d`, entitlements `afceac00b41e`, activations `92eac7d91c26`, releases `92b27f356957`, renewals `ae9a3075fe27`, audit log `d5c37a869beb`. License keys: 6 of 6 derive and match their stored verifier (digest over the keys `a348db177b5d…`); installation secrets: 7 of 7 decrypt. Option `wplc_entitlement_catalog` absent. `mysqldump --single-transaction` of the database kept.
4. **Upgrade:** `wp plugin install <new ZIP> --force` → `Plugin updated successfully.`, plugin stays active. That command still ran in the old code, so `wplc_schema_version` was 23 right after it; the next WP-CLI command (`wp plugin list`) ran the migration: `wplc_schema_version` = 25, no `wplc_schema_last_error` / `wplc_schema_error`, `wp wplc schema status`: `Schema installed: 25; expected: 25`, `Every table, column and index exists.`
5. **Structure:** `mysqldump --no-data` of the 15 plugin tables before and after differs only in the five new `licenses` columns of step 25 (`price_change_amount_minor`, `price_change_effective_at`, `price_change_announced_at`, `price_change_notified_at`, `price_change_notice_days`, all nullable); all five are `NULL` on the 6 existing licenses.
6. **Existing data unchanged:** all row counts and all eight checksums over the schema-23 columns equal the baseline; 6 of 6 license keys verify with the same digest; 7 of 7 installation secrets decrypt. Among the `wplc_*` options only `wplc_schema_version` and `wplc_schema_verified` changed and `wplc_entitlement_catalog` was added; the response signing key and its key ID are unchanged.
7. **Catalog files (step 24):** `wp wplc catalog list` shows the 7 shipped files imported with source `bundled` (`affiliate-captain-pro` available, 9 keys; `pass-captain-pro` available, 5; `voucher-captain-pro` available, 4; `workflow-captain-pro` planned, 6; `course-`, `form-`, `review-captain-pro` planned, 0). The plan and license checksums of step 6 show that the import changed no plan and no license. The shipped key lists contain every key of the former hard-coded lists of schema 23 (voucher gains three keys). The product editor of the voucher product still shows product key `voucher-captain-pro`, plan key `pro`, the saved key `voucher.white_label` and the custom value `voucher.monthly_limit`.
8. **Existing activations:** `check_entitlements()` from the 7 sites activated on schema 23 answers `active` with a verified snapshot; the hash of every site's entitlements equals the one before the upgrade (4 distinct values for 4 plans). The update check of site 1 still offers 1.1.0 with the same checksum.
9. **Work after the upgrade:** paying the renewal order started on schema 23 moves license 4's `paid_until` by one month (renewal 1 `paid`, order `processing`, audit `manual_renewal_paid`, `renewal_succeeded`); `wp wplc license issue --plan-id=1 … --skip-email --yes` issues license 7 on key ID `e2e-2026` (`price_change_*` `NULL`); a new site activates it through the client (8 activations). `wp wplc license show 3` lists its three activations including the staging link.
10. **Admin pages** as logged-in administrator over HTTP: `wplc-licenses` (list and one license), `wplc-releases`, `wplc-renewal-review`, `wplc-settings`, `wplc-catalog-csv`, `wplc-catalog-files`, `wplc-diagnostics`, `wplc-audit-log`, `plugins.php` and the product editor of the simple and the variable product answer HTTP 200 without a PHP error in the page. The diagnostics page shows billing as paused because the site address changed: this run moved `home` to `https://shop.test` after the first activation of the plugin (step 1), so the site guard did what it should; it is not caused by the upgrade.
11. **debug.log:** from the upgrade to the end no entry names a file of the plugin; the only entry naming a plugin table is a database error of this rehearsal's own script (a wrong column name in its read-only query). The other entries are WooCommerce's own (`Could not find asset registry`, unregistered script dependencies) and WordPress.org connection warnings of the offline sandbox.

## T-12: Stripe test mode, run without a browser (25 Nov 2026)

| Item | Value |
|---|---|
| Date | 2026-11-25 |
| Environment | e2e shop of `tests/e2e/` (WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3 CLI and `php -S` with `tests/e2e/router.php`, MariaDB), plugin from branch `claude/zen-babbage-7q4nac` (base `5a0aea4`) plus the fixes below |
| Stripe gateway | WooCommerce Stripe Gateway **11.2.0**, then **9.6.0**, from the Git tags of `woocommerce/woocommerce-gateway-stripe` (wordpress.org is blocked here; `composer dump-autoload`, no JavaScript build). Test mode, saved cards on, the secret key a placeholder that the session's proxy replaced with the account's test key. No publishable key. |
| Stripe account | Test mode; every answer and event `livemode: false` (checked on every response). Account default API version `2026-11-18.endive`; the gateway sends its own (`2024-06-20` on 9.6.0, `2026-03-25.dahlia` on 11.2.0). |
| Purchases | Real classic checkout over HTTP as the logged-in customer (`?add-to-cart`, checkout page nonce, `POST ?wc-ajax=checkout` with `payment_method=stripe`, `wc-stripe-payment-method`, `wc-stripe-is-deferred-intent=1`, the renewal consent box), with PaymentMethods created server-side from Stripe test tokens (`tok_visa`). The checkout page was switched to the `[woocommerce_checkout]` shortcode for the run (the block checkout needs a browser). |
| Card changes | Test cards attached to the customer through the API (`pm_card_*`), the gateway's token list synchronised, the license pointed at the new token: the "replace the card in the dashboard" of step 6. |
| Renewals | Made due with `e2e_move_paid_until()` [time simulated], run with `RenewalProcessor::process()`; 12-hour windows simulated by back-dating `last_attempt_at` and `_wplc_result_unknown_since`. |
| Webhooks | Stripe cannot reach the sandbox. The Stripe CLI was built from source (`stripe listen`) but its websocket host `stripecli-ws-nw.stripe.com` is refused by the egress proxy (HTTP 403). Instead the real events were fetched from `GET /v1/events` and posted to `/wp-json/wplc/v1/webhooks/stripe` and the gateway's `/?wc-api=wc_stripe`, signed with local test secrets (`WPLC_STRIPE_WEBHOOK_SECRET_TEST`, gateway setting `test_webhook_secret`). Signature checks unchanged. The bodies are rendered with the account's API version, not an endpoint version. |
| Fault injection | Test-only mu-plugin (not shipped): drop the answer of a request after it reached Stripe (timeout), block the search API, remove `latest_charge` from answers. |
| Helper scripts | Scratch only, not in the repository. |
| Result | **Pass for everything that runs without a browser, after four fixes.** Open: browser parts, delivery by Stripe, live-mode and second-account steps. |

Steps of `docs/STRIPE-TEST-PLAN.md` (Stripe test object IDs; order and license numbers are those of the e2e database):

| Step | Finding | Result | Evidence |
|---|---|---|---|
| 1 | T-12, B-57 | Pass (11.2.0, 9.6.0) | Order 215: card saved without the gateway's box (token 8, `pm_1UfZc9C8P8zS4JQxwmcGTU9f`), license 107: `renewal_enabled=1`, `gateway_customer_ref=cus_VgxXdnwViTlctx`, `gateway_method_ref=pm_…`, `gateway_mode=test`, order meta `_wplc_recurring_consent_at`. Charge `pi_3UfZcAC8P8zS4JQx1rp0vBPl`. |
| 2 | B-60, A-20, B-147, B-149 | Pass (11.2.0, 9.6.0) | Renewal 120 → order 216, `pi_3UfZcrC8P8zS4JQx1hbz7tmI`, txn `ch_3UfZcrC8P8zS4JQx1itHhcw5`, description "WPLC E2E - Order 216", metadata `order_id`, `order_key`, `site_url`, `signature`, `payment_type=recurring`, `wplc_renewal_id`, `wplc_license_id`, `wplc_period`, `wplc_site`, `wplc_attempt`; metas `_stripe_intent_id`, `_stripe_charge_captured=yes`, `_stripe_customer_id`, `_stripe_source_id`; extended once. Event `evt_3UfZcrC8P8zS4JQx1sgtWQro` → inbox `already_reconciled`; gateway webhook adds no note. On 9.6.0: order 280 (`pi_3UfZzbC8P8zS4JQx02hk7b1K`), 282 (`pi_3UfZzgC8P8zS4JQx2VIKbzo5`). Every succeeded answer carried `latest_charge`. |
| 3 | B-149 | Pass | Customer: only the plugin's mails ("license is renewed", "Action required …"), no WooCommerce processing/completed/failed mail for renewal orders. Shop: WooCommerce's "New order" and "Order failed" admin mails as usual. |
| 4 | B-60, A-20 | Pass (11.2.0, 9.6.0) | Refund from WooCommerce: `re_3UfZcrC8P8zS4JQx1yKaJGAr` (11.2.0, order 216), `re_3UfZzbC8P8zS4JQx0hqM1jVq` (9.6.0, order 280). Refund at Stripe (API, like the dashboard) + real `charge.refunded` to the gateway: `re_3UfZdiC8P8zS4JQx12zZLxpK` (11.2.0, order 218), `re_3UfZzgC8P8zS4JQx2MmagMol` (9.6.0, order 282). Every time: order `refunded` (never `cancelled`), license back to the old `paid_until`, renewal off, audit `renewal_refunded`. |
| 5 | T-12 | Partial | 3-D Secure at purchase needs Stripe.js (not run). Off-session renewal with `4000 0027 6000 3184` (`pm_card_authenticationRequired`): Stripe `authentication_required`, renewal 131 `manual_action_required`, license `past_due`, order note, customer mail with the pay link `…/checkout/order-pay/227/…`. |
| 6 | B-62 | Pass after fix | Soft: `…0341` → `card_declined`/`generic_decline`, advice `try_again_later`, outcome `issuer_declined`, renewal `failed`, retry next day (renewal 125, `pi_3UfZeVC8P8zS4JQx2iSmjiAV`). Hard: `…0019` fraudulent and `4100…0019` Radar block → `manual_action_required`, no retry (`pi_3UfZhaC8P8zS4JQx1f3b7hy7`, `pi_3UfZhfC8P8zS4JQx0wMJzssE`). **Defect:** `_wplc_stripe_outcome_type` stayed empty because the error answer names the charge only by ID (`error.charge`, `payment_intent.latest_charge` as string); fixed, now `blocked`. `…9995`, `…9987`, `…9979` cannot be attached in test mode (Stripe declines the attach). |
| 7 | B-58 | Pass | Failed renewal order 221 paid on the pay page (form POST) with a new `…4242` without "save": token 9 saved, license moved to `pm_1UfZi0C8P8zS4JQxrHicac1T`, audit `payment_method_adopted`, renewal `paid` after `wplc_reconcile_paid_renewal_order`; next renewal charged the new card (`pi_3UfZiPC8P8zS4JQx0F8WYmkk`). |
| 8 | B-59 | Partial | Pay page with a 3-D Secure card up to the dialog (`pi_3UfZilC8P8zS4JQx0ay5HLFd`, `requires_action`), then the renewal job: no PaymentIntent created, `last_error = renewal_order_in_use`, attempt not counted, retry in 15 minutes. Completing 3-D Secure needs a browser. |
| 9 | B-59 | Pass | Second succeeded PaymentIntent with the renewal metadata `pi_3UfZjCC8P8zS4JQx1v7q3CzV` (event `evt_3UfZjCC8P8zS4JQx17t5u3zJ`): inbox `manual_review`, audit `renewal_double_payment`, order note naming both intents, license extended once. |
| 10, 11, 12, 16 | B-61, B-57, B-78, B-63, A-15, A-16 | Not run | Link, SEPA and Amazon Pay need Stripe.js; live mode and a second account are not available; step 12 is covered by step 20. |
| 13 | A-114, B-146 | Pass | Answer of the charge request dropped after it reached Stripe (`pi_3UfZmUC8P8zS4JQx10ztfH7A` succeeded): renewal 151 `reconciling`, `renewal_result_unknown`, license `active`, no customer mail, order note. Retry: the adapter's search did not return the new intent yet (26 s), the frozen key was sent again and Stripe replayed the first payment (`Idempotent-Replayed: true`); booked once, one PaymentIntent. The search showed new intents after 15 to 40 s, not reliably within a minute. |
| 14 | B-10, B-29 | Pass | Backup before the renewal, renewal paid (`pi_3UfZnUC8P8zS4JQx0rBsaKIl`), backup restored, renewal run again: found by the search, booked without a charge request, one PaymentIntent. Same real event with another site ID: 200 `"reason":"other_site"`; resent: `duplicate`. |
| 15 | B-148, T-51 | Partial | Dispute card (`pi_3UfZv4C8P8zS4JQx17LwJ1Wg`), real `charge.dispute.created` (`du_1UfZv6C8P8zS4JQxrwJuRjg8`): license `suspended`, audit `chargeback`; won (`winning_evidence`): `charge.dispute.funds_reinstated` and `charge.dispute.closed` → audit `dispute_won`, license stays suspended. Lost dispute, early fraud warning and the live-mode check not run. |
| 17 | P-03 | Pass | Card changed while reconciling: retry with the frozen key → Stripe `idempotency_error` (400), note, no new charge; later found by the search and booked (`pi_3UfZp6C8P8zS4JQx28CWO6yl`). Amount changed (fee, 54 instead of 49 €): `idempotency_error`, then the found 49 € payment → `review_required` (`pi_3UfZpSC8P8zS4JQx1TptQPiW`). Search blocked: no charge request, after 12 h `review_required` [time simulated] (`pi_3UfZrEC8P8zS4JQx2iPfsBBs`). One PaymentIntent per period. |
| 18 | P-61 | Pass | Renewal order 265 cancelled after a Stripe refusal (salt raised), new card, same attempt `:0`: new order 266 charged at once (`pi_3UfZu2C8P8zS4JQx0AHEkLlE`), key `…:0:5cc6e5eb3d7515f9` instead of `…:0:21e30cc18edc0a21`/`…:0:55b22e473d224da5`. |
| 19 | P-15 | Pass after fix | Double payment (`pi_3UfZjBC8P8zS4JQx1nG1Qggv` + `pi_3UfZjCC8P8zS4JQx1v7q3CzV`), order refunded from WooCommerce (`re_3UfZjBC8P8zS4JQx1BoXxd9t`): period kept, renewal `paid`, audit `renewal_duplicate_refunded` once, `wplc/health_alert` `renewal_needs_review`, note names the second intent. **Defect:** second payment refunded at Stripe first (`re_3UfZm2C8P8zS4JQx0CIye4FU`), then the order: period was still kept. Fixed; re-run: renewal 148 `refunded`, license back, note "was refunded as well". The other order (order first, second payment afterwards at Stripe) stays a manual "Change term" (no event reaches the plugin). |
| 20 | P-16, B-78 | Pass after fix | (a) Card detached at Stripe: Stripe answers `invalid_request_error` **without** `code`/`param`. **Defect:** treated as a shop configuration problem, no customer mail. Fixed (the adapter asks `GET /v1/payment_methods/{id}`); re-run license 130: `scheduled`, `attempt_count=0`, `renewal_payment_method_missing`, order not failed, action `true` then `false`, one note, one mail, the customer's second card not charged. (b) Stripe customer deleted: `resource_missing`, same result (license 129). Counter-check with another account's key not run (one test account). |
| 21 | P-18 | Pass after fix | Renewal 169 in review with a succeeded payment, "Book": order 259 with `ch_3UfZrEC8P8zS4JQx2MMqEeYB`, `_stripe_intent_id`, `_stripe_charge_captured`, `_stripe_customer_id`; refund from WooCommerce reached Stripe (`re_3UfZrEC8P8zS4JQx2xaqLS7k`). **Defect:** `_stripe_source_id` missing; fixed, re-run order 269. Dispute on a booked payment assigned to the license (step 15). |
| 22 | P-59 | Pass | All real succeeded answers carried `latest_charge`. Forced answer without it: one lookup with `expand[]=latest_charge`, booked with `ch_…` (`pi_3UfZxJC8P8zS4JQx1wDICr09`). Without it in every answer: `reconciling`, no mail, next check booked without a second charge (`pi_3UfZxdC8P8zS4JQx2R7eL3ih`). |
| F2 / T-13, SA-01 | | Pass | Idempotency key `{renewal key}:{attempt}:{hash}` reaches Stripe: `request.idempotency_key` of `evt_3UfZcrC8P8zS4JQx1B5g1eKN` equals the adapter's key `7ab7685d…0d96:0:fc65fe7888390e9c`. |
| B-191 | | Pass | Combined renewals on: two licenses, one order (275) with two lines, one charge of 98.00 € (`pi_3UfZy9C8P8zS4JQx2gIRgbjU`), one customer mail; one line refunded (`re_3UfZy9C8P8zS4JQx2sri4H2d`) rolls back only that license. |
| T-160 | | Pass after fix | The account default `2026-11-18.endive` refuses the adapter's `payment_method_types` (`invalid_request_error`, `req_G4NTU80MFnF3gr`). Fixed the same day: `automatic_payment_methods` and the adapter's own API version `2026-03-25.dahlia`; renewals with 11.2.0 and 9.6.0 (`pi_3UfaWZC8P8zS4JQx15JNMh95`, `pi_3UfaXAC8P8zS4JQx0rKsNeYT`) and with a gateway forced to `2026-11-18.endive` (`pi_3UfaWoC8P8zS4JQx1K4f2IrI`). Section "T-160" below. |
| B-189 | | Partial | Bought while the shop sold in USD, shop back on EUR: renewal charged 49.00 USD (`pi_3UfZyXC8P8zS4JQx2LkvHOHc`). A real multi-currency plugin was not available. |

Defects found and fixed in this run (unit and integration tests added, simulated-Stripe tests still pass): B-62 outcome read from the charge (`StripeRecurringAdapter::error_charge_id()`), P-16 detached card without error code (`payment_method_detached()`), P-15 refund after the second payment was refunded at Stripe (filter `wplc_duplicate_payment_refunded`), P-18 `_stripe_source_id` after booking from review. New task T-160: newer Stripe API versions reject `payment_method_types` (fixed the same day, section "T-160" below).

What the owner has to provide for the rest of T-12:

- A publishable test key and a person with a browser (T-130): 3-D Secure at purchase (`4000 0025 0000 3155`, `4000 0027 6000 3184`) and on the pay page (steps 5 and 8), Link, SEPA, Checkout Sessions/Adaptive Pricing (T-133), wallets (B-113), the block checkout.
- Delivery by Stripe: a publicly reachable staging shop with the two webhook endpoints, or access to `stripecli-ws-nw.stripe.com` for `stripe listen`.
- Live keys of a second account for steps 11 and 16; a multi-currency plugin for B-189; INH-05 (charge at an announced higher price) was not run.

### T-12 browser part with Stripe.js: attempted, blocked by the network (25 Nov 2026)

| Item | Value |
|---|---|
| Setup | Same e2e shop; WooCommerce Stripe Gateway **11.2.0** from the GitHub release ZIP (with its built JavaScript), test mode, the owner's publishable test key in the shop database only (not in the repository), secret key a placeholder replaced by the proxy, express checkout on for product, cart and checkout, saved cards on. Headless Chromium (Playwright) through a local TLS proxy for `https://shop.test` and the session's egress proxy for everything else. Checkout page as the Checkout block, then as `[woocommerce_checkout]`. |
| Result | **Blocked.** The egress proxy refuses `js.stripe.com` (`403 Forbidden (agent proxy: no rule or network setting allows this host)`; in the browser `net::ERR_TUNNEL_CONNECTION_FAILED` for `https://js.stripe.com/dahlia/stripe.js`), and likewise `m.stripe.network` and `hooks.stripe.com` (the 3-D Secure challenge). `window.Stripe` stays undefined, the card element stays empty. No workaround was attempted. |
| Blocked scenarios | Card purchase (4242, 3-D Secure `4000 0027 6000 3184` completed/failed, decline `4000 0000 0000 0002`) in the classic and the block checkout; completing 3-D Secure on the pay page (step 8, second half, B-59) and after an off-session `authentication_required` (step 5); Link and SEPA (steps 10, 16); Checkout Sessions (T-133); wallets (B-113). No new PaymentIntents, so no events to replay. |
| Seen without Stripe.js | Classic checkout with the card gateway: the withdrawal box (required) and the renewal box (optional) are shown. B-113: for a guest the gateway does not enqueue its express-checkout script (`wc_stripe_express_checkout`) on the product and cart page of a license product; for a logged-in customer it does. The buttons themselves cannot render here. |
| Defects | None found in the plugin. |
| Restored | Gateway and its settings removed, checkout page back to the block, probe mu-plugin removed, `wp-config.php` as before; full e2e run afterwards. |

What is needed for this part: an environment whose network allows `js.stripe.com`, `m.stripe.network` and `hooks.stripe.com` (plus `r.stripe.com`/`q.stripe.com` for Stripe.js telemetry, also refused here), or a person with a browser (T-130); a real device with a wallet for B-113.

## T-160: Stripe API version of the adapter's requests (25 Nov 2026)

Found in the T-12 run: the account's default API version `2026-11-18.endive` refuses `payment_method_types`, which the adapter sent with every renewal charge. Renewals worked only because the gateway sends its own version on every request (9.6.0: `2024-06-20`, `WC_Stripe_API::STRIPE_API_VERSION`; 11.2.0: `2026-03-25.dahlia`). Fix: the charge sends `automatic_payment_methods[enabled]=true` with `allow_redirects=never` instead, and every adapter call (charge, lookups, the review's search) carries `Stripe-Version: 2026-03-25.dahlia` (`StripeRecurringAdapter::STRIPE_API_VERSION`) through the gateway's header filter (`wc_stripe_request_headers`, before 9.7.0 `woocommerce_stripe_request_headers`), for that call only.

Probe against the Stripe API on 25 Nov 2026, same test account, every answer `livemode: false` (checked on each response). Test customer `cus_VgyHrefOsea8uB`, a fresh `pm_card_visa` (or the named test card) attached through the API for each PaymentIntent, 12.34 EUR, `off_session=true`, `confirm=true`:

| `Stripe-Version` sent | Body | Result |
|---|---|---|
| none (account default, answered `2026-11-18.endive`) | `payment_method_types[]=card` | 400 `invalid_request_error` "The `payment_method_types` parameter is no longer supported" (`req_G4NTU80MFnF3gr`) |
| none (`2026-11-18.endive`) | `automatic_payment_methods`, `allow_redirects=never` | `succeeded`: `pi_3UfaKhC8P8zS4JQx1X4bz2vQ`, `ch_3UfaKhC8P8zS4JQx1SC0ndUM` |
| `2024-06-20` | `payment_method_types[]=card` / `automatic_payment_methods` | `succeeded`: `pi_3UfaKiC8P8zS4JQx1p4UvqQE` / `pi_3UfaKkC8P8zS4JQx12oWjTP3` |
| `2026-03-25.dahlia` | `payment_method_types[]=card` / `automatic_payment_methods` | `succeeded`: `pi_3UfaKlC8P8zS4JQx0XshhzAX` / `pi_3UfaKmC8P8zS4JQx1h7wXQoy` |
| none and `2026-03-25.dahlia` | `automatic_payment_methods`, created without `confirm`, then `POST …/confirm` with `off_session=true` | `succeeded`: `pi_3UfaMSC8P8zS4JQx2bALEuvA` (`ch_3UfaMSC8P8zS4JQx2pOjmpwL`), `pi_3UfaMTC8P8zS4JQx1kShjYnc` (`ch_3UfaMTC8P8zS4JQx1azIU27x`) |
| none and `2026-03-25.dahlia` | `automatic_payment_methods`, `pm_card_chargeCustomerFail` | `card_error`, `card_declined`/`generic_decline`, charge named by ID, as before: `pi_3UfaMDC8P8zS4JQx0ZNhuLEX`, `pi_3UfaMGC8P8zS4JQx2Ca630MB` |
| none and `2026-03-25.dahlia` | `automatic_payment_methods`, `pm_card_authenticationRequired` | `card_error`, `authentication_required`, as before: `pi_3UfaMFC8P8zS4JQx2aVtURwF`, `pi_3UfaMHC8P8zS4JQx2uVMGqQH` |

With `automatic_payment_methods` Stripe lists the account's methods in `payment_method_types` of the answer (`card`, `link`, `mb_way`, `satispay`); the adapter does not read that field, and the saved card is charged.

Renewals with the fixed adapter through `RenewalProcessor::process()` on the e2e shop (after the full e2e run), real gateway, Stripe test mode; each a new customer with `pm_card_visa`, 49.00 EUR. Stripe-Version per request from the HTTP log of the site:

| Gateway | Gateway's own requests | Adapter: search, `POST /v1/payment_intents` | Result |
|---|---|---|---|
| 11.2.0 | `2026-03-25.dahlia` | `2026-03-25.dahlia` | renewal 119 `paid`, order 215, `pi_3UfaWZC8P8zS4JQx15JNMh95`, `ch_3UfaWZC8P8zS4JQx1A3t2B5x` (`cus_VgyT7vx5sZJrwl`) |
| 11.2.0, header filter forcing `2026-11-18.endive` (a later gateway) | `2026-11-18.endive` | `2026-03-25.dahlia` | renewal 122 `paid`, order 217, `pi_3UfaWoC8P8zS4JQx1K4f2IrI`, `ch_3UfaWoC8P8zS4JQx1o2e9jMw` |
| 9.6.0 (filter `woocommerce_stripe_request_headers`) | `2024-06-20` | `2026-03-25.dahlia` | renewal 125 `paid`, order 219, `pi_3UfaXAC8P8zS4JQx0rKsNeYT`, `ch_3UfaXAC8P8zS4JQx0zH4SqHf` |

No deprecation notice from the adapter on 11.2.0 (it hooks only the new filter name there). Tests: `StripeAdapterIntegrationTest::test_t160_adapter_requests_pin_the_stripe_api_version`, `::test_t160_pin_is_removed_when_the_call_fails`, the request shape in `::test_successful_charge_sends_gateway_metadata_and_writes_gateway_metas`, `RenewalReviewIntegrationTest::test_p18_book_after_review_stores_the_stripe_reference` (the review's search), unit `StripeRecurringAdapterTest` (filter name per gateway version, header).
