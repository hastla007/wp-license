# 6. Integrating the client library into your add-on

Your premium add-on talks to the license server through the **client library**: a small PHP library for PHP 8.5+ (since client 2.2.0; 2.1.x ran on PHP 7.4) and WordPress 7.0+ (since 2.2.0) that needs neither WooCommerce nor any other part of the server. It activates the license key, keeps the last signed answer as an offline snapshot, gates premium features, shows a license screen and notices, and installs updates through the WordPress updater.

> **Where the client belongs.** If your product has a free core plugin (for example on WordPress.org), that core plugin contains no library, no entitlement check, no custom updater and no call to the license server. Only the paid add-on carries the client. An expired license only switches add-on features off; it never deletes or hides data the customer created and never changes what the core plugin does.

## Getting the library

The library is published as its own archive next to every release of the server plugin: **`license-client-<version>.zip`** with a `.sha256` file. It contains one folder `license-client-<version>/` with:

| Path | Content |
| --- | --- |
| `src/Client/*.php` | The library, namespace `WPLC\Client`: `LicenseManager` (the facade your add-on uses), `LicenseStatus`, `Updater`, `LicenseClient` (transport), `OptionStore`, `Messages` (all customer-facing texts) and `load.php`. |
| `bin/export-client.php` | Copies the library into your add-on under your own namespace and text domain. |
| `bin/client-contract-test.php` | Contract test you run against your own server. |
| `README.md` | Embedding, namespace rules, compatibility promise. |
| `CHANGELOG-CLIENT.md` | Changes by library version; changes that need work in the add-on are marked **Action**. |

Every class has a `VERSION` constant (for example `LicenseManager::VERSION`).

### Compatibility promise (API v1)

- A client of one major version (2.x) works with **every server that answers API v1** (`/wp-json/wplc/v1/…`). A newer server may add fields, error codes and routes; the client ignores what it does not know and treats an unknown error as "keep the stored rights".
- A server never needs a newer client. Minor and patch versions of the client only add or fix.
- A client that needs more than API v1 gets a new major version; API v1 then stays available on the server for the installed 2.x clients.

## Embedding: export under your own namespace

Several add-ons with this library can be active on one site, each with a different version. Always export the library under a namespace of your add-on, in your build or once (then commit the result):

```sh
php bin/export-client.php --target=/path/to/acme-reports-pro/includes/licensing \
    --namespace='Acme\ReportsPro\Licensing' --text-domain=acme-reports-pro
```

The script replaces `namespace WPLC\Client;` by your namespace and the text domain `wp-license` by yours, and fails if a copied file still refers to `WPLC\Client`. Your add-on then always runs exactly the version it was tested with, and `wp i18n make-pot` of the add-on finds the strings of `Messages.php`, which you translate with the add-on. Do not edit the exported files; export again to update.

An unchanged copy (namespace `WPLC\Client`) also loads, because every class is only declared when it does not exist yet, but then the copy loaded first is used by every add-on, even when it is older. Use that only for experiments.

## A complete minimal add-on

Three files of glue.

`acme-reports-pro/license-config.php`, shared by the main file and `uninstall.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

return array(
	'plugin_file' => __DIR__ . '/acme-reports-pro.php',
	'product_key' => 'acme-reports-pro',
	'version'     => '1.0.0',
	'name'        => 'Acme Reports Pro',
	'text_domain' => 'acme-reports-pro',
	'server_url'  => defined( 'ACME_REPORTS_PRO_LICENSE_SERVER' ) ? ACME_REPORTS_PRO_LICENSE_SERVER : 'https://licenses.example.com',
	'public_keys' => array( 'BASE64-PUBLIC-KEY' ), // wp wplc signing-key --field=public_key
	'account_url' => 'https://shop.example/my-account/wplc-licenses/',
	'update_uri'  => 'https://licenses.example.com/acme-reports-pro',
);
```

`acme-reports-pro/acme-reports-pro.php`:

```php
<?php
/**
 * Plugin Name: Acme Reports Pro
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.5
 * Text Domain: acme-reports-pro
 * Update URI: https://licenses.example.com/acme-reports-pro
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/licensing/load.php';

function acme_reports_pro_license(): \Acme\ReportsPro\Licensing\LicenseManager {
	static $manager = null;
	if ( null === $manager ) {
		$manager = new \Acme\ReportsPro\Licensing\LicenseManager( require __DIR__ . '/license-config.php' );
	}
	return $manager;
}
acme_reports_pro_license()->register();

// Premium features, switched on once all plugins and the theme are loaded.
add_action(
	'init',
	static function () {
		if ( acme_reports_pro_license()->is_entitled( 'acme_reports.pdf_export' ) ) {
			require_once __DIR__ . '/includes/pdf-export.php';
		}
	}
);
```

`acme-reports-pro/uninstall.php`:

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/licensing/load.php';
( new \Acme\ReportsPro\Licensing\LicenseManager( require __DIR__ . '/license-config.php' ) )->uninstall();
```

`register()` adds the license screen (**Settings → Acme Reports Pro – License**), the notices, the daily check, the Site Health information and test, the privacy policy text and the updater. Do not forget `wplc-catalog.json` in the same folder (chapter 3).

### Rules

- **`Update URI` is mandatory.** Without it WordPress asks wordpress.org about the add-on, and a plugin with the same folder name there would be offered as its update. Use the host of your license server; the path is free. If the header is missing, the updater does not register and an error notice names the reason.
- **`version` equals the `Version:` header**, and the release version on the server equals both.
- **Product key** = folder name = text domain, for example `acme-reports-pro`. It must exist in the catalog of your shop, and it is the top-level folder of every release ZIP. Never use the key of a free core plugin.
- **Option prefix:** options, hooks, cron events and form fields use the product key with `-` replaced by `_` (`acme_reports_pro_license`, `acme_reports_pro_license_state`, …); set `option_prefix` to choose another one.
- **Ask for entitlements on `init` or later**, not while the plugin file is loaded; otherwise filters added by the theme or later plugins come too late for that request.

### The server address

Give the license server a **host name of its own that never changes**, for example `licenses.example.com`, pointing to the shop, and use it for `server_url` and `Update URI`. The address is burnt into every shipped copy; if the shop moves to another domain, the old host name keeps answering. The address must be HTTPS without credentials, query or fragment; redirects are not followed. `fallback_urls` (tried in order when the first address cannot be reached) and `rest_base` (a server that does not answer under `/wp-json`, for example `https://licenses.example.com/index.php?rest_route=`) exist for emergencies.

Precedence: the filter `{prefix}_license_server_url`, then `server_url`. Requests go through `wp_safe_remote_request()`, which refuses hosts that resolve to private or loopback addresses. For a development server on a local address define `{PREFIX}_LICENSE_DEV` (for example `define( 'ACME_REPORTS_PRO_LICENSE_DEV', true );`) or return `true` from `{prefix}_license_allow_local_server`.

### The public key of the server

`public_keys` is a list of base64 Ed25519 public keys (`wp wplc signing-key --field=public_key` on the server). The library accepts an answer, and its stored snapshot, only with a valid signature of one of them; without a usable key the facade refuses to work (state `misconfigured`). Release builds pin the keys in the code. The filter `{prefix}_license_public_keys` only works in development builds (`{PREFIX}_LICENSE_DEV` true); `WP_DEBUG` does not enable it.

To rotate the server key: ship an add-on version that lists the old **and** the new key, wait until it is widely installed, then switch the server (chapter 9).

## Settings of `LicenseManager`

| Key | Required | Meaning |
| --- | --- | --- |
| `plugin_file` | yes | Main file of the add-on. |
| `product_key` | yes | See above. |
| `version` | yes | Installed version. |
| `server_url` | yes | HTTPS address of the license server. |
| `public_keys` | yes | Pinned public keys of the server. |
| `name` | | Display name in notices and on the license screen. |
| `text_domain` | | Text domain of the add-on. |
| `option_prefix` | | Prefix of options, hooks and form fields. |
| `network` | | Multisite: one activation for the whole network. Default `false`. |
| `update_uri` | | Value of the `Update URI` header; saves reading the plugin file. Recommended. |
| `channel` | | `stable` (default) or `beta`. |
| `core_version` | | Version of your free core plugin (string or closure); the server then holds back releases whose `requires_core` is higher. |
| `settings_parent` | | Admin menu of the license screen. Default `options-general.php` (`settings.php` in network mode). `false`: no screen; call `render_settings()` in your own page and set `settings_url`. |
| `settings_url` | | Admin address of your own page that calls `render_settings()`. |
| `capability` | | Who may manage the license. Default `manage_options` (`manage_network_options` in network mode). |
| `account_url` | | Customer account of your shop; appended to the grace and expiry notices. |
| `texts` | | Since 2.3.0: your own texts by code (an array code => text, or a callable returning one) in place of texts of the library, for a product whose license does not switch "premium features" on and off. The codes are the keys in `Messages.php`, for example `notice.expired` or `ui.access_until`. |
| `fallback_urls`, `rest_base` | | See "The server address". |

## Methods of the facade

| Method | Purpose |
| --- | --- |
| `register()` | Add all hooks. Call once when the add-on loads. |
| `is_entitled( string $key ): bool` | Feature gate. Reads one option, sends nothing, fails closed. |
| `status(): LicenseStatus` | Current state (below). `status()->entitlement( $key, $default )` returns a raw value such as a limit. |
| `activate( $license_key, $environment = '', $staging_parent_installation_id = '' )` | Activate; returns `LicenseStatus` or `WP_Error`. |
| `refresh( bool $force = true )` | "Check now". `false` respects the back-off. |
| `deactivate()` | Release this site on the server. |
| `move( $license_key )` | The site moved to a new address: keep the activation. |
| `uninstall()` | Release the activation and delete all licensing data of the add-on. |
| `render_settings()`, `run_form_action()`, `notice()`, `render_notice()`, `settings_url()`, `privacy_text()` | Building blocks for add-ons with their own admin page. |
| `error_text( WP_Error $error )` | Translated text of a library error; use it instead of `get_error_message()`. |

`is_entitled( $key )` is true when the license grants access and the value of the right is `true` or a number above zero. Decide by `is_entitled()` and `status()->access`, never by the raw license status or the plan name.

### States

| State | Access | Meaning |
| --- | --- | --- |
| `unlicensed` | no | No activation on this site. |
| `active` | yes | Paid and usable. |
| `grace` | yes | Renewal payment overdue or pending; usable until `access_until`. |
| `expired` | no | Paid period and grace period are over. |
| `suspended` | no | Blocked by the shop. |
| `revoked` | no | Ended for good. |
| `inactive` | no | Any other state without access (`pending`). |
| `deactivated` | no | The activation of this site was released (signed by the server). The key must be entered again. |
| `site_changed` | no | The site runs under another address than the activated one (for example a copy). |
| `stale` | no | The signed offline window is used up or the stored snapshot does not verify. Reason `clock_ahead`: the site's clock is ahead. |
| `misconfigured` | no | Server address, product key or public key is unusable. |

All dates of `LicenseStatus` are UTC (`Y-m-d H:i:s`).

## Offline window and errors

- The library stores the **signed snapshot** of the last answer (option `{prefix}_license_state`, not autoloaded) and verifies it again on every read. A snapshot edited in the database is void.
- The snapshot is valid until its signed `cache_expires_at`: 14 days after the answer, but never beyond `access_until` (the shop can change the 14 days with the server setting "Offline period of customer sites" or the filter `wplc_offline_cache_days`). A local clock that runs more than one day past that deadline makes the snapshot `stale` (tolerance filter `{prefix}_license_offline_tolerance`, at most 7 days).
- **Only a signed answer takes rights away.** Timeouts, DNS errors, `5xx`, `429`, firewall pages and unsigned answers leave the snapshot in force until its deadline; the check is repeated with a back-off (15 minutes, doubling up to one day). A signed "deactivated" answer drops snapshot and credentials. `401 activation_not_found`, `401 installation_secret_invalid` and `404 license_not_found` keep the snapshot and ask for the key again.
- Errors carry the server's code; the library shows its own translated text for it (`Messages.php`). Codes an activation form meets most often: `invalid_key`, `product_mismatch`, `license_expired`, `license_suspended`, `license_revoked`, `license_inactive`, `site_limit`, `staging_limit`, `development_limit`, `development_site_invalid`, `staging_parent_required`, `staging_parent_invalid`, `staging_site_invalid`, `rate_limited`.

## Activation, environments and copies of a site

- Activation with the same key on an activated site reuses the activation; a new site slot is only used when the server says the installation is unknown. The license key is sent once and never stored.
- The environment is derived from `wp_get_environment_type()`: `staging` → `staging`; `local` and `development` → `development`; everything else → `production`. The server accepts `development` only on local addresses and counts a `staging` request on an implausible host as a live site.
- **Staging:** a live site has one free staging site. When a site is copied, the copy notices the address change (`site_changed`), switches premium features off and sends **nothing** with the copied credentials. The administrator enters the key on the copy; with `WP_ENVIRONMENT_TYPE=staging` it becomes the free staging site of the live site.
- **Moved site:** after a real domain move the license screen offers "The site moved here: transfer the activation" behind a confirmation checkbox.
- **Deactivation** on the license screen also needs a confirmation checkbox ("I confirm that this site gives up its license …"). Own forms pass the confirmation as the third argument of `run_form_action( 'deactivate', '', $confirmed )`.

## Multisite

Decide once per add-on, before the first release; changing it later means every customer activates again.

| | `network` => `false` (default) | `network` => `true` |
| --- | --- | --- |
| Storage | options of each site | network options |
| Site slots | one per site that activates | one for the whole network |
| License screen | Settings of each site | Network Admin → Settings |
| Updates | one update entry for the network, fetched with the credentials of one activated site | with the network activation |

Use `network => true` for an add-on that is network-activated and licensed per WordPress installation; use the default when each site of a network is a customer of its own.

## Updates

`register()` hooks the updater when the add-on has a usable `Update URI`. It checks for updates during the WordPress update check (cached for twelve hours), fills "View details" with the changelog, fetches a fresh one-time link right before the download, accepts only HTTPS (also on redirects) up to 100 MB (filter `{prefix}_license_max_package_bytes`), compares the SHA-256 with the signed value and makes sure the unpacked folder contains the add-on's main file. A license without access sees that a version exists, with the reason in the plugin row and a link to the license screen.

## Screen, notices, daily check, hooks

- **License screen:** status, paid until, sites in use, environment and installation ID, last check and last error, key field, "Check now", "Remove license from this site". The key and the installation secret are never printed.
- **Site Health:** a section "{name} – License" under Tools → Site Health → Info and a test "License check" under Status.
- **Notices** for grace, expired, suspended, revoked, deactivated, copied site, stale snapshot, misconfiguration and a server unreachable for more than three days.
- **Daily check:** cron event `{prefix}_license_check`; if WP-Cron does not run, the next admin page view of a user who may manage the license catches up after 36 hours.

| Hook | Kind | Purpose |
| --- | --- | --- |
| `{prefix}_license_status_changed` | action | `( $state, $previous_state, LicenseStatus $status )` whenever the state changes. |
| `{prefix}_license_server_url` | filter | Address of the license server. |
| `{prefix}_license_public_keys` | filter | Pinned keys; development builds only. |
| `{prefix}_license_max_package_bytes` | filter | Size limit of an update package in bytes. |
| `{prefix}_license_settings_url` | filter | Address of the license screen. |
| `{prefix}_license_show_notice` | filter | `( bool $show, array $notice )`: suppress a notice. |
| `{prefix}_license_offline_tolerance` | filter | Seconds the local clock may run past `cache_expires_at` (default 86400, at most 604800). |
| `{prefix}_license_allow_local_server` | filter | Allow a server on a local or private address (development). |

## Uninstall

`uninstall()` tries to release the activation with a short timeout and then deletes the credentials, the snapshot, the update cache and the cron event, whatever the server answered. If the server cannot be reached, the site slot stays in use; the customer releases it in the account, or you with `wp wplc activation release <id>`.

## Privacy notes for your add-on

`register()` offers a paragraph for the WordPress privacy policy guide (`privacy_text()`). What the client sends: the site address, an installation ID created by the site, the product key and version, the WordPress and PHP versions, the environment type; on activation also the license key and the new installation secret; on update checks also the channel and the version of your core plugin. No data about visitors or customers of the site is sent. The installation secret is stored in plain text in the options of the customer site (`{prefix}_license`); do not print or log that option.

## Contract test against your server

Before you ship an add-on, run the contract test of the package against your own server with a test license of the product:

```sh
php bin/client-contract-test.php --server=https://licenses.example.com --product=acme-reports-pro \
    --key=WPLC-XXXX-XXXX-XXXX-XXXX --public-key=BASE64-PUBLIC-KEY
```

It runs the unchanged library without WordPress over real HTTPS: activate (as a development site `https://contract-test.local` by default, so no live site slot is used; the license must allow a development site), check the entitlements, check for an update (metadata only), verify the signatures (a changed payload and another key must be refused), and deactivate again, also after a failed step. Each step prints `PASS` or `FAIL`; exit code 0 when all passed, 1 otherwise, 2 for wrong arguments. Options: `--site`, `--environment`, `--version`, `--rest-base`, `--timeout`, `--allow-insecure` (http, local servers only); repeat `--public-key` during a key rotation. It needs the PHP sodium extension and never prints the key.

## Test checklist before the first release of an add-on

On a test shop with WP License Captain:

1. `wp wplc signing-key --field=public_key` → `public_keys` of the add-on.
2. Import the catalog file, create a test product with a plan (chapter 4), buy it with a test payment method. `wp wplc license show <ID or key>` shows the license.
3. Build the add-on ZIP (one top-level folder named like the product key, `Version:` equal to the release version) and publish it: `wp wplc release create --product=acme-reports-pro --version=1.0.1 --zip=build/acme-reports-pro-1.0.1.zip --publish --reason="test"`.
4. On a test site, define the server address (`define( 'ACME_REPORTS_PRO_LICENSE_SERVER', 'https://shop.test' );`), enter the key, look for updates.
5. Try: "Check now"; copy the database to a second host (`site_changed`) and activate the copy as staging; suspend the license in the shop (`suspended` after the next check); release the site in the customer account (`deactivated`); block the server address in the test site's firewall (features stay until `cache_expires_at`); update through the plugin list; let the license expire.
6. Free the test sites again: `wp wplc activation list --license=<id>` and `wp wplc activation release --license=<id>`.

## Implementing the protocol yourself

The library is the supported client. If you write your own client against API v1, the verification order of every answer is mandatory: check the HMAC of the received `payload` text with the installation secret, then the Ed25519 signature over `wplc-response-v2\n{installation ID in lower case}\n{payload}` with a pinned key, then decode `payload`, compare its `nonce` with the value you sent (for an activation: the installation ID) and use `payload.data`. Ignore the legacy top-level members `data`, `signature` and `signature_ed25519`, and refuse an answer without `payload`. All responses carry `Cache-Control: no-store`; error answers carry a stable `code` and a fixed English `message` for logs.
