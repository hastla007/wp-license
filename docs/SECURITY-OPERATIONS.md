# Security and key operations

## Stable secrets

Configure two distinct random secrets in `wp-config.php` before issuing production licenses or activating product installations:

- `WPLC_LICENSE_KEY_SECRET` (or the key ring `WPLC_LICENSE_KEY_SECRETS` plus `WPLC_LICENSE_KEY_ID`) derives displayable license keys.
- `WPLC_INSTALLATION_ENCRYPTION_KEY` (or the key ring `WPLC_INSTALLATION_ENCRYPTION_KEYS` plus `WPLC_INSTALLATION_ENCRYPTION_KEY_ID`) encrypts installation credentials with AES-256-GCM.

### Fallback to WordPress salts

Without these constants the plugin keeps working, but derives license keys from `wp_salt( 'auth' )` (key ID `legacy`) and encrypts installation credentials with `wp_salt( 'secure_auth' )` (key ID `wp-salt`). Issuance is deliberately not blocked, because existing shops would stop working. The consequence: when the salts change (security plugin, host migration, "log out all users"), the shop can no longer display issued license keys, and no stored installation credential can be decrypted, so every activated site fails authentication and has to be activated again. Stored license-key hashes stay valid, so a customer who still has the key can re-activate.

While a fallback is active, shop managers (`manage_woocommerce`) see a non-dismissible error notice on WooCommerce, license, and plugin screens with a copy-ready `wp-config.php` block. It contains placeholders only. `LicenseKey::uses_fallback_secret()` and `SecretCrypto::uses_fallback_key()` expose the state to diagnostics.

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
4. Run `wp wplc rotate-installation-secrets` (dry run), then with `--apply`, so stored credentials move to the new encryption key. Keep `wp-salt` in the ring until a dry run reports nothing left.
5. Existing licenses keep the key ID `legacy`; keep that entry for as long as such licenses exist.

A brand-new shop without issued licenses only needs one new random entry per ring.

Use at least 32 random bytes per secret. Do not reuse WordPress salts, commit secrets, or expose them in logs, diagnostics, exports, support tickets, or CI. Keep encrypted backups in an access-controlled secrets manager and deploy the same active key ring on every application node.

The implementation can read current and previous key IDs and re-encrypt installation credentials lazily after authenticated use. It also provides bounded WP-CLI tools:

- `wp wplc rotate-installation-secrets` defaults to a dry run; use `--apply` only after backup and validation.
- `wp wplc recover-license-key-ids --to=<key-id>` defaults to a dry run and changes a row only when the existing license verifier matches a key derived by that key ID.

These tools have unit coverage, but operators must still rehearse them against a restored copy of real data. Do not remove an old key until recovery, restore, and client authentication have been verified.

## Request security and database controls

Version 1 product requests are signed over method, REST route, timestamp, nonce, and the SHA-256 digest of the raw request body. Timestamps are limited to five minutes. A valid nonce is atomically stored; replay returns a conflict. Per-installation request limits use an atomic database upsert and expire through a bounded scheduled cleanup. Authentication failures and storage outages fail closed.

### Activation rate limits and client addresses

Unauthenticated activation attempts are limited to 30 per minute per client address (IPv6 per /64) and 20 per minute per license-key prefix (first 13 characters, hashed), so one key cannot be tried from many addresses. A request without a resolvable address is counted in a shared `unknown` bucket rather than rejected. A failing limit store still fails closed with 503.

The address is `REMOTE_ADDR` unless `WPLC_TRUSTED_PROXY_HEADER` names a forwarding header (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, ...). The header is honored only if the direct peer is listed in `WPLC_TRUSTED_PROXIES` (IPs or CIDR ranges). Leaving `WPLC_TRUSTED_PROXIES` undefined trusts the header from any peer; do that only when the origin accepts connections from the proxy alone, otherwise an attacker sets the header and evades the per-address limit (the per-key limit still applies). Behind a CDN without this configuration all customers share one bucket and a single client can block activations for everyone. Keep the proxy list current with the provider's published ranges. Details: `docs/API-V1.md`.

Known trade-off: the key prefix is shown in the customer account and in the admin, so someone who knows a customer's prefix can hold that one key at its limit and delay its activations; other keys are unaffected.

### Response signatures

Signed responses carry `payload` (the exact signed JSON, containing the data and the request nonce) and `payload_signature`. The client verifies the received bytes and the echoed nonce, so a recorded response cannot be replayed for a later request and verification no longer depends on both sides encoding JSON identically. The older `data`/`signature` pair is still sent for product plugins that bundle an earlier client; it offers no replay protection. Signed responses and the download redirect are sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

### Activation replacement and staging sites

All deactivations (REST, customer account, admin) go through `ActivationManager::deactivate()`: a compare-and-set on `status = active` under the per-license lock that also deactivates the active staging site of a live site, with one audit entry per row. A new installation that activates with the same environment and site URL as an active activation of the same license replaces it (audit action `site_replaced`). Anyone holding the license key can therefore disconnect a site by activating its URL elsewhere; they could equally consume a free slot, and the audit log records the replacement.

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
