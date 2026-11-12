# Update package storage provider contract

The update registry still exposes a stable HTTPS package URL and SHA-256. After a one-use download grant is atomically consumed, the server calls:

```php
add_filter( 'wplc_resolve_update_package_url', $resolver, 10, 2 );
```

The resolver receives the configured package URL and license ID and may return a short-lived HTTPS URL (for example, a private object-storage signed URL). Its returned host must pass the same `wplc_update_package_hosts` allow-list or the download fails closed. The client separately allow-lists the redirect host through `wplc_client_update_hosts` and verifies the ZIP digest before install.

The resolver runs only after the one-use token is consumed; a failed resolver therefore requires the client to request a fresh update grant. Provider credentials belong in protected server configuration. Never put provider credentials into Git, the manifest, license data, or logs.

This repository supplies only the hook, not an AWS/S3/GCS adapter, credentials, bucket policy, retention, or deployment. A provider implementation must ensure that the resulting URL is HTTPS, short-lived, read-only, and points to immutable bytes matching the published SHA-256.
