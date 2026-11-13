# Updates and releases

## How an update reaches an installation

1. A shop manager adds a release under **WooCommerce → License releases** and publishes it.
2. The license client signs `POST /wp-json/wplc/v1/updates/check` with the installation HMAC headers and sends `product_key` and `current_version`.
3. The server answers with the highest published version of that product. The signed response contains the SHA-256 of the package and a ten-minute, one-use `package_url` on the license server.
4. The client downloads through that URL. The server consumes the grant with one conditional database update, checks the license again and redirects to the package: a pre-signed storage URL, a plain HTTPS URL, or the local streaming route (see [UPDATE-STORAGE.md](UPDATE-STORAGE.md)).
5. The client must verify the response HMAC and compare the SHA-256 of the downloaded ZIP with the returned checksum before installing.

## Release registry

Releases live in `{prefix}wplc_releases` (schema 10). One row per product, version and channel.

| Field | Meaning |
| --- | --- |
| `product_key` | Key from `wplc_products`. |
| `version` | `MAJOR.MINOR.PATCH` with an optional suffix (`1.4.0-beta1`). |
| `channel` | Always `stable` today. The column exists so that pre-release channels can be added without a schema change; only `stable` is delivered. |
| `storage` | `url` (package at an HTTPS URL) or `local` (uploaded ZIP in the private directory). |
| `package_url` | The HTTPS URL, or the private file name for `local`. |
| `sha256` | Checksum of the ZIP. Computed on upload; required for URLs. |
| `requires_php`, `requires_wp`, `tested_wp`, `release_notes` | Passed to the client unchanged. |
| `status` | `draft`, `published` or `withdrawn`. |

### Which version is delivered

The highest **published** version by `version_compare()`, not the most recently added row: `1.10.0` beats `1.9.0`, and `2.0.0` beats `2.0.0-rc1`, which beats `2.0.0-beta2`. A pre-release suffix of a higher version still beats a lower stable version (`2.0.0-beta1` > `1.9.9`), so publish pre-releases only when every customer should get them.

### Releases are immutable

Once a release has been published, its product, version, channel, storage, package and SHA-256 can no longer be changed, also not after it was withdrawn: installations may already have it, and the checksum they verified must stay true. Release notes and the PHP/WordPress requirements stay editable. To correct a package, withdraw the release and publish a new version. Drafts can be changed and deleted freely.

### Withdrawing (rollback)

Withdrawing removes a release from the feed at once; the feed falls back to the next highest published version. Installations that already installed the withdrawn version are **not** downgraded (WordPress only updates upwards): ship the fix as a higher version. A withdrawn release can be published again, unchanged.

Publishing checks the package first: an uploaded file must exist and match its checksum, and a package URL must be HTTPS on a host from `wplc_update_package_hosts`.

### Adding releases

- **Form:** product, version, uploaded ZIP *or* package URL plus SHA-256, requirements, release notes. The release is created as a draft.
- **Manifest import:** upload or paste the JSON written by `tools/create-release-manifest.php`. `product_key`, `version`, `package_url` (HTTPS) and `sha256` are required; `requires_php`, `requires_wp`, `tested_wp` and `release_notes` are optional; `package_file` and `generated_at_utc` are ignored. A manifest always creates a `url` release.
- **Code:** `WPLC\Update\ReleaseRegistry::create()`, `update()`, `publish()`, `withdraw()`.

Every change is written to the audit log (`release_created`, `release_updated`, `release_published`, `release_withdrawn`, `release_deleted`).

### Overriding the registry

The registry feeds `wplc_update_packages` at priority 5. A filter on the default priority still wins:

```php
add_filter( 'wplc_update_packages', function ( $packages, $product_key ) {
	$packages['my-product'] = array(
		'version'     => '1.2.3',
		'package_url' => 'https://downloads.example.com/my-product-1.2.3.zip',
		'sha256'      => '…64 hex characters…',
	);
	return $packages;
}, 10, 2 );
```

`package_url` must be HTTPS on a host from `wplc_update_package_hosts` (default: the host of the shop), on the standard port, without credentials or a fragment.

## First download by the customer

The update feed needs an activated installation, so the first ZIP comes from the customer account. `WPLC\Update\CustomerDownload::render_button( $license_row )` prints a "Download version x.y.z" button when the logged-in user owns the license, the license has access and a published release exists; otherwise it prints nothing.

The button posts to `admin-post.php?action=wplc_download_package`. The handler checks the nonce, the owner (`customer_id`), `LicenseLifecycle::has_access()` and the latest published release, then redirects to a single-use streaming link (local storage) or to the resolved, allow-listed HTTPS URL. Downloads are limited to 10 per license and hour (`wplc_customer_download_hourly_limit`) and audited as `package_downloaded`.

## Building this plugin's own release

Pushing a tag `v1.2.3` runs `.github/workflows/release.yml`: it fails unless the tag equals both the `Version:` header and `WPLC_VERSION` in `wp-license.php`, installs runtime dependencies with `composer install --no-dev --optimize-autoloader`, builds `wp-license-captain-1.2.3.zip` with a single top-level `wp-license/` folder, writes `wp-license-captain-1.2.3.zip.sha256`, and attaches both to the GitHub release and the workflow run. `.distignore` lists what is left out. Tags are the immutable unit: never move a tag, release a new version instead.

## Product client integration status

The client updater is proposed in separate product-repository PRs:

- Voucher Captain: https://github.com/hastla007/wp-couponmaster/pull/11
- WP Pass Captain: https://github.com/hastla007/wp-passmaster/pull/21
- Affiliate Captain: https://github.com/hastla007/wpaffiliatemaster/pull/10

Each client consumes the update metadata, downloads through the one-use grant, verifies SHA-256, restricts the redirect host to an explicit HTTPS allow-list (`wplc_client_update_hosts`) and refreshes an expired grant once. With local storage the redirect stays on the license server host. The full path — publish, update check, download, install, withdraw — has not yet been run against a real WordPress shop and must be validated in a test shop before launch.
