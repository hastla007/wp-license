# License client library {{VERSION}}

The PHP library a premium WordPress add-on embeds to talk to a WP License Captain server: activation with a license key, signed entitlement checks with an offline window, the feature gate, notices and a license screen, premium updates through the WordPress updater, and uninstall. It needs WordPress 7.0+ and PHP 8.5+ (both since 2.2.0; 2.1.x ran on WordPress 6.0 and PHP 7.4) and, for the signature check, the PHP sodium extension or the `sodium_compat` that WordPress ships.

There is no separate client plugin: every add-on carries its own copy of this library.

## Contents

| Path | What it is |
| --- | --- |
| `src/Client/` | The library, namespace `WPLC\Client`. `load.php` requires the six classes. |
| `bin/export-client.php` | Copies the library into an add-on under the add-on's own namespace and text domain. |
| `SOURCE.json` | Version, source commit and checksum of the library in this package. The export script copies them into `wplc-client-source.json` of every export, so each add-on can tell which library it ships. |
| `bin/client-contract-test.php` | Runs activate → check → update check → deactivate against your server and verifies the signatures. |
| `CHANGELOG-CLIENT.md` | Changes by library version. |

## Embedding the library

Copy it into the add-on with its own namespace, in the build of the add-on or once (then commit the result):

```sh
php bin/export-client.php --target=/path/to/my-addon/includes/licensing \
    --namespace='MyVendor\MyAddon\Licensing' --text-domain=my-addon
```

Then, in the main file of the add-on:

```php
require_once __DIR__ . '/includes/licensing/load.php';

$license = new \MyVendor\MyAddon\Licensing\LicenseManager(
	array(
		'plugin_file' => __FILE__,
		'product_key' => 'my-addon',                       // product key in the catalog of your server
		'version'     => '1.0.0',
		'name'        => 'My Add-on',
		'text_domain' => 'my-addon',
		'server_url'  => 'https://shop.example',            // your license server, HTTPS
		'public_keys' => array( 'BASE64-PUBLIC-KEY' ),      // `wp wplc signing-key` on the server
	)
);
$license->register();

if ( $license->is_entitled( 'my-addon.some_feature' ) ) {
	// premium code
}
```

The main file needs the header `Update URI: https://shop.example/my-addon` so that WordPress asks your server, and nobody else, for updates. The full guide (settings, states, error codes, multisite, updates, the license form, privacy) is chapter 6 of the vendor manual that ships with the server plugin, `docs/manual/06-client-integration.md`.

### Namespace and prefix rules

- **Always export under a namespace of your add-on.** Several add-ons with this library can be active on one site, each with a different version. With its own namespace each add-on runs exactly the copy it was tested with. The script refuses `WPLC\Client` and fails when a copied file still refers to it.
- An unchanged copy (namespace `WPLC\Client`) also loads, because every class is declared only when it does not exist yet. But then the copy that is loaded first is used by every add-on, also when it is older. Use that only for experiments.
- The export rewrites the text domain literal `wp-license` to yours, so `wp i18n make-pot` of the add-on finds the strings of `Messages.php`; translate them with the add-on.
- Options, hooks, cron events and form fields use the `option_prefix` setting (default: the product key with `-` replaced by `_`), for example `{prefix}_license`, `{prefix}_license_state`, `{prefix}_license_check`. Choose a product key that is unique to your add-on.
- Do not edit the exported files; export again to update. The `VERSION` constant of every class names the library version.

## Compatibility promise (API v1)

- The client of one major version (2.x) works with **every server that answers API v1** (`/wp-json/wplc/v1/…`). A new server version may add fields, error codes and routes; the client ignores what it does not know and treats an unknown error as "keep the stored rights".
- A server never needs a newer client: a new server feature that needs client support is optional for the client and announced in the changelogs of both.
- Minor and patch versions of the client (2.1.0 → 2.1.1) only add things or fix bugs; a minor version may also raise the minimum PHP version (2.2.0: PHP 8.5). A change that needs work in the add-on is marked **Action** in `CHANGELOG-CLIENT.md`.
- A client that needs something the server cannot do (a new major API version) gets a new major version (3.0.0); API v1 then stays available on the server for the 2.x clients that are installed on customer sites.
- What the client relies on: the response signature `wplc-response-v2` (HMAC over `payload` with the installation secret, Ed25519 over `wplc-response-v2\n{installation id}\n{payload}` with a pinned key), the request nonce in the payload, `product_key` in every signed activation, entitlement and update answer, and the error codes listed in `docs/API-V1.md`.

## Contract test against your server

Before you ship an add-on, run the contract test against your own server with a test license of the product:

```sh
php bin/client-contract-test.php --server=https://shop.example --product=my-addon \
    --key=WPLC-XXXX-XXXX-XXXX-XXXX --public-key=BASE64-PUBLIC-KEY
```

It uses the unchanged library of this package without WordPress and real HTTPS. It activates a development site (`https://contract-test.local` by default, so no live site slot of the license is used; the license must allow a development site), checks the entitlements, asks for an update (metadata only, no download grant), checks that the last answer verifies against the pinned key while a changed payload and another key are refused, and deactivates again, also when a step failed. Each step prints `PASS` or `FAIL`; the exit code is 0 when all passed, 1 otherwise, 2 for wrong arguments. Options: `--site`, `--environment=production|staging|development`, `--version` (reported installed version), `--rest-base` (server without `/wp-json`), `--timeout`, `--allow-insecure` (http, local servers only). Needs the PHP sodium extension. The key is sent only to your server and never printed.

## License

GPL-2.0-or-later, like the server plugin.
