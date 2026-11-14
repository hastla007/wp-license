# Performance and load test (T-89)

This page records a load test of the plugin with realistic data volumes, the
query plans of the hot paths, the changes made because of it (schema 15) and
the limits that remain. The tools are in `tests/load/` and can be rerun on any
test site.

## Test setup

| | |
|---|---|
| Database | MariaDB 10.11 (InnoDB, default settings, no query cache) |
| PHP | 8.3 CLI; HTTP through `php -S` with `tests/e2e/router.php`, 4 workers, no opcache |
| WordPress / WooCommerce | 7.1 / 11.2 (HPOS), the e2e site of `tests/e2e/` |
| Object cache | none (non-persistent default) |
| Machine | 4 vCPU cloud container, local database over TCP |

Seeded volumes (`tests/load/seed.php`, scale 1, seed 89):

| Table | Rows |
|---|---:|
| WordPress users (customers) | 20 000 (1% "agencies" own ~30% of the licenses, one reseller owns 1 500) |
| licenses | 50 000 (73% active, 20% expired, 2% past due, rest suspended/revoked/pending; created over 4 years) |
| entitlements | 131 000 (the plan snapshot of every license) |
| activations | 119 000 (production/staging/development, 52% active) |
| renewals | 30 000 (mostly paid; scheduled, failed, review, processing rows included) |
| audit_log | 150 000 |
| event_inbox | 50 000 (Stripe events with ~1 KB payload) |
| request_nonces | 20 000 |
| reminder_log | 30 000 |
| download_tokens, package_streams | 10 000 each |
| request_limits | 3 000 |

The database of the seeded site is about 270 MB. Seeding takes about 22 s.

## How to run it

On a test site (never on a shop with real data; the seed creates users):

```sh
wp eval-file tests/load/seed.php            # scale=0.1 for a quick run, reset to remove the rows
wp eval-file tests/load/bench.php runs=5 json=/tmp/after.json
```

`bench.php` calls the plugin code in-process with `SAVEQUERIES`. Jobs that write
(expiry, reminders, retention, ...) run inside a transaction that is rolled
back, so the data stays the same between runs. For every plugin query it
prints the time of an isolated re-run, the rows the server really read
(`Handler_read_*` counters) and the `EXPLAIN` plan. Options: `only=<text>`
filters scenarios, `explain=0` skips the plans.

Real HTTP (admin pages, account page, REST through the bundled
`LicenseClient`): copy `tests/load/query-log.php` into `wp-content/mu-plugins/`,
start `WPLC_LOAD_QUERY_LOG=/tmp/q.jsonl php -S 127.0.0.1:8831 tests/e2e/router.php`
in the site root (with `PHP_CLI_SERVER_WORKERS=4`) and run the bench with the
same `WPLC_LOAD_QUERY_LOG` and `http=8831`. The e2e mu-plugin routes
`https://shop.test` to that port. The REST scenarios deactivate the live sites
of one agency license and activate new test installations on it.

For a single query, `ANALYZE FORMAT=JSON <query>` in the MariaDB client shows
the real row counts per table (`r_rows`) and the time per step.

## Results

Median of 5 runs. "Rows read" is the largest number of rows a single query of
the scenario read. Wall time includes PHP rendering; HTTP times include the
WordPress and WooCommerce bootstrap (about 75 ms on this machine without
opcache), which dominates every request.

| Scenario | Before: wall ms | DB ms | rows read | After: wall ms | DB ms | rows read |
|---|---:|---:|---:|---:|---:|---:|
| list: default (created desc) | 31.6 | 22.8 | 50 147 | 15.0 | 7.1 | 50 097 ¹ |
| list: created asc | 45.6 | 19.5 | 50 147 | 16.3 | 7.8 | 50 097 ¹ |
| list: status asc | 30.7 | 21.4 | 50 147 | 29.0 | 19.8 | 50 097 |
| list: status desc | 40.0 | 32.9 | 50 147 | 31.3 | 20.8 | 50 097 |
| list: paid_until asc | 34.1 | 26.2 | 50 147 | 16.1 | 8.2 | 50 097 ¹ |
| list: paid_until desc | 28.5 | 17.5 | 50 147 | 17.1 | 9.5 | 50 097 ¹ |
| list: last page (OFFSET 50 050) | 89.3 | 77.5 | 100 193 | 18.7 | 10.0 | 50 097 |
| list: status=active | 79.2 | 71.3 | 36 644 | 21.4 | 9.4 | 36 594 ¹ |
| list: status=past_due | 18.6 | 5.4 | 1 023 | 12.8 | 4.2 | 973 |
| list: status=past_due, paid_until | 11.4 | 4.2 | 973 | 12.1 | 4.1 | 973 |
| list: product=top (28 000 licenses) | 34.8 | 20.3 | 50 147 | 24.2 | 11.5 | 28 026 ¹ |
| list: product=rare (7 000 licenses) | 37.9 | 27.2 | 50 147 | 14.3 | 5.6 | 6 981 ¹ |
| list: product + status | 42.6 | 29.1 | 50 097 | 25.6 | 13.3 | 50 097 ¹ |
| list: search email (full address) | 13.5 | 9.9 | 20 014 ² | 11.7 | 9.7 | 20 014 ² |
| list: search email fragment | 27.0 | 16.6 | 3 339 | 19.0 | 9.6 | 3 289 |
| list: search key prefix `WPLC-1733932E` | 70.6 | 67.8 | 50 098 | 13.2 | 9.2 | 20 014 ² |
| list: search key fragment `WPLC-1733` | 80.7 | 77.9 | 50 098 | 10.9 | 9.0 | 20 014 ² |
| list: search full key (hash) | 4.5 | 2.5 | 9 | 4.1 | 2.5 | 9 |
| list: search `#ID` | 4.4 | 2.9 | 9 | 4.4 | 2.4 | 9 |
| list: search number `25051` | 38.1 | 36.1 | 50 098 | 15.5 | 13.2 | 50 097 ³ |
| list: search text `captain` (matches all) | 93.5 | 84.0 | 50 147 | 41.1 | 29.2 | 50 097 ¹ |
| detail: busiest license | 5.7 | 6.6 | 25 | 6.8 | 3.8 | 25 |
| detail: typical license | 6.9 | 3.7 | 12 | 6.9 | 2.5 | 12 |
| account: reseller (1 500 licenses), page 1 | 29.8 | 17.0 | 1 516 | 16.1 | 7.8 | 1 506 |
| account: reseller, last page | 27.2 | 17.7 | 3 011 | 22.0 | 14.2 | 1 506 |
| account: customer with 2 licenses | 7.2 | 4.2 | 12 | 5.6 | 3.5 | 12 |
| job: expire_due_licenses | 0.5 | 0.5 | 2 | 0.4 | 0.3 | 2 |
| job: requeue_overdue_renewals (100 rows) | 108.1 | 95.9 | 101 | 78.8 | 66.5 | 101 |
| job: reconcile_stuck_renewals | 42.3 | 36.4 | 101 | 30.3 | 23.7 | 101 |
| job: send_due_reminders (≈500 mails) | 8 516 | 834 | 347 | 8 656 | 934 | 347 |
| job: retention run | 115.1 | 89.8 | 198 064 | 163.4 ⁴ | 77.9 | 80 385 |
| job: cleanup_security_rows | 6.0 | 5.8 | 1 000 | 5.3 | 5.0 | 1 000 |
| job: purge_expired_grants | 13.5 | 13.4 | 10 009 | 3.7 | 3.6 | 10 009 |
| diagnostics: metrics | 22.1 | 21.8 | 50 001 | 5.3 | 4.5 | 9 803 |
| review: open count + open cases | 1.5 | 1.2 | 301 | 1.9 | 1.3 | 301 |
| HTTP admin list page (default) | 145.5 | 48.6 | | 136.0 | 34.1 | |
| HTTP admin list page (active, paid_until) | 142.1 | 32.5 | | 140.7 | 47.8 ⁴ | |
| HTTP admin detail page | 124.3 | 25.2 | | 101.5 | 19.7 | |
| HTTP account licenses page (reseller) | 153.0 | 38.7 | | 137.5 | 25.3 | |
| HTTP REST activation | 94.3 | 19.9 | | 84.5 | 20.7 | |
| HTTP REST entitlements check | 108.1 | 35.3 | | 79.5 | 20.3 | |
| HTTP REST update check | 95.5 | 22.6 | | 84.6 | 17.9 | |

¹ The remaining big read is the `COUNT(*)` for the pagination, now from a
narrow index (see limits). The page itself reads 50 index entries and 50 rows.
² WordPress core user search (`user_email LIKE '%…%'` over all users).
³ Collecting the key-prefix matches reads the whole `key_prefix` index (index only, no rows).
⁴ Within noise of the shared test machine; the DB time went down.

The REST hot paths were already fine and did not change: every plugin query is
a unique-key or `(license_id, status)` lookup that reads 1 to 10 rows
(license by key hash, activation by installation UUID, site-limit
`COUNT(*) … WHERE license_id AND status AND environment` through
`license_status`, nonce insert/delete through `activation_nonce`, rate-limit
rows through `limiter_bucket`, releases through `product_status`). Their
20 ms of database time are mostly WordPress and WooCommerce bootstrap queries
(options, user, session), not plugin queries.

## Plans: what was wrong and what changed

### Admin license list (`LicenseListTable`)

Before, every page ran `SELECT * … ORDER BY <column>, id LIMIT 50 OFFSET n`
plus `COUNT(*)`. The licenses table had no index on `created_at`, `paid_until`
or `product_key` (as first column), so every sort and the product filter was a
full table scan with a filesort of all rows (`type=ALL, Using filesort`, 50 000
rows read). Deep pages read the table twice (100 000 rows). With the status
filter the `status_paid_until` index found the rows, but sorting by creation
date still sorted 36 000 full rows (71 ms).

After:

- New indexes `created_at`, `paid_until`, `status_created (status, created_at)`
  and `product_created (product_key, created_at)`. InnoDB appends the primary
  key to every secondary index, so they also deliver the `, id` tie-breaker:
  `type=index key=created_at rows=50`, no filesort.
- Deferred join: the page first selects only the IDs
  (`SELECT id … ORDER BY … LIMIT 50 OFFSET n`, covered by the index, `Using index`),
  then the full rows for those 50 IDs by primary key. The last page went from
  89 ms to 19 ms.
- `COUNT(*)` is skipped when the page is not full (the total is then
  `offset + rows`), which covers nearly every search and small filter.
- Sorting by status without a filter still sorts, but only the narrow
  `(status, created_at, id)` index entries, not full rows. A dedicated index
  was not worth it for this rarely used sort.

### Admin search

Before, a text search ran
`key_prefix LIKE '%t%' OR product_key LIKE '%t%' OR plan_key LIKE '%t%' OR customer_id IN (…)`
and a number search `id = n OR customer_id = n OR key_prefix LIKE '%n%'`. A
leading wildcard can never use an index and the `OR` across columns forced a
full table scan, twice per request (list and count): 70 to 90 ms.

After, each part is resolved to an indexable condition with the same result:

- Key prefix: every prefix is `WPLC-` plus 8 hex digits. A plain ASCII term
  with any other character cannot match and the part is dropped. A term that
  starts with `WPLC-` can only match at the start, so it becomes
  `key_prefix LIKE 'WPLC-…%'` (range on the new `key_prefix` index). Any other
  fragment (e.g. `1733`) is first resolved to the matching IDs from the
  `key_prefix` index alone (index-only scan); more than 1 000 matches fall
  back to the plain `LIKE`.
- Product and plan key: the distinct product/plan pairs of all licenses are
  read from the new `product_plan` index (`Using index for group-by`, 57
  entries instead of 50 000 rows) and matched with `LIKE`; licenses are then
  selected with `(product_key, plan_key) IN (…)`.
- The remaining `OR` of indexable parts runs as `index_merge`
  (`sort_union(key_prefix, customer_created, PRIMARY)`).

The email part still uses WordPress' `get_users()` search, a leading-wildcard
`LIKE` over `wp_users.user_email` (6 to 10 ms at 20 000 users).

### Customer account page

`WHERE customer_id = ? ORDER BY created_at DESC, id DESC` used
`customer_product` and sorted all licenses of the customer. The new index
`customer_created (customer_id, created_at)` returns the page in order. The
page also runs one releases lookup per license with a download button (tiny
table, `product_status` index, 0.3 ms each); not worth batching.

### License detail page

All queries are lookups by license ID (`license_status`, `license_period`,
`license_entitlement`, `object_history`); 2.5 to 4 ms of database time even for
the license with the most activations. Unchanged.

### Daily jobs

- Retention, activations: `NOT EXISTS (SELECT 1 FROM activations c WHERE c.staging_parent_id = a.id AND c.status = 'active')`
  was materialized by MariaDB with a full scan of all activations
  (`c: ALL, 116 000 rows`). Rewritten as an anti-join
  (`LEFT JOIN … c ON c.staging_parent_id = a.id AND c.status = 'active' … WHERE c.id IS NULL`),
  which looks children up in `staging_parent` (`Not exists`): 198 000 → 80 000
  rows read. The rest is the join of all terminal licenses with their
  activations; it shrinks as old activations are deleted.
- Retention, audit log / event inbox / reminder log: ordered deletes through
  `PRIMARY`, `inbox_pending` and `sent_at`, 1 000 rows per batch. Fine.
- Expiry, grace end, renewal requeue, stuck renewals: range scans on
  `status_paid_until` and `due_renewals`, reading only the due rows. The
  requeue job's time is Action Scheduler (about 4 queries per queued action).
- Reminders: the license scan walks the primary key from a cursor and reads
  only a few hundred rows per batch. The job's cost is mail rendering (about
  17 ms per mail, two single-row lookups per mail); it is limited by its
  batch and time budget.
- `purge_expired_grants` (`COALESCE(consumed_at, expires_at) < ?`) scans the
  download tokens table. The table only holds the last week of tokens, so it
  stays small; left as it is.

### Diagnostics

`COUNT(*) … WHERE source = 'stripe' AND outcome = 'pending_reconciliation'`
scanned the whole event inbox. New index `outcome_processed (outcome, processed_at)`
serves both inbox metrics: 50 000 → 100 rows. The renewal metrics already
used `due_renewals`.

## Schema 15

| Table | New index | Serves |
|---|---|---|
| licenses | `customer_created (customer_id, created_at)` | account page, email search |
| licenses | `status_created (status, created_at)` | status filter, default sort |
| licenses | `product_created (product_key, created_at)` | product filter, default sort |
| licenses | `product_plan (product_key, plan_key)` | text search on product/plan keys |
| licenses | `created_at (created_at)` | default sort, deep pages |
| licenses | `paid_until (paid_until)` | sort by paid-until |
| licenses | `key_prefix (key_prefix)` | key-prefix search |
| event_inbox | `outcome_processed (outcome, processed_at)` | diagnostics metrics |

dbDelta adds them; step 15 only checks that they exist. Adding them to 50 000
licenses took about 0.5 s (online `ALTER TABLE`). The license table is written
rarely (orders, renewals, status changes), so the extra index maintenance is
negligible; the seven license indexes take about 13 MB at 50 000 licenses.

## Remaining limits and recommendations

- `COUNT(*)` for the pagination of a full page still reads a whole index:
  7 ms for all 50 000 licenses, 6 ms for 36 000 active ones, and a full table
  scan (15 ms) for product + status combined, because no index has both
  columns. It grows linearly (about 70 ms at 500 000 licenses). If that ever
  matters, cache the total per filter for a minute in a transient, or show
  "more than N" instead of the exact count.
- Searches with very common terms (a product name that matches every
  license) still count every match. The list itself stops after 50 rows.
- The email search is WordPress core: `LIKE '%…%'` over all users. Shops with
  hundreds of thousands of customers should search by full key, `#ID` or key
  prefix, which are index lookups.
- Each HTTP request spends most of its time in the WordPress and WooCommerce
  bootstrap, not in plugin queries: use opcache in production, and a
  persistent object cache (Redis, Memcached) to save the option, user and
  session queries; see [CACHING.md](CACHING.md). The plugin's own state is
  read from its tables on purpose and is not affected.
- The REST endpoints do 10 to 15 small plugin queries per request. At the
  measured 80 to 100 ms per request on one PHP worker, a server handles roughly
  10 requests per second and worker; daily entitlement checks of 100 000
  installations average about 1.2 per second.
- Unbounded growth: `audit_log`, `event_inbox`, `reminder_log` and ended
  activations are bounded by the retention rules (see
  [PRIVACY-DATA-FLOWS.md](PRIVACY-DATA-FLOWS.md)); `renewals` and
  `entitlements` grow with the business and are only read by license ID.
