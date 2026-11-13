# Security and key operations

## Stable secrets

Configure two distinct random secrets in `wp-config.php` before issuing production licenses or activating product installations:

- `WPLC_LICENSE_KEY_SECRET` (or the key ring `WPLC_LICENSE_KEY_SECRETS` plus `WPLC_LICENSE_KEY_ID`) derives displayable license keys.
- `WPLC_INSTALLATION_ENCRYPTION_KEY` (or the key ring `WPLC_INSTALLATION_ENCRYPTION_KEYS` plus `WPLC_INSTALLATION_ENCRYPTION_KEY_ID`) encrypts installation credentials with AES-256-GCM.

Use at least 32 random bytes per secret. Do not reuse WordPress salts, commit secrets, or expose them in logs, diagnostics, exports, support tickets, or CI. Keep encrypted backups in an access-controlled secrets manager and deploy the same active key ring on every application node.

The implementation can read current and previous key IDs and re-encrypt installation credentials lazily after authenticated use. It also provides bounded WP-CLI tools:

- `wp wplc rotate-installation-secrets` defaults to a dry run; use `--apply` only after backup and validation.
- `wp wplc recover-license-key-ids --to=<key-id>` defaults to a dry run and changes a row only when the existing license verifier matches a key derived by that key ID.

These tools have unit coverage, but operators must still rehearse them against a restored copy of real data. Do not remove an old key until recovery, restore, and client authentication have been verified.

## Response signing key (Ed25519)

Product builds pin the public key(s) of the response signing key; a server key that no installed build pins locks every customer out, including the update path. In production set `WPLC_RESPONSE_SIGNING_KEY` (base64 32-byte seed) in `wp-config.php` and keep an offline backup. Rotate only with overlap (docs/API-V1.md → Key rotation): configure a next key (`WPLC_RESPONSE_SIGNING_KEY_NEXT` or `wp wplc signing-key --next`), release product builds that pin both keys, wait until customers run them, then promote (`wp wplc signing-key --promote`, or move the value in `wp-config.php`). Keeping a pinned next key in every release also gives an emergency path when the current key is lost or compromised: promote the next key at once. `GET /wp-json/wplc/v1/signing-keys` lists the public keys for release checks.

## Request security and database controls

Version 1 product requests are signed over method, REST route, timestamp, nonce, and the SHA-256 digest of the raw request body. Timestamps are limited to five minutes. A valid nonce is atomically stored; replay returns a conflict. Per-installation request limits use an atomic database upsert and expire through a bounded scheduled cleanup. Authentication failures and storage outages fail closed.

Update download grants are stored as hashes, consumed atomically, and checked again against current license state before redirect. Restrict package hosts to HTTPS allowlisted hosts. Keep package URLs and grant records only for the documented operational retention period.

Renewal execution uses a per-renewal MySQL advisory lock and the provider adapter must pass an idempotency key through to the gateway. This cannot by itself resolve an ambiguous provider timeout: reconcile provider state before retrying a charge.

## Deployment and recovery checklist

- Use HTTPS for the license API and package delivery.
- Restrict database, `wp-config.php`, CI, and secret-manager access.
- Back up database and package metadata before schema, key, or release changes.
- Restore a recent backup to an isolated staging copy and verify schema, license verifiers, activation authentication, renewal links, and package records.
- Run key rotation and key-ID recovery in dry-run mode first; preserve command output with secrets redacted.
- Never repair production rows by direct SQL except through an approved incident process with before/after backups and an audit record.
- Do not enable automatic charges until a concrete gateway integration passes its sandbox scenarios for consent, token ownership, idempotency, webhooks, timeouts, refunds, and manual authentication.

## Current release evidence

The source contains unit tests and a WordPress/MySQL REST integration suite. Their CI execution and the full database/gateway/update acceptance are release gates. A runbook is not evidence that production infrastructure, backup restore, key rotation, alerts, privacy handling, or a payment account has been configured.
