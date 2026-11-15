# Security and key operations

## Stable secrets

Configure two distinct random secrets in `wp-config.php` before issuing production licenses or activating product installations:

- `WPLC_LICENSE_KEY_SECRET` (or the key ring `WPLC_LICENSE_KEY_SECRETS` plus `WPLC_LICENSE_KEY_ID`) derives displayable license keys.
- `WPLC_INSTALLATION_ENCRYPTION_KEY` (or the key ring `WPLC_INSTALLATION_ENCRYPTION_KEYS` plus `WPLC_INSTALLATION_ENCRYPTION_KEY_ID`) encrypts installation credentials with AES-256-GCM.

### Fallback to WordPress salts

Without these constants the plugin keeps working, but derives license keys from `wp_salt( 'auth' )` (key ID `legacy`) and encrypts installation credentials with `wp_salt( 'secure_auth' )` (key ID `wp-salt`). Issuance is deliberately not blocked, because existing shops would stop working. The consequence: when the salts change (security plugin, host migration, "log out all users"), the shop can no longer display issued license keys, and no stored installation credential can be decrypted, so every activated site fails authentication and has to be activated again. Stored license-key hashes stay valid, so a customer who still has the key can re-activate.

While a fallback is active, everyone who works on licenses sees a non-dismissible error notice on WooCommerce, license, and plugin screens. Administrators (`manage_options`) get a copy-ready `wp-config.php` block in it; shop managers and support roles are told to pass the message on to an administrator. It contains placeholders only. `LicenseKey::uses_fallback_secret()` and `SecretCrypto::uses_fallback_key()` expose the state to diagnostics.

Moving an existing shop off the fallback without invalidating anything:

```php
define( 'WPLC_LICENSE_KEY_SECRETS', array(
	'legacy'  => 'PASTE-CURRENT-AUTH_KEY-FOLLOWED-BY-AUTH_SALT',
	'2026-11' => 'REPLACE-WITH-NEW-RANDOM-SECRET-1',
) );
define( 'WPLC_LICENSE_KEY_ID', '2026-11' );

define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(
	'wp-salt' => 'PASTE-CURRENT-SECURE_AUTH_KEY-FOLLOWED-BY-SECURE_AUTH_SALT',
	'2026-11' => 'REPLACE-WITH-NEW-RANDOM-SECRET-2',
) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '2026-11' );
```

1. `legacy` and `wp-salt` must be literal copies of the current values: the key constant immediately followed by the salt constant, with nothing in between (this is what `wp_salt()` returns when both are defined in `wp-config.php`). Paste the strings; do not reference `AUTH_KEY . AUTH_SALT`, or a later salt change breaks the entry again. If the salts are not defined in `wp-config.php`, WordPress keeps them in the options `auth_key`/`auth_salt` and `secure_auth_key`/`secure_auth_salt`; read them with `wp eval 'echo wp_salt( "auth" );'` on the server and never paste the output into tickets or chats.
2. Place the block above the line that loads `wp-settings.php` and deploy it to every application node.
3. Check that a customer account still shows an existing license key and that an activated site still passes its entitlement check.
4. Run `wp wplc rotate-installation-secrets` (dry run), then with `--apply`, so stored credentials and the stored response signing keys (current and prepared next key) move to the new encryption key. Keep `wp-salt` in the ring until a run ends with exit code 0 (see [Rotating the encryption key](#rotating-the-encryption-key)).
5. Existing licenses keep the key ID `legacy`; keep that entry for as long as such licenses exist.

A brand-new shop without issued licenses only needs one new random entry per ring.

Use at least 32 random bytes per secret. Do not reuse WordPress salts, commit secrets, or expose them in logs, diagnostics, exports, support tickets, or CI. Keep encrypted backups in an access-controlled secrets manager and deploy the same active key ring on every application node.

The implementation can read current and previous key IDs and re-encrypt installation credentials (and the stored response signing key) lazily after authenticated use. It also provides bounded WP-CLI tools:

- `wp wplc rotate-installation-secrets` defaults to a dry run; use `--apply` only after backup and validation. It processes at most `--max-rows` activations per run (default 5000); continue with `--after-id=<last ID>` from the previous output. Every run also checks the stored response signing key and a prepared next signing key, which are encrypted with the same key ring.
- `wp wplc recover-license-key-ids --to=<key-id>` defaults to a dry run and changes a row only when the existing license verifier matches a key derived by that key ID. The key ID must be configured in the ring. Continue a bounded run with `--after-id=<last ID>` (exactly the printed value; a larger value skips rows). Each change is audited as `license_key_id_recovered`.

### Rotation runbook (rehearsed, T-86)

The license-key ring and the encryption ring rotate independently. What each key protects decides when it may leave the ring:

| Ring | What breaks without the old entry | When the old entry may go |
|---|---|---|
| `WPLC_LICENSE_KEY_SECRETS` | Customers no longer see license keys derived with it ("Currently unavailable"). Activation with a key the customer already has keeps working: the server looks keys up by their stored hash, not by deriving them. | Never while licenses with that key ID exist; existing licenses are **not** migrated to the new ID (the key would change). |
| `WPLC_INSTALLATION_ENCRYPTION_KEYS` | Activations still encrypted with it fail with `401 installation_secret_invalid`, and a response signing key encrypted with it cannot be decrypted: responses lose the Ed25519 signature and every client that pins the public key rejects the server, including new activations (whose rows are still created and use up a site slot). | After `wp wplc rotate-installation-secrets` ends with exit code 0, **and** no backup that you might restore still needs it (see restore below). |

1. **Back up** the database (`mysqldump --single-transaction`) and the current key constants (secrets manager). Record row counts and a checksum of the `license_key_secret_id`/`license_key_hash` and `installation_secret_encrypted` columns to compare after a restore.
2. **Add** a new entry to both rings and make it current (`WPLC_LICENSE_KEY_ID`, `WPLC_INSTALLATION_ENCRYPTION_KEY_ID`); deploy to every node at once. New licenses get the new key ID immediately; existing keys and installations keep working.
3. **Check** that an existing license key is still shown in the customer account, that an installation still passes its entitlement check, and that `wp wplc recover-license-key-ids --to=<old-id>` reports `unmatched: 0`.
4. **Rotate** `wp wplc rotate-installation-secrets`, then `--apply` (repeat with `--after-id` until done), then a dry run again until it ends with exit code 0 (see [Rotating the encryption key](#rotating-the-encryption-key)).
5. **Retire** the old encryption entry only when the table allows it. Keep the old license-key entry.
6. A **typo in the current key ID** (`WPLC_LICENSE_KEY_ID` naming no ring entry) stops license issuance: paid orders get no license, the manual license form fails, and WooCommerce → Status → Logs (source `wp-license`) records `License not issued: the current license key ID ... has no secret`. Fix `wp-config.php`, then issue the missing licenses (for example with "Issue missing licenses for an order").

**Recovering a wrong key ID.** If an entry was renamed in the ring (same secret, new ID) or `license_key_secret_id` was changed by hand, customers see "Currently unavailable" instead of their key (the account only shows a key that reproduces the stored verifier). Configure the secret under the ID you want to keep, run `wp wplc recover-license-key-ids --to=<that-id>` (dry run: `recoverable` must equal the number of affected rows, `unmatched` should be 0), then with `--apply`. If the secret itself was removed, `--to` cannot match anything (`unmatched`, nothing modified): put the original secret back first; there is no other way to show those keys again.

**Recovering a removed encryption key.** Put the removed entry back into `WPLC_INSTALLATION_ENCRYPTION_KEYS` (keep the new one current), run `wp wplc rotate-installation-secrets --apply`, check `Response signing key: current` (or `rotated`), then retire it again. Do not run `wp wplc signing-key --rotate` to "repair" an undecryptable signing key: it creates a new key deliberately and every client that pins the old public key rejects the server. Activations created while signatures were missing exist on the server although the client reported an error; release them in the admin if they block a site slot.

**Restoring a backup.** A restored database needs the key ring that was in use when the backup was taken: rows in it are still encrypted with the old encryption key and reference the old license-key IDs. Restoring a pre-rotation backup with only the new encryption key makes every installation fail with `installation_secret_invalid` and the response signature disappear. Keep retired keys in the secrets manager for as long as backups that use them are kept, and deploy them together with the restored database. A restore also brings back the response signing key of that time: clients that pin only a newer public key reject the server, and activations made after the backup are gone (those sites have to activate again).

### Rotating the response signing key

Product builds pin the server's Ed25519 public key (`LicenseClient` accepts several). Rotate without breaking installed clients:

1. `wp wplc signing-key --prepare` creates the next key without using it (stored encrypted only) and prints `next_public_key` / `next_key_id` (repeated calls keep the same prepared key).
2. Release every product with both public keys pinned (current and next); wait until the installed base has updated.
3. `wp wplc signing-key --rotate` switches to the prepared key after a confirmation (`--yes` skips it in scripts; audit `response_signing_key_rotated` with the old and new key IDs). Clients pinning both keys keep working; clients pinning only the old key fail with `wplc_server_signature_invalid` until updated. Without a prepared key `--rotate` refuses; only `--rotate --force` (again with a confirmation) creates a brand-new key that no delivered client pins.
4. Remove the old public key from product builds only after no backup with the old signing key can be restored.

With `WPLC_RESPONSE_SIGNING_KEY` in `wp-config.php` the commands cannot rotate; `wp wplc signing-key --generate` prints a new seed and its public key without storing anything. Ship the public key pinned next to the current key, then replace the constant.

These tools have automated test coverage and were rehearsed on a database copy with backup and restore (docs/LAUNCH-EVIDENCE.md, T-86); operators must still rehearse them against a restored copy of their real data. Do not remove an old key until recovery, restore, and client authentication have been verified.

### Rotating the encryption key

1. Add the new key to `WPLC_INSTALLATION_ENCRYPTION_KEYS`, keep the old one, and point `WPLC_INSTALLATION_ENCRYPTION_KEY_ID` to the new ID on every application node.
2. `wp wplc rotate-installation-secrets` (dry run) inspects **all** activation rows and the stored response signing keys (current and prepared) and reports how many still use an old key.
3. `wp wplc rotate-installation-secrets --apply` re-encrypts them. On a very large table the run can be split with `--max-rows=<n>` and continued with `--after-id=<last ID>`; the command prints the last inspected ID and how many rows it did not reach.
4. **Completion criterion: the command ends with exit code 0.** It ends with exit code 1 as long as any row still uses an old key: rows it did not inspect (`--max-rows`), rows it could not verify or encrypt, rows that changed during the run, a signing key that still needs re-encryption or cannot be decrypted, and, in a dry run, every row that needs rotation. Only after an exit code 0 may the old key leave the key ring; removing it earlier makes the remaining installations fail with `401 installation_secret_invalid`. In scripts: `wp wplc rotate-installation-secrets || echo "old key still in use"`.
5. `\WPLC\CLI\RotateInstallationSecretsCommand::pending_count()` returns the number of activation rows that are not encrypted with the current key, for diagnostics and monitoring.

**Multisite:** every site of a network has its own license tables. The commands only work on the site selected with `--url`; repeat them for each site, for example `wp site list --field=url | xargs -I % wp --url=% wplc rotate-installation-secrets --apply`. The command prints this reminder on a network.

`wp wplc recover-license-key-ids` follows the same rules: all rows by default, exit code 1 while unverifiable, uninspected or (dry run) recoverable rows remain.

## Request security and database controls

Version 1 product requests are signed over method, REST route, timestamp, nonce, and the SHA-256 digest of the raw request body. Timestamps are limited to five minutes. A valid nonce is atomically stored; replay returns a conflict. Per-installation request limits (120 per minute) use an atomic database upsert and are checked before the nonce is stored, so a throttled installation cannot fill the replay table. The installation ID is compared and counted in lower case. Authentication failures and storage outages fail closed.

The hourly job `wplc_cleanup_security_rows` deletes used nonces after their ten minutes and limit buckets after two days. It deletes in batches of 5,000 rows until nothing is left or 20 seconds have passed, so the tables cannot grow faster than they are cleaned; `Authenticator::cleanup_security_rows()` returns the number of deleted rows. If WP-Cron does not run, these two tables grow without bound: monitor the cron.

Every response of the `wplc/v1` routes, errors included, is sent with `Cache-Control: no-store`. A cached `401` or `429` would otherwise be served to other installations.

### Rejected requests

Every error answer of the API is counted per error code and UTC minute in the limit table and read as daily sums (`\WPLC\Api\V1\ApiLog::counts()` for today, `counts( 1 )` for yesterday). Above 100 rejections of one code in a minute only every tenth rejection writes (adding ten), so a flood of rejected requests does not serialize on one row lock. Each rejection is announced through the action `wplc/api_request_rejected` (code, status, method, route) and written to the WooCommerce log `wplc-api`: the first per code and minute, then every hundredth, so an attack cannot fill the log. Path parameters are replaced by `{id}`; no address, license key, installation ID or site address is logged. Use the counters for alerts, for example a rising `installation_secret_invalid` (encryption key missing on a node), `request_expired` (clock drift), `activation_not_found` (unknown installations and wrong signatures share this code), `rate_limited` or `retry_later`.

Signed requests are also limited before authentication: 100 failed authentications (unknown installation or wrong signature) per client address and minute; past that the address gets `429 rate_limited` from one indexed read, without an activation lookup, decryption or HMAC.

API error messages are fixed English texts; the `code` is the contract (`docs/API-V1.md`, "Error codes").

### Activation rate limits and client addresses

Unauthenticated activation attempts are limited by **failed** attempts: 30 per minute per client address (IPv6 per /64) and 20 per minute per license-key prefix (first 13 characters) and client address. There is deliberately no bucket per prefix alone (P-09): the prefix is a public reference (mails, customer account, cancellation page), and such a bucket let anybody who knew it block the owner's activations from a single address. It is not needed against guessing: after the prefix a key carries about 90 further random bits of the license UUID and a 256-bit HMAC, so trying one key from many addresses is as hopeless as trying random keys, and the per-address buckets bound what one client can cause. A failure is an unknown key or a request rejected before the key lookup (malformed request, invalid development URL). Successful activations and answers about a recognized key (wrong product, expired) do not count. A request without a resolvable address is counted in a shared `unknown` bucket rather than rejected. A failing limit store still fails closed with 503.

Once an address or prefix has reached its failure limit within the current minute it is throttled (T-142):

- Every unknown key and every malformed request is answered `429 rate_limited`, the same answer as for a fully blocked client, so the error code tells a guesser nothing new.
- The key is still looked up (one indexed hash comparison). A recognized key proceeds, so customers behind a shared address (office NAT, agency server, CDN without proxy configuration) are no longer locked out by someone else's typos or guessing. Before T-142 every attempt counted before the key check and a valid key was blocked together with the guesser.
- Letting valid keys through is an oracle. It is bounded: requests made while throttled are counted in a second bucket with the same limit, and past it every request gets 429 without a key lookup. One address can therefore receive at most 60 key-dependent answers per minute (30 failures plus 30 throttled requests), at most 40 of them for one prefix. Against a 64-hex-character HMAC part of the key this is no practical guessing rate.
- A valid key is still blocked when its address keeps sending past the throttled ceiling within the same minute; deliberate flooding from a shared address can delay activations from that address by up to a minute, accidental failures cannot. Other addresses are not affected.

Successful activations of new installation IDs are limited as well (P-10): per license and UTC hour twice the live-site limit, at least 10 (filter `wplc_new_activations_per_hour`), so a leaked or misused key cannot fill `activations` and the audit log. Repeated activations of a known installation ID are not counted.

The bucket keys are HMAC-SHA-256 values with a server-side key: `WPLC_RATE_LIMIT_KEY` from `wp-config.php` when defined, otherwise a key derived from the WordPress auth salt. A database dump therefore does not contain hashes that can be reversed into client addresses, key prefixes or installation IDs by trying candidates. Changing the key only resets the running buckets.

The address is `REMOTE_ADDR` unless `WPLC_TRUSTED_PROXY_HEADER` names a forwarding header (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, ...). The header is honored only if the direct peer is listed in `WPLC_TRUSTED_PROXIES` (IPs or CIDR ranges; IPv4-mapped IPv6 ranges such as `::ffff:10.0.0.0/104` count as their IPv4 range). Without `WPLC_TRUSTED_PROXIES` (undefined or empty) the header is ignored and the diagnostics page shows a warning (P-42): trusting it from any peer let every client that reaches the origin choose its own bucket. Behind a CDN without this configuration all customers share one bucket and a single client can block activations for everyone. Keep the proxy list current with the provider's published ranges. Details: `docs/API-V1.md`.

The key prefix is shown in the customer account and in the admin. Since P-09 knowing it only lets someone throttle that prefix from their own address; the owner activates from another address unaffected.

### Response signatures

Signed responses carry `payload` (the exact signed JSON, containing the data and the request nonce) and `payload_signature` (HMAC with the installation secret). The client verifies the received bytes and the echoed nonce, so a recorded response cannot be replayed for a later request and verification no longer depends on both sides encoding JSON identically.

`payload_signature_ed25519` is the server's own Ed25519 signature over `wplc-response-v2\n{installation UUID}\n{payload}`. Because it covers the payload, it covers the nonce: a recorded "active" answer cannot be replayed later, even by somebody who knows the installation secret. Product builds pin the public key and reject answers and cached snapshots without it.

The legacy members `data` + `signature` (HMAC over the re-encoded data) and `signature_ed25519` (over `wplc-response-v1\n{UUID}\n{JSON of data}`) are still sent for product plugins that bundle an earlier client. They are not bound to a request and are the only downgrade path; clients must not accept them in place of the payload signatures. The server keeps sending them during API v1; the fallback in the bundled client is to be removed before the first add-on release (B-158).

Data that cannot be JSON-encoded (a non-finite number in an entitlement value) is never signed: such entitlement values are left out of the snapshot, and if encoding still fails the answer is `500 response_encoding_failed` instead of a signature over an empty string.

All responses of the API and the download redirect are sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

### The response signing key

Production servers must set the key in `wp-config.php`, next to the other secrets and on every application node:

```php
define( 'WPLC_RESPONSE_SIGNING_KEY', 'BASE64-OF-32-RANDOM-BYTES' );
```

`wp wplc signing-key --generate` prints a fresh value together with its public key and key ID without storing anything. The public key goes into the product builds; back the constant up like the other secrets. Losing it means every pinned client rejects the server until a product update ships a new public key.

- **Validation.** The value must be the base64 of a 32-byte seed or of a real 64-byte Ed25519 secret key (seed followed by its public key). The server derives the key pair, compares the halves and signs and verifies a probe before it uses the key. Anything else is refused: responses are then sent **without** Ed25519 members, `ResponseSigner::status()['state']` is `constant_invalid`, `wp wplc signing-key` ends with an error and the action `wplc/response_signing_key_unavailable` fires. The server never falls back to another key in this case, because clients pin one public key.
- **Without the constant** the server creates a key on first use and stores it in the option `wplc_response_signing_key`, encrypted with the installation encryption key ring. Creation takes a named database lock and reads the option back from the database, so two parallel first requests cannot sign with different keys; it writes the audit entry `response_signing_key_created` and fires `wplc/response_signing_key_created`. The key ID is remembered in `wplc_response_signing_key_id`.
- **Never in plaintext.** If the key cannot be encrypted (no usable encryption key, no OpenSSL) it is not stored and not used (`encryption_unavailable`). A plaintext value left by an earlier version is encrypted the first time it is read.
- **Never replaced silently.** When the stored key can no longer be decrypted (changed salts or encryption keys: `undecryptable`), is damaged (`corrupt`) or its option disappeared (`missing`), the server does not generate a new one. Answers go out without the Ed25519 members and the alert action fires. Fix the key ring or restore the option; if the key is really lost, `wp wplc signing-key --rotate --force` creates a new one deliberately after a confirmation (audit entry `response_signing_key_rotated`), and the product builds need the new public key.
- **Rotation of the encryption key** includes the signing key: `wp wplc rotate-installation-secrets` re-encrypts it and reports its state.
- `ResponseSigner::status()` returns `state`, `source` (`constant` or `option`), `key_id` and `public_key` for diagnostics and monitoring.

The installation secret itself is stored on the customer's site by the product plugin, in the options table of that site. Whoever can read that database or a backup of it can sign requests for that installation (download the licensed package, release the activation). This is the customer's data on the customer's server; the license server limits the damage with the per-installation rate limit, the site-address check on deactivation and the Ed25519 signature that such a reader cannot forge.

### Activation replacement and staging sites

All deactivations (REST, customer account, admin, WP-CLI) go through `ActivationManager::deactivate()`: a compare-and-set on `status = active` under the per-license lock that also deactivates the active staging site of a live site, with one audit entry per row. The audit entries keep the site address, and a changed address of an existing installation is recorded as `site_url_changed`.

A new installation that activates the same site (same environment; address compared without scheme, leading `www.` and path case) as an active activation of the same license replaces it (audit action `site_replaced`). The limits are checked first, the new activation is stored next, and only then the old one ends; its staging site moves to the new activation (`staging_reparented`). Development activations are not replaced. Anyone holding the license key can therefore disconnect a site by activating its URL elsewhere; they could equally consume a free slot, and the audit log records the replacement.

A released installation still authenticates and receives a signed `deactivated` answer, so it cannot keep running on a cached snapshot (see `docs/API-V1.md`, "Released installations"). `DELETE` compares the site address the client sends with the activated one and refuses a mismatch (`409 site_mismatch`), so a clone of a site cannot end the activation of the original once clients send their address.

Limits that depend on what the client says about itself:

- **Staging** is free (one per live site), so its host is checked: same registrable domain as the live site, a local host, a staging label or a hosting provider's staging domain. Any other host is counted as a live site. The registrable domain is derived without a public-suffix list (last two labels, three under generic second-level names of country codes such as `co.uk`); sites under a shared suffix that is not in that short list (`*.github.io`, `*.myshopify.com`) are treated as one domain. Install a filter on `wplc_registrable_domain` or `wplc_is_staging_host` if that matters for your products.
- **Development** activations do not use a live slot and their host is only checked by name or private address range, which a customer controls. They are therefore capped per license (twice the live-site limit, at least 5; filter `wplc_development_site_limit`, 0 forbids them).

### Database requirements of the activation lock

Activation, deactivation and the creation of the signing key serialize on MySQL named locks (`GET_LOCK`). Two things can break that:

- **The server refuses named locks** (a connection proxy that multiplexes sessions, such as ProxySQL in its default mode, or some managed clusters). Every activation then answers `503 retry_later`. `\WPLC\License\ActivationManager::lock_self_test()` takes and releases a lock and returns whether that worked; call it from diagnostics, after deployment and from monitoring.
- **Read/write splitting** (HyperDB, LudicrousDB): the lock and the counting queries must run on the same server. Before taking the lock the plugin calls `$wpdb->send_reads_to_master()` when the database class offers it, which is the documented switch of both drop-ins. This has not been run against a replicated setup. With another splitting layer, route the tables `wplc_activations` and `wplc_licenses` and all `GET_LOCK` calls to the primary.

Galera or Group Replication with several writers are not supported: named locks are local to one node.

Update download grants are stored as hashes, consumed atomically, and checked again against current license state before redirect. Restrict package hosts to HTTPS allowlisted hosts. Keep package URLs and grant records only for the documented operational retention period.

### Private package directory

Uploaded release ZIPs are paid content. By default they live in `wp-content/uploads/wplc-packages/`, protected by `.htaccess`/`web.config` (Apache, IIS) and by 128-bit random file names. nginx ignores `.htaccess`, so on nginx the file name is the only barrier unless you act:

1. **Preferred:** move the directory outside the web root with `define( 'WPLC_PACKAGE_STORAGE_DIR', '/var/lib/wplc-packages' );` in `wp-config.php` (absolute, writable by the PHP user, mode `0750`, included in backups). An invalid or web-reachable value is ignored and reported, never silently used.
2. **Otherwise:** add `location ^~ /wp-content/uploads/wplc-packages/ { deny all; }` to the nginx `server` block, adapted to the real uploads path (multisite: `/wp-content/uploads/sites/{id}/wplc-packages/`) and placed before regex locations.
3. **Verify:** **WooCommerce → License diagnostics → Package storage** must show "Outside the web root" or "refused to deliver a test file". The self-check fetches a canary file over HTTP, caches the answer for 12 hours and runs only on the diagnostics and releases pages; press **Check again** after every web server change. "Publicly readable" also raises an error notice on **License releases**. "Could not reach the site" (loopback blocked) means: verify by hand with `curl -I https://shop.example/wp-content/uploads/wplc-packages/index.html`, which must return `403`.

If the self-check ever reported "Publicly readable" in production, treat the stored packages as potentially downloaded: consider rotating them (withdraw and publish new versions) and review web server access logs for requests to the directory.

Renewal execution uses a per-renewal MySQL advisory lock and the provider adapter must pass an idempotency key through to the gateway. This cannot by itself resolve an ambiguous provider timeout: reconcile provider state before retrying a charge.

## Deployment and recovery checklist

- Use HTTPS for the license API and package delivery.
- Keep uploaded packages outside the web root (`WPLC_PACKAGE_STORAGE_DIR`) or deny the directory on nginx, and confirm the package storage self-check on the diagnostics page.
- Restrict database, `wp-config.php`, CI, and secret-manager access.
- Back up database and package metadata before schema, key, or release changes.
- Restore a recent backup to an isolated staging copy and verify schema, license verifiers, activation authentication, renewal links, and package records.
- Run key rotation and key-ID recovery in dry-run mode first; preserve command output with secrets redacted.
- Never repair production rows by direct SQL except through an approved incident process with before/after backups and an audit record.
- Do not enable automatic charges until a concrete gateway integration passes its sandbox scenarios for consent, token ownership, idempotency, webhooks, timeouts, refunds, and manual authentication.

## Current release evidence

The source contains unit tests and a WordPress/MySQL REST integration suite. Their CI execution and the full database/gateway/update acceptance are release gates. A runbook is not evidence that production infrastructure, backup restore, key rotation, alerts, privacy handling, or a payment account has been configured.
