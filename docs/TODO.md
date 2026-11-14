# WP License Captain — To-do-Liste

Stand: 13.11.2026, nach der Zusammenführung aller Arbeitspakete auf `main`. Dies ist die einzige Aufgabenliste des Lizenzservers. Die Aufgaben der drei Produkt-Plugins (Kern-Plugin und Premium-Add-on) stehen in deren eigenen `TODO.md`. Am 14.11.2026 um die Ergebnisse des Security-Audits ergänzt (T-154 bis T-168, Zusätze bei T-13 und T-142).

**Regeln**

- Ein Haken bedeutet: umgesetzt **und** belegt. Der Beleg steht dabei.
- Belegarten: *Unit* (PHPUnit ohne WordPress), *Integration* (WordPress + WooCommerce + MySQL, 4 Kombinationen in CI), *E2E* (Durchlauf in einer echten WordPress/WooCommerce-Installation, `tests/e2e/`, 542 Prüfungen), *Quelltext* (gegen den Quellcode von WooCommerce oder des Stripe-Plugins gelesen, nicht ausgeführt).
- Was nur per *Quelltext* belegt ist, gilt als offen, bis es im Browser oder gegen Stripe gelaufen ist.
- Kritikalität: **[KRITISCH]** blockiert den ersten echten Verkauf, **[WICHTIG]** blockiert den öffentlichen Launch, **[OPTIMIERUNG]** kann danach folgen.
- Nummern bleiben stabil; erledigte Nummern stehen in Abschnitt 6.

## 1. Was noch nie gelaufen ist

Diese drei Lücken lassen sich nicht durch weiteren Code schließen.

- [ ] **T-12 [KRITISCH] Stripe-Testmodus.** Automatische Verlängerung, 3DS, Kartenspeicherung beim Erstkauf, Webhooks und Streitfälle sind nur per *Quelltext* und mit einem Test-Adapter geprüft. Durchspielen: Erfolg, Ablehnung, 3DS, Timeout, widerrufene Karte, Streitfall. Dabei mitprüfen:
  - T-13: Idempotency-Key pro Versuch (`{Schlüssel}:{Versuch}`) verhindert Doppelbelastung. *Security-Audit 14.11.2026:* `StripeRecurringAdapter::charge()` setzt den Key nicht selbst, sondern nur über den Filter `wc_stripe_idempotency_key`. Im Testmodus die ausgehende Anfrage mitschneiden und belegen, dass das Stripe-Plugin den Filter auch für `payment_intents` anwendet, das Request-Array als zweites Argument übergibt und der Header `Idempotency-Key` wirklich gesendet wird. Sonst kann die Wiederholung nach `renewal_result_unknown` die Karte ein zweites Mal belasten. Besser: Key im Adapter selbst setzen und ein unklares Ergebnis zuerst über den Abruf des PaymentIntent klären.
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
- [ ] **T-154 [KRITISCH] Bestellstatus gilt als Zahlung.** *Security-Audit 14.11.2026, nur per Quelltext belegt.* `LicenseIssuer::maybe_issue_order` und `RenewalProcessor::reconcile_paid_renewal_order` prüfen als einzige Zahlungsbedingung `$order->is_paid()` auf den Hooks „processing“ und „completed“; fehlt das Zahlungsdatum, wird `time()` eingesetzt. Setzt eine Zahlungsart eine unbezahlte Bestellung auf „In Bearbeitung“ (zum Beispiel Nachnahme), entstünden ohne Zahlungseingang aktive Lizenzen samt Schlüssel-Mail, und eine manuelle Verlängerung würde die Laufzeit verlängern. Prüfen: Testbestellung und manuelle Verlängerung mit Nachnahme, Überweisung und Scheck. Regel festlegen: nur Zahlungsarten zulassen, die Geld einziehen, oder Zahlungsdatum beziehungsweise Transaktions-ID verlangen. Hängt mit T-152 zusammen.

## 3. Wichtig: vor dem öffentlichen Launch

### Fehler und Lücken im Code

- [ ] **T-136 [WICHTIG]** Bei unklarem Zahlungsergebnis (`renewal_result_unknown`) wird die Lizenz auf „Zahlung überfällig“ gesetzt und die Mail „Zahlung fehlgeschlagen“ verschickt, obwohl die Abbuchung gelungen sein kann.
- [ ] **T-50 [WICHTIG]** Teilerstattung einer Verlängerungsbestellung ändert nichts an der Lizenz. Die Vollerstattung ist umgesetzt, aber nicht in einem Test belegt.
- [ ] **T-137 [WICHTIG]** Eine bezahlte manuelle Verlängerung, die außerhalb des Zeitfensters eingeht (späte Überweisung), wird nur per Bestellnotiz vermerkt. Es fehlen Adminliste und Erstattungsweg.
- [ ] **T-138 [WICHTIG]** Etwa 30 Begründungstexte im Audit-Verlauf sind fest auf Englisch und erscheinen so im Admin (`RenewalProcessor`, `ManualRenewal`, `LicenseLifecycle`, `ActivationManager`, `CustomerPortal`). Ebenso die Stripe-Zahlungsbeschreibung auf dem Kundenbeleg (`StripeRecurringAdapter.php`).
- [ ] **T-139 [WICHTIG]** Weitere Übersetzungsfehler: „1 live sites“ ohne Mehrzahlform (`LicenseDetail.php`), „Release“ als Verb und Substantiv ohne Kontext, interner Tarifschlüssel in der Überschrift des Kundenkontos, Aktivierungsfehler erscheinen vor dem Laden der Übersetzung auf Englisch.
- [ ] **T-140 [WICHTIG]** Die Einstellung „Absenderhinweis“ wird gespeichert, aber in keiner Mail ausgegeben.
- [ ] **T-141 [WICHTIG]** Ein in die Adminsuche eingefügter vollständiger Lizenzschlüssel bleibt in der URL, in Sortierlinks, im Verlauf und in Zugriffslogs. Suche per POST umstellen.
- [ ] **T-142 [WICHTIG]** Das Aktivierungs-Limit pro Adresse greift vor der Schlüsselprüfung; ein gültiger Schlüssel wird mitgesperrt. Liefert der Speicher-Hook eine nicht erlaubte URL, ist das Download-Token trotzdem verbraucht. *Security-Audit 14.11.2026:* Dasselbe gilt für das Limit pro Schlüsselpräfix (20 pro Minute): Es zählt vor der Schlüsselprüfung, und das Präfix ist nicht geheim. Wer es kennt, kann neue Aktivierungen dieses Schlüssels blockieren. Abhilfe: zuerst den vollen Schlüssel prüfen und nur Fehlversuche auf das Präfix zählen.
- [ ] **T-143 [WICHTIG]** Die Kennzahl `overdue_renewals` zählt auch den Normalzustand zwischen letztem Wiederholungsversuch und Kulanzende und löst täglich Alarm aus. Einstellungsänderungen und CSV-Importe schreiben keinen Audit-Eintrag.
- [ ] **T-144 [WICHTIG]** Die Tabelle `wplc_reminder_log` legt sich selbst an und ist dem Schema nicht bekannt. In `Schema` übernehmen.
- [ ] **T-145 [WICHTIG]** API-Spezifikation: die Antworten 409, 429 und 503 der signierten Routen sind nicht deklariert. Drei Integrationstests sind deshalb als unvollständig markiert.
- [ ] **T-146 [WICHTIG]** `SiteUrl`: IP-Schreibweisen wie `0x7f.1` oder `192.168.001.010` und nicht normalisierte Pfade werden akzeptiert; ohne die PHP-Erweiterung `intl` werden alle Umlaut-Domains abgelehnt. Regeln festlegen (alt T-87, Verhalten ist in Tests festgehalten).
- [ ] **T-61 [WICHTIG]** Multisite: Mindestverhalten umgesetzt (`docs/MULTISITE.md`), auf einem Netzwerk nie ausgeführt.
- [ ] **T-92 [WICHTIG]** Datenbereinigung: Fristen per *Unit* belegt, die Löschabfragen sind nie gegen eine Datenbank gelaufen.

### Aus dem Security-Audit vom 14.11.2026

Quelle: Lauf mit dem Cloudflare-Skill `security-audit`, Profil `standard`, Commit `26afe73`. Es wurde kein Code ausgeführt; alle Punkte sind nur per *Quelltext* belegt. Bestätigte Schwachstellen gab es keine. T-155 bis T-158 sind offene Prüfpunkte mit Prüfplan, T-159 bis T-166 Härtungen. Der kritische Punkt steht als T-154 in Abschnitt 2, kleinere Härtungen als T-167 und T-168 in Abschnitt 4.

- [ ] **T-155 [WICHTIG] Produktschlüssel im Produkteditor.** `ProductEditor::can_save` prüft nur `edit_post` und die WooCommerce-Nonce; `Catalog::save_mapping` speichert jeden Produktschlüssel ohne Prüfung auf `manage_woocommerce`. Wer nur eigene Produkte bearbeiten darf, könnte sein Produkt an den Schlüssel eines fremden Premium-Produkts binden; dessen Lizenzen bekämen dann die Releases dieses Schlüssels. Klären, ob es im Shop eine Rolle mit Produktbearbeitung ohne `manage_woocommerce` gibt. Abhilfe: Zuordnung an `manage_woocommerce` binden. Außerdem im Editor und im CSV-Import dasselbe Muster wie `ReleaseRegistry::PRODUCT_KEY_PATTERN` verlangen.
- [ ] **T-156 [WICHTIG] Aufräumjob langsamer als der Zufluss.** `Authenticator::cleanup_security_rows` löscht pro Stunde höchstens 1000 Zeilen je Tabelle. Eine einzelne Adresse kann am Aktivierungs-Endpunkt rund 44.600 Limit-Zeilen pro Tag erzeugen (eine je Schlüsselpräfix, vor jeder Prüfung), eine Installation mindestens 7.200 Nonce-Zeilen pro Stunde, weil die Nonce-Zeile vor der Limitprüfung geschrieben wird. Abhilfe: in einer Schleife löschen, bis weniger als ein Stapel übrig ist; Nonce erst nach bestandenem Limit speichern; Schlüsselformat prüfen, bevor die Präfix-Zeile entsteht; pro Lizenz nur ein offenes Download-Token je Update-Prüfung.
- [ ] **T-157 [WICHTIG] Limit der signierten API hängt an der Schreibweise.** Der Schlüssel des Limits (120 pro Minute) wird aus dem Header `x-wplc-installation-id` gebildet, wie er gesendet wird; der Header ist nicht signiert und wird nicht kleingeschrieben. Vergleicht die Datenbank ohne Beachtung der Groß- und Kleinschreibung, bekommt jede Schreibweise derselben UUID ein eigenes Kontingent. Abhilfe: Limit an die ID der authentifizierten Aktivierung binden oder den Header vorher kleinschreiben.
- [ ] **T-158 [WICHTIG] Releases mit externer Paket-URL.** Bei `storage=url` leiten Update-Download und Kunden-Download nach der Prüfung der Host-Liste auf die unveränderte, dauerhafte URL weiter, solange kein eigener Resolver über `wplc_resolve_update_package_url` eingehängt ist. `docs/UPDATE-STORAGE.md` verspricht, dass der Client nie eine dauerhafte Adresse erfährt; `docs/UPDATES.md` nennt eine einfache HTTPS-URL als zulässiges Ziel. Regel festlegen und beide Dokumente angleichen (hängt mit T-31 zusammen). Zusätzlich: beim Einlösen prüfen, ob das Release noch veröffentlicht ist (heute bis zu 600 Sekunden nach dem Zurückziehen einlösbar); eine URL auf dem eigenen Host, die auf `/wplc/v1/packages/release/N` endet, bei der Anlage ablehnen.
- [ ] **T-159 [WICHTIG] Verbuchung von Verlängerungen.** `RenewalProcessor::finish_paid` wertet das Ergebnis von `mark_renewed` nicht aus: Eine Zahlung für eine gesperrte oder abgelaufene Lizenz wird als bezahlt verbucht, ohne dass die Laufzeit steigt. Der Zweig „bereits bezahlt“ und `reconcile_stuck_renewal` prüfen weder `net_matches` noch die Währung. `net_matches` sieht nur die Produktzeile, keine Gebühren oder Guthaben anderer Plugins. Die PaymentIntent-ID des Webhooks wird nicht mit der gespeicherten verglichen und vor der Betragsprüfung überschrieben. Offene Verlängerungsbestellungen bleiben nach Ablauf oder Sperre bezahlbar. Hängt mit T-136 und T-137 zusammen.
- [ ] **T-160 [WICHTIG] Webhook-Eingang ohne Wiedervorlage.** Geht das Einplanen nach dem Speichern eines Stripe-Ereignisses verloren, bleibt die Zeile auf `pending_reconciliation`, und Stripes Wiederholung wird als Duplikat beantwortet. Täglichen Lauf ergänzen, der alte offene Zeilen neu einplant.
- [ ] **T-161 [WICHTIG] Signaturschlüssel des Servers.** Schlägt die Verschlüsselung fehl, speichert `ResponseSigner::rotate` den privaten Ed25519-Schlüssel ohne Warnung im Klartext in `wp_options`. Lässt sich der gespeicherte Schlüssel nicht mehr entschlüsseln, fehlt die Signatur in den Antworten stillschweigend. `wp wplc rotate-installation-secrets` verschlüsselt diese Option nicht neu. Bei Deinstallation bleibt sie liegen. Abhilfe: Fehler statt Klartext, Zustand in der Diagnose anzeigen, Option in die Rotation aufnehmen. Hängt mit T-86 zusammen.
- [ ] **T-162 [WICHTIG] Datenschutz-Löschung und Aufbewahrung.** `TERMINAL_STATES` in `PersonalData` und `Retention` enthält den nicht existierenden Status `cancelled` und nicht `suspended`. Der Eraser löst beendete Lizenzen vom Kunden, lässt deren aktive Aktivierungen (Site-URL, Versionen) aber stehen; sie sind danach für keinen Export und keine Löschung mehr erreichbar. Der Fortschritts-Cursor `wplc_privacy_eraser_cursor_<id>` bleibt nach einem abgebrochenen Lauf stehen, sodass ein späterer Auftrag Lizenzen überspringt. `Retention::delete_activations` löscht Nonces, bevor feststeht, dass die Aktivierung wirklich entfernt wird. Hängt mit T-92 zusammen.
- [ ] **T-163 [WICHTIG] Release-Workflow absichern.** Actions in allen Workflows auf Commit-Hashes festlegen (heute bewegliche Tags, in `release.yml` mit Schreibrecht). `contents: write` vom Workflow auf den Job legen und Bauen von Veröffentlichen trennen. Vor dem Release die Tests laufen lassen und prüfen, dass der Tag auf `main` liegt; Tag-Schutz einrichten. `composer.lock` einchecken und im Release-Job `--no-plugins --no-scripts` setzen. Die Prüfsumme liegt im selben Release wie das ZIP und ist kein unabhängiger Nachweis. Zusammen mit T-95 erledigen.
- [ ] **T-164 [WICHTIG] Client-Bibliothek.** `LicenseClient::check_update()` gibt `package_url`, `sha256`, Version und Produktschlüssel ungeprüft zurück. Antwort ablehnen, wenn der Produktschlüssel nicht passt, die Version nicht neuer ist, die URL nicht mit `https` beginnt oder die Prüfsumme nicht 64 Hex-Zeichen hat; eine Hilfsfunktion mitliefern, die das geladene ZIP gegen die Prüfsumme prüft. Bei der Aktivierung `payload` verlangen und den alten Signaturpfad ohne Nonce entfernen. Ein leerer gespeicherter `license_key_hash` darf nicht als Treffer für jeden Schlüssel gelten. `docs/CLIENT-INTEGRATION.md` erwähnt `signature_ed25519` nicht. Zusammen mit T-134 erledigen.
- [ ] **T-165 [WICHTIG] Aktivierung.** `ActivationService::activate` deaktiviert die bisherige Aktivierung derselben Site, bevor Limitprüfung, Verschlüsselung und Einfügen gelaufen sind; scheitert ein späterer Schritt, steht die Site ohne Aktivierung da. Das Site-Limit wird vor der Sperre gelesen. Der erste Pfad der Re-Aktivierung ändert `site_url` ohne Statusbedingung und ohne Sperre. `product_version`, `wp_version` und `php_version` werden nicht wie in `EntitlementService` auf 30 Zeichen gekürzt.
- [ ] **T-166 [WICHTIG] Lizenzen ohne Kunde und Erstattungen.** Gastschutz und Opt-in hängen am Produkt-Meta `_wplc_is_license_product`, die Vergabe an der Tariftabelle; bei abweichendem Stand kann eine Gast- oder Adminbestellung eine Lizenz mit `customer_id` 0 erzeugen, die niemand im Kundenkonto sieht. `handle_refund()` setzt `_wplc_refund_processed`, bevor der Widerruf gelaufen ist; ein Fehler wird nicht wiederholt. Erstattungen, die bei deaktiviertem Plugin laufen, werden nie nachgeholt. Die Stückzahl einer Bestellzeile ist unbegrenzt (ebenso viele Lizenzen und Mails in einer Anfrage).

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
- [ ] **T-167 [OPTIMIERUNG]** *Security-Audit 14.11.2026, Downloads und Betrieb.* Andere Methoden als GET ablehnen, bevor ein Download-Token verbraucht wird (HEAD-Anfragen von Link-Prüfern). Download-Token an die ausstellende Aktivierung binden. Die Host-Liste ist zweimal mit verschiedenen Parsern umgesetzt (`UpdateService::trusted_package_url`, `PackageStream::url_matches_hosts`); zusammenführen. Lokale Pakete beim Ausliefern erneut gegen die Prüfsumme prüfen. Ist `WPLC_TRUSTED_PROXY_HEADER` ohne `WPLC_TRUSTED_PROXIES` gesetzt, wird der Header von jedem Absender akzeptiert; dann ignorieren oder in der Diagnose warnen, ebenso wenn Anfragen im Sammeltopf `unknown` landen.
- [ ] **T-168 [OPTIMIERUNG]** *Security-Audit 14.11.2026, API und Sonstiges.* Signierte Routen lesen Parameter über `get_param()`, also auch aus dem unsignierten Query-String; nur aus dem signierten Body lesen. Die Fehlermeldungen der Authentifizierung verraten, ob eine Installations-UUID aktiv ist; einheitliche 401. Die Nonce läuft genau am Rand des Zeitfensters ab; Lebensdauer länger als das Fenster wählen. `/entitlements/check` deklariert keine String-Argumente. `SecretCrypto` bindet weder Zweck noch Zeile in die AAD; Altpfad ohne Version nach der Migration entfernen. CSV-Export neutralisiert keine Formelzeichen (`=`, `+`, `-`, `@`). Der volle Lizenzschlüssel steht bei jedem Aufruf im HTML des Kundenkontos; erst auf Klick laden. `Schema::maybe_upgrade` läuft bei fehlgeschlagener Migration in jeder Anfrage erneut; kurze Wartezeit einbauen.

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
