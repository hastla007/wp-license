# WP License Captain — Programmierplan

**Repository:** `hastla007/wp-license`  
**Arbeitsbranch:** gestapelte Branches codex/pr-01-foundation bis codex/pr-12-release  
**Stand:** 12.11.2026

## 1. Ziel und Ausgangslage

Dieses Repository wird zum WooCommerce-Plugin, das als Lizenzserver für WP Affiliate Captain, Voucher Captain und WP Pass Captain dient. Die API erhält von Beginn an eine Versionierung, damit sie später aus dem Shop-Plugin herausgelöst werden kann.

Der Basisbranch main ist weiterhin der Ausgangspunkt. Die PR-Branches 01–12 enthalten inzwischen den Plugin-Unterbau, Lizenzserverfunktionen, Client-SDK und Betriebsdokumentation. Die Pull Requests sind gestapelt und noch nicht gemergt. Die Abschnitte unten beschreiben den Stand auf den PR-Branches, nicht den Stand von main.

### Festgelegte Produktregeln

- Affiliate Captain: Starter 1, Pro 3, Agency 10 Live-Websites.
- Voucher Captain: Solo 1, Business 5, Agency 25 Live-Websites; Free ohne Lizenzschlüssel.
- WP Pass Captain: Pro 1, Business 5, Agency 25 Live-Websites; Core ohne Lizenzschlüssel.
- Je Live-Site ist eine verknüpfte Staging-Site ohne zusätzlichen Platz enthalten.
- Die bestehenden öffentlichen Funktionen der drei Produkte bleiben verfügbar. Neue Premiumrechte werden nur für neu entwickelte Funktionen verwendet.
- Lizenzserver zunächst als Plugin im WooCommerce-Verkaufsshop; kein WooCommerce Subscriptions.
- Automatische Verlängerungen mit Stripe und PayPal nur bei geeigneter Token-/Recurring-Unterstützung. Zahlungskartendaten oder CVV werden nicht im Lizenzserver gespeichert.
- Wiederholungsversuche bei fehlgeschlagener Zahlung: Tag 1, 3 und 7; Kulanzzeit: 14 Tage.
- Kündigung beendet die Lizenz am Ende der bezahlten Laufzeit. Bis dahin bleiben die Rechte aktiv.
- Add-ons werden anteilig bis zum nächsten Hauptverlängerungstermin berechnet und danach gemeinsam verlängert.
- Core-Downloads und Core-Updates sind schlüsselfrei; Premium-Downloads und Premium-Updates benötigen eine passende aktive Lizenz.
- Ein lokaler, signierter Rechte-Cache darf bei Serverausfall höchstens 14 Tage verwendet werden. Bereits laufende Shop-Prozesse und verkaufte Gutscheine/Tickets dürfen durch Lizenzablauf nicht unbrauchbar werden.
- WooCommerce-Bestellungen müssen über WooCommerce CRUD APIs und HPOS-kompatibel verarbeitet werden.
- Produktclients müssen die jeweiligen PHP-Mindestversionen ihrer Repositories erhalten; aktueller Prüfstand: Affiliate Captain 7.4, Voucher Captain 8.1, Pass Captain 8.2. Vor Umsetzung erneut verifizieren.

## 2. Arbeitsweise und Fertig-Kriterium

Jeder Umsetzungsschritt wird als kleiner, reviewbarer Pull Request mit eigener Änderung und nachvollziehbarem Abnahmepunkt umgesetzt. Für jeden Schritt gehören dazu:

1. Code und Datenbankänderungen,
2. passende automatisierte Tests,
3. manuelle Prüfung im WooCommerce-Testshop, wenn Bestell-, Zahlungs- oder Updateabläufe betroffen sind,
4. Aktualisierung der Entwicklerdokumentation.

**Definition of Done für Version 1:** Eine Testbestellung stellt zuverlässig eine Lizenz aus; Kunde und Administrator können sie verwalten; Lizenzclients können Websites aktivieren und Rechte abrufen; Site-Limits und Staging-Regeln sind korrekt; Verlängerungen, Kündigungen, Zahlungsausfälle und Erstattungen führen zu konsistenten Statuswerten; Updates funktionieren; alle drei Clients bleiben auf ihren PHP-Mindestversionen lauffähig.

## 3. Entwickler-Checkliste: aktueller Stand

**Abhakregel:** Ein Haken bedeutet, dass Code oder Dokumentation in einem PR erstellt wurde. Er bedeutet nicht, dass der PR gemergt, geprüft oder produktiv abgenommen ist.

### In PR01–PR12 umgesetzt

- [x] **PR01:** Plugin-Grundgerüst, Bootstrap und WooCommerce-Abhängigkeit.
- [x] **PR02:** Datenbankschema und Schema-Versionierung für Produkte, Pläne, Lizenzen, Aktivierungen, Rechte, Verlängerungen, Webhook-Inbox und Audit-Log.
- [x] **PR03:** Produkt-/Plan-Katalog und WooCommerce-Zuordnung.
- [x] **PR04:** Bezahlte Bestellungen idempotent in Lizenzdatensätze umsetzen.
- [x] **PR05:** Lizenzlebenszyklus, Erstattungsbehandlung, Statusaktionen und Audit-Ereignisse.
- [x] **PR06:** REST API v1 für Aktivierung, Deaktivierung und Rechteprüfung mit HMAC-Authentifizierung.
- [x] **PR07:** Live-Site-Limits, Staging-Verknüpfung und Schutz gegen parallele Überaktivierung.
- [x] **PR08:** Kundenportal und administrative Lizenzaktionen.
- [x] **PR09:** Verlängerungsgrundgerüst mit expliziter Zustimmung, Adaptervertrag, Wiederholungen und Kulanzablauf.
- [x] **PR10:** Update-Metadaten, Allowlist für HTTPS-Pakethosts und kurzlebige Downloadfreigaben.
- [x] **PR11:** Gemeinsamer PHP-Client für Aktivierung, signierte Rechteabfragen und Update-Metadaten.
- [x] **PR12:** README, Changelog, API-/Betriebsdokumentation und Release-Checkliste.

### Für die Freigabe noch offen

- [ ] PR01–PR12 reviewen, Konflikte beheben und in Reihenfolge mergen.
- [ ] Produkt-Repositories tatsächlich anbinden: Affiliate Captain, Voucher Captain und Pass Captain.
- [ ] Konkrete, getestete Zahlungsadapter für die beworbenen Gateways bereitstellen; aktuell existiert nur der Adaptervertrag.
- [ ] Downloadpakete hosten und den Produktclients die Signatur- und ZIP-Prüfung vor Installation beibringen.
- [ ] Tests, Kompatibilitätsmatrix, Checkout-/Gateway-Sandboxläufe und vollständige Sicherheitsprüfung durchführen.
- [ ] Betrieb, Datenschutz, Schlüsselrotation, Wiederherstellung und Supportprozesse vor kommerziellem Einsatz festlegen.

## 4. Nächste Phasen und PRs

### PR13 — Kernsystem härten und API-Vertrag finalisieren
- [x] Per-renewal advisory lock gegen parallele Scheduler-Ausführung ergänzt.
- [x] Update-Downloadgrants als gehashte DB-Tokens mit atomarer Einmalverwendung und erneuter Lizenzprüfung umgesetzt.
- [x] Dokumentierte stabile Schlüsselkonstanten und sichere Einsatzhinweise ergänzt; Migration bestehender Server bleibt offen.
- [ ] Code-Review von Datenbankzugriffen, Autorisierung, Statusübergängen, Nonce-/Rate-Limits, Redirects und Fehlerantworten durchführen.
- [ ] Schlüssel- und Wiederherstellungsstrategie definieren, damit Auth-Salt-Rotation nicht unbemerkt Lizenzschlüssel oder Installationsgeheimnisse unbrauchbar macht.
- [ ] Erneuerungsablauf auf Doppelverarbeitung, mehrdeutige Gateway-Timeouts, fehlgeschlagene WooCommerce-Bestellungen und abgelaufene Kulanz prüfen und korrigieren.
- [ ] API-Fehlercodes und Vertragsversionen vervollständigen; signierte Ereignis-/Webhook-Verarbeitung entweder spezifizieren oder explizit aus v1 streichen.
- [ ] Adminoberfläche für Produkt-/Plan-Konfiguration auf Berechtigungen, Validierung und vollständige Bedienbarkeit prüfen.
- **Abnahme:** API-Vertrag stimmt mit den Routen und gespeicherten Statuswerten überein; bekannte Sicherheits- und Datenintegritätsfehler sind behoben.

### PR14 — Stripe-Adapter
- [x] Offizielles WooCommerce-Stripe-Gateway als begrenzte Integration festgelegt; nur gespeicherte Kartentoken und eingeloggte Kunden.
- [x] Erneuerung nur mit expliziter WP License Captain Zustimmung und kunden-eigenem gespeicherten Token ermöglichen.
- [x] Stable provider idempotency key, PaymentIntent-Bestätigung und Authentifizierungsbedarf behandeln.
- [ ] Webhook-Signatur, Event-Inbox, Duplikaterkennung und Zustandsabgleich ergänzen.
- [ ] Erfolgs-, Ablehnungs-, Widerrufs- und Timeoutfälle in einer Gateway-Sandbox durchspielen.
- [ ] Kundenflow zur manuellen Authentifizierung und Zahlungswiederherstellung ergänzen.
- **Abnahme:** Keine Abbuchung ohne gültigen Token und Zustimmung; wiederholte Events erzeugen keine doppelte Zahlung oder Verlängerung.

### PR15 — PayPal-Adapter (gesperrt bis passendes Abo-Modell)
- [x] Prüfen, ob ein gespeicherter PayPal-Vault-Token für eigene Off-Session-Renewal-Orders ausreicht: nein.
- [x] Unkonfigurierte PayPal-Verlängerungen fail-closed halten; kein PayPal-Adapter wird registriert.
- [x] Kompatibilitätsgrenze und erforderliches PayPal-Abo-/Webhookmodell dokumentieren.
- [ ] Genehmigungs-, Abo- und Webhookmodell mit einem konkreten PayPal-Konto und WooCommerce-Gatewayversion umsetzen.
- [ ] Sandboxfälle für Aktivierung, Erfolg, Ablehnung, Kündigung, verspätete/doppelte Events und Refund durchspielen.
- **Abnahme:** erst erfüllt, wenn eine unterstützte PayPal-Abo-/Vault-Integration ohne WooCommerce-Subscriptions-Zwang nachweislich funktioniert; bis dahin werden PayPal-Renewals nicht angeboten.

### PR16 — Produktclients in den drei Plugin-Repositories
- [ ] Aktuelle PHP-/WordPress-/WooCommerce-Mindestversionen und Architektur jedes Repositories erneut erfassen.
- [ ] Gemeinsamen Client kompatibel bundlen oder als versioniertes Paket ausliefern; keine Mindestversion unbeabsichtigt anheben.
- [ ] CouponMaster: Aktivierungseinstellungen, Add-on-Rechte, Offlinecache und Updates anbinden.
- [ ] PassMaster: Aktivierungseinstellungen, Tarifrechte, Offlinecache und Updates anbinden.
- [ ] AffiliateMaster: Aktivierungseinstellungen, Tarif-/Feature-Rechte, Offlinecache und Updates anbinden.
- [ ] Bestehende kostenlose Funktionen unverändert lassen; nur neue Premium-Funktionen sperren.
- **Abnahme:** Je Produkt eigener PR im jeweiligen Repository, mit dokumentierter Rechtezuordnung und Kompatibilität.

### PR17 — Paketveröffentlichung und WordPress-Updater
- [ ] Sichere Ablage und Versionierung für Core- und Premium-ZIPs einrichten.
- [ ] Release-Prozess erzeugt Metadaten inklusive Version, Mindestversionen, Changelog und SHA-256.
- [x] Produktclient prüft signierte Update-Metadaten und ZIP-SHA-256 vor Installation (Client-PRs vorbereitet; Runtime-Abnahme offen).
- [x] Update-Transient/Plugin-Upgrader anbinden; Fehler werden als kontrollierte WP_Error-Antwort zurückgegeben (Testshop-Abnahme offen).
- [x] Downloadgrant-Ablauf, einmalige Nutzung, Host-Allowlist und erneute Anforderung nach Ablauf clientseitig behandelt; Storage-/Lizenzfehler brechen kontrolliert ab (Testshop-Abnahme offen).
- **Abnahme:** Autorisierte und nicht autorisierte Updatepfade verhalten sich im Testshop wie spezifiziert; Rollbackpakete sind verfügbar.

### PR18 — Automatisierte Qualitätssicherung und Kompatibilität
- [ ] Vollständige CI für PHP, WordPress-/WooCommerce-Versionen, Coding-Standards und statische Analyse einrichten.\n- [x] PHP-Syntax-Baseline für PHP 7.4/8.1/8.2/8.3 ergänzt (PR19; noch nicht ausgeführt).
- [ ] Unit-/Integrationstests für Lizenzvergabe, doppelte Bestellereignisse, Refunds, Statuswechsel und Migrationen ergänzen.
- [ ] REST-Sicherheitstests für Signaturen, Replay, Rate-Limits, Site-Limits und Staging ergänzen.
- [ ] Renewal- und Updateabläufe mit WooCommerce-Testdaten und Gateway-Sandboxes abdecken.
- [ ] Kompatibilität mindestens gegen die dokumentierten Mindestversionen der drei Produktclients prüfen.
- **Abnahme:** CI ist reproduzierbar; kritische End-to-End-Fälle sind bestanden und Fehlerberichte referenzieren die betroffenen Versionen.

### PR19 — Adminbetrieb und Supportwerkzeuge
- [ ] Ablauf für Lizenzsuche, Verlängerungsstatus, Gatewayfehler und manuelle Korrektur vervollständigen.
- [ ] Sichere Wiederherstellung bei Domainwechsel, verlorenem Schlüssel und Installationslimit definieren.
- [ ] Paginierung, Bulk-Aktionen, Berechtigungen und Audit-Nachweise für große Lizenzbestände prüfen.
- [x] Read-only Diagnoseinformationen ohne Schlüssel, Tokens oder Zahlungsgeheimnisse bereitgestellt (PR20; Review/Abnahme offen).
- **Abnahme:** Support kann typische Fälle ohne direkte Datenbankänderung lösen; jede Änderung ist nachvollziehbar.

### PR20 — Produktionsbetrieb und Launch
- [ ] Lizenzserver mit HTTPS, stabiler Schlüsselverwaltung, Backups und Restoreverfahren bereitstellen.
- [ ] Überwachung für Renewalwarteschlangen, Webhooks, REST-Fehler, Updatepaketfehler und Speicherverfügbarkeit einrichten.
- [ ] Datenaufbewahrung/-löschung, Datenschutzhinweise, Checkouttexte und Kündigungsprozess festlegen.
- [ ] Sicherheitsprüfung und rechtliche Prüfung des Vertriebs-/Lizenzmodells abschließen.
- [ ] Release-, Support-, Incident- und Rollback-Verantwortliche festlegen.
- **Abnahme:** Alle Punkte in der Release-Readiness-Checkliste sind geprüft; erst dann kommerzieller Start.

## 5. Ursprünglicher technischer Detailplan

### Schritt 0 — Repositories und technische Entscheidungen festziehen

**Aufgaben**
- In allen drei Produkt-Repositories jede bestehende Funktion als `core/legacy-free` oder geplante neue Premiumfunktion klassifizieren.
- Aktuelle PHP-, WordPress- und WooCommerce-Mindestversionen sowie Plugin-Slugs, Update-Slugs und Textdomains erfassen.
- Vorhandene Stripe-/PayPal-Gatewayplugins und deren unterstützte Token- und Folgeabbuchungs-APIs festlegen.
- Produkt-SKUs und WooCommerce-Produkt-/Variationszuordnung für Tarife und Add-ons definieren.
- Zielversionen für PHP und WooCommerce des Lizenzservers festlegen.

**Ergebnis / Abnahme**
- Eine versionierte Kompatibilitätsmatrix und ein Produktkatalog, der ohne Codeänderung administrierbar ist.
- Kein existierendes öffentliches Feature ist als neu lizenzpflichtig markiert.

### Schritt 1 — Plugin-Grundgerüst und lokale Entwicklungsumgebung

**Aufgaben**
- WordPress-Plugin-Header, Bootstrap, Aktivierungs-/Deaktivierungshooks und Prüfung auf WooCommerce-Abhängigkeit einrichten.
- Namensraum und Autoloading definieren; empfohlener Namensraum: `WPLC\\`.
- Verzeichnisstruktur anlegen:
  - `wp-license.php` — Plugin-Header und Bootstrap
  - `src/Plugin.php` — Initialisierung und Abhängigkeiten
  - `src/Admin/` — WooCommerce-Adminoberfläche
  - `src/Api/V1/` — versionierte REST-Endpunkte
  - `src/License/`, `src/Activation/`, `src/Entitlement/` — Domänenlogik
  - `src/Billing/`, `src/Gateway/` — Verlängerungen und Zahlungsadapter
  - `src/Update/` — Update-Metadaten und Downloads
  - `src/Infrastructure/Database/` — Schema und Migrationen
  - `tests/`, `languages/`, `docs/`
- Coding-Standards, statische Analyse und automatisierte Testumgebung festlegen.
- Sichere Deinstallation definieren: Lizenz- und Bestelldaten werden nicht stillschweigend gelöscht.

**Ergebnis / Abnahme**
- Plugin aktiviert sich sauber, meldet fehlende WooCommerce-Abhängigkeit verständlich und erzeugt noch keine Lizenzdaten.
- Qualitätswerkzeuge laufen reproduzierbar lokal und in CI.

### Schritt 2 — Katalog, Datenbankschema und Migrationen

**Aufgaben**
- Tabellen mit dynamischem `$wpdb->prefix` und Schema-Versionierung anlegen: Produkte, Pläne, Lizenzen, Aktivierungen, Entitlements, Verlängerungen, Webhook-Inbox und Audit-Log.
- Primär-/Fremdschlüssel, eindeutige Event- und Idempotency-Schlüssel, Indizes und Aufbewahrungsregeln spezifizieren.
- Geldbeträge als Ganzzahl in Minor Units plus ISO-Währung speichern; Zeiten intern in UTC.
- Plan- und Rechtekatalog für Produkte, Laufzeit, Preis, Live-Site-Limit, Supportstufe und Add-ons bereitstellen.
- Migrationen idempotent und für große Tabellen erweiterbar machen.

**Ergebnis / Abnahme**
- Aktivierung, Plugin-Update und wiederholte Migrationen beschädigen keine Daten.
- WooCommerce Bestellungen und Kunden bleiben über WooCommerce APIs die führende Quelle; Bestelldaten werden nicht in eigene Tabellen dupliziert.

### Schritt 3 — Bestellungen zu Lizenzen und Lizenzlebenszyklus

**Aufgaben**
- WooCommerce-Produkte/Variationen auf `product_key`, `plan_key`, Intervall, Site-Limit und Entitlements abbilden.
- Bei bezahlter Bestellung Lizenzen pro gekaufter Einheit erzeugen und mit Kunde, Bestellposition und gegebenenfalls Add-on verbinden.
- Bestellereignisse idempotent verarbeiten, damit doppelte Hooks/Webhooks keine doppelten Lizenzen ausstellen.
- Schlüssel mit hoher Entropie erzeugen; nur Hash und ein nicht geheimes Anzeigepräfix speichern.
- Zahlung, Verlängerung, Kündigung, Voll-/Teilrefund, Chargeback, manuelle Sperre und Kulanz als explizite Zustandsübergänge modellieren.
- Manuelle Adminaktionen mit Grund und Audit-Eintrag versehen.

**Ergebnis / Abnahme**
- Jede bezahlte Einheit erzeugt exakt eine Lizenz.
- Teilrefund sperrt nicht automatisch die gesamte Lizenz; Vollrefund widerruft zukünftige Lizenz-/Downloadrechte.
- Keine Kartendaten oder vollständigen Lizenzschlüssel in Logs.

### Schritt 4 — REST API v1 und Signierung

**Aufgaben**
- Namespace `/wp-json/wplc/v1` registrieren.
- Endpunkte für Aktivierung, Deaktivierung, Entitlement-Prüfung, Update-Metadaten, kurzlebige Downloadtokens und signierte Zahlungsereignisse implementieren.
- Requests mit Installation-ID, normalisierter Site-URL, Produkt- und Clientversion validieren.
- Timestamp, Nonce und HMAC-Signatur gegen Replay-Angriffe prüfen; TLS voraussetzen.
- Stabile Fehlercodes definieren, darunter `invalid_key`, `product_mismatch`, `site_limit`, `expired`, `past_due`, `addon_required`, `incompatible_client` und `retry_later`.
- Rate Limits auf Lizenz, Installation und IP einführen.

**Ergebnis / Abnahme**
- API-Antworten sind versioniert und enthalten Status, `paid_until`, Site-Limit, Entitlements und Cache-Ablauf.
- Ungültige Signaturen, wiederholte Nonces und falsche Produktbindungen werden abgewiesen.

### Schritt 5 — Aktivierungen, Site-Limits und Staging

**Aufgaben**
- Installation-ID generieren und pro Installation persistieren.
- Live-Domain normalisieren; Domainwechsel als kontrollierten Deaktivieren-/Reaktivieren-Ablauf behandeln.
- Site-Limits pro Produktlizenz erzwingen.
- Staging-Umgebung nicht allein anhand von Hosting-Erkennung freischalten: Kunde ordnet sie einer Live-Site zu; maximal eine Staging-Site pro Live-Site.
- Aktivierungen in Kundenkonto und Admin anzeigen; Deaktivierung gibt Platz frei und erzeugt Audit-Ereignis.
- Lokale Entwicklungsinstallationen in dokumentierten Fällen nicht als Live-Aktivierung zählen.

**Ergebnis / Abnahme**
- Gleichzeitige Aktivierungen über dem Limit schlagen konsistent fehl.
- Ein verknüpftes Staging belegt keinen zusätzlichen Live-Platz.

### Schritt 6 — Kundenkonto und Shop-Administration

**Aufgaben**
- WooCommerce „Mein Konto“-Bereich für Lizenzübersicht, Schlüsselanzeige/Kopieren, Aktivierungen, Staging-Zuordnung, Premium-Download und Kündigung ergänzen.
- Kundenkonto für alle kostenpflichtigen Bestellungen erzwingen; Free-Core bleibt ohne Login verfügbar.
- Adminansicht für Lizenzen, Kunden, Laufzeiten, Verlängerungen, Aktivierungen, Add-ons, Zahlungsprobleme und Downloadfehler implementieren.
- Manuelle Aktionen mit Capability-Prüfung, Nonce, Bestätigungsdialog und Begründung absichern.
- Support-Level und Antwortziele im Konto sichtbar machen.
- Statushinweise bei abgelaufener Lizenz und ausstehender Zahlung anzeigen.

**Ergebnis / Abnahme**
- Kunde kann seine Lizenz ohne Supporteingriff einsehen, Websites verwalten und kündigen.
- Jede Adminänderung ist mit Akteur, Zeitpunkt und Grund nachvollziehbar.

### Schritt 7 — Erneuerungen und Zahlungsadapter

**Aufgaben**
- Renewal-Jobs mit Action Scheduler, Sperren gegen Parallelverarbeitung, begrenzten Batches und sichtbarer Fehlerwarteschlange planen.
- Abrechnungstermine für Monats-/Jahresintervalle inklusive Monatsende-Regel berechnen.
- Adaptergrenze für Stripe und PayPal implementieren; zunächst vorhandene Shop-Gateway-Token-APIs prüfen, dann je Gateway einzeln integrieren.
- Vor jeder Abbuchung eine Idempotency-Key-Strategie verwenden und WooCommerce-Erneuerungsbestellung erstellen.
- Fehlversuche an Tag 1, 3 und 7 wiederholen; Status `past_due` und 14-Tage-Kulanz verwalten.
- Kündigung verhindert die nächste Abbuchung, lässt Rechte bis `paid_until` aktiv.
- Add-ons beim Kauf anteilig bis zum Haupttermin berechnen und danach gemeinsam erneuern.
- Zahlungsart nicht für automatische Verlängerung anbieten, wenn Gateway/Token dies nicht sicher unterstützt.

**Ergebnis / Abnahme**
- Doppelter Job oder wiederholtes Gateway-Event erstellt höchstens eine Abbuchung und eine Renewal-Bestellung.
- Nach Ende der Kulanz werden Premium-Neueinrichtungen und Premium-Updates gesperrt; bestehende Abläufe und Daten bleiben erhalten.

### Schritt 8 — Update- und Downloadserver

**Aufgaben**
- Öffentlichen Core-Updatefeed für Free-Core-Pakete bauen.
- Premiumfeed nur mit aktiver Produkt-/Add-on-Lizenz ausliefern.
- Downloadberechtigung serverseitig prüfen und kurzlebige, signierte URL generieren.
- ZIP-Metadaten mit Version, Changelog, erforderlicher PHP-/WordPress-/WooCommerce-Version und Prüfsumme veröffentlichen.
- Clientseitig Updates erst nach erfolgreichem Download installieren; Fehler dürfen Shop oder Plugin nicht deaktivieren.
- Package-Hosting/Storage, Backup, Retention und Rollbackstrategie festlegen.

**Ergebnis / Abnahme**
- Nicht autorisierte Nutzer erhalten kein Premium-ZIP.
- Schlüssel läuft nicht in Querystrings, Webserverlogs oder Update-URLs dauerhaft mit.

### Schritt 9 — Lizenzclient in die drei Plugins integrieren

**Aufgaben**
- Pro Plugin ein Client-Adaptermodul erstellen; gemeinsame Logik ohne höhere PHP-Mindestversion als Affiliate Captain.
- Einstellungsseite für Schlüssel, Status, Laufzeit, Site-Zähler und Add-on-Rechte erstellen.
- Signierte Entitlement-Antwort lokal cachen; maximal 14 Tage offline verwenden.
- Positive und negative Feature-Gates nur für neue Premiumfunktionen implementieren.
- Core-Updates schlüsselfrei und Premium-Updates lizenzgebunden anbinden.
- Keine Bestell-, Gutschein-, Ticket- oder Affiliate-Kundendaten an den Lizenzserver senden.

**Ergebnis / Abnahme**
- Affiliate-, Voucher- und Pass-Client erfüllen jeweils die aktuelle PHP-/WordPress-/WooCommerce-Kompatibilität.
- Bei Lizenzserverausfall werden bestehende Kernabläufe nicht blockiert.

### Schritt 10 — Betrieb, Sicherheit und Release

**Aufgaben**
- API-, Zahlungs- und Lizenzereignisse strukturiert protokollieren; geheime Daten redigieren.
- Backups und Wiederherstellung für Lizenz-/Aktivierungsdaten dokumentieren und erproben.
- Überwachung für fehlgeschlagene Renewals, aufgestaute Jobs, Gateway-Webhooks, API-Fehler und Updatepaketfehler einrichten.
- Datenschutz-/Checkouttexte, Kündigungsablauf, Supportprozesse und GPL-/Add-on-Vertriebsmodell rechtlich prüfen.
- CI-Matrix für unterstützte PHP-Versionen sowie WooCommerce/WordPress einrichten.
- Release-, Rollback- und Sicherheitsupdateprozess definieren.

**Ergebnis / Abnahme**
- Testfälle für Kauf, doppelte Events, Verlängerung, Kündigung, Refund, Chargeback, Site-Limit, Staging, Offlinebetrieb und Update sind bestanden.
- Release ist reproduzierbar und kann bei Fehlern zurückgerollt werden.

## 6. Pull-Request-Status und Merge-Reihenfolge

- [x] PR01–PR12 als aufeinander aufbauende Änderungen angelegt.
- [x] Roadmap-Dokumentation als PR #13 aktualisiert.
- [ ] Phase-13-Codeänderungen reviewen und mergen (GitHub PR #14).
- [ ] PR01 → PR12 reviewen und mergen; Folge-PRs basieren jeweils auf dem vorherigen Branch.
- [ ] PR13–PR20 gemäß den oben beschriebenen nächsten Phasen umsetzen.
- [ ] PR16 umfasst zusätzliche, getrennte Pull Requests in den drei Produkt-Repositories.

## 7. Offene Entscheidungen und externe Voraussetzungen

- [ ] Reale Source-Dateien der drei Produkt-Repositories und deren aktuelle Mindestversionen auditieren.
- [ ] Konkrete Stripe-/PayPal-Gatewayversionen sowie tokenisierte Folgeabbuchungen in Sandboxen verifizieren.
- [ ] Hosting für Premium-ZIPs, Backup-/Retentiondauer und Alarmierung festlegen.
- [ ] Rechtliche Prüfung von GPL-Kompatibilität, Add-on-Vertrieb, Verbraucherinformation, Kündigung und Datenschutz abschließen.
- [ ] Entscheiden, ob Zahlungsereignisse über Gateway-Webhooks oder ausschließlich über Gateway-API-Abfragen verarbeitet werden; beide Wege nicht parallel ohne klare Zuständigkeit implementieren.

## 8. Nächster konkreter Startpunkt

Als nächstes PR13 auf Basis von PR12 erstellen: zuerst Architektur- und Sicherheitsreview, bekannte Fehler im Implementierungsstand beheben und den API-/Renewal-Vertrag präzisieren. Danach Stripe- und PayPal-Adapter getrennt umsetzen. Die PRs 01–12 sind offen und noch nicht gemergt; neue Branches daher weiterhin auf den jeweils letzten Stack-Branch aufsetzen.


### Phase 17 — Produkt-Updater
- [x] Premium-Update-Hooks, Lizenzabfrage, einmalige Downloadfreigabe, HTTPS-Host-Allowlist und SHA-256-Prüfung in den drei Produktclients vorbereitet.
- [x] Abgelaufene Downloadfreigaben werden einmal neu angefordert.
- [x] Release-/Hostkonfiguration und offene Produktionsvoraussetzungen dokumentiert.
- [ ] Produkt-PRs mergen und im WordPress-Testshop gegen echte Paketspeicherung abnehmen.


## Fortschritt Phase 13–20 (12.11.2026)

Die folgenden Haken bestätigen vorbereitete Code-/Dokumentations-PRs, nicht Merge oder Abnahme:

- [x] **Phase 13 / PR14:** Token-/Renewal-Härtung und Sicherheitsnotizen vorbereitet; Review, Schlüsselmigration und vollständige Fehlerfallprüfung offen.
- [x] **Phase 14 / PR15:** begrenzter Stripe-Recurring-Adapter vorbereitet; Webhookabgleich, 3DS-Wiederherstellung und Sandboxabnahme offen.
- [x] **Phase 15 / PR16:** PayPal fail-closed Grenze dokumentiert; Adapter bleibt bewusst nicht verfügbar.
- [x] **Phase 16 / PR17 + Client-PRs:** Lizenzclient und Aktivierungseinstellungen in allen drei Produkten vorbereitet; Feature-Gates und Offlinecache offen.
- [x] **Phase 17 / PR18 + Client-PRs:** Updater, Downloadgrant-Erneuerung und SHA-256-Prüfung vorbereitet; echte Paketablage und WordPress-Testshop offen.
- [x] **Phase 18 / PR19:** PHP-Syntax-CI-Matrix und QA-Lücken dokumentiert; Integrationstests und Kompatibilitätsläufe offen.
- [x] **Phase 19 / PR20:** geschützte, schlüsselfreie Diagnoseansicht vorbereitet; Support- und Wiederherstellungsworkflows teilweise offen.
- [x] **Phase 20 / PR21:** Produktionsbetrieb, Backup/Restore, Überwachung, Rollback und Go/No-Go dokumentiert; Infrastruktur und rechtliche Prüfung offen.

### Offene Launch-Blocker

- [ ] Alle gestapelten PRs reviewen, Konflikte lösen, mergen und in der vorgesehenen Reihenfolge ausrollen.
- [ ] Stripe-Webhooks und manuelle Authentifizierung/Wiederherstellung implementieren und Gateway-Sandbox testen.
- [ ] PayPal-Verlängerungen deaktiviert lassen, bis ein konkret unterstütztes Abo-/Vaultmodell umgesetzt und getestet ist.
- [ ] Feature-Gates und höchstens 14 Tage signierten Offline-Entitlement-Cache in den Produktclients ergänzen.
- [ ] Release-Speicher/Manifest konfigurieren, Updates im echten WordPress-Testshop samt Fehler-/Rollbackfällen abnehmen.
- [ ] Vollständige PHPUnit-/REST-/Datenbankintegrationstests sowie Kompatibilitäts- und Sicherheitsprüfung ausführen.
- [ ] Produktionsschlüssel, Hosting, Monitoring, Backup/Restore-Test, Datenschutz-/Checkouttexte, Kündigung, Steuer-/Rechnungsprozess und Supportverantwortung festlegen.


## Entwickler-Checkliste: weitere 20 Aufgaben (Phasen 21–40)

**Statusregel:** Haken bestätigen vorhandene Code-/Dokumentationsänderungen. Sandbox-, Merge-, Laufzeit- und Produktionsabnahmen bleiben separat offen.

- [x] **21 · Entitlement-Katalog:** stabile Feature-IDs und vorläufige Tarifzuordnung in `docs/ENTITLEMENTS.md` dokumentieren; reale SKU-/Plan-Zuordnung vor Aktivierung validieren.
- [ ] **22 · SKU-/Plan-Mapping:** reale WooCommerce-Produkte, Variationen, Preise und Add-ons auf serverseitige Plan-IDs abbilden und Import/Export definieren.
- [ ] **23 · Gemeinsamer Feature-Gate-Client:** API zum Abfragen von boolean/quota-Entitlements mit deny-by-default und kompatiblen PHP-Mindestversionen ergänzen.
- [ ] **24 · Produkt-Gates:** neue Premiumfunktionen in allen drei Clients auf die freigegebenen Entitlement-IDs abbilden; kostenlose/Bestandsfunktionen unangetastet lassen.
- [ ] **25 · Offline-Rechtecache:** signierte Antworten lokal verifizieren, höchstens 14 Tage cachen, Ablauf/Manipulation testen und bestehende Shopdaten schützen.
- [ ] **26 · Stripe-Webhooks:** Signaturprüfung, Event-Inbox und Provider-Event-IDs für unterstützte Zahlungsereignisse implementieren.
- [ ] **27 · Renewal-Abgleich:** doppelte/verspätete Events, mehrdeutige Timeouts, Orders und Lizenzlaufzeiten idempotent abgleichen.
- [ ] **28 · Auth-Recovery:** Kundenflow für erforderliche Stripe-Kundenaktion, erneute Zahlung und Rückkehr zum Abo ergänzen.
- [ ] **29 · PayPal-Go/No-Go:** konkrete Gateway-/Abo-Version und Merchant-Freigabe nachweisen; bis dahin Checkout und Renewal-Werbung dafür deaktiviert halten.
- [ ] **30 · Release-Manifest:** reproduzierbares Manifestformat und CLI/Build-Schritt mit Version, Mindestversionen, Changelog und SHA-256 erzeugen.
- [ ] **31 · Paketablage:** austauschbaren privaten Storage-Provider mit signierten/kurzlebigen Redirects und dokumentierter Retention anschließen.
- [ ] **32 · Release-Rollback:** unveränderliche Vorversionen und dokumentierten Rollbackpfad in Build- und Updateprozess integrieren.
- [ ] **33 · Lizenzverwaltung:** Such-/Listenansicht auf Status, Kunde, Bestellung, Produkt und Renewal-Fälle ergänzen, falls aktuelle UI dies nicht abdeckt.
- [ ] **34 · Supportaktionen:** sichere, capability-/nonce-geschützte Domainfreigabe und Korrekturworkflows mit Begründung und Audit umsetzen.
- [ ] **35 · Testbasis:** automatisiertes Testframework und minimale isolierte Tests für Signatur, Entitlement-Vertrag und Schlüssel-Utilities einrichten.
- [ ] **36 · API-Sicherheitstests:** REST-Tests für Signaturen, Replay, Rate-Limits, Fehlermeldungen und Einmal-Downloadtoken ergänzen.
- [ ] **37 · Datenbank-/Renewaltests:** Tests für Schema-Upgrades, doppelte Bestellungen, Refunds, Renewal-Locks und Grace Period ergänzen.
- [ ] **38 · Kompatibilitätsmatrix:** minimale/aktuelle WordPress-, WooCommerce- und PHP-Versionen festschreiben und CI-Matrix entsprechend erweitern.
- [ ] **39 · Betriebsüberwachung:** konkrete Health-/Queue-/Fehler-Metriken und Alarmgrenzen für Renewals, Webhooks, API und Pakete implementieren.
- [ ] **40 · Launch-Evidenz:** Restore-, Gateway-, Sicherheits-, Datenschutz-, Kündigungs- und Supportabnahmen mit verantwortlicher Person und Datum dokumentieren.

**Umsetzungsstand dieses Updates:** Aufgabe 21 erledigt. Aufgaben 22–40 bleiben offen und werden als einzelne reviewbare PRs bearbeitet. Aufgabenausführung setzt bei externen Abhängigkeiten (Gatewaykonto, echte SKUs, Hosting/Secrets, Testshop, Rechtstexte) deren Bereitstellung voraus.
