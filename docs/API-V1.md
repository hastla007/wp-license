# WP License Captain REST API v1

Base namespace: `/wp-json/wplc/v1`.

All requests must use HTTPS. Activation requests are rate-limited by source IP. Authenticated installation requests are signed with the secret created by the product client and rate-limited by installation ID.

## First activation

`POST /activations`

JSON body:

```json
{
  "license_key": "WPLC-...",
  "product_key": "voucher-captain",
  "installation_id": "uuid-v4",
  "installation_secret": "at-least-32-random-characters",
  "site_url": "https://shop.example",
  "product_version": "1.0.0",
  "wp_version": "6.8.0",
  "php_version": "8.2.0"
}
```

The client generates and retains the installation ID and secret locally. The optional `environment` is `production`, `staging` or `development`; staging also requires `staging_parent_installation_id` for an active live activation on the same license. A license allows at most one active staging site per live activation. Development activations do not consume a live-site slot. The server stores a SHA-256 verifier and an AES-256-GCM encrypted copy of the secret. The complete license key is not stored in plaintext.

Successful responses use the envelope:

```json
{
  "data": {
    "activation_id": 123,
    "installation_id": "uuid-v4",
    "license_status": "active",
    "paid_until": "2027-11-12 00:00:00",
    "site_limit": 3,
    "entitlements": {},
    "server_time": "2026-11-12 00:00:00",
    "cache_expires_at": "2026-11-26 00:00:00"
  },
  "signature": "hex HMAC-SHA-256"
}
```

The response signature is HMAC-SHA-256 over the JSON encoding of `data` with `JSON_UNESCAPED_SLASHES`, keyed by the installation secret.

## Authenticated requests

For every request after activation, send:

- `X-WPLC-Installation-Id`: installation UUID
- `X-WPLC-Timestamp`: Unix timestamp in seconds
- `X-WPLC-Nonce`: unique random value, 16–80 URL-safe characters
- `X-WPLC-Signature`: lowercase hex HMAC-SHA-256

The signature input is UTF-8 text joined by newline characters:

```
UPPERCASE_METHOD
REST_ROUTE
TIMESTAMP
NONCE
SHA256_OF_RAW_REQUEST_BODY
```

The server rejects timestamps outside five minutes, repeated nonces, malformed signatures and inactive activations.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/activations` | Create or idempotently retrieve an activation |
| DELETE | `/activations/{activation_id}` | Deactivate the authenticated installation |
| POST | `/entitlements/check` | Return current status and feature rights |
| POST | `/updates/check` | Return signed release metadata and a short-lived package grant |
| GET | `/updates/download/{token}` | Consume a one-use grant and redirect to the allow-listed package host |

## Error responses

Errors use WordPress REST's JSON error envelope: a stable `code`, a human-readable `message`, and an HTTP status. Clients should branch on `code`, not message text. Current codes include:

| Code | HTTP | Meaning |
| --- | ---: | --- |
| `invalid_activation` | 400 | Required activation fields are missing or invalid |
| `invalid_key` | 401 | License key is unknown |
| `invalid_request_signature` | 401 | Authenticated request headers or HMAC are invalid |
| `request_expired` | 401 | Timestamp is outside the five-minute window |
| `activation_not_found` | 401 | Installation is inactive or not registered |
| `product_mismatch` | 403 | License is for a different product |
| `expired` / `license_inactive` | 403 | License cannot be used for this operation |
| `request_replayed` / `activation_conflict` / `site_limit` | 409 | Request conflicts with current activation or license state |
| `rate_limited` | 429 | Request limit was reached |
| `retry_later` | 503 | A temporary lock or service condition prevents processing |

Error codes are versioned API behavior. Billing webhooks are outside v1; payment state is reconciled through the configured WooCommerce gateway adapter, not an unauthenticated client event route.

## Credential rotation and recovery

New installations can use `WPLC_LICENSE_KEY_SECRET` to derive displayable license keys and `WPLC_INSTALLATION_ENCRYPTION_KEY` to encrypt installation secrets. For development compatibility, missing constants fall back to the WordPress auth and secure-auth salts. Existing records created with salt-derived keys/secrets are not migrated automatically: adding constants to an existing store can invalidate customer keys or make saved installation secrets undecryptable. Preserve the original derivation values until a tested migration and recovery procedure is completed. See `docs/SECURITY-OPERATIONS.md`.

## Current API scope

The v1 endpoints cover activation, signed entitlement checks, deactivation, and premium update metadata/download grants. Update metadata is provided by the trusted `wplc_update_packages` filter; package URLs must use HTTPS and a host included in `wplc_update_package_hosts`. The download grant expires after ten minutes and is consumed on use. The product client must verify the signed metadata and ZIP SHA-256 before installation. Billing webhooks and concrete gateway adapters are not implemented in this milestone.
