# WP License Captain — To-do-Liste

Stand: 13.11.2026, nach der Zusammenführung aller Arbeitspakete auf `main`. Dies ist die einzige Aufgabenliste des Lizenzservers. Die Aufgaben der drei Produkt-Plugins (Kern-Plugin und Premium-Add-on) stehen in deren eigenen `TODO.md`.

**Regeln**

- Ein Haken bedeutet: umgesetzt **und** belegt. Der Beleg steht dabei.
- Belegarten: *Unit* (PHPUnit ohne WordPress), *Integration* (WordPress + WooCommerce + MySQL, 4 Kombinationen in CI), *E2E* (Durchlauf in einer echten WordPress/WooCommerce-Installation, `tests/e2e/`, 542 Prüfungen), *Quelltext* (gegen den Quellcode von WooCommerce oder des Stripe-Plugins gelesen, nicht ausgeführt).
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
