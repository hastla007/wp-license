# 14. Outgoing webhooks

A webhook sends license events of your shop to another system as a signed HTTPS request: your CRM, your accounting tool, an automation platform or your own backend. You do not need PHP access to the shop server for it. Plugins that run in the same WordPress can listen to the license actions directly instead (chapter 6 and the hook contract in the plugin).

Webhooks are off until you add an endpoint.

## Adding an endpoint

**WooCommerce → License webhooks** → "Add endpoint". You need the capability `wplc_manage_policy` (by default administrators; a shop manager does not see the screen), because a webhook sends events about all your customers to another system.

| Field | What it does |
| --- | --- |
| Name | Optional label, e.g. "CRM". |
| Target URL | The address of the receiver. Only `https://` on port 443, no user name or password in the URL. |
| Events | One or more of the events below. |
| Active | Whether events are sent to it. |

When you save, the server generates a **secret** (`whsec_` followed by 48 hex characters) and shows it **once**. Copy it into the receiving system: it checks the signature with it. The secret is stored encrypted with the encryption key ring of `wp-config.php` (`WPLC_INSTALLATION_ENCRYPTION_KEYS`, chapter 2); if you lose it, click **Generate new secret** (the old one stops working at once).

The same on the command line (chapter 10):

```
wp wplc webhook add https://hooks.example.com/licenses --events=license.issued,license.status_changed --name="CRM"
wp wplc webhook test 1
```

Every change of an endpoint is written to the audit log (object "Webhook"). The log keeps the host of the target URL, never its path or query (many services put an access token there) and never the secret.

### Which addresses are refused

To protect your network, the server refuses target URLs that point inward: host names without a dot, `localhost` and names ending in `.localhost`, `.local`, `.internal`, `.lan`, `.home.arpa`, `.localdomain`, `.intranet`, `.corp`, `.test`, `.invalid`, `.example`, and any host that resolves to a loopback, private, link-local (including the cloud metadata address `169.254.169.254`), shared, documentation, multicast or reserved address, IPv4 or IPv6. The check runs when you save and again before every request, and the request goes to exactly the address that was checked. Redirects are not followed.

## Events

| Event | When | Data |
| --- | --- | --- |
| `license.issued` | A license was issued for an order or by hand. | `license_id`, `order_id` (0 by hand) |
| `license.status_changed` | The status of a license changed, or it was renewed. | `license_id`, `old_status`, `new_status`, `action` |
| `license.plan_changed` | The plan of a license changed. | `license_id` |
| `license.site_limit_reached` | An activation was refused because a site limit was reached. | `license_id`, `environment`, `limit` |
| `activation.created` | A site was activated. | `license_id`, `activation_id`, `environment` |
| `activation.removed` | A site activation ended. | `license_id`, `activation_id`, `environment`, `initiator` |
| `release.published` | A release was published. | `release_id`, `product_key`, `version`, `channel` |
| `release.withdrawn` | A published release was withdrawn. | as above |

Statuses: `active`, `past_due`, `suspended`, `expired`, `revoked`. `action`: `renewal_succeeded`, `renewal_failed`, `license_expired`, `chargeback`, `order_cancelled`, `order_refund`, `admin_status_change`, `admin_restore`, `admin_extend`. `environment`: `live`, `staging`, `development`. `initiator`: `api` (the site released itself), `customer`, `support`, `system`.

Every event with a `license_id` also carries `license`: product key, plan key, status and end of the paid period, as they were when the event happened.

An event is sent only after the change is stored for good: a change that is rolled back sends nothing. The request that changed the license never waits for your receiver; the webhook is sent a moment later by the background queue (Action Scheduler).

**Never sent:** license keys or any part of them, key hashes, secrets, site addresses, email addresses, names, the customer's user ID and payment data. License and order IDs are references into your shop.

## The request

A `POST` with a JSON body and these headers:

| Header | Value |
| --- | --- |
| `X-WPLC-Event-Id` | Event ID (UUID). The same for every attempt and every endpoint. |
| `X-WPLC-Event` | Event name |
| `X-WPLC-Timestamp` | Unix time of this attempt |
| `X-WPLC-Attempt` | 1 to 5 |
| `X-WPLC-Signature` | `v1=` and the hex HMAC-SHA256 of `<timestamp>.<body>`, keyed with the secret |

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
    "license": { "product_key": "acme-reports-pro", "plan_key": "business", "status": "suspended", "expires_at": "2027-05-01T00:00:00Z" }
  }
}
```

`contract_version` is the version of the license hook contract: a field of `data` is not removed or given another meaning without a new major version. `webhook_version` is the version of the envelope and the signature.

## Checking the signature

Use the raw body as received, compare in constant time and refuse old timestamps:

```php
<?php
$secret    = getenv( 'ACME_WEBHOOK_SECRET' );
$body      = file_get_contents( 'php://input' );
$timestamp = $_SERVER['HTTP_X_WPLC_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WPLC_SIGNATURE'] ?? '';

$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || ! hash_equals( $expected, $signature ) ) {
	http_response_code( 401 );
	exit;
}

$event = json_decode( $body, true );
if ( already_processed( $event['id'] ) ) { // The same event can arrive twice.
	http_response_code( 200 );
	exit;
}
queue_for_processing( $event );
http_response_code( 204 );
```

Answer within 10 seconds with a status from 200 to 299. Anything else (also a redirect) counts as a failure.

## Retries and switching off

- **Five attempts:** at once, then after 1, 5, 30 and 120 minutes. Body and event ID stay the same.
- **At least once:** your receiver can get the same event twice. Deduplicate by `id`. The order of events is not guaranteed; use `created_at`.
- After **five deliveries in a row** that failed after their last attempt, the endpoint is **switched off**. Every administrator sees a notice until you switch it on again; queued deliveries are not sent. A success, or switching the endpoint on, starts the count again.
- The filters `wplc_webhook_retry_delays` and `wplc_webhook_disable_after_failures` change the schedule and the threshold; `wplc_webhook_payload` can add fields to the body (never keys, secrets or more personal data than the receiver needs).

## Test event and delivery log

**Send test event** sends `webhook.test` once, now, and shows the HTTP status. It works on an endpoint that is switched off, is not retried and does not count as a failure.

**Delivery log** shows the newest deliveries of an endpoint: time, event, event ID, status (queued, sending, failed and will retry, delivered, failed, not sent), attempt, HTTP status, duration and a short error. **Send again** queues a finished delivery once more with the same event ID.

The log keeps at most the newest 100 deliveries per endpoint. Older entries are deleted after **30 days** (License settings → Legal and privacy → "Keep the webhook delivery log for", 0 = never by age; chapter 13). Deleting an endpoint deletes its log.

## Privacy

The receiver gets the data listed above. Whether you need a data processing agreement with the operator of the receiving system depends on your setup and your jurisdiction; have it checked. Mention the receiver in your privacy notice if it gets license data of your customers.
