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

- **Create draft:** product, version, channel (`stable` or `beta`), an uploaded ZIP (up to 50 MB by default; PHP's `upload_max_filesize` and `post_max_size` apply) **or** an HTTPS package URL on an allowed host (or `s3://bucket/key` with object storage, see below) plus its SHA-256, optional requirements (PHP, WordPress, tested up to, minimum version of your free core plugin) and release notes (HTML). Empty PHP and WordPress requirements are filled from the plugin header of an uploaded ZIP.
- **Import manifest:** upload or paste a JSON object with `product_key`, `version`, `package_url` (HTTPS, or `s3://bucket/key`) and `sha256`, optionally `requires_php`, `requires_wp`, `tested_wp`, `requires_core` and `release_notes`. A manifest always creates a URL release.
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
| Where the ZIP lives | Private directory on the shop server | A private S3-compatible bucket, or any HTTPS host |
| Delivered by | Streaming route of the shop (PHP, or the web server via `X-Accel-Redirect`/`X-Sendfile`) | Redirect to a short-lived pre-signed URL (built in for S3-compatible storage, or from your resolver) |
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

### Object storage (S3-compatible, built in)

Packages can live in a private bucket of any storage that speaks the S3 API with Signature Version 4: AWS S3, Cloudflare R2, MinIO, Backblaze B2 (S3 API), Google Cloud Storage (XML API with HMAC keys) and others. The plugin signs the download links itself; no SDK is needed.

**1. Configure the bucket in `wp-config.php`** (above the line that loads `wp-settings.php`). The values are read from these constants only; they are never stored in the database, shown in the admin or written to a log.

```php
define( 'WPLC_S3_BUCKET', 'acme-releases' );
define( 'WPLC_S3_REGION', 'eu-central-1' );              // Default us-east-1; "auto" for Cloudflare R2.
define( 'WPLC_S3_ENDPOINT', 'https://s3.eu-central-1.amazonaws.com' ); // Default: AWS for the region.
define( 'WPLC_S3_ACCESS_KEY_ID', '…' );
define( 'WPLC_S3_SECRET_ACCESS_KEY', '…' );
// define( 'WPLC_S3_PATH_STYLE', true );                // https://endpoint/bucket/key instead of https://bucket.endpoint/key.
```

| Storage | `WPLC_S3_ENDPOINT` | `WPLC_S3_REGION` | Path-style |
| --- | --- | --- | --- |
| AWS S3 | omit (or `https://s3.<region>.amazonaws.com`) | bucket region | no |
| Cloudflare R2 | `https://<account-id>.r2.cloudflarestorage.com` | `auto` | either |
| MinIO | `https://minio.example.com` | your MinIO region (default `us-east-1`) | usually yes |
| Backblaze B2 | `https://s3.<region>.backblazeb2.com` | e.g. `eu-central-003` | either |
| Google Cloud Storage | `https://storage.googleapis.com` | `auto` | either |

- The endpoint must be HTTPS on the standard port 443, without a path. Put a MinIO server that listens on another port behind a reverse proxy.
- A bucket name with dots or capital letters is always addressed path-style (TLS certificates do not cover it otherwise).
- Give the key **read access to the release objects only** (for example `s3:GetObject` on `arn:aws:s3:::acme-releases/*`). Write access stays with your build pipeline. Keep the bucket private.
- The request host of the bucket is added to the trusted package hosts automatically.
- **WooCommerce → License diagnostics** shows "Object storage (S3-compatible)": the bucket and the endpoint host, or what is wrong with the configuration. Never a key.

**2. Check the credentials:**

```sh
wp wplc storage check                       # signed HEAD on the bucket (needs list permission on AWS)
wp wplc storage check --key=acme-pro/acme-pro-1.4.0.zip   # signed HEAD on one object (read permission is enough)
```

**3. Upload the ZIP to the bucket** with your own tooling (one key per version; never overwrite an object), then register it as package location `s3://<bucket>/<key>`, together with the SHA-256:

- **Admin:** in **Create draft**, enter `s3://acme-releases/acme-pro/acme-pro-1.4.0.zip` as package URL.
- **WP-CLI:** `wp wplc release create --product=acme-pro --version=1.4.0 --object=acme-pro/acme-pro-1.4.0.zip --sha256=<sha256> --publish` (`--object` takes `s3://bucket/key` or only the key).
- **Manifest:** `"package_url": "s3://acme-releases/acme-pro/acme-pro-1.4.0.zip"`.

The key is taken as written (spaces and non-ASCII characters are fine; no `.` or `..` segments). The release stores the permanent object URL (`https://…/acme-pro/acme-pro-1.4.0.zip`). Publishing downloads the object once through a signed link and checks SHA-256, folder and `Version:` header.

**How it is delivered:** when a licensed installation redeems its one-time download link, or a customer downloads from the account, the server checks license, installation and release, then redirects to a pre-signed `GET` URL of the object. It is valid for 300 seconds by default (filter `wplc_s3_presign_expires`, 1 second to 7 days). The client never learns a permanent address, and a link stops working after it expires. The server clock must be correct (NTP): the storage refuses links whose time stamp is off.

### Object storage with your own resolver

For a storage that is not S3-compatible, or to sign links differently, register a resolver: it receives the stored URL and returns a short-lived URL. Your resolver runs **before** the built-in one (use the default priority 10, or any priority below 20); a URL it changed is not signed again, so it takes precedence.

```php
add_filter( 'wplc_update_package_hosts', function ( array $hosts ) {
	$hosts[] = 'downloads.example.com';
	return $hosts;
} );

add_filter( 'wplc_resolve_update_package_url', function ( $url, $license_id ) {
	if ( 'downloads.example.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $url; // Not ours, for example an uploaded package.
	}
	// Return a signed URL valid for about five minutes, built with credentials from the server environment.
	return acme_sign_download_url( $url, '+5 minutes' );
}, 10, 2 );
```

- The host can also be added under **License settings → Additional trusted package hosts**. Only HTTPS on port 443 on an allowed host is delivered.
- **Without a resolver a URL release is not delivered**, because every licensed site would learn the permanent address. A shop that accepts that (a public mirror) can allow it with `add_filter( 'wplc_require_package_resolver', '__return_false' );`.
- The resolver also runs once when a URL release is published: the server downloads the package to verify SHA-256, folder and `Version:` header. Upload each version under its own object key and never overwrite objects.

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
| `wplc_s3_presign_expires` | Validity of pre-signed object storage links in seconds (default 300, at most 7 days). |
| `wplc_download_reuse_seconds` | Reuse window of download links (default 0). |
| `wplc_update_product_homepage`, `wplc_update_product_icons` | Homepage and icons in the "View details" window. |
| `wplc_package_storage_dir`, `wplc_package_max_upload_bytes`, `wplc_package_offload_header` | Local storage. |
| `wplc_customer_download_hourly_limit`, `wplc_customer_download_version_choices`, `wplc_expired_license_download` | Downloads from the customer account. |
| `wplc_release_package_missing` (action) | A published upload is missing on this server. |
