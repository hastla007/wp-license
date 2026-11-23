# License actions for plugins in the shop (hook contract)

The license server is a provider in the sense of the suite specification `docs/SPEC-suite-events.md` in `hastla007/wp-workflowmaster` (sections 3, 4.5, 6 and 11; open questions F-02 and W-4). Its license actions are consumed only in the vendor's own shop, in the same WordPress, by the Pro add-on of the workflow product (decision D3: the free workflow core knows nothing about licenses). Any other plugin in the shop may listen as well. Decisions D2, D4, D5, D8 and D9 (`docs/TODO.md`, section 14) and tasks WFC-LIC-04, -05 and -07 (section 12) are the frame.

## What the server provides, and what it does not

- **Plain PHP actions** in the same request (D5), with scalar arguments. Nothing is sent to customer sites: no webhook, no outbox, no signature (D2, R-11.1). Separately, and only when the shop adds an endpoint, the same eight actions can be sent to the shop's own systems as signed HTTPS requests (outgoing webhooks, SET-LIC-46): that layer has its own event ID, retries and delivery log and is described in [WEBHOOKS.md](WEBHOOKS.md). The PHP actions below are unchanged by it.
- **No envelope and no event ID.** The consumer builds the envelope, its `id`, `idempotency_key`, `occurred_at` and the rest (R-6.1, W-4). The server stores nothing and repeats nothing (D9).
- **Subject:** the record ID `license_id` (`subject.id_kind` = `record`). The server has no public license reference of its own; the key prefix `WPLC-XXXXXXXX` that the customer account shows as "reference" is part of the license key and is **never** passed or accepted (§11, F-02).
- **Never in an argument:** the license key or any part of it, its hash, a site address or other identifier of an activated site, an email address, a name, payment data, a reason text.
- **Contract version** `WPLC_CONTRACT_VERSION` (R-3.1; prefix of `WPLC_VERSION`) in `wp-license.php`, currently `1.0.0`. The rules (SemVer, stability promise, deprecation) and the table "since / after commit" for every action (R-3.4, R-3.9) are in [EXTENSION-POINTS.md](EXTENSION-POINTS.md#hook-contract-for-plugins-in-the-shop-version-100).

## Actions

| Action | Arguments | Suite event | Fires |
| --- | --- | --- | --- |
| `wplc/license_issued_followup` | `int $license_id, int $order_id` | `license.license.issued` | when the follow-up job of a newly issued license is done; since 0.2.0 also right after a license is issued by hand (`$order_id` 0). The job can run again for a license whose key email is still missing: the consumer recognises the repeat by its idempotency key. |
| `wplc/license_status_changed` | `int $license_id, string $old_status, string $new_status, string $action` | `license.license.status_changed` | after every successful status transition and every renewal. `$action`: `renewal_succeeded`, `renewal_failed`, `license_expired`, `chargeback`, `order_cancelled`, `order_refund`, `admin_status_change`, `admin_restore`, `admin_extend`. Statuses: `active`, `past_due`, `suspended`, `expired`, `revoked`. |
| `wplc/license_plan_changed` | `int $license_id` | `license.license.plan_changed` | after a paid upgrade, a downgrade at renewal, an administrator change or a revert. |
| `wplc/activation_created` | `int $license_id, int $activation_id, string $environment` | `license.activation.created` (reserved) | after a new site activation was stored (`POST /activations` with a new installation); not for a repeated activation of a known installation. `$environment`: `live`, `staging`, `development`. |
| `wplc/activation_removed` | `int $license_id, int $activation_id, string $environment, string $initiator` | `license.activation.removed` (reserved) | after a site activation ended. `$initiator`: `api` (the installation released itself, or the same site activated again with a new installation ID), `customer` (account page), `support` (admin screen, bulk action, WP-CLI, key rotation), `system` (license ended, privacy erasure). The staging copy that ends with its live site gets its own call. |
| `wplc/site_limit_reached` | `int $license_id, string $environment, int $limit` | `license.license.site_limit_reached` (reserved) | once per activation request refused because a limit was reached (`409 site_limit`, `staging_limit`, `development_limit`). |
| `wplc/release_published` | `int $release_id, string $product_key, string $version, string $channel` | `license.release.published` (reserved) | after a release was published. |
| `wplc/release_withdrawn` | as above | none (not in the suite catalog v1) | after a published release was withdrawn. An extra; the consumer may ignore it. |

More about a license comes from the facade below; the owner (`actor_user_id` of a `license.*` event, §6) is `LicenseReader::get( $license_id )['customer_user_id']`.

**Never attach to** `wplc/license_issued`, `wplc/license_key_reissued` or `wplc/license_key_resend_requested`: they pass the plaintext key (§4.5).

**Errors of listeners:** a listener of the five new actions that throws is caught and reported through `wplc/job_failed` (job `action:wplc/<name>`); the activation, deactivation or release operation is not disturbed. The three existing actions keep their behaviour.

## After the commit

`WPLC\Events\AfterCommit` keeps the promise for all eight actions above (WFC-LIC-07, R-3.9).

- An action fires right away when the request holds no license lock, no activation lock and no transaction the plugin opened. Otherwise it waits.
- The renewal changes license and entitlements in one transaction (`AfterCommit::begin()` / `commit()` / `rollback()` around WooCommerce's transaction helper): `wplc/license_status_changed` fires after the commit; on a rollback it is dropped.
- The license lock (`LicenseLifecycle::run_locked()`) and the activation locks (`AfterCommit::hold()` / `release()`) run the queue when the last of them is released.
- At `shutdown` the rest of the queue runs; calls of a transaction that was never committed are dropped.

Evidence:

- `tests/Events/LicenseEventsTest.php`: arguments of the new actions (scalar IDs only), the contract constant, the queue (commit, rollback, activation lock, shutdown).
- `tests/integration/LicenseEventsIntegrationTest.php`: every action once, with the documented arguments, while no lock is held; nothing for a refused or rolled-back change; a throwing listener does not break the operation; no argument contains the key, key hash, key prefix or an email address.
- `tests/integration/LicenseEventsAfterCommitIntegrationTest.php`: a listener of each of the three existing actions reads the committed state through a second database connection while no license lock is held.

## Reading more: `WPLC\Events\LicenseReader`

Read-only facade (WFC-LIC-05). All methods take the `license_id` from an action; the optional `$customer_user_id` narrows the lookup to that owner.

| Method | Returns |
| --- | --- |
| `get( $license_id, $customer_user_id = 0 )` | `array{license_id, product_key, plan_key, status, access, expires_at, access_until, customer_user_id}` or null |
| `status( $license_id )` | Stored status or null |
| `plan( $license_id )` | `array{product_key, plan_key}` or null |
| `expires_at( $license_id )` | End of the paid period (UTC, ISO 8601) or null |
| `entitlements( $license_id )` | Entitlements granted now (key ⇒ value); empty without access |
| `is_entitled( $license_id, $key )` | Whether a boolean entitlement is granted now |
| `for_user( $user_id, $product_key = '' )` | Summaries of the user's licenses, newest first (at most 200) |

It runs SELECT queries only and never returns a key, key prefix, key hash, email address, site address, payment data or secret (`LicenseEventsIntegrationTest::test_the_facade_issues_only_select_queries_and_discloses_no_key_or_email`). It offers no way to change a license: refunds, renewals, status changes and key issue stay behind the plugin's capabilities `wplc_*` and nonces, and the workflow product gets none of them (D10, D11). On customer sites nothing is needed: an add-on reads its own license through the client library (`LicenseManager::status()`, `is_entitled()`).

## Privacy

The actions pass record IDs; the user ID of the owner is available through the facade. The consumer stores events with the owner's user ID (§12); the assessment belongs to the legal review (D8, T-93). See [PRIVACY-DATA-FLOWS.md](PRIVACY-DATA-FLOWS.md).
