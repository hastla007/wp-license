# Update package integration

An update provider registers package metadata with the `wplc_update_packages` filter. The value is keyed by product key and includes `version`, `package_url` (HTTPS), `sha256` (64 lowercase or uppercase hex characters), and optional `requires_php`, `requires_wp`, `tested_wp`, and `release_notes`.

The package host must also be added to `wplc_update_package_hosts`. This is an explicit allow-list; arbitrary redirects and non-HTTPS package URLs are rejected. Download grants are stored by SHA-256 token hash in the schema v5 table and consumed by one conditional database update, so concurrent requests cannot reuse a grant. The active license is checked again when the token is consumed. The license client signs `POST /wp-json/wplc/v1/updates/check` using the same installation HMAC headers as entitlement checks and supplies `product_key` and `current_version`. The response envelope is signed with the installation secret and contains a ten-minute, one-use `package_url`. The download endpoint consumes the token and redirects to the configured package URL.

The plugin client must verify the response HMAC and compare the downloaded ZIP SHA-256 to the returned checksum before installing it. Package signing, release storage, and client-side update integration are separate release responsibilities. Keep the package host private or use a provider URL whose access controls remain valid for the redirect lifetime.


## Product client integration status

The Phase 17 client updater is proposed in separate stacked product-repository PRs:

- Voucher Captain: https://github.com/hastla007/wp-couponmaster/pull/11
- WP Pass Captain: https://github.com/hastla007/wp-passmaster/pull/21
- Affiliate Captain: https://github.com/hastla007/wpaffiliatemaster/pull/10

Each client consumes the update metadata, downloads through the one-use grant, verifies SHA-256, restricts the redirect host to an explicit HTTPS allow-list, and refreshes an expired grant once. The integration does not itself publish releases or configure storage. A release operator must publish a ZIP and matching manifest, configure `wplc_update_packages` and `wplc_update_package_hosts`, then validate the full update in a WordPress test shop before release. No production package storage or signed release pipeline is configured by this repository change.
