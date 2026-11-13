# Update package storage

A release package is stored in one of two ways. In both cases the client never learns a permanent download address: it gets a one-use grant on the license server, and the real location is resolved only after that grant has been consumed.

| | `local` | `url` |
| --- | --- | --- |
| Where the ZIP lives | Private directory on the shop server | Any HTTPS host: S3, GCS, a download server |
| How it is added | Upload on **WooCommerce → License releases** | Package URL + SHA-256, or manifest import |
| Delivered by | PHP streaming route on the shop | Redirect to the (pre-signed) URL |
| Good for | Small catalogs, no extra infrastructure | Large files, many downloads, CDN |

## Local private storage

Uploaded ZIPs are stored in `wp-content/uploads/wplc-packages/` (per site on multisite).

- The directory gets a deny-all `.htaccess`, a `web.config` and an empty `index.html`.
- **nginx ignores `.htaccess`.** Every file name therefore ends in 32 random hexadecimal characters (`{product}-{version}-{random}.zip`), and that name is never shown in the admin, written to the audit log or sent to a client. Still add a deny rule, or move the directory out of the web root:

  ```nginx
  location ^~ /wp-content/uploads/wplc-packages/ { deny all; }
  ```

  ```php
  add_filter( 'wplc_package_storage_dir', function () {
  	return '/var/lib/wplc-packages'; // Outside the web root, writable by PHP.
  } );
  ```

- Uploads must be a real `.zip` (extension, ZIP signature, readable archive) of at most 50 MB (`wplc_package_max_upload_bytes`; PHP's `upload_max_filesize` and `post_max_size` apply as well). The SHA-256 is computed from the stored file and checked again when the release is published.
- Include the directory in backups. A release whose file is missing cannot be published or downloaded.

### How a local package is delivered

1. The registry gives the update API a placeholder URL, `…/wp-json/wplc/v1/packages/release/{id}`. No route answers there; it only identifies the release inside the download grant.
2. When the client redeems its grant, `UpdateService::download()` applies `wplc_resolve_update_package_url`. `WPLC\Update\PackageStream::resolve()` recognizes the placeholder, stores a stream grant in `{prefix}wplc_package_streams` (only the SHA-256 of the token) and returns `…/wp-json/wplc/v1/packages/{token}`.
3. That route checks the grant, the license and the release, marks the grant as consumed with one conditional `UPDATE` (so two parallel requests cannot both succeed), and streams the file with `Content-Type: application/zip`, `Content-Length`, `Content-Disposition`, `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`.

A stream link is valid for 5 minutes and for one request. An interrupted download needs a new update check. Old grants are deleted by the daily `wplc_daily_lifecycle` job.

Requirements: the shop must be reachable over **HTTPS on the standard port**, because the update API only redirects to such URLs. The shop host is always kept in `wplc_update_package_hosts`.

## S3, GCS and other object storage

Use `url` storage: implement `wplc_resolve_update_package_url` to return a pre-signed URL and add the bucket host via `wplc_update_package_hosts`. Register the release with the permanent object URL; the object itself stays private.

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
```

For Google Cloud Storage, return `$bucket->object( $key )->signedUrl( new DateTime( '+5 minutes' ), array( 'version' => 'v4' ) )` and allow `storage.googleapis.com`.

Rules for a resolver:

- It runs only after the one-use grant is consumed. If it fails, the client has to ask for a fresh update check.
- The returned URL must be HTTPS and its host must be in `wplc_update_package_hosts`, otherwise the download fails closed with 404.
- The URL must be short-lived, read-only and point to the exact bytes of the published SHA-256. Do not overwrite objects; upload each version under its own key.
- The second argument is the license ID, e.g. for logging on the storage side.
- Keep credentials in server configuration. Never put them into Git, a manifest, license data or logs.
- Product clients must allow the bucket host as redirect target (`wplc_client_update_hosts`).

This plugin ships no AWS or Google SDK, credentials, bucket policy or retention rules; those belong to the deployment.

## Customer downloads

The customer-account download (see [UPDATES.md](UPDATES.md)) uses the same storage: a stream link for `local`, and for `url` the same `wplc_resolve_update_package_url` resolver followed by the same HTTPS and host checks.
