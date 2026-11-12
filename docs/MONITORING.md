# Operational metrics and alert integration

The WooCommerce diagnostics page shows aggregate counts for pending Stripe inbox events, events sent to manual review in the last 24 hours, overdue renewal attempts, and unused expired download grants. It does not show customer identifiers, keys, tokens, or payment data.

A daily check emits `wplc/health_alert` with `($metric_key, $value, $threshold)` when a metric meets its threshold. Default alert thresholds are one pending webhook, one recent manual-review event, and one overdue renewal. Sites may tune them with `wplc_health_alert_thresholds` and attach an external alerting integration to `wplc/health_alert`.

This is an extension point, not a configured notification destination. Production operators must install and verify an alert subscriber, ensure WP-Cron/Action Scheduler runs reliably, and monitor database/API availability and package-host failures at infrastructure level.
