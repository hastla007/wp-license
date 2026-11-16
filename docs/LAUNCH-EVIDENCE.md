# Launch evidence register

Complete this register before enabling paid production checkout. Every evidence row needs a named owner, date, environment/version, result, and link to the run or approval record. Do not store credentials or personal customer data in this file.

| Gate | Required evidence | Owner | Date | Result / evidence link |
|---|---|---|---|---|
| PR review and merge order | All required PRs reviewed, merge conflicts resolved, deployment tag recorded |  |  |  |
| WooCommerce checkout | Order → license, monthly/yearly, duplicate order event, tax/invoice behavior |  |  |  |
| Stripe test mode | Success, decline, timeout, duplicate event, SCA recovery, refund |  |  |  |
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
3. **Installation:** `wp plugin install wp-license-captain-0.2.0.zip --activate` → `Plugin 'wp-license' activated.` Schema: 16 `wp_wplc_*` tables, `wplc_schema_version` = 25, `wp wplc schema status`: `Schema installed: 25; expected: 25`, `Every table, column and index exists.` REST namespace `wplc/v1` with 7 routes. No entry in `wp-content/debug.log` names a file of the plugin.
4. **Admin pages** as logged-in administrator over HTTP: `wplc-licenses`, `wplc-releases`, `wplc-renewal-review`, `wplc-settings`, `wplc-catalog-csv`, `wplc-catalog-files`, `wplc-diagnostics`, `wplc-audit-log`, `plugins.php` and the product editor answer HTTP 200 without a PHP error in the page. (The very first admin request answered with WooCommerce's one-time redirect after activation; the same page then answered 200.)
5. **Deactivate and uninstall:** `Deactivated 1 of 1 plugins.`, `Uninstalled 1 of 1 plugins.`; plugin directory removed, no `wplc_*` cron event left, no entry in `debug.log`. Kept by design (the list in `uninstall.php`): the 16 tables and the options `wplc_entitlement_catalog`, `wplc_reminder_log_version`, `wplc_response_signing_key`, `wplc_response_signing_key_id`, `wplc_rewrite_version`, `wplc_schema_version`, `wplc_site_fingerprint`, `wplc_sites_endpoint_rules`.

### R-25 (interim 3): T-61 and T-86 repeated on schema 25

Run on 16 Nov 2026 on the working branch, commit `7494bfe` (wave 4 and 5, plugin version 0.2.0, schema 25; not a release commit, no tag). Same procedures as the schema-23 repetitions above. They do not replace the repetition with the release commit and the real tag push (R-25).

Table count: schema 25 has **15** plugin tables (`Schema::table_statements()` in src/Infrastructure/Database/Schema.php holds 15 `CREATE TABLE` statements, the same 15 as schema 23; schemas 24 and 25 add catalog data and columns, no table). Both runs below counted 15 per site. The "16 tables" in the record "R-25 (interim 2)" above and in docs/RELEASE-READINESS.md (T-95 item) is a counting error, not a different state.

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
