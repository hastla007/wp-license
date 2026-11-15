# Stress checks (not part of CI)

Reusable tools from the review of the plugin (task B-181). They look for defects the unit
and integration suites cannot see: inputs nobody thought of, and several PHP processes
working on the same license at the same time. They print what happened; a person reads the
output. Nothing here runs in CI and nothing here is loaded by the plugin.

```bash
tests/stress/run.sh fuzz                 # pure classes, no WordPress, about 20 seconds
WP_TESTS_DIR=… WP_CORE_DIR=… WPLC_WC_DIR=… WPLC_STRESS_ALLOW_TRUNCATE=1 tests/stress/run.sh race 8
tests/stress/race/run.sh process_parallel 8   # one scenario
```

## fuzz/

- `fuzz1.php` runs with the unit bootstrap (`tests/bootstrap.php`). It feeds edge values and
  100,000 random values each to `Money`, `SiteUrl`, `ClientIp`, `LicenseKey`, the state
  machine, `RenewalScheduler::period_end()`, `ReminderSchedule`, `SecretCrypto` and
  `ResponseSigner`, and compares against a reference calculation where one exists. The
  section `==== FAILURES` must be read: every line is a property that did not hold.
  Known and still open: two lines for `RenewalScheduler` (a later purchase at a month end can
  end earlier, task B-178).
- `fuzz2.php` needs the test site (it loads `race/boot.php`): REST envelope with odd data,
  release validation, package storage names, admin search classification, Stripe version
  checks. It only prints results; exceptions from `src/` abort it.

## race/

`run.sh <scenario> [N]` builds a fixture (`setup.php`), starts N processes (`worker.php`)
that wait for the same instant and then all do the same thing (or two opposing things), and
prints one JSON line per process plus the charge requests the fake gateway
(`qa-adapter.php`, slow on purpose) received. Unlike PHPUnit there is no transaction around
the processes, so locks and compare-and-set updates are really exercised.

| Scenario | What must hold |
| --- | --- |
| `activate_same` | one activation row, every process gets the same activation ID |
| `activate_diff`, `activate_diff3` | exactly `site limit` activations succeed, the rest answer `site_limit` |
| `activate_samesite` | the same site with new installation IDs replaces, never adds |
| `nonce_replay` | one request passes, all others answer `request_replayed` |
| `deact_vs_check`, `deact_parallel` | no error from `src/`; one deactivation audit entry |
| `staging_vs_deact`, `staging_parallel` | no staging site survives without its live parent; one staging site per parent |
| `dl_token` | a download grant is redeemed inside its reuse window only |
| `issue_order` | each unit of the order gets exactly one license |
| `process_parallel` | exactly one charge request, renewal `paid`, next renewal scheduled once |
| `process_vs_manualpay`, `process_vs_extend` | the charge and the customer payment or the term change never both apply |
| `manual_start`, `manual_paid` | one renewal order; the paid period is added once |
| `refund_vs_pay`, `status_vs_cancel`, `extend_vs_renew` | the final state is one of the two serial outcomes |

Check the database after a run (tables with the prefix of the test library), for example
`SELECT id,status,attempt_count,last_error FROM <prefix>wplc_renewals`.

### Requirements and warnings

- The environment of the integration suite: `WP_TESTS_DIR`, `WP_CORE_DIR`, `WPLC_WC_DIR`
  (see `tests/integration-bootstrap.php` and `bin/install-test-env.sh`), PHP CLI, MySQL or MariaDB.
- **The harness truncates** the plugin tables, the order tables and the Action Scheduler
  tables of that test database on every scenario. Use a test library with its own table
  prefix and confirm with `WPLC_STRESS_ALLOW_TRUNCATE=1`. Never point it at a real site.
- Output goes to `$WPLC_STRESS_OUT` (default: `$TMPDIR/wplc-stress`).
- The scenarios switch the advance-notice rule off (`wplc_renewal_requires_advance_notice`),
  because they charge licenses that became due seconds ago.
- `QA_CHARGE_MS` sets how long the fake gateway takes per charge (default 700 ms).
