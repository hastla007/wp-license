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

The client generates and retains the installation ID and secret locally. The server stores a SHA-256 verifier and an AES-256-GCM encrypted copy of the secret. The complete license key is not stored in plaintext.

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

## Credential rotation and recovery

The current implementation derives a displayable license key from its UUID and the WordPress authentication salt. The installation-secret encryption key is derived from the WordPress secure-auth salt. Rotating either salt can prevent a stored key or installation secret from being re-derived/decrypted. Production deployment must preserve these salts; a managed key-rotation and customer recovery flow remains required before general availability.

## Current API scope

The v1 endpoints currently cover activation, signed entitlement checks and deactivation. Staging activation, premium update metadata, signed download tokens and billing webhooks are later implementation steps.
