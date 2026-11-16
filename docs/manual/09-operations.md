# 9. Operations

## Backups and restore

Back up **three things together**: the WordPress database, the package directory (chapter 5) and the secret constants of `wp-config.php` (chapter 2). A database backup without the matching secrets cannot be used: keys cannot be displayed, installations cannot authenticate and answers lose their signature. Keep encrypted backups with documented retention, and test a restore on an isolated environment before launch and after storage or schema changes. Never repair production rows by direct SQL except under an incident procedure with a before/after backup.

### Restoring a backup into production

An older database does not know what happened after the backup (renewals charged, licenses issued, refunds, cancellations). Restoring it and letting the jobs run would charge paid periods again or expire paid licenses. Work in this order:

1. Put the shop into maintenance mode and add `define( 'WPLC_PAUSE_BILLING', true );` to `wp-config.php` before the restored database goes live.
2. Restore database, package directory and the matching secrets (also retired keys that the backup still uses). Flush the object cache.
3. Check **WooCommerce → License diagnostics** (or `wp wplc schema status`): installed and expected schema version match, no structure problems.
4. Reconcile Stripe payments made after the backup time with the renewal orders. For a payment without a paid renewal order, mark the order paid or extend the license with "Change term", with the payment ID as reason.
5. Reconcile orders and refunds made after the backup; licenses follow the orders.
6. Check cancellations made after the backup and cancel again.
7. Remove `WPLC_PAUSE_BILLING`. The end of the pause is recorded as a processing gap: due renewals are attempted before anything expires. Watch the WooCommerce log (source `wp-license`) during the next daily run.

Activations made after the backup are gone; those sites activate again. A restore also brings back the response signing key of that time (unless it is in `wp-config.php`).

### Staging copies and the billing pause

A copy of the shop database contains the same due renewals, saved cards and customers as the live shop. The plugin stores a fingerprint of the installation (home URL host and path, database name, table prefix) and **pauses billing** when one of these is true:

| Reason | Trigger | How to end it |
| --- | --- | --- |
| Constant | `define( 'WPLC_PAUSE_BILLING', true );` | Remove the constant |
| Schema downgrade | The database was upgraded by a newer plugin version | Install the newer version again |
| Fingerprint | Home URL, database name or table prefix changed (clone, restore elsewhere, domain move) | Answer the admin notice: "This is the live shop – resume billing" or "This is a copy – keep billing paused" |
| Environment | `wp_get_environment_type()` is not `production` | Set it to `production`, or define `WPLC_PAUSE_BILLING` as `false` on a staging site that should bill test cards |

While paused, no renewal is charged, no license expires, no reminder or status email is sent and missed orders are not caught up. The API keeps answering, so add-ons on test sites can activate against a staging server.

**Rules:** set `WP_ENVIRONMENT_TYPE` to `staging` and `WPLC_PAUSE_BILLING` to `true` on every staging site **before** importing a production database; give staging its own secrets and its own Stripe test-mode webhook endpoint (a staging copy with the production encryption keys can decrypt production installation secrets); use a mail trap on staging. After a domain move of the live shop, confirm "This is the live shop" once.

### Processing gaps

When the plugin did not run for a while (deactivated, cron stopped, billing paused), it records a gap and a catch-up job looks at the orders and refunds of the last 30 days (filter `wplc_gap_reconcile_days`): paid orders without licenses get them, paid renewal orders are booked, refunds are applied. Licenses whose renewal was due during the gap are held back from expiry so the renewal can be attempted first.

## Key rotation

The license-key ring and the encryption ring rotate independently.

| Ring | What breaks without the old entry | When the old entry may go |
| --- | --- | --- |
| `WPLC_LICENSE_KEY_SECRETS` | Customers no longer see keys derived with it. Activation with a key the customer has keeps working (keys are looked up by hash). | Never while licenses with that key ID exist. A routine rotation does not move existing licenses to the new ID. |
| `WPLC_INSTALLATION_ENCRYPTION_KEYS` | Installations still encrypted with it fail with `401 installation_secret_invalid`; a signing key encrypted with it cannot be decrypted. | After `wp wplc rotate-installation-secrets` ends with exit code 0, and no backup you might restore still needs it. |

### Routine rotation

1. **Back up** the database and the current constants.
2. **Add** a new entry to both rings and make it current (`WPLC_LICENSE_KEY_ID`, `WPLC_INSTALLATION_ENCRYPTION_KEY_ID`); deploy to every node at once. New licenses get the new key ID immediately.
3. **Check** that an existing key is still shown in the customer account and an installation still passes its check.
4. **Re-encrypt:** `wp wplc rotate-installation-secrets` (dry run), then `--apply` (on large tables with `--max-rows` and `--after-id`), then a dry run again until it ends with **exit code 0**. In scripts: `wp wplc rotate-installation-secrets || echo "old key still in use"`.
5. **Retire** the old encryption entry only then. Keep the old license-key entry.

A typo in the current key ID stops license issuance (the WooCommerce log says `License not issued: the current license key ID ... has no secret`). Fix `wp-config.php`, then issue the missing licenses ("Issue missing licenses for an order" or `wp wplc repair issue-order <id>`).

**Wrong key ID:** if an entry was renamed (same secret, new ID), customers see "Currently unavailable". Configure the secret under the ID you want to keep and run `wp wplc recover-license-key-ids --to=<that-id>` (dry run: `recoverable` must equal the affected rows), then with `--apply`.

**Removed encryption key:** put the entry back (keep the new one current), run `wp wplc rotate-installation-secrets --apply`, check `Response signing key: current`, then retire it again.

### A leaked license-key secret

A rotation alone does not invalidate keys derived from a leaked secret. Re-key the affected licenses:

1. Back up. Add a new entry, make it current, deploy.
2. `wp wplc rekey-licenses --from=<leaked-id>` (dry run, exit code 1 while licenses use that ID).
3. `wp wplc rekey-licenses --from=<leaked-id> --apply`: every license with that key ID gets a new key; the old key stops working at once. Add `--notify` to email each owner the new key, `--release-sites` if foreign sites may have been activated with the leaked keys. Repeat until it reports "No license uses this key ID any more".
4. Retire the leaked entry once no backup you might restore needs it.

### Rotating the response signing key

Your add-ons pin the public key; rotate without breaking them:

1. `wp wplc signing-key --prepare` creates the next key without using it and prints `next_public_key` (repeated calls keep the same prepared key).
2. Release every add-on with both public keys pinned; wait until the installed base has updated.
3. `wp wplc signing-key --rotate` switches to the prepared key (asks for confirmation; `--yes` in scripts). Clients that pin only the old key fail with `wplc_server_signature_invalid` until updated.
4. Remove the old public key from your builds only after no backup with the old key can be restored.

With `WPLC_RESPONSE_SIGNING_KEY` in `wp-config.php`, `--prepare` and `--rotate` are not available: print a new value with `wp wplc signing-key --generate`, ship its public key next to the current one, then replace the constant. Never run `--rotate --force` to "repair" a key that cannot be decrypted; fix the key ring instead (`--force` creates a key that no delivered add-on pins).

## Monitoring

### Diagnostics page

**WooCommerce → License diagnostics** (capability `wplc_view_diagnostics`) is read-only and shows no keys, secrets or customer data. Rows with a problem carry a warning and one sentence on what to do: versions, schema, tables, server and database time, the last and next daily run and every job's heartbeat, Action Scheduler and failed actions, Stripe gateway and webhook state, pending and review Stripe events, stuck and overdue renewals, licenses with automatic renewal but no open renewal, license products without a plan, keys verifiable with the current secret, secrets configured, database lock self-test, database layer (read/write splitting), client address and proxy constants, package storage and the newest plugin release.

### Heartbeat

The option `wplc_last_run` holds the time of every job's last run. External monitoring that does not depend on WP-Cron:

```sh
wp option get wplc_last_run --format=json   # alert when "daily_lifecycle" is older than 26 hours
```

Users with `wplc_view_diagnostics` see an admin notice when the daily run has not finished for more than 26 hours.

### Alert hook

Once a day each metric is compared with its threshold and `wplc/health_alert` fires for every metric at or above it. The plugin sends no alert by itself; connect a receiver:

```php
add_action( 'wplc/health_alert', function ( $metric, $value, $threshold, $url = '' ) {
	wp_mail( 'ops@example.com', "License server: $metric", "$metric = $value (threshold $threshold)\n$url" );
}, 10, 4 );
```

Metrics: `pending_webhooks`, `manual_review_webhooks`, `rejected_webhook_minutes`, `overdue_renewals`, `review_required_renewals`, `stuck_processing_renewals`, `renewing_without_renewal`, `paid_without_extension`, `failed_scheduled_actions`, `unmapped_license_products`, `orders_with_issuance_problem`, `failed_jobs`, `release_problems`, `expired_download_grants`. Thresholds: filter `wplc_health_alert_thresholds`. Events that need a person are passed on at once: `renewal_blocked`, `renewal_needs_review`, `license_needs_review`, `renewal_lock_unavailable`, `response_signing_key_unavailable`, `price_change_not_applied`.

### Scheduled jobs

| Hook | Schedule | Does |
| --- | --- | --- |
| `wplc_daily_lifecycle` | daily (WP-Cron) | Expiry, stuck renewals, reminders, catch-up, retention, cleanup, health alerts. With Action Scheduler each job runs as its own action `wplc_daily_job`. |
| `wplc_cleanup_security_rows` | hourly (WP-Cron) | Deletes used nonces, old rate-limit rows and expired download grants. |
| `wplc_process_renewal`, `wplc_process_stripe_webhook`, … | Action Scheduler group `wplc` | Charges, retries, webhook reconciliation (WooCommerce → Status → Scheduled Actions). |

If WP-Cron does not run, licenses do not expire, reminders are not sent and the security tables grow without bound. Use a real system cron for `wp-cron.php`.

### Logs and API counters

- WooCommerce → Status → Logs, source `wp-license` (jobs, emails, renewals) and `wplc-api` (rejected API requests, sampled). Messages are English; no keys, addresses or installation IDs are logged.
- Rejected API requests are counted per error code. Watch for a rising `installation_secret_invalid` (encryption key missing on a node), `request_expired` (clock drift), `activation_not_found`, `rate_limited` and `retry_later`. Action: `wplc/api_request_rejected`.
- Audit actions worth watching: `renewal_blocked`, `renewal_disabled`, `billing_paused`, `billing_resumed`.

Outside the plugin, monitor the availability of `/wp-json/wplc/v1/`, the database, the TLS certificate, the package storage and the backups.

## Caching, CDN and firewalls

Every API answer is signed for one installation and one request; none may come from a shared cache.

| Path | Exclude from |
| --- | --- |
| `/wp-json/wplc/*` (and `/?rest_route=/wplc/…`) | Page cache, CDN/edge cache, reverse-proxy cache, REST API cache plugins |
| My Account with all endpoints (`/my-account/wplc-licenses/`) | Page cache, CDN cache |
| `/wp-admin/admin-post.php` (customer actions too) | Page cache, CDN cache; do not block it with wp-admin protection |
| Cart, checkout, order-pay | Page cache, CDN cache (standard WooCommerce rule) |

- Exclude by path, not by cookie: API clients send no WordPress cookies.
- Do not cache `POST`/`DELETE` or error answers; do not strip headers or rewrite request bodies (that breaks the request signature).
- Download links are single-use: exclude `/wp-json/wplc/v1/updates/download/` and `/wp-json/wplc/v1/packages/` from link scanners, prefetchers, uptime monitors and security proxies.
- **Bot protection and WAFs:** API calls come from customer web servers (no cookies, no JavaScript, data-centre IPs, `GET`/`POST`/`DELETE`). Let `/wp-json/wplc/v1/*` pass without challenge (Cloudflare: skip Bot Fight Mode, Browser Integrity Check and managed challenges for the path; security plugins: do not restrict the namespace to logged-in users and do not block `DELETE`). Stripe webhook deliveries must reach PHP unchanged.
- **Check after every CDN or WAF change**, from a server outside your network: `curl -i -X POST https://shop.example/wp-json/wplc/v1/activations -H 'Content-Type: application/json' -d '{}'` must return JSON with an error `code`, not HTML, a redirect or a challenge. Repeat it ten times quickly.

### Rate limits behind a proxy

The API limits failed activations per client address (30 per minute, IPv6 per /64) and signed requests per installation (120 per minute). Behind a CDN or reverse proxy every request seems to come from the proxy, so all customers would share one bucket. Configure:

```php
define( 'WPLC_TRUSTED_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP' ); // or HTTP_X_FORWARDED_FOR, …
define( 'WPLC_TRUSTED_PROXIES', array( '173.245.48.0/20', '103.21.244.0/22' ) ); // the proxy's published ranges
```

The header is honored only when the direct peer is in `WPLC_TRUSTED_PROXIES`. Keep the list current with the provider's published ranges. The diagnostics page warns when the direct peer is private and the constants are missing.

## Database and several web servers

- One writable MySQL/MariaDB primary. The named-lock self-test is on the diagnostics page. With read/write splitting (HyperDB, LudicrousDB) the `wplc_*` tables and all `GET_LOCK` calls must go to the primary; the diagnostics row "Database layer" warns about splitting layers. Multi-writer clusters are not supported.
- Every web node gets the same secret constants and the same package directory (shared storage), or use URL storage for packages.
- Each local package download occupies a PHP worker; size the pool for the downloads after a release, or let the web server send the files (chapter 5).

## Multisite

The server is meant to run on **one** site of a network: the site where WooCommerce sells the licenses. Running it on several sites gives several independent servers with separate licenses, releases and API endpoints; a key issued on one site is unknown on every other.

- Tables, settings, cron events, the billing pause and the package directory (`wp-content/uploads/sites/{id}/wplc-packages/`) are per site. A site creates its tables on its first admin, cron or WP-CLI request, and only if WooCommerce is active there.
- The secret constants are shared by all sites; that is harmless because each site has its own tables. **Stripe secrets are per site:**

  ```php
  define( 'WPLC_STRIPE_WEBHOOK_SECRETS', array(
  	1 => array( 'live' => 'whsec_…', 'test' => 'whsec_…' ),
  	4 => 'whsec_…', // live secret of site 4
  ) );
  ```

  Once this constant is defined, a site without an entry accepts no webhook.
- WP-CLI commands work on the site selected with `--url`; repeat them per site, for example `wp site list --field=url | xargs -I % wp --url=% wplc rotate-installation-secrets --apply`.
- Deleting a site drops its `wplc_*` tables when the plugin is loaded in that request. If the plugin is active on a sub-site only, install the must-use helper (`wp wplc schema install-mu-helper`) or network-activate the plugin. Leftovers: `wp wplc schema orphans` lists them, `--drop` removes them after a confirmation.

## Privacy and GDPR

**What the plugin stores:** licenses linked to the customer account (no plaintext keys, only a verifier), activated site addresses with environment and plugin/WordPress/PHP versions, encrypted installation secrets, renewal records, consent records for automatic renewal, references to the payment provider's customer and payment method (never card numbers), download records, a notification log and an audit log. Card data stays with Stripe.

**Export and erasure** run through the WordPress privacy tools (Tools → Export/Erase Personal Data), both named `wp-license-captain`. The email address resolves to the account and to owners of licenses bought with that billing email.

- The export contains licenses, installations, renewals, consents, license history (redacted), the email log and downloads; never secrets, hashes, keys, tokens or package URLs.
- The erasure detaches ended licenses from the account and deletes their installations; licenses still in force are kept (erasing them would stop a paid service), but their automatic renewal is switched off and the payment references are removed. WooCommerce orders are handled by WooCommerce's own eraser.
- A deleted customer account detaches its licenses and switches automatic renewal off; the licenses run until the end of the paid period.

**Automatic retention** (daily, filter `wplc_retention_days`; `0` disables a rule):

| Rule | Default |
| --- | --- |
| `audit_log` | 730 days |
| `event_inbox` (processed Stripe events) / `event_inbox_open` | 180 / 730 days |
| `activations` (deactivated installations of ended licenses) / `inactive_activations` | 365 / 365 days |
| `ended_license_activations` (deactivate sites of ended licenses) | 30 days |
| `reminder_log` | 730 days |
| `consents` (of ended licenses) | 3650 days, counted from the end of the year |
| `anonymize_licenses` (detach owner of licenses ended longer ago) | 3650 days, counted from the end of the year in which the license ended |

```php
add_filter( 'wplc_retention_days', static function ( array $days ): array {
	$days['anonymize_licenses'] = 2557; // Seven years, e.g. where that is the statutory period.
	return $days;
} );
```

`anonymize_licenses` and `consents` run from the end of the calendar year, as statutory retention periods for business records do: with the default of ten years, a license that ended in 2026 is detached from its customer in 2037. Ten years covers the longest common period (Germany, Switzerland); shorten it where your duties allow, or set 0 to switch the rule off. The settings page shows the period in force. Keep the retention of deactivated activations at least as long as the offline window of the clients (14 days). Deletion is permanent: take a backup before the first run on an existing database and align the periods with your privacy notice. The plugin's hooks are tools, not compliance: your privacy notice, data processing agreements (mail provider, payment provider, package host) and retention schedule remain your responsibility.
