# Quality gates and compatibility

The initial CI gate runs PHP syntax checks on PHP 7.4, 8.1, 8.2, and 8.3 for every branch push and pull request. This is intentionally a baseline: syntax success does not prove WordPress, WooCommerce, database, or gateway behavior.

Before commercial release, extend the matrix with the supported WordPress/WooCommerce versions and add automated coverage for:

- license issuance idempotency, refund transitions, renewals, grace periods, activation limits, staging links, and schema upgrades;
- REST HMAC, timestamp/replay rejection, rate limiting, and update-download single use;
- Stripe webhook signature verification, duplicate delivery and manual-action recovery;
- all three client plugin minimum PHP versions (Affiliate Captain 7.4, Voucher Captain 8.1, Pass Captain 8.2).

Gateway sandbox and full WordPress integration tests require configured test environments and credentials. They are not run by the syntax workflow. Add no secrets to repository variables unless they are restricted to the required environments.
