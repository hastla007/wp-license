# License events (hook contract)

Versioned events for other plugins that run **in the same WordPress as the license server** (the shop). Built for the Pro add-on of the workflow product, which is the only consumer of license events (decision D3: the free workflow core knows nothing about licenses); any other plugin in the shop may listen as well. Decisions D2, D4, D5, D8 and D9 (`docs/TODO.md`, section 14) define the frame; task WFC-LIC-04 (section 12) the details.

- **Contract version:** `WPLC_EVENT_CONTRACT_VERSION` (defined in `wp-license.php`, equal to `WPLC\Events\LicenseEvents::CONTRACT_VERSION`), currently **`1.0.0`**. Semantic versioning: a new event or a new optional field raises the minor version; removing or renaming an event or a field, or changing its meaning, raises the major version. The `version` field of each event counts changes of that event's shape.
- **Transport:** PHP actions in the same request (D5). Nothing is sent to customer sites, there is no outbox, no signature and no subscription per installation (D2).
- **Delivery:** each event fires **exactly once**, never repeated and never stored by the server (D9). The receiver stores and retries itself. The one exception: `license.license.issued` comes from the follow-up job, which runs again for a license whose key email is still missing; the repeat carries the **same `event_id`**.
- **After the commit:** an event fires only after the state change is committed and the license lock (and the activation lock) is released, at the latest at `shutdown`. A change that was refused or rolled back fires nothing. See [After the commit](#after-the-commit).
- **Errors of listeners:** a listener that throws is caught and reported through `wplc/job_failed` (job name `license_event:<event>`); the license operation continues. Other listeners of the same hook after the throwing one do not run for that event (WordPress semantics); the same envelope still goes to `wplc/event/<event>`.

## Hooks

```php
add_action( 'wplc/event', function ( array $event ) {
	// Every event of the contract.
} );

add_action( 'wplc/event/license.site.activated', function ( array $event ) {
	// One event by name.
} );
```

Both actions receive the same envelope. Without any listener the server builds no envelope and reads nothing.

## Envelope

An array of scalar values only (string, int, bool, null):

| Field | Type | Meaning |
| --- | --- | --- |
| `event_id` | string | UUID. Random (v4) for every event; for `license.license.issued` derived from the license (v5 layout), so a repeated follow-up keeps it. Use it to recognise duplicates. |
| `event` | string | Event name, `license.<object>.<event>` (see below). |
| `version` | int | Version of this event's shape, currently `1` for all. |
| `contract` | string | `WPLC_EVENT_CONTRACT_VERSION`. |
| `occurred_at` | string | UTC, ISO 8601 (`2026-11-16T10:00:00Z`). |

Events about a license add:

| Field | Type | Meaning |
| --- | --- | --- |
| `license` | string | Public reference `WPLC-XXXXXXXX` (the key prefix shown in the account and the admin; `LicenseKey::prefix()`). Never the key, never the database ID. |
| `product_key` | string | Product key, e.g. `workflow-captain-pro`. |
| `plan_key` | string | Plan key after the event (`single`, `business`, `agency` …). |
| `status` | string | License status after the event (`active`, `past_due`, `suspended`, `expired`, `revoked`). A cancellation of automatic renewal is not a status: the license stays `active` until it expires. |
| `customer_user_id` | int | WordPress user ID of the owner (zero when the license has no account). |

**Never in an event:** license key or key hash, tokens or secrets, email addresses, site addresses, payment data, database IDs of licenses or activations (D8). Money with currency would be allowed (D8b); version 1 carries none. The privacy assessment of the user ID belongs to the legal review (T-93).

## Events (version 1)

| Event | Fires when | Extra fields |
| --- | --- | --- |
| `license.license.issued` | The follow-up job of a newly issued license finished (`wplc/license_issued_followup`), and when an administrator issued a license by hand. Never from `wplc/license_issued`, which passes the key. | `order_id` (int, WooCommerce order; `0` for a license issued by hand) |
| `license.license.status_changed` | Every successful status transition and every renewal (`wplc/license_status_changed`): renewal, expiry, past due, suspension, reactivation, revocation. | `previous_status` (string), `action` (string): `renewal_succeeded`, `renewal_failed`, `license_expired`, `chargeback`, `order_cancelled`, `order_refund`, `admin_status_change`, `admin_restore`, `admin_extend` |
| `license.license.plan_changed` | The plan changed (`wplc/license_plan_changed`): paid upgrade, downgrade at renewal, administrator change, revert after a refund. | – |
| `license.site.activated` | A new site activation was stored (`POST /activations` with a new installation). Not for a repeated activation of a known installation. | `environment` (`live`, `staging`, `development`) |
| `license.site.deactivated` | A site activation ended: by the installation through the API, in the customer account, by support (admin screen, bulk action, WP-CLI, key rotation), by the system (license ended, privacy erasure), or because the same site activated again with a new installation ID. The staging copy that ends with its live site gets its own event. | `environment`, `initiator` (`api`, `customer`, `support`, `system`) |
| `license.site.limit_reached` | An activation was refused because a limit was reached (`409 site_limit`, `staging_limit`, `development_limit`). One event per refused request. | `environment`, `limit` (int) |
| `license.release.published` | A release was published. | instead of the license fields: `product_key`, `release_version`, `channel` |
| `license.release.withdrawn` | A published release was withdrawn. | as above |

Not (yet) an event: a failed renewal payment (candidate from the workflow concept; the specification decides), the transfer of a site between licenses (`wplc/activation_transferred`), an owner change (`wplc/license_owner_changed`) and a key change (the key-carrying actions are never turned into events).

## After the commit

`WPLC\Events\AfterCommit` keeps the promise for the events **and** for the three existing actions the specification takes over: `wplc/license_issued_followup`, `wplc/license_status_changed` and `wplc/license_plan_changed` (WFC-LIC-07).

- A call is made right away when the request holds no license lock, no activation lock and no transaction the plugin opened. Otherwise it waits.
- The renewal changes license and entitlements in one transaction (`AfterCommit::begin()` / `commit()` / `rollback()` around WooCommerce's transaction helper): the status action fires after the commit; on a rollback it is dropped.
- The license lock (`LicenseLifecycle::run_locked()`) and the activation locks (`AfterCommit::hold()` / `release()`) run the queue when the last of them is released.
- At `shutdown` the rest of the queue runs; calls of a transaction that was never committed are dropped.

Evidence: `tests/Events/LicenseEventsTest.php` (shape of every event, no secrets, queue), `tests/integration/LicenseEventsIntegrationTest.php` (every event once, after the locks, not for a refused or rolled-back change, the existing actions unchanged) and `tests/integration/LicenseEventsAfterCommitIntegrationTest.php` (a listener of each of the three actions reads the committed state through a second database connection while no license lock is held).

## Reading more: `WPLC\Events\LicenseReader`

A read-only facade for the receiver (WFC-LIC-05). All methods take the public reference from an event; the optional `$customer_user_id` narrows the lookup (and resolves the rare case of two licenses with the same reference, which otherwise returns null).

| Method | Returns |
| --- | --- |
| `get( $reference, $customer_user_id = 0 )` | `array{license, product_key, plan_key, status, access, expires_at, access_until, customer_user_id}` or null |
| `status( $reference )` | Stored status or null |
| `plan( $reference )` | `array{product_key, plan_key}` or null |
| `expires_at( $reference )` | End of the paid period (UTC, ISO 8601) or null |
| `entitlements( $reference )` | Entitlements granted now (key ⇒ value); empty without access |
| `is_entitled( $reference, $key )` | Whether a boolean entitlement is granted now |
| `for_user( $user_id, $product_key = '' )` | Summaries of the user's licenses, newest first (at most 200) |

It runs SELECT queries only and never returns a key, a key hash, an email address, a secret or a database ID (`LicenseEventsIntegrationTest::test_the_facade_issues_only_select_queries_and_discloses_no_key_or_email`). It offers no way to change a license: refunds, renewals, status changes and key issue stay behind the plugin's capabilities `wplc_*` and nonces, and the workflow product gets none of them (D10, D11). On customer sites nothing is needed: an add-on reads its own license through the client library (`LicenseManager::status()`, `is_entitled()`).
