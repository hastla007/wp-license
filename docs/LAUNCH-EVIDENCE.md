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
