# WP License Captain — Programmierplan

**Repository:** `hastla007/wp-license`  
**Stand:** 13.11.2026  
**Offene Aufgaben:** ausschließlich in [TODO.md](TODO.md). Dieses Dokument enthält nur noch Produktregeln und den technischen Zielentwurf.

## 1. Ziel und Ausgangslage

Dieses Repository wird zum WooCommerce-Plugin, das als Lizenzserver für WP Affiliate Captain, Voucher Captain und WP Pass Captain dient. Die API erhält von Beginn an eine Versionierung, damit sie später aus dem Shop-Plugin herausgelöst werden kann.

Die früheren 37 gestapelten `codex/*`-Branches sind in einem Integrationsstand zusammengeführt. Die alten Checklisten (PR01–12, Phasen 13–40, Aufgaben 41–61) wurden in [TODO.md](TODO.md) überführt; ihr Verlauf steht in der Git-Historie.

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

## 2. Technischer Zielentwurf

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
