# Product compatibility matrix

Source is the current `readme.txt` in each connected product repository (snapshot 2026-11-12). These are declared minimums, not runtime test results.

| Product | WordPress minimum | PHP minimum | WooCommerce minimum | Declared tested WordPress |
|---|---:|---:|---:|---:|
| Voucher Captain (`wp-couponmaster`) | 6.4 | 8.1 | Not declared in readme | 7.1 |
| WP Pass Captain (`wp-passmaster`) | 6.4 | 8.2 | 8.2 | 7.1 |
| Affiliate Captain (`wpaffiliatemaster`) | 6.2 | 7.4 | Not declared in readme | 7.1 |
| WP License Captain | Not yet declared | 7.4 | Not yet verified | Not yet verified |

The PHP CI currently lints 7.4, 8.1, 8.2, and 8.3. This does not verify WordPress/WooCommerce integration. Before release, owners of Voucher Captain and Affiliate Captain must declare their WooCommerce minima, and the license server needs a documented WooCommerce minimum. Add integration jobs for the actual oldest supported and current stable combinations; keep the product minimums accurate if test failures require a version increase.
