# WP License Captain REST API v1

Base namespace: `/wp-json/wplc/v1`.

All requests must use HTTPS. Failed activation attempts are rate-limited by client address (30 per minute) and by license-key prefix (20 per minute); a valid key is not blocked by other clients' failures; see [Rate limits behind a proxy or CDN](#rate-limits-behind-a-proxy-or-cdn). Authenticated installation requests are signed with the secret created by the product client and rate-limited by installation ID.

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
  "signature": "hex HMAC-SHA-256",
  "payload": "{\"data\":{\"activation_id\":123,...},\"nonce\":\"uuid-v4\"}",
  "payload_signature": "hex HMAC-SHA-256"
}
```

### Response signature

Every signed response carries two signatures, both keyed by the installation secret:

- `payload` is the exact JSON text the server signed: an object with the members `data` (the same content as the top-level `data`) and `nonce`. `payload_signature` is the lowercase hex HMAC-SHA-256 over that string exactly as transmitted.
- `nonce` echoes the `X-WPLC-Nonce` header of an authenticated request. `POST /activations` is not HMAC-authenticated, so there `nonce` is the `installation_id` from the request body.
- `data` and `signature` are the original v1 fields and remain unchanged: `signature` is HMAC-SHA-256 over the server's JSON encoding of `data` with `JSON_UNESCAPED_SLASHES`. A client can only check it by re-encoding `data` byte-identically, and it is not bound to a request.

Clients must verify in this order:

1. If `payload` or `payload_signature` is present: compare `payload_signature` with the HMAC of the received `payload` string in constant time, then JSON-decode `payload`, require `nonce` to equal the value sent with this request, and use `payload.data`. Ignore the top-level `data`.
2. Only if both fields are absent (a server older than this change): fall back to the `signature` check over the re-encoded `data`.

The bundled client additionally remembers that its server sent a `payload` at activation and from then on rejects responses without one, so stripping the new fields cannot force the weaker check. Product plugins that bundle an older client copy keep working because `data` and `signature` are still sent.

Signed responses are sent with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`; exclude `/wp-json/wplc/` from page and CDN caches as well.

### Activating the same site again

The client keeps its installation ID and secret in one option. If valid credentials are already stored, `LicenseClient::activate()` first calls `/entitlements/check` and returns that result instead of creating a second activation; pass `true` as the fourth argument (`$force`) to request a new activation regardless.

When a new installation ID activates with the same `environment` and the same normalized `site_url` as an existing active activation of the same license (lost options, database restore, migration), the server replaces the old activation instead of counting a second site: the old one is deactivated (audit action `site_replaced`), together with its staging site if it was a live site, and the new one takes the slot. The old credentials stop working (`activation_not_found`). Possession of the license key authorizes this.

Deactivating a live site, whether through this API, the customer account, or the admin, also deactivates the staging site linked to it.

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

Errors of the signed routes (`DELETE /activations/{activation_id}`, `POST /entitlements/check`, `POST /updates/check`) before the route itself runs:

| Status | Code | When |
| --- | --- | --- |
| 401 | `invalid_request_signature` | Headers missing or malformed, or the signature does not match |
| 401 | `request_expired` | Timestamp more than five minutes off |
| 401 | `activation_not_found`, `installation_secret_invalid` | Installation not active, or its stored credential cannot be read |
| 409 | `request_replayed` | Nonce already used by this installation in the last ten minutes; sign again with a new nonce |
| 429 | `rate_limited` | More than 120 requests by this installation within the current minute |
| 503 | `replay_store_unavailable`, `rate_limit_store_unavailable` | Replay protection or rate limiting could not be recorded; retry later |

Route-specific errors on top: `DELETE /activations/{activation_id}` answers `403 activation_mismatch`, `409 deactivation_failed` and `503 retry_later` (license busy); `POST /entitlements/check` answers `404 license_not_found`; `POST /updates/check` answers `400` for missing arguments, `403 license_inactive` and `503 package_configuration_invalid` or `download_token_error`. `docs/openapi-v1.json` declares all of them.

## Rate limits behind a proxy or CDN

Failed anonymous activation attempts (unknown key, invalid request) are counted per client address (IPv6: per /64) and per license-key prefix (the first 13 characters, stored only as a hash). Successful activations are not counted. After 30 failures per address or 20 per prefix within a minute, unknown keys and invalid requests are answered `429 rate_limited`, while a recognized key is still accepted; after another 30 (address) or 20 (prefix) requests in the same minute every request returns `429 rate_limited`. The reasoning is in `docs/SECURITY-OPERATIONS.md` (T-142). A request whose address cannot be determined is counted in one shared bucket.

By default the address is `REMOTE_ADDR`. Behind a reverse proxy or CDN that is the proxy, so all customers would share one bucket. Configure the forwarding header in `wp-config.php`:

```php
define( 'WPLC_TRUSTED_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP' ); // or 'HTTP_X_FORWARDED_FOR'
define( 'WPLC_TRUSTED_PROXIES', array( '173.245.48.0/20', '2400:cb00::/32' ) ); // addresses of your proxies
```

- The header is used only when `REMOTE_ADDR` matches an entry of `WPLC_TRUSTED_PROXIES` (single IPs or CIDR ranges, IPv4 and IPv6).
- For a list header such as `X-Forwarded-For` the right-most address that is not a trusted proxy is used; anything further left is supplied by the client and ignored.
- If `WPLC_TRUSTED_PROXIES` is not defined, the header is trusted from every peer. That is only safe when the origin server cannot be reached directly (firewall restricted to the proxy); otherwise anyone can send the header and choose their own bucket. With `X-Forwarded-For` and no proxy list the right-most entry is used, which is correct for exactly one proxy in front of the origin.
- The filter `wplc_client_ip` receives the resolved address (or an empty string) for custom setups.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/activations` | Create or idempotently retrieve an activation |
| DELETE | `/activations/{activation_id}` | Deactivate the authenticated installation |
| POST | `/entitlements/check` | Return current status and feature rights |
| POST | `/updates/check` | Return signed release metadata and a short-lived package grant |
| GET | `/updates/download/{token}` | Consume a one-use grant and redirect to the allow-listed package host (`Cache-Control: no-store`, `Referrer-Policy: no-referrer`) |

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

### Server signature (Ed25519)

Responses for a known installation also carry `signature_ed25519` and `key_id`.

`signature_ed25519` is the server's Ed25519 signature (detached, base64) of

```
wplc-response-v1\n{installation UUID, lowercase}\n{JSON of data, JSON_UNESCAPED_SLASHES}
```

The HMAC signatures (`signature`, `payload_signature`) prove integrity in transit and of the client's cache, but the client knows the installation secret itself. The Ed25519 signature proves that this license server produced the data for exactly this installation: product builds pin the server's public key (`wp wplc signing-key --field=public_key`) and reject responses and cached snapshots without a valid signature. `key_id` is the first 16 hex characters of the SHA-256 of the public key.

The key comes from the constant `WPLC_RESPONSE_SIGNING_KEY` (base64 seed or secret key; recommended) or is generated once and stored encrypted in the option `wplc_response_signing_key`. Rotating it requires a product release with the new public key. The fields are omitted only when PHP has neither sodium nor WordPress' sodium_compat.

A client should check all of: `payload_signature` over `payload`, the echoed nonce, and `signature_ed25519` over the JSON of `data` inside the verified payload.

Test vector: seed 32 × `0x01` → public key `iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=`; installation `a1b2c3d4-0000-4000-8000-000000000001`, data `{"license_status":"active"}` → signature `4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==`.
