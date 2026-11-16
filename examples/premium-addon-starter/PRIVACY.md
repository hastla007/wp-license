# Privacy: what Premium Add-on Starter sends to the license server

Premium Add-on Starter talks to exactly one server: the vendor's license server (WP License Captain) at the URL built into the add-on (`Config::SERVER_URL`). The free core plugin never contacts it. No other service is contacted for licensing or updates.

## When it connects

| When | Request |
| --- | --- |
| An administrator activates a license on **Settings → Premium Add-on Starter license** | `POST /wp-json/wplc/v1/activations` |
| Once a day (WP-Cron), on **Check now**, and right after activation | `POST /wp-json/wplc/v1/entitlements/check` |
| Once a day (WP-Cron) and when WordPress refreshes plugin updates, at most every 12 hours | `POST /wp-json/wplc/v1/updates/check` |
| When an update is installed | `POST …/updates/check` again, then `GET` of the one-time package URL |
| An administrator deactivates the license, or the add-on is deleted | `DELETE /wp-json/wplc/v1/activations/{id}` |

## What is sent

**Activation (once):**

- the license key as entered (the add-on stores only its SHA-256 hash and the last four characters),
- the product key (fixed per add-on, e.g. `voucher-captain-pro`),
- the site URL (`home_url()`),
- the environment chosen (production, staging, development) and, for staging, the installation ID of the live site,
- a random installation ID and a random installation secret created on this site,
- the add-on version, the WordPress version and the PHP version.

**Every later request:** the installation ID, a timestamp, a random nonce and an HMAC signature in HTTP headers; the add-on version (entitlement check), or the product key and the installed version (update check). The license key is not sent again.

The server additionally sees the IP address of the web server making the request, as with any HTTP request, and records when the installation last checked in.

## What is never sent

- orders, vouchers, tickets, passes, bookings, commissions or any other shop data,
- customer, partner or user data (names, e-mail addresses, addresses, payment data),
- content, settings or files of the core plugin or of the site,
- the installation secret after activation (it only signs requests),
- data about other plugins or themes.

## What is stored on this site

In the WordPress options table, not autoloaded: the installation ID, the installation secret, the activation ID, the SHA-256 of the license key (`premium_addon_starter_license`); the last signed answer of the server, the last check time and error, the environment and the last four characters of the key (`premium_addon_starter_license_state`); the last update check (`premium_addon_starter_update`). Deleting the add-on releases the activation at the server and removes these options. Data of the core plugin is never touched.

## Data processing at the vendor

The license server keeps the activation (installation ID, site URL, environment, versions, last check) for as long as the license exists, to enforce the number of sites and to deliver updates. Details are in the vendor's privacy policy; the shop owner's privacy notice should name the license server as an external service of the add-on.
