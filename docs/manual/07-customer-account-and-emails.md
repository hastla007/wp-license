# 7. Customer account and emails

## The "Licenses" page in My Account

WP License Captain adds a page **Licenses** to the WooCommerce customer account (endpoint `wplc-licenses`, for example `https://shop.example/my-account/wplc-licenses/`; filter `wplc_account_endpoint_slug` changes the slug). For each license the customer sees:

- the license key with a copy button, the status, the plan and the end date;
- the sites in use, with a button to deactivate a site (its staging site goes with it);
- "Renew now" inside the manual renewal window (chapter 8);
- cancel or resume the automatic renewal;
- upgrade to a more expensive plan (prorated) or switch to a cheaper one at the next renewal;
- "Move to …" for the sites of an ending license when the customer owns a newer license of the same product;
- a download of the latest release (or one of up to nine older versions).

The key is shown only to the logged-in owner and only when it can be reproduced with the configured secret; otherwise the page says "Currently unavailable" (chapter 11). Customer forms post to the account page itself, so they also work on shops that lock `wp-admin` down.

The templates live in `templates/myaccount/` of the plugin; copy them to `wp-license/myaccount/` in your theme to change the markup.

## Shortcodes and blocks

| Shortcode | Block | Shows |
| --- | --- | --- |
| `[wplc_licenses]` | My licenses | The license list of the account page, anywhere on the site. |
| `[wplc_changelog product="acme-reports-pro" limit="10"]` | Changelog | Release notes of a product. |
| `[wplc_pricing product="acme-reports-pro" highlight="business" show_features="yes"]` | Pricing table | The plans of a product with price (shop tax display), term, live-site limit, features (labels from the catalog file) and a "Buy" button. Only published, purchasable products with an active plan are listed. |
| `[wplc_cancel]` | – | Public cancellation page (below). |

## Public cancellation page

Put `[wplc_cancel]` on a page to let customers cancel an automatic renewal **without logging in**: email address plus license reference (key prefix), full key or order number, then a confirmation email with a link. Only addresses of the current owner match (account email, license email recipient, billing email of the source order while that order belongs to the owner). Requests are limited to 5 per hour per client address, reference and (for order numbers) email address (filter `wplc_public_cancel_hourly_limit`). The page answers identically whether or not the details matched, so it reveals nothing about your customers. Many jurisdictions expect such a simple cancellation route; link it from your terms and from the renewal emails' footer text.

## "Renew instead" in the cart

A logged-in customer who puts a license product into the cart while owning a license of the same product that still grants access (or expired within the renewal window) sees a notice above cart and checkout that links to the renewal of that license. The purchase itself stays possible; a new purchase always issues a new license with a new key. Filter: `wplc_renew_instead_notice`.

## The twelve emails

All emails are sent through WooCommerce's mailer to the account email of the license owner and are managed under **WooCommerce → Settings → Emails**: on/off, subject, heading, additional text, preview. They are rendered in the customer's profile language when a translation is installed (English, `de_DE`, `de_DE_formal` ship with the plugin).

| Email | Sent when |
| --- | --- |
| License issued | A license was issued (purchase, manual license, new key). |
| Expiry reminder | 30 and 7 days before the end of a license without automatic renewal or with a scheduled cancellation (setting "Reminder lead days"; days longer than half the billing period are skipped). |
| Upcoming automatic renewal | Before an automatic charge (setting "Renewal notice lead days", default 7), with amount, date and how to cancel. **A license is only charged after this notice was sent** (chapter 8). |
| Renewal payment failed | An automatic charge was declined; the license is `past_due`. |
| Payment method missing | The saved card is gone; nothing else is charged. |
| License renewed | A renewal was booked. |
| License expired | The license expired. |
| Cancellation confirmed | The customer (or you) cancelled the automatic renewal. |
| License suspended | The license was suspended. |
| License revoked | The license was revoked. |
| New renewal price announced | A higher renewal price was announced with notice (chapter 8). |
| Announced renewal price withdrawn | The announcement was withdrawn after the customer had been notified. |

- **The full license key is not in the emails by default.** Email is not a secure channel; the email shows the key prefix and links to the account. You can opt in under **WooCommerce → Settings → Emails → License issued**.
- **Sender note:** a short text (for example a support contact) that the emails can show below their content, under **WooCommerce → License settings**.
- **Templates** live in `templates/emails/` (plain-text variants in `templates/emails/plain/`). Copy a template to `wp-license/emails/` (or `wp-license/emails/plain/`) in your theme to change its layout.
- Every reminder and status email is recorded before sending and sent once per license and period. Sending is best effort: a mail failure never blocks checkout, renewal or cron. Watch the WooCommerce log (source `wp-license`) for "was not accepted by the mailer".
- Disabling the "Upcoming automatic renewal" email holds back every automatic charge (unless you switch that rule off, chapter 8), and may conflict with consumer-protection rules.

**Before launch:** send every email once on staging, in HTML and plain text, in every language you sell in; check links, dates and amounts; use an authenticated mail transport (SPF, DKIM, DMARC) – a reminder that lands in spam is a lost renewal.

## Privacy policy text

The plugin suggests a section for **Settings → Privacy → Policy guide**: the license record, the technical data sent by licensed plugins, automatic renewal and the data sent to the payment provider, update downloads from an external package host, the history, export and erasure. It is a starting point; adapt it and have it checked. More in chapter 9.
