# Update package storage

A release package is stored in one of two ways.

| | `local` | `url` |
| --- | --- | --- |
| Where the ZIP lives | Private directory on the shop server | A private S3-compatible bucket (built in), or any HTTPS host with your own resolver |
| How it is added | Upload on **WooCommerce → License releases**, or `wp wplc release create --zip=…` | Package URL or `s3://bucket/key` + SHA-256, manifest import, or `wp wplc release create --object=…` |
| Delivered by | Streaming route on the shop (PHP, or the web server via `X-Accel-Redirect`/`X-Sendfile`) | Redirect to the (pre-signed) URL |
| Does the client learn a permanent address? | No | No: without a resolver that returns a pre-signed URL the release is not delivered at all (see below) |
| Good for | Small catalogs, no extra infrastructure | Large files, many downloads, CDN |

## Local private storage

Uploaded ZIPs are stored in `wp-content/uploads/wplc-packages/` (per site on multisite) unless a directory outside the web root is configured. **Recommended for production:** set `WPLC_PACKAGE_STORAGE_DIR` (see [Outside the web root](#outside-the-web-root-recommended)).

- The directory gets a deny-all `.htaccess`, a `web.config`, an empty `index.html` and an `index.php`.
- **nginx ignores `.htaccess`.** Every file name therefore ends in 32 random hexadecimal characters (`{product}-{version}-{random}.zip`), and that name is never shown in the admin, written to the audit log or sent to a client. That is the last line of defence, not the only one: move the directory out of the web root, or add a deny rule (see [nginx](#nginx)).
- **Self-check.** While the directory lies inside the web root, the plugin writes a short-lived canary file into it and requests that file over HTTP (3 s timeout). The result is cached for 12 hours (1 hour when the site could not be reached) and is only computed when **WooCommerce → License diagnostics** or **License releases** is opened, never on front-end requests. If the web server delivers the canary, the releases page shows an error notice and the diagnostics page shows the matching nginx rule; **Check again** drops the cached result. Disable the probe with `add_filter( 'wplc_package_storage_probe', '__return_false' );` (for example when loopback requests are blocked).

### Outside the web root (recommended)

Define the directory in `wp-config.php`, above the line that loads `wp-settings.php`:

```php
define( 'WPLC_PACKAGE_STORAGE_DIR', '/var/lib/wplc-packages' );
```

```sh
sudo install -d -m 0750 -o www-data -g www-data /var/lib/wplc-packages
```

- The path must be absolute, must not contain `.` or `..` segments and must not lie inside `ABSPATH`, `WP_CONTENT_DIR`, the uploads directory or the document root (symlinks are resolved). An invalid value is **ignored**: the default directory stays in use and both admin pages show an error, so a typo never sends packages to an unexpected place.
- PHP must be able to create and write the directory; the diagnostics page shows **Writable**. With `open_basedir`, add the directory to it.
- Moving an existing directory: copy the files (including the random file names) to the new place, then set the constant. Releases store only the file name, so nothing else changes.
- On multisite the constant applies to every site; give each site its own directory with the filter instead (`get_current_blog_id()`).
- The `wplc_package_storage_dir` filter still runs last and can override the constant. Its value is used as returned (not validated), but the self-check still applies.

### nginx

When the directory stays below `uploads`, add a deny rule to the `server` block **before** the generic PHP and static-file locations. Adapt the path to your uploads URL (multisite: `/wp-content/uploads/sites/{id}/wplc-packages/`, or one rule for `/wp-content/uploads/sites/`); the diagnostics page prints the rule for the current site.

```nginx
location ^~ /wp-content/uploads/wplc-packages/ { deny all; }
```

`^~` makes nginx stop at this prefix, so a later regex location (for example `location ~* \.(zip|php)$`) cannot serve the files. A regex form such as `location ~* ^/wp-content/uploads/wplc-packages/ { deny all; }` works only if it comes before those locations. Reload nginx, then press **Check again** on the diagnostics page: the result must read "refused to deliver a test file". You can also check by hand: `curl -I https://shop.example/wp-content/uploads/wplc-packages/index.html` must return `403`.

  **The plugin checks this.** When the releases screen is opened and a published upload exists, it writes a small probe file (`wplc-probe.txt`) into the directory, requests it over HTTP and deletes it again. If the web server hands the file out, the screen shows an error ("The package directory can be read over HTTP") until the rule is in place; "Check again" repeats the probe. The result is cached for a day (`WPLC\Update\LocalPackageStorage::exposure_status()`: `protected`, `exposed`, `outside_webroot` or `unknown`). A directory outside the uploads folder has no URL and is reported as `outside_webroot`.

- Uploads must be a real `.zip` of at most 50 MB (`wplc_package_max_upload_bytes`; PHP's `upload_max_filesize` and `post_max_size` apply as well) with exactly one top-level folder and a matching `Version:` header (see [UPDATES.md](UPDATES.md), "What a package must look like"). The SHA-256 is computed from the stored file and checked again when the release is published.
- Include the directory in backups. A release whose file is missing cannot be published, is not offered as an update and is listed as a problem on the releases screen.

### Several web servers

The package directory must be **the same directory on every web node** (NFS, EFS, a shared volume): an upload lands on the node that handled the request, and a download can reach any node. On a node without the file the release is skipped in the update feed, a download answers 404 without using the grant up, and the releases screen of that node shows "The uploaded package … is missing on this server". Point `wplc_package_storage_dir` at the shared mount, or use `url` storage.

### How a local package is delivered

1. The registry gives the update API a placeholder URL, `…/wp-json/wplc/v1/packages/release/{id}`. No route answers there; it only identifies the release inside the download grant.
2. When the client redeems its grant, `UpdateService::download()` checks that the release is still published and its file present, then applies `wplc_resolve_update_package_url`. `WPLC\Update\PackageStream::resolve()` recognizes the placeholder, stores a stream grant in `{prefix}wplc_package_streams` (only the SHA-256 of the token) and returns `…/wp-json/wplc/v1/packages/{token}`.
3. That route checks the grant, the license and the release, marks the grant as used with one conditional `UPDATE`, and sends the file with `Content-Type: application/zip`, `Content-Length`, `Content-MD5`, `Accept-Ranges: bytes`, `Content-Disposition`, `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`.

A stream link is valid for 60 seconds. It is only handed out as a redirect target that the client follows at once. `HEAD` does not use it up; by default the first `GET` that presents it uses it up, also when it fails (strict single use, T-108). With `wplc_download_reuse_seconds` (0 to 600) a shop can let a used link keep working for a short time, so a second fetch by a download manager or a `Range` request that resumes an interrupted download succeeds. After that a new update check is needed. Customer downloads from the account page do not use stream links; the ZIP is sent in the response to the POST form. Old grants are deleted by the daily `wplc_daily_lifecycle` job.

Requirements: the shop must be reachable over **HTTPS on the standard port**, because the update API only redirects to such URLs. The shop host is always kept in `wplc_update_package_hosts`.

### Let the web server send the file

By default PHP streams the file, which keeps one PHP worker busy for the whole transfer. With many or slow downloads hand the file to the web server. The filter returns one complete header line; PHP then sends the headers only.

nginx (`X-Accel-Redirect`, with the directory outside the web root or denied as above):

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

Apache with mod_xsendfile (`XSendFile On`, `XSendFilePath /var/lib/wplc-packages`) or LiteSpeed:

```php
add_filter( 'wplc_package_offload_header', function ( $header, $path ) {
	return 'X-Sendfile: ' . $path; // LiteSpeed: 'X-LiteSpeed-Location: /internal/path/' . basename( $path )
}, 10, 2 );
```

Only `X-Accel-Redirect`, `X-Sendfile` and `X-LiteSpeed-Location` are accepted. License and token checks still run in PHP before the header is sent. Test the setup with one download and compare the SHA-256: a wrong `alias` shows up as a 404 from the web server.

## S3-compatible object storage (built in, T-31)

Since T-31 the plugin signs links for one private bucket itself, in pure PHP (AWS Signature Version 4, query-string presigning; no SDK): `WPLC\Update\S3Presigner` (signing, pure) and `WPLC\Update\S3Storage` (configuration, resolver, check). It works with AWS S3, Cloudflare R2, MinIO, Backblaze B2 (S3 API) and Google Cloud Storage (XML API with HMAC keys).

### Configuration (wp-config.php only)

| Constant | Required | Default | Meaning |
| --- | --- | --- | --- |
| `WPLC_S3_BUCKET` | yes | | Bucket name. |
| `WPLC_S3_ACCESS_KEY_ID` | yes | | Access key ID. |
| `WPLC_S3_SECRET_ACCESS_KEY` | yes | | Secret access key. |
| `WPLC_S3_REGION` | no | `us-east-1` | Signing region; `auto` for R2 and GCS. |
| `WPLC_S3_ENDPOINT` | no | `https://s3.{region}.amazonaws.com` | HTTPS endpoint, port 443, no path. A bare host is accepted. |
| `WPLC_S3_PATH_STYLE` | no | `false` | `https://endpoint/bucket/key` instead of `https://bucket.endpoint/key`. Always on for bucket names that are not one lower-case DNS label (dots, capitals, underscores). |

- The credentials are read from the constants on each use and passed only to the signer. They are not stored in an option, not shown on any screen, not part of an audit entry, log line, REST answer or CLI output (`S3StorageIntegrationTest::assertSecretNowhere`). `S3Presigner::__debugInfo()` hides them from `var_dump`/`print_r`. There is deliberately no filter for them.
- If only some of the three required constants are set, or a value is invalid, the storage counts as not configured; **License diagnostics** shows "Invalid configuration" with the reason. A configured storage shows "Bucket {bucket} on {endpoint host}" and nothing else.
- The request host (`bucket.endpoint` or the endpoint) is added to `wplc_update_package_hosts` while the storage is configured.
- Give the key read access to the release objects only (`s3:GetObject` on `bucket/*`). `wp wplc storage check` needs `s3:ListBucket` for the bucket check, or only `s3:GetObject` with `--key=<object>`.
- Endpoints on another port (MinIO on `:9000`) are refused, like every package host that is not HTTPS on 443: put them behind a TLS reverse proxy.

### Registering a release

The admin form, manifests and `wp wplc release create --object=<s3://bucket/key | key> --sha256=…` accept `s3://<bucket>/<key>`. `ReleaseRegistry::validate()` turns it into the permanent object URL of the configured bucket, e.g. `s3://acme-releases/acme pro/1.0.zip` → `https://acme-releases.s3.eu-central-1.amazonaws.com/acme%20pro/1.0.zip`, and stores that (no schema change: storage stays `url`). The key is raw (not URL-decoded), 1 to 1024 bytes of UTF-8, without control characters and without `.` or `..` segments (HTTP clients would collapse them). Another bucket, an invalid key or a missing configuration is refused with `object_storage_unavailable`. A release can also be registered directly with the object URL.

Changing the endpoint, the bucket or the addressing style later changes the request host: releases stored with the old object URL are then no longer recognized (and their host is no longer trusted). Publish new releases, or keep the old host trusted and register a resolver for it.

### Delivery

`S3Storage::resolve()` runs on `wplc_resolve_update_package_url` with priority 20, after custom resolvers on priority 10. It signs only a URL that is an unchanged object URL of the bucket: right host, right bucket prefix (path-style), HTTPS on 443, **no query**, no fragment, no credentials. A URL a custom resolver already signed carries a query and is passed on unchanged, so custom resolvers keep precedence; a resolver registered after priority 20 sees the signed URL.

The result is a `GET` URL with `X-Amz-Algorithm`, `X-Amz-Credential` (access key ID and scope, public by design), `X-Amz-Date`, `X-Amz-Expires`, `X-Amz-SignedHeaders=host` and `X-Amz-Signature`, payload `UNSIGNED-PAYLOAD`. Validity: 300 s (`wplc_s3_presign_expires`, clamped to 1 s … 604800 s). The update download route (`UpdateService::download()`) and the customer-account download redirect to it after all license, installation and release checks; `HEAD` on the grant route is still answered by the shop without a redirect. Publishing a URL release fetches the object once through the same resolver (license ID 0) to verify it. The signed URL is never written to the audit log; a download error message repeats only the host.

The shop's clock must be right: the storage refuses a link whose `X-Amz-Date` lies too far in the future, and a link expires `X-Amz-Expires` seconds after it.

### Credential check

`wp wplc storage check [--key=<key>]` prints endpoint host, bucket, region and addressing style and sends one signed `HEAD` (60 s validity, no redirects, 10 s timeout) to the bucket or the object. Answers: 2xx ok; 403 denied (key, secret, region, clock or permission); 404 bucket or object not found; 301/307/400 wrong region or endpoint, or path-style needed; transport error unreachable. Exit code 1 on failure. The signed URL and the secret are never printed.

## Other object storage: your own resolver

For storage that is not S3-compatible, or to sign links differently, use `url` storage with your own resolver: implement `wplc_resolve_update_package_url` to return a pre-signed URL and add the host under **License settings → Additional trusted package hosts** (or with the filter `wplc_update_package_hosts`). Register the release with the permanent URL; the object itself stays private.

```php
// 1. Allow the host. The host must match exactly; HTTPS and port 443 only.
add_filter( 'wplc_update_package_hosts', function ( array $hosts ) {
	$hosts[] = 'downloads.example.com';
	return $hosts;
} );

// 2. Replace the permanent URL with a short-lived signed one (priority below 20: before the built-in resolver).
add_filter( 'wplc_resolve_update_package_url', function ( $url, $license_id ) {
	if ( 'downloads.example.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $url; // Not ours, e.g. a local package.
	}
	return my_sign_download_url( $url, 5 * MINUTE_IN_SECONDS ); // Credentials from the environment, never from the database.
}, 10, 2 );

// 3. Nothing to do: since A-32 the server never hands out the unchanged permanent URL,
//    even if the resolver is missing one day (`wplc_require_package_resolver` defaults to true).
```

For Google Cloud Storage with its own SDK, return `$bucket->object( $key )->signedUrl( new DateTime( '+5 minutes' ), array( 'version' => 'v4' ) )` and allow `storage.googleapis.com` (or use the built-in storage with HMAC keys).

### Without a resolver a `url` release is not delivered

Redirecting a client to the stored URL would tell every licensed installation the permanent address, which it could keep using or pass on after its license has ended. Since A-32 the server therefore refuses (404; the customer-account download shows an error) to redirect to an unchanged stored URL, and the releases screen says that such releases are not delivered. Either keep the object private and add a resolver, or upload the ZIP (`local`).

A shop that accepts the permanent address (for example a public mirror of a free download) restores the old behaviour with `add_filter( 'wplc_require_package_resolver', '__return_false' );`. The releases screen then warns that customers learn the address.

### Rules for a resolver

- A download link is valid for five minutes (`UpdateService::TOKEN_TTL`, 300 seconds) and, by default, for one `GET`: the grant is marked as used **before** the resolver runs (strict single use, T-108, `UpdateService::download()`). If the resolver returns nothing usable, the download answers 404 and the link is spent; the client asks for a new one with `POST /updates/check`. Only a shop that switched the reuse window on (`wplc_download_reuse_seconds` above 0) gets the older order: the resolver runs first, and a failed resolver leaves the grant unused.
- It also runs once when a `url` release is **published**: the server downloads the package through it (license ID `0`) to compare the SHA-256 and to check the folder and the `Version:` header. The shop server must therefore be able to reach the storage. Switch this off with `add_filter( 'wplc_verify_url_release_on_publish', '__return_false' )` only if your pipeline verifies the package itself.
- The returned URL must be HTTPS and its host must be allowed, otherwise the download fails closed with 404.
- The URL must be short-lived, read-only and point to the exact bytes of the published SHA-256. Do not overwrite objects; upload each version under its own key.
- The second argument is the license ID, e.g. for logging on the storage side.
- Keep credentials in server configuration. Never put them into Git, a manifest, license data or logs.
- A product client follows the redirect to the storage host. If the client restricts redirect targets, the bucket host has to be allowed there too (there is no server-side filter for that).

A withdrawn `url` release is no longer delivered: grants issued before the withdrawal answer 404.

### After a domain move

Package URLs that point to the shop's own old host are no longer on the allow-list after the shop moved to another domain; update checks for those products answer 503 `package_configuration_invalid`. The releases screen lists every published release with such a URL (`ReleaseRegistry::problems()`, code `untrusted_package_host`). Add the old host under **License settings → Additional trusted package hosts** while it still serves the files, or publish new releases. Uploaded (`local`) packages are not affected: their delivery URL is built from the current site address on every request.

This plugin ships no AWS or Google SDK. Credentials, bucket policy, retention rules and the upload of packages into the bucket belong to the deployment.

## Customer downloads

The customer-account download (see [UPDATES.md](UPDATES.md)) uses the same storage: a stream link for `local`, and for `url` the same `wplc_resolve_update_package_url` resolvers (custom ones, then the built-in object storage) followed by the same HTTPS, host and resolver checks.
