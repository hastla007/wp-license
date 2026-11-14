# Product repositories and the license client

## Who carries the client

Decision of 13 Nov 2026 (`docs/IMPLEMENTATION-PLAN.md`): the three core plugins stay free and ungated and are published on WordPress.org. They contain **no** license client, no entitlement check, no custom updater and no call to the license server. The client library (`src/Client/`, see [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md)) is bundled only in the separate premium add-on of each product.

| Product | Core plugin (free) | Premium add-on | Product key of the add-on (proposal) |
| --- | --- | --- | --- |
| Voucher Captain | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | separate plugin, sold in the shop | `voucher-captain-pro` |
| WP Pass Captain | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | separate plugin, sold in the shop | `pass-captain-pro` |
| WP Affiliate Captain | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | separate plugin, sold in the shop | `affiliate-captain-pro` |

The product keys are a proposal that follows the rule "product key = folder of the add-on = text domain". They become final with the real product catalog (TODO T-42); the key in the add-on, in the catalog of the shop and the top-level folder of every release ZIP must be the same.

The library is written for PHP 7.4 and WordPress 6.5, the lowest requirements among the products, and needs neither WooCommerce nor a class of the license server.

## Earlier pull requests against the core plugins

Before that decision, each core repository received a pull request from a branch `codex/wplc-client` (wp-couponmaster #10, wp-passmaster #20, wpaffiliatemaster #9) that added a license screen and an older copy of `LicenseClient` to the **core** plugin. They contradict the decision and must not be merged into a core plugin. They also predate version 2.0.0 of the library and its contract (server signature, signed offline window, site address, updater). Their state is not tracked in this repository.

## What an add-on has to do

Per add-on, in its own repository (the tasks are listed there, section "Premium add-on"):

1. Export the library under the add-on's namespace (`php bin/export-client.php`), or scope it in the add-on's build.
2. Add the three glue files of the example in [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md): configuration, `register()` in the main file, `uninstall.php`.
3. Add the `Update URI` header with the stable host name of the license server and pin the public key of the server.
4. Gate the premium features with `is_entitled()` using the keys of [ENTITLEMENTS.md](ENTITLEMENTS.md).
5. Decide the multisite mode (per site or per network) before the first release.
6. Run the quick start of the integration guide against a test shop: activation, staging copy, release, update, expiry.

## Updating the library in an add-on

The library has one version for all its classes (`LicenseManager::VERSION`). To update an add-on, run the export again from the wanted tag of this repository and release the add-on. There is no shared package and no automatic distribution: each add-on ships the copy it was tested with.

## Status

No add-on exists yet and none talks to the server. The library is covered by unit tests and by integration tests against the REST server of this plugin, including an update installed by the WordPress upgrader; it has not run in a real add-on against a real shop.
