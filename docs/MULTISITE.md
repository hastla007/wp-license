# Multisite

## Policy

WP License Captain is a license **server**. It is meant to run on **one** shop site: the site where WooCommerce sells the licenses. Running it on several sites of a network gives several independent license servers with separate licenses, releases and API endpoints — nothing is shared.

Whether a *product* plugin (the license client) supports multisite, and whether a network counts as one activation or one per site, is decided by that product's client, not here.

## Behavior

| Situation | What happens |
| --- | --- |
| Activation on a single site of a network | Tables are created for that site (`{site prefix}wplc_*`). Recommended. |
| Network activation | The schema is created for the site the activation ran on, and updated on every other site that already has plugin tables (up to 200 sites per activation). A site that never used the plugin creates its tables on its first admin, WP-Cron or WP-CLI request, and only if WooCommerce is active there (`Schema::maybe_upgrade()` on `plugins_loaded`). Front-end and API requests never create tables. Sites without WooCommerce get no tables and no hooks. |
| New site in the network | Nothing at creation time. Tables appear on the first admin or cron request as above, so no `wp_initialize_site` handling is needed. |
| Site is deleted | WordPress drops the site's tables; the plugin adds that site's `wplc_*` tables, including `wplc_reminder_log`, to the list (`wpmu_drop_tables`), so no orphan tables stay behind. This is the only place where license data is removed automatically — deleting a site is an explicit decision to delete its data. Export licenses first if they are still needed. |
| Site is archived, deactivated or marked as spam | Nothing is changed or deleted. |
| Plugin is deactivated network-wide | Scheduled events are cleared and the deactivation time is stored on every site that has plugin tables (up to 200 sites). Tables stay. |
| Plugin is deleted | `uninstall.php` runs for every site of the network (up to 500): scheduled events, pending Action Scheduler jobs and working state are removed, tables and settings stay. With `WPLC_REMOVE_ALL_DATA` the tables and options of every site are removed. |
| A user is deleted from the network | The licenses of that user are detached on the site where the `deleted_user` hook runs with the plugin loaded. Removing a user from a single site (`remove_user_from_blog`) changes nothing. |

Everything is per site: tables, the schema version option `wplc_schema_version`, settings, the installation fingerprint of the staging guard (home URL, database name and the site's table prefix), the billing pause, and the private package directory (`wp-content/uploads/sites/{id}/wplc-packages/`). Migrations are serialized per site with a MySQL lock whose name includes the site prefix.

## Secrets and the Stripe webhook in a network

The secret constants in `wp-config.php` are the same for every site of the network. For the license-key and encryption key rings that is harmless: each site has its own tables.

For Stripe it is a limit. Stripe creates one signing secret per webhook endpoint, every site has its own endpoint URL (`https://{site}/wp-json/wplc/v1/webhooks/stripe`), and `WPLC_STRIPE_WEBHOOK_SECRET` holds exactly one secret. Therefore:

- **Only one site of a network can receive Stripe webhooks.** Create the webhook endpoint in Stripe for that site only and put its secret into the constant. On any other site of the network every delivery fails the signature check.
- Do not point two endpoints at two sites with the same Stripe account: the second site rejects everything, and even with a matching secret it would receive the events of the first site's payments, which it cannot assign (they end in manual review).
- A second license shop therefore needs its own WordPress installation (own `wp-config.php`, own Stripe webhook endpoint), not a second site in the same network.

A webhook secret per site and a site identifier in the payment metadata are not implemented.

## Known limits

- `wpmu_drop_tables` only runs in a request where the plugin is loaded. If the plugin is active on a sub-site only and that site is deleted from the network admin, its `wplc_*` tables stay and must be dropped manually. Network-activate the plugin, or activate it on the main site as well, to avoid this.
- Uploaded packages of a deleted site are removed by WordPress together with the site's upload directory. A directory moved with `wplc_package_storage_dir` is not touched.
- The daily maintenance event (`wplc_daily_lifecycle`) is scheduled per site on the first request that loads the plugin there. Network-wide deactivation and deletion clear it on every site (limits above); on larger networks leftover events on the remaining sites do nothing while the plugin is inactive.
- The staging guard compares per site. Changing the domain of one site of the network pauses billing on that site until an administrator of the site (capability `manage_options`) confirms it.
- With WooCommerce missing on the activating site, activation stops with an error; network activation therefore has to be started from a site where WooCommerce is active.
- Multisite behavior is implemented but not yet verified on a real network.
