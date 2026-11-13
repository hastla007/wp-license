# Multisite

## Policy

WP License Captain is a license **server**. It is meant to run on **one** shop site: the site where WooCommerce sells the licenses. Running it on several sites of a network gives several independent license servers with separate licenses, releases and API endpoints — nothing is shared.

Whether a *product* plugin (the license client) supports multisite, and whether a network counts as one activation or one per site, is decided by that product's client, not here.

## Behavior

| Situation | What happens |
| --- | --- |
| Activation on a single site of a network | Tables are created for that site (`{site prefix}wplc_*`). Recommended. |
| Network activation | The schema is created only for the site the activation ran on. Every other site creates its tables lazily on its first request, and only if WooCommerce is active there (`Schema::maybe_upgrade()` on `plugins_loaded`). Sites without WooCommerce get no tables and no hooks. |
| New site in the network | Nothing at creation time. Tables appear on the first request as above, so no `wp_initialize_site` handling is needed. |
| Site is deleted | WordPress drops the site's tables; the plugin adds that site's `wplc_*` tables to the list (`wpmu_drop_tables`), so no orphan tables stay behind. This is the only place where license data is removed automatically — deleting a site is an explicit decision to delete its data. Export licenses first if they are still needed. |
| Site is archived, deactivated or marked as spam | Nothing is changed or deleted. |
| Plugin is deactivated or deleted | Tables stay (business records), on every site. See `uninstall.php`. |

Everything is per site: tables, the schema version option `wplc_schema_version`, settings, and the private package directory (`wp-content/uploads/sites/{id}/wplc-packages/`). Migrations are serialized per site with a MySQL lock whose name includes the site prefix.

## Known limits

- `wpmu_drop_tables` only runs in a request where the plugin is loaded. If the plugin is active on a sub-site only and that site is deleted from the network admin, its `wplc_*` tables stay and must be dropped manually. Network-activate the plugin, or activate it on the main site as well, to avoid this.
- Uploaded packages of a deleted site are removed by WordPress together with the site's upload directory. A directory moved with `wplc_package_storage_dir` is not touched.
- The daily maintenance event (`wplc_daily_lifecycle`) is scheduled per site on the first request that loads the plugin there. Deactivating the plugin network-wide clears it only for the site the deactivation ran on; the leftover events on other sites do nothing while the plugin is inactive.
- With WooCommerce missing on the activating site, activation stops with an error; network activation therefore has to be started from a site where WooCommerce is active.
- Multisite behavior is implemented but not yet verified on a real network.
