# Product repositories and the license client

## Who carries the client

Decision of 13 Nov 2026 (`docs/IMPLEMENTATION-PLAN.md`): the three core plugins stay free and ungated and are published on WordPress.org. They contain **no** license client, no entitlement check, no custom updater and no call to the license server. The client library (`src/Client/`, see [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md)) is bundled only in the separate premium add-on of each product.

| Product | Core plugin (free) | Premium add-on | Product key of the add-on (decided 14 Nov 2026) |
| --- | --- | --- | --- |
| Voucher Captain | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | `addons/voucher-captain-pro` in [captain-pro](https://github.com/hastla007/captain-pro) | `voucher-captain-pro` |
| Pass Captain | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | `addons/pass-captain-pro` in captain-pro | `pass-captain-pro` |
| Affiliate Captain | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | `addons/affiliate-captain-pro` in captain-pro | `affiliate-captain-pro` |

The product keys are fixed (decision of 14 Nov 2026) and follow the rule "product key = folder of the add-on = text domain": `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`. The key in the add-on, in the catalog of the shop and the top-level folder of every release ZIP must be the same.

The library is written for PHP 8.5 (since 2.2.0; the owner decided on 16 Nov 2026 that every Captain plugin requires the newest PHP version) and WordPress 7.0 (owner decision of 17 Nov 2026, uniform for all Captain plugins, add-ons and connectors), and needs neither WooCommerce nor a class of the license server.

### Project connectors

The second group of users are the project connectors (decision 17, [TODO.md](TODO.md) section 15): plugins built for one client, such as the connectors for BoatingSchool and LearnBoating. Each connector is its own project product with its own product key (key = folder = text domain, e.g. `learnboating-form-connector`), its own catalog file and entitlement prefix, and a license the owner issues by hand ([ENTITLEMENTS.md](ENTITLEMENTS.md#creating-a-project-product)). It carries its own copy of the library under its own namespace, exported like an add-on's (captain-pro delivers the export, `build/lib/ClientExporter.php`), and keeps its own options, named after its option prefix.

A connector and a Pro add-on on the same website are two independent clients: two installations, two activations, and each takes one place of the live-site limit of its own product only. Revoking or suspending one license leaves the other valid, and a key of one product is refused by the other client (`product_mismatch`). `tests/integration/ProjectConnectorClientIntegrationTest.php` runs this with the library of this repository for the Pro add-on and the copy vendored into the starter (`Vendor\Product\Premium\Client`) for the connector.

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

## Where a copy comes from

Every export writes `wplc-client-source.json` next to the copied files. A receiver reads the origin of its copy from this file and does not write it down by hand:

| Field | Meaning |
|---|---|
| `version` | `LicenseManager::VERSION` of the copied library. |
| `source_commit` | Commit of this repository the copy was made from: from `--source-commit=`, from `SOURCE.json` of the library package `license-client-<version>.zip`, or from Git when the export runs in a checkout of this repository. `unknown` when none of them is available (the script prints a note). |
| `source_modified` | `true` when the source files differed from that commit (uncommitted changes, or an edited unpacked package). |
| `content_sha256` | Checksum of the seven source files before the export. For a published version it equals the line of that version in `packaging/client/checksums.json`. |
| `namespace`, `text_domain` | The values the export was run with. |
| `brand` | Since 2.4.0, only when given: the brand values the export wrote into `LicenseManager::BUILD_BRAND` (`support_email`, `support_url`, `key_placeholder`). |
| `files` | SHA-256 of every exported file, to check later that the copy was not edited. |

Content and version number belong together: `packaging/client/checksums.json` holds one checksum per published version, `bin/package-client.php` refuses to build a package whose content is not listed under its version, and `ClientPackageTest::test_pk06_content_and_version_number_belong_together` fails as soon as `src/Client` changes while `VERSION` stays the same. A change of the library therefore needs a new `VERSION` in every class, a section in `packaging/client/CHANGELOG-CLIENT.md` and a new line in `checksums.json`; existing lines are never changed. The list starts with 2.2.0: version 2.1.0 existed with three different contents (16 Nov 2026), which is what this rule prevents. The copy vendored into the starter by `examples/premium-addon-starter/bin/vendor-client.php` names the SHA-256 of each source file in its file header.

## Status

Stand 23 Nov 2026 (captain-pro `main` `4abdee4`):

- Six add-ons exist in [hastla007/captain-pro](https://github.com/hastla007/captain-pro): `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`, `form-captain-pro`, `review-captain-pro` and `workflow-captain-pro`. Each is built with a copy of this library from `packages/license-client` there (version 2.2.0 from commit `ec9220d0` of this repository, recorded in its `SOURCE.md`; 2.3.0 and 2.4.0 have not been taken over yet), a license screen, the updater and a catalog file. Pass, Form and Review Captain Pro and four functions of Affiliate Captain Pro are released by the owner; Voucher Captain Pro, Workflow Captain Pro and five further Affiliate functions are built but held back (their catalog entries stay `planned`).
- captain-pro runs its add-ons against the code of this server in its own test run. `tests/Api/ServerClientInteropTest.php` in this repository checks the signature contract with the copy of the library used there.
- Not done: no add-on has talked to a **deployed** license server. The server address and the pinned public key in the add-ons are placeholders until the permanent host name is decided (B-15 in [TODO.md](TODO.md)); no add-on release has been built for sale.
- Since 23 Nov 2026 captain-pro `main` also builds `form-captain-pro`, `review-captain-pro` and `workflow-captain-pro` (Workflow held back by the owner); the catalog files of Affiliate, Voucher, Pass, Form and Review under `catalogs/` are copies of that build (PROD-02 in [TODO.md](TODO.md)). `course-captain-pro` is not built yet.
- To do in captain-pro for library 2.4.0 (SET-LIC-33, -35): refresh `packages/license-client` from a tag with 2.4.0 (`bin/export-client.php`, which records the origin in `wplc-client-source.json`); pass the brand values of the Captain add-ons to the export (`--support-email=`, `--support-url=`, `--key-placeholder=`) or as `brand` in each add-on's configuration, within the white-label concept there (`docs/WHITE-LABEL.md`); set `allow_channel_choice => true` in each Captain add-on (owner decision E-05 of 23 Nov 2026: the customer turns beta on; the server side is done since 23 Nov 2026, SET-LIC-20: the signed answers carry `update_channels`, and a license whose plan has no beta right gets stable releases even when it asks for beta); add the 19 new texts of 2.4.0 to the German catalogs of each add-on (`de_DE` du, `de_DE_formal` Sie).
- The library is covered by unit tests and by integration tests against the REST server of this plugin, including an update installed by the WordPress upgrader.
