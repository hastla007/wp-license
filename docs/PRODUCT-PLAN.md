# Produktplan: der Lizenzserver als verkaufbares Produkt

Stand: 16.11.2026. Grundlage sind die Entscheidungen des Inhabers vom selben Tag (E8, E8-Zeitpunkt und G2; `docs/TODO.md`, Abschnitt 14): Der Lizenzserver wird selbst an andere Hersteller von WordPress-Plugins verkauft, der Umbau dazu geschieht vor dem Verkaufsstart der Captain-Add-ons, und die Bezeichnungen der Funktionen liefern die Add-ons, nicht der Server.

Dieses Dokument beschreibt, was dafür zu tun ist. Es ist ein Plan, keine Zusage: Größe und Reihenfolge sind Schätzungen aus dem Code vom 16.11.2026. Die Arbeitspakete stehen als Aufgaben PROD-01 bis PROD-13 in `docs/TODO.md`, Abschnitt 14.

## 1. Ausgangslage

- Der Lizenzserver ist ein WordPress-Plugin für einen WooCommerce-Shop. Eine Installation bedient genau einen Hersteller; mehrere Hersteller in einer Installation sind nicht vorgesehen und werden hier nicht geplant.
- **Erster Mandant** des herstellerneutralen Servers ist die Captain Suite mit sieben Produkten: Voucher Captain, Pass Captain und Affiliate Captain (Add-ons vorhanden) sowie Form Captain, Review Captain, Course Captain und Workflow Captain (Konzeptstand, je ein eigenes Repository). Alles, was nur für diese sieben gilt, ist Daten des Mandanten und gehört nicht in den Code des Servers. Dazu gehören auch die Preise: Bestätigt sind am 16.11.2026 nur die von Form Captain Pro (59, 129 und 249 € brutto im Jahr für 1, 5 und 25 Websites, Tarife `single`/`business`/`agency`); die Preise aller anderen Produkte, auch die des Servers selbst, sind nicht bestätigt.
- Bis zum 16.11.2026 standen Produktschlüssel, Rechte, Bezeichnungen und deren deutsche Übersetzungen fest im Code (`src/Catalog/EntitlementCatalog.php`) und in der Textdomain des Servers. Seit PROD-01 kommen sie aus Katalogdateien.
- Die Client-Bibliothek liegt in `src/Client` (Version 2.1.0). Jedes Add-on liefert eine eigene Kopie unter eigenem Namensraum mit (`bin/export-client.php`); ein Beispiel-Add-on liegt unter `examples/premium-addon-starter`.
- Der Server hat keinen eigenen Updateweg als Produkt: `src/Infrastructure/SelfUpdateCheck.php` (A-42) vergleicht nur die installierte Version mit dem neuesten Release auf GitHub und meldet das auf der Diagnoseseite. Installiert wird von Hand. Eine Lizenz für den Server selbst gibt es nicht.
- Texte, Platzhalter, Beispiele und Dokumente nennen an vielen Stellen Captain-Produkte: 39 Dateien unter `src/`, `templates/` und `assets/` enthalten das Wort „Captain“ (der größte Teil davon ist der eigene Produktname „WP License Captain“), dazu `README.md` und fast alle Dokumente unter `docs/`.

## 2. Was „Server als Produkt“ verlangt

1. **Keine Daten eines Herstellers im Code.** Produkte, Rechte, Bezeichnungen und Übersetzungen kommen aus Katalogdateien, die der Hersteller liefert (PROD-01 bis PROD-03).
2. **Herstellerneutrale Oberfläche und Dokumentation.** Kein Text, kein Platzhalter und kein Beispiel setzt Captain-Produkte voraus (PROD-04, PROD-05, PROD-10).
3. **Ein eigener Updateweg und eine eigene Lizenz für den Server** (PROD-06, PROD-07).
4. **Ein Client, den fremde Hersteller ohne dieses Repository einbauen können** (PROD-08).
5. **Ein Einstieg für einen neuen Hersteller**: erstes Produkt, erster Tarif, erstes Release, Testlizenz (PROD-09).
6. **Eine saubere Grenze**, was Captain-spezifisch bleibt und wohin es wandert (PROD-11).
7. **Eine Migration ohne Datenverlust** für die bestehende Installation (PROD-12).
8. **Ein Release des Servers als Produkt** (PROD-13).

## 3. Arbeitspakete

Größe: **S** bis etwa ein Arbeitstag, **M** einige Tage, **L** ein bis zwei Wochen; jeweils ohne die Zeit für Entscheidungen des Inhabers. „Blockiert“ sagt, was ohne das Paket nicht beginnen kann: **Add-ons** = der erste Verkauf der Captain-Add-ons, **Server** = der Verkauf des Servers an Dritte.

| Nr. | Paket | Größe | Hängt ab von | Blockiert | Stand |
| --- | --- | --- | --- | --- | --- |
| PROD-01 | Katalog als Daten | M | – | Add-ons (siehe 4) | erledigt am 16.11.2026 |
| PROD-02 | Captain-Katalog kommt aus captain-pro | S | PROD-01, captain-pro | Add-ons (siehe 4) | offen |
| PROD-03 | Katalogdatei mit dem Release einspielen | M | PROD-01 | Server | offen |
| PROD-04 | Herstellerneutrale Texte, Platzhalter und Beispiele | M | PROD-01 | Server | offen |
| PROD-05 | Name, Marke und Kennungen des Produkts | S | Entscheidung | Server | offen |
| PROD-06 | Eigener Updateweg des Servers | L | PROD-07, PROD-13 | Server | offen |
| PROD-07 | Lizenzmodell des Servers | S | Entscheidung | Server | offen |
| PROD-08 | Eigenständiger Client für andere Hersteller | M | Entscheidung | Server | offen |
| PROD-09 | Einstieg für einen neuen Hersteller | M | PROD-01, PROD-04 | Server | offen |
| PROD-10 | Dokumentation für Hersteller | M | PROD-04, PROD-08, PROD-09 | Server | offen |
| PROD-11 | Captain-spezifisches auslagern | M | PROD-02, PROD-04 | Server | offen |
| PROD-12 | Migration der bestehenden Installation | S | PROD-01, PROD-02 | Add-ons (siehe 4) | Teil 1 erledigt |
| PROD-13 | Release des Servers als Produkt | S | PROD-05 | Server | offen |

### PROD-01 · Katalog als Daten (erledigt)

- **Vorher:** `EntitlementCatalog::all()`, `planned()`, `PRODUCT_KEYS`, `PLANNED_PRODUCT_KEYS` und `group_label()` waren feste Listen; die Bezeichnungen standen als `__()`-Texte in der Textdomain des Servers.
- **Jetzt:** Ein Katalogspeicher (`WPLC\Catalog\CatalogStore`, eine nicht automatisch geladene Option) hält je Produkt: Produktschlüssel, Präfix der Rechte, Name, Status (verfügbar oder vorgemerkt) und die Rechte mit Bezeichnung und Beschreibung je Sprache und eigenem Status. Gefüllt wird er durch den Import einer geprüften Katalogdatei (`wplc-catalog.json`, Format in [CATALOG-FILE.md](CATALOG-FILE.md), Schema in [catalog-file.schema.json](catalog-file.schema.json)): im Admin unter „Lizenzkatalog: Dateien“ (Berechtigung `wplc_manage_catalog`), mit `wp wplc catalog import` und beim Upgrade durch einen Migrationsschritt, der die mitgelieferten Dateien unter `catalogs/` einspielt. Jeder Import wird im Audit-Protokoll vermerkt; derselbe Import ein zweites Mal ändert nichts.
- **Warum eine Option und keine Tabellen:** Der Katalog ist klein, wird immer als Ganzes gelesen, ein Import ersetzt ein Produkt auf einmal, und nichts verknüpft sich mit ihm (Tarife und Lizenzen speichern Rechte als JSON). Tabellen brächten Schemaschritte, Deinstallation und Multisite-Behandlung ohne eine Abfrage, die sie braucht.
- **Gleiches Verhalten wie vorher:** Vorgemerkte Rechte und vorgemerkte Produkte werden im Produkteditor nicht angeboten (Filter `wplc_offer_planned_entitlements`); die Filter `wplc_entitlement_catalog` und `wplc_known_product_keys` bleiben; ein gespeicherter Tarif behält Schlüssel, die der Katalog nicht (mehr) kennt, im JSON-Feld.

### PROD-02 · Captain-Katalog kommt aus captain-pro

- Der Build von captain-pro erzeugt je Add-on eine `wplc-catalog.json` im Wurzelordner des Add-ons (Format wie in [CATALOG-FILE.md](CATALOG-FILE.md); der Agent von captain-pro arbeitet parallel daran) und prüft sie gegen das Schema.
- Im Shop wird die Datei mit jedem Release eines Add-ons eingespielt (bis PROD-03 von Hand oder mit `wp wplc catalog import`).
- Danach entfallen der Ordner `catalogs/` in diesem Repository und der automatische Import der mitgelieferten Dateien für neue Installationen. Eine bestehende Installation behält ihren Katalog (PROD-12).
- Erledigt, wenn: captain-pro die sieben Dateien liefert (drei Add-ons verfügbar, vier vorgemerkt), ein Test in captain-pro sie gegen das Schema prüft, der Shop sie eingespielt hat und `catalogs/` hier gelöscht ist.

### PROD-03 · Katalogdatei mit dem Release einspielen

- Beim Anlegen eines Release aus einer ZIP-Datei (`ReleaseAdmin`, `wp wplc release create`) liest der Server eine `wplc-catalog.json` aus dem Wurzelordner des Pakets, zeigt die Unterschiede zum gespeicherten Katalog und spielt sie mit dem Veröffentlichen des Release ein. Ein Recht wird so genau dann „verfügbar“, wenn die Version erscheint, die es prüft.
- Zu klären beim Bau: Verhalten bei einem zurückgezogenen Release (der Katalog bleibt, weil verkaufte Tarife die Rechte tragen) und bei Paketen, die nur per URL registriert sind (kein Zugriff auf den Inhalt: Import bleibt von Hand).

### PROD-04 · Herstellerneutrale Texte, Platzhalter und Beispiele

- Texte und Platzhalter im Code, die Captain-Produkte nennen: `ProductEditor.php` (Platzhalter `voucher-captain-pro`, Hilfetext mit den drei Add-on-Schlüsseln, Beispiel `affiliate.program_limit`), `Settings.php` (Einstiegstext mit `voucher-captain-pro`), Hilfetexte der CLI-Befehle (`ReleaseCommand`, `SyncEntitlementsCommand`, `SigningKeyCommand`), Kommentare.
- Übersetzungen: Die Bezeichnungen der Captain-Funktionen sind mit PROD-01 aus `languages/` entfernt. Übrig bleiben die genannten Hilfetexte.
- Der Produkteditor ordnet eine Rechtegruppe einem Produktschlüssel heute zusätzlich über eine Teilzeichenkette zu (`wplc-product-editor.js`, `EntitlementCatalog::groups_for_product_key()`); für fremde Hersteller muss die Zuordnung allein über den Katalog laufen.
- Erledigt, wenn ein Test den Code unter `src/`, `templates/` und `assets/` nach den Namen der Captain-Produkte durchsucht und nichts findet (außer dem eigenen Produktnamen), und wenn die Tests einen zweiten, erfundenen Hersteller mit eigenem Katalog durchspielen.

### PROD-05 · Name, Marke und Kennungen des Produkts

- Heute: Plugin-Name „WP License Captain“, Ordner und Textdomain `wp-license`, Präfix `wplc` (Tabellen, Optionen, Hooks, REST-Namensraum `wplc/v1`), Composer-Name `wplc/wp-license-captain`.
- Der Präfix `wplc` und der REST-Namensraum bleiben in jedem Fall: Ausgelieferte Add-ons und gespeicherte Daten hängen daran.
- Zu entscheiden: Bleibt der Name, und unter welchem Autor erscheint das Produkt (siehe Fragen F1 und F4 der Entscheidungsvorlage)? Danach: Plugin-Kopf, `readme.txt`, Dokumente.

### PROD-06 · Eigener Updateweg des Servers

Die Frage dahinter: Wer lizenziert den Server? Eine Lizenzprüfung des Servers gegen sich selbst wäre ein Zirkel. Drei Wege:

- **a) Keine Lizenzprüfung im Server.** Käufer laden neue Versionen aus ihrem Kundenkonto im Shop des Inhabers und installieren von Hand; `SelfUpdateCheck` meldet nur, dass es eine neue Version gibt. Einfach, kein Zirkel, aber kein Update mit einem Klick und kein technischer Schutz.
- **b) Der Server ist selbst ein lizenziertes Produkt im Shop des Inhabers** (empfohlen). Die verkaufte Kopie enthält die Client-Bibliothek, fest eingestellt auf den Lizenzserver des Inhabers, und holt darüber Updates wie jedes Add-on. Das ist nur für die Installation des Inhabers ein Zirkel; sie läuft ohne Lizenz (Entwicklungsstand aus dem Repository) und nimmt sich selbst von der Prüfung aus. Feste Regel: Die Lizenz des Servers steuert nur Updates und Support, **nie** seine Funktion. Ein Server, dessen eigene Lizenz abgelaufen oder nicht erreichbar ist, prüft und verlängert die Lizenzen seiner Kunden weiter wie bisher; sonst legte ein Ausfall beim Inhaber die Shops aller Käufer lahm.
- **c) Updates über GitHub Releases** wie heute geprüft. Passt nur zu einem kostenlosen oder offen verteilten Server.

Zu bauen bei b: Einbau der eigenen Client-Bibliothek in den Server (Lizenzseite, Updater), Ausnahme für die Installation des Herstellers selbst, öffentlicher Schlüssel und Adresse des Lizenzservers des Inhabers im Build (dieselbe Abhängigkeit wie B-15), `SelfUpdateCheck` ersetzen oder daneben behalten, Tests für „Lizenz weg, Funktion bleibt“. Braucht PROD-07 (Lizenzmodell) und PROD-13 (Release).

### PROD-07 · Lizenzmodell des Servers

- Zu entscheiden: Produktschlüssel des Servers, Tarife und Preise, was als „Website“ zählt (ein Shop; die Staging-Regel des Servers gilt auch für ihn), Laufzeit und was nach Ablauf gilt (Empfehlung: keine Updates und kein Support mehr, Funktion bleibt).
- Zu bauen: eine Katalogdatei für den Server selbst, Shop-Produkte und Tarife. Kein neuer Code im Server.

### PROD-08 · Eigenständiger Client für andere Hersteller

- **Was es gibt:** die Bibliothek in `src/Client` mit eigener Versionsnummer, `bin/export-client.php` (kopiert sie unter den Namensraum und die Textdomain eines Add-ons), ein Beispiel-Add-on mit Tests, die Dokumente `CLIENT-INTEGRATION.md` und `API-V1.md`, Vertragstests unter `tests/Contract`.
- **Was fehlt für fremde Hersteller:** (1) eine Lieferform ohne dieses Repository: ein eigenes Paket (Composer-Paket aus einem abgespaltenen Repository oder ein Archiv je Release) mit eigenem Changelog; (2) das Exportskript als dokumentierter Weg, die Bibliothek unter eigenem Namensraum einzubauen, im Paket enthalten; (3) eine Zusage, welche Versionen der Bibliothek zu welcher Serverversion passen (API v1), und ein Vertragstest, den ein Hersteller gegen seinen Server laufen lassen kann; (4) die Texte der Bibliothek zum Übersetzen in der Textdomain des Add-ons (heute über das Exportskript gelöst).
- **Ein eigenes WordPress-Plugin „License Captain Client“ ist nach diesem Plan nicht nötig** und wird nicht gebaut: Die Bibliothek muss in jedem Add-on stecken, damit ein Add-on ohne zweite Installation funktioniert und mehrere Add-ons mit verschiedenen Versionen nebeneinander laufen. Ein gemeinsames Plugin schüfe genau die Abhängigkeit, die das Exportskript vermeidet. Es bleibt eine Frage an den Inhaber, weil `README.md` und die Konzepte der Produkte ein solches Plugin erwähnen.
- Der Add-on-Rahmen von captain-pro (`packages/addon-kernel`) ist Captain-eigen und nicht Teil des verkauften Clients.

### PROD-09 · Einstieg für einen neuen Hersteller

- Geführte Schritte auf einer leeren Installation: Schlüssel in der `wp-config.php` anlegen (heute Hinweise unter „Lizenzeinstellungen“), öffentlichen Schlüssel für das Add-on ausgeben (`wp wplc signing-key`), Katalogdatei einspielen, erstes Lizenzprodukt und ersten Tarif anlegen, erstes Release hochladen, Testkauf und Testaktivierung, Stripe-Webhook.
- Den Einstiegstext unter „Lizenzeinstellungen“ gibt es schon (`Settings.php`, fünf Schritte); er nennt Captain-Schlüssel und kennt die Katalogdatei nicht.
- Erledigt, wenn ein E2E-Ablauf diese Schritte auf einer leeren Installation mit einem erfundenen Hersteller durchläuft.

### PROD-10 · Dokumentation für Hersteller

- Ein Handbuch für Käufer des Servers: Installation, Betrieb, Sicherheit, Katalogdatei, Client-Einbau, Verlängerungen, Stripe, Updates des Servers, Datenschutz-Textbausteine. Vieles steht in `docs/`, aber als Arbeitsstand dieses Projekts, auf Englisch mit deutschen Aufgabenlisten gemischt und mit Verweisen auf Captain-Repositories.
- Aus dem verkauften Paket heraushalten: Aufgabenliste, Audits, Launch-Nachweise, dieses Dokument.

### PROD-11 · Captain-spezifisches auslagern

Captain-spezifisch und kein Teil des Servers sind:

- die sieben Katalogdateien (wandern nach captain-pro, PROD-02);
- das Tarifmodell `single`/`business`/`agency` mit 1/5/25 Websites und die Preise (der Server erzwingt keine Tarifschlüssel; `docs/ENTITLEMENTS.md` beschreibt das Modell als wäre es das des Servers);
- die Abschnitte 11 und 12 der Aufgabenliste (Form, Review, Course, Workflow) und `docs/CLIENT-REPOS.md`, `docs/PRODUCT-COMPATIBILITY.md`;
- Beispielwerte in Tests und E2E-Abläufen (`voucher-captain-pro` als Produktschlüssel der Testprodukte): Sie dürfen bleiben, solange daneben ein neutraler Hersteller geprüft wird (PROD-04);
- die Lizenzereignisse für Workflow Captain (WFC-LIC-04) sind kein Captain-Sonderweg, sondern ein allgemeiner Hook-Vertrag des Servers und bleiben hier.

### PROD-12 · Migration der bestehenden Installation

- **Teil 1 (erledigt mit PROD-01):** Schemaschritt 24 spielt die mitgelieferten Katalogdateien ein. Eine Installation, die von den festen Listen kommt, hat danach dieselben Produkte, Rechte und Bezeichnungen; gespeicherte Tarife und Lizenzen werden nicht angefasst und behalten ihre Schlüssel.
- **Teil 2 (mit PROD-02):** Wenn `catalogs/` entfällt, bleibt der gespeicherte Katalog bestehen. Der Import einer Datei aus captain-pro ersetzt den Eintrag aus der mitgelieferten Datei; ein Test spielt genau diesen Wechsel durch. Die Deinstallation löscht den Katalog mit den übrigen Daten.

### PROD-13 · Release des Servers als Produkt

- `bin/build-zip.sh` und der Workflow `release.yml` bauen heute ein ZIP und laden es zu einem GitHub Release hoch. Für den Verkauf wird das Release des Servers ein Release im Shop des Inhabers (Produktschlüssel aus PROD-07), mit Changelog für Käufer und ohne interne Dokumente.

## 4. Was das für die Reihenfolge kostet

Technisch braucht der erste Verkauf der Captain-Add-ons keines dieser Pakete: Der Server verkaufte und prüfte die drei Add-ons auch mit den festen Listen. Der Inhaber hat den Umbau trotzdem vor den Verkaufsstart gelegt. Daraus folgt:

- **Stufe 1, vor dem Verkaufsstart der Add-ons unvermeidlich**, weil sie das Datenformat festlegt, mit dem die Add-ons ausgeliefert werden: PROD-01 (erledigt), PROD-02 (captain-pro liefert die Dateien) und PROD-12. Wird ein Add-on ohne Katalogdatei verkauft, muss es später nachgerüstet werden; mit Datei ist die Trennung von Anfang an sauber. Aufwand: klein, aber abhängig von captain-pro.
- **Stufe 2, nur für den Verkauf des Servers nötig:** PROD-03 bis PROD-11 und PROD-13, zusammen etwa fünf Pakete der Größe M, ein Paket der Größe L und vier der Größe S, dazu vier Entscheidungen des Inhabers (PROD-05, PROD-06, PROD-07, PROD-08). Legt der Inhaber auch diese Stufe vor den Verkaufsstart der Add-ons, verschiebt sich dieser um die ganze Stufe, obwohl kein Kunde eines Add-ons davon etwas merkt.
- Unabhängig davon bleiben die bekannten Voraussetzungen des ersten Verkaufs: Stripe-Test (T-12), Abnahme im Browser (T-130), Produktivserver (T-90), Hostname (B-15), Preise (T-42).

Die erste Frage an den Inhaber ist deshalb, ob „vor dem Verkaufsstart“ beide Stufen meint oder nur Stufe 1.

## 5. Fragen an den Inhaber

1. **Umfang vor dem Verkaufsstart:** Müssen alle Pakete (auch eigener Updateweg, Client-Paket, Einstieg und Handbuch für fremde Hersteller) fertig sein, bevor die Captain-Add-ons verkauft werden, oder genügt Stufe 1 (Trennung der Captain-Daten vom Server)?
2. **Lizenz des Servers:** Prüft der verkaufte Server eine eigene Lizenz gegen den Lizenzserver des Inhabers (Weg b), oder gibt es keine technische Prüfung (Weg a)? Bei b: Bestätigt der Inhaber die Regel, dass die Lizenz nur Updates und Support steuert und nie die Funktion?
3. **Lizenzmodell und Preis des Servers:** Welche Tarife, was zählt als Website, welcher Produktschlüssel, welche Laufzeit?
4. **Client:** Genügt die Bibliothek als Paket mit Exportskript, oder soll es zusätzlich ein WordPress-Plugin „License Captain Client“ geben? (Empfehlung: nur die Bibliothek.)
5. **Name und Marke:** Bleibt „WP License Captain“ der Name des verkauften Produkts, und unter welchem Autor erscheint es?
6. **GPL:** Der Server steht unter GPL-2.0-or-later. Ein Käufer darf ihn weitergeben. Ist das für den Verkauf so gewollt? (Geschäftliche und rechtliche Frage.)
7. **Leistungsumfang für Käufer:** Welcher Support, welche unterstützten Versionen von WordPress, WooCommerce und PHP, und gilt „automatische Verlängerung nur mit Stripe“ auch als Produktgrenze für Käufer?
8. **Einspielen des Captain-Katalogs:** Wer spielt bei einem neuen Release eines Add-ons die Katalogdatei im Shop ein: von Hand im Admin, ein Befehl im Auslieferungsskript, oder automatisch mit dem Release-Paket (PROD-03)?
9. **Vertrieb:** Nur über den eigenen Shop? Gibt es eine Test- oder Entwicklerlizenz für den Server?
10. **Sprache der Dokumentation für Hersteller:** nur Englisch, oder auch Deutsch?
