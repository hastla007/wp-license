# 10. WP-CLI reference

All commands live under `wp wplc`. `wp help wplc <command>` prints the built-in help. On a multisite network every command works on the site selected with `--url=<site>`; repeat it per site. License keys and secrets are never printed.

Commands that change many rows are **dry runs by default** and need `--apply`. Several end with **exit code 1 while work is left**, so a script can loop until they succeed.

## Licenses

### `wp wplc license show <license> [--format=<format>]`

Shows one license with its activations, renewals and latest audit entries. `<license>`: license ID, complete license key or license UUID. `--format`: `text` (default) or `json`.

```sh
wp wplc license show 123
wp wplc license show WPLC-... --format=json
```

### `wp wplc license issue`

Issues a license by hand, without an order (same path as "Add license manually"): nothing is charged, no automatic renewal, audited with the reason. The customer gets the key by email and in the account.

| Option | Meaning |
| --- | --- |
| `--customer=<user>` | User ID or email address of an existing account (required). |
| `--expires=<date>` | Last day of the term, `YYYY-MM-DD`; the license runs until the end of that day in the site time zone (required). |
| `--product-id=<id>` | WooCommerce product or variation with an active plan… |
| `--plan=<plan-key>` | …and the plan key when the product has more than one active plan. |
| `--plan-id=<id>` | ID of an active catalog plan, instead of `--product-id`. |
| `--reason=<text>` | Reason for the audit log. |
| `--skip-email` | Do not send the license email. |
| `--yes` | Do not ask for confirmation. |

```sh
wp wplc license issue --product-id=812 --customer=client@example.com --expires=2027-12-31 --reason="Project contract"
```

### `wp wplc activation list --license=<id> [--status=<status>]`

Lists the activations of a license. `--status`: `active` (default), `deactivated` or `all`.

### `wp wplc activation release [<activation-id>...] [--license=<id>] [--environment=<environment>] [--reason=<text>] [--yes]`

Releases activations through the same code as the customer account and the admin; the staging site of a released live site goes with it. Either activation IDs **or** `--license` (all active activations of that license, asks for confirmation). `--environment` (with `--license`): `production`, `staging` or `development`.

```sh
wp wplc activation release 55 56
wp wplc activation release --license=123 --environment=development
```

### `wp wplc sync-entitlements`

Gives existing licenses the current entitlements of their plan (chapter 4). Dry run without `--apply`; exit code 1 while licenses differ.

| Option | Meaning |
| --- | --- |
| `--product=<product-key>` | Licenses of this product (required unless `--license`). |
| `--plan=<plan-key>` | Only this plan. |
| `--license=<id>` | Only this license. |
| `--apply` | Write the changes. |
| `--allow-reductions` | Also apply changes that take something away. |
| `--after-id=<number>`, `--max-rows=<number>` | Split large runs. |

### `wp wplc price-change announce` / `withdraw`

Announces a higher renewal price with notice, or withdraws an announcement (chapter 8).

| Option (`announce`) | Meaning |
| --- | --- |
| `--amount=<amount>` | New renewal amount, decimal with a point, in the form of the product price (required). |
| `--effective=<date>` | Day from which it applies, `YYYY-MM-DD` UTC, at least the notice period ahead (required). |
| `--reason=<text>` | Reason for the audit log (required). |
| `--license=<id>` | One license… |
| `--product=<product-key>`, `--plan=<plan-key>`, `--currency=<code>` | …or every active license of a product (and plan); `--currency` is required with `--product`. |
| `--apply` | Write the announcements and send the emails. |

`wp wplc price-change withdraw --license=<id> --reason=<text>`.

## Catalog

| Command | Purpose |
| --- | --- |
| `wp wplc catalog import <file>... [--dry-run]` | Import catalog files; `--dry-run` shows what would change. |
| `wp wplc catalog validate <file>...` | Check files without importing. |
| `wp wplc catalog list [--format=<format>]` | List the products of the catalog (`table`, `json`, `csv`). |
| `wp wplc catalog export <product-key>` | Print the catalog file of one product (`> wplc-catalog.json`). |
| `wp wplc catalog remove <product-key> [--yes]` | Remove a product; plans and licenses keep their entitlements. |

### `wp wplc project-product save`

Creates or updates a **project product** (chapter 4): a private, virtual WooCommerce product that costs nothing, with one plan. It does what the product editor does for "License product", product key, plan, live sites and entitlements, so a project can be set up by a script. The first run creates the WooCommerce product; a later run with the same product key and plan key updates it and keeps the plan ID. A product that is not private is never changed by this command.

| Option | Meaning |
| --- | --- |
| `--product=<product-key>` | Product key of the plugin, its folder name (required). |
| `--name=<name>` | Name the client sees in the account and in emails; needed when the product is created. |
| `--plan=<plan-key>` | Plan key. Default: `project`. |
| `--sites=<number>` | Live sites, 1 to 1000. Default: 1. |
| `--entitlements=<keys>` | Comma-separated keys of the product's catalog file, `all` (default: every available key of the catalog file) or `none`. Import the catalog file first. |
| `--support=<level>` | `community`, `standard` (default) or `priority`. |
| `--format=<format>` | `text` (default) or `json`: `product_id`, `plan_id`, `product_key`, `plan_key`, `created`, `entitlements`. |

A whole project product from the command line:

```sh
wp wplc catalog import acme-connector/wplc-catalog.json
wp wplc project-product save --product=acme-connector --name="Acme Connector" --sites=2 --format=json
wp wplc license issue --plan-id=<plan_id> --customer=client@example.com --expires=2027-12-31 --reason="Project contract" --yes
wp wplc release create --product=acme-connector --version=1.0.0 --zip=acme-connector.zip --publish
```

## Releases

### `wp wplc release create`

Creates a release from a ZIP on this server (copied into the private package directory), or from an object in the configured S3-compatible bucket (chapter 5).

| Option | Meaning |
| --- | --- |
| `--product=<product_key>` | Product key from the catalog (required). |
| `--version=<version>` | `1.2.3`, `1.2.3-alpha1`, `1.2.3-beta1` or `1.2.3-rc1` (required). |
| `--zip=<path>` | Path of the package. Either `--zip` or `--object`. |
| `--object=<location>` | Object in the configured bucket: `s3://bucket/key` or only the key. Needs `--sha256`. |
| `--sha256=<checksum>` | SHA-256 of the object (with `--object`). |
| `--channel=<channel>` | `stable` (default) or `beta`. |
| `--requires-php=<version>`, `--requires-wp=<version>` | Default: the plugin header. |
| `--tested-wp=<version>` | WordPress version tested with. |
| `--requires-core=<version>` | Minimum version of your free core plugin. |
| `--notes=<html>` / `--notes-file=<path>` | Release notes. |
| `--publish` | Publish at once (otherwise a draft). A release that cannot be published is removed again. |
| `--reason=<text>` | Reason for the release log with `--publish`. |
| `--porcelain` | Print only the release ID. |

### `wp wplc release publish <id> --reason=<text>` / `wp wplc release withdraw <id> --reason=<text>`

Publish a draft, or withdraw a published release.

### `wp wplc storage check [--key=<key>]`

Shows endpoint host, bucket, region and addressing style of the S3-compatible object storage (`WPLC_S3_*` constants, chapter 5) and sends a signed `HEAD` request to the bucket, or with `--key` to one object (enough when the key may only read objects). Exit code 1 with the reason (access denied, not found, wrong region or endpoint, unreachable) when it fails. Neither the secret nor the signed URL is printed.

## Keys and secrets

### `wp wplc signing-key`

Prints the public response signing key and its key ID; creates the key on first use when `WPLC_RESPONSE_SIGNING_KEY` is not set.

| Option | Meaning |
| --- | --- |
| `--field=<field>` | Print only `public_key`, `key_id`, `source`, `state`, `next_public_key` or `next_key_id`. |
| `--prepare` | Create the next key without using it and print its public key. |
| `--rotate` | Switch to the prepared key (asks for confirmation). |
| `--force` | With `--rotate`: create and use a brand-new key although none was prepared. Every delivered client rejects the server until updated. |
| `--yes` | Do not ask for confirmation. |
| `--generate` | Print a new value for `WPLC_RESPONSE_SIGNING_KEY` and its public key without storing anything. |

`--prepare` and `--rotate` are not available while the key comes from the constant.

### `wp wplc rotate-installation-secrets [--apply] [--batch-size=<number>] [--after-id=<number>] [--max-rows=<number>]`

Re-encrypts installation secrets and the stored signing keys with the current encryption key. Dry run without `--apply`. `--batch-size`: rows per query (default 100, at most 500). Ends with exit code 1 while any row still uses an old key; remove an old key from the ring only after exit code 0.

### `wp wplc rekey-licenses [--from=<key-id>] [--apply] [--notify] [--release-sites] [--max-rows=<number>]`

Issues new keys for every license derived with one (leaked) secret ID. Without `--from` it lists the key IDs in use with their counts; without `--apply` it only counts. `--notify` emails each owner the new key; `--release-sites` also releases their sites. Refuses to run while the leaked ID is still current.

### `wp wplc recover-license-key-ids --to=<key-id> [--apply] [--after-id=<number>] [--max-rows=<number>]`

Remaps licenses to a configured key ID whose secret reproduces their stored verifier (after a renamed ring entry). Dry run without `--apply`; exit code 1 while rows are left.

## Settings

### `wp wplc settings list [--group=<group>] [--format=<format>]`

Lists every setting of **WooCommerce → License settings** with its stored value, its default, the value in effect after the filters of the shop (where it can be told) and the filter that belongs to it (chapter 13).

### `wp wplc settings get <key>` / `wp wplc settings set <key> <value> [--yes]`

Reads or changes one setting. `set` uses the same check as the settings page and refuses a value that is not valid; lists are separated by commas, switches are `1` or `0`. Setting `uninstall_data` to `delete` needs `--yes`. The cross checks of the settings page apply as well: a setting that cannot change is reported with its reason and the command fails (exit code 1) instead of reporting success. That is the case for a locked setting (for example the license key prefix once licenses exist), a value set by a constant in `wp-config.php` (object storage), a value in conflict with another setting (the two customer account addresses must differ and must not be the address of a WooCommerce page such as `orders`) and a value refused when it was stored. A combination that works but is risky (a grace period not longer than the last retry day) is changed and printed as a warning.

### `wp wplc settings export` / `wp wplc settings import <file> [--apply] [--yes]`

`export` prints all settings as JSON, the same format as the export of the settings page. It contains no secret. `import` shows the differences between the file and the stored settings and changes nothing; with `--apply` it changes them. One value that is not valid stops the whole import. Rows that cannot change (locked, set by a constant, in conflict, refused; see `set`) are listed as "Not changed: <key>: <reason>" while the other rows are applied; when no row could change, the command fails. The choice "When the plugin is deleted" (`uninstall_data`) is never part of an export and is never applied from an import, a reset or a country profile; change it with `wp wplc settings set uninstall_data delete --yes` or on the settings page.

```
wp wplc settings export > wplc-settings.json
wp wplc settings import wplc-settings.json
wp wplc settings import wplc-settings.json --apply
```

### `wp wplc settings reset [--group=<group>] [--apply]`

Shows what a reset of one group, or of all settings, to the built-in defaults would change; `--apply` does it. The trusted package hosts are never reset.

### `wp wplc settings test-alert`

Sends a test message to the recipients of the health alerts (setting `health_alert_recipients`).

Every change made with these commands is written to the audit log with the source `cli`.

## Webhooks

The outgoing webhooks of chapter 14. Same checks as the admin screen: `https://` on port 443, no private or local address, at least one event. Every change is written to the audit log.

### `wp wplc webhook list [--format=<format>]`

Lists the endpoints with ID, name, target URL, events, whether they are on (`no (failures)` when they were switched off after failed deliveries), failed deliveries in a row and the times of the last success and failure. Never prints a secret.

### `wp wplc webhook add <url> --events=<events> [--name=<name>] [--inactive]`

Adds an endpoint and prints its secret **once**. `--events` is a comma-separated list of event names, or `all`.

```
wp wplc webhook add https://hooks.example.com/licenses --events=license.issued,license.status_changed --name="CRM"
```

### `wp wplc webhook delete <id> [--yes]`

Deletes an endpoint and its delivery log.

### `wp wplc webhook test <id>`

Sends the test event `webhook.test` now and prints the event ID, the HTTP status and the duration; exit code 1 when it was not delivered. The reason is printed in the same words as on the webhook screen, for example "The test event could not be delivered: The receiver answered with HTTP 503." (not the stored error code `http_status:503`). Works on an endpoint that is switched off; it is not retried.

## Repair

Thin wrappers around the steps the scheduler normally runs; all guards (locks, status checks, idempotency) apply unchanged.

| Command | Purpose |
| --- | --- |
| `wp wplc repair issue-order <order-id>` | Issue the licenses of a paid order that has none yet. |
| `wp wplc repair renewal <renewal-id> [--yes]` | Run one renewal now. For an automatic renewal this can charge the customer's card. The renewal ID is shown by `wp wplc license show`. |
| `wp wplc repair webhook <inbox-id>` | Process a stored Stripe event again (only events still waiting for reconciliation). |

## Schema and multisite

| Command | Purpose |
| --- | --- |
| `wp wplc schema status` | Installed and expected schema version and every structure problem. |
| `wp wplc schema orphans [--drop] [--yes]` | List (and drop) `wplc_*` tables of network sites that no longer exist. |
| `wp wplc schema install-mu-helper [--yes]` | Install the must-use helper that drops a deleted site's tables (multisite). |
| `wp wplc schema remove-mu-helper [--yes]` | Remove it again. |

## Useful WordPress commands

```sh
wp option get wplc_last_run --format=json        # heartbeat of the scheduled jobs
wp cap add shop_manager wplc_grant_licenses --grant=false   # take a capability away
wp site list --field=url | xargs -I % wp --url=% wplc schema status   # every site of a network
```
