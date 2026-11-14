# WooCommerce license catalog CSV

Users who may manage the license catalog can export the current plans and import a file from **WooCommerce → License catalog CSV**.

## Who may use it

The screen, the export and the import need the catalog capability: `manage_woocommerce` by default, changeable with the filter `wplc_capability` (second argument `manage_catalog`). The same capability is needed to see and save the license fields in the product editor. Being allowed to edit products is neither needed nor enough.

## File format

Columns, in this order:

`product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json`

| Column | Rule |
|---|---|
| `product_id` | ID of the product; for a variation the ID of its parent product. |
| `variation_id` | ID of the variation, `0` for a simple product or a plan stored on a variable parent. |
| `sku` | Must equal the SKU of that product in this shop. This stops a file from another shop from being applied to the wrong products. |
| `product_key` | 2 to 100 characters: lowercase letters, digits and hyphens, starting with a letter or digit (`voucher-captain`). |
| `plan_key` | 1 to 100 characters, same characters (`business`). |
| `billing_interval` | `month` or `year`. |
| `live_site_limit` | Whole number from 1 to 1000. |
| `support_level` | `community`, `standard` or `priority`. |
| `entitlements_json` | JSON object with simple values, at most 4096 characters, or empty. |

Keys are validated, never rewritten: `Jährlich` or `my_plan` is rejected with a reason instead of being stored as something else. Capital letters and surrounding blanks are the only things that are normalized. The product editor applies the same rules.

Comma and semicolon separated files are both accepted, with or without a UTF-8 byte order mark, so a file edited and saved in Excel imports. The header line must stay unchanged.

Prices and currency are read from the WooCommerce product at import time; the CSV cannot set a price. The customer-facing name of a license product is not part of the file and is not changed by an import.

## Export

The export is read from the license catalog (table `wplc_plans`), one line per active plan. Cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe, so a spreadsheet program does not run them as formulas. The import removes that apostrophe again.

## Import

- At most 1,000 rows and 2 MB per file.
- Every rejected row is listed with its line number and the reason (unknown product, SKU mismatch, invalid key, invalid interval, product open in the editor, …).
- A product that another user has open in the product editor is skipped (`product is being edited`): saving that editor form afterwards would silently undo the import. Import the row again when the editor is closed.
- Rows are independent and an import updates existing plans in place (plan IDs do not change). Importing the same file twice is harmless.
- The import stops before PHP's time limit (90 seconds by default, filter `wplc_catalog_csv_time_budget`) and reports the line it stopped at. Import the same file again to finish.
- Each import writes an audit entry `catalog_csv_imported` with the counts; each plan that changes writes `catalog_plan_created`, `catalog_plan_changed` or `catalog_plan_deleted`.

Use a staging backup first. Export and import contain product mapping data only, never license keys, activations, customer records, payment tokens, or secrets. The code supplies a transfer mechanism; it does not know the shop's actual product IDs. Import the real catalog in the seller's WooCommerce installation before activation.
