# Update package storage

A release package is stored in one of two ways.

| | `local` | `url` |
| --- | --- | --- |
| Where the ZIP lives | Private directory on the shop server | Any HTTPS host: S3, GCS, a download server |
| How it is added | Upload on **WooCommerce → License releases**, or `wp wplc release create --zip=…` | Package URL + SHA-256, or manifest import |
| Delivered by | Streaming route on the shop (PHP, or the web server via `X-Accel-Redirect`/`X-Sendfile`) | Redirect to the (pre-signed) URL |
| Does the client learn a permanent address? | No | **Yes, unless a resolver returns a pre-signed URL** (see below) |
| Good for | Small catalogs, no extra infrastructure | Large files, many downloads, CDN |

## Local private storage

Uploaded ZIPs are stored in `wp-content/uploads/wplc-packages/` (per site on multisite).

- The directory gets a deny-all `.htaccess`, a `web.config` and an empty `index.html`.
- **nginx ignores `.htaccess`.** Every file name therefore ends in 32 random hexadecimal characters (`{product}-{version}-{random}.zip`), and that name is never shown in the admin, written to the audit log or sent to a client. That alone is not a protection to rely on. Add a deny rule, or move the directory out of the web root:

  ```nginx
  location ^~ /wp-content/uploads/wplc-packages/ { deny all; }
  ```

  ```php
  add_filter( 'wplc_package_storage_dir', function () {
  	return '/var/lib/wplc-packages'; // Outside the web root, writable by PHP.
  } );
  ```

  **The plugin checks this.** When the releases screen is opened and a published upload exists, it writes a small probe file (`wplc-probe.txt`) into the directory, requests it over HTTP and deletes it again. If the web server hands the file out, the screen shows an error ("The package directory can be read over HTTP") until the rule is in place; "Check again" repeats the probe. The result is cached for a day (`WPLC\Update\LocalPackageStorage::exposure_status()`: `protected`, `exposed`, `outside_webroot` or `unknown`). A directory outside the uploads folder has no URL and is reported as `outside_webroot`.

- Uploads must be a real `.zip` of at most 50 MB (`wplc_package_max_upload_bytes`; PHP's `upload_max_filesize` and `post_max_size` apply as well) with exactly one top-level folder and a matching `Version:` header (see [UPDATES.md](UPDATES.md), "What a package must look like"). The SHA-256 is computed from the stored file and checked again when the release is published.
- Include the directory in backups. A release whose file is missing cannot be published, is not offered as an update and is listed as a problem on the releases screen.

### Several web servers

The package directory must be **the same directory on every web node** (NFS, EFS, a shared volume): an upload lands on the node that handled the request, and a download can reach any node. On a node without the file the release is skipped in the update feed, a download answers 404 without using the grant up, and the releases screen of that node shows "The uploaded package … is missing on this server". Point `wplc_package_storage_dir` at the shared mount, or use `url` storage.

### How a local package is delivered

1. The registry gives the update API a placeholder URL, `…/wp-json/wplc/v1/packages/release/{id}`. No route answers there; it only identifies the release inside the download grant.
2. When the client redeems its grant, `UpdateService::download()` checks that the release is still published and its file present, then applies `wplc_resolve_update_package_url`. `WPLC\Update\PackageStream::resolve()` recognizes the placeholder, stores a stream grant in `{prefix}wplc_package_streams` (only the SHA-256 of the token) and returns `…/wp-json/wplc/v1/packages/{token}`.
3. That route checks the grant, the license and the release, marks the grant as used with one conditional `UPDATE`, and sends the file with `Content-Type: application/zip`, `Content-Length`, `Content-MD5`, `Accept-Ranges: bytes`, `Content-Disposition`, `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`.

A stream link is valid for 5 minutes. `HEAD` does not use it up, and after its first `GET` it keeps working for 120 seconds (`wplc_download_reuse_seconds`), so a second fetch by a download manager or a `Range` request that resumes an interrupted download succeeds. After that a new update check is needed. Old grants are deleted by the daily `wplc_daily_lifecycle` job.

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

## S3, GCS and other object storage

Use `url` storage: implement `wplc_resolve_update_package_url` to return a pre-signed URL and add the bucket host under **License settings → Additional trusted package hosts** (or with the filter `wplc_update_package_hosts`). Register the release with the permanent object URL; the object itself stays private.

```php
// 1. Allow the bucket host. The host must match exactly; HTTPS and port 443 only.
add_filter( 'wplc_update_package_hosts', function ( array $hosts ) {
	$hosts[] = 'my-releases.s3.eu-central-1.amazonaws.com';
	return $hosts;
} );

// 2. Replace the permanent URL with a short-lived pre-signed one.
add_filter( 'wplc_resolve_update_package_url', function ( $url, $license_id ) {
	if ( 'my-releases.s3.eu-central-1.amazonaws.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $url; // Not ours, e.g. a local package.
	}
	$s3 = new Aws\S3\S3Client( array(
		'version' => 'latest',
		'region'  => 'eu-central-1',
		// Credentials from the environment or instance role, never from the database.
	) );
	$command = $s3->getCommand( 'GetObject', array(
		'Bucket' => 'my-releases',
		'Key'    => ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ),
	) );
	return (string) $s3->createPresignedRequest( $command, '+5 minutes' )->getUri();
}, 10, 2 );

// 3. Never hand out the permanent URL, even if the resolver is missing one day.
add_filter( 'wplc_require_package_resolver', '__return_true' );
```

For Google Cloud Storage, return `$bucket->object( $key )->signedUrl( new DateTime( '+5 minutes' ), array( 'version' => 'v4' ) )` and allow `storage.googleapis.com`.

### Without a resolver the address is permanent

A `url` release without a resolver is delivered by redirecting the client to the stored URL. Every licensed installation therefore learns that address and can keep using or pass it on after its license has ended. The releases screen warns about this. Either keep the object private and add a resolver, or upload the ZIP (`local`). With `wplc_require_package_resolver` returning `true`, the server refuses (404, grant not used) to redirect to an unchanged stored URL.

### Rules for a resolver

- It runs **before** the grant is marked as used. If it returns nothing usable, the download answers 404 and the client can try the same link again within its ten minutes.
- It also runs once when a `url` release is **published**: the server downloads the package through it (license ID `0`) to compare the SHA-256 and to check the folder and the `Version:` header. The shop server must therefore be able to reach the storage. Switch this off with `add_filter( 'wplc_verify_url_release_on_publish', '__return_false' )` only if your pipeline verifies the package itself.
- The returned URL must be HTTPS and its host must be allowed, otherwise the download fails closed with 404.
- The URL must be short-lived, read-only and point to the exact bytes of the published SHA-256. Do not overwrite objects; upload each version under its own key.
- The second argument is the license ID, e.g. for logging on the storage side.
- Keep credentials in server configuration. Never put them into Git, a manifest, license data or logs.
- A product client follows the redirect to the storage host. If the client restricts redirect targets, the bucket host has to be allowed there too (there is no server-side filter for that).

A withdrawn `url` release is no longer delivered: grants issued before the withdrawal answer 404.

### After a domain move

Package URLs that point to the shop's own old host are no longer on the allow-list after the shop moved to another domain; update checks for those products answer 503 `package_configuration_invalid`. The releases screen lists every published release with such a URL (`ReleaseRegistry::problems()`, code `untrusted_package_host`). Add the old host under **License settings → Additional trusted package hosts** while it still serves the files, or publish new releases. Uploaded (`local`) packages are not affected: their delivery URL is built from the current site address on every request.

This plugin ships no AWS or Google SDK, credentials, bucket policy or retention rules; those belong to the deployment.

## Customer downloads

The customer-account download (see [UPDATES.md](UPDATES.md)) uses the same storage: a stream link for `local`, and for `url` the same `wplc_resolve_update_package_url` resolver followed by the same HTTPS, host and resolver checks.
