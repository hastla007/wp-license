# Monitoring

What the plugin provides for monitoring. It sends no alerts by itself: `wplc/health_alert` is an extension point without a subscriber. Connecting an alert receiver is open (see [TODO.md](TODO.md), T-91). Implemented, not yet verified in a shop.

## Diagnostics page

WooCommerce → License diagnostics (`manage_woocommerce`), `src/Admin/Diagnostics.php`. Read only; shows no keys, secrets, tokens or customer data.

- Versions: PHP, WordPress, WooCommerce, license schema, database.
- HTTPS, OpenSSL, Action Scheduler, Stripe gateway class loaded, update package provider registered.
- Five counters (`Diagnostics::metrics()`):

| Metric key | Counts | Default alert threshold |
| --- | --- | --- |
| `pending_webhooks` | Stripe events stored but not reconciled yet | 1 |
| `manual_review_webhooks` | Stripe events that ended as `manual_review` in the last 24 hours | 1 |
| `overdue_renewals` | Renewals in `scheduled`, `failed` or `reconciling` whose retry time (or, without one, period start) has passed | 1 |
| `review_required_renewals` | Renewals in `review_required`: the payment result stayed unknown and an administrator must check the payment (see [RENEWALS.md](RENEWALS.md)) | 1 |
| `expired_download_grants` | Unused update download grants past their expiry | none |

WooCommerce → License settings additionally shows whether the Stripe webhook secret and the key constants are set, the schema version, the checkout type and the grace period.

## Alert hook

Once a day (with `wplc_daily_lifecycle`) each metric is compared with its threshold. `review_required_renewals` is also checked right away when a renewal goes to review. When the value is at or above it:

```php
do_action( 'wplc/health_alert', string $metric_key, int $value, int $threshold );
```

Thresholds can be changed with the filter `wplc_health_alert_thresholds` (array metric key → threshold; remove a key to silence it). Example subscriber:

```php
add_action( 'wplc/health_alert', function ( $metric, $value, $threshold ) {
	wp_mail( 'ops@example.com', "License server: $metric", "$metric = $value (threshold $threshold)" );
}, 10, 3 );
```

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
| `wplc_process_renewal` | renewal ID | Automatic charge attempt at `paid_until` and on retry days; hourly check (same idempotency key) of a `reconciling` renewal |
| `wplc_resolve_failed_renewal_order` | order ID | Records a renewal order an administrator set to Failed during reconciliation or review as a definite decline |
| `wplc_reconcile_stuck_renewal` | renewal ID | Checks a renewal left in `processing` 20 minutes after the attempt started |
| `wplc/renewal_grace_ended` | license ID | Expires the license at the end of the grace period |
| `wplc_process_stripe_webhook` | inbox ID | Reconciles a received Stripe event |
| `wplc_reconcile_paid_renewal_order` | order ID | Books a paid renewal order |

Watch for failed actions and for pending actions whose scheduled time is in the past. A failed `wplc_process_renewal` leaves the renewal `processing`; the stuck check and the daily job pick it up.

## Logs

- **WooCommerce log source `wp-license`** (WooCommerce → Status → Logs): email and reminder errors (`src/Notifications/Notifications.php`) and data retention runs (`src/Maintenance/Retention.php`).
- **Audit log** (table `{prefix}wplc_audit_log`): changes to licenses, activations, renewals and releases, and rejected webhook events, with action, object, reason and before/after values. Visible per license on the license detail screen. Actions worth alerting on: `stripe_webhook_mismatch`, `stripe_webhook_manual_review`, `stripe_webhook_amount_mismatch`, `chargeback_received`, `renewal_result_unknown`, `renewal_review_required`, `renewal_recovery_mismatch`, `renewal_recovery_amount_mismatch`, `manual_renewal_rejected`.
- **Event inbox** (table `{prefix}wplc_event_inbox`): received Stripe events with their outcome; see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).
- **Orders:** renewal orders with the meta `_wplc_renewal_review = yes` are paid but were not applied to a license.

Renewal charging, webhook reconciliation and the REST API write no log file entries; their trace is the audit log.

## Data retention

The daily job deletes old rows (`wplc_retention_days` filter, defaults: audit log 730 days, event inbox 180 days, deactivated activations 365 days, reminder log 730 days). Monitoring that relies on the audit log or inbox must read them within these periods.

## Outside the plugin

Not covered and to be monitored at infrastructure level: availability of `/wp-json/wplc/v1/`, database, TLS certificate, the package host or private package directory, backups, and that page caches and CDNs do not cache `/wp-json/wplc/` (see [CACHING.md](CACHING.md)).
