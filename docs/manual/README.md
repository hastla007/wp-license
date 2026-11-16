# WP License Captain – Vendor manual

This manual is for software vendors who run WP License Captain to sell licenses for their own premium WordPress plugins, deliver updates to licensed sites and renew licenses. It ships with the plugin (folder `docs/manual/` of the plugin ZIP) and describes the version it ships with.

> **Language.** This is the English edition. A German edition will follow.

Throughout the manual the examples use an invented vendor, **Acme Plugins**, that sells the add-on **Acme Reports Pro** (product key `acme-reports-pro`, entitlement keys `acme_reports.*`) from its shop `shop.example` with the license server host `licenses.example.com`. Replace these names with your own.

## Reading order

| Chapter | What it covers |
| --- | --- |
| [1. Introduction and requirements](01-introduction.md) | What the server does, how the parts fit together, what you need. |
| [2. Installation, secrets and keys](02-installation-and-keys.md) | Installing the plugin, the `wp-config.php` constants, key rings and the response signing key. |
| [3. Catalog files](03-catalog-files.md) | Telling the server which products and entitlements exist (`wplc-catalog.json`). |
| [4. License products and plans](04-license-products.md) | Turning WooCommerce products into license plans, the catalog CSV, licenses issued by hand. |
| [5. Releases and updates](05-releases-and-updates.md) | Uploading packages, package storage, nginx, how an update reaches a site. |
| [6. Integrating the client library](06-client-integration.md) | Embedding the client library in your add-on, the contract test and the API v1 promise. |
| [7. Customer account and emails](07-customer-account-and-emails.md) | What your customers see: account page, shortcodes and blocks, the twelve emails. |
| [8. Renewals and Stripe](08-renewals-and-stripe.md) | Manual and automatic renewal, grace period, price changes, the Stripe webhook. |
| [9. Operations](09-operations.md) | Backups and restore, key rotation, monitoring, caching, multisite, privacy. |
| [10. WP-CLI reference](10-wp-cli.md) | Every `wp wplc` command with its options. |
| [11. Troubleshooting and FAQ](11-troubleshooting.md) | Symptoms, causes and fixes. |
| [12. Upgrading](12-upgrading.md) | Updating the server plugin, migrations, rollback, uninstall. |

## The first hour, in short

1. Install WooCommerce and WP License Captain on a staging copy of your shop ([chapter 2](02-installation-and-keys.md)).
2. **Secrets:** put the secret constants into `wp-config.php` before the first license is issued.
3. **Public key:** generate the response signing key with `wp wplc signing-key --generate`, put it into `wp-config.php` as `WPLC_RESPONSE_SIGNING_KEY`, print the public key with `wp wplc signing-key --field=public_key` and pin it in your add-on ([chapter 6](06-client-integration.md)).
4. **Catalog file:** add `wplc-catalog.json` to your add-on and import it ([chapter 3](03-catalog-files.md)).
5. **License product:** turn a WooCommerce product into a license product with a plan ([chapter 4](04-license-products.md)).
6. **First release:** upload the add-on ZIP as a release and publish it ([chapter 5](05-releases-and-updates.md)).
7. **Test purchase:** buy the product as a test customer, activate the key on a test site and run the contract test.
8. **Stripe webhook:** connect it if you offer automatic renewal ([chapter 8](08-renewals-and-stripe.md)).

**WooCommerce → License settings** shows steps 2 to 8 as the seven-step "Getting started" checklist. It reads the state from the site on every visit (a key stored only in the database does not tick the public-key step; a draft release does not tick the release step), links each step to its screen and folds away when all seven are done.

## Conventions

- Menu paths such as **WooCommerce → License releases** refer to the WordPress admin of the shop.
- `{prefix}` in table names is the WordPress table prefix (`wp_` by default). In client hook names it is the option prefix of your add-on (see chapter 6).
- All dates and times of the API, the database and the CLI are UTC unless a screen says otherwise.
- Constants go into `wp-config.php` above the line that loads `wp-settings.php`, on every web server of the shop.
