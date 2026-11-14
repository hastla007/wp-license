# Multisite

## Policy

WP License Captain is a license **server**. It is meant to run on **one** shop site: the site where WooCommerce sells the licenses. Running it on several sites of a network gives several independent license servers with separate licenses, releases and API endpoints — nothing is shared.

Whether a *product* plugin (the license client) supports multisite, and whether a network counts as one activation or one per site, is decided by that product's client, not here.

## Behavior

| Situation | What happens |
| --- | --- |
| Activation on a single site of a network | Tables are created for that site (`{site prefix}wplc_*`). Recommended. |
| Network activation | The schema is created only for the site the activation ran on. Every other site creates its tables lazily on its first request, and only if WooCommerce is active there (`Schema::maybe_upgrade()` on `plugins_loaded`). Sites without WooCommerce get no tables, routes or events; only the `wpmu_drop_tables` filter is registered there. |
| New site in the network | Nothing at creation time. Tables appear on the first request as above, so no `wp_initialize_site` handling is needed. |
| Site is deleted | WordPress drops the site's tables; the plugin adds that site's `wplc_*` tables (all 14, including `wplc_reminder_log`) to the list (`wpmu_drop_tables`), so no orphan tables stay behind. The filter is registered on every site where the plugin is loaded, also on sites without WooCommerce, because the deleting request usually runs on the main site. This is the only place where license data is removed automatically — deleting a site is an explicit decision to delete its data. Export licenses first if they are still needed. |
| Site is archived, deactivated or marked as spam | Nothing is changed or deleted. |
| Plugin is deactivated on one site | The plugin's recurring events (`wplc_daily_lifecycle`, `wplc_cleanup_security_rows`, `wplc_send_reminders`) are removed on that site only. |
| Plugin is network-deactivated | The same events are removed on every site of the network. |
| Plugin is deleted (uninstall) | Tables stay (business records), on every site. Cron events, pending Action Scheduler actions of the group `wplc` and the leftover option `wplc_reminder_log_version` are removed on every site of the network (sites without Action Scheduler tables are skipped). See `uninstall.php`. |

Everything is per site: tables, the schema version option `wplc_schema_version`, settings, cron events, Action Scheduler actions, REST endpoints (`/{site path}/wp-json/wplc/v1/…`) and the private package directory (`wp-content/uploads/sites/{id}/wplc-packages/`). A license key issued on one site is unknown (`401 invalid_key`) on every other site. All MySQL advisory locks (schema migration, per-license, per-renewal) include the site prefix in their name, so sites never block each other.

## Verified

Verified on 2026-11-14 with WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3, MariaDB, subdirectory network:

- **Integration suite on a network** (`WP_MULTISITE=1`): the complete suite passes, plus `tests/integration/MultisiteIntegrationTest.php` (skipped on single sites), which covers: tables per blog prefix and lazy creation on the first load, network activation and activation on a single site, a license of one site unknown to the REST API of another, site deletion drops all of the site's tables and keeps the others, lock names per site, deactivation per site and network-wide, uninstall on every site. CI runs this as the multisite cell of the integration matrix.
- **Real network with WP-CLI** (`wp core multisite-convert`, sites with and without WooCommerce): network activation from the main site created only the main site's tables; the first request on a WooCommerce site created its 14 tables and scheduled its events; the site without WooCommerce got no tables, no routes and no events. A license seeded on site 2 activated there (200) and was `invalid_key` on sites 1 and 4. `wp site delete` dropped the site's `wplc_*` tables — also with WooCommerce deactivated on the main site (before the fix the tables stayed). Network deactivation cleared the events on all sites; `wp plugin uninstall` cleared leftover events and Action Scheduler actions on all sites and kept all tables.

Defects found and fixed during verification: network deactivation and uninstall only cleaned up the site the request ran on; the `wpmu_drop_tables` filter was not registered when the deleting site had no WooCommerce.

## Known limits

- `wpmu_drop_tables` only runs in a request where the plugin is loaded. If the plugin is active on a sub-site only (not network-activated, not active on the main site) and that site is deleted from the network admin, its `wplc_*` tables stay and must be dropped manually. Network-activate the plugin, or activate it on the main site as well, to avoid this.
- Uploaded packages of a deleted site are removed by WordPress together with the site's upload directory. A directory moved with `wplc_package_storage_dir` is not touched.
- Network activation creates the schema only for the site it runs on; every other site creates its tables on its first request. Until then a site has no tables (nothing is lost: there is no data yet either).
- With WooCommerce missing on the activating site, activation stops with an error; network activation therefore has to be started from a site where WooCommerce is active.
- Network deactivation and uninstall walk all sites of the network in one request. On very large networks (thousands of sites) this can run into the PHP time limit; the cleanup is repeatable, so running it again (or `wp plugin uninstall` from WP-CLI) finishes it.
- Deactivation does not remove pending Action Scheduler actions (only uninstall does), on a single site as on a network.
- Not verified: subdomain networks, multiple networks in one installation, WordPress 6.5 on a network. Legacy order storage on a network passed locally but is not a CI cell.
