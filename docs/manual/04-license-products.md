# 4. License products and plans in WooCommerce

A **plan** is a WooCommerce product (or a variation) that issues a license when it is paid. You sell plans with the normal WooCommerce tools: prices, taxes, coupons, payment methods and order emails stay WooCommerce's job.

## Turning a product into a license product

1. Import the catalog file of the add-on first (chapter 3), so that the product editor knows its product key and entitlements.
2. Create or edit a simple or variable WooCommerce product, ideally virtual. For a variable product, every variation can be its own plan (for example Single, Business, Agency).
3. In the product data, tick **License product** ("Issue or renew a WP License Captain license when this product is paid") and fill in:

   | Field | Meaning |
   | --- | --- |
   | License product key | The product key of the add-on, for example `acme-reports-pro`. The editor suggests the keys of the catalog. |
   | License product name | Name of the license product as customers see it in their account and in emails, shared by all plans with the same product key. Empty keeps the current name. |
   | License plan key | For example `single`, `business`, `agency`: 1 to 100 lowercase letters, digits and hyphens. Each plan of one product needs its own key; keep it unchanged once the plan is sold. Your add-on must never authorize by plan key. |
   | License billing interval | Monthly or Yearly. |
   | Live site limit | Number of production sites (1 to 1000). Staging and development sites do not use it. |
   | License support level | Community, Standard or Priority. Sent to the client as `support.level`. |
   | Entitlements | Tick boxes with the available entitlements of the product's catalog group. |
   | Advanced: additional entitlements (JSON) | Optional JSON object for numeric limits and keys that are not in the list, for example `{"acme_reports.max_dashboards": 10}`. |
   | Rules of this plan | Optional: staging sites per live site (0 to 10), development sites (0 to 100, exactly this number), offline period in days (1 to 90), largest quantity per order line (0 to 100000), co-users per license (0 to 100). **Empty = the global setting** (chapter 13); the field shows it as a placeholder. A value applies to the existing licenses of the plan at once. The filters of the global values still decide last. Beta access per plan is not available yet. |

4. Save. The plan is active as long as the product exists (also while it is private); moving the product to the trash deactivates it.

Only users with `wplc_manage_catalog` see and save these fields; being allowed to edit products is not enough. The block-based product editor is not supported; use the classic product editor.

**What the client receives.** A ticked entitlement becomes `"acme_reports.pdf_export": true` in the signed answer. Two keys are part of every plan: `sites.live_limit` (number) and `support.level` (string). The answer is empty whenever the license grants no access.

**Products that cannot be license products:** WooCommerce Subscriptions products. WP License Captain renews licenses itself; mapping a subscription product is refused ("WooCommerce Subscriptions products cannot be license products"), and renewal orders created by WooCommerce Subscriptions never issue a license.

## How a license is issued

- When an order is paid, the plugin issues **one license per purchased unit** (quantity 3 = three licenses), idempotently per order item. A license purchase requires a customer account; guest checkout is switched off for carts with a license product.
- The customer gets the email "License issued" and sees the key under **My Account → Licenses** (chapter 7).
- A refund of the purchase revokes the license; a payment dispute suspends it (chapter 8).
- The order screen shows a box "Licenses" with the licenses issued for the order.
- **Largest quantity.** A customer can order at most 100 units of a license product in one order line (License settings → Rules of this shop → Largest quantity per order line; `0` = no limit). The quantity field, the cart and the checkout enforce it, in the classic checkout and in the block checkout. Order of precedence: the filter `wplc_max_license_quantity_per_line` (it receives the product, so your code can set a limit per product) wins over the setting. An order you create yourself in the admin or through the REST API is never blocked: every unit gets its license, and the order gets a note and an entry in the audit log (`order_quantity_above_limit`).

**Withdrawal right at checkout.** For carts with a license product the checkout asks for consent to the immediate start of the license and to the loss of the EU withdrawal right for digital content. It is required by default, recorded on the order (time, wording, language) and repeated in the customer's order emails. When the legal-text plugins Germanized or German Market are active, the plugin does not ask itself; enable their own checkbox for digital products there. Filters: `wplc_withdrawal_consent_enabled`, `wplc_withdrawal_consent_required` (`false` makes it optional), `wplc_withdrawal_consent_text` (the shipped wording is a technical proposal; have yours reviewed).

## Changing a plan that licenses already use

Saving a plan changes what **new** licenses get. Existing licenses keep the snapshot of their purchase or last plan change, also when they renew: a customer's rights never change behind their back. To give existing licenses a feature or a higher limit that their plan gained, run the opt-in sync:

```sh
wp wplc sync-entitlements --product=acme-reports-pro --plan=business          # dry run: lists every license that differs
wp wplc sync-entitlements --product=acme-reports-pro --plan=business --apply  # writes the changes
```

Only `active` and `past_due` licenses are changed. A change that takes something away (a removed feature, a lower number, a lower support level) is skipped unless `--allow-reductions` is given. Clients see the change at their next entitlement check, without re-activation. Each change is audited as `license_entitlements_synced`.

## Plan changes by the customer and by the shop

A license can move to another plan of the same product and the same billing interval. Key, paid period and site activations stay.

- **Upgrade** (customer, in the account): a plan whose current price is higher than what the customer pays now. The customer pays the prorated difference for the rest of the paid period; the new entitlements apply as soon as the order is paid. When automatic renewal is on, the form asks for consent to the new automatic amount.
- **Downgrade** (customer, in the account): a cheaper plan, applied with the next renewal. Nothing is refunded.
- **Administrator change** (license detail → Change plan): "Now" or "With the next renewal", reason required, no order and no charge. The automatic amount is never raised without the customer's consent.
- A lower live-site limit never deactivates a site; new activations are refused until enough sites are released.

## Catalog CSV: many plans at once

**WooCommerce → License catalog CSV** exports the current plans and imports a file of plan mappings (capability `wplc_manage_catalog`). The CSV maps products to plans; which entitlement keys exist is still defined by the catalog files.

Columns, in this order:

```csv
product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json
120,121,ARP-SINGLE,acme-reports-pro,single,year,1,standard,"{""acme_reports.pdf_export"":true}"
120,122,ARP-BUSINESS,acme-reports-pro,business,year,5,standard,"{""acme_reports.pdf_export"":true}"
120,123,ARP-AGENCY,acme-reports-pro,agency,year,25,priority,"{""acme_reports.pdf_export"":true}"
```

| Column | Rule |
| --- | --- |
| `product_id`, `variation_id` | Product ID (for a variation: the parent) and variation ID (`0` for a simple product). |
| `sku` | Must equal the product's SKU in this shop, so a file from another shop cannot change the wrong products. |
| `product_key`, `plan_key` | As in the product editor. Keys are validated, never rewritten. |
| `billing_interval` | `month` or `year`. |
| `live_site_limit` | 1 to 1000. |
| `support_level` | `community`, `standard` or `priority`. |
| `entitlements_json` | JSON object with simple values, at most 4096 characters, or empty. |

- At most 1,000 rows and 2 MB per file; comma- and semicolon-separated files are accepted, with or without byte order mark (Excel works).
- Every rejected row is listed with its line number and reason. A product another user has open in the editor is skipped.
- Prices come from WooCommerce; the CSV cannot set them. Importing the same file twice is harmless.
- Imports are audited (`catalog_csv_imported`, plus one entry per changed plan).

## Licenses without an order

**WooCommerce → Licenses → Add license manually** (capability `wplc_grant_licenses`): an existing user account (ID or email), an active plan, an end date and a reason. No order is created and nothing is charged; the license does not renew automatically, but the customer can renew it in the account inside the renewal window. The "License issued" email is sent unless you untick it. The same on the command line:

```sh
wp wplc license issue --product-id=812 --customer=client@example.com --expires=2027-12-31 --reason="Migrated customer"
```

**Project products** for a single client work the same way: create a simple, virtual product with visibility **Private**, tick "License product" with the project's own product key and plan (the price may stay 0), and issue the license by hand. `wp wplc project-product save` does the same from the command line (chapter 10). Private products are not in the pricing table and cannot be bought by customers; expiry reminders for them ask the client to contact you instead of offering "Renew now". The customer account offers no "Renew now" for them; you extend such licenses with "Change term" (for example by one year at a time). To let clients renew them at the plan price instead, add `add_filter( 'wplc_manual_renewal_for_unsold_products', '__return_true' );` to a small must-use plugin. Products that are for sale are not affected.

## Day-to-day license administration

**WooCommerce → Licenses** lists all licenses with filters (status, product, renewal state, renewal mode, expiry, source order), reports (automatic renewals in the next 30 days, ending without automatic renewal, payment overdue, cancelled to the end of the term) and a CSV export. The search accepts a complete license key (compared by hash, never shown in the address bar), `#123` for a license ID, order and customer IDs, email addresses, license UUIDs, installation IDs, product and plan keys and parts of site addresses.

The license detail screen shows customer, plan, status, dates, activations, renewals, entitlements and the latest audit entries. Actions (each needs a reason and is audited):

| Action | Effect |
| --- | --- |
| Suspend / Reactivate | Stops access until reactivated; reactivation only while the paid period runs. |
| Revoke | Ends the license for good; only an administrator can restore it. |
| Change term | New end date, no payment taken; an expired license becomes active again. |
| Restore with a new end date | Brings back a suspended license with ended term, or (administrators) a revoked one. |
| Issue new key | The old key stops working for new activations; activated sites keep working unless you release them too. |
| Send key email again | Re-sends the license email. |
| Change owner | Hands the license to another account, by default with a new key; automatic renewal is switched off; co-users and open invitations are removed. |
| Remove a co-user | Below the site activations: the co-users, open invitations and an open transfer started by the customer (chapter 7). |
| Change plan | See above. |
| Cancel / withdraw cancellation of automatic renewal | As the customer can in the account. |
| Change renewal amount | Lowers the amount of future renewals. Raising it needs an announced price change (chapter 8). |
| Release a site activation | Frees one site; its staging site is released with it. |

Bulk actions on the list (up to 100 licenses): suspend, reactivate, revoke, release all site activations, extend the term by a number of days. **Issue missing licenses for an order** (below the list) creates the licenses of a paid order that has none, for example when the product was mapped after the sale.

Every action that changes a license, an activation, a release or the settings is written to **WooCommerce → License audit log** with the acting user, the source (admin, customer, cli, cron, webhook, rest), the reason and the values before and after. License keys, email addresses, tokens and secrets are redacted when written.
