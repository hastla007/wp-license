# Release readiness checklist

This checklist is a go/no-go gate for a public or commercial release. The repository's stacked PRs are an implementation preview, not a claim that each item below has passed.

## Build and compatibility

- [ ] Merge the PR stack in order and review the final diff against the target branch.
- [ ] Run the project's supported PHP syntax, coding-standard, static-analysis, and integration checks on the minimum and current supported PHP/WordPress/WooCommerce versions.
- [ ] Exercise schema installation and upgrades against representative existing WordPress databases; confirm the database-delta results and rollback/recovery guidance.
- [ ] Verify plugin activation/deactivation, Action Scheduler/WP-Cron behavior, HPOS, multisite policy, and WooCommerce Checkout Blocks support.
- [ ] Build and install the distributable ZIP on a clean WordPress site; check plugin headers, dependencies, autoloading, translations, and upgrade behavior.

## License and payment behavior

- [ ] Implement and review a concrete gateway adapter for each advertised renewal payment method.
- [ ] Verify consent disclosure, token ownership, off-session authorization, idempotency, ambiguous timeouts, retries, refunds, cancellation, and grace-period expiry with gateway sandbox accounts.
- [ ] Confirm manual renewal works if automatic renewals are unavailable.
- [ ] Confirm site-limit concurrency, staging pairing, refunds by quantity, admin suspension/revocation, and customer deactivation against realistic orders.

## Premium updates

- [ ] Configure the package registry and HTTPS host allow-list.
- [ ] Confirm release metadata matches the exact ZIP version and SHA-256 checksum.
- [ ] Implement client-side signature verification and ZIP checksum verification before WordPress installs the package.
- [ ] Verify expired/replayed download grants, invalid hosts, inactive/expired licenses, and provider outages.
- [ ] Publish rollback steps and keep previous release artifacts available.

## Security and privacy

- [ ] Complete an independent review of REST authentication, authorization, nonce replay, activation abuse/rate limiting, secrets, redirects, and audit logging.
- [ ] Decide and document a secure key-rotation and license recovery approach. Current identifiers/installation secrets depend on WordPress salts.
- [ ] Define retention and deletion rules for licenses, activations, renewal orders, and audit records; publish the applicable privacy notice and data-processing terms.
- [ ] Confirm no payment credentials or unnecessary customer/product-site data are stored or transmitted.
- [ ] Review checkout consent and recurring billing disclosures for every sales jurisdiction.

## Operations

- [ ] Configure backups, database monitoring, Action Scheduler monitoring, error alerts, and incident response ownership.
- [ ] Set up a production license server with HTTPS, durable backups, stable salts, and a tested restore process.
- [ ] Document support procedures for lost keys, domain changes, site-limit requests, refunds, payment failures, and salt rotation.
- [ ] Assign a release owner, version, changelog, support window, and rollback decision maker.

Do not advertise a capability until its corresponding product client, payment adapter, package host, and operational runbook are deployed and verified.
