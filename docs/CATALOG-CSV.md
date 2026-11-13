# WooCommerce license catalog CSV

WooCommerce managers can export current mappings and import a mapping file from **WooCommerce → License catalog CSV**.

The CSV columns are `product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json`. Prices and currency are read from the local WooCommerce product at import time; the CSV cannot override price. Import requires the same product/variation IDs and exact SKU as the current shop, preventing a file from accidentally mapping a different catalog. Imports are limited to 1,000 rows; invalid rows are skipped and counted. Product metadata and the server-side plan mapping are updated together as far as WooCommerce APIs permit.

Use a staging backup first. Export/import contains product mapping data only, never license keys, activations, customer records, payment tokens, or secrets. The code supplies a transfer mechanism; it does not know the shop's actual product IDs. Import the real catalog in the seller's WooCommerce installation before activation.
