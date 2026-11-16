# 12. Upgrading the server plugin

WP License Captain never installs updates of itself. Plan every update like a deployment: staging first, backup, then production.

## How you learn about a new version

- **Release notice.** During the normal WordPress update check (twice a day) the plugin asks the release API of its distribution for the newest published version and shows it on the **Plugins** screen with "Automatic update is unavailable for this plugin": the answer never contains a package. The diagnostics row "Newest release" shows the cached result. The request sends no site data. Switch it off with `define( 'WPLC_DISABLE_UPDATE_CHECK', true );` (or the filter `wplc_self_update_check_enabled`).
- **Updates through your vendor's license server (optional).** If the vendor you bought the plugin from gave you the address, product key and public key of their license server, enter them in `wp-config.php`:

  ```php
  define( 'WPLC_VENDOR_SERVER_URL', 'https://licenses.vendor.example' ); // HTTPS, never this site
  define( 'WPLC_VENDOR_PRODUCT_KEY', 'product-key-of-the-server' );
  define( 'WPLC_VENDOR_PUBLIC_KEYS', array( 'key-id' => 'base64 public key of that server' ) );
  define( 'WPLC_VENDOR_ACCOUNT_URL', 'https://vendor.example/my-account/' ); // optional
  ```

  A license screen then appears under Settings, where you enter the key you bought; WordPress offers updates of the plugin through that server, and the release notice above is switched off. **Your own license never gates function:** with a missing or expired license, or an unreachable vendor server, the shop sells, checks and renews your customers' licenses exactly as before; only updates of the plugin itself stop. An address whose host is this site is refused. Without these constants nothing of this is active.

## Installing an update

1. Read the changelog of the new version (`readme.txt`, section "Changelog", and the release notes).
2. Download `wp-license-captain-<version>.zip` and its `.zip.sha256`; check with `sha256sum -c wp-license-captain-<version>.zip.sha256`.
3. Back up database, package directory and secrets together (chapter 9).
4. Install on staging first and run through your test checklist: activation, entitlement check, update download, a renewal with a test card.
5. In production, upload the ZIP under **Plugins → Add New → Upload Plugin** and choose "Replace current with uploaded".
6. Open **WooCommerce → License diagnostics** and check the schema version and the scheduled jobs.

The client library is versioned separately: a server update never requires new add-on releases (API v1 promise, chapter 6). When you want a newer library in an add-on, read `CHANGELOG-CLIENT.md` of the new client package for entries marked **Action**, export it again and ship an add-on release.

## Database migrations

- Tables are created and changed on activation and, after an update, by the first request in the admin, by WP-Cron or by WP-CLI. Front-end and API requests never migrate and never wait for a migration.
- A migration counts as done only when every expected column exists with the expected type. Otherwise the previous schema version stays stored, administrators see a notice with the technical reason, and the next attempt follows after five minutes.
- Once a day the structure is compared with the expected one; missing tables or columns are created again.
- `wp wplc schema status` prints the installed and expected version and every structure problem.

## Rolling back

Installing an older plugin version over a newer database is detected: nothing is migrated backwards, administrators see a notice, and billing is paused until the newer version is installed again. Roll back the plugin only together with the database backup that belongs to it, and never downgrade the schema by hand.

## Deactivating and deleting

- **Deactivate** stops the scheduled jobs. Tables, options and packages stay. While the plugin is off, nothing is processed and the API answers 404; add-ons fall back to their snapshot. On the next activation the gap is caught up (chapter 9).
- **Delete** additionally removes pending scheduled actions and the plugin's working state. It **keeps** the `{prefix}wplc_*` tables, the settings, the response signing key, the uploaded packages and all order meta, because they are business records. On a network this happens for every site.
- To remove tables and options as well, define `WPLC_REMOVE_ALL_DATA` as `true` in `wp-config.php` before deleting the plugin. Package files and order meta are never removed automatically. Without a backup of the signing key (option or constant), delivered add-ons can no longer verify a reinstalled server.
