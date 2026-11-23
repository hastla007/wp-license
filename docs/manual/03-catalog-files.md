# 3. Catalog files: your products and entitlements

The server knows nothing about your products until you tell it. Each licensed add-on ships one **catalog file**, `wplc-catalog.json`, that lists its product key, the prefix of its entitlement keys and every entitlement key the add-on checks, with labels per language. The shop imports the file; the product editor then offers the entitlements as tick boxes, and the pricing table shows their labels.

The add-on itself never reads the file at run time. It is data for the shop.

## Where the file lives

Put `wplc-catalog.json` into the root folder of the add-on, next to its main plugin file, so that it is part of every release ZIP:

```
acme-reports-pro/
├── acme-reports-pro.php
├── wplc-catalog.json
└── includes/…
```

When you upload that ZIP as a release, the server imports the file automatically (see "Import" below).

## Example

```json
{
  "format": 1,
  "product_key": "acme-reports-pro",
  "prefix": "acme_reports",
  "name": "Acme Reports Pro",
  "status": "available",
  "entitlements": [
    {
      "key": "acme_reports.pdf_export",
      "label": {
        "en": "PDF export",
        "de_DE": "PDF-Export",
        "de_DE_formal": "PDF-Export"
      },
      "description": {
        "en": "Export every report as a PDF file."
      },
      "status": "available"
    },
    {
      "key": "acme_reports.scheduled_reports",
      "label": "Scheduled reports by email",
      "status": "planned"
    }
  ]
}
```

## Fields

| Field | Required | Meaning |
| --- | --- | --- |
| `format` | yes | Always `1`. A file with another number is rejected. |
| `product_key` | yes | The product key: folder name and text domain of the add-on, the key on the WooCommerce product, what the client sends. `^[a-z0-9][a-z0-9-]{1,99}$`. |
| `prefix` | see text | The part of every entitlement key before the first dot (`acme_reports` for `acme_reports.pdf_export`). `^[a-z][a-z0-9_-]{0,39}$`. May be left out when the file lists at least one entitlement; required when `entitlements` is empty. One prefix belongs to one product: a file whose prefix another product already uses is rejected. |
| `name` | yes | Product name; the heading of its entitlements in the product editor. |
| `status` | no | `available` (default): released; the product editor suggests the key and offers its available entitlements. `planned`: the key is reserved, but nothing is offered. |
| `entitlements` | yes | List of at most 500 entitlements; may be empty. |
| `entitlements[].key` | yes | The key the add-on checks. Starts with the prefix and a dot; letters, digits, `.`, `_`, `-`; at most 120 characters. Lowercase snake_case after the prefix is the convention. Each key once per file. |
| `entitlements[].label` | yes | Short name next to the tick box and in the pricing table, at most 190 characters. |
| `entitlements[].description` | no | Longer explanation, at most 1000 characters. Stored and exported. |
| `entitlements[].status` | no | `available` (default): a released version of the add-on checks the key, the product editor offers it. `planned`: reserved and not offered, so you do not sell what does not exist yet. |
| `docs_url` | no | Address of your documentation (`https://…`), per locale. The account and the license email link to it next to the installation steps. |
| `install_steps` | no | 1 to 10 installation steps, per locale, at most 500 characters each. They replace the three built-in steps in the account and the license email. |
| `plans` | no | Names of your plans for customers: plan key => `{ "name": text }`, at most 50. Shown in the account and in the license emails instead of the name of the WooCommerce product. |

**Texts** (`name`, `label`, `description`, `docs_url`, the installation steps and the plan names) are either a plain string (read as English) or an object with one text per WordPress locale. `en` is required and is the fallback for every other language; add `de_DE` (informal German) and `de_DE_formal` (formal German) and any other locale (`fr_FR`, …) you translate into. Plain text only: a text with HTML or line breaks is rejected, not cleaned.

The texts for customers (`docs_url`, `install_steps`, `plans`, SET-LIC-24) are the place to maintain them per product and per language: the customer sees the text of the language of the email (the language of the purchase) or of the page. Without them the built-in texts and the name of the WooCommerce product are used, as before. The filters `wplc_product_docs_url`, `wplc_install_steps` and `wplc_plan_label` receive them and decide last.

Unknown fields are ignored, so your build may add its own (for example `generator` or `since`). A file may be at most 256 KB; a UTF-8 byte order mark is accepted. The JSON schema of the format ships with the plugin as [`docs/catalog-file.schema.json`](../catalog-file.schema.json); `wp wplc catalog validate` applies the same rules and names the first error with its field, for example `entitlements[3].key: The entitlement key is listed twice.`

## What an import does

- **One file, one product.** An import replaces the whole definition of that product. A key that is no longer in the file leaves the catalog.
- **Plans and licenses are never changed by an import.** They store their entitlement keys themselves. A plan that still stores a removed key keeps it; the product editor shows it in the field "Advanced: additional entitlements (JSON)" instead of as a tick box.
- **Removing a product takes nothing away from what was sold.** Plans keep selling, licenses keep their rights, installations notice nothing.
- **Idempotent.** Importing the same content again changes nothing and writes no audit entry.
- **The product key selects the entitlement group, nothing else.** A license product is offered only the entitlements of the prefix its product key maps to in the catalog. A product key the catalog does not know is offered no group until its file is imported.
- **Planned stays out.** Planned products and entitlements are not offered. A shop that already delivers such a function can offer planned keys with `add_filter( 'wplc_offer_planned_entitlements', '__return_true' );`.
- **Audit.** Every import that changes something is recorded as `catalog_file_imported` (with the added, removed and changed keys), a removal as `catalog_product_removed`.

## Import

| Way | How | Needs |
| --- | --- | --- |
| Release package (automatic) | Upload the add-on ZIP as a release (admin or `wp wplc release create --zip=…`). The server reads `<plugin folder>/wplc-catalog.json` from the package and imports it. | Capability `wplc_publish_releases`; setting "Catalog file of release packages" on (default) |
| Admin | **WooCommerce → License catalog files → Import a catalog file**. The screen lists every product with its entitlements, exports a product as a file and removes a product (with a confirmation box). | Capability `wplc_manage_catalog` |
| WP-CLI | `wp wplc catalog import <file>... [--dry-run]`, `validate`, `list`, `export`, `remove` (chapter 10) | Shell access |

### Import from the release package

The normal way to keep the catalog current is to do nothing: build the add-on with `wplc-catalog.json` in its folder and upload the ZIP as a release.

- Only exactly `<plugin folder>/wplc-catalog.json` is read; a file with that name elsewhere in the archive is ignored. The archive is opened read-only and only that entry is read, never more than 256 KB.
- The file is imported when the release is created from the ZIP, also as a draft: the statuses in the file decide what is offered, not the state of the release. A release registered by URL is not read; import its file by hand.
- **A release never fails because of its catalog file.** An invalid file, a file whose `product_key` is not the product of the release, or a file larger than 256 KB is refused; the release is created anyway, the screen names the reason and the audit log gets `catalog_release_import_refused`.
- An older package does not turn the catalog back: when the product already has a release with a higher version, the file is not imported.
- When the new file removes keys that active plans still grant, the import is carried out and the message names those keys (audit `catalog_keys_removed_in_use`); plans and licenses keep them.
- Switch it off under **WooCommerce → License settings → Catalog file of release packages**, or with the filter `wplc_catalog_auto_import`.

### Shipped catalog files

The plugin ZIP can contain catalog files under `catalogs/`. They are imported once when the database schema is installed. A product whose catalog was imported from a release package, in the admin or with WP-CLI is never replaced by a shipped file. If such a shipped product is not yours, remove it with `wp wplc catalog remove <product-key>` or on the catalog files screen; nothing that was sold depends on it.

## Checklist for your build

1. One file per add-on, `format` 1, `product_key` equal to the add-on's folder and text domain.
2. Every key the add-on checks with `is_entitled()` appears once: `available` if a released version checks it, `planned` otherwise.
3. Every text has `en`; add `de_DE` and `de_DE_formal` (and other locales) when the add-on is translated.
4. Validate in your build against [`catalog-file.schema.json`](../catalog-file.schema.json), or with `wp wplc catalog validate acme-reports-pro/wplc-catalog.json` on any WordPress with the plugin.
5. Do not remove a key that was sold unless the function is gone: the tick box disappears from the product editor (stored plans keep the key).

## Project products

A plugin built for a single client (a connector, a custom extension) is licensed like any other add-on: its own product key, its own catalog file and its own prefix. Two products never share a prefix, and an extension never uses the prefix of the add-on it extends:

| Product key | Prefix | Example key |
| --- | --- | --- |
| `acme-reports-pro` | `acme_reports` | `acme_reports.pdf_export` |
| `acme-reports-crm-connector` | `acme_reports_crm` | `acme_reports_crm.sync` |

Create the license product first (it may stay private, see chapter 4), then upload the first release: before the product key exists in WooCommerce, the release screen refuses the upload with "This product key is not registered".

## Extension points

The filters `wplc_entitlement_catalog` and `wplc_known_product_keys` receive what the catalog offers and can still add or remove entries. Catalog files are the supported way; use the filters only for special cases.
