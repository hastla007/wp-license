# 1. Introduction and requirements

WP License Captain turns a WooCommerce shop into a license server for premium WordPress plugins. It sells licenses, tracks the sites a license is activated on, tells your add-on which features the customer has paid for, renews licenses and delivers updates to licensed sites.

## How the parts fit together

```
 Your shop (WordPress + WooCommerce + WP License Captain)
   ├─ WooCommerce products mapped to license plans   ← chapter 4
   ├─ Catalog of products and entitlements           ← chapter 3 (wplc-catalog.json)
   ├─ Releases (ZIP packages)                         ← chapter 5
   ├─ Customer account "Licenses", emails            ← chapter 7
   ├─ Renewals, Stripe webhook                        ← chapter 8
   └─ REST API v1  /wp-json/wplc/v1/…
            ▲  signed requests / signed answers
            │
 Customer site (WordPress)
   └─ Your premium add-on with the embedded client library   ← chapter 6
```

- **The server** is this plugin on your shop. It needs WooCommerce and sells through it.
- **The client** is a small PHP library that your add-on embeds. It activates the license key, stores the signed answer of the server, gates premium features and installs updates through the WordPress updater. You get it as a separate package, `license-client-<version>.zip`, next to the plugin ZIP. There is no separate client plugin: every add-on carries its own copy.
- **The free core plugin** of your product (if you have one, for example on WordPress.org) contains no license code at all. Only the paid add-on talks to the license server, and an expired license only switches add-on features off.

## Key terms

| Term | Meaning |
| --- | --- |
| Product key | Identifier of a licensed add-on, for example `acme-reports-pro`. It is the folder name of the add-on, its text domain, the key on the WooCommerce product and the key the client sends. Lowercase letters, digits and hyphens. |
| Plan | A WooCommerce product or variation mapped to a product key, a plan key (for example `single`, `business`), a billing interval, a live-site limit, a support level and entitlements. |
| Entitlement | A feature right, for example `acme_reports.pdf_export: true` or a numeric limit. Licenses store a snapshot of the entitlements of their plan. |
| License | Issued per purchased unit when an order is paid. Has a key such as `WPLC-…`, a status and a paid period (`paid_until`). |
| Activation (installation) | One site on which a license is activated. Each activation has its own installation ID and secret; later requests are signed with that secret. |
| Environment | `production` (counts against the live-site limit), `staging` (one free staging site per live site) or `development` (local addresses, capped separately). |
| Release | One version of an add-on package: uploaded ZIP or HTTPS URL with SHA-256, in the channel `stable` or `beta`. |
| Response signing key | The Ed25519 key the server signs its answers with. Your add-on pins its public key. |

## License states

| State | Access to features and updates |
| --- | --- |
| `pending` | No – the license is being issued. |
| `active` | Yes, until `paid_until`; with automatic renewal switched on also during the grace period after it. |
| `past_due` | Yes, during the grace period (14 days by default) while a renewal payment is retried. |
| `suspended` | No – blocked by the shop (for example after a payment dispute). Can be reactivated. |
| `expired` | No – the paid period and the grace period are over. A manual renewal within 30 days reactivates it. |
| `revoked` | No – ended for good (for example after a refund). |

Every status change is written to the audit log with user, time and reason.

## Requirements

**Server**

- WordPress 6.5 or later
- WooCommerce 8.9 or later, active on the same site
- PHP 7.4 or later with the extensions `openssl`, `sodium` (or the `sodium_compat` that WordPress ships) and `zip` (needed to inspect uploaded packages)
- MySQL or MariaDB with a single writable primary. Activations, renewals and the signing key serialize on named locks (`GET_LOCK`); connection proxies that multiplex sessions and multi-writer clusters (Galera, Group Replication) are not supported
- HTTPS on the standard port for the API and for package delivery
- A cron that really runs; a system cron that calls `wp-cron.php` is recommended. Action Scheduler (part of WooCommerce) is used when available
- For automatic renewals: the official WooCommerce Stripe gateway

**Customer sites (client library)**

- WordPress 6.0 or later, PHP 7.4 or later
- The PHP sodium extension, or the `sodium_compat` that WordPress ships, for the signature check
- Outgoing HTTPS to your license server

## What is in the plugin ZIP

| Path | Content |
| --- | --- |
| `wp-license.php`, `uninstall.php`, `readme.txt` | Plugin main file, uninstall routine, WordPress readme |
| `src/` | The server code and, in `src/Client/`, the client library it uses for its own update check |
| `vendor/` | The generated autoloader |
| `templates/` | Customer account and email templates (overridable in your theme) |
| `assets/`, `languages/` | Scripts, styles, English and German translations (`de_DE`, `de_DE_formal`) |
| `mu-plugins/` | Optional must-use helper for multisite networks (chapter 9) |
| `catalogs/` | Catalog files imported once on installation (chapter 3) |
| `docs/manual/` | This manual |

The client library for your add-ons is published as its own archive, `license-client-<version>.zip`, with a `.sha256` file (chapter 6).

## Before you go live

These things depend on your shop and cannot be checked by the plugin. Go through them on a staging copy:

- Secrets defined and backed up together with the database (chapter 2 and 9).
- A full purchase, activation, update and renewal with a test customer, including the emails in HTML and plain text in every language you sell in.
- Automatic renewal and the Stripe webhook tested against your Stripe test account (chapter 8).
- A backup restored to a separate environment, with the matching secrets.
- Your privacy notice, terms (renewal amount and interval, cancellation, grace period, refunds) and the withdrawal-right wording at checkout reviewed for your jurisdiction.
- Page caches, CDN and firewall configured so the API and the account pages reach PHP unchanged (chapter 9).
