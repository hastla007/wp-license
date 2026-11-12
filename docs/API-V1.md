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

## Credential rotation and recovery

Installation secrets are encrypted in a versioned envelope (`v1:<key-id>:<base64>`). Configure a key ring in `wp-config.php` before activating this release:

```php
define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(
	'2026-01' => getenv( 'WPLC_INSTALLATION_KEY_2026_01' ),
	'2026-06' => getenv( 'WPLC_INSTALLATION_KEY_2026_06' ),
) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '2026-06' );
```

Keep the previous key in the ring while ciphertexts still refer to its key ID. New writes use the configured current ID; reads select the matching key. Remove an old key only after all rows have been re-encrypted and a restore test confirms the migration. Never commit real key values. Legacy unversioned ciphertext is still read using configured keys and, as a fallback, the historical WordPress `secure_auth` salt. Rotate existing activation rows with `wp wplc rotate-installation-secrets` (dry-run by default); after verifying the proposed counts and taking a tested database backup, use `--apply` in bounded batches. Keep old keys available until a subsequent dry-run reports no rows needing rotation and a restore test passes.

License keys now carry a `license_key_secret_id` in the license row. Configure a key ring in `wp-config.php` and retain every key ID still referenced by a license:

```php
define( 'WPLC_LICENSE_KEY_SECRETS', array(
	'legacy' => getenv( 'WPLC_LICENSE_KEY_LEGACY' ),
	'2026-06' => getenv( 'WPLC_LICENSE_KEY_2026_06' ),
) );
define( 'WPLC_LICENSE_KEY_ID', '2026-06' );
```

The schema migration assigns existing rows the key ID `legacy`. Set that entry to the exact secret previously used for those keys (the historical `WPLC_LICENSE_KEY_SECRET` or auth salt); missing secrets prevent the customer portal from re-deriving the display value, although stored key hashes remain verifiable. Do not remove old IDs until a managed license-key rotation and recovery migration has been tested. To check a candidate recovery ID without changing data, run `wp wplc recover-license-key-ids --to=<key-id>`; after reviewing counts and taking a tested backup, pass `--apply`. A row changes only if deriving its key with that configured ID reproduces its stored SHA-256 verifier.

## Current API scope

The v1 endpoints cover activation, signed entitlement checks, deactivation, and premium update metadata/download grants. Update metadata is provided by the trusted `wplc_update_packages` filter; package URLs must use HTTPS and a host included in `wplc_update_package_hosts`. The download grant expires after ten minutes and is consumed on use. The product client must verify the signed metadata and ZIP SHA-256 before installation. Billing webhooks and concrete gateway adapters are not implemented in this milestone.
