# WP License Captain — To-do-Liste

Stand: 14.11.2026, Branch `claude/zen-babbage-7q4nac` (19 Aufgaben abgeschlossen, siehe Abschnitt 6 „Abgeschlossen am 14.11.2026“). Dies ist die einzige Aufgabenliste des Lizenzservers. Die Aufgaben der drei Produkt-Plugins (Kern-Plugin und Premium-Add-on) stehen in deren eigenen `TODO.md`.

**Regeln**

- Ein Haken bedeutet: umgesetzt **und** belegt. Der Beleg steht dabei.
- Belegarten: *Unit* (PHPUnit ohne WordPress), *Integration* (WordPress + WooCommerce + MySQL, 4 Kombinationen in CI, 181 Tests), *E2E* (Durchlauf in einer echten WordPress/WooCommerce-Installation, `tests/e2e/`, 684 Prüfungen, seit 14.11. auch als CI-Job), *Quelltext* (gegen den Quellcode von WooCommerce oder des Stripe-Plugins gelesen, nicht ausgeführt).
- Was nur per *Quelltext* belegt ist, gilt als offen, bis es im Browser oder gegen Stripe gelaufen ist.
- Kritikalität: **[KRITISCH]** blockiert den ersten echten Verkauf, **[WICHTIG]** blockiert den öffentlichen Launch, **[OPTIMIERUNG]** kann danach folgen.
- Nummern bleiben stabil; erledigte Nummern stehen in Abschnitt 6.

## 1. Was noch nie gelaufen ist

Diese drei Lücken lassen sich nicht durch weiteren Code schließen.

- [ ] **T-12 [KRITISCH] Stripe-Testmodus.** Automatische Verlängerung, 3DS, Kartenspeicherung beim Erstkauf, Webhooks und Streitfälle sind nur per *Quelltext* und mit einem Test-Adapter geprüft. Durchspielen: Erfolg, Ablehnung, 3DS, Timeout, widerrufene Karte, Streitfall. Dabei mitprüfen:
  - T-13: Idempotency-Key pro Versuch (`{Schlüssel}:{Versuch}`) verhindert Doppelbelastung.
  - T-51: Ereignis `charge.dispute.created` im Stripe-Dashboard aktivieren; Streitfall sperrt die Lizenz.
  - T-58: Adapter gegen Stripe-Plugin 9.6 bis 11.2 gelesen; die Karte der Bestellung wird über `_stripe_source_id` gefunden.
  - T-133: Im Modus „Checkout Sessions / Adaptive Pricing“ des Stripe-Plugins speichert der Filter die Karte möglicherweise nicht.
- [ ] **T-130 [KRITISCH] Browser-Abnahme.** Noch niemand hat das Plugin auf dem Bildschirm gesehen. Prüfen: Block-Checkout mit Opt-in (T-14, nur *Quelltext*), klassischer Checkout, Produkteditor mit Rechte-Auswahl und Varianten (JavaScript ungeprüft), Kundenkonto mit Kopieren-Button und Bestätigungen, Adminliste, Detailseite, Einstellungen, Releases, die deutschen Texte, die sechs E-Mails in einem Mailprogramm. *Vorbereitet am 14.11.:* Screenshots aller Seiten und Mails auf Englisch und Deutsch mit Chromium; dabei drei Fehler gefunden und behoben (Opt-in bei Überweisung sichtbar, interner Tarifschlüssel in Mails, Umbrüche in der Lizenzliste). Nicht prüfbar waren Block-Checkout und WooCommerce-Oberflächen (Testumgebung ohne gebaute WooCommerce-Skripte) und die deutschen Texte von WordPress und WooCommerce selbst. Offen bleibt die Abnahme durch einen Menschen.
- [ ] **T-131 [KRITISCH] Produkt-Add-ons.** Kein Produkt-Plugin spricht bisher mit dem Server. Die Aufgaben stehen in den `TODO.md` von `wp-couponmaster`, `wp-passmaster` und `wpaffiliatemaster` (Abschnitt „Premium-Add-on“, B1 bis B11). Ersetzt die früheren T-40 und T-41. Jedes Add-on muss den öffentlichen Signaturschlüssel des Servers an `LicenseClient` übergeben (T-134, `docs/CLIENT-INTEGRATION.md`).

## 2. Kritisch: vor dem ersten echten Verkauf

- [ ] **T-42 [KRITISCH] Echter Katalog.** Produkte, Tarife und Rechte pro Tarif im Shop zuordnen und abnehmen. Die Produktschlüssel müssen zu den Add-ons passen.
- [ ] **T-90 [KRITISCH] Produktivserver.** HTTPS, Schlüssel als Konstanten in `wp-config.php`, Backups, erprobte Wiederherstellung, eine einzelne schreibende Datenbankinstanz (siehe `docs/CONCEPT-CHECKOUT-RENEWAL.md`, Datenbank-Voraussetzungen).

## 3. Wichtig: vor dem öffentlichen Launch

### Fehler und Lücken im Code

- [ ] **T-155 [WICHTIG]** `payment_intent.payment_failed` per Webhook löst eine Verlängerung im Status `reconciling` nicht auf; dazu fehlt eine Versuchsnummer in den Metadaten des PaymentIntent. Heute klärt der stündliche Abgleich mit demselben Idempotency-Key den Fall.
- [ ] **T-61 [WICHTIG]** Multisite: Mindestverhalten umgesetzt (`docs/MULTISITE.md`), auf einem Netzwerk nie ausgeführt.

### Tests und Betrieb

- [ ] **T-148 [WICHTIG]** Zweimal schlug ein einzelner Integrationstest lokal einmalig fehl, als zwei Testläufe denselben Datenbankserver nutzten. Die serverweiten Sperrnamen sind inzwischen pro Site eindeutig; beobachten, ob es in CI wieder auftritt. Am 14.11. traten ähnliche Zufallsfehler nur auf, solange parallele Testläufe versehentlich dieselbe Datenbank nutzten; mit getrennten Datenbanken waren alle Läufe stabil.
- [ ] **T-95 [WICHTIG]** Release-Workflow (`.github/workflows/release.yml`) ist geschrieben und nie gelaufen. Mit einem Test-Tag ausführen und das ZIP auf einer leeren Installation prüfen.
- [ ] **T-86 [WICHTIG]** Schlüsselrotation und Key-ID-Wiederherstellung (WP-CLI) an einer Datenbankkopie mit Backup und Restore abnehmen.
- [ ] **T-89 [WICHTIG]** Lasttest mit realistischen Mengen; Abfragepläne der Listen prüfen.
- [ ] **T-91 [WICHTIG]** Monitoring anschließen: Empfänger für `wplc/health_alert`, Überwachung von Action Scheduler, Webhooks und Paket-Host (`docs/MONITORING.md`).
- [ ] **T-31 [WICHTIG]** Externer Paketspeicher (S3 oder GCS) ist nur dokumentiert. Bei Bedarf anbinden und abnehmen.
- [ ] **T-93 [WICHTIG]** Rechtliche Prüfung: Kündigungsbutton, Hinweispflichten bei automatischer Verlängerung, Widerruf bei digitalen Inhalten, Umsatzsteuer und Rechnungen für Verlängerungen, Datenschutzhinweise.
- [ ] **T-94 [WICHTIG]** Supportabläufe und Verantwortliche festlegen (`docs/SUPPORT-ACTIONS.md` beschreibt, was im Admin möglich ist).
- [ ] **T-96 [WICHTIG]** Launch-Nachweise in `docs/LAUNCH-EVIDENCE.md` ausfüllen.
- [ ] **T-157 [WICHTIG]** PHPStan lief am 14.11. lokal nicht (Download von `phpstan/phpstan` blockiert). Der erste CI-Lauf fand 13 Befunde und einen Integrationstest, der nur mit gebautem WooCommerce scheitert; beides behoben, der grüne CI-Lauf steht noch aus.
- [ ] **T-149 [WICHTIG]** Alte Branches `codex/*` löschen. Alle 37 sind vollständig in `main` enthalten.

## 4. Optimierung

- [ ] **T-106 [OPTIMIERUNG]** Kürzeres Schlüsselformat (heute 106 Zeichen).
- [ ] **T-108 [OPTIMIERUNG]** Download-Token steht weiter im URL-Pfad. Die Antwort verbietet inzwischen Caching und Referrer.
- [ ] **T-109 [OPTIMIERUNG]** Add-ons mit anteiliger Berechnung; `Money::prorate()` ist nirgends angebunden.
- [ ] **T-110 [OPTIMIERUNG]** Tarifwechsel einer bestehenden Lizenz.
- [ ] **T-102 [OPTIMIERUNG]** Adminliste: Bulk-Aktionen und Filter nach Bestellung oder Verlängerung.
- [ ] **T-150 [OPTIMIERUNG]** Die Bezeichnungen der Produktfunktionen im Rechtekatalog liegen in der Textdomain dieses Plugins und müssen hier mitübersetzt werden. In die Add-ons verlagern.
- [ ] **T-151 [OPTIMIERUNG]** WP-CLI-Ausgaben und Logmeldungen sind englisch.
- [ ] **T-156 [OPTIMIERUNG]** Systemweite Audit-Einträge (Einstellungen, CSV-Import, Schlüsselrotation; Objekt-ID 0) erscheinen auf keiner Adminseite.

## 5. Entscheidungen

- [ ] **T-121** Eigenbau-Verlängerung behalten oder WooCommerce Subscriptions einsetzen. Nach T-12 entscheiden.
- [ ] **T-152** Behält eine bezahlte Bestellung, die ohne Erstattung storniert wird, ihre Lizenz? Heute ja.
- [ ] **T-153** Bleiben Entwicklungs-Aktivierungen auf lokalen Hosts unbegrenzt? Heute ja.
- [ ] **T-120** PayPal: keine automatische Verlängerung, solange es kein passendes Vertragsmodell gibt. Manuelle Verlängerung funktioniert mit jeder Zahlungsart.
- [ ] **T-122** Zahlungsereignisse: Adapter-Ergebnis und Webhook werden beide ausgewertet; der Webhook ist der Abgleich. Zuständigkeit schriftlich bestätigen.

## 6. Erledigt

### Abgeschlossen am 14.11.2026

Belege: *Unit* 489 Tests, *Integration* 181 Tests (HPOS und klassische Bestellspeicherung, MariaDB 10.11, WordPress 7.1.4, WooCommerce 11.2.2), *E2E* 684 Prüfungen, blockierendes PHPCS ohne neue Befunde. PHPStan siehe T-157.

- [x] **T-132** Verlängerungspreis der gekauften Variante wird an der Lizenz gespeichert (`renewal_product_id`, Migration 13). Automatische Verlängerung belastet den zugestimmten Betrag; manuelle Verlängerung nutzt den aktuellen regulären Preis der gekauften Variante. *Integration, E2E (79,00 statt 59,00).* Offen bleibt nur die Frage, ob Altlizenzen nachkorrigiert werden; vor dem ersten Verkauf gegenstandslos.
- [x] **T-134** `LicenseClient` prüft die Ed25519-Signatur gegen fest hinterlegte öffentliche Schlüssel (mehrere während einer Rotation), auch für den Offline-Cache (`check_entitlements_envelope()`, `verify_cached_envelope()`). *Unit: gefälschte Antwort mit gültigem HMAC wird abgelehnt.* Die Add-ons müssen den Schlüssel einbauen (T-131).
- [x] **T-135** Paketablage außerhalb des Webroots per `WPLC_PACKAGE_STORAGE_DIR`; Selbsttest auf öffentliche Abrufbarkeit in Diagnose und Releases; nginx-Regel dokumentiert. *Unit, E2E.*
- [x] **T-136** Unklares Zahlungsergebnis: Lizenz bleibt unverändert, keine Fehlermail, Status `reconciling`, stündlicher Abgleich mit demselben Idempotency-Key, nach 12 Stunden `review_required` mit Alarm. *Unit, Integration (12 Tests), E2E.* Folgeaufgaben T-154, T-155.
- [x] **T-50** Teilerstattung einer Verlängerung: Laufzeit bleibt, Audit-Eintrag und Bestellnotiz; Vollerstattung (auch schrittweise) setzt die Verlängerung zurück. *Integration.*
- [x] **T-138** Audit-Begründungen als Codes gespeichert und bei der Anzeige übersetzt (`AuditReason`); Stripe-Zahlungsbeschreibung übersetzbar. *Unit.*
- [x] **T-139** Pluralform „Live-Website(s)“, „Release“ mit Kontext, Produktname statt Tarifschlüssel im Kundenkonto, Textdomain vor der Aktivierung geladen. 83 neue Texte in `de_DE` und `de_DE_formal`. *Geprüft mit WordPress (Plural- und Kontextsuche); nicht auf dem Bildschirm, siehe T-130.*
- [x] **T-140** Absenderhinweis in allen sechs E-Mails (HTML und Text). *Unit.*
- [x] **T-141** Adminsuche per POST; die URL trägt nur ein zufälliges Token, ein voller Schlüssel wird nur als Hash gespeichert. *Unit, Integration, E2E.*
- [x] **T-142** Aktivierungs-Limit zählt nur Fehlversuche; ein gültiger Schlüssel kommt durch, mit Obergrenze gegen Ausprobieren. Ein abgelehnter Speicherort verbraucht das Download-Token nicht mehr. *Unit, Integration.*
- [x] **T-143** `overdue_renewals` zählt nur hängende Verlängerungen; Einstellungsänderungen und CSV-Importe schreiben Audit-Einträge. *Unit, Integration.*
- [x] **T-144** `wplc_reminder_log` im Schema (Migration 12). *Unit, Integration, E2E (Upgrade).*
- [x] **T-145** API-Spezifikation deklariert 409, 429 und 503; die drei unvollständigen Integrationstests prüfen jetzt hart. *Integration.*
- [x] **T-146** `SiteUrl`: nicht kanonische IPv4-Schreibweisen abgelehnt, Pfade normalisiert, Umlaut-Domains auch ohne `intl` (gegen `idn_to_ascii` über alle BMP-Zeichen geprüft); bestehende Aktivierungen werden weiter erkannt. *Unit, Integration.*
- [x] **T-154, T-137** Adminseite „Zu prüfende Verlängerungen“ mit Zähler im Menü: unklares Zahlungsergebnis, bezahlt aber Lizenz während der Zahlung geändert, verspätete oder nicht anwendbare manuelle Zahlung. Aktionen „Verlängerung buchen“, „Als nicht bezahlt markieren“, „Ohne Buchung abschließen“, jeweils mit Pflichtbegründung, Sperre und Audit-Eintrag; Doppelklick ändert nichts. Solange ein Fall offen ist, läuft die Lizenz höchstens 30 Tage länger nicht ab (Zugang endet trotzdem mit der Kulanzzeit). Schema 14. *Unit, Integration (11 Tests), E2E.* Bekannte Lücke: Eine gesperrte Lizenz mit abgelaufenem Zeitraum lässt sich nicht reaktivieren, ihr Fall nur schließen.
- [x] **T-22** Echte Parallelität gegen MariaDB: neuer E2E-Ablauf `11-concurrency` mit 8 Server-Prozessen und Startschranke (Nonce-Replay, Site-Limit, Einmal-Download, Statuswechsel während einer Abbuchung, doppelt laufende Verlängerung, mehrfach bezahlte manuelle Verlängerung, parallele Bestell-Hooks; je 28 Runden). Vier Fehler gefunden und behoben: (1) Lizenz während der Abbuchung gesperrt/erstattet/abgelaufen → Verlängerung bezahlt, Lizenz nicht verlängert; jetzt `review_required` mit Alarm. (2) Kündigung während der Abbuchung ging verloren. (3) Statuswechsel ohne Sperre; jetzt eine Sperre pro Lizenz, Reihenfolge Verlängerung → Lizenz. (4) Parallele Bestell-Hooks erzeugten Datenbankfehler und unvollständige Lizenzlisten. *E2E, Integration (6 Regressionstests).* Nicht abgedeckt: parallele Stripe-Webhooks (T-12).
- [x] **T-92** Löschabfragen der Datenbereinigung gegen die Datenbank. *Integration.*
- [x] **T-147** E2E-Lauf als CI-Job (`.github/workflows/e2e.yml`). Dabei behoben: Der Pfad `wplc-e2e-site` wurde dem Plugin zugeschrieben, jede fremde Warnung ließ den Lauf scheitern. *Lokal mit MariaDB grün; auf GitHub noch nicht gelaufen.*

### Entscheidungen

- [x] Kern-Plugins bleiben kostenlos, ohne Lizenzprüfung und ohne gesperrte Funktionen; Premium liegt in getrennten Add-ons (13.11.2026, `docs/IMPLEMENTATION-PLAN.md`). Kernfunktionen sind keine Rechte mehr (`EntitlementCatalog`).
- [x] Auto-Verlängerung ist ein Opt-in; ein Kauf hängt nie davon ab (`docs/CONCEPT-CHECKOUT-RENEWAL.md`).
- [x] Mindestversion WooCommerce 8.9 (T-104).

### Stand und Qualitätssicherung

- [x] **T-01** Alle 37 Branches zusammengeführt und nach `main` gemergt; 35 alte PRs geschlossen.
- [x] **T-03, T-80** CI: Unit auf PHP 7.4 bis 8.3, vier Integrationszellen (WordPress 6.5 und aktuell, WooCommerce 9.4 und 11.2, HPOS und klassische Bestellspeicherung), PHPCS-Sicherheits-Sniffs, PHPStan. *Beleg: grüne Läufe auf `main`.*
- [x] **T-02 (lokal)** E2E-Harness mit 12 Abläufen und 542 Prüfungen. *Ohne Stripe und ohne Browser.*
- [x] **T-81, T-23, T-83, T-85** Integrationstests für Lizenzvergabe, Erstattungen, Autorisierung, API-Konformität und Webhook-Eingang (131 Tests).

### Kauf und Verlängerung

- [x] **T-10** Manuelle Verlängerung derselben Lizenz mit jeder Zahlungsart, Zeitfenster 30 Tage vor bis 30 Tage nach Ablauf, Admin-Aktion „Laufzeit ändern“. *E2E 48 Prüfungen.*
- [x] **T-11, T-55** Steuern in Verlängerungsbestellungen; Beträge ohne Fließkomma. *E2E: 36 Preis- und Länderpaare in beiden Steuermodi.*
- [x] **T-15** Verlängerungspreis ist der reguläre Preis, kein Aktionspreis. *Für Varianten siehe T-132.*
- [x] **T-54** Hängende Verlängerungen werden abgeglichen; überfällige Verlängerungen ohne Auftrag werden täglich neu eingeplant. *E2E.*
- [x] Automatische Verlängerung mit Test-Adapter: Erfolg, Ablehnung mit Wiederholungen an Tag 1, 3 und 7, 14 Tage Kulanz, Ablauf, „Kundenaktion nötig“. *E2E 94 Prüfungen.*
- [x] Geplante Aufgaben können sich selbst neu einplanen (`Requirements::schedule_single_action`).
- [x] **T-16, T-30** Release-Verwaltung im Admin, lokale Paketablage, Erstauslieferung im Kundenkonto, Einmal-Download mit Prüfsumme. *E2E 49 Prüfungen.*
- [x] **T-74** Sechs Kunden-E-Mails und Erinnerungen, jede genau einmal. *E2E, Versand abgefangen.*

### API und Sicherheit

- [x] **T-20** Admin-Hinweis, solange Schlüssel aus den WordPress-Salts abgeleitet werden. *Unit.*
- [x] **T-21** Aktivierungs-Limit hinter Proxy und pro Schlüssel. *Unit, Integration.*
- [x] **T-52, T-53** Staging endet mit der Live-Site; dieselbe Site ersetzt ihre alte Aktivierung. *Integration, E2E.*
- [x] **T-57** Antwortsignatur über den rohen Text, an die Anfrage gebunden, abwärtskompatibel; zusätzlich Ed25519-Signatur des Servers. *Unit, Integration, E2E. Client-Prüfung siehe T-134.*
- [x] **T-59** Sperrnamen pro Site eindeutig; Datenbank-Voraussetzungen dokumentiert.
- [x] **T-60, T-100** Versionierte Migrationen; Schema 11. *E2E: Upgrade 10 → 11 auf laufender Datenbank.*

### Bedienung

- [x] **T-70, T-71, T-72, T-101, T-103** Einstellungsseite, Adminliste mit Suche nach E-Mail und Schlüssel, Lizenz-Detailseite, manuelle Lizenz, verständliche Fehlermeldungen. *E2E: Seiten gerendert und Aktionen ausgeführt; nicht im Browser.*
- [x] **T-73, T-75** Kundenkonto und Rechte-Auswahl im Produkteditor. *E2E serverseitig; JavaScript siehe T-130.*
- [x] **T-76** Deutsche Übersetzung, 573 Texte, formelle Anrede. *Formal geprüft; nicht auf dem Bildschirm.*
- [x] **T-105, T-107** Schutz gegen Direktaufruf in allen Klassen; Caching-Hinweise (`docs/CACHING.md`).

### Im E2E-Lauf gefundene und behobene Fehler

- [x] Eine nach Zahlungsausfall bezahlte Lizenz wurde vom alten Kulanz-Auftrag trotzdem auf „abgelaufen“ gesetzt.
- [x] Der Status „Kundenaktion nötig“ passte nicht in die Datenbankspalte; die Karte wurde ein zweites Mal belastet.
- [x] Verlängerungen, die während einer Deaktivierung des Plugins fällig wurden, wurden nie versucht.
- [x] Eine überfällige Lizenz mit Kündigung blieb für immer überfällig.
- [x] „Aktualisieren“ im Produkteditor löschte die Zuordnung eines variablen Produkts.
- [x] Der Tarifpreis hing im Editor einen Speichervorgang hinterher.
- [x] Nach zurückgenommener Kündigung blieb die Verlängerung storniert.
- [x] PHP-Hinweis beim ersten Aufruf nach der Aktivierung.

### In den Integrationstests gefundene und behobene Fehler

- [x] Variante eines auf dem Hauptprodukt zugeordneten Produkts bekam keine Lizenz.
- [x] Doppelter Refund-Hook widerrief eine zusätzliche Lizenz.
- [x] `straße.de` wurde als `strasse.de` gespeichert; IPv6-Loopback nie als Entwicklungs-Site akzeptiert; überlange URLs lieferten einen irreführenden Fehler.
- [x] Abgelehnte Zahlungen landeten im Webhook immer in der manuellen Prüfung.
- [x] Rechteprüfungen leerten die gespeicherten WordPress- und PHP-Versionen.
