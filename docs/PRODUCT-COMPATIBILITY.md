# Produkt-Kompatibilitätsmatrix

Erfasst am 16.11.2026 aus den Plugin-Köpfen und `readme.txt`-Dateien der Arbeitsbranches `claude/sharp-mendel-xiiwf3` (enthalten den jeweiligen `main`; die Versionsnummern der Kerne können dort vor `main` liegen). Mindestversionen sind Projektangaben; sie ersetzen keine Laufzeitprüfung gegen konkrete Shopinstallationen.

| Produkt | Repository | PHP mindestens | WordPress mindestens | WordPress getestet bis | WooCommerce |
|---|---|---:|---:|---:|---|
| Affiliate Captain (Kern, kostenlos) | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | 7.4 | 6.3 | 7.1 | Optional; `WC requires at least: 8.0`, getestet bis 11.3 |
| Voucher Captain (Kern, kostenlos) | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | 8.1 | 6.4 | 7.1 | Erforderlich; `WC requires at least: 8.9`, getestet bis 11.2 |
| Pass Captain (Kern, kostenlos) | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | 8.2 | 6.4 | 7.1 | Optional (nur für die WooCommerce-Anbindung); `WC requires at least: 8.2`, getestet bis 11.3 |
| Premium-Add-ons `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro` | [captain-pro](https://github.com/hastla007/captain-pro) | wie der jeweilige Kern | wie der jeweilige Kern | – | wie der jeweilige Kern; die Mindestversion des Kerns steht in `addons/<slug>/config.php` |
| WP License Captain (Lizenzserver) | [wp-license](https://github.com/hastla007/wp-license) | 7.4 | 6.5 | 7.1 | Erforderlich; **8.9** (`WC requires at least: 8.9` in `wp-license.php`, zur Laufzeit geprüft durch `Requirements::MIN_WC`, entschieden mit T-104); in CI geprüft mit 8.9.3, 9.4.3 und 11.2.2 ([COMPATIBILITY.md](COMPATIBILITY.md)) |

## Client- und Gate-Entscheidungen

- Der gebundelte Lizenzclient bleibt PHP-7.4-kompatibel, damit Affiliate Captain nicht unbeabsichtigt eine höhere PHP-Mindestversion erhält.
- Produktschlüssel (festgelegt am 14.11.2026, T-158): `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro` (Schlüssel = Ordner = Textdomain des Add-ons in hastla007/captain-pro); siehe [ENTITLEMENTS.md](ENTITLEMENTS.md#product-keys-decided-14-nov-2026-t-158).
- Der Client verifiziert die signierte Serverantwort vor Entitlement-Auswertung; der Offlinecache nutzt denselben Installation-Key und darf serverseitige Ablaufzeit oder 14 Tage nicht überschreiten.
- Bestehende Funktionen bleiben frei. Konkrete Premium-Feature-Keys müssen vor Aktivierung eines Gates je Produkt in einem versionierten Mapping festgelegt werden; bis dahin bleiben bereits veröffentlichte Funktionen ungated.
- REST-/Updater-Kompatibilität, PHP-Matrix sowie die Kombinationen WordPress 6.3/6.4/6.5 und WooCommerce 8.0/8.2/8.9 sind vor Launch in den jeweiligen Produkt-CIs abzunehmen. Die Client-Bibliothek selbst verlangt PHP 7.4 und WordPress 6.5; ein Add-on, dessen Kern WordPress 6.3 oder 6.4 erlaubt, muss selbst mindestens 6.5 verlangen.

## Offene Verifikation

- Die Affiliate-Captain-WooCommerce-Integration ist optional und muss separat gegen Classic Checkout, Blocks und HPOS geprüft werden (Aufgabe des Kern-Repositorys).
- Die Client-Bibliothek (`src/Client/`) verlangt WordPress 6.5; Affiliate Captain erlaubt 6.3, Voucher und Pass Captain 6.4. Ob die Add-ons in captain-pro 6.5 als eigenes Minimum deklarieren, ist dort zu prüfen.
- Die Readme-Versionen sind gegen die aktuellen unterstützten WordPress-/WooCommerce-Releases erneut zu prüfen, bevor ein öffentlicher Supportzeitraum zugesagt wird.
