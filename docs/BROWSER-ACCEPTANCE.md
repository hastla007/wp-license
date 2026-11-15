# Browser acceptance pass (automated part of T-130)

`tests/browser/` drives the E2E shop of `tests/e2e/` with headless Chromium (Playwright) and
checks every screen of the plugin the way a browser renders it: JavaScript, CSS, narrow
screens and right-to-left. It does not replace the human acceptance of T-130; it removes
everything a machine can check from that list.

## Run

```bash
tests/e2e/bin/run-e2e.sh             # once: set up the shop and its data (keeps the database)
tests/browser/run-browser.sh         # the browser pass, about 2-3 minutes
```

`run-browser.sh` needs the site from `run-e2e.sh` (flows 01-06 at least; a full run is best),
Node with the module `playwright` and its Chromium (`node -e "require('playwright')"` must
work; nothing is installed by the script), `openssl` and PHP. It

1. copies the current plugin into the site (as `run-e2e.sh` does) plus the test-only
   mu-plugin `tests/browser/mu-plugins/wplc-browser.php`;
2. on a WooCommerce **source build** (the `run-e2e.sh` fallback when wordpress.org is not
   reachable) copies WooCommerce's uncompiled classic scripts (`client/legacy/js`) to
   `assets/js`, so the classic checkout can refresh itself;
3. starts the PHP server and runs `tests/browser/fixtures.php` (customer `browser` with a
   license bought with automatic renewal, an activation with a long unbreakable site address,
   a page with the classic `[woocommerce_checkout]` shortcode);
4. runs `tests/browser/acceptance.js` and afterwards checks `debug.log` for entries from
   plugin code.

| Variable | Default | Meaning |
| --- | --- | --- |
| `WPLC_E2E_SITE` | `${TMPDIR:-/tmp}/wplc-e2e-site` | Site of `run-e2e.sh` |
| `WPLC_E2E_PORT` | `8765` | Port of the PHP server |
| `WPLC_BROWSER_PROXY_PORT` | `8766` | Port of the local TLS proxy |
| `WPLC_BROWSER_OUT` | `$WPLC_E2E_SITE/.browser` | Screenshots (`NN-page-width.png`), `report.json`, `fixtures.json` |

Output: one `PASS`/`FAIL`/`NOTE` line per check, `BROWSER RESULT: PASS|FAIL`, exit code 1 on a
failed check, 2 on a setup error.

### How the browser reaches https://shop.test

Chromium is started with `--host-resolver-rules=MAP shop.test 127.0.0.1:<proxy port>` and
`--proxy-server=direct://` (an HTTPS proxy from the environment would otherwise swallow the
request). The script runs a tiny Node TLS proxy with a throw-away self-signed certificate
(`ignoreHTTPSErrors`) in front of the PHP server; `router.php` presents every request to
WordPress as `https://shop.test`. Cookies, redirects and secure-only cookies work as on a
real HTTPS shop.

### Right-to-left

With the cookie `wplc_browser_rtl=1` the mu-plugin switches WordPress to right-to-left for
that request (`is_rtl()`, `<html dir="rtl">`, the `-rtl` stylesheets of WordPress), as an RTL
locale would. No language pack is needed.

### WooCommerce source build

Without the compiled WooCommerce CSS the product data panel and the responsive account tables
would be unstyled and the layout checks meaningless. Only in that case the script injects two
hand-flattened stand-ins of the relevant WooCommerce 11.2.2 rules
(`tests/browser/wc-admin-shim.css`, `tests/browser/wc-frontend-shim.css`) and says so in a
`NOTE`. With the release ZIP (CI) the real stylesheets are used. The block checkout cannot
render at all in a source build (no compiled Blocks scripts); the script notes that and checks
it automatically when `assets/client/blocks/checkout.js` exists.

## What it checks

Customer at 1280 px and 375 px, plus the account at 375 px right-to-left; admin at 1280 px and
375 px, each left-to-right and right-to-left.

Every page:

- HTTP status below 400, the plugin markup is present, the plugin stylesheet is loaded;
- no PHP error output in the HTML (`WP_DEBUG_DISPLAY` is off, so `debug.log` is checked too);
- no JavaScript error and no failed request from plugin assets (errors of other code are
  counted in a `NOTE`; a source build produces many 404s for WooCommerce's own files);
- at 375 px: no element inside the plugin's container sticks out of the viewport unless a
  container that scrolls by itself holds it. Absolutely positioned elements (screen-reader
  labels) count against their containing block, because they widen the page even inside a
  scrolling table. If the page still scrolls sideways, the culprits are listed.

Pages: classic checkout, block checkout (when built), My account → Licenses, Sites, dashboard;
admin license list, license detail, add license, settings, releases, renewal review (the open
case from the E2E data is listed), audit log, diagnostics, catalog CSV, product editor (simple
product and an expanded variation).

Specific items:

| Item | Check |
| --- | --- |
| A-118 | Classic checkout: choose the renewal-capable gateway, tick the automatic-renewal opt-in and the withdrawal consent, change the billing country (AT, then DE) so WooCommerce runs `update_order_review` and re-renders the payment section; both boxes are still ticked (the first refresh is verified to have replaced the nodes). Bank transfer hides and unticks the opt-in. At 1280 px the order is then placed through the real form POST and lands on "order received". |
| A-124 | Copy button: live region `role=status aria-live=polite` next to it; success text announced and the clipboard holds the key; with clipboard API and `execCommand` failing, the failure text is announced and the key is focused and selected. |
| A-125 | Entitlement checklist: in the simple product's general panel the legend sits in the label column (left, or right in RTL) above 500 px and stacks below; in a variation it always stacks; legend and list stay inside their panel in all four combinations. |
| A-126 | Detail, settings, releases, renewal review and diagnostics tables scroll inside themselves at 375 px; account tables with a long site address fit. |

## Findings of the first run (15.11.) and fixes

- **Sites page without its assets.** `SiteOverview::enqueue_assets()` asked
  `is_wc_endpoint_url('wplc-sites')`, which only knows WooCommerce query vars; the endpoint is a
  plain rewrite endpoint. The page had neither `wplc-account.css` nor `wplc-ui.js` (no
  confirmation before "Deactivate"). Now detected through `$wp->query_vars`, loaded with the
  cache-busting version (`SiteOverviewIntegrationTest::test_site_overview_loads_its_stylesheet_and_script`).
- **Long site addresses** without a hyphen widened the account tables past a phone screen:
  `overflow-wrap: anywhere` on the cells (`wplc-account.css`).
- **A-126 incomplete:** the releases, renewal review and diagnostics tables did not scroll in
  themselves (diagnostics did not even load `wplc-admin.css`); the nginx snippet on the
  diagnostics page widened it. Screen-reader labels inside the scrolling tables escaped the
  scroll box (no containing block) and still made every such page scroll sideways; the tables
  are now `position: relative`. The license list's long view links ("Ending in the next 30 days
  without automatic renewal") now wrap. Wrapper classes `wplc-releases`, `wplc-diagnostics`,
  `wplc-settings`, `wplc-catalog-csv`, `wplc-license-list` were added for this.
- A-118, A-124 and A-125 passed without changes.

## What still needs a human

- **Screen reader:** the live region is verified in the DOM; whether NVDA/JAWS/VoiceOver/TalkBack
  actually announce it, and the reading order of the forms, needs a person.
- **Real wallets** (Apple Pay, Google Pay, Link, B-113) and **real Stripe** (3DS windows, saved
  cards, webhooks; see `docs/STRIPE-TEST-PLAN.md`, T-12).
- **Block checkout** in a source build, and generally against the release build of the
  WooCommerce version you ship (CI has it, see below).
- **Mail clients** (the nine plugin mails in Outlook, Gmail, Apple Mail; A-129).
- **Language and design judgement:** German texts in context, themes other than Twenty
  Twenty-Five, real devices (touch targets, zoom), visual polish. The screenshots are a starting
  point for that review.
- The secondary screens listed in T-130 (account as a theme override, restore, re-issue key,
  owner change, export) are only covered where they appear on the pages above.

## CI

Not wired into `.github/workflows/e2e.yml`. The runner has Node but neither the `playwright`
module nor its Chromium; installing them (`npm i playwright && npx playwright install
--with-deps chromium`) adds a download and a moving dependency to the required E2E job, and the
pass has only run locally so far. To add it later as an opt-in step after the E2E step:
`WPLC_BROWSER_OUT=$WPLC_E2E_SITE/.browser tests/browser/run-browser.sh` and upload
`.browser/` as an artifact on failure.
