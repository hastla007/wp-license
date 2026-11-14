# Updates and releases

## How an update reaches an installation

1. Someone with the release capability adds a release under **WooCommerce → License releases** (or with `wp wplc release create`) and publishes it.
2. The license client signs `POST /wp-json/wplc/v1/updates/check` with the installation HMAC headers and sends `product_key` and `current_version`.
3. The server answers with the highest published version the installation can run. The signed response contains the metadata WordPress needs and the SHA-256 of the package.
4. Right before it downloads, the client asks again with `"download": true` and receives a ten-minute `package` URL on the license server.
5. The client downloads through that URL. The server checks the license and the release again and redirects to the package: a pre-signed storage URL, a plain HTTPS URL, or the local streaming route (see [UPDATE-STORAGE.md](UPDATE-STORAGE.md)).
6. The client must verify the response signature and compare the SHA-256 of the downloaded ZIP with `sha256` before installing.

## Update check request

`POST /wplc/v1/updates/check`, JSON body, signed like every installation request.

| Field | Required | Meaning |
| --- | --- | --- |
| `product_key` | yes | Product key of the add-on. Must be the product of the license. |
| `current_version` | yes | Installed version. |
| `download` | no | `true`: create a download grant and return `package`. `false`: metadata only, **no grant is stored** — use this for the periodic check. Omitted: behaves like `true` for clients that predate the field (filter `wplc_update_check_issues_download`, return `false` once every shipped client sends the field). |
| `channel` | no | `stable` (default) or `beta`. A beta installation receives beta **and** stable releases, whichever is higher. Unknown values count as `stable`. |
| `target_version` | no | Ask for exactly this published version, also an older one (rollback). Needs a license with access. |
| `wp_version`, `php_version` | no | Versions of the site. If omitted, the versions the installation last reported with an entitlement check are used. |
| `core_version` | no | Version of the free core plugin the add-on extends. |

Values that are not strings (arrays) are ignored.

## Update check response

HTTP 200 with the signed envelope (`data`, `signature`, `payload`, `payload_signature`, and `signature_ed25519`/`key_id` when a signing key exists). All fields below are inside `data`. Date-times are **UTC**, formatted `Y-m-d H:i:s`.

### Always present

| Field | Type | Meaning |
| --- | --- | --- |
| `update_available` | bool | `true` when the offered `version` is higher than `current_version`. Also `true` for a license without access (see `license_active`), so the client can show "version X is available, renew to get it". |
| `current_version` | string | Echo of the request. |
| `product_key` | string | Echo of the request. |
| `channel` | string | `stable` or `beta`, as understood by the server. |
| `license_active` | bool | Whether the license currently gives access to updates. |
| `reason` | string | Only when no package is offered. One of: `up_to_date`, `package_unavailable` (no deliverable release, or `target_version` unknown), `incompatible` (a release exists but the site does not meet its requirements), `license_expired`, `license_suspended`, `license_revoked`, `license_pending`, `license_inactive`. |
| `blocked` | object | Only when a higher version was skipped because of its requirements: `version`, `unmet` (list of `requires_php`, `requires_wp`, `requires_core`), `requires_php`, `requires_wp`, `requires_core`. |

### Present whenever a release is known (also when `update_available` is `false`)

These feed the `update_plugins` transient and the "View details" window (`plugins_api`).

| Field | Type | Meaning |
| --- | --- | --- |
| `version` / `new_version` | string | Offered version. Both names carry the same value; `new_version` is the WordPress name. |
| `name` | string | Display name of the product in the catalog. |
| `slug` | string | Folder of the plugin (top-level folder of the ZIP). Falls back to the product key until the first package was inspected. |
| `plugin` | string | Plugin file relative to the plugins directory, e.g. `voucher-captain-pro/voucher-captain-pro.php`. Empty for releases created before schema 15 or supplied by a custom filter; the client then uses its own `plugin_basename()`. |
| `homepage` | string | Permalink of the mapped WooCommerce product (filter `wplc_update_product_homepage`). May be empty. |
| `last_updated` | string | Publication time of the offered version (UTC). |
| `requires_php` | string | Minimum PHP version, may be empty. |
| `requires_wp` / `requires` | string | Minimum WordPress version, may be empty. `requires` is the WordPress name. |
| `tested_wp` / `tested` | string | Highest WordPress version tested, may be empty. `tested` is the WordPress name. |
| `requires_core` | string | Minimum version of the free core plugin, may be empty. |
| `release_notes` | string (HTML) | Notes of the offered version. |
| `changelog` | list | `{version, date, notes}` for every published version above `current_version` up to the offered one, highest first (at most 20). When the installation is up to date: the five most recent versions. |
| `sections` | object | `{"changelog": "<h4>1.2.0 – 2026-11-02</h4>…"}`: the same notes as HTML, ready for `plugins_api`. |
| `icons` | object or `[]` | Icons from the filter `wplc_update_product_icons` (`1x`, `2x`, `svg` → HTTPS URL). An empty **list** when none are configured. |
| `package_url` / `package` | string | Download URL, or `""`. Both names carry the same value; `package` is the WordPress name. Non-empty only when a grant was created: license active, a version to deliver, and `download` not `false`. |
| `target_version` | string | Echo of the request field, when sent. |

### Present only with a package on offer

| Field | Type | Meaning |
| --- | --- | --- |
| `sha256` | string | SHA-256 of the ZIP (64 lowercase hex characters). Present as soon as a version is offered to an active license, also with `download: false`. |
| `download_expires_at` | string | UTC time until which `package` can be redeemed (10 minutes). Only with a grant. |

### Errors (not signed)

| Status | Code | When |
| --- | --- | --- |
| 400 | `rest_missing_callback_param` | `product_key` or `current_version` missing (checked before authentication). |
| 401, 409, 429, 503 | see [API-V1.md](API-V1.md) | Request authentication, replay, rate limit. |
| 403 | `license_inactive` | The license belongs to another product. A license of the right product **without access** no longer gets this error: it gets a signed 200 with `license_active: false` and a `reason`. |
| 503 | `package_configuration_invalid` | The offered package has no valid checksum or its URL is not HTTPS on an allowed host. |
| 503 | `download_token_error` | The grant could not be stored. |

### What a WordPress updater does with it

- Periodic check (`pre_set_site_transient_update_plugins` / `update_plugins_{hostname}`): send `download: false`, cache the answer, and put `slug`, `plugin`, `new_version`, `tested`, `requires`, `requires_php`, `icons` into the transient. Use a stable placeholder as `package`, because a real link would be expired when the customer clicks "Update".
- `upgrader_pre_download`: when the placeholder is requested, send the check again with `download: true`, download `package`, compare the SHA-256 with `sha256`.
- `plugins_api`: `name`, `slug`, `version`, `homepage`, `last_updated`, `requires`, `tested`, `requires_php`, `sections`.
- `license_active: false`: show `reason` in the plugin row; there is no `package`.
- `requires_core`/`blocked`: tell the customer which requirement stops the update.

## Download routes

`GET /wplc/v1/updates/download/{token}` redeems a grant, `GET /wplc/v1/packages/{token}` streams a local package. For both:

- **`HEAD` never uses a token up.** It answers 200 (the stream route with `Content-Length`, `Content-MD5` and `Accept-Ranges`) or 404.
- **A used token keeps working for 120 seconds** (`wplc_download_reuse_seconds`, 0 to 600; 0 restores strict single use). Download managers, scanning proxies and some browsers fetch a URL twice. The window ends at once when the license loses access or the release is withdrawn.
- A grant is marked as used only after a deliverable target is known: a missing file, a withdrawn release or a failing storage resolver answers 404 **without** burning the grant.
- Every answer carries `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`, and `DONOTCACHEPAGE` is defined for page-cache plugins. Exclude `/wp-json/wplc/` from any CDN or page cache anyway.
- The stream sends `Content-MD5` (base64; WordPress' `download_url()` verifies it) and supports a single `Range` (HTTP 206), so an interrupted download can be resumed while the token is valid.

Grants older than seven days (`wplc_download_grant_retention_days`) are deleted daily in batches of 1,000 along the `expires_at` index. A used grant forgets its package location after the reuse window.

## Release registry

Releases live in `{prefix}wplc_releases`. One row per product, version and channel.

| Field | Meaning |
| --- | --- |
| `product_key` | Key from `wplc_products`: lowercase letters, digits, `_` and `-`, 1 to 100 characters (what the catalog stores). |
| `version` | `MAJOR.MINOR.PATCH`, optionally followed by `-alphaN`, `-betaN` or `-rcN` (`1.4.0`, `1.4.0-beta1`, `1.4.0-rc2`). Nothing else: `-pre1`, `-hotfix1` or `+build` are sorted wrongly by `version_compare()` and therefore by WordPress. |
| `channel` | `stable` or `beta`. |
| `storage` | `url` (package at an HTTPS URL) or `local` (uploaded ZIP in the private directory). |
| `package_url` | The HTTPS URL, or the private file name for `local`. |
| `sha256` | Checksum of the ZIP. Computed on upload; required for URLs. |
| `requires_php`, `requires_wp`, `tested_wp` | Up to three numeric parts, at most 20 characters. Empty `requires_php`/`requires_wp` are filled from the plugin header of an uploaded ZIP. |
| `requires_core` | Minimum version of the free core plugin (schema 15). |
| `plugin_file` | `folder/main-file.php`, read from the ZIP (schema 15). |
| `release_notes` | HTML, filtered with `wp_kses_post`. |
| `status` | `draft`, `published` or `withdrawn`. |

### What a package must look like

Checked when a ZIP is uploaded **and again** when a release is published (for `url` storage the file is downloaded once at publish):

- exactly **one top-level folder** and nothing next to it (no loose files, no `__MACOSX`);
- that folder is the **plugin folder of the product**. The first published release defines it (stored per product in the option `wplc_release_product_slugs`, shown on the releases screen); every later package must use the same folder. A different folder would move the plugin on customer sites and deactivate it. Change it deliberately with `ReleaseRegistry::set_product_slug()` or the filter `wplc_release_product_slug`;
- a PHP file with a `Plugin Name:` header directly in that folder whose **`Version:` header equals the release version**. Otherwise WordPress would offer the same update forever;
- the SHA-256 matches.

The PHP extension `zip` is required; without it packages are refused.

### Which version is delivered

The highest **published** version by `version_compare()` in the requested channel whose requirements the installation meets and whose file is present: `1.10.0` beats `1.9.0`, `2.0.0` beats `2.0.0-rc1`, which beats `2.0.0-beta2`. A pre-release published in the `stable` channel still beats a lower stable version, so use the `beta` channel for pre-releases.

- **Requirements:** `requires_php`, `requires_wp` and `requires_core` are compared with the versions the installation reported. A release that does not fit is skipped; the next lower fitting one is offered and the skipped one is named in `blocked`. An installation that reports nothing is treated as compatible.
- **Missing file:** a published upload whose file is not on this server is skipped (action `wplc_release_package_missing`) and listed on the releases screen.
- **Publishing a version that is not higher than the highest published one** is allowed (backports), but the releases screen and WP-CLI warn: installations on the higher version will never be offered it.

The update check reads a narrow index (id, version, requirements, date) that is cached per product and channel in the object cache for five minutes and dropped on publish, withdraw, update and delete. Notes are loaded for the offered and the skipped versions only. Changing rows directly in the database bypasses this; call `ReleaseRegistry::flush_cache( $product_key )` afterwards.

### Releases are immutable

Once a release has been published, its product, version, channel, storage, package and SHA-256 can no longer be changed, also not after it was withdrawn: installations may already have it, and the checksum they verified must stay true. Release notes and the requirements stay editable. To correct a package, withdraw the release and publish a new version. Drafts can be changed and deleted freely.

### Withdrawing (rollback)

Withdrawing removes a release from the feed at once; the feed falls back to the next highest published version, and download grants that were already issued for it stop working. Installations that already installed the withdrawn version are **not** downgraded (WordPress only updates upwards): ship the fix as a higher version, or let the client request an older one with `target_version`. A withdrawn release can be published again, unchanged.

### Adding releases

- **Form:** product, version, channel, uploaded ZIP *or* package URL plus SHA-256, requirements, release notes. The release is created as a draft. After an error the form keeps what was entered. An upload above the server's `post_max_size` ends with a message instead of an empty page.
- **Manifest import:** upload or paste the JSON written by `tools/create-release-manifest.php`. `product_key`, `version`, `package_url` (HTTPS) and `sha256` are required; `requires_php`, `requires_wp`, `tested_wp`, `requires_core` and `release_notes` are optional; other keys are ignored. A manifest always creates a `url` release.
- **WP-CLI:**

  ```sh
  wp wplc release create --product=voucher-captain-pro --version=1.4.0 --zip=build/voucher-captain-pro-1.4.0.zip \
      [--channel=beta] [--requires-php=7.4] [--requires-wp=6.5] [--tested-wp=6.8] [--requires-core=2.1.0] \
      [--notes="<p>…</p>" | --notes-file=CHANGES.html] [--publish] [--reason="CI build 77"] [--porcelain]
  wp wplc release publish <id> --reason="…"
  wp wplc release withdraw <id> --reason="…"
  ```

  The ZIP is copied into the private directory. With `--publish`, a release that cannot be published is removed again, so a pipeline can simply run once more.
- **Code:** `WPLC\Update\ReleaseRegistry::create()`, `create_with_package()`, `update()`, `publish()`, `withdraw()`, `delete_draft()`.

### Who may do this, and the release log

The releases screen and all its actions require the capability returned by `apply_filters( 'wplc_capability', 'manage_woocommerce', 'publish_releases' )`. A published release is code that runs on every customer site; give this capability to as few accounts as possible.

Publishing, withdrawing and deleting ask for confirmation and need a **reason** (at most 255 characters). Every change is written to the audit log (`release_created`, `release_updated`, `release_published`, `release_withdrawn`, `release_deleted`, `release_product_slug_changed`) with user, time and reason; the last 50 entries are shown as "Release log" at the bottom of the screen.

### Problems shown on the releases screen

`ReleaseRegistry::problems()` returns them for other screens (diagnostics) as `code`, `message`, `release_id`:

- `package_missing`: a published upload is not on this server (several web servers without a shared directory, restore without the directory).
- `untrusted_package_host`: a published package URL is not on an allowed host (any more), typically after the shop moved to another domain. Update checks for that product answer 503 until the host is added under **License settings → Additional trusted package hosts** or the release is replaced.
- `package_dir_exposed`: the package directory answers over HTTP (see [UPDATE-STORAGE.md](UPDATE-STORAGE.md)).

A separate warning appears when a release with a package URL is published and no storage resolver is registered: every licensed customer then learns the permanent address.

### Overriding the registry

The registry feeds `wplc_update_packages` at priority 5. A filter on the default priority still wins:

```php
add_filter( 'wplc_update_packages', function ( $packages, $product_key, $context = array() ) {
	$packages['my-product'] = array(
		'version'     => '1.2.3',
		'package_url' => 'https://downloads.example.com/my-product-1.2.3.zip',
		'sha256'      => '…64 hex characters…',
		// Optional: requires_php, requires_wp, tested_wp, requires_core, slug, plugin,
		// last_updated, release_notes, changelog (list of version/date/notes).
	);
	return $packages;
}, 10, 3 );
```

`$context` holds `channel`, `current_version`, `target_version`, `wp_version`, `php_version` and `core_version` of the request. `package_url` must be HTTPS on a host from `wplc_update_package_hosts` (default: the host of the shop), on the standard port, without credentials or a fragment.

## Download from the customer account

The update feed needs an activated installation, so the first ZIP comes from the customer account. `WPLC\Update\CustomerDownload::render_button( $license_row )` prints the download form when the logged-in user owns the license:

- A license with access can choose between the latest and up to nine older published versions (`wplc_customer_download_version_choices`).
- A license whose paid period has ended (`expired`) keeps the versions that were **published while it was paid**; newer versions need a renewal. Switch this off with `add_filter( 'wplc_expired_license_download', '__return_false' )`. Revoked, suspended and pending licenses get nothing.
- Without any downloadable release a short hint is shown instead of the button.

The form posts to the customer account URL (field `wplc_action=wplc_download_package`), not to `wp-admin`, so it also works on shops that lock `wp-admin` down. `admin-post.php?action=wplc_download_package` keeps working. The handler checks the nonce, the owner (`customer_id`), the license and the release, then redirects to a stream link (local storage) or to the resolved, allow-listed HTTPS URL. Downloads are limited to 10 per license and hour (`wplc_customer_download_hourly_limit`) and audited as `package_downloaded`.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wplc_update_packages` | filter | Packages on offer (see above). |
| `wplc_update_package_hosts` | filter | Allowed package hosts. The shop host is always kept. |
| `wplc_resolve_update_package_url` | filter | Turn a stored package URL into the URL to deliver (pre-signed URL). Second argument: license ID, `0` when the server verifies a package at publish. |
| `wplc_require_package_resolver` | filter | `true`: never redirect a client to an unchanged stored URL (default `false`). |
| `wplc_verify_url_release_on_publish` | filter | `false`: do not download a URL package at publish. |
| `wplc_update_check_issues_download` | filter | Default of the `download` request field. |
| `wplc_download_reuse_seconds` | filter | Reuse window of download tokens. |
| `wplc_download_grant_retention_days` | filter | Retention of download grants. |
| `wplc_update_product_homepage`, `wplc_update_product_icons` | filter | Details-view metadata. |
| `wplc_release_product_slug` | filter | Plugin folder of a product. |
| `wplc_package_storage_dir`, `wplc_package_max_upload_bytes`, `wplc_package_offload_header` | filter | Local storage, see [UPDATE-STORAGE.md](UPDATE-STORAGE.md). |
| `wplc_customer_download_hourly_limit`, `wplc_customer_download_version_choices`, `wplc_expired_license_download` | filter | Customer account download. |
| `wplc_capability` | filter | Capability per area; the releases screen asks for `publish_releases`. |
| `wplc_release_package_missing` | action | A published upload is missing on this server. |

## Building this plugin's own release

Pushing a tag `v1.2.3` runs `.github/workflows/release.yml`: it fails unless the tag equals both the `Version:` header and `WPLC_VERSION` in `wp-license.php`, installs runtime dependencies with `composer install --no-dev --optimize-autoloader`, builds `wp-license-captain-1.2.3.zip` with a single top-level `wp-license/` folder, writes `wp-license-captain-1.2.3.zip.sha256`, and attaches both to the GitHub release and the workflow run. `.distignore` lists what is left out. Tags are the immutable unit: never move a tag, release a new version instead.

## Product client integration status

**There is no finished WordPress updater yet.** The bundled `WPLC\Client\LicenseClient` only transports the update check (`check_update()`); it does not hook into WordPress' update system, does not download, and does not verify the SHA-256. No product add-on talks to this server so far. The premium add-ons (not the free core plugins) will carry the client; their tasks are listed in the `TODO.md` of each product repository.

The server side described above is covered by unit and integration tests. The full path — publish, update check, download, install through the WordPress updater, withdraw — has not been run with a real add-on and must be validated in a test shop before launch.
