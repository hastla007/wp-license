# Outgoing webhooks

Since SET-LIC-46 (owner decision E-09 of 23 Nov 2026: for every customer, built now) the license server sends license events of the shop to other systems as signed HTTPS requests: a CRM, an accounting tool, an automation platform, the vendor's own backend. It is the HTTP form of the versioned hook contract in [LICENSE-EVENTS.md](LICENSE-EVENTS.md): the same eight actions, the same "after commit" promise, the same rule that no license key, site address, email address or payment data is passed. Plugins in the same WordPress keep using the PHP actions directly.

Code: `src/Webhooks/` (`EventCatalog`, `Payload`, `UrlGuard`, `Endpoints`, `Deliveries`, `Dispatcher`, `Admin`), `src/CLI/WebhookCommand.php`; tables `{prefix}wplc_webhook_endpoints` and `{prefix}wplc_webhook_deliveries` (schema 31).

## Setting up an endpoint

**WooCommerce → License webhooks** (capability `wplc_manage_policy`, by default administrators; a webhook sends events about every customer to another system, so a shop manager cannot add one) or WP-CLI:

```
wp wplc webhook add https://hooks.example.com/licenses --events=license.issued,license.status_changed --name="CRM"
wp wplc webhook list
wp wplc webhook test 1
wp wplc webhook delete 1 --yes
```

An endpoint has a name, a target URL, the selected events and an on/off switch. When it is saved, a **secret** (`whsec_` and 48 hex characters) is generated. It is shown **once** (on the screen after saving, or printed by `wp wplc webhook add`) and stored encrypted with the key ring of the installation secrets (`WPLC_INSTALLATION_ENCRYPTION_KEYS`, AES-256-GCM, `Security\SecretCrypto`). "Generate new secret" replaces it; the old one stops working at once. Without a working encryption key the endpoint is not saved. At most 20 endpoints per site; on a multisite network every site has its own.

Every change (added, changed, switched off, deleted, new secret, switched off after failures) is written to the audit log with object type `webhook`. The entry holds the name, the **host** of the URL (never its path or query: automation platforms put access tokens there), the events and the switch; never the secret.

### Which target URLs are accepted (SSRF protection)

- `https://` only, port 443 (no other port), no user name or password in the URL, at most 2000 characters.
- No local host names: names without a dot, `localhost`, and names ending in `.localhost`, `.local`, `.internal`, `.lan`, `.home.arpa`, `.localdomain`, `.intranet`, `.corp`, `.test`, `.invalid`, `.example`.
- Every address the host resolves to (A and AAAA) must be public. Refused: loopback, private (10/8, 172.16/12, 192.168/16), link-local (169.254/16, including the cloud metadata address), shared address space (100.64/10), documentation and benchmark ranges, multicast, reserved, `0.0.0.0/8`; IPv6 `::`, `::1`, `fc00::/7`, `fe80::/10`, `fec0::/10`, `ff00::/8`, `2001:db8::/32`, `2001::/23`, `2002::/16`, `100::/64`; IPv4-mapped (`::ffff:a.b.c.d`) and NAT64 (`64:ff9b::/96`) addresses are judged by their IPv4 part. One private address among public ones is enough to refuse.
- The check runs when the endpoint is saved **and again before every request**. The request then goes to the checked address only (cURL `CURLOPT_RESOLVE`), so a second DNS answer (DNS rebinding) cannot redirect it. It is sent with `wp_safe_remote_post()` (WordPress's own check of unsafe URLs as a second layer) and **redirects are not followed**: a 3xx answer counts as a failure.

## The request

`POST` to the target URL, timeout 10 seconds, the answer is read up to 4 KB and otherwise ignored. Any **2xx** status is a success; everything else, and no answer, is a failure.

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json; charset=utf-8` |
| `User-Agent` | `WP-License-Captain-Webhooks/<plugin version>` |
| `X-WPLC-Event-Id` | Event ID (UUID v4): the same for every endpoint, every attempt and a redelivery. Use it as the idempotency key. |
| `X-WPLC-Event` | Event name, e.g. `license.status_changed` |
| `X-WPLC-Timestamp` | Unix time of this attempt (seconds) |
| `X-WPLC-Attempt` | Number of the attempt, 1 to 5 |
| `X-WPLC-Signature` | `v1=` and the hex HMAC-SHA256 of `<timestamp>.<body>` with the endpoint secret as key |

Body (JSON, slashes and Unicode not escaped):

```json
{
  "id": "6f1c2a0e-8a43-4f5e-9d61-2b7e4c1d9a30",
  "event": "license.status_changed",
  "hook": "wplc/license_status_changed",
  "contract_version": "1.0.0",
  "webhook_version": 1,
  "created_at": "2026-11-23T10:15:00Z",
  "source": "https://shop.example.com/",
  "data": {
    "license_id": 812,
    "old_status": "active",
    "new_status": "suspended",
    "action": "chargeback",
    "license": {
      "product_key": "acme-reports-pro",
      "plan_key": "business",
      "status": "suspended",
      "expires_at": "2027-05-01T00:00:00Z"
    }
  }
}
```

- `id`: event ID (also in `X-WPLC-Event-Id`).
- `event`, `hook`: the webhook event and the action of the hook contract it comes from.
- `contract_version`: version of the hook contract (`WPLC_CONTRACT_VERSION`, see [EXTENSION-POINTS.md](EXTENSION-POINTS.md#hook-contract-for-plugins-in-the-shop-version-100)): the meaning of `data` follows its rules (a field is not removed or reinterpreted without a new MAJOR version). `webhook_version`: version of this envelope (headers, field names, signature), currently `1`.
- `created_at`: when the event happened (UTC), not when it was sent.
- `source`: the address of the shop (`home_url( '/' )`), so one receiver can serve several shops.
- `data`: the arguments of the action by name, plus `license` for every event with a `license_id`: product, plan, status and end of the paid period **as read when the event was queued** (so a later change does not rewrite an older event). Fetch the current state yourself if you need it.

### Events

| Event | Action of the hook contract | `data` (besides `license`) |
| --- | --- | --- |
| `license.issued` | `wplc/license_issued_followup` | `license_id`, `order_id` (0 for a license issued by hand) |
| `license.status_changed` | `wplc/license_status_changed` | `license_id`, `old_status`, `new_status`, `action` (`renewal_succeeded`, `renewal_failed`, `license_expired`, `chargeback`, `order_cancelled`, `order_refund`, `admin_status_change`, `admin_restore`, `admin_extend`) |
| `license.plan_changed` | `wplc/license_plan_changed` | `license_id` |
| `license.site_limit_reached` | `wplc/site_limit_reached` | `license_id`, `environment` (`live`, `staging`, `development`), `limit` |
| `activation.created` | `wplc/activation_created` | `license_id`, `activation_id`, `environment` |
| `activation.removed` | `wplc/activation_removed` | `license_id`, `activation_id`, `environment`, `initiator` (`api`, `customer`, `support`, `system`) |
| `release.published` | `wplc/release_published` | `release_id`, `product_key`, `version`, `channel` (no `license`) |
| `release.withdrawn` | `wplc/release_withdrawn` | as `release.published` |
| `webhook.test` | none ("Send test event", `wp wplc webhook test`) | `message`; cannot be subscribed to |

When each action fires is described in [LICENSE-EVENTS.md](LICENSE-EVENTS.md#actions). The actions that pass the plaintext key (`wplc/license_issued`, `wplc/license_key_reissued`, `wplc/license_key_resend_requested`) and the other actions of the plugin (not promised after commit, some with reason texts) are **not** offered.

## Verifying the signature

Compute the HMAC over the **raw** body exactly as received (before any JSON decoding), compare in constant time, and refuse old timestamps so a recorded request cannot be replayed. A PHP receiver:

```php
<?php
$secret    = getenv( 'WPLC_WEBHOOK_SECRET' ); // The secret shown once when the endpoint was saved.
$body      = file_get_contents( 'php://input' );
$timestamp = $_SERVER['HTTP_X_WPLC_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WPLC_SIGNATURE'] ?? '';

$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || ! hash_equals( $expected, $signature ) ) {
	http_response_code( 401 );
	exit;
}

$event = json_decode( $body, true );
// Idempotency: the same event can arrive more than once (retries, "Send again").
if ( already_processed( $event['id'] ) ) {
	http_response_code( 200 );
	exit;
}
queue_for_processing( $event ); // Answer quickly; do slow work after the response.
http_response_code( 204 );
```

The reference implementation is `WPLC\Webhooks\Payload::verify()` (tested in `tests/Webhooks/WebhookPayloadTest.php`). Answer within 10 seconds with a 2xx status; do the real work later.

## Retries, idempotency, switching off

- A delivery is queued by a listener (priority 50) of the contract action, which fires after the commit; the listener queues through `AfterCommit` as well. A rolled-back change queues nothing. The request that changed the license never waits for the receiver: the attempt runs as an Action Scheduler job (`wplc_webhook_deliver`, group `wplc`; WP-Cron without Action Scheduler).
- **Five attempts**: the first at once, then after 1, 5, 30 and 120 minutes (filter `wplc_webhook_retry_delays`; at most nine retries of up to a day each; an empty list means one attempt). The body and the event ID stay the same; `X-WPLC-Timestamp`, `X-WPLC-Attempt` and the signature change per attempt.
- **At least once**: a receiver can get an event more than once (an answer lost on the way back, "Send again", a stuck attempt sent again after ten minutes). Deduplicate by `id`. The order of events is not guaranteed; use `created_at` and fetch the current state when it matters.
- Only one request sends a given delivery at a time (conditional update `pending`/`retrying` → `sending`), so a duplicate queue entry sends nothing.
- When a delivery has failed after its last attempt **five times in a row** for an endpoint (filter `wplc_webhook_disable_after_failures`, 0 = never), the endpoint is **switched off**: audit entry `webhook_endpoint_disabled`, action `wplc/webhook_endpoint_disabled` (endpoint ID, number), and a notice on every admin screen for users with `wplc_manage_policy` until it is switched on again or deleted. Queued deliveries of a switched-off endpoint are not sent (status "not sent"); they can be sent again from the log. Switching it on resets the counter. A success also resets it. The test event never counts.
- **Send again** (delivery log) queues a finished delivery with the same event ID and body; its attempts start at one.

## Delivery log and retention

One row per event and endpoint: event, event ID, status (`pending`, `sending`, `retrying`, `delivered`, `failed`, `cancelled`), number of the last attempt, HTTP status, duration in milliseconds, a short error (at most 190 characters, e.g. `HTTP 503`, `cURL error 28: …`, `Address refused: blocked_address`), the time of the next attempt, and the body (for retries and "Send again"). The screen shows the newest 50 per endpoint.

- At most the newest 100 finished rows are kept per endpoint (older ones are removed when a new delivery is added).
- Retention rule **`webhook_deliveries`** (daily, `Maintenance\Retention`, setting "Keep the webhook delivery log for" under License settings → Legal and privacy, default **30 days**, filter `wplc_retention_days`, 0 = off): rows created before the period are deleted; queued or retrying rows get one more day.
- Deleting an endpoint deletes its log.

## What is sent, and what not (privacy)

Sent: event name, event ID, time, contract and envelope version, the shop address, record IDs (license, order, activation, release), status codes and reason codes, environment, initiator, site limit, product key, plan key, release version and channel, end of the paid period. Not sent: license keys or any part of them (also not the key prefix shown as "reference"), key hashes, secrets, site addresses or installation IDs, email addresses, names, the customer's user ID, payment data, reason texts. The license ID and order ID are pseudonymous references: whoever also has the shop's database can link them to a person. The receiver is a recipient in the sense of data protection law; the shop operator decides whether a processing agreement is needed (legal review, T-93). See [PRIVACY-DATA-FLOWS.md](PRIVACY-DATA-FLOWS.md).

The filter `wplc_webhook_payload` can add fields (once per event, every endpoint gets the same body). It must not add keys, secrets or personal data the receiver does not need: the body is stored in the delivery log until the retention rule removes it. `id`, `event` and `created_at` cannot be changed by the filter.

## Filters and actions

| Hook | Kind | Purpose |
| --- | --- | --- |
| `wplc_webhook_payload` | filter | `( array $envelope, string $event )` → body |
| `wplc_webhook_retry_delays` | filter | `( int[] $delays )` seconds after attempt 1, 2, … |
| `wplc_webhook_disable_after_failures` | filter | `( int $failures )` default 5, 0 = never |
| `wplc/webhook_endpoint_disabled` | action | `( int $endpoint_id, int $failures )` after an endpoint was switched off |
| `wplc_retention_days` | filter | key `webhook_deliveries` |

## Evidence (23 Nov 2026)

- *Unit*: `tests/Webhooks/WebhookPayloadTest.php` (signature, verification with age limit, headers, envelope, backoff, catalog without key actions, typed data), `tests/Webhooks/UrlGuardTest.php` (30 URL forms, every blocked range, resolved addresses, rebinding), `tests/Infrastructure/SchemaMigrationStepsTest.php` (schema 31), `tests/Maintenance/RetentionCutoffTest.php`.
- *Integration*: `tests/integration/WebhooksIntegrationTest.php`: a status change in a transaction queues nothing before the commit and one Action Scheduler job after it; the request is signed and verifies with the secret shown once, carries no key, key prefix, hash, email address or customer ID; duplicate queue entries send once; a rolled-back change queues nothing; no webhook query without an active endpoint; retries after 1/5/30/120 minutes with the same event ID and body, failed after the fifth; redelivery; switching off after five failed deliveries with action, notice (administrator yes, shop manager no) and cancelled queue; the address is checked again before the request (rebinding to 169.254.169.254: no request); private, local and plain-http targets cannot be saved; the secret is stored encrypted and is not in the audit log (nor the URL path); test event; payload filter; retention and the 100-row cap; capability and nonce of the admin actions; the admin screen shows the secret once, tests, sends again and deletes with confirmation; WP-CLI `add`. `tests/integration/00SchemaUpgradeIntegrationTest.php`: upgrade from schema 28.
