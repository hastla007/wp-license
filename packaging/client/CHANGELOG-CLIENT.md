# Changelog of the license client library

Changes of the library in `src/Client` (namespace `WPLC\Client`), by library version. The version is the `VERSION` constant of every class (`LicenseManager::VERSION`). Changes of the license server are in the changelog of the server plugin.

Every version works with every server that answers API v1 (see "Compatibility" in `README.md`). A line marked **Action** needs a change in the add-on.

## 2.4.1

- Fixed: Enter in the key field of the license screen activates (CP-P5-08, reported by captain-pro). On an activated site the key field was in one form with "Save" (beta box), "Check now" and "Remove license from this site", and a browser sends the first submit button of a form on Enter, so Enter saved the channel or checked instead of activating the new key. The key field, "Activate" and "Type of this site" are now a form of their own (same `admin-post.php` action and nonce; `handle_settings_form()` and `run_form_action()` unchanged). The other buttons work by keyboard as before. The screens before the activation and of a copy of the site already had "Activate" as their first button.
- Fixed: the catch-up in the admin no longer waits for the license server (CP-P6-09, reported by captain-pro). When the daily check was overdue or a failed check was due again, the next admin page view asked the server synchronously; with an unreachable server each add-on held the page up to its HTTP timeout (six add-ons: up to 30 seconds). `catch_up()` now schedules a one-off event `{prefix}_license_check` (at most every 15 minutes, transient `{prefix}_license_recheck`, as the recheck after a clock correction) and starts WP-Cron in the background with `spawn_cron()`, at most once per request for all add-ons that embed the library (`$GLOBALS['wplc_client_cron_spawned']`, new constant `LicenseManager::CRON_SPAWNED_GLOBAL`; the name is the same in every exported copy). Not with `DISABLE_WP_CRON` (the system cron runs the event) or `ALTERNATE_WP_CRON` (WordPress runs it on the next page view). On the license screen of the add-on itself the check is still made at once; the daily check, "Check now" and the back-off after failed checks are unchanged.
- No new texts. No **Action** needed. An add-on that builds its own license form should put the key field and "Activate" in a form whose first submit button is `wplc_do=activate` (see "The license form" in `docs/CLIENT-INTEGRATION.md`). Works with every server that answers API v1, as before.

## 2.4.0

- New: beta versions by choice of the customer (SET-LIC-33). With the setting `allow_channel_choice => true` (default false) and `channel` `stable`, the license screen of an activated site shows the checkbox "Receive beta versions" (default off). The choice is stored per site (`{prefix}_license_channel`; a network option in network mode) and sent as `channel` with the update checks; changing it drops the cached update offer. The box is shown while the verified signed answer does not exclude beta: a server that limits beta lists the channels of the license in `update_channels` (planned with SET-LIC-20); without that list (every server so far) the box is shown, because those servers give beta to every client that asks. New methods `LicenseManager::update_channel()`, `beta_chosen()`, `offers_channel_choice()`, `choose_beta( bool )`; new form action `channel`. `channel => beta` in the build works as before.
- New: the environment of an activation can be chosen on the license screen when the detection from `wp_get_environment_type()` is wrong (SET-LIC-34): "Type of this site" with "Detected automatically" preselected, live, staging or development, and for a staging site the installation ID of its live site. The server decides as before; when it counts a staging request as a live site (address unrelated to the live site), the message says so and why, also with `409 site_limit`. A staging activation without a live site's ID on a site without any activation is stopped before the request (`wplc_staging_parent_missing`). New constant `LicenseClient::ENVIRONMENTS`; `run_form_action()` takes a fourth, optional parameter `$options` (`environment`, `staging_parent`, `beta`).
- New: brand values of the vendor (SET-LIC-35): setting `brand` with `support_email`, `support_url` and `key_placeholder` (default `WPLC-…`), and `LicenseManager::BUILD_BRAND`, which `bin/export-client.php --support-email= --support-url= --key-placeholder=` fills in the exported copy (`wplc-client-source.json` lists them under `brand`). The support contact is shown under the license screen and appended to the notices of a suspended or revoked license. New method `LicenseManager::brand()`.
- Site Health → Info lists the update channel (`channel`).
- New texts for the add-ons to translate: `error.wplc_staging_parent_missing`, `error.wplc_beta_unavailable`, `error.wplc_setting_not_saved`, `notice.support`, `ui.site_type`, `ui.site_type.detected`, `ui.site_type_help`, `ui.staging_parent`, `ui.saved.activated_as_live`, `ui.staging_counted_as_live`, `ui.channel`, `ui.channel.stable`, `ui.channel.beta`, `ui.beta`, `ui.beta_help`, `ui.save_channel`, `ui.saved.channel_beta`, `ui.saved.channel_stable`, `ui.support`.
- No **Action** needed: without the new settings an add-on behaves as with 2.3.0 (the license screen additionally offers "Type of this site", collapsed on a live site). Works with every server that answers API v1, as before.

## 2.3.0

- New: own texts of the product. `LicenseManager` (and `LicenseClient`) accept `texts`: an array code => text, or a callable that returns one, which replaces texts of the library (`notice.expired`, `ui.access_until`, `health.good_text` and so on; the codes are the keys of `Messages`). For a product whose license does not switch "premium features" on and off, for example one whose license only covers updates and support. Without `texts` nothing changes.
- Changed: stored credentials without a key hash (written by a library before 2.0, which was never delivered) no longer count as "this key is already active" for every key; the entered key is activated. No effect on credentials written by 2.x.
- No **Action** needed. Works with every server that answers API v1, as before.

## 2.2.0

- **Action:** the library needs PHP 8.5 or later, the newest PHP release line (2.1.1 ran on PHP 7.4). Raise `Requires PHP` of the add-on to at least 8.5 before you ship it with this version. No change of behaviour or of the API; works with every server that answers API v1, as before.
- **Action:** the library is supported on WordPress 7.0 or later (owner decision of 17 Nov 2026, uniform for all Captain plugins, add-ons and connectors). Raise `Requires at least` of the add-on to at least 7.0. The library does not check the WordPress version itself.

## 2.1.1

- Site Health test "License check" (`{prefix}_license_check`, Tools → Site Health → Status), added by `LicenseManager::register()`: *good* while the last successful check is at most 36 hours old, *recommended* when the last check failed, the check is overdue, the server no longer knows the activation, the site address changed or less than three days of the offline window are left, *critical* when the stored answer is `stale` or a signed answer released the activation. No key, secret or snapshot in the result. New public methods `LicenseManager::site_status_tests()` and `site_health_test()`, constant `LicenseManager::HEALTH_LOW_WINDOW`.
- New texts for the add-ons to translate: `health.good`, `health.failing`, `health.overdue`, `health.running_low`, `health.site_changed`, `health.stale`, `health.locked`, `health.good_text`, `health.failing_text`, `health.overdue_text`.
- First release as a separate package (`license-client-2.1.1.zip`) with the export script, this changelog and a contract test (`bin/client-contract-test.php`).

## 2.1.0

- Offline window: a stored answer is also `stale` (reason `clock_ahead`) as soon as the local time is later than its signed `cache_expires_at` plus one day (`LicenseManager::OFFLINE_TOLERANCE`, filter `{prefix}_license_offline_tolerance`, at most seven days). Resetting the unsigned clock fields in the database no longer revives an old signed answer. New `LicenseStatus::$reason`.
- Signed `409 site_mismatch`: a verified answer removes only the local credentials (`status: removed_locally`, `reason: site_mismatch`, `LicenseClient::is_signed_site_mismatch()`); an unsigned 409 of an older server keeps them.
- API requests use `wp_safe_remote_request()`. A license server on a local or private address needs `{PREFIX}_LICENSE_DEV` or the filter `{prefix}_license_allow_local_server`. New `LicenseClient` argument `allow_local_server`.
- Site Health section under Tools → Site Health → Info (`LicenseManager::debug_information()`).
- **Action:** "Remove license from this site" needs a confirmation; `run_form_action( 'deactivate', '', true )` for callers without the form.
- No translation before `init`: errors created that early carry their code as message; `LicenseManager::error_text()` and `Messages::ready()`.
- A signed answer that cannot be stored no longer leaves an older state that granted more in charge (`wplc_state_not_saved`).

## 2.0.3

- Signed activation, entitlement and update answers must carry `product_key`; without it they are `wplc_response_invalid`.
- The `activation_id` of an activation answer must be a positive integer.
- **Action (test doubles):** `OptionStore::delete()`, `LicenseClient::forget()` and `LicenseManager::forget_locally()` return `bool`; subclasses of `OptionStore` must declare `delete(): bool`. `deactivate()` reports `wplc_credentials_not_removed` while the credentials stay.
- A failed update check keeps the last valid offer until it expires.

## 2.0.2

- Only the v2 response signature (`payload_signature_ed25519`, bound to the request nonce) is accepted.
- Network mode posts its form to `wp-admin/admin-post.php`; "transfer activation" asks for a confirmation; an immediate re-check after the clock went back; the site identity comes from the unfiltered `home` option; new setting `settings_url`.

## 2.0.1

- The offline window is measured from the signed `server_time`; a stored `fetched_at` in the future voids the snapshot.
- The server signature of the stored snapshot is checked on every read; without any Ed25519 implementation nothing is accepted (`wplc_ed25519_unavailable`).
- Update packages only over HTTPS, at most 100 MB (filter `{prefix}_license_max_package_bytes`).
- `deactivate()` keeps the credentials on an unsigned `site_mismatch` or `activation_not_found`; `LicenseManager::forget_locally()`.
- The filter `{prefix}_license_public_keys` only works with `{PREFIX}_LICENSE_DEV`.

## 2.0.0

- The `LicenseManager` facade: status, feature gate, stored signed snapshot, daily check, notices, license screen, updater and uninstall. `LicenseClient` keeps the 1.x helpers `check_entitlements_envelope()`, `verify_cached_envelope()` and `verified_server_data()`.
