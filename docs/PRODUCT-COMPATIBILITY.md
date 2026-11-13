# Produkt-Kompatibilitätsmatrix

Erfasst am 12.11.2026 aus den jeweiligen `readme.txt`- und `composer.json`-Dateien auf den Default-Branches. Mindestversionen sind Projektangaben; sie ersetzen keine Laufzeitprüfung gegen konkrete Shopinstallationen.

| Produkt | Repository | PHP mindestens | WordPress mindestens | WordPress getestet bis | WooCommerce |
|---|---|---:|---:|---:|---|
| WP Affiliate Captain | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | 7.4 | 6.2 | 7.1 | Optional; im Readme keine Mindestversion genannt |
| Voucher Captain | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | 8.1 | 6.4 | 7.1 | 8.9 oder neuer (Unreleased-Hinweis im Readme) |
| WP Pass Captain | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | 8.2 | 6.4 | 7.1 | 8.2 oder neuer, wenn die WooCommerce-Anbindung genutzt wird; WooCommerce ist für den Kern optional |
| WP License Captain | [wp-license](https://github.com/hastla007/wp-license) | 7.4 | 6.5 | 7.1 (Projektangabe) | Erforderlich; Mindestversion ist noch festzulegen und in CI zu prüfen |

## Client- und Gate-Entscheidungen

- Der gebundelte Lizenzclient bleibt PHP-7.4-kompatibel, damit Affiliate Captain nicht unbeabsichtigt eine höhere PHP-Mindestversion erhält.
- Pro Produkt gelten eigene Textdomain-, Optionsschlüssel- und Produkt-Key-Werte: `wp-affiliate-captain` / `affiliate-captain`, `voucher-captain` / `voucher-captain`, `wp-pass-captain` / `wp-pass-captain`.
- Der Client verifiziert die signierte Serverantwort vor Entitlement-Auswertung; der Offlinecache nutzt denselben Installation-Key und darf serverseitige Ablaufzeit oder 14 Tage nicht überschreiten.
- Bestehende Funktionen bleiben frei. Konkrete Premium-Feature-Keys müssen vor Aktivierung eines Gates je Produkt in einem versionierten Mapping festgelegt werden; bis dahin bleiben bereits veröffentlichte Funktionen ungated.
- REST-/Updater-Kompatibilität, PHP-Matrix sowie die Kombinationen WordPress 6.2/6.4/6.5 und WooCommerce 8.2/8.9 sind vor Launch in den jeweiligen Produkt-CIs abzunehmen.

## Offene Verifikation

- Die Affiliate-Captain-WooCommerce-Integration ist optional und muss separat gegen Classic Checkout, Blocks und HPOS geprüft werden.
- Voucher Captain meldet WooCommerce 8.9+ in einem Unreleased-Changelog-Eintrag; die Readme-Metadaten enthalten keine eigene WC-Mindestversion.
- WP License Captain nennt WooCommerce als Pflicht, aber die kleinste unterstützte WooCommerce-Version ist im Projektstand noch nicht verbindlich dokumentiert.
- Die Readme-Versionen sind gegen die aktuellen unterstützten WordPress-/WooCommerce-Releases erneut zu prüfen, bevor ein öffentlicher Supportzeitraum zugesagt wird.
