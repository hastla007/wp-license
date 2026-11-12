# Security and key configuration

## Stable secrets

For a new production license server, configure two distinct random secrets in wp-config.php before issuing the first license or activating the first product installation:

- WPLC_LICENSE_KEY_SECRET: at least 32 random bytes, used to derive displayable license keys.
- WPLC_INSTALLATION_ENCRYPTION_KEY: at least 32 random bytes, used as the AES-256-GCM key source for installation secrets.

Do not reuse the WordPress salts for these values. Keep backups in a secrets manager and deploy the same values to every application node. The current implementation falls back to WordPress auth/secure_auth salts when the constants are absent for development compatibility.

Do not add either constant to an existing installation without a planned migration. Existing displayable keys were derived from the auth salt, and encrypted installation secrets were derived from secure_auth. Changing the derivation source without migrating existing records can invalidate customer keys or make authenticated license API calls fail. Key rotation and re-encryption tooling remain a production release blocker.

## Database protections

Schema version 5 stores only a SHA-256 hash for each short-lived update token. Consumption is an atomic conditional update; the redirect is issued only if the associated license remains active and paid at the time of download. Consumed package URLs are cleared as part of the atomic claim. Expired/consumed grant rows are purged by the daily lifecycle job; `wplc_download_grant_retention_days` controls retention (default 7 days, clamped to 1–3650 days).

Renewal attempts are serialized with a per-renewal MySQL advisory lock. A gateway adapter must still send the supplied idempotency key to its payment provider because a process can terminate after the provider accepts a charge but before WooCommerce records it.

## Deployment checklist

- Use HTTPS for all license API and package host traffic.
- Restrict database and wp-config.php access; do not expose secrets through logs, support exports, or error pages.
- Configure Action Scheduler monitoring and database backups.
- Test restore procedures before changing salts or deployment keys.
- Do not enable automatic charges until a concrete adapter verifies off-session consent, token ownership, provider confirmation, and idempotency.
