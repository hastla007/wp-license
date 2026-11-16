# Product repositories and the license client

## Who carries the client

Decision of 13 Nov 2026 (`docs/IMPLEMENTATION-PLAN.md`): the three core plugins stay free and ungated and are published on WordPress.org. They contain **no** license client, no entitlement check, no custom updater and no call to the license server. The client library (`src/Client/`, see [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md)) is bundled only in the separate premium add-on of each product.

| Product | Core plugin (free) | Premium add-on | Product key of the add-on (decided 14 Nov 2026) |
| --- | --- | --- | --- |
| Voucher Captain | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | `addons/voucher-captain-pro` in [captain-pro](https://github.com/hastla007/captain-pro) | `voucher-captain-pro` |
| Pass Captain | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | `addons/pass-captain-pro` in captain-pro | `pass-captain-pro` |
| Affiliate Captain | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | `addons/affiliate-captain-pro` in captain-pro | `affiliate-captain-pro` |

The product keys are fixed (decision of 14 Nov 2026) and follow the rule "product key = folder of the add-on = text domain": `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`. The key in the add-on, in the catalog of the shop and the top-level folder of every release ZIP must be the same.

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

Stand 16 Nov 2026:

- The three add-ons exist in [hastla007/captain-pro](https://github.com/hastla007/captain-pro) (`addons/voucher-captain-pro`, `addons/pass-captain-pro`, `addons/affiliate-captain-pro`). Each carries a copy of this library (version 2.0.2, byte-identical to `src/Client/` of this repository at that time), a license screen, the updater and a build. Affiliate Captain Pro and Pass Captain Pro contain premium functions; Voucher Captain Pro is a skeleton without a sellable function.
- captain-pro runs its add-ons against the code of this server in its own test run. `tests/Api/ServerClientInteropTest.php` in this repository checks the signature contract with the copy of the library used there.
- Not done: no add-on has talked to a **deployed** license server. The server address and the pinned public key in the add-ons are placeholders until the permanent host name is decided (B-15 in [TODO.md](TODO.md)); no add-on release has been built for sale.
- Planned, not built: `workflow-captain-pro`, `form-captain-pro`, `review-captain-pro` (TODO sections 11 and 12).
- The library is covered by unit tests and by integration tests against the REST server of this plugin, including an update installed by the WordPress upgrader.
