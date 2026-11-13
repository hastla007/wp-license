# Launch evidence register

Complete this register before enabling paid production checkout. Every evidence row needs a named owner, date, environment/version, result, and link to the run or approval record. Do not store credentials or personal customer data in this file.

| Gate | Required evidence | Owner | Date | Result / evidence link |
|---|---|---|---|---|
| PR review and merge order | All required PRs reviewed, merge conflicts resolved, deployment tag recorded |  |  |  |
| WooCommerce checkout | Order → license, monthly/yearly, duplicate order event, tax/invoice behavior |  |  |  |
| Stripe test mode | Success, decline, timeout, duplicate event, SCA recovery, refund |  |  |  |
| PayPal scope | Auto-renew remains disabled unless a supported adapter is approved and sandboxed |  |  |  |
| Product clients | Activation, feature gates, site/staging limit, offline expiry, update install per product |  |  |  |
| Package security | Immutable ZIP, manifest SHA-256, one-use grant, host allow-list, rollback |  |  |  |
| Database / recovery | Upgrade from previous schema, backup restore, renewal and event reconciliation |  |  |  |
| Security / privacy | Secret storage/rotation, access review, retention, incident contact |  |  |  |
| Customer terms | Pricing, recurring consent, cancellation, refund, privacy and support copy approved |  |  |  |
| Operations | Alert delivery, on-call owner, restore runbook, rollback owner |  |  |  |

**Decision:** No-go until every mandatory row has a passing result or a documented product decision. A documentation template is not evidence of a completed test or legal approval.
