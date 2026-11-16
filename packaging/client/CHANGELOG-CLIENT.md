# Changelog of the license client library

Changes of the library in `src/Client` (namespace `WPLC\Client`), by library version. The version is the `VERSION` constant of every class (`LicenseManager::VERSION`). Changes of the license server are in the changelog of the server plugin.

Every version works with every server that answers API v1 (see "Compatibility" in `README.md`). A line marked **Action** needs a change in the add-on.

## 2.2.0

- **Action:** the library needs PHP 8.5 or later, the newest PHP release line (2.1.1 ran on PHP 7.4). Raise `Requires PHP` of the add-on to at least 8.5 before you ship it with this version. No change of behaviour or of the API; works with every server that answers API v1, as before.

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
