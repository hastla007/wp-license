# Entitlements

Entitlements are the feature rights a license grants. The server stores them per license and sends them, signed, in the `entitlements` object of an activation and of every entitlement check ([API-V1.md](API-V1.md)). A premium add-on asks `LicenseManager::is_entitled( 'pass.card_terminal' )` ([CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md)).

## Core plugins are free and ungated

Decision of 13 Nov 2026: the core plugin of each product stays completely free, with no license check and no locked features, so it can be published on WordPress.org. Consequences:

- Core features have **no** entitlement keys. Nothing in a core plugin asks the license server for anything.
- Entitlement keys exist only for features that ship in the separate premium add-on.
- An expired or missing license can only switch add-on features off. It never changes what the core plugin does, and it never removes or hides data the customer already created (vouchers sold, tickets issued, partners, reports).

## What the server delivers

`entitlements` is a JSON object: key → value. It is **empty whenever the license grants no access** (`access: false`: expired after the grace period, suspended, revoked, pending, released installation).

| Value | Example | How a client reads it |
| --- | --- | --- |
| `true` | `"pass.card_terminal": true` | The feature is granted. This is what a ticked feature of a plan becomes. |
| number | `"sites.live_limit": 3` | A limit. `is_entitled()` is true for a number above zero; read the number with `status()->entitlement( $key )`. |
| string | `"support.level": "standard"` | Information. `is_entitled()` is false for a string; read it with `entitlement()`. |
| `false`, `0`, `null`, absent key | | Not granted. |

Two keys are part of every plan and are not feature gates:

- `sites.live_limit` (number): live-site limit of the plan. The limit that is enforced is `site_limit` of the signed data; do not count sites in the add-on.
- `support.level` (string): support level of the plan.

Values that cannot be encoded as JSON (a number that is too large) are refused when a plan is saved and left out of the answer.

A client must **fail closed**: an absent key, an unexpected type or data without `access: true` means "not granted". It must authorize by key, never by the label of a feature or by the name of a plan.

## How long a client may rely on them: the offline window

A client may keep the last signed answer and use it while the server cannot be reached. That snapshot is valid until its signed **`cache_expires_at`**: 14 days after the answer (`server_time`), but never later than `access_until`, the time at which the license stops granting access if nothing changes. This is the only definition of the "maximum offline window"; there is no separate setting on the client.

- The deadline is taken from the signed data of the stored snapshot. It is not restarted by later, failed requests.
- A client must not cut it down to `paid_until` on its own: during the renewal grace period `access_until` is later than `paid_until`, and an honest customer would be locked out.
- After the deadline the client treats the license as without access until it receives a new signed answer.
- Only a signed answer may take rights away before the deadline.

The shop can change the 14 days with the filter `wplc_offline_cache_days`.

## Dates

All date-time values of the API (`paid_until`, `access_until`, `grace_until`, `server_time`, `cache_expires_at`, `deactivated_at`) are **UTC** in the form `YYYY-MM-DD HH:MM:SS`, without a time zone suffix. Parse them as UTC (`strtotime( $value . ' UTC' )`) and convert for display. `paid_until`, `access_until` and `grace_until` can be `null`.

## Product keys (decided 14 Nov 2026, T-158)

The product key on a license product in the shop and the key the premium add-on sends must be identical. Key = add-on folder = text domain; the add-ons live in hastla007/captain-pro. Each key maps to one entitlement prefix. Products, prefixes, keys and labels are not part of the server code: they come from the catalog file each add-on ships (`wplc-catalog.json`, [CATALOG-FILE.md](CATALOG-FILE.md)); the table below is what the catalog files of the Captain products contain (`catalogs/` in this repository until captain-pro delivers them). Further products are added by importing their file; the filter `wplc_known_product_keys` still works.

| Product | Product key | Entitlement prefix | Premium add-on |
|---|---|---|---|
| Voucher Captain | `voucher-captain-pro` | `voucher.` | `voucher-captain-pro` |
| Pass Captain | `pass-captain-pro` | `pass.` | `pass-captain-pro` |
| Affiliate Captain | `affiliate-captain-pro` | `affiliate.` | `affiliate-captain-pro` |

The product editor suggests these keys. The plan key (`single`, `business` or `agency`, see [Plans and entitlements](#plans-and-entitlements)) is set per product or variation and is independent of the product key.

Planned add-ons, not released and not for sale yet (status `planned` in their catalog file): `workflow-captain-pro` (`workflow.`), `form-captain-pro` (`form.`), `review-captain-pro` (`review.`), `course-captain-pro` (`course.`).

## Identifier rules

- Prefix keys by product: `affiliate.`, `voucher.`, or `pass.` (planned: `workflow.`, `form.`, `review.`, `course.`). One prefix belongs to one product.
- Use lowercase snake_case after the prefix; never reuse a key for different behavior.
- Server plans return a boolean or a documented numeric limit. Clients fail closed for new premium features when an entitlement is absent or invalid.
- Plan key/SKU assignment is a separate configuration step; the tables below are the decided model, not live catalog data.
- Site counts are license limits, not feature entitlements. Staging does not consume another live site.
- The keys the shop offers as tick boxes in the product editor come from the imported catalog files (`WPLC\Catalog\CatalogStore`, read by `WPLC\Catalog\EntitlementCatalog`; filter `wplc_entitlement_catalog`). A key is offered when its product and the key itself have the status `available`. Further keys and numeric limits can be entered as JSON.
- A license stores a snapshot of its plan's entitlements. A plan change (upgrade, downgrade at the next renewal, administrator change) replaces the whole snapshot with the new plan's entitlements; clients see the change at their next entitlement check, without re-activation. A lower live site limit deactivates no site, it only blocks new activations (see [RENEWALS.md](RENEWALS.md#plan-changes)).

## Changing a plan that licenses already use (A-23)

Saving a plan in the product editor (or the catalog CSV import) changes what **new** licenses get. Existing licenses keep the snapshot from their purchase or last plan change, also when they renew; a customer's rights never change behind their back. To give existing licenses a feature or a higher site limit that their plan gained, run the opt-in sync:

```
wp wplc sync-entitlements --product=voucher-captain-pro --plan=business          # dry run: lists every license that differs
wp wplc sync-entitlements --product=voucher-captain-pro --plan=business --apply  # writes the changes
```

- The plan of a license is the catalog mapping of the product it renews with (its product key, plan key and billing interval must still match); licenses without one are reported as "no current plan" and left alone. Only `active` and `past_due` licenses are changed.
- The whole snapshot is replaced, the same way as for a plan change. A change that takes something away (a removed or switched-off feature, a lower number, a lower support level, any changed text value) is skipped and reported unless `--allow-reductions` is given. Check your terms before taking rights away from paying customers.
- `--license=<id>` syncs one license; `--max-rows` and `--after-id` split large runs. Without `--apply` nothing is written. The command ends with exit code 1 while licenses remain that differ from their plan.
- Each change is audited as `license_entitlements_synced` (before and after values of the changed keys) and fires `wplc/license_entitlements_synced`. Clients see it at their next entitlement check.

## Plans and entitlements

Decided on 14 Nov 2026 (captain-pro `docs/TARIFE.md`, `docs/PREMIUM-ENTSCHEIDUNGEN.md`): **every product has the same three plans, and every plan of a product contains every premium function of its add-on.** Plans differ only by the number of live sites and the support level.

| Plan key | Name | Live sites (`sites.live_limit`) | Support (`support.level`) |
|---|---|---:|---|
| `single` | Single | 1 | `standard` |
| `business` | Business | 5 | `standard` |
| `agency` | Agency | 25 | `priority` |

Prices are not part of this document. The prices proposed in captain-pro `docs/TARIFE.md` are not confirmed yet (see "Offene Entscheidungen" in [TODO.md](TODO.md), T-42). Confirmed by the owner on 16 Nov 2026 are only the prices of the planned Form Captain Pro: 59, 129 and 249 EUR per year net, plus VAT, for `single`, `business` and `agency` (owner decision of 16 Nov 2026, corrected from gross to net).

The keys below are the ones the product editor offers as tick boxes (`WPLC\Catalog\EntitlementCatalog::all()`, filled from the catalog files). A key has the status `available` only when the premium add-on ships the function; the core plugins have no keys. The labels (English, `de_DE`, `de_DE_formal`) are in the catalog files, not in the translations of the server.

| Product (product key) | Keys in every plan (`single`, `business`, `agency`) | State of the add-on (captain-pro, 16 Nov 2026) |
|---|---|---|
| Affiliate Captain Pro (`affiliate-captain-pro`) | `affiliate.extended_api`, `affiliate.automated_payouts`, `affiliate.white_label_portal`, `affiliate.webhooks` | Built. |
| Pass Captain Pro (`pass-captain-pro`) | `pass.e_invoicing`, `pass.card_terminal`, `pass.waiting_room`, `pass.write_api`, `pass.white_label` | Built. `pass.e_invoicing` delivers XRechnung 3.0 XML (UBL and CII) and, since captain-pro `e54eda9` (16 Nov 2026), a ZUGFeRD PDF/A-3 with the CII XML embedded; the external validations of the PDF are still open there (captain-pro `docs/PASS-E-INVOICE.md`). |
| Voucher Captain Pro (`voucher-captain-pro`) | none yet | No premium function is built; the add-on is a skeleton. Nothing can be sold for it yet. |
| Workflow Captain Pro (`workflow-captain-pro`), Form Captain Pro (`form-captain-pro`), Review Captain Pro (`review-captain-pro`), Course Captain Pro (`course-captain-pro`) | none yet | Planned (product status `planned` in the catalog file). The product keys resolve to the prefixes `workflow.`, `form.`, `review.`, `course.`; the product editor does not suggest them and offers no key for them. Six `workflow.*` keys are reserved (next section). |

Support is the plan attribute `support.level` (`standard` or `priority`) and nothing else (owner decision of 16 Nov 2026, R-24). The three former keys `*.priority_support` (one each for `affiliate.`, `voucher.` and `pass.`), which no add-on checked, are no longer in the catalog. A plan or license that still stores one keeps it in the advanced JSON field; it has no effect.

Plan keys and product keys are assigned in the product editor or by the catalog CSV ([CATALOG-CSV.md](CATALOG-CSV.md)). The server does not enforce the three plan keys: a shop can name plans differently, and a custom key is only a label. Clients must authorize by entitlement key, never by plan key.

## Planned, not deliverable yet

These keys are reserved for functions that are planned but that no released add-on checks. They are **not** offered in the product editor and not shown in the pricing table (status `planned` in the catalog file, `EntitlementCatalog::planned()`), so that a shop does not sell what does not exist. A shop that ships such a function itself can offer them with `add_filter( 'wplc_offer_planned_entitlements', '__return_true' );`.

| Key | Planned function |
|---|---|
| `affiliate.store_credit` | Payout as store credit |
| `affiliate.stripe_connect` | Payouts via Stripe Connect |
| `affiliate.leaderboard` | Leaderboard and milestone emails |
| `affiliate.multi_tier` | Multi-tier commissions |
| `affiliate.multiple_programs` | Multiple programs |
| `voucher.white_label` | White label for Voucher Captain Pro (not described yet) |
| `workflow.suite_integrations` | Workflow Captain Pro: integrations with the other Captain plugins |
| `workflow.webhooks` | Workflow Captain Pro: webhooks |
| `workflow.event_correlation` | Workflow Captain Pro: event correlation |
| `workflow.advanced_logic` | Workflow Captain Pro: advanced logic |
| `workflow.recipe_templates` | Workflow Captain Pro: recipe templates |
| `workflow.extended_logs` | Workflow Captain Pro: extended logs |

The six `workflow.*` keys are boolean and belong to every plan (`single`, `business`, `agency`) once the add-on is released; captain-pro uses the same names (its TODO, WFC-PRO-03). No Workflow add-on exists yet, and `workflow-captain-pro` is a planned product key that the product editor does not suggest.

## Contract examples

Signed data of a license with access:

```json
{
  "access": true,
  "license_status": "active",
  "entitlements": {
    "pass.e_invoicing": true,
    "pass.card_terminal": true,
    "sites.live_limit": 5,
    "support.level": "standard"
  },
  "server_time": "2026-11-12 00:00:00",
  "cache_expires_at": "2026-11-26 00:00:00"
}
```

The same license after its paid period and grace period:

```json
{
  "access": false,
  "license_status": "expired",
  "entitlements": {},
  "access_until": null
}
```

Clients may cache only a verified signed entitlement response under the separately specified maximum offline window. Entitlements must not disable existing customer data or already purchased vouchers/tickets when a license expires.

## Removed keys

Removed on 14 Nov 2026 by decision of the owner. Licenses that stored one of them keep the value (the product editor keeps unknown keys as JSON); nothing checks them any more.

| Key | Reason | Where the function lives now |
|---|---|---|
| `voucher.central_license_overview` | An overview of a customer's sites is a function of the customer account, not of the plugin on each site. | Customer account → **Sites** (`WPLC\Account\SiteOverview`), for every customer, all products. |
| `pass.client_license_management` | Same as above. | Customer account → **Sites**. |
| `pass.team_assignment` | Ships in the free core: seven staff roles and per-event check-in staff. | Pass Captain (core). |
| `affiliate.bonuses` | Ships in the free core: monthly tier bonuses (marginal or retroactive). | Affiliate Captain (core). |
| `pass.woocommerce_sales`, `pass.membership_passes`, `pass.campaign_codes`, `pass.waitlist`, `pass.csv_export`, `pass.seating`, `pass.service_slots`, `pass.pos`, `pass.offline_checkin`, `pass.advanced_reports`, `pass.api_webhooks` | Pass Pro was re-cut on 14 Nov 2026 (captain-pro `docs/PASS-PRO.md`): these functions ship in the free Pass core; Pass Pro sells e-invoicing, card terminal, white label, waiting room and write API, in every plan (plans differ by sites). | Pass Captain (core). |
| `affiliate.partner_portal`, `affiliate.coupon_attribution`, `affiliate.order_refund_handling`, `affiliate.basic_commissions`, `affiliate.manual_payout_csv`, `affiliate.partner_groups`, `affiliate.product_category_rates`, `affiliate.recurring_commissions` | Removed on 16 Nov 2026 (R-07): each names a function of the free Affiliate Captain core (checked against its code: partner portal, partner codes and coupon link, refund corrections in the ledger, commission rules by product, category, tag and SKU, payout runs with SEPA and CSV export, partner groups, lifetime and subscription commissions). A core function is never license-gated. | Affiliate Captain (core). |
| `voucher.custom_branding`, `voucher.pdf_templates`, `voucher.automatic_delivery`, `voucher.qr_redemption`, `voucher.partial_balance`, `voucher.scheduled_delivery`, `voucher.bulk_import_export`, `voucher.reports` | Removed on 16 Nov 2026 (R-07): each names a function of the free Voucher Captain core (visual PDF designer with own templates, delivery at a chosen order status and scheduled delivery, QR scanner, balance-tracked gift cards, code import from CSV/XLS/XLSX/ODS, reports). | Voucher Captain (core). |
| `voucher.staff_roles` | Removed on 16 Nov 2026 (R-08): the re-cut of Voucher Pro of 14 Nov 2026 moves the cashier role and locations into the free core (captain-pro `docs/PREMIUM-ENTSCHEIDUNGEN.md`, row "Voucher Pro neu zugeschnitten", and `docs/VOUCHER-PRO.md`); no add-on builds it. | Voucher Captain (core, from 1.2.0). |
