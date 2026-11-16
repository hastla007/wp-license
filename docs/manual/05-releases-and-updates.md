# 5. Releases and updates

Licensed sites receive updates of your add-on through the normal WordPress update screen. You publish a **release** on the server; the client library in the add-on asks for it and installs it after checking its SHA-256.

## What a package must look like

Checked when a ZIP is uploaded **and again** when the release is published:

- exactly **one top-level folder** and nothing next to it (no loose files, no `__MACOSX`);
- that folder is the **plugin folder of the product**, normally the product key (`acme-reports-pro/`). The first published release defines it; every later package must use the same folder, because a different folder would move the plugin on customer sites and deactivate it;
- a PHP file directly in that folder with a `Plugin Name:` header whose **`Version:` equals the release version**;
- the SHA-256 matches;
- optional: `wplc-catalog.json` in the plugin folder, imported into the catalog (chapter 3).

Versions are `MAJOR.MINOR.PATCH`, optionally followed by `-alphaN`, `-betaN` or `-rcN` (`1.4.0`, `1.4.0-beta1`, `1.4.0-rc2`). Other suffixes (`-pre1`, `+build`) are sorted wrongly by WordPress and are refused. The PHP extension `zip` is required on the server.

## Adding a release

**In the admin:** **WooCommerce → License releases** (capability `wplc_publish_releases`).

- **Create draft:** product, version, channel (`stable` or `beta`), an uploaded ZIP (up to 50 MB by default; PHP's `upload_max_filesize` and `post_max_size` apply) **or** an HTTPS package URL on an allowed host plus its SHA-256, optional requirements (PHP, WordPress, tested up to, minimum version of your free core plugin) and release notes (HTML). Empty PHP and WordPress requirements are filled from the plugin header of an uploaded ZIP.
- **Import manifest:** upload or paste a JSON object with `product_key`, `version`, `package_url` (HTTPS) and `sha256`, optionally `requires_php`, `requires_wp`, `tested_wp`, `requires_core` and `release_notes`. A manifest always creates a URL release.
- **Publish**, **Withdraw** (reason required) and **Delete** (drafts only) ask for confirmation. The last 50 changes are shown as "Release log".

**On the command line** (for your build pipeline):

```sh
wp wplc release create --product=acme-reports-pro --version=1.4.0 \
    --zip=build/acme-reports-pro-1.4.0.zip --notes-file=CHANGES.html --publish --reason="CI build 77"
wp wplc release publish <id> --reason="…"
wp wplc release withdraw <id> --reason="…"
```

With `--publish`, a release that cannot be published is removed again, so the pipeline can simply run once more. `--porcelain` prints only the release ID. All options are listed in chapter 10.

## Which version a site gets

- The highest **published** version in the requested channel whose requirements the site meets and whose file is present. A `beta` installation receives beta and stable releases, whichever is higher. Use the `beta` channel for pre-releases: a pre-release published as `stable` still beats a lower stable version.
- `requires_php`, `requires_wp` and `requires_core` are compared with the versions the site reported. A release that does not fit is skipped; the next lower fitting one is offered and the site is told which requirement blocks the newer one.
- A license without access still sees that a version exists ("renew to get it"), but gets no package.
- **Releases are immutable once published:** product, version, channel, package and SHA-256 can no longer change, also not after withdrawal. Release notes and requirements stay editable. To fix a package, withdraw the release and publish a higher version.
- **Withdrawing** removes a release from the feed at once; the feed falls back to the next highest published version and already issued download links stop working. Sites that installed the withdrawn version are not downgraded. A withdrawn release can be published again, unchanged.
- Publishing a version that is not higher than the highest published one (a backport) is allowed, with a warning: installations on the higher version will never be offered it.

## How an update reaches a site

1. During the WordPress update check the client asks `POST /wp-json/wplc/v1/updates/check` (signed) with `download: false`; the answer is cached for twelve hours (one hour after a failure). The signed answer contains the version, requirements, changelog and the SHA-256 of the package. WordPress shows the update in the plugin list and in "View details".
2. When the update is installed (by a person or as an automatic update), the client asks again with `download: true` and receives a **one-time download link** on the license server: valid for five minutes, bound to the installation, used up by the first `GET`.
3. The server checks the license, the installation and the release again and redirects to the package: the streaming route for an uploaded package, or a short-lived pre-signed URL for object storage.
4. The client compares the SHA-256 of the file with the signed value before WordPress unpacks it.

A cache, link scanner or security proxy that fetches a download link first uses it up. Exclude `/wp-json/wplc/` from every such component (chapter 9). Filter `wplc_download_reuse_seconds` (0 to 600, default 0) allows a short reuse window for download managers that fetch a URL twice.

**The first download** comes from the customer account: a license with access can download the latest or one of up to nine older published versions there. An expired license keeps the versions published while it was paid (filter `wplc_expired_license_download`). Downloads are limited to 10 per license and hour and audited as `package_downloaded`.

## Package storage

| | Uploaded ZIP (`local`) | Package URL (`url`) |
| --- | --- | --- |
| Where the ZIP lives | Private directory on the shop server | Any HTTPS host: S3, GCS, a download server |
| Delivered by | Streaming route of the shop (PHP, or the web server via `X-Accel-Redirect`/`X-Sendfile`) | Redirect to a short-lived pre-signed URL from your resolver |
| Good for | Small catalogs, no extra infrastructure | Large files, many downloads, CDN |

### Uploaded packages: keep them out of the web root

Uploaded ZIPs are paid content. By default they are stored in `wp-content/uploads/wplc-packages/` (per site on multisite) with deny-all `.htaccess` and `web.config` files and random file names. **nginx ignores `.htaccess`**, so on nginx only the random file name protects them unless you act.

**Preferred:** a directory outside the web root.

```php
// wp-config.php
define( 'WPLC_PACKAGE_STORAGE_DIR', '/var/lib/wplc-packages' );
```

```sh
sudo install -d -m 0750 -o www-data -g www-data /var/lib/wplc-packages
```

The path must be absolute and must not lie inside the WordPress directory, `wp-content`, the uploads directory or the document root; an invalid value is ignored and reported on the releases and diagnostics pages, never silently used. With `open_basedir`, add the directory to it. Include it in backups. To move an existing directory, copy the files (with their names) first, then set the constant.

**Otherwise on nginx:** deny the directory, before the generic PHP and static-file locations:

```nginx
location ^~ /wp-content/uploads/wplc-packages/ { deny all; }
```

On multisite the path is `/wp-content/uploads/sites/{id}/wplc-packages/`; the diagnostics page prints the rule for the current site.

**Verify:** **WooCommerce → License diagnostics → Package storage** must show "Outside the web root" or "refused to deliver a test file". The self-check fetches a canary file over HTTP; press **Check again** after every web server change. If loopback requests are blocked, check by hand: `curl -I https://shop.example/wp-content/uploads/wplc-packages/index.html` must return `403`. If the check ever reported "Publicly readable" in production, treat the packages as possibly downloaded.

### Let the web server send the file

By default PHP streams the file, which keeps one PHP worker busy per download. With many downloads, hand the file to the web server; license and token checks still run in PHP first.

```nginx
location /wplc-protected/ {
	internal;
	alias /var/lib/wplc-packages/;
}
```

```php
add_filter( 'wplc_package_offload_header', function ( $header, $path, $release ) {
	return 'X-Accel-Redirect: /wplc-protected/' . basename( $path );
}, 10, 3 );
```

Apache with mod_xsendfile: return `'X-Sendfile: ' . $path`; LiteSpeed: `'X-LiteSpeed-Location: …'`. Only these three headers are accepted. Test one download and compare the SHA-256.

### Several web servers

The package directory must be the same directory on every node (NFS, EFS, a shared volume). On a node without the file a release is skipped in the feed and the releases screen shows "The uploaded package … is missing on this server". Alternatively use URL storage.

### Object storage (S3, GCS)

Register the release with the permanent object URL, keep the object private, allow the bucket host, and return a pre-signed URL from a resolver:

```php
add_filter( 'wplc_update_package_hosts', function ( array $hosts ) {
	$hosts[] = 'acme-releases.s3.eu-central-1.amazonaws.com';
	return $hosts;
} );

add_filter( 'wplc_resolve_update_package_url', function ( $url, $license_id ) {
	if ( 'acme-releases.s3.eu-central-1.amazonaws.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $url; // Not ours, for example an uploaded package.
	}
	// Build a pre-signed GET URL valid for about five minutes with your storage SDK,
	// with credentials from the server environment, never from the database.
	return acme_presign_s3_url( $url, '+5 minutes' );
}, 10, 2 );
```

- The bucket host can also be added under **License settings → Additional trusted package hosts**. Only HTTPS on port 443 on an allowed host is delivered.
- **Without a resolver a URL release is not delivered**, because every licensed site would learn the permanent address. A shop that accepts that (a public mirror) can allow it with `add_filter( 'wplc_require_package_resolver', '__return_false' );`.
- The resolver also runs once when a URL release is published: the server downloads the package to verify SHA-256, folder and `Version:` header. Upload each version under its own object key and never overwrite objects.
- The plugin ships no storage SDK; credentials, bucket policy and retention belong to your deployment.

## Problems shown on the releases screen

- **Package missing:** a published upload is not on this server (several nodes without shared storage, restore without the directory).
- **Untrusted package host:** a published URL is no longer on an allowed host, typically after a domain move. Add the old host under trusted package hosts while it still serves the files, or publish new releases.
- **Package directory exposed:** the directory answers over HTTP (see above).

## Hooks for releases

| Hook | Purpose |
| --- | --- |
| `wplc_update_packages` | Offer packages from your own source instead of, or next to, the registry. |
| `wplc_update_package_hosts` | Allowed package hosts (the shop host is always kept). |
| `wplc_resolve_update_package_url` | Turn a stored URL into the URL to deliver (pre-signed). |
| `wplc_require_package_resolver` | `false` delivers URL releases at their permanent address. |
| `wplc_download_reuse_seconds` | Reuse window of download links (default 0). |
| `wplc_update_product_homepage`, `wplc_update_product_icons` | Homepage and icons in the "View details" window. |
| `wplc_package_storage_dir`, `wplc_package_max_upload_bytes`, `wplc_package_offload_header` | Local storage. |
| `wplc_customer_download_hourly_limit`, `wplc_customer_download_version_choices`, `wplc_expired_license_download` | Downloads from the customer account. |
| `wplc_release_package_missing` (action) | A published upload is missing on this server. |
