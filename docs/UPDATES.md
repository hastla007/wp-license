# Update package integration

An update provider registers package metadata with the `wplc_update_packages` filter. The value is keyed by product key and includes `version`, `package_url` (HTTPS), `sha256` (64 lowercase or uppercase hex characters), and optional `requires_php`, `requires_wp`, `tested_wp`, and `release_notes`.

The package host must also be added to `wplc_update_package_hosts`. This is an explicit allow-list; arbitrary redirects and non-HTTPS package URLs are rejected. The license client signs `POST /wp-json/wplc/v1/updates/check` using the same installation HMAC headers as entitlement checks and supplies `product_key` and `current_version`. The response envelope is signed with the installation secret and contains a ten-minute, one-use `package_url`. The download endpoint consumes the token and redirects to the configured package URL.

The plugin client must verify the response HMAC and compare the downloaded ZIP SHA-256 to the returned checksum before installing it. Package signing, release storage, and client-side update integration are separate release responsibilities. Keep the package host private or use a provider URL whose access controls remain valid for the redirect lifetime.
