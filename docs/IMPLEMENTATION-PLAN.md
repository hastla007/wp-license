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
- [x] PHP-Mindestversionen der drei Repositories erfasst (Affiliate 7.4, Voucher 8.1, Pass 8.2); aktuelle WordPress-/WooCommerce-Mindestversionen und vollständige Architekturprüfung bleiben offen.
- [x] Gemeinsamen Client PHP-kompatibel in den drei Produkt-Repositories gebundled, ohne in diesem Schritt die jeweilige Plugin-PHP-Mindestversion anzuheben (Client-PRs offen).
- [x] CouponMaster: Aktivierungseinstellungen und lizenzierte Update-Hooks vorbereitet.
- [ ] CouponMaster: Feature-/Add-on-Gates und Offlinecache anbinden.
- [x] PassMaster: Aktivierungseinstellungen und lizenzierte Update-Hooks vorbereitet.
- [ ] PassMaster: Tarif-/Feature-Gates und Offlinecache anbinden.
- [x] AffiliateMaster: Aktivierungseinstellungen und lizenzierte Update-Hooks vorbereitet.
- [ ] AffiliateMaster: Feature-Gates und Offlinecache anbinden.
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
- [ ] Vollständige CI für PHP, WordPress-/WooCommerce-Versionen, Coding-Standards und statische Analyse einrichten.
- [x] PHP-Syntax-Baseline für PHP 7.4/8.1/8.2/8.3 ergänzt (PR19; noch nicht ausgeführt).
- [ ] Unit-/Integrationstests für Lizenzvergabe, doppelte Bestellereignisse, Refunds, Statuswechsel und Migrationen ergänzen.
- [ ] REST-Sicherheitstests für Signaturen, Replay, Rate-Limits, Site-Limits und Staging ergänzen.
- [ ] Renewal- und Updateabläufe mit WooCommerce-Testdaten und Gateway-Sandboxes abdecken.
- [ ] Kompatibilität mindestens gegen die dokumentierten Mindestversionen der drei Produktclients prüfen.
- **Abnahme:** CI ist reproduzierbar; kritische End-to-End-Fälle sind bestanden und Fehlerberichte referenzieren die betroffenen Versionen.

### PR19 — Adminbetrieb und Supportwerkzeuge
- [ ] Verlängerungsstatus, Gatewayfehler und manuelle Korrektur im Supportablauf ergänzen.
- [ ] Sichere Wiederherstellung bei Domainwechsel, verlorenem Schlüssel und Installationslimit definieren.
- [ ] Paginierung, Bulk-Aktionen, Berechtigungen und Audit-Nachweise für große Lizenzbestände prüfen.
- [x] Lizenzliste mit Suche/Paginierung und auditierten Statusaktionen ist im Admin-Grundumfang vorhanden; Ausbau für Supportfälle offen.
- [x] Read-only Diagnoseinformationen ohne Schlüssel, Tokens oder Zahlungsgeheimnisse bereitgestellt (PR20; Review/Abnahme offen).
- **Abnahme:** Support kann typische Fälle ohne direkte Datenbankänderung lösen; jede Änderung ist nachvollziehbar.

### PR20 — Produktionsbetrieb und Launch
- [ ] Lizenzserver mit HTTPS, stabiler Schlüsselverwaltung, Backups und Restoreverfahren bereitstellen.
- [ ] Überwachung für Renewalwarteschlangen, Webhooks, REST-Fehler, Updatepaketfehler und Speicherverfügbarkeit einrichten.
- [ ] Datenaufbewahrung/-löschung, Datenschutzhinweise, Checkouttexte und Kündigungsprozess festlegen.
- [ ] Sicherheitsprüfung und rechtliche Prüfung des Vertriebs-/Lizenzmodells abschließen.
- [x] Release-, Incident- und Rollback-Ablauf als Runbook dokumentiert (Verantwortliche noch nicht benannt).
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
- [x] WooCommerce-Produkteditor unterstützt Zuordnung für einfache Produkte/Variationen mit Plan, Intervall, Site-Limit, Support und Entitlement-JSON.
- [x] **22 · SKU-/Plan-Mapping (Transferwerkzeug):** CSV-Import/Export mit Produkt-/Variations-ID, exaktem SKU-Match, Plan/Intervall/Entitlements und Preis aus lokaler WooCommerce-Quelle umgesetzt.
- [ ] Realen Shopkatalog importieren und Preis-/Addon-Zuordnung mit den tatsächlichen WooCommerce-Produkten abnehmen; IDs erfordern Shopzugriff.
- [x] **23 · Gemeinsamer Feature-Gate-Client:** `allows_entitlement()` mit Produktpräfixprüfung, boolean/quota-Werten und deny-by-default in drei Client-PRs vorbereitet: Voucher [#12](https://github.com/hastla007/wp-couponmaster/pull/12), Pass [#22](https://github.com/hastla007/wp-passmaster/pull/22), Affiliate [#11](https://github.com/hastla007/wpaffiliatemaster/pull/11).
- [ ] **24 · Produkt-Gates:** neue Premiumfunktionen in allen drei Clients auf die freigegebenen Entitlement-IDs abbilden; kostenlose/Bestandsfunktionen unangetastet lassen.
- [x] **25 · Offline-Rechtecache:** lokale HMAC-Cachelogik mit 14-Tage-Höchstfrist und Fallback nur bei Netzwerk-/Serverfehlern vorbereitet: Voucher [#13](https://github.com/hastla007/wp-couponmaster/pull/13), Pass [#23](https://github.com/hastla007/wp-passmaster/pull/23), Affiliate [#12](https://github.com/hastla007/wpaffiliatemaster/pull/12). Manipulation/Ablauf und Produktverhalten müssen noch getestet werden.
- [x] **26 · Stripe-Webhooks:** Stripe-Signatur (300 s), minimale Event-Inbox, eindeutige Event-IDs und Konflikterkennung vorbereitet; Stripe-Sandbox-Abnahme offen.
- [x] **27 · Renewal-Abgleich (Code):** signierte Erfolgsereignisse werden idempotent gegen Renewal, Order, Lizenz, Betrag und Währung geprüft; manuelle Sandbox-/Timeout-Abnahme offen.
- [x] **28 · Auth-Recovery (Code):** Mein-Konto-Link zum offenen Renewal-Auftrag plus asynchrone Zahlungsabstimmung vorbereitet; SCA-/Gateway-Sandbox-Abnahme offen.
- [x] **29 · PayPal-Go/No-Go (v1-Entscheidung):** keine PayPal-Renewals ohne registrierten Adapter; Checkout lehnt nicht unterstützte Renewal-Gateways ab. Merchant-/Sandbox-Prüfung ist erst vor einer späteren PayPal-Freigabe nötig.
- [x] **30 · Release-Manifest:** CLI `tools/create-release-manifest.php` erzeugt Metadaten mit HTTPS-Paket-URL, Version, Mindestversionen, Changelog und SHA-256. Release-Build-/Storage-Integration bleibt offen.
- [x] **31 · Paketablage (Provider-Hook):** kurzlebige HTTPS-URL-Auflösung nach Verbrauch des Einmalgrants sowie Host-Allowlist dokumentiert/implementiert.
- [ ] Konkreten privaten S3/GCS/anderen Storage-Provider, Bucket-Policy, Credentials und Retention konfigurieren und abnehmen.
- [x] **32 · Release-Rollback (Runbook):** unveränderliche Versionspakete, Vorab-Backup und manueller Rollbackpfad dokumentiert.
- [ ] Rollback in den Release-Build-/Storageprozess integrieren und mit einem realen Paket testen.
- [x] **33 · Lizenzverwaltung:** vorhandene Suche/Paginierung um Statusfilter ergänzt und Kundennummer in die Suche aufgenommen. Bestell-/Renewal-spezifische Filter und Skalierungsprüfung bleiben offen.
- [x] **34 · Supportaktionen (Aktivierungsfreigabe):** capability-/nonce-geschützte Freigabe mit Pflichtbegründung und Auditlog ergänzt; Domainänderung, Lizenzschlüssel-Wiederherstellung und Laufzeitabnahme bleiben offen.
- [x] **35 · Testbasis:** PHPUnit-Konfiguration, PHP-Matrix-Workflow und isolierte LicenseKey-Unit-Tests ergänzt. Tests/CI-Ausführung sowie Signatur- und Entitlement-Testfälle noch offen.
- [x] Isolierte Stripe-Signaturtests für gültige HMACs, ungültige Signaturen und abgelaufene Zeitstempel ergänzt.
- [ ] **36 · API-Sicherheitstests:** REST-/WordPress-Tests für Nonce-Replay, Rate-Limits, Antwortcodes und Einmal-Downloadtoken ergänzen.
- [x] Isolierte PHPUnit-Tests für Monats-/Jahresperioden und Monatsende/Schaltjahr ergänzt.
- [ ] **37 · Datenbank-/Renewaltests:** WordPress-/MySQL-Integrationstests für Schema-Upgrades, doppelte Bestellungen, Refunds, Renewal-Locks und Grace Period ergänzen.
- [x] Bekannte PHP-/WordPress-Minima und deklarierte WooCommerce-Minima der Produkt-Repositories in `docs/COMPATIBILITY.md` erfasst.
- [ ] **38 · Kompatibilitätsmatrix:** fehlende WooCommerce-Minima für Voucher/Affiliate und Serverminimum festlegen; reale minimale/aktuelle Versionen in WP/Woo-CI testen.
- [x] Aggregierte Queue-/Renewal-Metriken, tägliche Alert-Hooks und konfigurierbare Schwellen ergänzt.
- [ ] **39 · Betriebsüberwachung:** produktive Alert-Empfänger und Infrastruktur-Monitoring für API/Paket-Host konfigurieren und verifizieren.
- [x] **40 · Launch-Evidenz:** Registervorlage mit Gates, Owner-, Datums-, Ergebnis- und Evidenzfeldern in `docs/LAUNCH-EVIDENCE.md` erstellt.
- [ ] Launch-Evidenz tatsächlich ausfüllen: Restore-, Gateway-, Sicherheits-, Datenschutz-, Kündigungs- und Supportabnahmen durchführen und freigeben.

**Umsetzungsstand dieses Updates:** Code/Dokumentation für Aufgaben 21, 23, 25–28, 30, 33–35 liegt in offenen PRs; 29 ist für v1 fail-closed entschieden, 31 hat den Provider-Hook, 32 den manuellen Runbookpfad. Für 22 fehlen echte Shopdaten; 24 braucht die freizugebende Gate-Liste; 31 konkrete Storage-Einrichtung; 36–39 benötigen weitere Tests/Monitoring; für 40 ist die Registervorlage angelegt, Nachweise fehlen. Alle PRs müssen noch reviewt/gemergt werden; Tests und Sandboxen sind nicht ausgeführt.


## Ergänzende technische Restliste bis zur technischen V1-Reife (Aufgaben 41–60)

Diese Punkte ergänzen die bisherige Liste um konkrete Lücken, die beim Abgleich von API, Abrechnung, Clients, Updates und Testabdeckung sichtbar bleiben. Der Status wird pro Teilaufgabe geführt. Ein Haken bedeutet, dass der jeweilige Code-/Dokumentationsumfang erstellt wurde; Integration, CI und produktive Abnahme stehen separat.

### API, Sicherheit und Identität

- [x] **41a · OpenAPI-Vertrag:** OpenAPI 3.0.3-Spezifikation für die sechs v1-Routen unter `docs/openapi-v1.json` erstellt; PHPUnit prüft JSON, Routen/Methoden, Signaturumschläge und Aktivierungs-Pflichtfelder (`tests/Contract/ApiContractTest.php`).
- [x] **41b · Laufzeit-Konformitätstest (statisch):** PHPUnit vergleicht die deklarierten OpenAPI-Routen und Methoden mit dem erwarteten v1-Inventar und prüft Pflichtfelder/Signaturtypen.
- [ ] **41c · REST-Laufzeitkonformität:** REST-Integrationstests gegen echte WordPress-Routen ergänzen und Antworten/Fehlercodes mit der Spezifikation sowie gemeinsamen Client-Fixtures vergleichen. **Abnahme:** inkompatible API-Änderungen schlagen in CI fehl.
- [x] **42a · Installation-secret key ring:** `SecretCrypto` schreibt `v1:<key-id>`-AES-256-GCM-Umschläge, liest versioniert den passenden Schlüssel und unterstützt unversionierte Altwerte über configured keys/Legacy-Salt. Dokumentation und Tests für aktuellen sowie vorherigen Key-ID sind ergänzt.
- [x] **42e · Lazy-Re-Encryption bei authentifizierter Nutzung:** Nach erfolgreicher HMAC-Prüfung und Rate-Limit-Zulassung werden Alt-/Vorversion-Umschläge mit Compare-and-set auf den aktuellen Key umgeschrieben; Fehler blockieren die gültige Anfrage nicht. Unit-Tests prüfen aktuelle, vorherige und unversionierte Umschläge. Batchmigration, Audit/Backup/Restore und WP/MySQL-Abnahme bleiben offen.
- [x] **42b · Rotationswerkzeug implementiert:** `wp wplc rotate-installation-secrets` prüft Bestandszeilen in begrenzten Batches; Dry-Run ist Standard, `--apply` schreibt per Compare-and-set und protokolliert erfolgreiche Änderungen ohne Geheimnisse.
- [ ] **42f · Rotation im Bestand abnehmen:** WP/MySQL-Prüflauf, verifiziertes Backup/Restore, Rückfallpfad und Test in einer Kopie realer Bestandsdaten durchführen. Alte Schlüssel erst danach entfernen.
- [x] **42c · Lizenzschlüssel-Key-ID:** Schema v8 speichert `license_key_secret_id`; neue Schlüssel werden mit aktueller Key-ID abgeleitet und das Kundenportal nutzt die gespeicherte Version. Tests decken aktuelle/vorherige IDs und unbekannte IDs ab.
- [ ] **42d · Bestandsmigration und Recovery:** vorhandene Zeilen sicher auf bestätigte Legacy-Key-ID abgleichen; Recovery-/Rotationsworkflow mit Audit und Rückfallpfad umsetzen. **Abnahme:** Rotations- und Wiederherstellungstest ohne ungültig werdende Bestandslizenzen.
- [x] **43a · Atomare Sicherheitszähler:** Schema v7 ergänzt persistente Nonce- und Rate-Limit-Tabellen; HMAC-validierte Nonces werden per eindeutigem Schlüssel atomar konsumiert, Minutenlimits per Upsert gezählt, abgelaufene Datensätze stündlich bereinigt. Aktivierungs-IP wird vor der Verwendung validiert.
- [x] **43b · Atomarer Zähler-Unit-Test:** PHPUnit prüft den `INSERT ... ON DUPLICATE KEY UPDATE`-Minutenbucket und den zurückgelesenen Zählerstand (`tests/Api/AuthenticatorRateLimitTest.php`).
- [ ] **43c · Sicherheitsintegrationstests:** parallele Replay-/Flood-Tests, DB-Fehler/Fallback, Proxy-Header und Bereinigungsgrenzen in WordPress/MySQL prüfen. **Abnahme:** Limits sind unter Konkurrenz nicht umgehbar und Datensätze wachsen nicht unbegrenzt.
- [x] **44a · Aktivierungs-URL kanonisieren:** eigenständige Routine `SiteUrl::normalize()` in Aktivierung verdrahtet; Scheme/Host normalisiert, Default-Port und abschließende Pfad-Slashes entfernt, IPv6 geklammert, Credentials/Query/Fragment/fremde Schemes, ungültige Ports und Backslashes im Pfad abgewiesen. IDN wird bei verfügbarer Intl-Erweiterung nach ASCII normalisiert; andernfalls sicher abgewiesen.
- [x] **44b · SiteUrl-Unit-Tests:** Host-/Scheme-/Portnormalisierung, IPv6 und Zurückweisung von Credentials, Query, Fragment und Fremdscheme in `tests/Api/SiteUrlTest.php` abgedeckt.
- [ ] **44c · Identitätsintegration:** gleiche Normalisierung bei Domainwechsel, Kundenkonto/Admin und Staging-Zuordnung durch WordPress-Integrationstests abnehmen; IDN- und lokale Entwicklungsregeln ausdrücklich festlegen.
- [x] **45a · Transition-Matrix implementiert:** `LicenseStateMachine` definiert erlaubte Aktionen/Übergänge; Lifecycle und Renewal blockieren ungültige Änderungen und automatische Wiederaktivierung gesperrter, widerrufener oder abgelaufener Lizenzen.
- [x] **45b · Unit-Tests:** erlaubte/abgewiesene Übergänge und Renewal-Freigabe sind in `tests/License/LicenseStateMachineTest.php` abgedeckt.
- [x] **45c · Compare-and-set umgesetzt:** Statuswechsel, Renewal-Erfolg und Kündigung schreiben nur, wenn der vorher gelesene Status unverändert ist; ein paralleler Refund/Chargeback kann so nicht durch ein veraltetes Update überschrieben werden.
- [ ] **45d · Persistenz-/Konkurrenztests:** parallele Refund-, Renewal- und Chargeback-Events sowie DB-Fehler auf Status-/Auditkonsistenz prüfen.
- [ ] **46 · Kunden- und Admin-Autorisierung testen:** Besitzprüfung bei Mein-Konto-Lizenz, Aktivierung, Kündigung, Zahlungslink und Download gegen fremde Kunden-IDs prüfen; Adminaktionen auf Capability, Nonce, Eingabevalidierung und Audit-Eintrag absichern. **Abnahme:** negative IDOR-/CSRF-Tests schlagen für alle schreibenden Endpunkte fehl.

### WooCommerce-Aufträge und Abrechnung

- [ ] **47 · HPOS- und Bestellereignisse als Integrationstests:** WooCommerce CRUD statt direkter Postmeta-Annahmen für Bestellung, Position, Refund und Kundenzuordnung testen; HPOS an/aus, Gastbestellung, Variation, Menge, Teil-/Vollrefund und wiederholte Hooks abdecken. **Abnahme:** identisches Lizenzresultat unter HPOS und klassischer Speicherung.
- [ ] **48 · Checkout- und Gateway-Grenzen festlegen:** Classic Checkout, Checkout Blocks, Gast-Checkout und unterstützte Gatewayversionen als V1-Vertrag festlegen; nur zugesagte Wege integrieren und für nicht unterstützte Abläufe klare Checkout-Fehler ausgeben. **Abnahme:** jede beworbene Kaufstrecke ist E2E getestet; keine Renewal-Zustimmung wird stillschweigend vorausgewählt.
- [ ] **49 · Stripe-Webhook-Reihenfolge und Wiederholung absichern:** Eventtypen allowlisten, wiederholte und verspätete Events, ungültige Objektverknüpfungen, Inbox-Retry, Dead-Letter-/Manual-Review-Fälle und konkurrierende Verarbeitung testen. **Abnahme:** kein Event kann ohne erneute Order-/Renewal-/Betrags-/Währungsprüfung Rechte verlängern.
- [ ] **50 · Stripe-Tokenvertrag mit Gatewayversion testen:** Tokenbesitz, Kundenzuordnung, Zustimmung, Widerruf, abgelaufener Token, Timeout, Idempotency-Key und Gatewayfehler über den offiziellen WooCommerce-Stripe-Adapter mit Test-Doubles und Sandboxfällen prüfen. **Abnahme:** keine Zahlung ohne gültigen kundeneigenen Token und gespeicherte Zustimmung; keine Kartendaten im WPLC-Speicher.
- [ ] **51 · 3DS- und Zahlungswiederherstellung vervollständigen:** Authentifizierungsbedarf, abgebrochener Flow, Rückkehr ins Kundenkonto, erneuter Versuch, bereits bezahlter Auftrag und doppelte Rückmeldung behandeln. **Abnahme:** Status bleibt bis zur verifizierten Zahlung konsistent und der Kunde erhält genau einen sicheren Wiederaufnahmeweg.
- [x] **52a · WooCommerce-Auftrag wiederfinden:** Renewal-Prozessor sucht vor Erstellung per `wc_get_orders()` HPOS-kompatibel nach bereits angelegten Aufträgen mit `_wplc_renewal_id`, prüft Renewal/Lizenz/Kunde/Gateway und verknüpft genau einen passenden Auftrag wieder. Bei Mehrdeutigkeit oder Lookup-/Linkfehler wird fail-closed abgebrochen.
- [ ] **52b · Crash-Recovery integrieren und testen:** Timeout nach erfolgreicher Zahlung, Abbruch zwischen Gateway-Aufruf, `payment_complete`, Renewal-Status, Lizenzlaufzeit und Scheduler-Requeue mit WooCommerce-/Gateway-Tests abdecken. **Abnahme:** Recovery erzeugt weder Doppelabbuchung noch verlorene Verlängerung.
- [ ] **53 · Retry-/Kulanzjobs gegen Race Conditions prüfen:** Action-Scheduler-Duplikate, verspätete Jobs, Kündigung während eines Laufs, Refund während Retry und Wiederaktivierung während Kulanz abdecken. **Abnahme:** jeder fällige Versuch wird höchstens einmal ausgeführt und Rechte folgen exakt den festgelegten Fristen.
- [x] **54a · Präzision pro Währung speichern:** Schema v9 speichert Preisdezimalstellen im Tarif und bei der Lizenz; Renewals verwenden den beim Kauf gespeicherten Präzisionswert.
- [x] **54b · Exakte Betragskonvertierung:** `Money` konvertiert WooCommerce-Dezimalpreise mit half-up-Rundung stringbasiert in Minor Units und zurück ohne binäre Float-Arithmetik; Unit-Tests decken 0/2/3/6 Dezimalstellen und Rundungsgrenzen ab.
- [ ] **54c · Add-on-Proration und Steuern:** anteilige Add-on-Berechnung, gemeinsame Verlängerung, Entfernen/Erstatten, WooCommerce-Steuer-/Währungssonderfälle und Monatsende-/Jahreswechseltests vollständig implementieren. **Abnahme:** Rechnungssumme, Renewal-Termin und Entitlements stimmen für dokumentierte Grenzfälle.

### Produktclients, Updates und Datenhaltung

- [ ] **55 · Feature-Gates und Offlinecache in Produktclients abnehmen:** pro Produkt nur freigegebene neue Premiumfunktionen an Entitlement-IDs binden; Cache-Signatur, Manipulation, Ablauf nach 14 Tagen, Server-5xx, Lizenzwiderruf und bestehende Core-Funktionen testen. **Abnahme:** alle drei Plugins bestehen dieselben Client-Vertragsfixtures auf ihren jeweiligen PHP-Minima.
- [ ] **56 · WordPress-Updater Ende-zu-Ende testen:** Plugin-Slug/Update-Slug, Multisite-Netzwerkaktivierung, WordPress-Transient, Downloadfehler, abgelaufener Grant, Prüfsummenabweichung, nicht unterstützte PHP-Version und fehlgeschlagenes Update/Rollback testen. **Abnahme:** fehlerhafte oder unautorisierte ZIPs werden nie installiert; Shop und Plugin bleiben nach Updatefehler nutzbar.
- [ ] **57 · Releasepakete reproduzierbar und unveränderlich bauen:** CI-Paketbau, Versionsmanifest, SHA-256, Signaturprüfung mit festem Client-Public-Key, private Ablage, unveränderliche Versionen und kontrollierte Löschung integrieren. **Abnahme:** ein reproduzierbarer Release kann nachweisbar veröffentlicht, autorisiert heruntergeladen und bei Fehlern durch ein vorheriges Paket ersetzt werden.
- [ ] **58 · Einmal-Downloadgrants unter Parallelität prüfen:** gleichzeitige Requests, Replay, Lizenzwiderruf zwischen Metadatenabfrage und Download, URL-Ablauf, Hostwechsel und Storage-Ausfall testen. **Abnahme:** ein Grant ist genau einmal verwendbar und ein widerrufener Anspruch erhält kein Paket.
- [ ] **59 · Migrationen und Datenintegrität automatisiert prüfen:** leere Installation, Upgrade von jeder unterstützten Schema-Version, wiederholte Migration, Teilfehler, große Tabellen, Indizes und Wiederherstellung aus Backup abdecken. **Abnahme:** Migrationen sind idempotent; bei Fehlern bleiben Lizenz- und Renewal-Daten konsistent.
- [ ] **60 · Last-, Fehler- und Datenschutztests für V1:** repräsentative Mengen an Lizenzen/Aktivierungen/Events messen; Pagination, Abfragepläne, API-Timeouts, Protokollredaktion, Datenaufbewahrung und WordPress Export-/Erase-Hooks prüfen. **Abnahme:** definierte Antwortzeit-/Datenmengen-Ziele werden erreicht; Geheimnisse und nicht erforderliche personenbezogene Daten verlassen weder Logs noch Exportgrenzen.

### Zusätzlicher Sicherheitsfund: signierte Requests (Aufgabe 61)

- [x] **61a · Eingehende HMAC-Prüfung:** Authenticator prüft jetzt X-WPLC-Signature in konstanter Zeit gegen den dokumentierten kanonischen String aus HTTP-Methode, REST-Route, Timestamp, Nonce und SHA-256 des Raw Bodys; ungültige Formate und Signaturen werden vor Replay-/Rate-Limit-Verbrauch abgewiesen.
- [x] **61b · Testvektoren:** PHPUnit prüft einen festen HMAC-Testvektor sowie Bindung an Methode, Route und unveränderten Body (tests/Api/ApiRequestSignatureTest.php).
- [ ] **61c · REST-Endpunkt-Integration:** Positive/negative Signaturen über echte WP_REST_Request-Routen und alle geschützten Endpunkte integrieren; Client-SDK-Fixtures dagegen ausführen.
- [ ] **61d · Sicherheitsreview:** Authentifizierungsfluss, Failure-Modes, Replay-Eintrag und Rotation in WP/MySQL mit parallelen Requests prüfen.
- [x] **61e · Fehlerreferenzen:** Doppelt escapte globale WP_Error-Referenzen im Authenticator normalisiert, damit Rückgabepfade gültige PHP-Namespaces verwenden.

### Technische V1-Abnahme

- [ ] Eine CI-Pipeline führt Unit-, API-, WooCommerce-/HPOS-, Datenbank-, Clientvertrag- und Updatepakettests reproduzierbar aus.
- [ ] Die komplette Kauf-zu-Update-Kette wird mit Testshop und Testgateway dokumentiert durchgespielt: Kauf → Lizenz → Aktivierung → Entitlement → Verlängerung/Refund → Update → Deaktivierung.
- [ ] Für jedes negative Szenario ist das erwartete Verhalten dokumentiert: Netzwerkfehler, Gateway-Timeout, doppeltes Event, abgelaufene Lizenz, manipuliertes Cacheobjekt, fehlendes Paket und fehlgeschlagene Migration.
- [ ] Die V1-Kompatibilitätsmatrix ist in CI auf Mindest- und aktuellen unterstützten WordPress-, WooCommerce- und PHP-Versionen erfolgreich.
- [ ] Sicherheitsreview mit Findings, Schweregrad, Fix-Commit und erneutem Test ist abgelegt.

**Zielwert:** Wenn Aufgaben 21–60 und die technischen Abnahmen umgesetzt und bestanden sind, ist eine technische V1-Reife von ungefähr 90–95 % plausibel. Die restlichen Prozent entfallen auf reale Shop-/Gateway-/Storage-Konfiguration, Betriebsabnahme und externe Datenschutz-/Rechtsfreigaben; sie lassen sich nicht allein durch Programmiercode erledigen.


## Arbeitsfortschritt zur Restliste

- [x] **41a** OpenAPI-v1-Datei und strukturelle PHPUnit-Vertragsprüfung hinzugefügt.
- [x] **43a** Replay-/Rate-Limits auf persistente atomare Datenbankzähler umgestellt; Schema auf Version 7 erweitert und Bereinigung geplant.
- [ ] **41b / 43b** REST- und Datenbankintegrationstestläufe sind noch offen; die neue Änderung ist nicht als CI-Ergebnis bestätigt.

- [x] **44a** Aktivierungs-URL-Normalisierung und isolierte Unit-Tests ergänzt.

- [x] **43b** PHPUnit prüft, dass die Rate-Limit-Zählung als atomarer Datenbank-Upsert formuliert ist.
- [x] **44a** URL-Normalisierungs-Unit-Tests ergänzt; Live-Integrationsfälle bleiben offen.

- [x] **42a** Versioniertes, konfigurierbares Installation-Secret-Keyring-Verfahren, Legacy-Leseweg, Tests und Betriebsdokumentation ergänzt.
- [x] **42b/42e** Batch-Rotationswerkzeug und Lazy-Re-Encryption für Aktivierungsgeheimnisse ergänzt.
- [ ] **42d/42f** License-Key-Recovery sowie WP/MySQL-Backup-/Restore- und Rotationsabnahme bleiben offen.

- [x] **45a/45b** Explizite Statusübergänge in den Lifecycle eingebunden und mit Unit-Tests abgedeckt.

- [x] **42c** Key-IDs für neu ausgestellte und im Kundenportal angezeigte Lizenzen gespeichert; Versionsauswahl durch PHPUnit abgedeckt.
- [ ] **42b/42d** Re-Encryption der Aktivierungsgeheimnisse sowie Bestands-/Recovery-Migration für License Keys bleiben offen.

- [x] **52a** HPOS-kompatible Wiedererkennung und Validierung verwaister Renewal-Aufträge implementiert.
- [ ] **52b** Recovery-Tests und Stripe-Sandbox-Abnahme bleiben offen.

- [x] **54a/54b** Kurs-/Betragspräzision im Katalog und Renewal festgehalten; exakte Konvertierung und Rundung getestet.
- [ ] **54c** Add-on-Proration sowie Steuer-/Gatewayintegration bleiben offen.

- [x] **45c** Compare-and-set-Schutz für Lizenzzustandswechsel, Renewal-Erfolg und Kündigung eingebaut.
- [ ] **45d** Konkurrenz-/Auditintegrationstests bleiben offen.

- [x] **61a/61b/61e** Eingehende Signaturprüfung, fixer HMAC-Testvektor, request-binding Tests und PHP-Fehlerreferenzen im Authenticator ergänzt; echte REST-/MySQL-Integration bleibt offen.
- [x] **42e** Lazy-Re-Encryption für erfolgreich authentifizierte Nutzungen ergänzt; vollständige Batch-Rotation, Restoretest und Datenbankabnahme bleiben offen.
- [x] **42b** WP-CLI-Batchrotation mit Dry-Run-Standard, Apply-Flag, Batch-/Resume-Optionen, Compare-and-set und Audit-Ereignissen ergänzt und in API-Betriebsdokumentation beschrieben.
- [ ] **42f** Die Batchrotation ist nicht gegen eine echte WP/MySQL-Datenbank, Backup-/Restore-Kopie oder Produktionsschlüssel geprüft.
