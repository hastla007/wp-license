# Monitoring

What the plugin provides for monitoring. It sends no alerts by itself: `wplc/health_alert` is an extension point without a subscriber. Connecting an alert receiver is open (see [TODO.md](TODO.md), T-91). Implemented, not yet verified in a shop.

## Diagnostics page

WooCommerce → License diagnostics (capability `wplc_view_diagnostics`, by default everyone with `manage_woocommerce`), `src/Admin/Diagnostics.php`. Read only; shows no keys, secrets, tokens, payloads or customer data. Rows with a problem carry a warning sign and one sentence on what to do.

| Row | Shows | Warning when |
| --- | --- | --- |
| Plugin version, PHP, WordPress, WooCommerce, database | Versions | – |
| License schema (installed / expected) | Schema version in the database and the one the code needs | The installed version is lower: a migration did not finish |
| License tables | Missing plugin tables | Any table is missing |
| Server time / database time | UTC and site time, database clock | Web server and database differ by more than two minutes |
| HTTPS, OpenSSL | Availability | Missing |
| Last daily run / Next daily run | Heartbeat of `wplc_daily_lifecycle` (see below) | Not finished for more than 26 hours, broken off, or not scheduled |
| Last run: *job* | One row per job that reports a heartbeat | – |
| Action Scheduler | Availability | Missing (WP-Cron fallback) |
| Failed scheduled actions (group wplc) | Failed Action Scheduler actions of the plugin | One or more |
| Automatic renewal (Stripe), Stripe gateway version | Whether the renewal adapter can be used and why not | Gateway missing, too old, disabled, or saved cards switched off |
| Stripe webhook secret | Whether `WPLC_STRIPE_WEBHOOK_SECRET` is set | Not set |
| Last valid Stripe event | Time of the last event that passed the signature check | – (compare with the Stripe dashboard) |
| Pending Stripe events, events needing review, overdue renewal attempts | Counters, see table below | Above zero |
| Renewals stuck in processing (over 1 hour) | Charge attempts that never reported back | Above zero |
| Licenses with automatic renewal but no open renewal | Licenses that would not be charged at the end of the term | Above zero |
| Paid renewals without extension | Latest renewal is paid, but the license ends before the paid period does | Above zero |
| License products without a plan | Products ticked as license product without product key and plan; they sell without issuing a license | Above zero |
| License keys verifiable with the current secret | "n of the m most recent keys" (up to 50) | Fewer than all: secret or salts were changed |
| Secrets in wp-config.php | Whether the key constants are defined | WordPress salts are used |
| Database locks (GET_LOCK) | Self-test: take and release a named lock on this connection | Fails (database proxy with connection multiplexing, multi-writer cluster) |
| Client address of this request, Proxy constants | Address used for rate limits, direct peer, `WPLC_TRUSTED_PROXY_HEADER` / `WPLC_TRUSTED_PROXIES` | The direct peer is a private address and the proxy constants are not both set: all customers share one rate limit |

Below the table: the latest 50 Stripe events with outcome `manual_review` or `pending_reconciliation` (event ID, type, times; no payload). Look the event up in the Stripe dashboard and compare it with the renewal order. There is no retry button.

Counters (`Diagnostics::metrics()`):

| Metric key | Counts | Default alert threshold |
| --- | --- | --- |
| `pending_webhooks` | Stripe events stored but not reconciled yet | 1 |
| `manual_review_webhooks` | Stripe events that ended as `manual_review` in the last 24 hours | 1 |
| `overdue_renewals` | Renewals in `scheduled` or `failed` whose retry time (or, without one, period start) has passed | 1 |
| `stuck_processing_renewals` | Renewals in `processing` for more than one hour | 1 |
| `renewing_without_renewal` | Licenses with automatic renewal, not cancelled, without a scheduled, processing or failed renewal | none |
| `paid_without_extension` | Licenses whose latest paid renewal ends after the license does | 1 |
| `failed_scheduled_actions` | Failed Action Scheduler actions in group `wplc` (at most 500 counted; `-1` without Action Scheduler) | 1 |
| `unmapped_license_products` | Products ticked as license product without an active plan | 1 |
| `expired_download_grants` | Unused update download grants past their expiry | none |

`paid_without_extension` also counts a license whose term an administrator shortened after a paid renewal; check the audit log of the license before acting.

WooCommerce → License settings additionally shows whether the Stripe webhook secret and the key constants are set, how many recent keys verify, why automatic renewal is unavailable, the schema version, the checkout type and the grace period.

## Heartbeat

Option `wplc_last_run`: an array job name → Unix timestamp (UTC) of the last run. Written with

```php
\WPLC\Admin\Diagnostics::mark_run( 'renewals' ); // lowercase letters, digits, underscores
```

The plugin itself records `daily_lifecycle_started` (first callback of `wplc_daily_lifecycle`) and `daily_lifecycle` (last callback). A start without a matching end means a task in between ended the request (fatal error, timeout).

- **Admin notice:** when the daily run has not finished for more than 26 hours, or started and did not finish, users with `wplc_view_diagnostics` see an error notice on the dashboard, the plugin list, WooCommerce and license screens. On a new installation the notice appears at the earliest 26 hours after such a screen was opened for the first time.
- **External monitoring:** read the option, for example `wp option get wplc_last_run --format=json`, and alert when `daily_lifecycle` is older than 26 hours. This check does not depend on WP-Cron running, which the alert hook below does.

## Key configuration notice

While the key constants are missing, or while recent license keys cannot be reproduced with the configured secret, an error notice is shown on WooCommerce, license, plugin and dashboard screens. Administrators (`manage_options`) get the `wp-config.php` instructions; other users are told to pass the message on. Defining the constants does not hide the notice as long as issued keys do not verify. The same problem is added as a note to the WooCommerce inbox, because the WooCommerce Home and Analytics pages do not display classic admin notices; the note is removed when the problem is gone.

## Alert hook

Once a day (with `wplc_daily_lifecycle`) each metric is compared with its threshold. When the value is at or above it:

```php
do_action( 'wplc/health_alert', string $metric_key, int $value, int $threshold );
```

Thresholds can be changed with the filter `wplc_health_alert_thresholds` (array metric key → threshold; remove a key to silence it). Example subscriber:

```php
add_action( 'wplc/health_alert', function ( $metric, $value, $threshold ) {
	wp_mail( 'ops@example.com', "License server: $metric", "$metric = $value (threshold $threshold)" );
}, 10, 3 );
```

The alerts are emitted as the last task of the daily run, after the work they report on. A receiver that throws an exception is logged (WooCommerce log source `wp-license`) and does not stop the remaining alerts or the heartbeat.

Notes on reading the values:

- `overdue_renewals` also counts a `failed` renewal that has no retry left and is waiting for the end of its grace period, so it can stay above zero for several days per affected license.
- `pending_webhooks` above zero for more than a few minutes means the background queue is not running.
- The check runs once a day. For faster detection, monitor the scheduled actions below directly.

## Scheduled jobs to watch

### WP-Cron (recurring)

| Hook | Schedule | Does |
| --- | --- | --- |
| `wplc_daily_lifecycle` | daily | Expires licenses, picks up stuck renewals, sends reminder emails, purges expired download grants and stream tokens, runs data retention, emits health alerts |
| `wplc_cleanup_security_rows` | hourly | Deletes used request nonces and old rate-limit rows |
| `wplc_send_reminders` | single event, 5 minutes later, only when a reminder run did not finish | Continues sending reminders |

If `wplc_daily_lifecycle` does not run, licenses do not expire, reminders are not sent and nothing is cleaned up. Use a real system cron for `wp-cron.php` on the license server.

### Action Scheduler (group `wplc`)

Single actions, WooCommerce → Status → Scheduled Actions, filter by group `wplc`. Without Action Scheduler the same hooks are scheduled as single WP-Cron events.

| Hook | Argument | Does |
| --- | --- | --- |
| `wplc_process_renewal` | renewal ID | Automatic charge attempt at `paid_until` and on retry days |
| `wplc_reconcile_stuck_renewal` | renewal ID | Checks a renewal left in `processing` 20 minutes after the attempt started |
| `wplc/renewal_grace_ended` | license ID | Expires the license at the end of the grace period |
| `wplc_process_stripe_webhook` | inbox ID | Reconciles a received Stripe event |
| `wplc_reconcile_paid_renewal_order` | order ID | Books a paid renewal order |

Watch for failed actions and for pending actions whose scheduled time is in the past. A failed `wplc_process_renewal` leaves the renewal `processing`; the stuck check and the daily job pick it up.

## Logs

- **WooCommerce log source `wp-license`** (WooCommerce → Status → Logs): email and reminder errors (`src/Notifications/Notifications.php`) and data retention runs (`src/Maintenance/Retention.php`).
- **Audit log** (table `{prefix}wplc_audit_log`): changes to licenses, activations, renewals, releases and settings, and rejected webhook events, with action, object, reason and before/after values. Visible per license on the license detail screen and as a whole, with filters and CSV export, under WooCommerce → License audit log. Actions worth alerting on: `stripe_webhook_mismatch`, `stripe_webhook_manual_review`, `stripe_webhook_amount_mismatch`, `chargeback_received`, `renewal_result_unknown`, `renewal_recovery_mismatch`, `renewal_recovery_amount_mismatch`, `manual_renewal_rejected`.
- **Event inbox** (table `{prefix}wplc_event_inbox`): received Stripe events with their outcome; see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).
- **Orders:** renewal orders with the meta `_wplc_renewal_review = yes` are paid but were not applied to a license.

Renewal charging, webhook reconciliation and the REST API write no log file entries; their trace is the audit log.

## Data retention

The daily job deletes old rows (`wplc_retention_days` filter, defaults: audit log 730 days, event inbox 180 days, deactivated activations 365 days, reminder log 730 days). Monitoring that relies on the audit log or inbox must read them within these periods.

## Outside the plugin

Not covered and to be monitored at infrastructure level: availability of `/wp-json/wplc/v1/`, database, TLS certificate, the package host or private package directory, backups, and that page caches and CDNs do not cache `/wp-json/wplc/` (see [CACHING.md](CACHING.md)).
