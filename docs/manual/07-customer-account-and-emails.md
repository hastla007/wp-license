# 7. Customer account and emails

## The "Licenses" page in My Account

WP License Captain adds a page **Licenses** to the WooCommerce customer account (endpoint `wplc-licenses`, for example `https://shop.example/my-account/wplc-licenses/`; the address, the name in the menu and the number of licenses per page are settings under **License settings → Customer account**, chapter 13, and the filter `wplc_account_endpoint_slug` decides last). For each license the customer sees:

- the license key with a copy button, the status, the plan and the end date;
- the sites in use, with a button to deactivate a site (its staging site goes with it);
- "Renew now" inside the manual renewal window (chapter 8);
- cancel or resume the automatic renewal;
- upgrade to a more expensive plan (prorated by day) or switch to a cheaper one at the next renewal; each of the two offers can be switched off under License settings → Rules of this shop ("Customers can upgrade their plan", "Customers can switch to a cheaper plan", both on by default; switched off, the offer is not shown and a request is refused; administrators can always change the plan);
- "Move to …" for the sites of an ending license when the customer owns a newer license of the same product;
- a download of the latest release (or one of up to nine older versions);
- **Co-users** and **Transfer** (below).

The key is shown only to the logged-in owner (and the license's co-users) and only when it can be reproduced with the configured secret; otherwise the page says "Currently unavailable" (chapter 11). Customer forms post to the account page itself, so they also work on shops that lock `wp-admin` down.

The templates live in `templates/myaccount/` of the plugin; copy them to `wp-license/myaccount/` in your theme to change the markup.

## Co-users and transfers

The owner of a license can share it and hand it over from the account page; you do not need to do anything.

**Co-users.** Under "Co-users" the owner enters an email address and sends an invitation. The invited person receives the email "License co-user invitation" with a link. To accept, they open the link while signed in with an account whose email address is the invited address (a new customer registers with that address first), and press "Accept the invitation". A co-user then sees the license in their own account under **Licenses** (and its sites under **Sites**): status, plan, end date, key, sites, downloads, and they can deactivate sites. They cannot renew, pay, cancel, change the plan or the payment method, invite others or transfer the license; prices and renewal terms are not shown to them. The owner sees the co-users and open invitations and can remove a co-user or withdraw an invitation; a co-user can leave with "Leave license".

- At most 5 co-users per license, counting open invitations (setting **Co-users per license** under License settings → Co-users and transfer, also per plan; filter `wplc_license_member_limit`). 0 switches co-users off: nobody can be invited and open invitations can no longer be accepted.
- An invitation link works once and expires after 14 days (setting **Invitations valid for**, filter `wplc_license_invitation_days`). Only a hash of the link's token is stored. Opening the link changes nothing; only the button accepts. A link opened in an account with another email address is refused and stays valid for the right account.

**Transfer.** Under "Transfer" the owner enters the recipient's address and confirms that automatic renewal ends. The recipient receives the email "License transfer offered" and accepts it in their own account, under the same rules (matching email address, single use, validity of the invitation). Until then the license stays with the owner, who can cancel the offer. When the recipient accepts:

- the license, its key and its sites belong to the recipient; the key stays the same by default and activated sites keep working (the setting **New license key on transfer** or the filter `wplc_license_transfer_new_key` issues a new one);
- automatic renewal is switched off and the stored payment method and payment provider references are removed: the recipient renews manually or switches automatic renewal on with their own payment method;
- co-users and open invitations are removed;
- both sides receive the email "License transferred";
- the license history records the start of the offer, the change of owner (as the same entry the admin action "Change owner" writes, with the reason "transferred by the previous owner") and the removal of co-users.

While a renewal payment of the license is being processed, accepting is refused for a few minutes. The admin action **Change owner** (chapter 4) also removes co-users and open offers. On the license detail screen you see the co-users, open invitations and an open transfer, and can remove a co-user (with a reason). The setting **Customers can transfer licenses** switches the transfer off for the whole shop (the form is hidden and open offers can no longer be accepted); the filter `wplc_license_transfer_enabled` decides last, also per license.

If you copied `myaccount/wplc-license.php` into your theme before this version, add the line `do_action( 'wplc_account_license_sharing', $wplc_license, $view );` before the closing `</section>` to show these forms.

## Shortcodes and blocks

| Shortcode | Block | Shows |
| --- | --- | --- |
| `[wplc_licenses]` | My licenses | The license list of the account page, anywhere on the site. |
| `[wplc_changelog product="acme-reports-pro" limit="10"]` | Changelog | Release notes of a product. |
| `[wplc_pricing product="acme-reports-pro" highlight="business" show_features="yes"]` | Pricing table | The plans of a product with price (shop tax display), term, live-site limit, features (labels from the catalog file) and a "Buy" button. Only published, purchasable products with an active plan are listed. |
| `[wplc_cancel]` | – | Public cancellation page (below). |

## Public cancellation page

Put `[wplc_cancel]` on a page to let customers cancel an automatic renewal **without logging in**: email address plus license reference (`WPLC-` and eight characters, shown in the account under each license), full key (34 characters, or 106 for a key issued before 24 Nov 2026) or order number, then a confirmation email with a link. Only addresses of the current owner match (account email, license email recipient, billing email of the source order while that order belongs to the owner). Requests are limited to 5 per hour per client address, reference and (for order numbers) email address (setting **Cancellation page: requests per hour**, filter `wplc_public_cancel_hourly_limit`). The page answers identically whether or not the details matched, so it reveals nothing about your customers. Many jurisdictions expect such a simple cancellation route; link it from your terms and from the renewal emails' footer text.

## "Renew instead" in the cart

A logged-in customer who puts a license product into the cart while owning a license of the same product that still grants access (or expired within the renewal window) sees a notice above cart and checkout that links to the renewal of that license. The purchase itself stays possible; a new purchase always issues a new license with a new key. Filter: `wplc_renew_instead_notice`.

## The fifteen emails

All emails are sent through WooCommerce's mailer to the account email of the license owner (the co-user and transfer emails to the address the owner entered, and "License transferred" to both sides) and are managed under **WooCommerce → Settings → Emails**: on/off, subject, heading, additional text, preview. They are rendered in the customer's profile language when a translation is installed (English, `de_DE`, `de_DE_formal` ship with the plugin).

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
| License co-user invitation | The owner invited a co-user; contains the single-use accept link. |
| License transfer offered | The owner offered the license to another customer; contains the single-use accept link. |
| License transferred | The recipient accepted a transfer; sent to the previous and the new owner. |

- The co-user and transfer emails never contain the license key.
- **The full license key is not in the emails by default.** Email is not a secure channel; the email shows the key prefix and links to the account. You can opt in under **WooCommerce → Settings → Emails → License issued**.
- **Sender note:** a short text (for example a support contact) that the emails can show below their content, under **WooCommerce → License settings**.
- **Support contact:** the address the emails, the customer account and the cancellation page name for questions is the setting **Support email address** (empty = the sender address of the WooCommerce emails); **Support page** adds a link in the customer account and on the cancellation page (chapter 13).
- **Templates** live in `templates/emails/` (plain-text variants in `templates/emails/plain/`). Copy a template to `wp-license/emails/` (or `wp-license/emails/plain/`) in your theme to change its layout.
- Every reminder and status email is recorded before sending and sent once per license and period. Sending is best effort: a mail failure never blocks checkout, renewal or cron. Watch the WooCommerce log (source `wp-license`) for "was not accepted by the mailer".
- Disabling the "Upcoming automatic renewal" email holds back every automatic charge (unless you switch that rule off, chapter 8), and may conflict with consumer-protection rules. While licenses renew automatically, license managers then see an error notice in the admin, the diagnostics page shows "Automatic renewals blocked" and the health alert `renewal_notice_disabled` fires.

**Before launch:** send every email once on staging, in HTML and plain text, in every language you sell in; check links, dates and amounts; use an authenticated mail transport (SPF, DKIM, DMARC) – a reminder that lands in spam is a lost renewal.

## Privacy policy text

The plugin suggests a section for **Settings → Privacy → Policy guide**: the license record, the technical data sent by licensed plugins, automatic renewal and the data sent to the payment provider, update downloads from an external package host, the history, export and erasure. It is a starting point; adapt it and have it checked. More in chapter 9.
