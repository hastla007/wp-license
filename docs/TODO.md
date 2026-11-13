# WP License Captain — To-do-Liste

Stand: 13.11.2026, nach der Zusammenführung aller Arbeitspakete auf `main`. Dies ist die einzige Aufgabenliste des Lizenzservers. Die Aufgaben der drei Produkt-Plugins (Kern-Plugin und Premium-Add-on) stehen in deren eigenen `TODO.md`.

**Regeln**

- Ein Haken bedeutet: umgesetzt **und** belegt. Der Beleg steht dabei.
- Belegarten: *Unit* (PHPUnit ohne WordPress), *Integration* (WordPress + WooCommerce + MySQL, 4 Kombinationen in CI), *E2E* (Durchlauf in einer echten WordPress/WooCommerce-Installation, `tests/e2e/`, 542 Prüfungen), *Quelltext* (gegen den Quellcode von WooCommerce oder des Stripe-Plugins gelesen, nicht ausgeführt).
- Was nur per *Quelltext* belegt ist, gilt als offen, bis es im Browser oder gegen Stripe gelaufen ist.
- Kritikalität: **[KRITISCH]** blockiert den ersten echten Verkauf, **[WICHTIG]** blockiert den öffentlichen Launch, **[OPTIMIERUNG]** kann danach folgen.
- Nummern bleiben stabil; erledigte Nummern stehen in Abschnitt 6.
- Abschnitt 7 enthält die 95 Funde des Audits vom 13.11.2026 als Aufgaben A-01 bis A-137. Die sechs kritischen (A-01 bis A-06) gehen den Abschnitten 2 bis 4 vor.

## 1. Was noch nie gelaufen ist

Diese drei Lücken lassen sich nicht durch weiteren Code schließen.

- [ ] **T-12 [KRITISCH] Stripe-Testmodus.** Automatische Verlängerung, 3DS, Kartenspeicherung beim Erstkauf, Webhooks und Streitfälle sind nur per *Quelltext* und mit einem Test-Adapter geprüft. Durchspielen: Erfolg, Ablehnung, 3DS, Timeout, widerrufene Karte, Streitfall. Dabei mitprüfen:
  - T-13: Idempotency-Key pro Versuch (`{Schlüssel}:{Versuch}`) verhindert Doppelbelastung.
  - T-51: Ereignis `charge.dispute.created` im Stripe-Dashboard aktivieren; Streitfall sperrt die Lizenz.
  - T-58: Adapter gegen Stripe-Plugin 9.6 bis 11.2 gelesen; die Karte der Bestellung wird über `_stripe_source_id` gefunden.
  - T-133: Im Modus „Checkout Sessions / Adaptive Pricing“ des Stripe-Plugins speichert der Filter die Karte möglicherweise nicht.
- [ ] **T-130 [KRITISCH] Browser-Abnahme.** Noch niemand hat das Plugin auf dem Bildschirm gesehen. Prüfen: Block-Checkout mit Opt-in (T-14, nur *Quelltext*), klassischer Checkout, Produkteditor mit Rechte-Auswahl und Varianten (JavaScript ungeprüft), Kundenkonto mit Kopieren-Button und Bestätigungen, Adminliste, Detailseite, Einstellungen, Releases, die deutschen Texte, die sechs E-Mails in einem Mailprogramm.
- [ ] **T-131 [KRITISCH] Produkt-Add-ons.** Kein Produkt-Plugin spricht bisher mit dem Server. Die Aufgaben stehen in den `TODO.md` von `wp-couponmaster`, `wp-passmaster` und `wpaffiliatemaster` (Abschnitt „Premium-Add-on“, B1 bis B11). Ersetzt die früheren T-40 und T-41.

## 2. Kritisch: vor dem ersten echten Verkauf

- [ ] **T-42 [KRITISCH] Echter Katalog.** Produkte, Tarife und Rechte pro Tarif im Shop zuordnen und abnehmen. Die Produktschlüssel müssen zu den Add-ons passen.
- [ ] **T-132 [KRITISCH] Verlängerungspreis bei Varianten.** Ist ein variables Produkt nur auf dem Hauptprodukt zugeordnet, speichert der Tarif den niedrigsten Variantenpreis. Im E2E-Lauf wurde eine für 79,00 gekaufte Variante mit 59,00 verlängert. Regel festlegen: Zuordnung pro Variante erzwingen oder den Preis der gekauften Variante an der Lizenz speichern.
- [ ] **T-134 [KRITISCH] Server-Signatur im Client prüfen.** Der Server liefert jetzt zusätzlich `signature_ed25519` und `key_id` (zusammengeführt aus dem Branch `claude/youthful-bardeen-7ejd7a`). Der mitgelieferte `LicenseClient` prüft sie noch nicht. Im Add-on den öffentlichen Schlüssel fest hinterlegen (`wp wplc signing-key --field=public_key`) und Antworten sowie den Offline-Cache ohne gültige Signatur ablehnen. Produktiv den Schlüssel über `WPLC_RESPONSE_SIGNING_KEY` setzen.
- [ ] **T-22 [KRITISCH] Echte Parallelität.** Replay, Sperren, Einmal-Tokens und Statuswechsel sind nur nacheinander getestet (*Integration*). Mit gleichzeitigen Anfragen gegen MySQL prüfen.
- [ ] **T-90 [KRITISCH] Produktivserver.** HTTPS, Schlüssel als Konstanten in `wp-config.php`, Backups, erprobte Wiederherstellung, eine einzelne schreibende Datenbankinstanz (siehe `docs/CONCEPT-CHECKOUT-RENEWAL.md`, Datenbank-Voraussetzungen).
- [ ] **T-135 [KRITISCH] Paketablage auf nginx.** Lokal gespeicherte ZIPs sind per `.htaccess` geschützt; auf nginx schützt nur der zufällige Dateiname. Entweder die Ablage außerhalb des Webroots legen (`wplc_package_storage_dir`) oder eine nginx-Regel dokumentieren und prüfen.

## 3. Wichtig: vor dem öffentlichen Launch

### Fehler und Lücken im Code

- [ ] **T-136 [WICHTIG]** Bei unklarem Zahlungsergebnis (`renewal_result_unknown`) wird die Lizenz auf „Zahlung überfällig“ gesetzt und die Mail „Zahlung fehlgeschlagen“ verschickt, obwohl die Abbuchung gelungen sein kann.
- [ ] **T-50 [WICHTIG]** Teilerstattung einer Verlängerungsbestellung ändert nichts an der Lizenz. Die Vollerstattung ist umgesetzt, aber nicht in einem Test belegt.
- [ ] **T-137 [WICHTIG]** Eine bezahlte manuelle Verlängerung, die außerhalb des Zeitfensters eingeht (späte Überweisung), wird nur per Bestellnotiz vermerkt. Es fehlen Adminliste und Erstattungsweg.
- [ ] **T-138 [WICHTIG]** Etwa 30 Begründungstexte im Audit-Verlauf sind fest auf Englisch und erscheinen so im Admin (`RenewalProcessor`, `ManualRenewal`, `LicenseLifecycle`, `ActivationManager`, `CustomerPortal`). Ebenso die Stripe-Zahlungsbeschreibung auf dem Kundenbeleg (`StripeRecurringAdapter.php`).
- [ ] **T-139 [WICHTIG]** Weitere Übersetzungsfehler: „1 live sites“ ohne Mehrzahlform (`LicenseDetail.php`), „Release“ als Verb und Substantiv ohne Kontext, interner Tarifschlüssel in der Überschrift des Kundenkontos, Aktivierungsfehler erscheinen vor dem Laden der Übersetzung auf Englisch.
- [ ] **T-140 [WICHTIG]** Die Einstellung „Absenderhinweis“ wird gespeichert, aber in keiner Mail ausgegeben.
- [ ] **T-141 [WICHTIG]** Ein in die Adminsuche eingefügter vollständiger Lizenzschlüssel bleibt in der URL, in Sortierlinks, im Verlauf und in Zugriffslogs. Suche per POST umstellen.
- [ ] **T-142 [WICHTIG]** Das Aktivierungs-Limit pro Adresse greift vor der Schlüsselprüfung; ein gültiger Schlüssel wird mitgesperrt. Liefert der Speicher-Hook eine nicht erlaubte URL, ist das Download-Token trotzdem verbraucht.
- [ ] **T-143 [WICHTIG]** Die Kennzahl `overdue_renewals` zählt auch den Normalzustand zwischen letztem Wiederholungsversuch und Kulanzende und löst täglich Alarm aus. Einstellungsänderungen und CSV-Importe schreiben keinen Audit-Eintrag.
- [ ] **T-144 [WICHTIG]** Die Tabelle `wplc_reminder_log` legt sich selbst an und ist dem Schema nicht bekannt. In `Schema` übernehmen.
- [ ] **T-145 [WICHTIG]** API-Spezifikation: die Antworten 409, 429 und 503 der signierten Routen sind nicht deklariert. Drei Integrationstests sind deshalb als unvollständig markiert.
- [ ] **T-146 [WICHTIG]** `SiteUrl`: IP-Schreibweisen wie `0x7f.1` oder `192.168.001.010` und nicht normalisierte Pfade werden akzeptiert; ohne die PHP-Erweiterung `intl` werden alle Umlaut-Domains abgelehnt. Regeln festlegen (alt T-87, Verhalten ist in Tests festgehalten).
- [ ] **T-61 [WICHTIG]** Multisite: Mindestverhalten umgesetzt (`docs/MULTISITE.md`), auf einem Netzwerk nie ausgeführt.
- [ ] **T-92 [WICHTIG]** Datenbereinigung: Fristen per *Unit* belegt, die Löschabfragen sind nie gegen eine Datenbank gelaufen.

### Tests und Betrieb

- [ ] **T-147 [WICHTIG]** Den E2E-Lauf (`tests/e2e/bin/run-e2e.sh`, etwa vier Minuten) als CI-Job aufnehmen. Anleitung steht in `tests/e2e/README.md`.
- [ ] **T-148 [WICHTIG]** Zweimal schlug ein einzelner Integrationstest lokal einmalig fehl, als zwei Testläufe denselben Datenbankserver nutzten. Die serverweiten Sperrnamen sind inzwischen pro Site eindeutig; beobachten, ob es in CI wieder auftritt.
- [ ] **T-95 [WICHTIG]** Release-Workflow (`.github/workflows/release.yml`) ist geschrieben und nie gelaufen. Mit einem Test-Tag ausführen und das ZIP auf einer leeren Installation prüfen.
- [ ] **T-86 [WICHTIG]** Schlüsselrotation und Key-ID-Wiederherstellung (WP-CLI) an einer Datenbankkopie mit Backup und Restore abnehmen.
- [ ] **T-89 [WICHTIG]** Lasttest mit realistischen Mengen; Abfragepläne der Listen prüfen.
- [ ] **T-91 [WICHTIG]** Monitoring anschließen: Empfänger für `wplc/health_alert`, Überwachung von Action Scheduler, Webhooks und Paket-Host (`docs/MONITORING.md`).
- [ ] **T-31 [WICHTIG]** Externer Paketspeicher (S3 oder GCS) ist nur dokumentiert. Bei Bedarf anbinden und abnehmen.
- [ ] **T-93 [WICHTIG]** Rechtliche Prüfung: Kündigungsbutton, Hinweispflichten bei automatischer Verlängerung, Widerruf bei digitalen Inhalten, Umsatzsteuer und Rechnungen für Verlängerungen, Datenschutzhinweise.
- [ ] **T-94 [WICHTIG]** Supportabläufe und Verantwortliche festlegen (`docs/SUPPORT-ACTIONS.md` beschreibt, was im Admin möglich ist).
- [ ] **T-96 [WICHTIG]** Launch-Nachweise in `docs/LAUNCH-EVIDENCE.md` ausfüllen.
- [ ] **T-149 [WICHTIG]** Alte Branches `codex/*` löschen. Alle 37 sind vollständig in `main` enthalten.

## 4. Optimierung

- [ ] **T-106 [OPTIMIERUNG]** Kürzeres Schlüsselformat (heute 106 Zeichen).
- [ ] **T-108 [OPTIMIERUNG]** Download-Token steht weiter im URL-Pfad. Die Antwort verbietet inzwischen Caching und Referrer.
- [ ] **T-109 [OPTIMIERUNG]** Add-ons mit anteiliger Berechnung; `Money::prorate()` ist nirgends angebunden.
- [ ] **T-110 [OPTIMIERUNG]** Tarifwechsel einer bestehenden Lizenz.
- [ ] **T-102 [OPTIMIERUNG]** Adminliste: Bulk-Aktionen und Filter nach Bestellung oder Verlängerung.
- [ ] **T-150 [OPTIMIERUNG]** Die Bezeichnungen der Produktfunktionen im Rechtekatalog liegen in der Textdomain dieses Plugins und müssen hier mitübersetzt werden. In die Add-ons verlagern.
- [ ] **T-151 [OPTIMIERUNG]** WP-CLI-Ausgaben und Logmeldungen sind englisch.

## 5. Entscheidungen

- [ ] **T-121** Eigenbau-Verlängerung behalten oder WooCommerce Subscriptions einsetzen. Nach T-12 entscheiden.
- [ ] **T-152** Behält eine bezahlte Bestellung, die ohne Erstattung storniert wird, ihre Lizenz? Heute ja.
- [ ] **T-153** Bleiben Entwicklungs-Aktivierungen auf lokalen Hosts unbegrenzt? Heute ja.
- [ ] **T-120** PayPal: keine automatische Verlängerung, solange es kein passendes Vertragsmodell gibt. Manuelle Verlängerung funktioniert mit jeder Zahlungsart.
- [ ] **T-122** Zahlungsereignisse: Adapter-Ergebnis und Webhook werden beide ausgewertet; der Webhook ist der Abgleich. Zuständigkeit schriftlich bestätigen.

## 6. Erledigt

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

## 7. Audit vom 13.11.2026 (zwei Expertenrunden und Eigen-Audit)

Acht unabhängige Prüfer (Code, Sicherheit, Architektur und Datenbank, Bedienung, Marktreife, Frontend und Barrierefreiheit, Schnittstellen und Datenfluss, Support und Wartbarkeit) haben den Stand auf `main` nur lesend geprüft; dazu kommt eine eigene Nachprüfung. Alle Funde stammen aus dem Lesen des Codes, einzelne sind mit Skripten nachgestellt. In einem Browser oder gegen Stripe lief nichts davon. Die Aufgaben A-01 bis A-06 sind am Code nachgeprüft.

### Kritisch

- [ ] **A-01 [KRITISCH]** Variables Produkt, nur am Hauptprodukt zugeordnet: Kontozwang und Verlängerungs-Opt-in greifen nicht, ein Gast bekommt eine Lizenz ohne Besitzer, ohne Mail und ohne Zugang zum Schlüssel. „Ist Lizenzprodukt“ überall über `Catalog::find_mapping()` mit Rückfall aufs Hauptprodukt bestimmen; bei Bestellung ohne Kunde nicht still ausstellen. (`RecurringConsent.php:205, 284`, `BlockCheckoutConsent.php:218`, `LicenseIssuer.php:138`)
- [ ] **A-02 [KRITISCH]** Mit WooCommerce-Standardeinstellungen kann ein nicht angemeldeter Neukunde keine Lizenz kaufen: Das Konto wird erzwungen, die Kontoerstellung im Checkout aber nicht eingeschaltet. Zusätzlich `woocommerce_checkout_registration_enabled` filtern. (`RecurringConsent.php:46`)
- [ ] **A-03 [KRITISCH]** Manuelle Verlängerung sucht den Preis über das nicht eindeutige Paar Produktschlüssel und Tarifschlüssel; es gewinnt der zuletzt gespeicherte Tarif (Jahreskunde verlängert zum Monatspreis oder umgekehrt). Tarif oder Produkt-ID an der Lizenz speichern. Dieselbe Suche steckt im Rückfall für das Website-Limit. (`RenewalOrder.php:248`, `ActivationService.php:458`)
- [ ] **A-04 [KRITISCH]** Verlängerungsbestellungen übernehmen weder die Umsatzsteuerbefreiung (Reverse Charge) noch die UID der Erstbestellung; EU-Firmenkunden wird Umsatzsteuer berechnet. (`RenewalOrder.php:92-99, 313`)
- [ ] **A-05 [KRITISCH]** „Änderungen speichern“ im Varianten-Tab verwirft die Lizenzfelder der Variante stillschweigend, weil das erwartete Nonce-Feld dort nicht gesendet wird. Den E2E-Test korrigieren, der das Feld von Hand ergänzt. (`ProductEditor.php:307-314`, `tests/e2e/flows/02-catalog.php:247`)
- [ ] **A-06 [KRITISCH]** Die Aktivierung schreibt PHP-, WordPress- und Produktversion ungekürzt in 30-Zeichen-Spalten; bei längeren Versionsangaben ist die Site nicht aktivierbar. Vorher wird die alte Aktivierung derselben Site bereits beendet, ohne Transaktion. (`ActivationService.php:216-221, 274-276`)

### Wichtig — Geld und Verlängerung

- [ ] **A-10 [WICHTIG]** Die Einwilligung nennt den „angezeigten Preis“, abgebucht wird der reguläre Katalogpreis plus neu berechneter Steuer; die Ankündigungsmail zeigt den Betrag ohne Steuer. Verlängerungspreis brutto im Checkout und in den Mails ausweisen. (`RecurringConsent.php:70`, `BlockCheckoutConsent.php:90`, `Notifications.php:429-453`)
- [ ] **A-11 [WICHTIG]** Der Tarifpreis wird nur beim Speichern im klassischen Produkteditor übernommen, nicht bei Quick-Edit, Massenbearbeitung, REST oder Import. Der Preis bestehender Lizenzen lässt sich gar nicht ändern. (`ProductEditor.php:27-29`, `Catalog.php:55-60`)
- [ ] **A-12 [WICHTIG]** Eine fehlgeschlagene automatische Verlängerungsbestellung bleibt nach Ablauf der Lizenz bezahlbar; die Zahlung wird angenommen und nicht verbucht, ohne Notiz. (`RenewalProcessor.php:569-572`)
- [ ] **A-13 [WICHTIG]** `finish_paid()` setzt die Verlängerung auf „bezahlt“ und protokolliert Erfolg, auch wenn die Lizenz nicht verlängert wurde (etwa weil sie inzwischen gesperrt ist). (`RenewalProcessor.php:215-225`)
- [ ] **A-14 [WICHTIG]** Eine wartende Überweisung für eine vorab gestartete Verlängerung zählt am Stichtag als Zahlungsausfall, mit Mail und Ablauf nach 14 Tagen. (`RenewalProcessor.php:156-160`)
- [ ] **A-15 [WICHTIG]** Shop-seitige Fehler (Stripe-Plugin deaktiviert, Produkt fehlt, Bestellung nicht verknüpfbar) zählen als Zahlungsfehlversuch des Kunden; Stripe-Ratenlimits vermutlich ebenfalls. Eigene Fehlerklasse „Konfiguration/vorübergehend“ einführen. (`RenewalProcessor.php:139, 145`, `StripeRecurringAdapter.php:159`)
- [ ] **A-16 [WICHTIG]** Der Stripe-Fehlergrund wird verworfen; ein Konfigurationsfehler (Wechsel von Test- auf Live-Konto) sieht aus wie „Karte abgelehnt“ und lässt alle Lizenzen auslaufen. Die Spalte `gateway_attempt_id` wird nie beschrieben. (`StripeRecurringAdapter.php:150-162`)
- [ ] **A-17 [WICHTIG]** Nach einer Reaktivierung oder zurückgenommenen Kündigung bleibt eine stornierte Verlängerung storniert; das Konto zeigt „verlängert sich automatisch“, es folgt aber kein Versuch. (`RenewalProcessor.php:106-114`, `LicenseLifecycle.php:493, 865`)
- [ ] **A-18 [WICHTIG]** Der Nachhol-Lauf stellt höchstens 100 überfällige Verlängerungen pro Tag ein; was die Kulanz überschreitet, läuft ohne Abbuchungsversuch ab. (`RenewalProcessor.php:596-611, 119-122`)
- [ ] **A-19 [WICHTIG]** Für Webhook-Ereignisse und bezahlte Wiederherstellungs-Bestellungen gibt es genau einen Auftrag und keinen Nachhol-Lauf; geht er verloren, bleibt das Ereignis für immer offen. (`StripeWebhook.php:110-123`, `RenewalProcessor.php:332-340, 371-382`)
- [ ] **A-20 [WICHTIG]** Mit Stripe-Gateway 9.6 lassen sich Verlängerungsbestellungen nicht aus WooCommerce erstatten, weil die Stripe-Metadaten an der Bestellung fehlen. (`StripeRecurringAdapter.php:178`, `RenewalProcessor.php:180`)
- [ ] **A-21 [WICHTIG]** Keine Kündigungsbestätigung per Mail; der Support kann die automatische Verlängerung nicht für den Kunden abschalten oder wieder einschalten. (`LicenseLifecycle.php:433-469`, `Notifications.php:195-206`)
- [ ] **A-22 [WICHTIG]** Wer neu kauft statt zu verlängern, bekommt einen neuen Schlüssel; es gibt weder Hinweis im Checkout noch eine Übertragung der Aktivierungen. (`LicenseIssuer.php:41-85`)
- [ ] **A-23 [WICHTIG]** Rechte sind eine Momentaufnahme vom Kauf: Bekommt ein Tarif eine neue Funktion oder ein höheres Website-Limit, erreicht das bestehende Lizenzen nie, auch nicht bei der Verlängerung. (`LicenseIssuer.php:245`, Eigen-Audit)
- [ ] **A-24 [WICHTIG]** Lizenzen entstehen bei Bestellstatus „in Bearbeitung“; bei Zahlungsarten wie Nachnahme ist dann noch kein Geld eingegangen. (`LicenseIssuer.php:47`, Eigen-Audit)
- [ ] **A-25 [WICHTIG]** Wird eine Bestellung gelöscht oder in den Papierkorb gelegt oder ein Kundenkonto gelöscht, bleibt die Lizenz aktiv; die Verlängerung scheitert dann ohne erreichbaren Empfänger. (kein Hook vorhanden, Eigen-Audit)

### Wichtig — Sicherheit und Schlüssel

- [ ] **A-30 [WICHTIG]** Die Ed25519-Signatur deckt nur Daten und Installations-ID, nicht die Anfrage-Nonce; eine aufgezeichnete Antwort „aktiv“ lässt sich später wieder einspielen. Über den `payload`-Text signieren und im Client `cache_expires_at` erzwingen, bevor Add-ons das Format festschreiben. (`ResponseSigner.php:50`, `Response.php:76`)
- [ ] **A-31 [WICHTIG]** Der Signaturschlüssel entsteht stillschweigend als Datenbank-Option, fällt nach einem Wechsel der Schlüssel oder Salts ohne Meldung weg und wird bei leerer Option neu erzeugt. Im Rotationsbefehl mit umschlüsseln, nie still erzeugen, als Pflichtkonstante dokumentieren, im Client mehrere Schlüssel zulassen. (`ResponseSigner.php:131-158, 175`)
- [ ] **A-32 [WICHTIG]** Bei Releases mit Speicherart „URL“ erfährt jeder lizenzierte Client die dauerhafte Paketadresse und kann sie nach Ablauf weiter nutzen oder weitergeben. (`UpdateService.php:149-158`, `CustomerDownload.php:365`)
- [ ] **A-33 [WICHTIG]** Das Website-Limit ist mit `environment=development` und einer selbst gewählten `.local`-Adresse beliebig umgehbar; der Host wird nie überprüft. Entwicklungs-Aktivierungen deckeln. Ersetzt die Entscheidung T-153. (`ActivationService.php:70, 223-256, 415-423`)
- [ ] **A-34 [WICHTIG]** Eine geklonte Site nutzt dieselbe Aktivierung unbegrenzt mit, weil signierte Prüfungen keine Site-Adresse senden. (`EntitlementService.php:28-105`, `LicenseClient.php:129-131`)
- [ ] **A-35 [WICHTIG]** Releases veröffentlichen darf jeder Shop-Manager; ein übernommenes Konto könnte Code an alle Kundenseiten ausliefern. Eigene Berechtigung einführen. (`ReleaseAdmin.php:50, 423, 436`)
- [ ] **A-36 [WICHTIG]** Die Nonce- und Limit-Tabellen wachsen schneller, als sie bereinigt werden (1.000 Zeilen pro Stunde); ab etwa 6.000 Installationen läuft die Datenbank voll. Die Nonce wird außerdem vor der Limitprüfung gespeichert. (`Authenticator.php:85-108, 189-197`)

### Wichtig — Updates

- [ ] **A-40 [WICHTIG]** Jede Update-Prüfung legt ein Download-Token an; die Bereinigung löscht ohne Begrenzung und ohne nutzbaren Index. Token erst beim Download ausstellen. (`UpdateService.php:64-79, 167-179`)
- [ ] **A-41 [WICHTIG]** Das Integrationsbeispiel führt zu Updates, die beim Klick fehlschlagen (Link gilt 600 Sekunden, WordPress merkt sich das Update 12 Stunden). Einen gemeinsamen Updater mit Prüfsummenkontrolle mitliefern. (`docs/CLIENT-INTEGRATION.md:126-131`, `UpdateService.php:22`)
- [ ] **A-42 [WICHTIG]** Das Server-Plugin selbst hat keinen Update-Weg auf dem Shop; ein Sicherheitsfix erreicht ihn nur per ZIP-Upload. (`wp-license.php`, `release.yml`)

### Wichtig — Bedienung

- [ ] **A-50 [WICHTIG]** Die drei Fehlermeldungen des Produkteditors werden nie angezeigt; bei fehlendem Tarifschlüssel wird die Zuordnung stumm gelöscht. (`ProductEditor.php:388, 403-410, 453-459`)
- [ ] **A-51 [WICHTIG]** Eine gesperrte Lizenz mit abgelaufener Laufzeit lässt sich im Admin nie wieder aktivieren. (`LicenseDetail.php:191-193, 212-214`, `LicenseStateMachine.php:53-57`)
- [ ] **A-52 [WICHTIG]** Warum die automatische Verlängerung abgeschaltet ist (Stripe zu alt, gespeicherte Karten aus), wird nirgends angezeigt. (`Requirements.php:298`, `Settings.php:350-427`)
- [ ] **A-53 [WICHTIG]** Die Erinnerungsmail mit „Jetzt verlängern“ kommt bis zu 24 Stunden, bevor der Button im Konto existiert; bei Vorlauf über 30 Tage wochenlang. (`ReminderSchedule.php:152-160`, `ManualRenewal.php:73-84`, `Settings.php:250-264`)
- [ ] **A-54 [WICHTIG]** Der Produktname für Kunden wird bei jedem Speichern mit dem Namen des zuletzt gespeicherten Produkts oder der Variante überschrieben. (`Catalog.php:80-96`)
- [ ] **A-55 [WICHTIG]** Bei überfälliger, abgelaufener, gesperrter oder widerrufener Lizenz erfährt der Kunde im Konto nicht, was los ist und was er tun kann; ohne veröffentlichtes Release fehlt jeder Download-Hinweis. (`CustomerPortal.php:320-355`, `CustomerDownload.php:60-63`)
- [ ] **A-56 [WICHTIG]** Wer das Opt-in ankreuzt und mit einer nicht verlängerbaren Zahlungsart zahlt, erfährt nicht, dass es verworfen wurde. (`RecurringConsent.php:59-74, 140-147`, `BlockCheckoutConsent.php:178-181`)
- [ ] **A-57 [WICHTIG]** Ein ZIP über dem Upload-Limit des Servers endet auf einer leeren Seite; bei jedem Fehler verliert das Release-Formular alle Eingaben. (`ReleaseAdmin.php:327, 474-485`)
- [ ] **A-58 [WICHTIG]** Der CSV-Import nennt bei keinem Fehler einen Grund und scheitert an Dateien aus deutschem Excel (Semikolon, BOM); er lehnt Schlüssel ab, die der Editor erlaubt. (`CatalogCsv.php:47, 104-118, 206`)
- [ ] **A-59 [WICHTIG]** „Veröffentlichen“, „Zurückziehen“ und „Entwurf löschen“ auf der Release-Seite haben keine Rückfrage; das Bestätigungs-Skript ist dort nicht geladen. (`ReleaseAdmin.php:279-287, 302-311`)
- [ ] **A-60 [WICHTIG]** Ein Doppelklick auf „Lizenz hinzufügen“ stellt zwei Lizenzen aus. (`LicenseAdmin.php:343-381`)
- [ ] **A-61 [WICHTIG]** Dem Support fehlen Aktionen: Besitzer ändern, Tarif ändern, Schlüssel-Mail erneut senden, Lizenzen exportieren, Laufzeiten gesammelt verlängern. Berichte (anstehende Verlängerungen, Zahlungsausfälle, Kündigungen) gibt es nicht. (`LicenseAdmin.php:40-43`)

### Wichtig — Betrieb und Fehlersuche

- [ ] **A-70 [WICHTIG]** Eine bezahlte Bestellung ohne Lizenz hinterlässt keine Spur: kein Log, keine Bestellnotiz, kein Wiederholungsversuch. Eine falsch gesetzte Schlüssel-ID führt so still zu Verkäufen ohne Lizenz. (`LicenseIssuer.php:150-152, 200-204, 240`)
- [ ] **A-71 [WICHTIG]** Abgelehnte API-Anfragen sind serverseitig nicht nachvollziehbar (rund 25 Fehlerzweige ohne Log); das Plugin schreibt insgesamt nur an zwei Stellen ein Log. Zentralen Logger mit Zählern pro Fehlercode einführen. (`Authenticator.php:36-108`, `ActivationService.php:51-287`, `UpdateService.php:115-157`)
- [ ] **A-72 [WICHTIG]** „Manuelle Prüfung“ bei Zahlungsereignissen ist eine Sackgasse: kein Bildschirm, kein Wiederholen, Audit-Einträge unsichtbar, Signaturfehler ungezählt. (`RenewalProcessor.php:392-508`, `LicenseDetail.php:353-366`, `StripeWebhook.php:46`)
- [ ] **A-73 [WICHTIG]** Verlängerungen wechseln den Status ohne Audit und ohne gespeicherten Grund; `renewal_method_unavailable` fasst vier Ursachen zusammen. (`RenewalProcessor.php:102-139`, `RenewalScheduler.php:35-46`)
- [ ] **A-74 [WICHTIG]** Kein hängender Schritt lässt sich von Hand wiederholen: Es fehlen Befehle und Admin-Aktionen für Lizenz anzeigen, Verlängerung wiederholen, Webhook erneut verarbeiten, Lizenz für Bestellung nachvergeben, Mail erneut senden. (`src/CLI/*`)
- [ ] **A-75 [WICHTIG]** Der Diagnoseseite fehlen Plugin-Version, Soll-Schema, letzter Cron-Lauf, letzter Webhook, Serverzeit und Stripe-Status; eine Zeile zeigt immer „in Ordnung“. Migrationsfehler werden verschluckt. (`Diagnostics.php:83-102`, `Schema.php:148`, `Plugin.php:106`)
- [ ] **A-76 [WICHTIG]** Das Audit-Log ist nur als „letzte 100 je Lizenz“ lesbar; keine Gesamtansicht, kein Filter, kein Export. Die Suche findet weder Site-Adresse noch Installations-ID; die Quelle (Cron, API, Webhook) fehlt. (`LicenseDetail.php:29, 366`, `LicenseSearch.php:38-63`)
- [ ] **A-77 [WICHTIG]** Die Schwärzung im Audit löscht die Site-Adresse; ein Adresswechsel einer Aktivierung wird nicht protokolliert. Weitere Änderungen ohne Audit: Eraser, Tarifpreis und Rechte, Abschalten der Verlängerung bei Ablauf. (`AuditLog.php:84`, `ActivationService.php:134-140, 187-193`)
- [ ] **A-78 [WICHTIG]** Der „einmal gesendet“-Vermerk wird vor dem Versand geschrieben; lehnt der Mailserver ab, wird die Mail nie wieder versucht (auch Schlüssel-Mail und Zahlungsaufforderung). (`Notifications.php:370-386`)
- [ ] **A-79 [WICHTIG]** Nach Deinstallation, Löschen der Tabellen und Neuinstallation werden Erinnerungen und Verlängerungsankündigungen nie mehr verschickt. Erweitert T-144. (`ReminderLog.php:76-79`, `uninstall.php`)
- [ ] **A-80 [WICHTIG]** Der Tageslauf ist ein einziges Cron-Ereignis mit acht Aufgaben; der Ablauf-Lauf verschickt bis zu 450 Mails ohne Zeitbudget und bricht bei Zeitüberschreitung alle folgenden Aufgaben ab. Er verarbeitet höchstens 250 bzw. 200 Lizenzen und setzt nicht fort. (`LicenseLifecycle.php:653-682`)

### Wichtig — Dokumentation und Release

- [ ] **A-90 [WICHTIG]** Mehrere Dokumente weisen Lizenzclient und Updater weiter den Kern-Plugins zu; für Affiliate Captain stehen Grundfunktionen als Rechte im Katalog. (`docs/CLIENT-REPOS.md:13-21`, `docs/UPDATES.md:75-81`, `docs/ENTITLEMENTS.md:25`, `EntitlementCatalog.php:36-40`)
- [ ] **A-91 [WICHTIG]** Versions-, Anforderungs- und Statusangaben widersprechen sich (Block-Checkout, WooCommerce-Minimum, Version 0.1.0, veraltete Aufgabennummern in `RELEASE-READINESS.md`). WooCommerce 8.9 bis 9.3 ist nie gelaufen. Version anheben und Dokumente abgleichen. (`wp-license.php`, `readme.txt`, `README.md`, `CHANGELOG.md`, `docs/COMPATIBILITY.md`)

### Optimierung

- [ ] **A-100 [OPTIMIERUNG]** Der Eraser lässt aktive Aktivierungen beendeter Lizenzen stehen und meldet die Löschung als erledigt. (`PersonalData.php:324`)
- [ ] **A-101 [OPTIMIERUNG]** Der Eraser ignoriert die Seitenzahl, führt einen eigenen Cursor und meldet bei einem dauerhaften Fehler nie „fertig“. (`PersonalData.php:298-354`)
- [ ] **A-102 [OPTIMIERUNG]** Die signierte Antwort enthält keinen Produktschlüssel; Zugangsdaten von Produkt A könnten Add-on B freischalten. (`EntitlementService.php:91-102`, `ActivationService.php:390-402`)
- [ ] **A-103 [OPTIMIERUNG]** Der CSV-Export schreibt die SKU ungefiltert; Formeln würden in Excel ausgeführt. (`CatalogCsv.php:87`)
- [ ] **A-104 [OPTIMIERUNG]** Das Block-Checkout-Feld wird auf jedem Request registriert und liest dafür einen nicht automatisch geladenen Zwischenspeicher. (`BlockCheckoutConsent.php:53, 67-85`)
- [ ] **A-105 [OPTIMIERUNG]** Fehlende Indizes: Lizenzen nach Quellbestellung, Standard-Sortierung der Adminliste, deaktivierte Aktivierungen, Zahlungsereignisse nach Ergebnis. (`Schema.php:265-270`, `LicenseLifecycle.php:171, 322`, `LicenseListTable.php:174`, `Retention.php:207`, `Diagnostics.php:34-37`)
- [ ] **A-106 [OPTIMIERUNG]** Eine scheiternde Migration wird ohne Pause auf jedem Request wiederholt; danach arbeitet das Plugin gegen ein altes Schema. (`Schema.php:64-71, 82-101`)
- [ ] **A-107 [OPTIMIERUNG]** `.distignore` ist unvollständig; ein Build mit `wp dist-archive` würde Entwicklerwerkzeuge ausliefern. (`.distignore:3-7`)
- [ ] **A-108 [OPTIMIERUNG]** Client: REST-Präfix fest verdrahtet, kein Ersatz für `DELETE`, keine Sperre nach Transportfehlern bei 20 Sekunden Timeout. (`LicenseClient.php:155-162, 224-234`)
- [ ] **A-109 [OPTIMIERUNG]** Client korrigiert keine Uhrenabweichung; bei mehr als fünf Minuten scheitert jede Anfrage dauerhaft. Festlegen, dass nur ein signierter Status sperren darf. (`LicenseClient.php:215`, `Authenticator.php:40`)
- [ ] **A-110 [OPTIMIERUNG]** Client löscht lokale Zugangsdaten beim Deaktivieren nur nach signierter Erfolgsantwort, nicht wenn die Aktivierung serverseitig schon freigegeben ist. (`LicenseClient.php:163-169`)
- [ ] **A-111 [OPTIMIERUNG]** Fehlt die lokale Paketdatei, wird das Update weiter angekündigt und der Download verbraucht das Token; abgebrochene Downloads lassen sich nicht fortsetzen. (`ReleaseRegistry.php:59-69`, `PackageStream.php:224-237`)
- [ ] **A-112 [OPTIMIERUNG]** Weitere Stellen „Wert passt nicht in die Spalte“: Produktname (190 Zeichen) und Mindestversionen der Releases (20 Zeichen). (`Catalog.php:79-91`, `ReleaseRegistry.php:37`)
- [ ] **A-113 [OPTIMIERUNG]** Webhook-Duplikate werden über den rohen Text erkannt; eine erneute Zustellung mit leicht anderem Inhalt endet dauerhaft in 409. (`StripeWebhook.php:101, 118-120`)
- [ ] **A-114 [OPTIMIERUNG]** Ein „unbekanntes Ergebnis“ kann nach mehr als 24 Stunden doppelt belasten, weil Stripe den Idempotency-Key vergisst. Im Stripe-Test mitprüfen. (`RenewalProcessor.php:262-266, 596-611`)
- [ ] **A-115 [OPTIMIERUNG]** Der Abrechnungstag wandert dauerhaft vom 31. auf den 28.; die Periodenrechnung steht doppelt im Code. (`RenewalScheduler.php:177-192`, `LicenseIssuer.php:390-411`)
- [ ] **A-116 [OPTIMIERUNG]** Laufzeitende sekundengenau zur Kauf-Uhrzeit statt zum Tagesende; nach Ablauf kein Download der bezahlten Version; kein Beta-Kanal; Mindestversionen werden serverseitig nicht verglichen. (`LicenseLifecycle.php:125-130`, `CustomerDownload.php:57, 108`, `UpdateService.php:48, 83-94`)
- [ ] **A-117 [OPTIMIERUNG]** Was nach der Deinstallation bleibt (Optionen, Signaturschlüssel, Paketordner, Bestell-Meta), ist nicht dokumentiert; bei Multisite bleiben Aufgaben der Unterseiten. (`uninstall.php:16-22`)
- [ ] **A-118 [OPTIMIERUNG]** Das Opt-in im klassischen Checkout liegt in einem Bereich, den WooCommerce neu lädt; der Haken kann verschwinden. Bei T-130 mitprüfen. (`RecurringConsent.php:44, 64-73`)
- [ ] **A-119 [OPTIMIERUNG]** Release-Seite: Meldung bei nicht erlaubtem Host verweist auf einen PHP-Filter statt auf die Einstellung und kommt erst beim Veröffentlichen; Datum als roher UTC-Wert; keine Hilfe zur Prüfsumme. (`ReleaseRegistry.php:461, 615`, `ReleaseAdmin.php:277`)
- [ ] **A-120 [OPTIMIERUNG]** Kundenformulare für Deaktivieren, Kündigen und Zurücknehmen haben keinen Handler für abgelaufene Sitzungen; ein abgelaufener Nonce endet auf einer wp-admin-Seite ohne Rückweg. (`CustomerPortal.php:51-53`)
- [ ] **A-121 [OPTIMIERUNG]** Nach der Aktivierung gibt es keinen Einstieg; Statuszeilen und Feldhilfen sagen nicht, was zu tun ist (Reihenfolge, Stripe-Webhook, Bedeutung von Produkt- und Tarifschlüssel). (`Plugin.php:51-64`, `Settings.php:350-427`, `KeyConfigurationNotice.php:56-71`)
- [ ] **A-122 [OPTIMIERUNG]** Der Audit-Verlauf zeigt interne Codes und unbeschriftete UTC-Zeiten. Erweitert T-138. (`LicenseDetail.php:113, 408, 436-459`)
- [ ] **A-123 [OPTIMIERUNG]** „Lizenz manuell hinzufügen“ verliert bei Fehlern alle Eingaben und verschickt die Kaufmail; „Sperren“ und „Website freigeben“ ohne Rückfrage; Suche ohne Bestellnummer; kein Link von der Bestellung zur Lizenz. (`LicenseAdmin.php:352-378`, `LicenseListTable.php:218-222`, `LicenseIssuer.php:102`)
- [ ] **A-124 [OPTIMIERUNG]** Der Kopieren-Button meldet Erfolg nur über wechselnden Text und einen Fehlschlag gar nicht; für Screenreader nicht wahrnehmbar. (`assets/js/wplc-ui.js:26-38`)
- [ ] **A-125 [OPTIMIERUNG]** Die Rechte-Checkliste im Produkteditor ist in Varianten, unter 500 Pixel Breite und in Rechts-nach-links-Sprachen falsch positioniert. (`assets/css/wplc-admin.css:2-17`)
- [ ] **A-126 [OPTIMIERUNG]** Die Admin-Detailtabellen und die Einstellungsseite laufen auf schmalen Bildschirmen über. (`LicenseDetail.php:245-281`, `Settings.php:316-320`, `wplc-admin.css:55`)
- [ ] **A-127 [OPTIMIERUNG]** Doppelte `id="_wpnonce"`, sobald mehrere Formulare auf einer Seite stehen. (`CustomerDownload.php:66`, `ReleaseAdmin.php:306`)
- [ ] **A-128 [OPTIMIERUNG]** Das Datei-Feld des CSV-Imports hat kein Label; die Seite hat keine Zwischenüberschriften. (`CatalogCsv.php:57`)
- [ ] **A-129 [OPTIMIERUNG]** HTML-Mails: feste Linksausrichtung, Button schrumpft in Outlook, der volle Schlüssel sprengt die Tabelle. (`templates/emails/wplc-license-details.php:20-29`)
- [ ] **A-130 [OPTIMIERUNG]** Text-Mails verlieren Apostrophe („Captain's Pass“ wird „Captains Pass“). (`templates/emails/plain/*.php`)
- [ ] **A-131 [OPTIMIERUNG]** Zwei Admin-Werte sind nicht lokalisiert: Veröffentlichungsdatum und Verlängerungsbetrag. (`ReleaseAdmin.php:277`, `LicenseDetail.php:119-120`)
- [ ] **A-132 [OPTIMIERUNG]** Bei Mails ist „nie ausgelöst“ nicht von „abgeschaltet“ zu unterscheiden; Fehlermeldungen des Mailservers mit Empfängeradresse landen im Log. (`Notifications.php:157, 183, 324, 361-372`)
- [ ] **A-133 [OPTIMIERUNG]** Logging ohne Struktur: zwei private Kopien, kein Debug-Schalter, keine gemeinsame Kennung über Verlängerung, Bestellung und Zahlungsereignis.
- [ ] **A-134 [OPTIMIERUNG]** Erweiterungspunkte sind uneinheitlich benannt und überwiegend undokumentiert; die Doku nennt einen Filter, den es nicht gibt (`wplc_client_update_hosts`); `wplc/license_issued` übergibt den Klartextschlüssel an jeden Listener. (`LicenseIssuer.php:178`, `docs/UPDATES.md:83`)
- [ ] **A-135 [OPTIMIERUNG]** Fest verdrahtete und doppelt geführte Werte: Kulanztage, Wiederholungstage, Cache-Dauer, Zeitfenster, Limits, die Gateway-Kennung `stripe`. (`LicenseLifecycle.php:100`, `RenewalProcessor.php:259`, `EntitlementService.php:101`, `ActivationService.php:401`)
- [ ] **A-136 [OPTIMIERUNG]** Struktur und Tests: fast alles statisch mit direktem Datenbankzugriff, toter Code und tote Spalten aus den zusammengeführten Branches, und in CI läuft für den gesamten Geldpfad kein Test (nur im lokalen E2E-Lauf). Verschärft T-147.
- [ ] **A-137 [OPTIMIERUNG]** Mails gehen an die Adresse des Kundenkontos, nicht an die Rechnungsadresse der Bestellung. (`LicenseEmail.php:74-84`, Eigen-Audit)
