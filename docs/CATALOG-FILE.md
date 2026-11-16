# Catalog file (`wplc-catalog.json`)

A catalog file tells the license server which entitlements a license product has: their keys, their labels per language and whether they are released. The add-on (or its vendor's build) ships the file; the server imports it. The server code contains no product key, no entitlement key and no label of any vendor (owner decisions G2 and E8 of 16 Nov 2026, T-150).

This page is the contract for whoever generates the file, for example the build of an add-on. Format version: **1**. The JSON schema is [catalog-file.schema.json](catalog-file.schema.json); the validator is `WPLC\Catalog\CatalogFile` (`src/Catalog/CatalogFile.php`).

## Where an add-on puts the file

- **In the add-on:** one file per add-on, named `wplc-catalog.json`, in the root folder of the add-on (next to its main plugin file), so that it is part of the release ZIP.
- **In the shop:** the vendor imports the file when a version of the add-on is released that adds, renames or releases an entitlement (see "Import"). The add-on itself does not read the file at run time.
- **In this repository, for now:** the files of the seven Captain products lie under `catalogs/<product-key>.json` and are imported by schema step 24. They move to the build of `hastla007/captain-pro` (PROD-02 in [PRODUCT-PLAN.md](PRODUCT-PLAN.md)).

## Example

```json
{
  "format": 1,
  "product_key": "pass-captain-pro",
  "prefix": "pass",
  "name": "Pass Captain Pro",
  "status": "available",
  "entitlements": [
    {
      "key": "pass.waiting_room",
      "label": {
        "en": "Virtual waiting room",
        "de_DE": "Virtueller Warteraum",
        "de_DE_formal": "Virtueller Warteraum"
      },
      "description": {
        "en": "Queue for the start of a sale with many visitors."
      },
      "status": "available"
    },
    {
      "key": "pass.season_tickets",
      "label": "Season tickets",
      "status": "planned"
    }
  ]
}
```

## Fields

| Field | Required | Type | Meaning |
| --- | --- | --- | --- |
| `format` | yes | integer | Always `1`. A file with another number is rejected; the server is updated first. |
| `product_key` | yes | string | The license product key: what the add-on sends to the server and what the shop enters on the license product. Lowercase letters, digits and hyphens, 2 to 100 characters, starting with a letter or digit (`^[a-z0-9][a-z0-9-]{1,99}$`). |
| `prefix` | see text | string | The part of every entitlement key before the first dot (`pass` for `pass.waiting_room`). It groups the keys in the product editor. `^[a-z][a-z0-9_-]{0,39}$`. May be left out when the file lists at least one entitlement (it is then read from the first key); required when `entitlements` is empty. One prefix belongs to one product: a file whose prefix another product in the catalog uses is rejected. |
| `name` | yes | text | Name of the product; the heading of its entitlements in the product editor. |
| `status` | no | string | `available` (default): the product is released; the product editor suggests its key and offers its available entitlements. `planned`: the product key is reserved and resolves to its prefix, but the editor neither suggests it nor offers any of its entitlements. |
| `entitlements` | yes | list | The entitlements, at most 500. May be empty (a planned product without defined keys). |
| `entitlements[].key` | yes | string | The key the add-on checks. Must start with the prefix and a dot. Characters: letters, digits, `.`, `_`, `-`; at most 120. Lowercase snake_case after the prefix is the convention. Each key once per file. |
| `entitlements[].label` | yes | text | Short name shown next to the tick box and in the pricing table. At most 190 characters. |
| `entitlements[].description` | no | text | Longer explanation, at most 1000 characters. Stored and exported; not shown in the product editor yet. |
| `entitlements[].status` | no | string | `available` (default): a released version of the add-on checks the key; the product editor offers it. `planned`: reserved, not offered, so that a shop does not sell what does not exist. |

**Text** (`name`, `label`, `description`) is either a string, read as the English text, or an object with one text per locale:

- `en` is required. It is the fallback for every language without its own text.
- Further keys are WordPress locales: `de_DE` (informal German, "du"), `de_DE_formal` (formal German, "Sie"), `fr_FR` and so on (`^[a-z]{2,3}_[A-Z]{2}(_[a-z0-9]+)?$`).
- The server shows the text of the locale of the request; if the file has none, the text of the plugin's fallback for that locale (`de_AT`, `de_CH` and other German variants read `de_DE_formal`, their `_informal` forms `de_DE`; filter `wplc_translation_fallback_locale`); then `en`.
- Plain text only: no HTML, no line breaks, no control characters. A text with markup is rejected, not cleaned.

**Unknown fields are ignored**, at the top level and inside an entitlement. A build may add its own fields (for example `generator`, `addon` or `since`), with any JSON value; the server does not store them, does not export them and does not count them when it compares two files (`CatalogFileTest::test_unknown_fields_are_ignored_and_a_byte_order_mark_is_accepted`). The JSON schema allows additional properties for the same reason. Fields that change the meaning of the file will come with a new `format`.

A file may be at most 256 KB. A UTF-8 byte order mark is accepted.

## What the server does with it

- **One file, one product.** An import replaces the whole definition of that product. A key that is no longer in the file leaves the catalog.
- **Removing a product takes nothing away from what was sold.** Its keys are no longer offered as tick boxes and its product key is no longer suggested; plans keep their entitlements and keep selling, licenses keep theirs, and installations notice nothing (`CatalogFileIntegrationTest::test_removing_a_product_leaves_plans_and_licenses_untouched`).
- **Plans and licenses are never changed by an import.** They store their entitlement keys themselves. A plan that stores a key the catalog no longer knows keeps it; the product editor shows it in the field "Advanced: additional entitlements (JSON)" instead of as a tick box (the same rule as for the keys removed on 16 Nov 2026).
- **Idempotent.** Importing a file with the same content again changes nothing and writes no audit entry. Two files with the same meaning (other order of the object keys, other white space) count as the same.
- **Planned stays out.** A planned product or entitlement is not offered. A shop that delivers such a function itself can offer every planned key with `add_filter( 'wplc_offer_planned_entitlements', '__return_true' );`.
- **Filters stay.** `wplc_entitlement_catalog` and `wplc_known_product_keys` receive what the catalog offers and can still add or remove entries ([EXTENSION-POINTS.md](EXTENSION-POINTS.md)).
- **Storage.** The option `wplc_entitlement_catalog` (not autoloaded) holds every imported product with the source (`bundled`, `admin`, `cli`), the time of the import (UTC) and a fingerprint (SHA-256 of the normalized file). It is removed with the other data when the plugin is deleted with `WPLC_REMOVE_ALL_DATA`.
- **Audit.** Every import that changes something is recorded as `catalog_file_imported` (object type `catalog`, with the product key, the status, the number of entitlements and the added, removed and changed keys), a removal as `catalog_product_removed`. The import of the shipped files by schema step 24 is part of the upgrade and is not recorded.

## Import

| Way | How | Needs |
| --- | --- | --- |
| Admin | WooCommerce → License catalog files → "Import a catalog file" (upload). The screen lists every product with its entitlements, exports a product as a file and removes a product. Removing needs the capability, the nonce of the screen and a ticked confirmation box in the form (checked by the server; no script is involved), and is audited. | Capability `wplc_manage_catalog` |
| WP-CLI | `wp wplc catalog import <file>... [--dry-run]`, `wp wplc catalog validate <file>...`, `wp wplc catalog list [--format=json]`, `wp wplc catalog export <product-key>`, `wp wplc catalog remove <product-key> [--yes]` | Shell access |
| **Release package (automatic)** | When a release is created from a ZIP (upload on the releases screen, `wp wplc release create --zip=…`), the server reads `<plugin folder>/wplc-catalog.json` from the package and imports it. See "Import from the release package" below. | Capability `wplc_manage_releases`; setting "Import the catalog file from release packages" (on by default) |
| Upgrade | Schema step 24 imports the files shipped under `catalogs/`. A product that was imported from a release package, in the admin or with WP-CLI is never replaced by a shipped file. | – |

### Import from the release package (PROD-03)

Owner decision of 16 Nov 2026: the catalog file travels with the release. The normal way to keep the catalog current is therefore to do nothing: build the add-on with `wplc-catalog.json` in its folder and upload the ZIP as a release. Source: `src/Catalog/ReleaseCatalog.php`.

- **Where the file must be.** A release package has exactly one top-level folder (the plugin folder, see [UPDATES.md](UPDATES.md#what-a-package-must-look-like)). The server reads exactly `<plugin folder>/wplc-catalog.json`. A file with that name anywhere else in the archive is ignored.
- **When.** When the release is created from the ZIP, also as a draft: the statuses in the file (`available`, `planned`) decide what the product editor offers, not the state of the release. A release registered by URL is not read (the server does not download packages); import its file by hand.
- **A package without the file changes nothing.**
- **The release never fails because of its catalog file.** The file is refused, the release is created, the person who uploaded the package sees the reason next to "Draft release created", and the audit log gets `catalog_release_import_refused` (object: the release) when
  - the file is not a valid catalog file (the first error is named with its field),
  - its `product_key` is not the product of the release: a package must not redefine the catalog of another product,
  - it is larger than 256 KB or cannot be read.
- **An older package does not turn the catalog back.** When the product already has a release with a higher version, the file of the new package is not imported (no audit entry; the screen says so).
- **Removed keys that plans still grant.** The import is carried out. The message names the keys that active plans of the product still grant, and the audit log gets `catalog_keys_removed_in_use`. Plans and licenses keep the keys; the product editor shows them in the JSON field.
- **Idempotent and audited** like every import: source `release`, audit `catalog_file_imported` with the acting user; an unchanged file writes nothing.
- **Safe reading.** The archive is opened read-only and nothing is extracted to disk. Only the one entry is read, as a stream, and never more than 256 KB plus one byte of it, whatever size the archive declares (a file that inflates to more is refused).
- **Switching it off.** WooCommerce → License settings → Updates → "Import the catalog file from release packages", or the filter `wplc_catalog_auto_import`. Catalogs are then imported in the admin or with WP-CLI only.
- **The copies under `catalogs/`.** A product whose catalog came from a release package is no longer touched by the files shipped with the server (schema step 24 skips it). The shipped copy of a product becomes dead weight with the first release of that add-on that carries the file; the folder is deleted when every sold add-on does (PROD-02, PROD-12 in [TODO.md](TODO.md)).

`--dry-run` and `validate` check the file and print what an import would change, without writing. A rejected file is reported with the path of the field and the reason, for example `entitlements[3].key: The entitlement key is listed twice.` The codes are listed in `CatalogFile::describe()`.

## For the generator (checklist)

1. One file per add-on, `format` 1, `product_key` equal to the add-on's folder and text domain.
2. Every key the add-on checks appears once, with `status` `available` if a released version checks it, `planned` otherwise.
3. Every label has `en`; add `de_DE` and `de_DE_formal` when the add-on is translated (for nouns both are usually equal, give both anyway).
4. Validate against [catalog-file.schema.json](catalog-file.schema.json) in the build, or run `wp wplc catalog validate`.
5. Do not remove a key that was sold unless the function is gone: the tick box disappears from the product editor (stored plans keep the key).
