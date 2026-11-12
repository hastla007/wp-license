# Production operations and launch gate

This runbook defines the operator work required before selling WP License Captain subscriptions. It does not claim that infrastructure, secrets, legal text, or payment accounts have been configured.

## Environments and secrets

- Maintain separate staging and production WordPress/WooCommerce installations and gateway credentials.
- Serve every license API and package redirect over HTTPS. Confirm certificates and renewal monitoring.
- Set stable `WPLC_INSTALLATION_ENCRYPTION_KEY` and `WPLC_LICENSE_KEY_SECRET` before issuing the first production license. Store them in the hosting secret manager, restrict read access, and record the restore procedure offline. Do not rotate either value without the documented migration and recovery plan.
- Never put gateway secrets, license keys, installation secrets, or package storage credentials in Git, diagnostics, support tickets, or CI logs.
- Restrict CI secrets to protected environments; require review for production deployment.

## Database backup and recovery

- Back up the WordPress database and package storage together before plugin/schema releases.
- Keep encrypted, access-controlled backups with documented retention and restore ownership.
- Test restoration to an isolated environment before launch and after storage/schema changes.
- After restore, verify schema version, license counts, order links, activation counts, renewal queue state, audit events, and package metadata.
- Never repair production state by directly editing tables except under an approved incident procedure with a before/after backup and audit record.

## Monitoring and incident response

Monitor and alert on:
- scheduled renewal backlog and repeated provider failures;
- webhook signature failures, duplicate processing and queue age;
- activation/entitlement API error rates, latency, replay/rate-limit events;
- package redirect, storage, checksum, and updater failures;
- database capacity, failed migrations, backup age, and restore-test age.

For incidents: assign an incident owner, record start/end and affected versions, preserve sanitized logs, pause risky releases, restore service or roll back, then reconcile affected licenses/orders through supported WooCommerce flows and record the correction in audit history.

## Release and rollback checklist

1. Release to staging with a reversible database migration and a known-good previous plugin package.
2. Confirm CI passes on the supported PHP matrix; run WordPress/WooCommerce integration and gateway sandbox scenarios.
3. Publish immutable versioned ZIPs and manifest metadata (version, SHA-256, required PHP/WP, tested WP, changelog). Verify package host allow-lists.
4. Test activation, renewal success/failure, duplicate webhook, refund, cancellation-at-period-end, expired license, update authorization, expired grant refresh, invalid host and checksum mismatch.
5. Take and verify a pre-release backup. Deploy to a small pilot cohort; inspect diagnostics and monitoring.
6. Promote only after an owner signs off. For rollback, restore the previous plugin package and compatible database backup/migration path; do not downgrade schema blindly.
7. Communicate customer impact and open an incident record for any failed billing or update event.

## Customer, privacy and legal readiness

Before sales, publish accurate plan limits, renewal amount/interval, cancellation behavior, grace period, update/support entitlement, data retention, privacy notice and refund terms. Provide a customer route to cancel before renewal and a support process for lost license keys/domain changes. Confirm these texts and processes with qualified legal/accounting advice for the selling jurisdiction.

## Go / no-go

**No-go** while any of these are true: required PR stack is unreviewed/unmerged; production keys are not stable and backed up; PayPal renewals are advertised without a supported adapter; Stripe webhooks or manual-authentication recovery are unvalidated; packages are not immutable and checksum verified; restore and gateway sandbox runs have not passed; privacy, cancellation, tax/invoice and support ownership are unresolved.

Record named owners and dated evidence for every go/no-go item before launch.
