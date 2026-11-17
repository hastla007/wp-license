# Produkt-Kompatibilitätsmatrix

Erfasst am 16.11.2026 aus den Plugin-Köpfen und `readme.txt`-Dateien der Arbeitsbranches `claude/sharp-mendel-xiiwf3` (enthalten den jeweiligen `main`; die Versionsnummern der Kerne können dort vor `main` liegen). Mindestversionen sind Projektangaben; sie ersetzen keine Laufzeitprüfung gegen konkrete Shopinstallationen.

| Produkt | Repository | PHP mindestens | WordPress mindestens | WordPress getestet bis | WooCommerce |
|---|---|---:|---:|---:|---|
| Affiliate Captain (Kern, kostenlos) | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | 7.4 | 6.3 | 7.1 | Optional; `WC requires at least: 8.0`, getestet bis 11.3 |
| Voucher Captain (Kern, kostenlos) | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | 8.1 | 6.4 | 7.1 | Erforderlich; `WC requires at least: 8.9`, getestet bis 11.2 |
| Pass Captain (Kern, kostenlos) | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | 8.2 | 6.4 | 7.1 | Optional (nur für die WooCommerce-Anbindung); `WC requires at least: 8.2`, getestet bis 11.3 |
| Premium-Add-ons `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro` | [captain-pro](https://github.com/hastla007/captain-pro) | wie der jeweilige Kern | wie der jeweilige Kern | – | wie der jeweilige Kern; die Mindestversion des Kerns steht in `addons/<slug>/config.php` |
| WP License Captain (Lizenzserver) | [wp-license](https://github.com/hastla007/wp-license) | 8.5 (MySQL 9.7, MariaDB 12.3; MINVER-1) | 7.0 | 7.1 | Erforderlich; **11.2** (`WC requires at least: 11.2.2` in `wp-license.php`, zur Laufzeit geprüft durch `Requirements::MIN_WC`, Entscheidung des Inhabers vom 17.11.2026); in CI geprüft mit 11.2.2 (WordPress 7.0) und 11.3.0 (neuestes WordPress) ([COMPATIBILITY.md](COMPATIBILITY.md)) |

## Client- und Gate-Entscheidungen

- Entscheidung des Inhabers vom 16.11.2026 für alle Captain-Plugins (MINVER-1): Mindestversionen sind die neuesten, PHP 8.5, MySQL 9.7 (LTS) und MariaDB 12.3 (LTS). Der Lizenzclient zieht mit: ab Version 2.2.0 verlangt er PHP 8.5 (vorher 7.4). Die PHP-Spalte der Tabelle oben ist der Stand vor dieser Entscheidung, die Kerne stellen in ihren eigenen Repositorys um.
- Entscheidung des Inhabers vom 17.11.2026, ebenfalls einheitlich für alle Captain-Plugins, Add-ons und Connectoren: zusätzlich zu MINVER-1 sind WordPress 7.0 und WooCommerce 11.2.2 die Mindestversionen. WP License Captain und die Client-Bibliothek (ab 2.2.0) verlangen WordPress 7.0; die WordPress- und WooCommerce-Spalten der Kerne oben sind der Stand vor dieser Entscheidung, die Kerne und captain-pro stellen in ihren eigenen Repositorys um.
- Produktschlüssel (festgelegt am 14.11.2026, T-158): `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro` (Schlüssel = Ordner = Textdomain des Add-ons in hastla007/captain-pro); siehe [ENTITLEMENTS.md](ENTITLEMENTS.md#product-keys-decided-14-nov-2026-t-158).
- Der Client verifiziert die signierte Serverantwort vor Entitlement-Auswertung; der Offlinecache nutzt denselben Installation-Key und darf serverseitige Ablaufzeit oder 14 Tage nicht überschreiten.
- Bestehende Funktionen bleiben frei. Konkrete Premium-Feature-Keys müssen vor Aktivierung eines Gates je Produkt in einem versionierten Mapping festgelegt werden; bis dahin bleiben bereits veröffentlichte Funktionen ungated.
- REST-/Updater-Kompatibilität, PHP-Matrix sowie die Mindestversionen WordPress 7.0 und WooCommerce 11.2.2 (Entscheidung vom 17.11.2026) sind vor Launch in den jeweiligen Produkt-CIs abzunehmen. Die Client-Bibliothek selbst verlangt ab 2.2.0 PHP 8.5 und WordPress 7.0; ein Add-on, dessen Kern ein älteres WordPress erlaubt, muss selbst mindestens 7.0 verlangen.

## Offene Verifikation

- Die Affiliate-Captain-WooCommerce-Integration ist optional und muss separat gegen Classic Checkout, Blocks und HPOS geprüft werden (Aufgabe des Kern-Repositorys).
- Die Client-Bibliothek (`src/Client/`) verlangt WordPress 7.0; Affiliate Captain erlaubt laut Stand 16.11. noch 6.3, Voucher und Pass Captain 6.4. Ob Kerne und Add-ons in captain-pro 7.0 als Minimum deklarieren, ist dort zu prüfen.
- Die Readme-Versionen sind gegen die aktuellen unterstützten WordPress-/WooCommerce-Releases erneut zu prüfen, bevor ein öffentlicher Supportzeitraum zugesagt wird.
