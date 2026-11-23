# Einstellungen: WP License Captain (Lizenzserver) und Lizenz-Client / Add-on-Kernel

Stand: 23.11.2026. Geprüft wurden frische Klone von `hastla007/wp-license` (main, `76009d9`) und `hastla007/captain-pro` (main, `4abdee4`). Nur gelesen, nichts geändert. Alle Pfade ohne Zusatz beziehen sich auf wp-license; Pfade mit `captain-pro:` auf das Monorepo. Die Client-Bibliothek in `captain-pro:packages/license-client` ist eine unveränderte Kopie von `src/Client` (`captain-pro:packages/license-client/SOURCE.md`, Bibliothek 2.2.0); Zeilenangaben zum Client nennen deshalb `src/Client/…`.

## 0. Zusammenfassung

1. **Urteil:** Der Server ist technisch sehr gut vorbereitet, aber für einen Käufer ohne eigenen Entwickler kaum anpassbar. Es gibt 7 Felder auf der Einstellungsseite; rund 90 geschäftliche Regeln stehen als Konstante oder nur als PHP-Filter im Code.
2. **Lücke 1 – Fristen der Verlängerung:** Kulanzfrist (14 Tage), Wiederholungsplan der Abbuchung (Tag 1, 3, 7), Fenster der manuellen Verlängerung (30 Tage) sind nur per Filter oder gar nicht änderbar.
3. **Lücke 2 – Recht je Land:** Aufbewahrung (10 Jahre, deutsches Recht als Vorgabe), Wortlaut der Widerrufs-Zustimmung und der Zustimmung zur automatischen Verlängerung sind nur per Filter bzw. fest im Code. Kein Länderprofil.
4. **Lücke 3 – Regeln für Kunden-Websites:** Staging-Erkennung (10 Namensteile, 16 Hoster-Endungen), 1 Staging je Live-Website, Entwicklungs-Websites (mindestens 5), Offline-Frist (14 Tage) sind fest oder nur per Filter einstellbar.
5. **Lücke 4 – Marke des Herstellers:** Schlüssel-Präfix `WPLC-`, Texte des Kundenkontos, Installationsschritte, Support-Adresse und die Lizenzseite des Clients lassen sich nur im Code anpassen; ein „White Label“ der Lizenzseite gibt es nicht.
6. **Lücke 5 – Betrieb:** kein Import/Export der Einstellungen, kein Zurücksetzen, kein WP-CLI-Befehl für Einstellungen, kein Empfänger für Gesundheits-Meldungen (nur ein PHP-Hook), keine ausgehenden Webhooks.
7. **Vorhanden und gut:** Rollen (10 eigene Berechtigungen), Audit der Einstellungsänderungen, Diagnoseseite, 15 E-Mails über WooCommerce (Betreff, Überschrift, Zusatztext, Vorschau), Sprache der E-Mail je Kauf, Einstiegs-Liste in 7 Schritten.
8. **Editionen:** Empfehlung ist eine einzige Edition des Servers; Tarife unterscheiden sich nur in der Zahl der Shops und im Support. Eine Sperre von Funktionen über die eigene Lizenz widerspräche der festen Regel aus `docs/PRODUCT-PLAN.md` (PROD-06: „Die Lizenz steuert nur Updates und Support, nie die Funktion“). Entscheidung des Inhabers: INH-10.
9. **Client beim Endkunden:** Er sieht heute nur Schlüsselfeld und Schaltflächen, keine Einstellung. Beta-Kanal und Umgebung (Live/Staging) kann er nicht selbst wählen; kein Add-on setzt `channel`.
10. **In Arbeit (nicht auf main):** „gesperrt gilt als beendet nach X Tagen“ und „Höchstmenge je Bestellposition“ werden gerade als Einstellungen gebaut; sie sind unten nur als „in Arbeit“ geführt.

Zahlen: 7 Felder der Einstellungsseite, 76 WooCommerce-E-Mail-Felder (15 E-Mails), 9 Felder je Produkt, 28 Konfigurations-Konstanten in `wp-config.php`, 10 Berechtigungen, 90 Regeln in Abschnitt 1.7 (88 heute nur als Konstante, Filter oder fremde Einstellung im Code, 2 in Arbeit), Client/Kernel: 10 Build-Werte, 7 Filter, 7 feste Werte (davon 0 als Einstellung für den Endkunden). 46 Aufgaben (8 kritisch, 28 wichtig, 10 Optimierung), 11 Entscheidungen des Inhabers.

---

## 1. Bestand

### 1.1 Wie Einstellungen heute gebaut sind

- **Eine Option, WordPress Settings API.** `wplc_settings` (Array), registriert in `src/Admin/Settings.php:290`, Vorgaben in `Settings::defaults()` (`:171`), Prüfung in `Settings::sanitize()` (`:375`). Seite „Lizenzeinstellungen“ unter dem WooCommerce-Menü (`:272`), ohne Reiter.
- **Kein gemeinsames Register.** Vorgabe, Prüfung, Feld und Hilfetext stehen je Einstellung an vier Stellen derselben Datei. Mehrere Leser greifen an der Klasse vorbei direkt auf die Option zu: `src/Notifications/Notifications.php:554` und `:580`, `src/Catalog/ReleaseCatalog.php:52`, `src/Billing/PriceChange.php:92`. Ein Schema für Export oder WP-CLI gibt es nicht (Suche nach `settings.*(export|import|reset)|preset` in `src/`: keine Treffer außer Kommentaren).
- **Änderungsprotokoll vorhanden.** Jede Änderung schreibt `settings_updated` ins Audit-Protokoll (`Settings.php:109`); Werte werden nur für 6 Schlüssel mitgeschrieben (`AUDITED_VALUES`, `:78`), der Absenderhinweis nur dem Namen nach.
- **Geheimnisse nur in `wp-config.php`** (bewusste Entscheidung, Hinweis auf der Seite `Settings.php:604`).
- **Je Produkt:** Felder im WooCommerce-Produkt (`src/Catalog/ProductEditor.php`), gespeichert als Produkt-Meta `_wplc_*` und in der Tabelle der Tarife.
- **E-Mails:** als WooCommerce-E-Mails (`src/Notifications/Emails/LicenseEmail.php:31` erweitert `WC_Email`), einstellbar unter WooCommerce → Einstellungen → E-Mails.
- **Alles Übrige:** Klassen-Konstanten und `apply_filters()`. 111 Filter in `src/` (Liste: `docs/EXTENSION-POINTS.md`).

### 1.2 Einstellungsseite „Lizenzeinstellungen“ (Option `wplc_settings`, global je Shop)

Berechtigung für alle: `wplc_manage_settings` (Vorgabe: wer `manage_woocommerce` hat; `src/Admin/Capabilities.php:68`). Ausnahme: Paket-Hosts nur mit `wplc_manage_package_hosts` (Vorgabe `manage_options`, `:71`).

| Nr. | Schlüssel | Abschnitt | Feld gerendert | Typ / Grenzen | Vorgabe | Filter dazu |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `package_hosts` | Updates | `Settings.php:913` | Liste von Hostnamen, höchstens 50 (`:487`) | leer | `wplc_update_package_hosts` (`src/Api/V1/UpdateService.php:692`) |
| 2 | `catalog_auto_import` | Updates | `Settings.php:950` | Kästchen | an | `wplc_catalog_auto_import` (`src/Catalog/ReleaseCatalog.php:60`) |
| 3 | `combined_renewals` | Automatische Verlängerung | `Settings.php:962` | Kästchen | aus | `wplc_combined_renewals` (`src/Billing/CombinedRenewal.php:69`) |
| 4 | `reminder_days` | Kunden-E-Mails | `Settings.php:940` | Liste, 1–365, höchstens 6 Werte (`:518`, `:527`) | `30,7` | `wplc_reminder_lead_days` (`src/Notifications/Notifications.php:570`) |
| 5 | `renewal_notice_days` | Kunden-E-Mails | `Settings.php:984` | Zahl 1–60 (`:453`) | 7 | `wplc_renewal_notice_lead_days` (`Notifications.php:592`) |
| 6 | `price_change_notice_days` | Kunden-E-Mails | `Settings.php:974` | Zahl 30–365 (`src/Billing/PriceChange.php:49`, `:54`) | 60 (`:44`) | `wplc_price_change_notice_days` (`PriceChange.php:104`) |
| 7 | `sender_note` | Kunden-E-Mails | `Settings.php:994` | Text, höchstens 500 Zeichen (`:460`) | leer | – |

Nur zur Anzeige (kein Feld): Status-Tabelle mit Schema-Version, Stripe-Webhook, Schlüsseln, Kulanzfrist (`Settings.php:854`) und Anonymisierungsfrist (`:861`, mit Verweis auf den Filter `wplc_retention_days`).

### 1.3 WooCommerce → Einstellungen → E-Mails (je E-Mail, global je Shop, Berechtigung `manage_woocommerce`)

15 E-Mails (`src/Notifications/Notifications.php:34`): Lizenz ausgestellt, Ablauf-Erinnerung, Ankündigung der Verlängerung, Zahlung fehlgeschlagen, Zahlungsmittel fehlt, Lizenz abgelaufen, Lizenz verlängert, Kündigung bestätigt, Lizenz gesperrt, Lizenz widerrufen, Preisänderung, Preisänderung zurückgenommen, Einladung eines Mitnutzers, Angebot einer Übertragung, Lizenz übertragen.

Je E-Mail die fünf Standardfelder von WooCommerce (aktiv, Betreff, Überschrift, Zusatztext, Format) = 75 Felder; die Klasse `LicenseEmail` ändert sie nicht (Suche nach `init_form_fields` in `src/Notifications/Emails/`: nur ein Treffer). Dazu ein eigenes Feld:

| Nr. | Feld | Stelle | Typ | Vorgabe |
| --- | --- | --- | --- | --- |
| 76 | `include_full_key` („Vollen Lizenzschlüssel in die E-Mail aufnehmen“), nur bei „Lizenz ausgestellt“ | `src/Notifications/Emails/LicenseIssuedEmail.php:64` | Kästchen | aus |

Vorlagen liegen unter `templates/emails/` (18 HTML, 18 Text) und sind im Theme unter `wp-license/emails/` überschreibbar (`LicenseEmail.php:28`). Vorschau mit Beispieldaten: `LicenseEmail.php:180`.

### 1.4 Felder je Produkt / Variante (Produkt-Editor)

Berechtigung: Produkt bearbeiten **und** `wplc_manage_catalog` (`src/Catalog/ProductEditor.php:498`).

| Nr. | Feld | Stelle | Typ / Grenzen | Vorgabe |
| --- | --- | --- | --- | --- |
| 1 | `_wplc_is_license_product` | `ProductEditor.php:209` | Kästchen | aus |
| 2 | `_wplc_product_key` | `:219` | Text (Schlüssel aus dem Katalog) | leer |
| 3 | `_wplc_product_display_name` | `:245` | Text | leer |
| 4 | `_wplc_plan_key` | `:257` | Text | leer |
| 5 | `_wplc_billing_interval` | `:271` | Auswahl: nur `month` oder `year` (`:615`, `src/Catalog/Catalog.php:178`) | `year` |
| 6 | `_wplc_live_site_limit` | `:282` | Zahl 1–1000 (`:661`) | 1 |
| 7 | `_wplc_support_level` | `:295` | Auswahl `standard` / `priority` (`:300`) | `standard` |
| 8 | Rechte des Tarifs (Kästchen aus dem Katalog) | `:339` | Mehrfachauswahl | keine |
| 9 | `_wplc_entitlements_json` (weitere Rechte) | `:312` | JSON | leer |

Es fehlen je Tarif: Zahl der Staging- und Entwicklungs-Websites, Kulanzfrist, Offline-Frist, Zahl der Mitnutzer, Beta-Zugang, Höchstmenge (in Arbeit).

### 1.5 Konstanten in `wp-config.php` (global je Installation; nur wer die Datei ändern darf)

| Nr. | Konstante | Stelle | Zweck |
| --- | --- | --- | --- |
| 1–2 | `WPLC_LICENSE_KEY_SECRETS`, `WPLC_LICENSE_KEY_SECRET` | `src/License/LicenseKey.php:183` | Geheimnis(se) der Lizenzschlüssel |
| 3 | `WPLC_LICENSE_KEY_ID` | `LicenseKey.php:221` | aktueller Schlüssel des Rings |
| 4–5 | `WPLC_INSTALLATION_ENCRYPTION_KEYS`, `…_KEY` | `src/Security/SecretCrypto.php:143` | Verschlüsselung der Installations-Geheimnisse |
| 6 | `WPLC_INSTALLATION_ENCRYPTION_KEY_ID` | `SecretCrypto.php:181` | aktueller Schlüssel |
| 7 | `WPLC_RESPONSE_SIGNING_KEY` | `src/Security/ResponseSigner.php:160` | Signaturschlüssel der Antworten |
| 8–10 | `WPLC_STRIPE_WEBHOOK_SECRET`, `…_TEST`, `…_SECRETS` | `src/Api/V1/StripeWebhook.php:335`–`338` | Stripe-Webhook, auch je Site eines Netzwerks |
| 11 | `WPLC_RATE_LIMIT_KEY` | `src/Api/V1/Authenticator.php:338` | Schlüssel für die Zähler der Anfragegrenzen |
| 12–13 | `WPLC_TRUSTED_PROXIES`, `WPLC_TRUSTED_PROXY_HEADER` | `src/Api/V1/ClientIp.php:30`–`33` | Ermittlung der Client-Adresse hinter einem Proxy |
| 14 | `WPLC_PACKAGE_STORAGE_DIR` | `src/Update/LocalPackageStorage.php:55` | Ordner der Pakete |
| 15–20 | `WPLC_S3_BUCKET`, `…_ACCESS_KEY_ID`, `…_SECRET_ACCESS_KEY`, `…_REGION`, `…_ENDPOINT`, `…_PATH_STYLE` | `src/Update/S3Storage.php:87`–`92` | Objektspeicher (S3, R2) |
| 21 | `WPLC_PAUSE_BILLING` | `src/Maintenance/SiteGuard.php:100` | Abbuchungen anhalten (z. B. Kopie der Website) |
| 22 | `WPLC_DEBUG` | `src/Infrastructure/Log.php:71` | ausführliches Protokoll |
| 23 | `WPLC_DISABLE_UPDATE_CHECK` | `src/Infrastructure/SelfUpdateCheck.php:65` | Prüfung auf neue Server-Version aus |
| 24 | `WPLC_REMOVE_ALL_DATA` | `uninstall.php:130` | Deinstallation löscht Tabellen und Optionen |
| 25–28 | `WPLC_VENDOR_SERVER_URL`, `…_PRODUCT_KEY`, `…_PUBLIC_KEYS`, `…_ACCOUNT_URL` | `src/Infrastructure/SelfLicense.php:81`–`84` | Lizenz und Updates des Servers selbst |
| 29–31 | `WPLC_CONTRACT_VERSION`, `WPLC_TESTS_IGNORE_PHP_MINIMUM`, `WPLC_FILE`/`WPLC_PATH`/`WPLC_VERSION` | Suche `defined( 'WPLC_` | technisch, keine Einstellung |

### 1.6 Berechtigungen (nur per Rolle/Filter, keine Oberfläche)

`src/Admin/Capabilities.php:61`–`75`, Filter `wplc_capability` (`:98`):

| Berechtigung | Vorgabe |
| --- | --- |
| `wplc_view_licenses`, `wplc_manage_licenses`, `wplc_grant_licenses`, `wplc_manage_catalog`, `wplc_view_diagnostics`, `wplc_manage_settings` | wer `manage_woocommerce` hat |
| `wplc_restore_licenses`, `wplc_publish_releases`, `wplc_manage_package_hosts`, `wplc_change_prices` | wer `manage_options` hat |

Eine Seite, auf der ein Shop Rollen zuordnet, gibt es nicht; es braucht ein Rollen-Plugin oder Code.

### 1.7 Geschäftliche Regeln, die heute nur Konstante oder Filter sind

„F“ = per PHP-Filter änderbar, „K“ = fest im Code. Gruppiert nach Thema.

**A. Laufzeit, Verlängerung, Zahlung**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| A1 | Kulanzfrist nach Ende der bezahlten Zeit (Zugang bleibt bei laufender/fehlgeschlagener Abbuchung) | `src/License/LicenseLifecycle.php:53`, Grenzen `:56`–`57`, Filter `wplc_renewal_grace_days` `:280` | 14 Tage, 1–90 | F |
| A2 | Wiederholungsplan fehlgeschlagener Abbuchungen (Tage nach Beginn der Periode; Zahl der Versuche = Länge der Liste) | `src/Billing/RenewalProcessor.php:83`, Filter `wplc_renewal_retry_days` `:866` | 1, 3, 7 (drei Wiederholungen) | F |
| A3 | Abbuchung nur nach belegter Vorab-Ankündigung | `src/Notifications/Notifications.php:519` (`wplc_renewal_requires_advance_notice`) | ja | F |
| A4 | Fenster der manuellen Verlängerung vor und nach dem Ende (auch Reaktivierung einer abgelaufenen Lizenz) | `src/License/LicenseStateMachine.php:23` | 30 Tage | K |
| A5 | „Endet bald“ für den Umzug von Websites auf eine andere Lizenz | `src/License/ActivationTransfer.php:37` | 30 Tage (= A4) | K |
| A6 | Aufschub des Ablaufs bei offenem Prüffall | `src/License/LicenseLifecycle.php:1150` | 30 Tage | K |
| A7 | Laufzeiten | `src/Catalog/Catalog.php:178`, `src/Catalog/ProductEditor.php:615`, Anzeige `src/Catalog/TermDisplay.php:43` | nur 1 Monat oder 1 Jahr; keine Mehrjahres-, Dauer- oder Testlizenz | K |
| A8 | Anteilige Berechnung beim Tarifwechsel nach oben | `src/License/PlanChange.php:71`–`90` | (neuer − alter Preis) × Restzeit ÷ Periode, taggenau | K |
| A9 | Gültigkeit eines Upgrade-Angebots | `src/Billing/PlanUpgrade.php:64` | 24 Stunden | K |
| A10 | Tarifwechsel nach unten | `src/Billing/PlanUpgrade.php:3`, `:24` | erst zur nächsten Verlängerung, keine Gutschrift | K |
| A11 | Zahlungsart der automatischen Verlängerung | `src/Gateway/StripeRecurringAdapter.php:464` | nur Karte über Stripe | K (Adapter per `wplc_recurring_gateway_adapters`, `src/Gateway/Registry.php:26`) |
| A12 | Sammelverlängerung: höchstens Positionen je Bestellung | `src/Billing/CombinedRenewal.php:52` | 20 | K |
| A13 | Ausstellung der Lizenz erst nach Abschluss bei bestimmten Zahlungsarten | `src/License/LicenseIssuer.php:285` (`wplc_issue_after_completion_gateways`) | `cod` (Nachnahme) | F |
| A14 | Manuelle Verlängerung für nicht mehr verkaufte Produkte | `src/Billing/ManualRenewal.php:88` | per Filter | F |
| A15 | Admin darf Verlängerungsbetrag erhöhen | `src/Admin/SupportActions.php:524`, `src/Billing/RenewalOrder.php:119` | per Filter | F |
| A16 | Hinweis „verlängern statt neu kaufen“ ausblendbar für | `src/Billing/RenewInsteadNotice.php:44` | 30 Tage | K |
| A17 | Verlängerung abschalten, wenn die gespeicherte Karte gelöscht wird | `src/Maintenance/Orphans.php:187` | per Filter | F |
| A18 | Schutz nach Ausfall des Servers (Halten/Toleranz) | `src/Maintenance/GapGuard.php:123`, `:129`; `src/Maintenance/GapReconciler.php:55` | abgeleitet aus A1/A2; Abgleich 30 Tage | F |

**B. Rückerstattung, Sperre, Kündigung**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| B1 | Volle Rückerstattung → Lizenz widerrufen | `src/License/LicenseStateMachine.php:27`–`31`, `src/License/LicenseLifecycle.php:77` | immer | K |
| B2 | Teilrückerstattung nach Stückzahl → so viele Lizenzen widerrufen; reine Betrags-Rückerstattung → keine Wirkung | `LicenseLifecycle.php:169`–`170` | fest | K |
| B3 | Rückbuchung (Chargeback) → gesperrt | `LicenseStateMachine.php:39`–`42` | immer; nach gewonnenem Streit bleibt gesperrt, bis ein Admin freigibt (`src/Gateway/StripeEventHandler.php:137`) | K |
| B4 | Bestellung storniert/fehlgeschlagen → gesperrt | `LicenseLifecycle.php:730` (`wplc_suspend_license_on_order_status`) | ja | F |
| B5 | Bestellposition entfernt/Menge gesenkt → gesperrt | `LicenseLifecycle.php:852` (`wplc_suspend_license_on_order_item_change`) | ja | F |
| B6 | Öffentliche Kündigungsseite: Versuche je Stunde | `src/Account/PublicCancellation.php:63`, Filter `:219` | 5 | F |
| B7 | Gesperrte Lizenz gilt nach X Tagen als beendet | – | **in Arbeit** (nicht auf main; Suche `suspended.{0,30}(ended|days)` ohne Treffer für eine Frist) | – |
| B8 | Höchstmenge je Bestellposition | – | **in Arbeit** (Suche `max_quantity|sold_individually` in `src/`: keine Treffer) | – |

**C. Websites, Staging, Aktivierung**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| C1 | Namensteile, die eine Staging-Kopie kennzeichnen | `src/Api/V1/SiteUrl.php:36` | `staging, stage, stg, dev, test, testing, preprod, preview, sandbox, uat` | K |
| C2 | Hoster-Endungen für Staging | `SiteUrl.php:41` | 16 Endungen (WP Engine, Kinsta, Pantheon, Cloudways, Flywheel, SiteGround, WordPress.com, InstaWP, TasteWP, RunCloud, ngrok …) | K |
| C3 | Lokale Endungen (Entwicklung) | `SiteUrl.php:63` | `.localhost, .test, .local, .internal, .lan, .home.arpa, .localdomain, .ddev.site, .lndo.site` | K |
| C4 | Wann ein Host als Staging einer Live-Website gilt | `SiteUrl.php:305` (`wplc_is_staging_host`), Domain-Ermittlung `:263` (`wplc_registrable_domain`) | gleiche Hauptdomain, lokal oder Hoster-Endung; sonst zählt die Kopie als Live-Website | F |
| C5 | Staging-Websites je Live-Website | `src/Api/V1/ActivationService.php:276`–`278` | genau 1, zählt nicht gegen das Limit | K |
| C6 | Entwicklungs-Websites je Lizenz | `ActivationService.php:53`, Filter `wplc_development_site_limit` `:644` | das Doppelte des Live-Limits, mindestens 5; nur lokale Hosts (`wplc_is_local_development_url`, `:620`) | F |
| C7 | Multisite: jede Unterseite zählt als eine Website (Entscheidung B2) | `docs/MULTISITE.md` (Abschnitt „one site per subsite“), Client-Vorgabe `network => false` (`src/Client/LicenseManager.php:162`) | fest; Netzwerk-Modus ist Wahl des Produkts | K (je Produkt im Build) |
| C8 | Live-Limit | je Tarif (Feld 6 in 1.4), 1–1000 | je Tarif | Einstellung |
| C9 | Umzug einer Aktivierung auf eine andere Lizenz desselben Kunden | `src/License/ActivationTransfer.php:28`–`29` | nur von Hand, je Website ein Klick; **keine Wartezeit, keine Höchstzahl** (Suche `cool` in `src/`: keine Treffer) | K |
| C10 | Website im Client umziehen / freigeben | `src/Client/LicenseManager.php:892`, `:902` | beliebig oft, mit Bestätigung | K |
| C11 | Neue Installationen je Lizenz und Stunde | `ActivationService.php:47`, Filter `:668` | das Doppelte des Live-Limits, mindestens 10 | F |
| C12 | Aktive Installationen einer beendeten Lizenz abschalten nach | `src/Maintenance/Retention.php:56` | 30 Tage | F (`wplc_retention_days`) |

**D. Client-Verhalten, Updates, Downloads**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| D1 | Offline-Frist: so lange gilt die letzte signierte Antwort | `src/Api/V1/EntitlementService.php:25`, Filter `wplc_offline_cache_days` `:316` | 14 Tage | F |
| D2 | Toleranz der Uhr des Clients über die Offline-Frist hinaus | `src/Client/LicenseManager.php:62`, `:67`, Filter `{prefix}_license_offline_tolerance` `:496` | 1 Tag, höchstens 7 | F (im Add-on) |
| D3 | Update-Kanäle | `src/Update/ReleaseRegistry.php:32`–`33`, `:166`, `:179` | `stable` und `beta`; Beta erhält auch stabile Versionen; der Server gibt Beta **jedem** Client, der danach fragt (`src/Api/V1/UpdateService.php:80`), ohne Recht oder Tarif zu prüfen | K |
| D4 | Lebensdauer der Download-Freigabe (Update) | `src/Api/V1/UpdateService.php:39` | 300 Sekunden; höchstens 2 offene je Paket (`:42`) | K |
| D5 | Lebensdauer des Download-Links (Stream) | `src/Update/PackageStream.php:37` | 60 Sekunden, einmalig; Wiederverwendung `wplc_download_reuse_seconds` `:150` (Vorgabe 0, höchstens 600) | K / F |
| D6 | Lebensdauer vorab signierter Objektspeicher-Links | `src/Update/S3Storage.php:40`, Filter `wplc_s3_presign_expires` `:243` | 300 Sekunden (1 s bis 7 Tage) | F |
| D7 | Download nach Ablauf: Versionen aus der bezahlten Zeit bleiben ladbar | `src/Update/CustomerDownload.php:84` (`wplc_expired_license_download`) | ja | F |
| D8 | Zahl der wählbaren Versionen im Kundenkonto | `CustomerDownload.php:34`, Filter `:177` | 10 (1–50) | F |
| D9 | Downloads je Lizenz und Stunde im Kundenkonto | `CustomerDownload.php:33`, Filter `:302` | 10 (1–1000) | F |
| D10 | Höchstgröße eines Pakets (Upload / Client) | `src/Update/LocalPackageStorage.php:25`, Filter `:266`; `src/Client/Updater.php:57`, Filter `:589` | 50 MB / 100 MB | F |
| D11 | Aufbewahrung der Download-Freigaben (Verlauf) | `UpdateService.php:469` (`wplc_download_grant_retention_days`) | 7 Tage | F |
| D12 | Abstand der Prüfungen des Clients | `src/Client/LicenseManager.php:653` (täglich), `:51` (Nachholen nach 36 h), `src/Client/Updater.php:47` (Update-Prüfung 12 h) | fest | K |

**E. Schlüssel, Mitnutzer, Übertragung**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| E1 | Format des Lizenzschlüssels | `src/License/LicenseKey.php:34` | `WPLC-` + UUID + `-` + 64 Hex-Zeichen (106 Zeichen); Präfix fest; Platzhalter im Client `WPLC-…` (`src/Client/LicenseManager.php:858`) | K |
| E2 | Wechsel der Schlüssel (Lizenz-, Verschlüsselungs-, Signaturschlüssel) | `src/CLI/RekeyLicensesCommand.php`, `src/CLI/RotateInstallationSecretsCommand.php`, `src/CLI/SigningKeyCommand.php`, `src/Security/ResponseSigner.php:212` | nur von Hand per WP-CLI; **keine Frist, keine Erinnerung** (Suche nach `days|DAY_IN` in den drei CLI-Dateien und in `src/License/LicenseKeyRotation.php`: keine Treffer) | K |
| E3 | Mitnutzer je Lizenz | `src/License/LicenseMembers.php:47`, Filter `wplc_license_member_limit` `:199` | 5 | F |
| E4 | Gültigkeit einer Einladung / eines Übertragungsangebots | `LicenseMembers.php:52`, Filter `:217` | 14 Tage | F |
| E5 | Übertragung an einen anderen Kunden erlaubt | `LicenseMembers.php:514` (`wplc_license_transfer_enabled`) | ja | F |
| E6 | Neuer Schlüssel bei Übertragung | `LicenseMembers.php:778` (`wplc_license_transfer_new_key`) | nein | F |
| E7 | Inhaber bei Gastbestellung | `src/License/LicenseIssuer.php:317` | per Filter | F |

**F. Datenschutz, Aufbewahrung, Sicherheit**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| F1 | Audit-Protokoll | `src/Maintenance/Retention.php:51` | 730 Tage | F (`wplc_retention_days`, `:235`; 0 schaltet ab, höchstens 36500 `:107`) |
| F2 | Verarbeitete Zahlungsereignisse | `Retention.php:52` | 180 Tage | F |
| F3 | Ungelöste Zahlungsereignisse | `Retention.php:53` | 730 Tage | F |
| F4 | Deaktivierte Installationen beendeter Lizenzen | `Retention.php:54` | 365 Tage | F |
| F5 | Deaktivierte Installationen laufender Lizenzen | `Retention.php:55` | 365 Tage | F |
| F6 | E-Mail-Protokoll | `Retention.php:57` | 730 Tage | F |
| F7 | Anonymisierung beendeter Lizenzen | `Retention.php:58`, Jahresende-Regel `:66` | 3650 Tage ab Ende des Jahres (Begründung im Code: § 147 AO / § 257 HGB; Österreich 7 Jahre) | F |
| F8 | Nachweise der Zustimmung zur Verlängerung | `Retention.php:59` | 3650 Tage ab Jahresende | F |
| F9 | Zähler der Anfragegrenzen | `Retention.php:95`, `src/Api/V1/Authenticator.php:49` | 2 Tage | K (technisch) |
| F10 | Anfragen je Installation und Minute | `src/Api/V1/Authenticator.php:26` | 120 | K |
| F11 | Fehlgeschlagene Anmeldungen je Adresse und Minute | `Authenticator.php:43` | 100 | K |
| F12 | Fehlgeschlagene Aktivierungen je Adresse / je Schlüssel-Präfix und Minute | `src/Api/V1/ActivationService.php:32`, `:44` | 30 / 20 | K |
| F13 | Zeitfenster signierter Anfragen | `Authenticator.php:29` | 300 Sekunden | K (technisch) |
| F14 | Protokoll abgelehnter API-Anfragen, Debug-Protokoll | `src/Api/V1/ApiLog.php:122` (`wplc_log_api_rejection`), `src/Infrastructure/Log.php:78` (`wplc_log_debug`) | per Filter / Konstante | F |
| F15 | Deinstallation behält Daten | `uninstall.php:5`–`26` | behalten; löschen nur mit `WPLC_REMOVE_ALL_DATA` | K (Konstante) |

**G. Recht, Preis, Kasse**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| G1 | Zustimmung zum Erlöschen des Widerrufsrechts: Kästchen an/aus | `src/Billing/WithdrawalConsent.php:90` | an, außer Germanized oder German Market ist aktiv (`:116`) | F |
| G2 | … Pflicht oder freiwillig | `WithdrawalConsent.php:107` | Pflicht | F |
| G3 | … Wortlaut | `WithdrawalConsent.php:125`, Filter `:134` | ein englischer Quelltext, übersetzt über die Sprachdatei | F |
| G4 | Wortlaut der Zustimmung zur automatischen Verlängerung | `src/Billing/RecurringConsent.php:119` | fest im Code (kein Filter in `RecurringConsent.php`; Suche `apply_filters` dort ohne Treffer) | K |
| G5 | Zustimmung im Block-Checkout an/aus | `src/Billing/BlockCheckoutConsent.php:78`, `:143` | per Filter | F |
| G6 | Anzeige des Verlängerungspreises brutto/netto | `src/Notifications/RenewalTerms.php:115` | folgt der WooCommerce-Einstellung „Preise im Warenkorb anzeigen“; Betrag filterbar (`:195`) | WooCommerce |
| G7 | Laufzeit am Preis anzeigen („für 1 Jahr“) | `src/Catalog/TermDisplay.php:75` (`wplc_show_license_term`) | ja | F |
| G8 | Währung | `src/Catalog/Catalog.php:394`, Filter `:404`; Prüfung `src/Billing/RenewalOrder.php:375`; Nachkommastellen `src/Billing/Money.php:147` | Währung des Shops; Verlängerung in der Währung der Lizenz; bei Abweichung keine Abbuchung | F |

**H. Texte, Kundenkonto, Meldungen**

| Nr. | Regel | Stelle | Heutiger Wert | F/K |
| --- | --- | --- | --- | --- |
| H1 | Support-Adresse in E-Mails | `src/Notifications/Notifications.php:1264`, Filter `:1274` | Absender der Shop-E-Mails, sonst Admin-Adresse | F |
| H2 | Sprache der E-Mails einer Lizenz | `src/Notifications/PurchaseLocale.php:25`, Filter `:98` | Sprache des Kaufs, sonst Sprache des Kundenkontos | F |
| H3 | Empfänger der E-Mails einer Lizenz | `src/Notifications/Emails/LicenseEmail.php:168` | Inhaber | F |
| H4 | Installationsschritte, Link zur Dokumentation, Name des Tarifs | `Notifications.php:1202`, `:1224`, `:1253` | Standardtext / leer / aus dem Schlüssel gebildet | F |
| H5 | Adresse der Lizenzseite im Kundenkonto | `src/Account/CustomerPortal.php:47`, Filter `:132`; Seite „Websites“ `src/Account/SiteOverview.php:35` | `wplc-licenses`, `wplc-sites` | F / K |
| H6 | Warnschwellen im Kundenkonto („nicht gesehen“, „läuft bald ab“) | `src/Account/SiteOverview.php:50`, `:55`; Admin-Liste `src/Admin/LicenseListTable.php:42` | 14 / 30 / 30 Tage | K |
| H7 | Wiederholung nicht angenommener E-Mails | `Notifications.php:58` | nach 15 min, 2 h, 12 h | K (technisch) |
| H8 | Gesundheits-Meldungen: Schwellen | `src/Admin/Diagnostics.php:665` (`wplc_health_alert_thresholds`) | 12 Kennzahlen, Schwelle 1 (abgelehnte Webhooks: 5) | F |
| H9 | Gesundheits-Meldungen: Empfänger | `Diagnostics.php:613`, `:640` (Hook `wplc/health_alert`) | **kein Empfänger**: nur ein PHP-Hook, kein E-Mail-Versand (Suche `wp_mail(` in `src/` außerhalb des Clients: keine Treffer) | K |
| H10 | Ereignisse für andere Systeme | `docs/LICENSE-EVENTS.md`, 47 `do_action( 'wplc/…' )` | nur PHP-Hooks; **keine ausgehenden Webhooks** (Suche `wp_remote_post` in `src/`: nur `S3Storage.php` und Client) | K |

### 1.8 Client-Bibliothek und Add-on-Kernel (läuft im WordPress des Endkunden)

**Was der Endkunde sieht** (`src/Client/LicenseManager.php:812`–`914`; im Kernel eingehängt unter dem Menü des Kerns als „Pro-Lizenz“, `captain-pro:packages/addon-kernel/src/Addon.php:889`–`899`):

- Anzeige: Status, Grund, bezahlt bis, Zugang bis, Websites belegt/erlaubt, Umgebung mit Installations-ID, letzte Prüfung, letzter Fehler.
- Eingabe: ein Feld für den Lizenzschlüssel.
- Schaltflächen: Aktivieren, Jetzt prüfen, Diese Website deaktivieren (mit Bestätigung), Umziehen (mit Bestätigung), Lokale Daten vergessen.
- Kernel zusätzlich: Tabelle „Premium-Funktionen“ mit Status je Funktion (`Addon.php:942`–`962`).
- Hinweise im Admin (`LicenseManager.php:1008`), Abschnitt und Test unter „Website-Zustand“ (`:1127`, `:1266`).
- **Keine einzige speicherbare Einstellung.** Kein Feld für Kanal, Umgebung, Hinweise oder automatische Updates.

**Was der Hersteller im Build festlegt** (Konstruktor `LicenseManager.php:129`–`186`, Kernel `Addon.php:124`–`191`):

| Nr. | Stellschraube | Stelle | Vorgabe |
| --- | --- | --- | --- |
| 1 | `server_url`, `fallback_urls`, `rest_base` | `LicenseManager.php:135`, `:150`, `:151` | Pflicht / leer |
| 2 | `public_keys` | `:136` | Pflicht |
| 3 | `name`, `text_domain` | `:137`–`138` | Produktschlüssel |
| 4 | `network` (Multisite: eine Aktivierung je Netzwerk) | `:140`, `:162` | aus (Entscheidung B2) |
| 5 | `channel` | `:142`, `:172`; gesendet in `src/Client/Updater.php:815` | `stable`; kein Captain-Add-on setzt ihn (Suche `'channel'` in `captain-pro:addons/*/config.php`: keine Treffer) |
| 6 | `settings_parent`, `settings_url` | `:144`–`147`, Kernel `Addon.php:490` | Einstellungen-Menü; im Kernel unter dem Kern-Menü |
| 7 | `capability` | `:148`, `:175` | `manage_options` (Netzwerk: `manage_network_options`) |
| 8 | `account_url` | `:149` | leer |
| 9 | Kernel: `core` (Mindestversion des Kerns), `features`, `boot_priority` | `Addon.php:136`–`159` | – |
| 10 | Kernel: `own_options`, `retained_options`, Konstante `{PREFIX}_REMOVE_ALL_DATA` | `Addon.php:148`–`155`, `:367` | Daten mit Aufbewahrungspflicht bleiben |

**Filter im Add-on** (für Entwickler, nicht für Endkunden): `{prefix}_license_server_url` (`LicenseManager.php:213`), `{prefix}_license_public_keys` (`:228`, nur Entwicklungs-Build), `{prefix}_license_allow_local_server` (`:244`), `{prefix}_license_offline_tolerance` (`:496`), `{prefix}_license_settings_url` (`:792`), `{prefix}_license_show_notice` (`:1028`), `{prefix}_license_max_package_bytes` (`src/Client/Updater.php:589`).

**Feste Werte im Client:** tägliche Prüfung (`LicenseManager.php:653`), Nachholen nach 36 h (`:51`), Uhr-Toleranz 5 min (`:56`), Sperre zwischen zwei Prüfungen 15 min (`:72`), Update-Prüfung 12 h / bei Fehler 1 h (`Updater.php:47`, `:52`), Paket höchstens 100 MB (`:57`), offene Aktivierung 24 h (`src/Client/LicenseClient.php:52`).

**Staging im Client:** Die Umgebung wird aus `wp_get_environment_type()` abgeleitet (`src/Client/LicenseClient.php:405`–`408`); „staging“ wird Staging, lokale Typen werden Entwicklung. Der Endkunde kann das nicht korrigieren. Eine Kopie mit kopierten Zugangsdaten erkennt der Client an der geänderten Adresse (`LicenseManager.php:875`) und bietet „als Kopie aktivieren“ oder „umziehen“ an.

---

## 2. Bewertung für den Verkauf

### 2.1 Land und Sprache

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Währung | **teilweise** | Shop-Währung, Lizenz behält ihre Währung, Filter für Mehrwährungs-Plugins (`Catalog.php:404`, `RenewalOrder.php:375`). Keine Einstellung, was bei abweichender Währung geschieht. |
| Steuer (brutto/netto, Steuerklasse, Reverse Charge, OSS) | **vorhanden über WooCommerce** | Lizenzprodukte sind WooCommerce-Produkte; Anzeige folgt WooCommerce (`RenewalTerms.php:115`, `:408`). Eigene Einstellung nicht nötig. Brutto-Anzeige für Verbraucher: Rechtsprüfung, weil die eigenen Preise netto festgelegt sind. |
| Zahlen-, Datums-, Zeitformat, Zeitzone | **vorhanden** | WordPress-Formate (`src/Admin/Format.php:166`, `Notifications.php:1365`). |
| Sprache je E-Mail | **vorhanden** | Sprache des Kaufs (`PurchaseLocale.php:25`). Sprachdateien: nur `de_DE` und `de_DE_formal` (`languages/`). |
| Rechtstexte (Widerruf, AGB, Datenschutz, Impressum) | **teilweise** | AGB/Datenschutz kommen von WooCommerce. Wortlaut der Widerrufs-Zustimmung nur per Filter (G3), Wortlaut der Verlängerungs-Zustimmung fest (G4). |
| Zustimmungs-Kästchen | **teilweise** | Beide Kästchen vorhanden, aber an/aus und Pflicht nur per Filter (G1, G2, G5). |
| Altersgrenzen | **nicht zutreffend** | Lizenzen für Software; keine Stelle im Code. |
| Gesetzliche Fristen (Widerruf 14 Tage, Aufbewahrung) | **teilweise** | Aufbewahrung nur per Filter, Vorgabe deutsches Recht (F7, F8). Widerrufsfrist selbst wird nicht gerechnet. |
| Rechnungspflichten, E-Rechnung, Kasse | **nicht zutreffend im Server** | Rechnungen erzeugt der Shop bzw. dessen Rechnungs-Plugin; im Server keine Rechnungsnummern (Suche `invoice_number|Rechnungsnummer` nicht nötig: der Server legt nur WooCommerce-Bestellungen an, `src/Billing/RenewalOrder.php`). |
| Fristen für Preisänderung | **vorhanden** | Einstellung 6, Untergrenze 30 Tage, Hinweis auf Rechtsprüfung im Hilfetext (`Settings.php:976`). |
| Länderprofile | **fehlt** | Suche `preset|Länderprofil` in `src/`: keine Treffer. |

### 2.2 Geschäftliche Regeln

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Fristen und Grenzen der Verlängerung | **teilweise** | 3 Einstellungen (4–6 in 1.2); Kulanzfrist und Wiederholungsplan nur Filter (A1, A2); Verlängerungsfenster fest (A4). |
| Erinnerungen und Abstände | **vorhanden** | `reminder_days`, `renewal_notice_days`. |
| Laufzeiten | **fehlt** | nur Monat/Jahr (A7). |
| Tarifwechsel, anteilige Berechnung | **fehlt als Einstellung** | feste Formel (A8–A10). |
| Rückerstattung → Wirkung auf die Lizenz | **teilweise** | fest (B1–B3), zwei Schalter nur als Filter (B4, B5). |
| Grenzen je Kunde/Bestellung | **in Arbeit** | Höchstmenge je Position (B8). |
| Nummernkreise | **nicht zutreffend** | Lizenzschlüssel sind abgeleitet, keine laufenden Nummern (E1). |
| Rollen und Berechtigungen | **vorhanden, ohne Oberfläche** | 10 Berechtigungen (1.6). |
| Freigabeschritte | **vorhanden** | Prüffälle für Verlängerungen (`src/Admin/RenewalReviewAdmin.php`), Preisänderung nur Administrator (`Capabilities.php:73`). |
| Regeln für Websites (Staging, Entwicklung, Multisite, Umzug) | **fehlt als Einstellung** | C1–C11. |

### 2.3 Marke und Kommunikation

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Absender, Logo, Farben der E-Mails | **vorhanden über WooCommerce** | E-Mail-Rahmen von WooCommerce. |
| Betreff, Überschrift, Zusatztext je E-Mail | **vorhanden** | 1.3. Je Sprache nur über Übersetzungsdateien. |
| Vorlagen | **vorhanden für Entwickler** | Überschreiben im Theme (`LicenseEmail.php:28`). Kein Editor. |
| Platzhalter | **vorhanden** | `src/Notifications/Placeholders.php`. |
| Testversand | **teilweise** | Vorschau in WooCommerce (`LicenseEmail.php:180`); kein eigener Testversand. |
| Texte und Marke des Kundenkontos | **fehlt** | nur Vorlagen `templates/myaccount/` und Filter (H4, H5). |
| Marke der Lizenzseite im Client | **fehlt** | Name des Produkts ja (`LicenseManager.php:821`), sonst feste Texte, Platzhalter `WPLC-…` (`:858`). |
| Support-Kontakt | **teilweise** | `sender_note` (Einstellung 7) und Filter H1; kein eigenes Feld für Adresse oder Link. |

### 2.4 Datenschutz und Sicherheit

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Aufbewahrung und Anonymisierung | **vorhanden als Regelwerk, fehlt als Einstellung** | 9 Regeln (F1–F8, C12), nur Filter. |
| Export und Löschung | **vorhanden** | `src/Privacy/PersonalData.php` (8 Bereiche, `:59`). |
| Protokollstufe und Aufbewahrung | **teilweise** | Debug nur Konstante/Filter (F14); Aufbewahrung F1, F6. |
| Umgang mit IP-Adressen | **teilweise** | Proxy-Konstanten (1.5 Nr. 12–13); keine Einstellung zum Kürzen oder Nicht-Speichern. |
| Anfragegrenzen | **teilweise** | 3 kundennahe Grenzen per Filter (B6, D9, C11), technische fest (F10–F13). |
| API-Schlüssel, Webhooks | **teilweise** | Stripe-Webhook mit Status und Kopierfeld (`Settings.php:779`); keine ausgehenden Webhooks (H10). |
| Zwei-Schritt-Aktionen | **vorhanden** | Paket-Hosts mit Fingerabdruck und eigener Berechtigung (`Settings.php:418`), Bestätigungen im Client. |
| Schlüsselwechsel | **teilweise** | nur WP-CLI, ohne Frist und Erinnerung (E2). |

### 2.5 Betrieb

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Schalter je Modul | **teilweise** | Sammelverlängerung, Katalog-Import (Einstellungen 2, 3); Übertragung, Mitnutzer, Kündigungsseite, Download nach Ablauf nur Filter. |
| Einrichtungs-Assistent | **teilweise** | Liste „Erste Schritte“ mit 7 Schritten und Status (`Settings.php:616`, `:731`); kein geführter Assistent (Suche `wizard|onboarding`: keine Treffer). Nennt noch Captain-Beispiele (PROD-04, PROD-09). |
| Import/Export der Einstellungen | **fehlt** | siehe 1.1. |
| Zurücksetzen | **fehlt** | siehe 1.1. |
| Multisite-Vorgaben | **bewusst nicht** | alles je Site (`docs/MULTISITE.md`). |
| WP-CLI für Einstellungen | **fehlt** | 10 Befehlsgruppen (`src/CLI/Commands.php:32`–`41`), keine `settings`. |
| Änderungsprotokoll | **vorhanden** | `Settings.php:109`; Werte nur für 6 Schlüssel. |
| Zustands-/Diagnoseseite | **vorhanden** | `src/Admin/Diagnostics.php`. |
| Deinstallation | **teilweise** | nur Konstante (F15). |
| Debug-Modus | **teilweise** | nur Konstante/Filter (F14). |
| Meldungen an den Betreiber | **fehlt** | H9. |

### 2.6 Anbindungen

| Punkt | Stand | Beleg |
| --- | --- | --- |
| Zahlungsarten | **teilweise** | Kauf: alle WooCommerce-Zahlungsarten; automatische Verlängerung nur Stripe-Karte (A11). PayPal: `docs/PAYPAL-COMPATIBILITY.md`. |
| Versand/Abholung | **nicht zutreffend** | digitale Ware. |
| Andere Captain-Plugins | **vorhanden** | Katalogdateien, Ereignis-Hooks (`docs/LICENSE-EVENTS.md`). |
| Buchhaltungs-Export | **teilweise** | CSV-Export der Lizenzen (`src/Admin/CsvExport.php`), Audit-Export (`src/Admin/AuditLogAdmin.php:55`). |
| Externe Dienste | **teilweise** | Objektspeicher nur per Konstanten (1.5 Nr. 15–20), kein Formular. |

### 2.7 Bedienbarkeit der Einstellungsseite

- **Gut:** Jedes Feld hat `label_for`, einen Hilfetext mit `aria-describedby` und eine Prüfung mit verständlicher Meldung (`Settings.php:913`–`996`); Kopierfeld mit `aria-live` (`:588`); schmale Bildschirme behandelt (TODO A-126).
- **Lücken:** keine Reiter (bei Dutzenden weiterer Felder nötig); Status-Symbole nur farbig mit `aria-hidden`, der Zustand steht aber im Text (`:592`); Eingabe „30,7“ als freier Text statt einzelner Zahlenfelder (`:941`); keine Anzeige, wenn ein Filter eine Einstellung überschreibt.

---

## 3. Editionen, Ebenen und Sicht des Endkunden

Vorbemerkung zu den festen Regeln: Der Lizenzserver ist kein kostenloser Kern und steht nicht auf WordPress.org; Richtlinie 5 („Trialware“) gilt für ihn nicht. Die Client-Bibliothek steckt nur in den bezahlten Add-ons, nie im kostenlosen Kern. Der Mechanismus „ausgegraute Zeile im Kern“ betrifft die Kerne; der Kernel braucht dafür nur die Gegenstelle (Aufgabe SET-LIC-39).

### 3 a) Soll der verkaufte Server Editionen haben? (Entscheidung INH-10)

Feste Regel aus `docs/PRODUCT-PLAN.md` (PROD-06): „Die Lizenz des Servers steuert nur Updates und Support, **nie** seine Funktion.“ Der Code hält das ein (`src/Infrastructure/SelfLicense.php`, nichts fragt die eigene Lizenz ab). Jede Edition, die Funktionen zur Laufzeit über die eigene Lizenz sperrt, bricht diese Regel.

| Option | Was sie bedeutet | Folgen |
| --- | --- | --- |
| **A – eine Edition (empfohlen)** | Jeder Käufer bekommt alle Funktionen und alle Einstellungen. Tarife unterscheiden sich nur in der Zahl der Shops (Live-Installationen des Servers; Staging frei nach der eigenen Staging-Regel) und im Support, wie bei den Add-ons (`single`/`business`/`agency`). | Passt zur festen Regel, kein neuer Code, kein doppelter Test. Nichts Vorhandenes wandert hinter eine Schranke. |
| B – zwei Pakete („Standard“ und „Plus“) | Zwei getrennte Builds ohne Laufzeit-Sperre. „Plus“ enthält zusätzlich die Zeilen der Tabelle unten. | Hält die feste Regel ein, kostet aber doppelte Builds, Tests und Dokumentation. **Achtung:** Sammelverlängerung, Objektspeicher, Mitnutzer/Übertragung, Preisänderung und CSV-Import sind heute schon gebaut; sie in „Plus“ zu legen, verschiebt vorhandene Funktionen hinter eine Schranke (noch kein Käufer betroffen, trotzdem Entscheidung des Inhabers). |
| C – Sperre über die eigene Lizenz | Der Server fragt seine eigene Lizenz und graut Felder aus. | **Nicht empfohlen:** bricht PROD-06; fällt der Lizenzserver des Inhabers aus, verlören fremde Shops Funktionen. |

Welche Einstellungen sich bei Option B unterscheiden würden (bei Option A alle „für jeden“). Die Spalte „Recht“ nennt **neue, vorgeschlagene** Schlüssel: Der Server hat noch keine Katalogdatei (INH-10, PROD-07), in `captain-pro:docs/ENTITLEMENT-KEYS.md` kommt er nicht vor.

| Einstellung (vorhanden / vorgeschlagen) | Standard | Plus | Begründung | Vorgeschlagenes Recht |
| --- | --- | --- | --- | --- |
| Erinnerungen, Ankündigung, Preisänderungsfrist (1.2 Nr. 4–6) | ja | ja | nötig für korrekten, rechtssicheren Betrieb | – |
| Kulanzfrist, Wiederholungsplan, Verlängerungsfenster (A1, A2, A4) | ja | ja | Grundregeln jedes Abos | – |
| Aufbewahrung, Anonymisierung, Länderprofil (F1–F8) | ja | ja | Recht | – |
| Zustimmungstexte, Pflicht/freiwillig (G1–G5) | ja | ja | Recht | – |
| Absender, Support-Kontakt, Betreff/Text der E-Mails | ja | ja | Grundausstattung | – |
| Staging-/Entwicklungsregeln, Offline-Frist (C1–C6, D1) | ja | ja | ohne sie ist der Server je Hersteller nicht korrekt einstellbar | – |
| Rückerstattung → Wirkung (B1–B5) | ja | ja | Grundregel | – |
| Import/Export, Zurücksetzen, WP-CLI, Änderungsprotokoll | ja | ja | Betrieb | – |
| Sammelverlängerung (1.2 Nr. 3) – **heute vorhanden** | – | ja | Umfang/Automatisierung | `server.combined_renewals` |
| Objektspeicher S3/R2 (1.5 Nr. 15–20) – **heute vorhanden** | – | ja | Umfang | `server.object_storage` |
| Mitnutzer und Übertragung (E3–E6) – **heute vorhanden** | – | ja | Mehrbenutzer | `server.license_sharing` |
| Preisänderung für Bestandslizenzen (`src/Admin/PriceChangeAdmin.php`) – **heute vorhanden** | – | ja | Automatisierung | `server.price_change` |
| Beta-Kanal mit Recht je Tarif (D3) | – | ja | erweiterte Regel | `server.release_channels` |
| White Label der Client-Lizenzseite, eigenes Schlüssel-Präfix (E1) | – | ja | erweiterte Marke | `server.white_label` |
| Ausgehende Webhooks (H10) | – | ja | Anbindung | `server.webhooks` |
| Gesundheits-Meldungen per E-Mail (H9) | ja | ja | Betriebssicherheit, darf nicht fehlen | – |

Texte der ausgegrauten Zeile, falls doch Option B mit einem gemeinsamen Build gewählt würde (Englisch als Quelle, Deutsch in Du-Form; „Sie“-Fassung entsprechend):

| Zeile | Englisch | Deutsch (du) |
| --- | --- | --- |
| Sammelverlängerung | Available with License Captain Plus: charge all licenses of a customer that renew on the same day with one payment. | Verfügbar mit License Captain Plus: Buche alle Lizenzen eines Kunden, die am selben Tag verlängern, mit einer Zahlung ab. |
| Objektspeicher | Available with License Captain Plus: deliver update packages from S3-compatible storage. | Verfügbar mit License Captain Plus: Liefere Update-Pakete aus einem S3-kompatiblen Speicher aus. |
| Mitnutzer und Übertragung | Available with License Captain Plus: let customers invite co-users and transfer licenses. | Verfügbar mit License Captain Plus: Lass deine Kunden Mitnutzer einladen und Lizenzen übertragen. |
| Preisänderung | Available with License Captain Plus: announce a new renewal price to existing licenses. | Verfügbar mit License Captain Plus: Kündige Bestandslizenzen einen neuen Verlängerungspreis an. |
| Beta-Kanal | Available with License Captain Plus: offer beta releases to selected plans. | Verfügbar mit License Captain Plus: Biete ausgewählten Tarifen Beta-Versionen an. |
| White Label | Available with License Captain Plus: use your own key prefix and brand the license screen of your add-on. | Verfügbar mit License Captain Plus: Nutze ein eigenes Schlüssel-Präfix und gestalte die Lizenzseite deines Add-ons in deiner Marke. |
| Webhooks | Available with License Captain Plus: send license events to other systems. | Verfügbar mit License Captain Plus: Sende Lizenzereignisse an andere Systeme. |

Der Produktname in den Texten ist ein Platzhalter (INH-07).

### 3 b) Ebenen: Hersteller, Produkt/Tarif, Shop-Betrieb

Eine Installation bedient genau einen Hersteller (`docs/PRODUCT-PLAN.md`, Abschnitt 1). „Hersteller-Ebene“ heißt hier: Regeln, die das Verhalten der ausgelieferten Add-ons auf den Websites der Endkunden bestimmen. „Shop-Ebene“: Regeln für Verkauf, Kasse, E-Mails und Recht im Shop.

| Ebene | Einstellungen | Wer darf (Vorschlag) |
| --- | --- | --- |
| **Hersteller, global** | Staging-Namensteile und Hoster-Endungen (C1–C4), Staging je Live-Website (C5), Entwicklungs-Websites (C6), Offline-Frist (D1), Update-Kanäle und wer Beta bekommt (D3), Lebensdauer der Downloads (D4–D6), Download nach Ablauf (D7, D8), Schlüssel-Präfix (E1), Mitnutzer/Übertragung (E3–E6), Umzugsregeln (C9), Paket-Hosts (1.2 Nr. 1), Katalog-Import (Nr. 2), Marke des Clients | neue Berechtigung `wplc_manage_policy`, Vorgabe `manage_options` (diese Regeln wirken auf alle Kunden-Websites, wie die Paket-Hosts) |
| **Je Produkt** | Anzeigename, Link zur Dokumentation, Installationsschritte (H4), Netzwerk-Modus (C7; liegt im Build des Add-ons, im Server nur dokumentiert), Standard-Kanal | `wplc_manage_catalog` |
| **Je Tarif** | Laufzeit (A7), Live-Limit (C8), Support-Stufe, Rechte; neu überschreibbar: Staging-/Entwicklungs-Limit, Mitnutzer-Zahl, Beta-Zugang, Höchstmenge (B8, in Arbeit) | `wplc_manage_catalog` |
| **Shop-Betrieb, global** | Erinnerungen und Ankündigungen (1.2 Nr. 4–6), Kulanzfrist und Wiederholungsplan (A1, A2), Verlängerungsfenster (A4), Sammelverlängerung, Rückerstattungs-Wirkung (B1–B5), „gesperrt gilt als beendet“ (B7, in Arbeit), Zustimmungen und Texte (G1–G5), Preis-Anzeige (G6, G7), Aufbewahrung (F1–F8), E-Mails, Support-Kontakt, Kundenkonto-Texte, Gesundheits-Meldungen, Kündigungsseite | `wplc_manage_settings` |
| **Nur `wp-config.php`** | alle Geheimnisse, Proxy, Speicherordner, Objektspeicher-Zugang, Abbuchungen anhalten, Daten bei Deinstallation löschen | Server-Betreiber |

Hinweis: Kulanzfrist und Wiederholungsplan wirken über die signierte Antwort auch auf die Kunden-Websites (`EntitlementService.php:302`); sie bleiben trotzdem Shop-Regeln, weil sie an der Zahlung hängen.

### 3 c) Client und Kernel: was der Endkunde sehen soll

Der kostenlose Kern enthält keinen Lizenzcode; alles hier liegt im Add-on. Deshalb ist die Spalte „Kern“ immer „–“.

| Option | Heute | Vorschlag | Kern | Add-on |
| --- | --- | --- | --- | --- |
| Lizenzschlüssel eingeben, aktivieren, prüfen, freigeben, umziehen | vorhanden | bleibt | – | ja |
| Status, Websites belegt/erlaubt, Funktionen | vorhanden | bleibt | – | ja |
| **Beta-Versionen erhalten** | fehlt (nur Build-Wert, D3) | Kästchen auf der Lizenzseite, nur sichtbar, wenn der Hersteller es erlaubt und der Server das Recht meldet; Vorgabe aus | – | ja |
| **Umgebung dieser Website** (Live / Staging von … / Entwicklung) | automatisch, nicht korrigierbar | Auswahl nur bei der Aktivierung, wenn die Erkennung nicht „Live“ ergibt oder der Kunde sie ändern will; der Server entscheidet weiterhin nach seinen Regeln | – | ja |
| Hinweise im Admin | immer für Berechtigte (Filter `{prefix}_license_show_notice`) | kein Schalter für Endkunden; Ablauf-Hinweise sollen nicht abschaltbar sein | – | – |
| Automatische Updates | WordPress-eigene Schaltfläche in der Plugin-Liste | bleibt bei WordPress | – | – |
| Offline-Frist, Prüfabstand, Server-Adresse, öffentliche Schlüssel | fest / Build | **nie** für Endkunden (Schutz der Lizenz) | – | – |
| Netzwerk-Modus | Build (`network`) | bleibt Entscheidung des Herstellers (B2) | – | – |
| Wer die Lizenz verwalten darf | Build (`capability`) | optional Filter dokumentieren; kein Feld | – | – |
| **Marke des Herstellers / White Label** (Texte, Support-Link, Schlüssel-Platzhalter; Agentur blendet Lizenzseite für ihre Kunden aus) | fehlt | Build-Werte `brand` (Support-Adresse, Konto-Link, Platzhalter des Schlüssels) und ein Schalter „Lizenzseite nur für diese Rolle“; bei den Captain-Add-ons im Rahmen des vorhandenen White-Label-Konzepts (`captain-pro:docs/WHITE-LABEL.md`) | – | ja |

---

## 4. Aufgaben (zum Einfügen in `docs/TODO.md`)

Für alle gilt, sofern nicht anders gesagt: „erledigt“ heißt Feld mit Hilfetext und Prüfung auf der Einstellungsseite, sichere Vorgabe = heutiger Wert, vorhandener Filter bleibt und hat das letzte Wort, Wert im Audit-Protokoll, Unit- und Integrationstest, Eintrag in `docs/EXTENSION-POINTS.md` und im Handbuch, Übersetzung `de_DE` (du) und `de_DE_formal` (Sie).

### Kritisch

- [ ] **SET-LIC-01 [KRITISCH]** Einstellungs-Register bauen: eine Stelle je Einstellung mit Schlüssel, Typ, Vorgabe, Grenzen, Prüfung, Hilfetext, Ebene (Hersteller/Shop), Berechtigung, Filtername, „im Audit mit Wert“. Wo: `src/Admin/Settings.php:171` (`defaults()`), `:375` (`sanitize()`), `:289` (`register_settings()`); Direktzugriffe ersetzen in `src/Notifications/Notifications.php:554`, `:580`, `src/Catalog/ReleaseCatalog.php:52`, `src/Billing/PriceChange.php:92`. Erledigt, wenn die 7 vorhandenen Einstellungen unverändert aus dem Register kommen, kein Code außerhalb des Registers `get_option( 'wplc_settings' )` liest (Test durchsucht `src/`), und ein Test für jede Einstellung Vorgabe, Grenzen und Hilfetext prüft. Voraussetzung für alle folgenden Aufgaben.
- [ ] **SET-LIC-02 [KRITISCH]** Kulanzfrist als Einstellung. Wo: `src/License/LicenseLifecycle.php:53`, `:272`–`284`; Anzeige heute nur lesend `src/Admin/Settings.php:854`. Erledigt, wenn ein Zahlenfeld (1–90, Vorgabe 14) existiert, `grace_days()` es liest, der Filter `wplc_renewal_grace_days` weiter gilt, und die Seite warnt, wenn die Frist nicht länger ist als der letzte Wiederholungstag (Kommentar `:267`). Test: bestehende Verlängerungen behalten ihr `grace_ends_at`.
- [ ] **SET-LIC-03 [KRITISCH]** Wiederholungsplan der Abbuchung als Einstellung (Tage und damit Zahl der Versuche). Wo: `src/Billing/RenewalProcessor.php:83`, `:860`–`871`. Erledigt, wenn eine Liste (1–6 Werte, je 1–60, Vorgabe `1,3,7`; leer = keine Wiederholung) gespeichert wird, `retry_days()` sie liest, Filter `wplc_renewal_retry_days` weiter gilt, gemeinsame Prüfung mit SET-LIC-02. Test mit Stripe-Attrappe: 0, 1 und 3 Wiederholungen.
- [ ] **SET-LIC-04 [KRITISCH]** Aufbewahrungsfristen als Einstellungen (9 Regeln). Wo: `src/Maintenance/Retention.php:50`–`60`, `:207`, `:235`; Anzeige `src/Admin/Settings.php:861`. Erledigt, wenn je Regel ein Feld in Tagen (0 = aus, höchstens 36500) existiert, die zwei Jahresende-Regeln (`:66`) als solche erklärt sind, der Filter `wplc_retention_days` weiter gilt, eine Verkürzung vor dem Speichern anzeigt, wie viele Datensätze beim nächsten Lauf betroffen sind, und der Hilfetext „Rechtsprüfung“ nennt. Braucht: Rechtsprüfung der Vorgaben je Land (siehe SET-LIC-08).
- [ ] **SET-LIC-05 [KRITISCH]** Zustimmung zum Erlöschen des Widerrufsrechts einstellbar: an/aus, Pflicht/freiwillig, Wortlaut je Sprache. Wo: `src/Billing/WithdrawalConsent.php:80`–`137`. Erledigt, wenn drei Felder existieren (Vorgaben wie heute, auch die Ausnahme für Germanized/German Market `:116` bleibt und wird auf der Seite angezeigt), der gespeicherte Wortlaut je Sprache gilt und sonst die Übersetzung, die drei Filter weiter gelten, und der Nachweis an der Bestellung (`:44`) den tatsächlich gezeigten Text enthält. Hilfetext „Rechtsprüfung“. Braucht: Rechtsprüfung des Vorgabetextes.
- [ ] **SET-LIC-06 [KRITISCH]** Wortlaut der Zustimmung zur automatischen Verlängerung einstellbar und filterbar. Wo: `src/Billing/RecurringConsent.php:119`, Block-Checkout `src/Billing/BlockCheckoutConsent.php:78`. Erledigt, wenn ein Textfeld je Sprache existiert, ein neuer Filter `wplc_recurring_consent_text` den Text liefert, der Nachweis in der Tabelle der Zustimmungen den gezeigten Text speichert und beide Kassen denselben Text zeigen. Braucht: Rechtsprüfung.
- [ ] **SET-LIC-07 [KRITISCH]** Die zwei Einstellungen „in Arbeit“ ins Register aufnehmen, sobald sie auf main sind: „gesperrt gilt als beendet nach X Tagen“ und „Höchstmenge je Bestellposition“. Wo: nach dem Zusammenführen (heute keine Stelle auf main). Erledigt, wenn beide aus dem Register kommen, im Export/Import (SET-LIC-12) und in WP-CLI (SET-LIC-13) erscheinen und die Höchstmenge auch je Tarif überschreibbar ist (SET-LIC-21).
- [ ] **SET-LIC-08 [KRITISCH]** Länderprofile: ein Klick setzt Vorgaben für DE, AT, CH (danach EU allgemein), alles bleibt änderbar. Inhalt: Aufbewahrung (DE 10 Jahre, AT 7 Jahre laut Kommentar `Retention.php:44`; CH und weitere nach Rechtsprüfung), Zustimmungen an/Pflicht, Preisänderungsfrist, Ankündigungsfrist. Wo: neues Register (SET-LIC-01), Seite `src/Admin/Settings.php`. Erledigt, wenn das Profil vor dem Anwenden die Unterschiede zeigt, die Anwendung im Audit steht und ein Test jedes Profil gegen das Register prüft. Braucht: Rechtsprüfung jedes Profils; Abstimmung mit den Profilen der anderen Plugins der Suite (gleiche Ländercodes und Bedienung).

### Wichtig

- [ ] **SET-LIC-09 [WICHTIG]** Staging-Regeln einstellbar: Namensteile, Hoster-Endungen, lokale Endungen als bearbeitbare Listen mit „auf Vorgabe zurücksetzen“. Wo: `src/Api/V1/SiteUrl.php:36`, `:41`, `:63`, `:338`, `:348`. Erledigt, wenn die Listen aus dem Register kommen, nur gültige Namensteile/Endungen angenommen werden, die Filter `wplc_is_staging_host` und `wplc_registrable_domain` weiter gelten und `SiteUrlNormalizationTest` mit geänderten Listen läuft. Berechtigung der Hersteller-Ebene (SET-LIC-31).
- [ ] **SET-LIC-10 [WICHTIG]** Zahl der Staging-Websites je Live-Website (heute fest 1) und Entwicklungs-Websites (heute mindestens 5, doppeltes Live-Limit) einstellbar, global und je Tarif. Wo: `src/Api/V1/ActivationService.php:53`, `:276`–`278`, `:634`–`644`. Erledigt, wenn 0 „nicht erlaubt“ bedeutet, die Meldungen `staging_limit` die Zahl nennen und `SubsiteSeatsIntegrationTest` unverändert grün ist.
- [ ] **SET-LIC-11 [WICHTIG]** Offline-Frist einstellbar (heute 14 Tage, nur Filter). Wo: `src/Api/V1/EntitlementService.php:25`, `:308`–`316`. Erledigt, wenn ein Feld (1–90) existiert, global und je Tarif, und der Hilfetext erklärt, dass die Frist nie über das Ende des Zugangs hinausreicht (`:319`).
- [ ] **SET-LIC-12 [WICHTIG]** Import und Export der Einstellungen als JSON-Datei. Wo: neue Aktion auf `src/Admin/Settings.php`, Format aus dem Register. Erledigt, wenn der Export keine Geheimnisse enthält, der Import jede Zeile mit den Regeln des Registers prüft, Unterschiede vor dem Anwenden zeigt, Paket-Hosts nur mit `wplc_manage_package_hosts` ändert und im Audit steht.
- [ ] **SET-LIC-13 [WICHTIG]** WP-CLI `wp wplc settings list|get|set|reset|export|import`. Wo: `src/CLI/Commands.php:32`–`41`, neue Klasse. Erledigt, wenn `set` dieselbe Prüfung wie das Formular nutzt und Audit-Einträge mit Quelle `cli` schreibt (`src/License/AuditLog.php:27`).
- [ ] **SET-LIC-14 [WICHTIG]** „Auf Vorgabe zurücksetzen“ je Abschnitt und gesamt, mit Bestätigung. Wo: `src/Admin/Settings.php:571`. Erledigt, wenn Paket-Hosts ausgenommen sind (eigene Bestätigung) und der Vorgang im Audit steht.
- [ ] **SET-LIC-15 [WICHTIG]** Änderungsprotokoll vervollständigen: alle Einstellungen des Registers mit altem und neuem Wert (heute nur 6, `src/Admin/Settings.php:78`); Texte gekürzt. Erledigt, wenn die Audit-Seite nach „Einstellungen“ filtern kann.
- [ ] **SET-LIC-16 [WICHTIG]** Fenster der manuellen Verlängerung einstellbar (heute fest 30 Tage vor und nach Ende). Wo: `src/License/LicenseStateMachine.php:23`, abhängig `src/License/ActivationTransfer.php:37`. Erledigt, wenn zwei Felder (vor dem Ende 0–365, nach dem Ende 0–365) existieren, ein Filter `wplc_manual_renewal_window_days` dazukommt und die E-Mail „abgelaufen“ die tatsächliche Frist nennt.
- [ ] **SET-LIC-17 [WICHTIG]** Wirkung von Rückerstattung, Storno und Rückbuchung als Einstellungen. Wo: `src/License/LicenseLifecycle.php:730`, `:852`, `:169`–`170`; `src/License/LicenseStateMachine.php:27`–`47`. Erledigt, wenn vier Schalter existieren (Storno → sperren; Position entfernt → sperren; reine Betrags-Teilerstattung → keine Wirkung / Prüfhinweis; nach gewonnenem Streit automatisch freigeben ja/nein), die zwei vorhandenen Filter weiter gelten und volle Rückerstattung immer widerruft. Braucht: Entscheidung des Inhabers zu den Vorgaben (siehe Abschnitt 5, E-07).
- [ ] **SET-LIC-18 [WICHTIG]** Mitnutzer und Übertragung einstellbar: Funktion an/aus, Zahl der Mitnutzer (global, je Tarif), Gültigkeit der Einladung, neuer Schlüssel bei Übertragung. Wo: `src/License/LicenseMembers.php:47`, `:52`, `:199`, `:217`, `:514`, `:778`.
- [ ] **SET-LIC-19 [WICHTIG]** Downloads einstellbar: Download nach Ablauf erlaubt, Zahl wählbarer Versionen, Downloads je Stunde, Wiederverwendung des Links in Sekunden, Lebensdauer der Freigabe. Wo: `src/Update/CustomerDownload.php:33`–`34`, `:84`, `:177`, `:302`; `src/Update/PackageStream.php:37`–`39`, `:150`; `src/Api/V1/UpdateService.php:39`. Erledigt, wenn die Grenzen der Filter übernommen sind (z. B. 0–600 Sekunden) und der falsche Kommentar „Default 120“ (`PackageStream.php:148`, tatsächlich 0 `:39`) berichtigt ist.
- [ ] **SET-LIC-20 [WICHTIG]** Update-Kanäle regeln: Der Server gibt Beta heute jedem Client, der danach fragt (`src/Api/V1/UpdateService.php:80`, `src/Update/ReleaseRegistry.php:166`–`179`). Erledigt, wenn eine Einstellung „Beta erhalten: alle / nur Tarife mit Recht / niemand“ existiert, das Recht je Tarif gesetzt werden kann, ein Client ohne Recht stabile Versionen erhält und ein Vertragstest das belegt. Braucht: Entscheidung E-05.
- [ ] **SET-LIC-21 [WICHTIG]** Überschreibungen je Tarif im Produkt-Editor: Staging-/Entwicklungs-Limit, Offline-Frist, Mitnutzer, Beta, Höchstmenge. Wo: `src/Catalog/ProductEditor.php:201`–`320`, `:657`–`663`; Tabelle der Tarife (`src/Catalog/Catalog.php:271`). Erledigt, wenn leere Felder „globale Einstellung“ bedeuten, ein Schemaschritt die Spalten ergänzt und CSV-Import (`src/Admin/CatalogCsv.php`) und `docs/CATALOG-CSV.md` die Spalten kennen.
- [ ] **SET-LIC-22 [WICHTIG]** Gesundheits-Meldungen per E-Mail: Empfänger (Liste), Schwellen je Kennzahl, höchstens eine Sammel-E-Mail je Tag. Wo: `src/Admin/Diagnostics.php:603`–`640`, `:663`–`682`. Erledigt, wenn ohne Empfänger nichts gesendet wird, der Hook `wplc/health_alert` unverändert bleibt und ein Testversand vorhanden ist.
- [ ] **SET-LIC-23 [WICHTIG]** Support-Kontakt als Einstellung: Adresse und Link (heute Absender des Shops, `src/Notifications/Notifications.php:1263`–`1276`). Filter `wplc_support_email` bleibt.
- [ ] **SET-LIC-24 [WICHTIG]** Texte je Produkt: Installationsschritte, Link zur Dokumentation, Anzeigename des Tarifs. Wo: `src/Notifications/Notifications.php:1202`, `:1224`, `:1253`. Erledigt, wenn sie je Produkt/Tarif im Editor oder in der Katalogdatei (`docs/CATALOG-FILE.md`) je Sprache gepflegt werden können. Hängt mit PROD-04 zusammen.
- [ ] **SET-LIC-25 [WICHTIG]** Kundenkonto einstellbar: Adress-Teile der Seiten (`src/Account/CustomerPortal.php:47`, `:132`; `src/Account/SiteOverview.php:35`), Bezeichnung der Menüpunkte, Warnschwellen (`SiteOverview.php:50`, `:55`), Einträge je Seite (`CustomerPortal.php:41`). Erledigt, wenn eine Änderung der Adresse die Umschreibregeln neu erzeugt (`CustomerPortal.php:195`).
- [ ] **SET-LIC-26 [WICHTIG]** Schalter für Funktionen, die heute nur Filter sind: öffentliche Kündigungsseite mit Versuchen je Stunde (`src/Account/PublicCancellation.php:63`, `:219`), Laufzeit am Preis (`src/Catalog/TermDisplay.php:75`), manuelle Verlängerung nicht mehr verkaufter Produkte (`src/Billing/ManualRenewal.php:88`), Verlängerung aus bei gelöschter Karte (`src/Maintenance/Orphans.php:187`), Zahlungsarten mit später Ausstellung (`src/License/LicenseIssuer.php:285`). Hinweis „Rechtsprüfung“ bei der Kündigungsseite.
- [ ] **SET-LIC-27 [WICHTIG]** Neue Aktivierungen je Stunde und Lizenz einstellbar (`src/Api/V1/ActivationService.php:47`, `:668`). Technische Grenzen (F10–F13) bleiben Konstanten, werden aber auf der Diagnoseseite angezeigt.
- [ ] **SET-LIC-28 [WICHTIG]** Schlüsselwechsel: Erinnerung statt Automatik. Einstellung „an Wechsel erinnern nach X Monaten“ (Vorgabe aus) für Lizenz-, Verschlüsselungs- und Signaturschlüssel; Anzeige des Alters auf der Diagnoseseite. Wo: `src/Admin/KeyConfigurationNotice.php`, `src/Security/ResponseSigner.php:212`, `src/CLI/SigningKeyCommand.php`. Der Wechsel selbst bleibt WP-CLI.
- [ ] **SET-LIC-29 [WICHTIG]** Deinstallation und Debug als sichtbare Einstellungen: „Daten bei Deinstallation löschen“ (Vorgabe nein, mit Warnung; Konstante `WPLC_REMOVE_ALL_DATA` gewinnt, `uninstall.php:130`) und „ausführliches Protokoll“ mit Ablaufdatum (`src/Infrastructure/Log.php:71`–`78`).
- [ ] **SET-LIC-30 [WICHTIG]** Objektspeicher auf der Einstellungsseite: Bucket, Region, Endpunkt, Pfad-Stil als Felder; Zugangsdaten bleiben Konstanten. Wo: `src/Update/S3Storage.php:66`–`97`, `:243`. Erledigt, wenn die Seite den Zustand und eine Verbindungsprobe zeigt (`src/Update/PackageStorageCheck.php`).
- [ ] **SET-LIC-31 [WICHTIG]** Neue Berechtigung `wplc_manage_policy` (Vorgabe `manage_options`) für Regeln der Hersteller-Ebene (3 b), und eine Übersicht „Wer darf was“ auf der Einstellungsseite. Wo: `src/Admin/Capabilities.php:32`–`75`. Erledigt, wenn ein Shop-Manager die Hersteller-Regeln lesen, aber nicht ändern kann (wie bei den Paket-Hosts, `src/Admin/Settings.php:916`).
- [ ] **SET-LIC-32 [WICHTIG]** Einstellungsseite in Reiter gliedern (Verlängerung, Websites und Staging, Updates und Downloads, E-Mails und Texte, Recht und Datenschutz, Betrieb) und anzeigen, wenn ein Filter einen gespeicherten Wert überschreibt („Wirksam: 21 Tage, gesetzt durch Code“). Wo: `src/Admin/Settings.php:300`–`359`, `:571`.
- [ ] **SET-LIC-33 [WICHTIG]** Client: Beta-Kästchen für den Endkunden. Wo: `src/Client/LicenseManager.php:142`, `:172`, `:812`–`914`; `src/Client/Updater.php:815`. Erledigt, wenn der Hersteller es im Build freischaltet (`allow_channel_choice`), das Kästchen nur erscheint, wenn die signierte Antwort Beta erlaubt (SET-LIC-20), die Wahl je Website gespeichert wird und die Kopie in `captain-pro:packages/license-client` aufgefrischt ist. Andere Stelle: captain-pro.
- [ ] **SET-LIC-34 [WICHTIG]** Client: Umgebung bei der Aktivierung wählbar machen, wenn die Erkennung nicht passt. Wo: `src/Client/LicenseClient.php:405`–`408`, `:438`; Formular `src/Client/LicenseManager.php:905`–`911`. Erledigt, wenn der Server unverändert entscheidet (`ActivationService.php:219`–`232`) und die Meldung erklärt, warum eine „Staging“-Website als Live-Website gezählt wurde.
- [ ] **SET-LIC-35 [WICHTIG]** Client: Marke des Herstellers. Build-Werte für Support-Adresse/-Link, Platzhalter des Schlüsselfelds (heute `WPLC-…`, `src/Client/LicenseManager.php:858`) und überschreibbare Texte (`src/Client/Messages.php`). Erledigt, wenn `bin/export-client.php` die Werte setzt, das Beispiel-Add-on sie zeigt und `docs/CLIENT-INTEGRATION.md` sie beschreibt. Hängt an PROD-08; Schlüssel-Präfix siehe E-04.
- [ ] **SET-LIC-36 [WICHTIG]** Hilfe im Handbuch: Kapitel „Einstellungen“ mit jeder Einstellung, Vorgabe, Wirkung und Filter, Englisch und Deutsch (PROD-10). Erledigt, wenn ein Test das Kapitel gegen das Register prüft (kein Feld ohne Eintrag).

### Optimierung

- [ ] **SET-LIC-37 [OPTIMIERUNG]** Erinnerungstage als einzelne Zahlenfelder statt freiem Text „30,7“ (`src/Admin/Settings.php:940`–`942`), mit Meldung bei verworfenen Werten (heute still, `:514`–`527`).
- [ ] **SET-LIC-38 [OPTIMIERUNG]** Status-Tabelle: Zustand zusätzlich als Text („In Ordnung“ / „Achtung“) für Bildschirmleser (`src/Admin/Settings.php:590`–`596`).
- [ ] **SET-LIC-39 [OPTIMIERUNG]** Kernel: Gegenstelle für ausgegraute Zeilen der Kerne. Der Kern zeigt aus eigenen, festen Daten eine deaktivierte Zeile „Verfügbar mit <Produkt> Pro“ (ohne Code der Funktion, ohne Lizenzwissen; so bleibt Richtlinie 5 von WordPress.org eingehalten); die Funktion des Add-ons ersetzt sie durch das echte Feld. Wo: `captain-pro:packages/addon-kernel/src/Feature.php:23`–`81` (neue, freiwillige Schnittstelle `SettingsFields` wie `Retention`), `captain-pro:packages/addon-kernel/src/FeatureRegistry.php:158`. Erledigt, wenn eine Funktion ohne Lizenz die Zeile des Kerns stehen lässt und mit Lizenz ersetzt; Test im Kernel. Andere Stelle: captain-pro und jeder Kern (Hook-Name je Kern).
- [ ] **SET-LIC-40 [OPTIMIERUNG]** Kernel: Text „Pro-Lizenz“ und Lizenzseite in `de_DE` und `de_DE_formal` prüfen; Texte der Bibliothek im Server selbst fehlen auf Deutsch (`docs/PRODUCT-PLAN.md`, „Offen bei PROD-06“).
- [ ] **SET-LIC-41 [OPTIMIERUNG]** Sammelverlängerung: Höchstzahl der Positionen einstellbar (`src/Billing/CombinedRenewal.php:52`, heute 20).
- [ ] **SET-LIC-42 [OPTIMIERUNG]** Aufschub bei offenem Prüffall (`src/License/LicenseLifecycle.php:1150`, 30 Tage) und Gültigkeit des Upgrade-Angebots (`src/Billing/PlanUpgrade.php:64`, 24 h) einstellbar.
- [ ] **SET-LIC-43 [OPTIMIERUNG]** Testversand je E-Mail an eine frei wählbare Adresse und Sprache (Vorschau gibt es, `src/Notifications/Emails/LicenseEmail.php:180`).
- [ ] **SET-LIC-44 [OPTIMIERUNG]** IP-Adressen: Einstellung „im Protokoll kürzen/nicht speichern“ prüfen; zuerst feststellen, wo Adressen gespeichert werden (`src/Api/V1/ApiLog.php`, `docs/PRIVACY-DATA-FLOWS.md`), dann entscheiden.
- [ ] **SET-LIC-45 [OPTIMIERUNG]** „Erste Schritte“ zu einem geführten Ablauf ausbauen, der die neuen Regeln (Länderprofil, Staging, Kulanz) abfragt (`src/Admin/Settings.php:616`–`754`; gehört zu PROD-09).
- [ ] **SET-LIC-46 [OPTIMIERUNG]** Ausgehende Webhooks: Ziel-Adresse, Geheimnis, Auswahl der Ereignisse, Zustellprotokoll (Grundlage `docs/LICENSE-EVENTS.md`, 47 Hooks). Braucht: Entscheidung E-09.

### Braucht Entscheidung des Inhabers

| Nr. | Frage | Optionen | Empfehlung |
| --- | --- | --- | --- |
| E-01 (INH-10) | Editionen des verkauften Servers | A eine Edition, Tarife nach Zahl der Shops und Support; B zwei getrennte Pakete; C Sperre über die eigene Lizenz | **A.** C bricht die Regel aus PROD-06; B verschiebt gebaute Funktionen hinter eine Schranke und verdoppelt Builds. |
| E-02 (INH-10) | Was zählt als „Website“ des Servers | ein Shop je Live-Installation, Staging frei nach eigener Regel / jede Installation zählt | ein Shop je Live-Installation, 1 Staging frei (wie bei den Add-ons). |
| E-03 | Laufzeiten | nur Monat/Jahr (heute) / zusätzlich Mehrjahres-Laufzeit / zusätzlich Dauerlizenz / Testzeitraum | zunächst Monat/Jahr lassen; Mehrjahres- und Dauerlizenz als eigene Ausbaustufe, weil Verlängerung, Preisänderung und E-Mails daran hängen (`Catalog.php:178`). |
| E-04 | Format des Schlüssels: eigenes Präfix je Hersteller | `WPLC-` fest / Präfix einstellbar nur vor der ersten Lizenz / frei | einstellbar nur vor der ersten ausgestellten Lizenz; Länge bleibt (Sicherheit, `LicenseKey.php:34`). Bestehende Schlüssel bleiben gültig. |
| E-05 | Beta-Kanal | alle Kunden dürfen / nur Tarife mit Recht / kein Beta | nur mit Recht je Tarif, Vorgabe für alle Tarife „erlaubt“, Endkunde schaltet selbst ein (SET-LIC-20, -33). |
| E-06 | Umzug von Websites: Wartezeit oder Höchstzahl | keine Grenze (heute) / höchstens N Umzüge je Lizenz und 30 Tage / Wartezeit nach Freigabe | Einstellung mit Vorgabe „keine Grenze“; Missbrauch ist durch das Live-Limit begrenzt. Erst bauen, wenn Missbrauch belegt ist. |
| E-07 | Rückerstattung: Vorgaben (SET-LIC-17) | wie heute / Betrags-Teilerstattung erzeugt Prüfhinweis / nach gewonnenem Streit automatisch freigeben | wie heute, zusätzlich Prüfhinweis bei Betrags-Teilerstattung. |
| E-08 | Tarifwechsel: Regel der anteiligen Berechnung | taggenau (heute) / kein Abzug / Gutschrift beim Wechsel nach unten | taggenau lassen, nur „Wechsel nach oben erlaubt ja/nein“ und „nach unten erst zur Verlängerung“ als Schalter. Rechtsprüfung für Gutschriften. |
| E-09 | Ausgehende Webhooks (SET-LIC-46) | nicht bauen (PHP-Hooks reichen) / bauen für alle / bauen nur in „Plus“ | bauen für alle, aber nach dem Verkaufsstart; fremde Hersteller haben oft kein PHP auf dem Shop-Server in der Hand. |
| E-10 | Zahlungsarten der automatischen Verlängerung | nur Stripe-Karte (heute) / zusätzlich SEPA-Lastschrift über Stripe / weitere Anbieter als Adapter | als Produktgrenze „nur Stripe-Karte“ festhalten (Frage 7 in PRODUCT-PLAN), SEPA als nächste Stufe; betrifft Fristen (Vorab-Ankündigung, Rücklastschrift). |
| E-11 | Brutto-Anzeige für Verbraucher | folgt WooCommerce (heute) / eigener Schalter im Server | bei WooCommerce lassen, kein eigener Schalter; Rechtsprüfung, weil die eigenen Preise netto festgelegt sind (Entscheidung A1). |

Rechtsprüfung nötig bei: SET-LIC-04, -05, -06, -08, -17, -26 (Kündigungsseite), E-08, E-11 und der schon vorhandenen Preisänderungsfrist (T-93).
