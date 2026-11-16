# 2. Installation, secrets and keys

## Installing the plugin

1. Install and activate WooCommerce.
2. Verify the download: `sha256sum -c wp-license-captain-<version>.zip.sha256` must print `OK`.
3. Add the secret constants below to `wp-config.php` **before** the first license is issued.
4. Upload the ZIP under **Plugins → Add New → Upload Plugin** and activate it. Activation creates the database tables (`{prefix}wplc_*`) and the scheduled jobs.
5. Open **WooCommerce → License settings**. The "Getting started" checklist and the status table show what is still missing.
6. Exclude the API and the account pages from page and CDN caches (chapter 9, "Caching, CDN and firewalls").

Install on a staging copy first. A staging site gets its own secrets and should have `WP_ENVIRONMENT_TYPE` set to `staging`; billing is then paused automatically (chapter 9).

## The secrets in `wp-config.php`

Secrets are never stored in the database and never shown in the admin. Use at least 32 random bytes per secret, for example from `openssl rand -base64 48`. Do not reuse the WordPress salts, never commit the values to Git and never paste them into tickets, chats or logs. Deploy the same values to every web server of the shop.

| Constant | Purpose | What breaks when it is lost or changed |
| --- | --- | --- |
| `WPLC_LICENSE_KEY_SECRETS` (array key ID → secret) and `WPLC_LICENSE_KEY_ID` (the ID used for new licenses) | Derives the license keys shown to customers | The shop can no longer display keys of that key ID ("Currently unavailable"). Stored key hashes stay valid, so a customer who still has the key can keep activating. |
| `WPLC_INSTALLATION_ENCRYPTION_KEYS` and `WPLC_INSTALLATION_ENCRYPTION_KEY_ID` | Encrypts the per-installation secrets (AES-256-GCM) and the stored response signing key | Every activated site fails authentication (`401 installation_secret_invalid`) and has to be activated again. |
| `WPLC_RESPONSE_SIGNING_KEY` | Ed25519 key the server signs its answers with; your add-ons pin its public key | Every delivered add-on rejects the server until you ship a release with the new public key. |
| `WPLC_STRIPE_WEBHOOK_SECRET` (and optionally `WPLC_STRIPE_WEBHOOK_SECRET_TEST`, or `WPLC_STRIPE_WEBHOOK_SECRETS` on a network) | Verifies Stripe webhook deliveries | Every Stripe event is rejected. See chapter 8. |

A new shop without issued licenses:

```php
// wp-config.php, above the line that loads wp-settings.php.
define( 'WPLC_LICENSE_KEY_SECRETS', array(
	'2027-01' => 'REPLACE-WITH-RANDOM-SECRET-1',
) );
define( 'WPLC_LICENSE_KEY_ID', '2027-01' );

define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(
	'2027-01' => 'REPLACE-WITH-RANDOM-SECRET-2',
) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '2027-01' );

define( 'WPLC_RESPONSE_SIGNING_KEY', 'OUTPUT-OF-wp-wplc-signing-key--generate' );
```

Use the key-ring form (arrays) from the start: a later rotation then only adds an entry. The single-value constants `WPLC_LICENSE_KEY_SECRET` and `WPLC_INSTALLATION_ENCRYPTION_KEY` still work, but you need a ring for the first rotation anyway. **Never remove or rename a ring entry** while licenses or installations still use it; how to rotate is described in chapter 9.

### Without the constants

The plugin keeps working without them, but derives license keys from the WordPress `auth` salt (key ID `legacy`) and encrypts installation secrets with the `secure_auth` salt (key ID `wp-salt`). When the salts change (a security plugin, a host migration, "log out all users"), issued keys can no longer be displayed and every activated site has to be activated again. While this fallback is active, everyone who works on licenses sees a non-dismissible error notice; administrators get a copy-ready `wp-config.php` block in it.

To move an existing shop off the fallback without invalidating anything, keep the current salt values as the first ring entries:

```php
define( 'WPLC_LICENSE_KEY_SECRETS', array(
	'legacy'  => 'PASTE-CURRENT-AUTH_KEY-FOLLOWED-BY-AUTH_SALT',
	'2027-01' => 'REPLACE-WITH-NEW-RANDOM-SECRET-1',
) );
define( 'WPLC_LICENSE_KEY_ID', '2027-01' );

define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(
	'wp-salt' => 'PASTE-CURRENT-SECURE_AUTH_KEY-FOLLOWED-BY-SECURE_AUTH_SALT',
	'2027-01' => 'REPLACE-WITH-NEW-RANDOM-SECRET-2',
) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '2027-01' );
```

1. Paste the literal strings: the key constant immediately followed by the salt constant. Do not write `AUTH_KEY . AUTH_SALT`, or a later salt change breaks the entry again. If the salts are not in `wp-config.php`, read them on the server with `wp eval 'echo wp_salt( "auth" );'` (and `"secure_auth"`).
2. Deploy to every web server. Check that a customer account still shows an existing key and that an activated site still passes its check.
3. Run `wp wplc rotate-installation-secrets`, then with `--apply`, until it ends with exit code 0. Only then may `wp-salt` leave the ring.
4. Keep the `legacy` entry for as long as licenses with that key ID exist.

## The response signing key

Every answer of the API carries an Ed25519 signature. Your add-on pins the public key and refuses answers without a valid signature. The key must therefore never change silently.

**Recommended: the key in `wp-config.php`.**

```sh
wp wplc signing-key --generate
```

prints a fresh value for `WPLC_RESPONSE_SIGNING_KEY` together with its public key and key ID, without storing anything. Put the value into `wp-config.php` on every web server and back it up with the other secrets. A key in the constant survives a database restore. The value must be the base64 of a 32-byte seed (or of a 64-byte Ed25519 secret key); anything else is refused, and answers are then sent without the Ed25519 signature, which your add-ons reject.

**Without the constant** the server creates a key on first use and stores it in the option `wplc_response_signing_key`, encrypted with the installation encryption key ring. A stored key that can no longer be decrypted is never replaced on its own; the diagnostics page and `wp wplc signing-key` name the reason.

**The public key for your add-on:**

```sh
wp wplc signing-key --field=public_key
```

`wp wplc signing-key` without options prints `public_key`, `key_id`, `source` (`constant` or `option`) and `state`. Chapter 6 shows where the public key goes; chapter 9 how to rotate the key without locking out installed add-ons.

## Other constants

| Constant | Purpose |
| --- | --- |
| `WPLC_PACKAGE_STORAGE_DIR` | Absolute directory outside the web root for uploaded release ZIPs (chapter 5). Recommended for production. |
| `WPLC_S3_BUCKET`, `WPLC_S3_ACCESS_KEY_ID`, `WPLC_S3_SECRET_ACCESS_KEY` | Private S3-compatible bucket for release packages and a key that may read it (chapter 5). Optional; all three are required together. Never stored in the database or shown. |
| `WPLC_S3_REGION`, `WPLC_S3_ENDPOINT`, `WPLC_S3_PATH_STYLE` | Region (default `us-east-1`, `auto` for Cloudflare R2), HTTPS endpoint (default: AWS for the region) and path-style addressing (default `false`) of that storage. |
| `WPLC_TRUSTED_PROXY_HEADER`, `WPLC_TRUSTED_PROXIES` | Behind a CDN or reverse proxy: the header with the real client address (for example `HTTP_CF_CONNECTING_IP`) and the IPs or CIDR ranges of the proxies allowed to set it. Without both, all customers behind the proxy share one rate-limit bucket (chapter 9). |
| `WPLC_RATE_LIMIT_KEY` | Key of the HMAC that hashes rate-limit buckets. Optional; derived from the WordPress auth salt otherwise. Changing it only resets the running buckets. |
| `WPLC_PAUSE_BILLING` | `true` pauses all charges, expiries and reminder emails (staging copies, restores); `false` lets a non-production site bill test cards (chapter 9). |
| `WPLC_DISABLE_UPDATE_CHECK` | `true` switches off the plugin's check for new versions of itself (chapter 12). |
| `WPLC_VENDOR_SERVER_URL`, `WPLC_VENDOR_PRODUCT_KEY`, `WPLC_VENDOR_PUBLIC_KEYS`, `WPLC_VENDOR_ACCOUNT_URL` | Optional: lets this plugin receive its own updates from the license server of the vendor you bought it from (chapter 12). |
| `WPLC_DEBUG` | `true` writes additional debug messages to the WooCommerce log (source `wp-license`). |
| `WPLC_REMOVE_ALL_DATA` | `true` before deleting the plugin removes its tables and options as well (chapter 12). |

## Settings in the admin

**WooCommerce → License settings** holds the few settings that are not secrets:

| Setting | Default | Meaning |
| --- | --- | --- |
| Additional trusted package hosts | – | HTTPS hosts your release packages may be delivered from besides the shop itself (chapter 5). Only administrators may change them. |
| Catalog file of release packages | on | Import `wplc-catalog.json` automatically from uploaded release ZIPs (chapter 3). |
| Reminder lead days | `30,7` | Days before the end of the paid period on which expiry reminders are sent. |
| Renewal notice lead days | 7 | Days before an automatic charge on which the customer is notified (1 to 60). |
| Combine automatic renewals | off | Charge the licenses of one customer that renew automatically on the same day with one order and one payment (chapter 8). |
| Price change notice period | 60 | Days between the notice of a higher renewal price and its first charge (30 to 365, chapter 8). |
| Sender note | – | Short text the license emails can show below their content, for example a support contact (up to 500 characters). |

The same page shows the Stripe webhook URL with a copy button, whether the secrets are configured, how many recent license keys verify with the current secret, whether automatic renewal is available and why not, the checkout type, the grace period and the database schema version.

## Who may do what

The plugin brings its own capabilities. They are not stored on roles; each one is derived from a WordPress capability unless you store it explicitly on a role.

| Capability | Allows | Default: users with |
| --- | --- | --- |
| `wplc_view_licenses` | License list and detail, CSV export of licenses | `manage_woocommerce` |
| `wplc_manage_licenses` | Suspend, revoke, reactivate, change term, release sites, new key, change owner, bulk actions, renewals to review | `manage_woocommerce` |
| `wplc_grant_licenses` | Add a license by hand, extend several licenses, issue missing licenses for an order | `manage_woocommerce` |
| `wplc_restore_licenses` | Restore a revoked license | `manage_options` |
| `wplc_manage_catalog` | License fields of products, catalog files, catalog CSV | `manage_woocommerce` |
| `wplc_publish_releases` | Create, publish, withdraw and delete releases | `manage_options` |
| `wplc_view_diagnostics` | Diagnostics, complete audit log and its export | `manage_woocommerce` |
| `wplc_manage_settings` | License settings except the package hosts | `manage_woocommerce` |
| `wplc_manage_package_hosts` | Trusted package hosts | `manage_options` |
| `wplc_change_prices` | Announce and withdraw a new renewal price | `manage_options` |

A published release is code that runs on every customer site: give `wplc_publish_releases` to as few accounts as possible. A support role without shop access gets `read`, `wplc_view_licenses` and `wplc_manage_licenses` (for example `wp role create license_support "License support"` and `wp cap add license_support read wplc_view_licenses wplc_manage_licenses`); the license list then appears as its own menu "Licenses". To take a capability away from shop managers, store it as denied: `wp cap add shop_manager wplc_grant_licenses --grant=false`. Developers can change the mapping with the filter `wplc_capability` (arguments: capability, action name without `wplc_`).
