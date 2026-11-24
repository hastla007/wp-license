# Multisite

## Policy

WP License Captain is a license **server**. It is meant to run on **one** shop site: the site where WooCommerce sells the licenses. Running it on several sites of a network gives several independent license servers with separate licenses, releases and API endpoints — nothing is shared.

Whether a *product* plugin (the license client) supports multisite, and whether a network counts as one activation or one per site, is decided by that product's client, not here.

## Behavior

| Situation | What happens |
| --- | --- |
| Activation on a single site of a network | Tables are created for that site (`{site prefix}wplc_*`). Recommended. |
| Network activation | The schema is created for the site the activation ran on, and updated on every other site of the network that already has plugin tables. A site that never used the plugin creates its tables on its first admin, WP-Cron or WP-CLI request, and only if WooCommerce is active there (`Schema::maybe_upgrade()` on `plugins_loaded`). Front-end and API requests never create tables. Sites without WooCommerce get no tables, routes or events; only the `wpmu_drop_tables` filter is registered there. |
| New site in the network | Nothing at creation time. Tables appear on the first admin or cron request as above, so no `wp_initialize_site` handling is needed. |
| Site is deleted | WordPress drops the site's tables; the plugin adds that site's `wplc_*` tables (all 16, including `wplc_reminder_log`, `wplc_consents` and `wplc_license_members`) to the list (`wpmu_drop_tables`), so no orphan tables stay behind. The filter is registered by `wp-license.php` itself as soon as the plugin file is loaded (on every site where the plugin is active or network-activated, also without WooCommerce and before `plugins_loaded`), because the deleting request usually runs on the main site. Besides the 16 schema tables it adds every other `{site prefix}wplc_*` table of that site, e.g. one left by an older version. This is the only place where license data is removed automatically — deleting a site is an explicit decision to delete its data. Export licenses first if they are still needed. If the plugin is active on that site only, see [Site cleanup helper](#site-cleanup-helper-must-use). |
| Site is archived, deactivated or marked as spam | Nothing is changed or deleted. |
| Plugin is deactivated on one site | The plugin's recurring events (`wplc_daily_lifecycle`, `wplc_cleanup_security_rows`, `wplc_send_reminders` and the other plugin hooks) are removed and the deactivation time is stored on that site only. |
| Plugin is network-deactivated | The same is done on every site of the network that has plugin tables. Tables stay. |
| Plugin is deleted (uninstall) | `uninstall.php` runs for every site of the network: cron events, pending Action Scheduler actions of the group `wplc` (sites without Action Scheduler tables are skipped) and working state are removed, tables and settings stay. With `WPLC_REMOVE_ALL_DATA` the tables and options of every site are removed. |
| A user is deleted from the network | The licenses of that user are detached on the site where the `deleted_user` hook runs with the plugin loaded. Removing a user from a single site (`remove_user_from_blog`) changes nothing. |

Everything is per site: tables, the schema version option `wplc_schema_version`, settings, cron events, Action Scheduler actions, REST endpoints (`/{site path}/wp-json/wplc/v1/…`), the installation fingerprint of the staging guard (home URL, database name and the site's table prefix), the billing pause, and the private package directory (`wp-content/uploads/sites/{id}/wplc-packages/`). A license key issued on one site is unknown (`401 invalid_key`) on every other site. All MySQL advisory locks (schema migration, per-license, per-renewal) include the site prefix in their name, so sites never block each other.

## Add-ons on a customer network: one site per subsite (B2)

This section is the license contract for add-ons that run on a customer's multisite network, for example the network-wide workflows of Workflow Captain Pro (`workflow.multisite_network`, TODO WF-MS-25 of wp-workflowmaster). Nothing in the server is specific to it.

- **Every subsite activates by itself and uses one live site.** The bundled client stores credentials per site by default (`network => false`, decision B2): each subsite has its own installation ID and sends its own address (`home` of that subsite). The server counts active live activations of the license (`site_count`) against `site_limit`; it never counts the address of the network root for the whole network. A subdirectory subsite (`example.com/zwei/`) and a subdomain subsite (`zwei.example.com`) are different sites from the main site and from each other (`SiteUrlNormalizationTest`, B-161 rows "two subsites …").
- **Releasing a seat.** "Remove license" on the license screen of a subsite releases its activation (`DELETE /activations/{id}`); the add-on of that subsite then reports no access and stops its premium functions there. A seat that ends on the server (expired, suspended, revoked, released in the customer account) reaches the subsite with its next signed answer.
- **Mapped domain, domain change, migration of the whole network.** The blog ID and the installation ID stay. The client of that subsite notices the new address (`site_changed`, no access) until its administrator moves the activation; the move re-activates the same installation ID, the server stores the new address (`site_url_changed`) and keeps the same live site, so a mapped domain never counts twice. A move to an address that looks like a copy is refused (`site_move_refused`); the copy is then activated as a staging site. After a migration of the whole network every subsite does this once.
- **What the add-on may count.** The enforced limit is `site_limit` of the signed data (see `ENTITLEMENTS.md`). An add-on that combines several subsites (Workflow Captain Pro: every distinct blog ID of a network workflow) may count them for a preview, but it relies on the activation of every participating subsite, not on its own count.
- **Staging on a subsite of a staging network** (owner answer of 24 Nov 2026, UEB-08). The single-site rule applies unchanged: one staging activation per live activation, without a seat of its own. A staging activation on a subsite never counts as a live site.
- **Network mode of the client** (stays, owner answer of 24 Nov 2026, UEB-08). The library keeps `network => true` (one activation for the whole network, credentials in the network options, address of the network). It is meant for buyers of the license server who count differently for their own products: a product that sets it is counted once per network. The Captain add-ons do not use it; for them B2 applies (one site per subsite), and none of them sets it. `workflow.multisite_network` is a function, not a storage mode: it needs the per-site default.
- **Evidence.** `tests/integration/SubsiteSeatsIntegrationTest.php`: the REST API with the addresses of a network (main site, subdirectory and subdomain subsite, a fourth subsite over the limit, mapped domain, release) in every CI cell; the bundled client on three real subsites (two seats, third refused, main site without a license, mapped domain with `move()`, release and reuse of the seat) in the multisite cell. Address rules: `SiteUrlNormalizationTest`.

## Secrets and the Stripe webhook in a network

The secret constants in `wp-config.php` are the same for every site of the network. For the license-key and encryption key rings that is harmless: each site has its own tables.

Stripe creates one signing secret per webhook endpoint, and every site has its own endpoint URL (`https://{site}/wp-json/wplc/v1/webhooks/stripe`). The secrets are therefore configured per site:

```php
define( 'WPLC_STRIPE_WEBHOOK_SECRETS', array(
	1 => array( 'live' => 'whsec_…', 'test' => 'whsec_…' ),
	4 => 'whsec_…', // live secret of site 4
) );
```

- Once `WPLC_STRIPE_WEBHOOK_SECRETS` is defined, a site without an entry accepts no webhook. Without it, `WPLC_STRIPE_WEBHOOK_SECRET` (and `WPLC_STRIPE_WEBHOOK_SECRET_TEST`) apply to the site that owns the endpoint; give them to one site only.
- Every PaymentIntent the plugin creates carries the site identifier in its metadata, and an event that belongs to another site or to the other mode (live/test) is refused instead of ending in manual review. Details: [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md).
- The settings page and the diagnostics page of each site show whether a secret is configured for that site (`StripeWebhook::is_configured()`), and the time of the last valid event per mode.

## Site cleanup helper (must-use)

Optional, for networks on which the plugin is active on single sites only. The plugin ships `mu-plugins/wplc-site-cleanup.php`. Once it is in `wp-content/mu-plugins/` WordPress loads it in every request, so deleting a site drops that site's `wplc_*` tables even when the plugin itself is not loaded in that request.

```
wp wplc schema install-mu-helper          # asks for confirmation; --yes skips it
wp wplc schema remove-mu-helper --yes
```

Copying the file by hand into `wp-content/mu-plugins/` (or `WPMU_PLUGIN_DIR`) works the same way.

- It hooks `wpmu_drop_tables` and adds only tables whose name is exactly the deleted site's prefix followed by `wplc_` (`wp_2_wplc_licenses` for site 2, never `wp_22_wplc_licenses`). It does nothing for the main site, on a single site, or when another plugin passes no list.
- It has no other dependency on the plugin: it keeps working when the plugin is deactivated or deleted (tables stay on uninstall unless `WPLC_REMOVE_ALL_DATA` is set). With the plugin loaded as well, both filters run and the list is de-duplicated.
- Installing it is a decision to delete license data together with a site, like the plugin's own filter. Export licenses of a site before deleting it if they are still needed.
- `install-mu-helper` replaces an older copy of the helper after an update of the plugin and never overwrites another file of that name; `remove-mu-helper` deletes only the helper (recognised by its header line `WPLC MU Helper: site-cleanup`). The helper is not updated automatically.
- The diagnostics page of each site on a network shows the state: installed, older copy, missing (a warning unless the plugin is network-activated) or another file with that name.

## Verified

Verified on 2026-11-14 with WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3, MariaDB, subdirectory network (before the two development lines were merged; the network deactivation now also stores the deactivation time per site):

- **Integration suite on a network** (`WP_MULTISITE=1`): the complete suite passes, plus `tests/integration/MultisiteIntegrationTest.php` (skipped on single sites), which covers: tables per blog prefix and lazy creation on the first load, network activation and activation on a single site, a license of one site unknown to the REST API of another, site deletion drops all of the site's tables and keeps the others, lock names per site, deactivation per site and network-wide, uninstall on every site. CI runs this as the multisite cell of the integration matrix.
- **Real network with WP-CLI** (`wp core multisite-convert`, sites with and without WooCommerce): network activation from the main site created only the main site's tables; the first request on a WooCommerce site created its tables and scheduled its events; the site without WooCommerce got no tables, no routes and no events. A license seeded on site 2 activated there (200) and was `invalid_key` on sites 1 and 4. `wp site delete` dropped the site's `wplc_*` tables — also with WooCommerce deactivated on the main site (before the fix the tables stayed). Network deactivation cleared the events on all sites; `wp plugin uninstall` cleared leftover events and Action Scheduler actions on all sites and kept all tables.

Defects found and fixed during verification: network deactivation and uninstall only cleaned up the site the request ran on; the `wpmu_drop_tables` filter was not registered when the deleting site had no WooCommerce.

## Known limits

- `wpmu_drop_tables` only runs in a request where the plugin is loaded. If the plugin is active on a sub-site only (not network-activated, not active on the main site) and that site is deleted from the network admin, WordPress does not load the plugin file at all in that request, so the plugin's own filter cannot add the tables, and they stay. Install the optional must-use helper (see [Site cleanup helper](#site-cleanup-helper-must-use)), network-activate the plugin, or activate it on the main site as well. Leftovers are listed by `wp wplc schema orphans` (tables `{base prefix}{id}_wplc_*` of site IDs that no longer exist) and dropped with `wp wplc schema orphans --drop` after a confirmation. Nothing is dropped automatically: another installation sharing the database could use a prefix such as `wp_7_`.
- Uploaded packages of a deleted site are removed by WordPress together with the site's upload directory. A directory moved with `wplc_package_storage_dir` is not touched.
- Network activation creates the schema only for the site it runs on and for sites that already have plugin tables; every other site creates its tables on its first admin or cron request. Until then a site has no tables (nothing is lost: there is no data yet either).
- The staging guard compares per site. Changing the domain of one site of the network pauses billing on that site until an administrator of the site (capability `manage_options`) confirms it.
- With WooCommerce missing on the activating site, activation stops with an error; network activation therefore has to be started from a site where WooCommerce is active.
- Network deactivation and uninstall walk all sites of the network in one request. On very large networks (thousands of sites) this can run into the PHP time limit; the cleanup is repeatable, so running it again (or `wp plugin uninstall` from WP-CLI) finishes it.
- Deactivation does not remove pending Action Scheduler actions (only uninstall does), on a single site as on a network.
- Not verified: subdomain networks, multiple networks in one installation, WordPress 7.0 on a network. Legacy order storage on a network passed locally but is not a CI cell.
