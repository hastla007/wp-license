# Integrating a premium add-on with the license server

This is the guide for the developer of a premium add-on. Everything the add-on needs is in `src/Client/` of this repository: a small library for PHP 7.4 and WordPress 6.5 that needs neither WooCommerce nor any other class of the license server. The server side of the contract is specified in [API-V1.md](API-V1.md) and [UPDATES.md](UPDATES.md).

> **Server and client are separate.** WP License Captain Server is a separately sold product for vendors who want to license their own software. A WordPress product can implement the client directly against the API in [API-V1.md](API-V1.md), or use the PHP library in `src/Client/`; a dedicated client plugin such as License Captain Client is also an option. The client library used in Captain Pro add-ons serves only our own license checks and does not include or replace the server product. (Stated by the owner on 15 Nov 2026 and not worked out yet: today only this plugin and the library in `src/Client/` exist; there is no "License Captain Client" plugin, and the questions about selling the server to other vendors are open, see R-21 in [TODO.md](TODO.md).)

> **Where the client belongs.** The core plugin of each product is free, ungated and published on WordPress.org. It must not contain this library, an entitlement check, a custom updater or any call to the license server. Only the separate premium add-on, which is sold and delivered from the shop, carries it.

> **Starting a premium add-on?** [`examples/premium-addon-starter/`](../examples/premium-addon-starter/README.md) is a complete add-on skeleton with this client vendored under the add-on namespace (`bin/vendor-client.php`), a license settings page, a fail-closed entitlement helper on a signed 14-day offline cache, the updater with SHA-256 and size checks, uninstall cleanup, a privacy note, tests with server-generated signed fixtures, and `bin/init-addon.php` to create a product repository from it. Its tests run with `composer test:starter`, and e2e flow 12 runs it against a real server.

## The library

| File | Class | Job |
| --- | --- | --- |
| `LicenseManager.php` | `LicenseManager` | **The facade an add-on uses.** Status, feature gate, stored rights, daily check, notices, license screen, updater, uninstall. |
| `LicenseStatus.php` | `LicenseStatus` | Result of `LicenseManager::status()`. |
| `Updater.php` | `Updater` | Connects the update feed with the WordPress update system. Created and registered by the manager. |
| `LicenseClient.php` | `LicenseClient` | Transport: activation, signed requests, verification of answers. |
| `OptionStore.php` | `OptionStore` | Storage per site or per network. |
| `Messages.php` | `Messages` | All texts shown to the customer, by code. |
| `load.php` | | Requires the six files. |

All state is per `LicenseManager` instance. Nothing is static, so one copy of the classes can serve several add-ons.

## Getting the library into the add-on

Three add-ons can be active on one site, each with its own copy and possibly different versions. There are two ways to ship the copy.

**Recommended: a copy with the add-on's own namespace.** Run the export script in the build of the add-on, or once, and commit the result:

```sh
php bin/export-client.php --target=/path/to/voucher-captain-pro/includes/licensing \
    --namespace='VoucherCaptainPro\Licensing' --text-domain=voucher-captain-pro
```

It copies `src/Client/*.php`, replaces `namespace WPLC\Client;` by the given namespace and the text domain `wp-license` by the add-on's, and fails if a file still refers to `WPLC\Client`. The add-on then always runs exactly the version it was tested with, and `wp i18n make-pot` of the add-on finds the strings of `Messages.php`. [php-scoper](https://github.com/humbug/php-scoper) with a prefix for `WPLC\Client` gives the same result if the add-on already uses it.

**Without the script: an unchanged copy.** Every class is declared inside `if ( ! class_exists( ... ) )`, so two add-ons with the same unchanged files do not end in "Cannot declare class". The limits of this way:

- The copy that is loaded **first** is used by all add-ons, also when it is the older one. WordPress loads plugins in the alphabetical order of their folders.
- Every class has a `VERSION` constant (`LicenseManager::VERSION`, currently `2.0.2`). An add-on that needs a newer copy than the one that won can only notice it (`version_compare( LicenseManager::VERSION, '2.1.0', '<' )`) and show a message; it cannot replace a class that is already declared.
- The strings keep the text domain `wp-license`. They are translated through the `text_domain` setting only if the add-on's language files contain them; `make-pot` does not find them on its own.

Use the unchanged copy for experiments only.

## A complete minimal add-on

Three files of glue. The add-on's own feature code is not part of the count.

`voucher-captain-pro/license-config.php`, shared by the main file and `uninstall.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

return array(
	'plugin_file' => __DIR__ . '/voucher-captain-pro.php',
	'product_key' => 'voucher-captain-pro',
	'version'     => '1.0.0',
	'name'        => 'Voucher Captain Pro',
	'text_domain' => 'voucher-captain-pro',
	'server_url'  => defined( 'VOUCHER_CAPTAIN_PRO_LICENSE_SERVER' ) ? VOUCHER_CAPTAIN_PRO_LICENSE_SERVER : 'https://licenses.example.com',
	'public_keys' => array( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=' ), // wp wplc signing-key --field=public_key
	'account_url' => 'https://shop.example.com/my-account/licenses/',
);
```

`voucher-captain-pro/voucher-captain-pro.php`:

```php
<?php
/**
 * Plugin Name: Voucher Captain Pro
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: voucher-captain-pro
 * Update URI: https://licenses.example.com/voucher-captain-pro
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/licensing/load.php';

function voucher_captain_pro_license(): \VoucherCaptainPro\Licensing\LicenseManager {
	static $manager = null;
	if ( null === $manager ) {
		$manager = new \VoucherCaptainPro\Licensing\LicenseManager( require __DIR__ . '/license-config.php' );
	}
	return $manager;
}
voucher_captain_pro_license()->register();

// A premium feature, switched on once all plugins and the theme are loaded.
add_action(
	'init',
	static function () {
		if ( voucher_captain_pro_license()->is_entitled( 'voucher.example_feature' ) ) {
			require_once __DIR__ . '/includes/qr-redemption.php';
		}
	}
);
```

Asking `is_entitled()` while the plugin file is loaded works too, but then filters that the theme or later plugins add (such as `{prefix}_license_server_url`) come too late for this request.

`voucher-captain-pro/uninstall.php`:

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/licensing/load.php';
( new \VoucherCaptainPro\Licensing\LicenseManager( require __DIR__ . '/license-config.php' ) )->uninstall();
```

That is all. `register()` adds the license screen (**Settings → Voucher Captain Pro – License**), the notices, the daily check, the privacy text and the updater.

### Rules for the header and the settings

- **`Update URI` is mandatory.** Without it WordPress asks wordpress.org about the add-on, and a plugin with the same folder name published there would be offered as its update. The host of the URI must not be wordpress.org; the path is free. Use the stable host name of the license server (see below). If the header is missing the updater does not register and an error notice names the reason.
- **`version` must equal the `Version:` header.** The release server refuses a package whose header differs from the release version.
- **Product key.** One key per add-on, in the form `^[a-z0-9_-]{1,100}$`, never the key of the free core plugin. Rule: the product key is the folder name of the add-on and its text domain, for example `voucher-captain-pro`. It must exist in the product catalog of the shop, and it is the folder the release ZIP must contain. The three keys are fixed (decision of 14 Nov 2026): `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`. The feature keys inside a license keep their short prefixes `voucher.`, `pass.` and `affiliate.`; the product editor offers the matching feature group for each of these product keys.
- **Option prefix.** Options and hooks start with the product key with underscores (`voucher_captain_pro`); set `option_prefix` to choose another one.

### Where the server address comes from

Order of precedence: the filter `{prefix}_license_server_url`, then the `server_url` setting, which in the example reads a constant from `wp-config.php` and falls back to the built-in address. The filter is read when the license is first needed in a request (not when the `LicenseManager` is created), so it also works from another plugin or the theme as long as the add-on does not ask for the license while its file is loaded; a must-use plugin or the constant always works.

The address is burnt into every shipped copy. Before the first release, give the license server a **host name of its own that never changes**, for example `licenses.example.com`, pointing to the shop, and use it for `server_url` and for `Update URI`. If the shop moves to another domain later, the old host name keeps answering and no customer site has to be updated first. The address must be HTTPS without credentials, query or fragment; redirects are not followed. Two further settings exist for emergencies: `fallback_urls` (tried in order when the first address cannot be reached at all) and `rest_base` (when the server does not answer under `/wp-json`, for example `https://licenses.example.com/index.php?rest_route=`).

### The public key of the server

`public_keys` is a list of base64 Ed25519 public keys (`wp wplc signing-key --field=public_key` on the server). The library accepts an answer, and its stored copy, only with a valid signature of one of them over the bytes of the received `payload`. The facade refuses to work without a usable key (state `misconfigured`).

**The keys cannot be changed by another plugin.** The filter `{prefix}_license_public_keys` exists for development builds only: it is applied when the constant `{PREFIX}_LICENSE_DEV` (the option prefix in upper case, e.g. `define( 'VOUCHER_CAPTAIN_PRO_LICENSE_DEV', true );`) is defined and true, and ignored otherwise. `WP_DEBUG` does not enable it, because it is on on many live sites. Release builds pin their keys in the code and never define the constant; a plugin that adds a key of its own through the filter then signs nothing.

To rotate the server key: ship an add-on version that lists the old **and** the new key, wait until it is installed widely, then switch the server. Sites that still pin only the old key keep their rights until the signed deadline of their last snapshot and then stop with the state `stale`; that is why the order matters. Set the key on the server with `WPLC_RESPONSE_SIGNING_KEY` so that it survives a database restore ([SECURITY-OPERATIONS.md](SECURITY-OPERATIONS.md)).

## Settings of `LicenseManager`

| Key | Required | Meaning |
| --- | --- | --- |
| `plugin_file` | yes | Main file of the add-on. |
| `product_key` | yes | See above. |
| `version` | yes | Installed version. |
| `server_url` | yes | HTTPS address of the license server. |
| `public_keys` | yes | Pinned keys of the server. |
| `name` | | Display name in notices and on the license screen. |
| `text_domain` | | Text domain of the add-on. |
| `option_prefix` | | Prefix of options, hooks and form fields. |
| `network` | | Multisite: one activation for the network. Default `false`. See [Multisite](#multisite). |
| `update_uri` | | Value of the `Update URI` header; saves reading the plugin file (without it the header is read once per request and kept in memory). Recommended. |
| `channel` | | `stable` (default) or `beta`. |
| `core_version` | | Version of the free core plugin, as a string or a closure. The server then holds back releases whose `requires_core` is higher. |
| `settings_parent` | | Admin menu of the license screen. Default `options-general.php` (`settings.php` in network mode). `false`: no screen; call `render_settings()` inside the add-on's own page and set `settings_url`. |
| `settings_url` | | Full admin address of the add-on's own page that calls `render_settings()` (for `settings_parent => false`). The form returns there after an action and notices link to it. Without it, `settings_url()` is empty: notices carry no link and the form returns to the page that sent it. The filter `{prefix}_license_settings_url` still applies. |
| `capability` | | Who may manage the license. Default `manage_options` (`manage_network_options` in network mode). |
| `account_url` | | Customer account of the shop; appended to the grace and expiry notices. |
| `fallback_urls`, `rest_base` | | See above. |
| `store`, `transport`, `clock` | | Test doubles: an `OptionStore`, a replacement for `wp_remote_request()` (also used for the package download), a replacement for `time()`. |
| `signature_verifier` | | Test double: replacement for `sodium_crypto_sign_verify_detached()`; `false` simulates a PHP without any Ed25519 implementation. |

## Methods of the facade

| Method | Purpose |
| --- | --- |
| `register()` | Add all hooks. Call once when the add-on loads. |
| `is_entitled( string $key ): bool` | Feature gate. Reads one option, sends nothing, fails closed. |
| `status(): LicenseStatus` | Current state, see below. |
| `activate( $license_key, $environment = '', $staging_parent_installation_id = '' )` | Activate; returns `LicenseStatus` or `WP_Error`. |
| `refresh( bool $force = true )` | "Check now". `false` respects the back-off. |
| `deactivate()` | Release this site. |
| `move( $license_key )` | The site moved to a new address: keep the activation. |
| `uninstall()` | Release the activation and delete all data of the add-on's licensing. |
| `render_settings()`, `run_form_action()`, `notice()`, `render_notice()`, `settings_url()`, `privacy_text()` | Building blocks of the admin screen for add-ons that bring their own page. |
| `client()`, `updater()` | The lower layers. |
| `LicenseManager::error_class( WP_Error )` | `lock`, `reactivate` or `keep`. |

### `LicenseStatus`

`state` is one of:

| State | `access` | Meaning |
| --- | --- | --- |
| `unlicensed` | no | No activation on this site. |
| `active` | yes | Paid and usable. |
| `grace` | yes | Renewal payment overdue or pending; usable until `access_until`. |
| `expired` | no | Paid period and grace period are over. |
| `suspended` | no | Blocked by the shop. |
| `revoked` | no | Ended for good. |
| `inactive` | no | Any other state without access (`pending`). |
| `deactivated` | no | The activation of this site was released (signed by the server). The key must be entered again. |
| `site_changed` | no | The site runs under another address than the activated one. |
| `stale` | no | The signed offline window is used up, or the stored snapshot does not verify. |
| `misconfigured` | no | Server address, product key or public key is unusable. |

Other fields: `access`, `license_status` (the raw value of the server), `entitlements`, `paid_until`, `access_until`, `grace_until`, `cache_expires_at`, `environment`, `site_limit`, `site_count`, `installation_id`, `last_checked`, `last_error`, `last_error_at`, `needs_reactivation`. All date-time strings are **UTC** in the form `Y-m-d H:i:s`; `last_checked` and `last_error_at` are Unix times.

`is_entitled( $key )` is true when `access` is true and the value of the right is `true` or a number above zero. `entitlement( $key, $default )` returns the raw value (a limit, a string). Decide by `access` and `is_entitled()`, never by `license_status`.

An expired or missing license only switches premium features off. It must never delete or hide data the customer created, and never change what the free core plugin does.

## How rights are stored

- The manager stores the **signed snapshot** of the last answer (`payload` with its HMAC and the Ed25519 signature of the server) in the option `{prefix}_license_state`. It is not autoloaded and not a transient, so a flushed object cache does not switch the premium features off.
- On every read the snapshot is verified again: the HMAC with the installation secret, and the server signature with the pinned keys. A snapshot edited in the database is void (state `stale`). Both checks run on every read; nothing stored next to the snapshot can skip the server signature. Without the sodium extension WordPress' `sodium_compat` verifies, which takes about 20 ms per check: its result is then remembered for the rest of the request (for all add-ons using the library) and for a day in the object cache, or in a transient without a persistent object cache, under a key derived from signature, message and key. The stored value is an HMAC with `AUTH_KEY` and `AUTH_SALT` from `wp-config.php`, so a database edit cannot mark a forged snapshot as verified; without those constants only the per-request memo is used. The HMAC with the installation secret is checked on every read. `client()->uses_native_ed25519()` tells whether ext-sodium is used. On a PHP with neither, the client fails closed: no answer and no snapshot is accepted (`wplc_ed25519_unavailable`, state `stale`), because the HMAC alone proves nothing to someone who can read the installation secret in the same database. The activation itself is kept.
- The snapshot is valid until its signed **`cache_expires_at`**. This is the offline window: 14 days after the answer, but never beyond `access_until`. The deadline is read from the signed payload. It is not restarted by failed attempts, and the add-on must not shorten it to `paid_until` on its own (that would lock honest customers out during the grace period).
- Time is measured from the signed `server_time` inside the verified payload plus the local time that passed since it was stored (`fetched_at`). A wrong time zone or a constant clock error on the customer server therefore does not matter. The unsigned helper fields in the option can only make the result later: the highest local time seen (`clock_high`, so turning the clock back does not extend the window) and the highest signed server time seen. A `fetched_at` more than five minutes in the future (an edited option, or the clock turned back before the last answer, for example by an NTP correction) voids the snapshot (state `stale`) until a new signed answer arrives. The first page view that notices it schedules a one-off background check through WP-Cron right away (event `{prefix}_license_check`, at most every 15 minutes, transient `{prefix}_license_recheck`), so the features are back within minutes instead of after the next daily check; the admin catch-up asks at once as well. Limit: someone who can write the database and keeps resetting `fetched_at` can stretch the window again and again; no local-only check prevents that, but a single edit no longer unlocks anything for good.
- An answer whose `server_time` is more than five minutes older than one already accepted is refused (`wplc_response_stale`).

## What an error does

**Only a signed answer takes rights away.** Everything else leaves the snapshot in force until its deadline.

| Class | Which answers | Effect |
| --- | --- | --- |
| signed status | `200` with a verified payload, whatever it says: `access: false`, `expired`, `suspended`, `revoked`, `past_due` | Replaces the snapshot at once. The credentials stay, so a renewal is picked up by the next check. |
| `lock` | Verified `activation_status: deactivated` in a `200`, or `403 activation_deactivated` whose `data.signed` verifies | Snapshot **and** credentials are dropped; state `deactivated`. |
| `reactivate` | `401 activation_not_found`, `401 installation_secret_invalid`, `404 license_not_found` | Snapshot stays until `cache_expires_at`; `needs_reactivation` is set and a notice asks for the license key. |
| `keep` | Everything else: timeouts, DNS errors, `5xx`, `429`, pages of a firewall, `401 invalid_request_signature`, `request_expired`, an answer that does not verify, an unsigned `403` | Snapshot stays until `cache_expires_at`; the check is repeated with a back-off. |

Back-off after a failed check: 15 minutes, doubled with every further failure up to one day, never shorter than `Retry-After`. The daily check and the catch-up in the admin respect it; the button "Check now" does not.

`request_expired` is handled before it becomes an error: the client reads `data.server_time`, remembers the difference to the local clock and repeats the request once. `request_replayed` is repeated once with a new nonce.

### Error codes

The server's `message` is a fixed English text for logs. The library never shows it; it shows its own translated text for the `code` (`Messages.php`) and keeps the original in the error data as `server_message`. All `data` of the server is passed on in the `WP_Error` (`status`, `site_limit`, `site_count`, `development_limit`, `fields`, `license_status`, `retry_after`, ...).

Codes of the library itself:

| Code | Meaning |
| --- | --- |
| `wplc_configuration_invalid` | Server address, product key or pinned key unusable. |
| `wplc_license_key_missing` | No key was entered. |
| `wplc_not_activated` | No credentials on this site. |
| `wplc_site_changed` | The site address differs from the activated one. No request was sent. |
| `wplc_credentials_not_saved` | The credentials could not be written to the database. No request was sent (pending step), or the activation exists on the server and the next attempt finds it again. |
| `wplc_connection_failed` | No HTTP answer from any server address; `transport_code` and `transport_message` carry the WordPress HTTP error. |
| `wplc_blocked_by_firewall` | An HTML page arrived instead of JSON: a WAF, a bot challenge, a maintenance page. |
| `wplc_http_error` | An error status without a JSON error body. |
| `wplc_response_invalid` | Status 2xx, but not a readable envelope. |
| `wplc_signature_invalid` | `payload` or its HMAC is missing or wrong. |
| `wplc_server_signature_invalid` | The Ed25519 signature of the server is missing or does not match a pinned key. |
| `wplc_ed25519_unavailable` | This PHP has neither ext-sodium nor `sodium_compat`; nothing is accepted (fail closed). |
| `wplc_nonce_mismatch` | The answer belongs to another request. |
| `wplc_product_mismatch` | The signed data is for another product. |
| `wplc_response_stale` | The answer is older than one already accepted. |
| `wplc_backoff` | `refresh( false )` was skipped because of the back-off. |
| `wplc_update_failed`, `wplc_update_license`, `wplc_update_unavailable`, `wplc_update_insecure`, `wplc_update_too_large`, `wplc_update_checksum`, `wplc_update_folder` | Updater, see below. |

Codes of the server are listed in [API-V1.md](API-V1.md#error-codes). Those an activation form meets most often: `invalid_key`, `product_mismatch`, `license_expired`, `license_suspended`, `license_revoked`, `license_inactive`, `site_limit`, `staging_limit`, `development_limit`, `development_site_invalid`, `staging_parent_required`, `staging_parent_invalid`, `staging_site_invalid`, `rate_limited`.

## Activation

What `activate()` does, in this order:

1. **Working credentials are reused.** If the site is activated with the same key, a signed check is sent and its result returned; no second site slot is used.
2. **A new activation only when the server says so.** Only `activation_not_found`, `installation_secret_invalid` or a signed "deactivated" lead to a new installation. A timeout or any other error of that check is returned as it is.
3. **Pending installation.** The new installation ID and secret are written to `{prefix}_license_pending` *before* the request. If the answer is lost, the next attempt sends the same values, and the server answers idempotently instead of counting a second site. A definite refusal (`4xx` with a code) removes the pending values.
4. **Another key.** If the site is activated with a different key, the old activation is released first, so its slot does not stay in use.
5. The license key is sent once and is never stored; only its SHA-256 is kept to recognize "the same key".

### Environments and staging

`environment` is `production`, `staging` or `development`. When it is not given, `LicenseClient::detect_environment()` derives it from `wp_get_environment_type()`: `staging` → `staging`, `local` and `development` → `development`, everything else → `production`. The server accepts `development` only on local addresses and may count a `staging` request on an implausible host as a live site; the state shows the environment the server stored.

Staging flow, as the customer sees it:

1. The live site is activated.
2. The site is copied to the staging host, including the database.
3. On the copy the add-on notices that the address changed. The state is `site_changed`, premium features are off, and **no request is sent with the copied credentials**: no check, no update, no release. The copy cannot end the activation of the live site.
4. The administrator opens the license screen of the copy and enters the key ("Activate this address"). If the copy runs with `WP_ENVIRONMENT_TYPE=staging`, the copied installation ID is sent as `staging_parent_installation_id`, and the copy becomes the free staging site of the live site. Otherwise it is activated as a further live site, or the server answers `site_limit`.
5. "Remove the copied activation data from this site" deletes the local data only.

A staging site set up from scratch needs the installation ID of the live site; it is shown on the license screen of the live site. Pass it as the third argument of `activate()`.

### The same site under a new address

A change of scheme (`http` → `https`), of a leading `www.` or of the case of the path is not a change; the comparison follows the server's rule for "the same site". The address is the stored `home` option of the site (network mode: domain and path of the network), read without the `home_url` filter: multilingual plugins with one domain per language (WPML, Polylang) and domain mapping plugins filter `home_url()` per request, and a second language domain must not count as a copy. After a real move to another domain the state is `site_changed`. The license screen then shows both addresses (activated for, address of this site) and offers "The site moved here: transfer the activation" (`move()`) behind a checkbox "I confirm that the site moved and no longer runs at …"; without the confirmation nothing is sent (`run_form_action( 'move', $key, $confirmed )`). The warning says that the site at the old address loses the license and that a staging copy should be activated for its own address instead. The activation request is then repeated with the stored installation ID and secret, and the server stores the new address instead of counting a second site. A staging site cannot move to a host that is unrelated to its live site (`staging_site_invalid`).

### Deactivation

`deactivate()` sends a signed `DELETE` with the current site address. The local data is removed after the signed confirmation, or on a signed refusal (`403 activation_deactivated` whose `data.signed` verifies against the pinned keys; result `status: removed_locally`). Every other error keeps the credentials and is returned, so that the release can be repeated; this includes an unsigned `site_mismatch` or `activation_not_found`, which anyone between the site and the server could send. An add-on that wants a "remove anyway" button calls `client()->forget()` (a new activation with the key also replaces credentials the server no longer knows). If the host or a firewall refuses the `DELETE` method (HTML page, `405`, `501`), the request is repeated as `POST` with `X-HTTP-Method-Override: DELETE`.

## Multisite

Decide once per add-on, before the first release; changing it later means every customer activates again.

| | `network` => `false` (default) | `network` => `true` |
| --- | --- | --- |
| Storage | `get_option()` of each site | `get_site_option()` of the network |
| Activated address | `home` option of the site (unfiltered) | domain and path of the network (`network_home_url()` without filter) |
| Site slots | one per site of the network that activates | one for the whole network |
| License screen | Settings of each site | Network Admin → Settings |
| Who manages | `manage_options` | `manage_network_options` |
| Daily check | on every activated site | on the main site |
| Updates | one update entry for the network, fetched with the credentials of one activated site (see below) | with the network activation |
| License form | `wp-admin/admin-post.php` of the site | `wp-admin/admin-post.php` of the main site, back to Network Admin → Settings |

Updates with per-site activations: WordPress keeps a single update list for the whole network, and its check runs on whichever site's cron or admin screen comes first. The updater therefore caches the update data once for the network (`get_site_option( '{prefix}_update_meta' )`) and always asks with the credentials of the same site: the main site when it is activated, otherwise the activated site it used last time (`{prefix}_update_site`), otherwise the activated site with the lowest ID. The download uses the same site. An activation or deactivation on any site starts the choice anew. If no site of the network is activated, no update is offered and nothing is sent.

Use `network => true` for an add-on that is network-activated and licensed per installation of WordPress. Use the default when each site of a network is a customer of its own (agencies).

## Updates

`register()` hooks the updater when the add-on has a usable `Update URI` header.

1. **Periodic check.** WordPress calls the filter `update_plugins_{host of the Update URI}`. The updater asks `POST /updates/check` with `download: false` (the server stores no download grant), plus `channel`, `wp_version`, `php_version` and `core_version`. The answer is cached in `{prefix}_update_meta` (a network option on a multisite with per-site activations): twelve hours after a success, one hour after a failure, with a five-second timeout. WordPress runs the filter several times per check; only the first costs a request. "Check again" on the updates screen skips the cache.
2. **What WordPress gets.** `slug`, `plugin`, `version`/`new_version`, `tested`, `requires`, `requires_php`, `icons`, `url` and a **stable placeholder** as `package`, because a real link is valid for five minutes and for one download, and WordPress keeps the entry for twelve hours. An up-to-date add-on is listed under `no_update`, which the plugin list needs to show the auto-update switch.
3. **"View details".** `plugins_api` is answered from the cached data: name, version, homepage, last update, requirements and the `changelog` section with all versions since the installed one.
4. **Download.** In `upgrader_pre_download` the placeholder is replaced: a fresh check with `download: true`, download of `package`, comparison of the SHA-256 of the file with the signed `sha256`. A file that does not match is deleted and the update ends with `wplc_update_checksum`. The download only uses `https://` (plain `http://` only for `localhost`), on the first request and on every redirect (at most five; `wplc_update_insecure` otherwise), and stops at 100 MB (filter `{prefix}_license_max_package_bytes`, in bytes; `wplc_update_too_large`). There is no `HEAD` request first, because pre-signed storage URLs are signed for `GET` only; the response is streamed with `limit_response_size` and a larger file is deleted. This also runs for automatic updates.
5. **Folder.** In `upgrader_source_selection` the unpacked folder must contain the main file of the add-on (otherwise `wplc_update_folder`); a different folder name is corrected.
6. **Afterwards.** `upgrader_process_complete` forgets the cached feed.

A license without access still sees that a version exists: the server answers signed `license_active: false` with a `reason`. The entry then has no package, WordPress shows "automatic update is unavailable", and the updater adds the reason in the plugin row (`in_plugin_update_message-{file}`) with a link to the license screen. A version that is held back because of `requires_php`, `requires_wp` or `requires_core` is explained the same way. Without an activation, and on a copy of the site, the updater sends nothing.

## Screen, notices, daily check

- **License screen:** status, paid until, sites in use, environment with the installation ID, last check, last error; a key field with "Activate"; "Check now"; "Remove license from this site". The key and the installation secret are never printed. The form posts to `admin-post.php` (action `{prefix}_license`) with a nonce and the capability check.
- **Notices** for `grace`, `expired`, `suspended`, `revoked`, `inactive`, `deactivated`, `site_changed`, `stale`, `misconfigured`, for "the server no longer knows this site" and for a server that could not be reached for more than three days (with the date at which the features stop). The hint for a site without a license appears on the dashboard and the plugin list only.
- **Daily check:** the cron event `{prefix}_license_check` is scheduled at a random time of day while the site is activated and removed on deactivation of the plugin. **If WP-Cron does not run**, the next admin page view of a user who may manage the license catches up after 36 hours, and after a failed attempt as soon as the back-off allows.

Hooks (the prefix is the option prefix of the add-on):

| Hook | Kind | Purpose |
| --- | --- | --- |
| `{prefix}_license_status_changed` | action | `( $state, $previous_state, LicenseStatus $status )` whenever the state changes. |
| `{prefix}_license_server_url` | filter | Address of the license server. |
| `{prefix}_license_public_keys` | filter | Pinned keys; **only** when `{PREFIX}_LICENSE_DEV` is defined and true (see [The public key of the server](#the-public-key-of-the-server)). |
| `{prefix}_license_max_package_bytes` | filter | Size limit of an update package in bytes (default 104857600). |
| `{prefix}_license_settings_url` | filter | Address of the license screen. |
| `{prefix}_license_show_notice` | filter | `( bool $show, array $notice )`: suppress a notice. |

## Uninstall

`uninstall()` tries to release the activation with a short timeout and then deletes the credentials, the snapshot, the update cache and the cron event, whatever the server answered. On a multisite with per-site storage it does so for every site, in batches of 100 (`get_sites()` with `offset`), and stops asking the server after 20 seconds; the local data of the remaining sites is still deleted. If the server cannot be reached, the site slot stays in use; the customer releases it in the customer account, or the shop with `wp wplc activation release <id>`.

## Privacy and security notes for the add-on

**Text for the privacy policy.** `register()` offers this paragraph in the privacy policy guide of WordPress (`privacy_text()` returns it):

> *Voucher Captain Pro* checks its license and looks for updates at *licenses.example.com*. When the license is activated, once a day and when an update is checked or downloaded, this site sends: its address (URL), an installation ID created by this site, the product key of the plugin, the type of environment (live, staging or development site), the license key (once, on activation), for a staging site the installation ID of its live site, and the versions of the plugin, of the base plugin, of WordPress and of PHP. The license server sees the IP address of this web server. No data about visitors or customers of this site is sent. Legal basis: our legitimate interest in the use of licensed software and in security updates (Art. 6(1)(f) GDPR).

What is sent, exactly: `site_url`, `installation_id`, `product_key`, `product_version`, `wp_version`, `php_version`, `environment`; on activation also `license_key` and the new installation secret; on update checks also `channel` and `core_version`. WordPress adds its default `User-Agent`, which contains the WordPress version and the site address. The versions are used to offer only updates the site can run and for support.

**The installation secret is stored in plain text** in `wp_options` of the customer site (`{prefix}_license`). Whoever can read that database or a backup of it can sign requests as this installation: download the paid package and release the activation of that same address. It cannot be used from another address, and it does not reveal the license key. Do not print or log the option. Rotating it means deactivating and activating again.

## Translations

All texts live in `Messages.php` with their English source. After the export they carry the add-on's text domain and are part of its `.pot`. Server messages are never shown to the customer.

## Quick start: test product, test license, test release

On a test shop with WP License Captain and WooCommerce:

1. **Signing key.** `wp wplc signing-key --field=public_key` prints the key for `public_keys`.
2. **Test product.** Create a WooCommerce product and map it to a license plan in the product editor: the product key of the add-on (`voucher-captain-pro`), a plan key, the billing interval, the live-site limit and the features. [CATALOG-CSV.md](CATALOG-CSV.md) describes the import of many plans.
3. **Test license.** Buy the product with a test payment method and complete the order. The license key is shown in the customer account and sent by e-mail; `wp wplc license show <license ID, key or UUID>` shows the license on the server.
4. **Test release.** Build the ZIP with exactly one top-level folder named like the product key and a `Version:` header equal to the release version, then:

   ```sh
   wp wplc release create --product=voucher-captain-pro --version=1.0.1 --zip=build/voucher-captain-pro-1.0.1.zip --publish --reason="test"
   ```

5. **Add-on.** Define the server address of the test shop in `wp-config.php` of the customer test site (`define( 'VOUCHER_CAPTAIN_PRO_LICENSE_SERVER', 'https://shop.test' );`), enter the key on the license screen, then look for updates.
6. **Reset.** `wp wplc activation list --license=<id>` and `wp wplc activation release --license=<id>` free the site slots again; `wp wplc activation release --license=<id> --environment=development` only the development sites.

Things to try before a release of the add-on: activate, "Check now", copy the database to a second host (state `site_changed`), activate the copy as staging, suspend the license in the shop (state `suspended` after the next check), release the site in the customer account (state `deactivated`), block the server address in the firewall of the test site (features stay until `cache_expires_at`), publish a release and update through the plugin list, let the license expire and look at the plugin row.

## The transport class

`LicenseManager` is built on `LicenseClient`; add-ons rarely need it directly.

```php
new LicenseClient( string $server_url, string $product_key, string $product_version, string $option_key, array $args = array() )
```

`$args`: `public_keys`, `text_domain`, `fallback_urls`, `rest_base`, `timeout`, `network`, `allow_insecure` (accept an `http://` server on a local development machine), `store`, `transport`, `clock`, `signature_verifier` (tests). Without `public_keys` only the HMAC is checked; this mode exists for tests, the facade does not allow it. `can_verify_server_signature()` tells whether this PHP has an Ed25519 implementation at all.

| Method | Purpose |
| --- | --- |
| `activate( $license_key, $environment = '', $staging_parent_installation_id = '', $force = false )` | See [Activation](#activation). `$force` skips step 1. |
| `move( $license_key )` | Repeat the activation with the stored installation for the current address. |
| `check_entitlements()` | Signed status and rights. Sends `site_url`. |
| `check_update( bool $download = false, array $extra = array() )` | Update feed. `$extra`: `channel`, `core_version`, `target_version`. |
| `deactivate()`, `forget()` | Release on the server; remove the local data only. |
| `is_activated()`, `installation_id()`, `environment()`, `activated_site_url()`, `site_matches()`, `is_configured()` | State. |
| `last_envelope()`, `verify_snapshot( array $envelope )` | The signed envelope of the last answer, and its verification later. |
| `LicenseClient::verified_data( $envelope, $secret, $expected_nonce = '', $public_keys = array(), $installation_id = '' )` | Pure verification of an envelope; returns the data or an error code. |
| `LicenseClient::detect_environment()`, `LicenseClient::site_key( $url )` | Helpers. |

Verification order, mandatory for every client of this API: the HMAC of the received `payload` text; the Ed25519 signature over `wplc-response-v2\n{installation UUID, lower case}\n{payload}` with a pinned key; then decode `payload`, compare `nonce` with the value sent (for an activation: the installation ID) and use `payload.data`. The top-level `data`, `signature` and `signature_ed25519` are legacy members and are ignored; an answer without `payload` is refused. Never run the Ed25519 check over the top-level `data`.

Timeouts: 5 seconds in web requests, 15 seconds in WP-Cron, WP-CLI and for the check right before a download; `timeout` overrides both. TLS verification is on, redirects are not followed.

Stored credentials (`{prefix}_license`, not autoloaded): `installation_id`, `installation_secret`, `activation_id`, `license_key_hash`, `product_key`, `site_url`, `environment`, `activated_at`, `clock_offset`.

## How this is tested

- `tests/Client/`: unit tests against a fake server that signs like the real one. One case for every row of the error table, signature checks (valid, tampered, wrong key, missing, legacy only, replayed), copies of a site, the pending activation, clock errors, back-off, and the export script.
- `tests/integration/Client*IntegrationTest.php`: the library against the REST server of the test process with a real database: activate, check, update check, download, deactivate, staging copy, move, key change, signed refusals, and an update that the WordPress upgrader installs from a release ZIP in the local package storage. HTTP itself is bridged in the process (`tests/integration/ClientServerBridge.php` names what that leaves out).

Not covered by any test here: a real add-on in a real shop over real HTTP and TLS, WP-Cron timing on a live host, and PHP without the sodium extension. The client integration tests also pass on a multisite test install (`WP_MULTISITE=1`; there the network-mode form, its `admin-post.php` target and the redirect to Network Admin are exercised); the per-site update source on a network is covered by unit tests only. Run the quick start above before the first release of each add-on.

**Helpers kept from client 1.x.** `LicenseClient` 2.0 still offers the methods add-ons built on the 1.x API use: `check_entitlements_envelope()` (returns `array( 'data' => …, 'envelope' => … )` for an offline cache), `verify_cached_envelope( array $envelope )` (re-verifies a stored envelope like `verify_snapshot()`) and the static `LicenseClient::verified_server_data( array $envelope, string $installation_id, array $public_keys, string $expected_nonce )`. Since 2.0.2 that helper checks the payload signature `payload_signature_ed25519` (context `wplc-response-v2`, which covers the request nonce) and requires the nonce of the request (`wplc_nonce_mismatch` without it); the legacy `signature_ed25519` (context `wplc-response-v1`, no nonce, so a recorded answer could be replayed) is never accepted. The server still sends it for copies of client 1.x. New add-ons use the `LicenseManager` facade.
