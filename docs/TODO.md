# WP License Captain — To-do-Liste

Stand: 13.11.2026. Dies ist die einzige Aufgabenliste des Projekts. Sie ersetzt die sechs früheren Checklisten (PR01–12, PR13–20, Phasen 21–40, Aufgaben 41–61, Fortschrittsblöcke, PR-Stapel-Review) und enthält zusätzlich alle Funde aus dem Code-Review vom 13.11.2026.

**Regeln**

- Ein Haken bedeutet: umgesetzt **und** durch einen automatisierten Test oder eine dokumentierte Abnahme im Testshop belegt. „Code geschrieben“ reicht nicht.
- Kritikalität: **[KRITISCH]** blockiert den ersten echten Verkauf, **[WICHTIG]** blockiert den öffentlichen Launch, **[OPTIMIERUNG]** kann danach folgen.
- Herkunft in Klammern: *Code*, *Security*, *Architektur*, *UX*, *Produkt* (Review-Perspektive) oder die alte Aufgabennummer.
- Die Funde stammen aus dem Lesen des Codes. Was davon in einem laufenden Shop geprüft werden muss, steht ausdrücklich dabei.

## 1. Jetzt: Stand sichern

- [ ] **T-01 [KRITISCH]** Integrationsstand `claude/sharp-mendel-xiiwf3` nach `main` mergen; danach die 35 alten Pull Requests schließen und die `codex/*`-Branches löschen. (alt: „PR01–PR12 mergen“)
- [ ] **T-02 [KRITISCH]** Lokalen WooCommerce-Testshop mit Stripe-Testmodus aufsetzen (z. B. `wp-env`). Bisher ist kein Kauf-zu-Lizenz-Ablauf real gelaufen. (Produkt)
- [ ] **T-03 [KRITISCH]** WordPress-/MySQL-Integrationstests auf dem Integrationsstand in CI grün bekommen. Lokal liefen nur die 42 Unit-Tests; die Integrationssuite wurde nach der Zusammenführung noch nicht ausgeführt.

## 2. Kritisch: vor dem ersten echten Verkauf

### Kauf und Verlängerung

- [ ] **T-10 [KRITISCH]** Manuelle Verlängerung bauen (Kundenkonto-Button, Verlängerungsbestellung, `expired → active` in der Nachfrist, Admin-Aktion). Konzept: [CONCEPT-CHECKOUT-RENEWAL.md](CONCEPT-CHECKOUT-RENEWAL.md). Ohne sie endet jede Lizenz ohne Auto-Verlängerung endgültig. (Produkt)
- [ ] **T-11 [KRITISCH]** Steuern in Verlängerungsbestellungen korrigieren. `RenewalProcessor::get_or_create_order()` übergibt den Katalogpreis als Positionssumme und ruft `calculate_totals()` auf. Bei Bruttopreisen im Shop wird die Steuer doppelt aufgeschlagen; `reconcile_paid_renewal_order()` vergleicht danach brutto mit dem gespeicherten Betrag und schlägt fehl. (Code, alt 54d)
- [ ] **T-12 [KRITISCH]** Auto-Verlängerung in der Stripe-Sandbox abnehmen: Erfolg, Ablehnung, 3DS, Timeout, widerrufene Karte. Dabei prüfen, ob die Filter `wc_stripe_force_save_source` / `wc_stripe_force_save_payment_method` die Karte beim Erstkauf wirklich speichern. Bis dahin Auto-Verlängerung nicht bewerben. (alt PR14, 50, 51)
- [ ] **T-13 [KRITISCH]** Stripe-Idempotenz bei Wiederholungen klären. Alle Versuche einer Verlängerung senden denselben Idempotency-Key; Stripe liefert für einen bekannten Key die gespeicherte erste Antwort. Lösung: vor jedem Versuch den vorhandenen PaymentIntent abfragen, Key pro Versuch nur nach bestätigtem Fehlschlag wechseln. (Code)
- [ ] **T-14 [KRITISCH]** Checkout Blocks: entweder Store-API-Erweiterung für das Opt-in bauen oder den Shop verbindlich auf den klassischen Checkout festlegen und das dokumentieren. Aktuell als inkompatibel deklariert. (Architektur, alt 48)
- [ ] **T-15 [KRITISCH]** Verlängerungspreis festlegen. `Catalog::save_mapping()` speichert `get_price()` inklusive laufendem Aktionspreis als dauerhaften Renewal-Betrag. (Produkt)
- [ ] **T-16 [KRITISCH]** Erstauslieferung des Premium-ZIP festlegen. Der Updatefeed setzt eine Aktivierung voraus; wie der Käufer das Plugin zum ersten Mal erhält (WooCommerce-Download oder Download im Kundenkonto), ist nirgends umgesetzt. (Produkt)

### Sicherheit und Schlüssel

- [ ] **T-20 [KRITISCH]** Produktive Schlüssel als Konstanten erzwingen (`WPLC_LICENSE_KEY_SECRETS`, `WPLC_INSTALLATION_ENCRYPTION_KEYS`). Ohne sie hängen alle Lizenzschlüssel an `wp_salt()`; eine Salt-Rotation macht jeden ausgegebenen Schlüssel ungültig. Admin-Hinweis anzeigen, solange der Fallback aktiv ist. (Security)
- [ ] **T-21 [KRITISCH]** Aktivierungs-Rate-Limit hinter Proxy/CDN. `ActivationService::activation_attempt_count()` zählt nach `REMOTE_ADDR`; hinter Cloudflare teilen sich alle Kunden 30 Versuche pro Minute, ein Angreifer kann Aktivierungen für alle blockieren. Vertrauenswürdige Proxy-Header konfigurierbar machen und zusätzlich pro Schlüssel-Präfix zählen. (Security)
- [ ] **T-22 [KRITISCH]** Sicherheitsreview der REST-Authentifizierung mit parallelen Anfragen in WordPress/MySQL: Replay, Flood, Datenbankfehler, Rotation. (alt 43c, 61c, 61d)
- [ ] **T-23 [KRITISCH]** Negative Autorisierungstests für alle schreibenden Endpunkte (fremde Kunden-ID, fehlende Nonce, fehlende Capability). (alt 46)

### Updates

- [ ] **T-30 [KRITISCH]** Paketquelle bedienbar machen. Updates existieren nur, wenn Code den Filter `wplc_update_packages` füllt; es gibt keine Admin-Oberfläche und keine Ablage. Mindestens: Release-Tabelle plus Adminseite (Version, URL, SHA-256, Mindestversionen). (UX, alt PR17)
- [ ] **T-31 [KRITISCH]** Privaten Paketspeicher einrichten (S3/GCS oder geschütztes Verzeichnis) und `wplc_resolve_update_package_url` daran anbinden. (alt 31, 57)
- [ ] **T-32 [KRITISCH]** WordPress-Updater Ende-zu-Ende im Testshop: gültiges Update, abgelaufener Grant, falsche Prüfsumme, inaktive Lizenz, Multisite. (alt 56, 58)

### Produkt-Plugins

- [ ] **T-40 [KRITISCH]** Client-PRs in den drei Produkt-Repositories auf den neuen Serverstand abgleichen und mergen: wp-couponmaster 12/13, wp-passmaster 22/23, wpaffiliatemaster 11/12. (alt PR16)
- [ ] **T-41 [KRITISCH]** Entitlement-Keys aus [ENTITLEMENTS.md](ENTITLEMENTS.md) mit den echten Tarifen abgleichen, dann Feature-Gates nur für neue Premiumfunktionen verdrahten. (alt 24, 55)
- [ ] **T-42 [KRITISCH]** Realen Shopkatalog importieren und Zuordnung Produkt → Tarif abnehmen. (alt 22)

## 3. Wichtig: vor dem öffentlichen Launch

### Fehler und Lücken im Code

- [ ] **T-50 [WICHTIG]** Erstattung einer Verlängerungsbestellung behandeln. `handle_refund()` und `handle_full_refund()` suchen nur nach `source_order_id`; ein Refund auf eine Renewal-Bestellung ändert nichts an der Lizenz. (Code)
- [ ] **T-51 [WICHTIG]** Chargebacks anbinden. Der Hook `wplc/chargeback` wird nirgends ausgelöst; ebenso unbenutzt: `wplc/renewal_payment_failed`, `wplc/renewal_payment_succeeded`. Stripe-Event `charge.dispute.created` auswerten. (Code)
- [ ] **T-52 [WICHTIG]** Verwaiste Staging-Aktivierungen. Wird die Live-Site deaktiviert (Portal, Admin, API), bleibt ihre Staging-Site aktiv; so lassen sich mehr Sites betreiben als lizenziert. Staging beim Deaktivieren der Eltern-Site mit deaktivieren. (Code)
- [ ] **T-53 [WICHTIG]** Reaktivierung derselben Installation. `LicenseClient::activate()` erzeugt jedes Mal eine neue Installations-ID; nach verlorenen Optionen oder Datenbank-Restore belegt die alte Aktivierung weiter einen Platz. Server: gleiche `site_url` auf derselben Lizenz ersetzt die alte Aktivierung. (Code/UX)
- [ ] **T-54 [WICHTIG]** Absturz zwischen Abbuchung und Buchung. Bleibt eine Verlängerung nach erfolgreicher Zahlung im Status `processing`, holt sie nur der Webhook zurück. Geplanten Abgleichjob für hängende `processing`-Zeilen ergänzen. (alt 52b, 53)
- [ ] **T-55 [WICHTIG]** `reconcile_paid_renewal_order()` rechnet mit `float` und `wc_get_price_decimals()` statt mit `Money` und den an der Lizenz gespeicherten Dezimalstellen. (Code)
- [ ] **T-56 [WICHTIG]** `wc_get_orders()` mit `meta_query` in `get_or_create_order()` unter klassischer Bestellspeicherung (ohne HPOS) verifizieren; dort ist `meta_query` nicht garantiert. (Code, alt 47)
- [ ] **T-57 [WICHTIG]** Antwortsignatur robuster machen. Server und Client signieren jeweils ihr eigenes `wp_json_encode()` der Daten; das hält nur, solange beide Seiten identisch kodieren. Den rohen JSON-String signieren und unverändert übertragen; zusätzlich die Request-Nonce in die Antwort binden. (Security)
- [ ] **T-58 [WICHTIG]** Stripe-Adapter von internen Klassen lösen. `WC_Stripe_API::request()`, `WC_Stripe_Helper` und die User-Option `_stripe_customer_id` sind keine öffentliche API des Stripe-Plugins. Unterstützte Version festschreiben und beim Laden prüfen. (Architektur)
- [ ] **T-59 [WICHTIG]** `GET_LOCK` setzt eine einzelne schreibende MySQL-Instanz voraus (kein Galera-/Multi-Primary-Betrieb). Als Betriebsvoraussetzung dokumentieren oder auf Zeilensperren umstellen. (Architektur)
- [ ] **T-60 [WICHTIG]** Datenmigrationen. `Schema::migrate()` führt nur `dbDelta` aus; es gibt keinen Weg, bestehende Zeilen bei Schemaänderungen umzuschreiben. Versionierte Migrationsschritte einführen und Upgrade von jeder Schemaversion testen. (Architektur, alt 59)
- [ ] **T-61 [WICHTIG]** Multisite-Verhalten festlegen und umsetzen (netzwerkweite Aktivierung, Tabellen pro Site, Aufräumen bei Site-Löschung). (Produkt)

### Bedienung

- [ ] **T-70 [WICHTIG]** Einstellungsseite statt Konstanten und Filter: Stripe-Webhook-Secret-Status, Paket-Hosts, Schlüsselstatus, Kulanzdauer. (UX)
- [ ] **T-71 [WICHTIG]** Admin: Lizenz-Detailansicht mit Aktivierungen, Verlängerungen, Audit-Verlauf; Lizenz manuell anlegen; Laufzeit ändern; abgelaufene Lizenz reaktivieren; Suche nach vollständigem Schlüssel. (UX, alt PR19)
- [ ] **T-72 [WICHTIG]** Admin-Fehler verständlich anzeigen. Abgelehnte Aktionen enden in einer `wp_die()`-Seite; stattdessen Rückleitung mit Admin-Hinweis. (UX)
- [ ] **T-73 [WICHTIG]** Kundenkonto: Kopieren-Button für den 106 Zeichen langen Schlüssel, übersetzte Statusnamen statt `past_due`, Datumsangaben in Shop-Zeitzone und -Format, Bestätigung vor Deaktivierung, Kündigung zurücknehmen, Download des Premium-ZIP. (UX)
- [ ] **T-74 [WICHTIG]** E-Mails: Lizenz ausgestellt, Ablauf-Erinnerung (30/7 Tage), Ankündigung der Abbuchung, Zahlung fehlgeschlagen, Lizenz abgelaufen. Bisher gibt es nur einen Hinweis in der Bestell-E-Mail. (UX/Produkt)
- [ ] **T-75 [WICHTIG]** Produkteditor: Entitlements als Auswahl aus dem Katalog statt freiem JSON-Feld. (UX)
- [ ] **T-76 [WICHTIG]** Übersetzung: `.pot`-Datei erzeugen, deutsche Übersetzung liefern, Verzeichnis `languages/` anlegen (im Plugin-Header deklariert, fehlt im Repository). (Produkt)

### Tests und Qualität

- [ ] **T-80 [WICHTIG]** CI vervollständigen: PHPCS mit WordPress Coding Standards, PHPStan, Matrix aus minimaler und aktueller WordPress-/WooCommerce-Version. (alt PR18, 38)
- [ ] **T-81 [WICHTIG]** WooCommerce-Integrationstests: Lizenzvergabe, doppelte Hooks, Menge > 1, Variation, Teil- und Vollrefund, HPOS an/aus. (alt 37, 47)
- [ ] **T-82 [WICHTIG]** Integrationstests Verlängerung: Sperren, Wiederholungen an Tag 1/3/7, Kulanzende, Kündigung während eines Laufs, Refund während Retry. (alt 53)
- [ ] **T-83 [WICHTIG]** REST-Laufzeittests gegen `docs/openapi-v1.json` und gemeinsame Client-Fixtures. (alt 41c)
- [ ] **T-84 [WICHTIG]** Konkurrenztests für Statuswechsel und Einmal-Downloadgrants. (alt 45d, 58)
- [ ] **T-85 [WICHTIG]** Webhook-Tests: verspätete, doppelte und falsch verknüpfte Events; Wiederaufnahme hängender Inbox-Einträge. (alt 49)
- [ ] **T-86 [WICHTIG]** Schlüsselrotation und Key-ID-Recovery an einer Datenbankkopie mit Backup/Restore abnehmen. (alt 42f, 42g)
- [ ] **T-87 [WICHTIG]** URL-Normalisierung bei Domainwechsel, im Kundenkonto und bei Staging testen; Regeln für IDN und lokale Hosts festschreiben. (alt 44c)
- [ ] **T-88 [WICHTIG]** Feature-Gates und Offline-Cache in allen drei Clients mit denselben Fixtures auf PHP 7.4/8.1/8.2 testen: Manipulation, Ablauf nach 14 Tagen, Serverfehler, Widerruf. (alt 55)
- [ ] **T-89 [WICHTIG]** Last- und Datenschutztests: Mengengerüst, Abfragepläne, Export ab 100 Datensätzen, Protokollredaktion. (alt 60)

### Betrieb und Recht

- [ ] **T-90 [WICHTIG]** Produktivserver: HTTPS, Schlüsselverwaltung, Backups, erprobte Wiederherstellung. (alt PR20)
- [ ] **T-91 [WICHTIG]** Monitoring anschließen: Empfänger für `wplc/health_alert`, Überwachung von Action Scheduler, Webhooks, Paket-Host. (alt 39)
- [ ] **T-92 [WICHTIG]** Automatische Datenbereinigung nach konfigurierbarer Aufbewahrung für Audit-Log, Event-Inbox und deaktivierte Aktivierungen. (alt Datenschutzrunde)
- [ ] **T-93 [WICHTIG]** Rechtliche Prüfung: Kündigungsbutton, Informationspflichten bei automatischer Verlängerung, Widerruf bei digitalen Inhalten, Datenschutzhinweise, Rechnungen und Umsatzsteuer für Verlängerungen, GPL und Add-on-Vertrieb. (alt PR20)
- [ ] **T-94 [WICHTIG]** Supportabläufe festlegen: verlorener Schlüssel, Domainwechsel, Platzlimit, Refund, Zahlungsausfall. Verantwortliche für Release, Support, Incident und Rollback benennen. (alt PR19, PR20)
- [ ] **T-95 [WICHTIG]** Release-Build: ZIP in CI bauen, Manifest und SHA-256 erzeugen, unveränderliche Versionen, Rollback mit echtem Paket testen. (alt 30, 32, 57)
- [ ] **T-96 [WICHTIG]** Launch-Nachweise in [LAUNCH-EVIDENCE.md](LAUNCH-EVIDENCE.md) ausfüllen. (alt 40)

## 4. Optimierung

- [ ] **T-100 [OPTIMIERUNG]** `Schema::maybe_upgrade()` liest bei jedem Request eine nicht automatisch geladene Option; Schemaversion mit Autoload speichern. (Architektur)
- [ ] **T-101 [OPTIMIERUNG]** N+1-Abfragen in `LicenseAdmin::render()` und `CustomerPortal::render()` (pro Lizenz zwei bis drei Abfragen) zusammenfassen; Kundenportal paginieren. (Architektur)
- [ ] **T-102 [OPTIMIERUNG]** Adminliste auf `WP_List_Table` umstellen (Sortierung, Bulk-Aktionen, Filter nach Bestellung und Verlängerung). (UX, alt 33)
- [ ] **T-103 [OPTIMIERUNG]** `flush_rewrite_rules()` läuft nur bei der Aktivierung; nach Plugin-Updates kann der Endpunkt `wplc-licenses` 404 liefern. Bei Versionswechsel einmalig neu schreiben. (Code)
- [ ] **T-104 [OPTIMIERUNG]** Mindestversion von WooCommerce/Action Scheduler festschreiben; `as_schedule_single_action()` wird mit dem `unique`-Parameter aufgerufen. (Code)
- [ ] **T-105 [OPTIMIERUNG]** `ABSPATH`-Schutz in `SiteUrl.php`, `Money.php`, `LicenseStateMachine.php` ergänzen (reine Klassen, kein Risiko, aber uneinheitlich). (Security)
- [ ] **T-106 [OPTIMIERUNG]** Kürzeres Schlüsselformat. Der Schlüssel besteht aus UUID plus 64 Hex-Zeichen. (UX)
- [ ] **T-107 [OPTIMIERUNG]** Caching-Hinweise dokumentieren: `/wp-json/wplc/` und das Kundenkonto von Seiten- und CDN-Cache ausnehmen. (Architektur)
- [ ] **T-108 [OPTIMIERUNG]** Download-Token steht im URL-Pfad und landet in Zugriffslogs. Einmalig und 10 Minuten gültig; alternativ als Header übertragen. (Security)
- [ ] **T-109 [OPTIMIERUNG]** Add-ons und anteilige Berechnung: `Money::prorate()` existiert, ist aber nirgends angebunden. (alt 54d)
- [ ] **T-110 [OPTIMIERUNG]** Tarifwechsel (Upgrade/Downgrade) einer bestehenden Lizenz. (Produkt)

## 5. Blockiert oder zu entscheiden

- [ ] **T-120** PayPal-Verlängerungen: bleiben gesperrt, bis ein Vertragsmodell ohne WooCommerce Subscriptions verfügbar ist. Mit T-10 können PayPal-Kunden manuell verlängern. (alt PR15)
- [ ] **T-121** Eigenbau-Renewals oder WooCommerce Subscriptions: nach T-12 entscheiden.
- [ ] **T-122** Zahlungsereignisse: Der Stand nutzt beides, synchrones Adapter-Ergebnis und Webhook als Abgleich. Zuständigkeit schriftlich festlegen. (alt Abschnitt 7)

## 6. Erledigt am 13.11.2026 (Konsolidierung)

Umgesetzt und durch Unit-Tests oder Lint belegt; die Abnahme im Testshop steht unter T-02/T-03 aus.

- [x] 37 Branches in einem Stand zusammengeführt; liegengebliebene Fixes aus `pr-03` bis `pr-11`, `phase-13`, `phase-14`, `phase-19`, `phase-22` übernommen (u. a. Statusprüfung vor Abbuchung, Development-Aktivierung nur auf lokalen Hosts, Aktivierung unter Sperre erneut gelesen, Entitlement-JSON-Validierung, Bereinigung alter Downloadgrants).
- [x] Zweiter, paralleler Stripe-Webhook (`/stripe/webhook`) nicht übernommen; es gibt nur `/wplc/v1/webhooks/stripe`.
- [x] Plugin lädt ohne Composer (PSR-4-Autoloader statt unvollständiger `require`-Liste).
- [x] HPOS-Kompatibilität deklariert.
- [x] Checkout blockiert Neukunden nicht mehr; Auto-Verlängerung ist Opt-in; Lizenzkauf erzwingt Kundenkonto; Bestell-E-Mail verweist auf den Schlüssel.
- [x] Gesperrte und abgelaufene Lizenzen werden nicht mehr belastet; Ablaufjob kollidiert nicht mehr mit laufender Verlängerung.
- [x] Vollrefund ohne Positionszeilen widerruft die Lizenz.
- [x] 14 Tage Kulanz wirken tatsächlich (`LicenseLifecycle::has_access()`, 7 neue Tests).
- [x] 3DS: Verlängerungsbestellung bleibt verknüpft, Bezahllink erscheint.
- [x] Stripe-Webhook quittiert fremde Zahlungen mit 200 statt 400.
- [x] Lizenz bleibt `pending`, bis ihre Entitlements gespeichert sind.
- [x] Deaktivierung räumt beide Cron-Hooks ab; `uninstall.php` ergänzt (Daten bleiben bewusst erhalten).
- [x] Release-Werkzeug nur noch per CLI aufrufbar.
