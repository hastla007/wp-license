# Stripe-Testmodus: Abnahme Schritt für Schritt (T-12)

Diese Anleitung führt durch alle Stripe-Fälle, die bisher nur mit einem Test-Adapter und per Quelltext geprüft sind. Dauer: etwa 30 bis 45 Minuten. Alles läuft im **Testmodus** von Stripe; es fließt kein echtes Geld.

Ergebnis: Für jeden Fall ist in der Tabelle am Ende „bestanden“ oder „Abweichung“ eingetragen, mit Bestell- und Lizenznummer. Damit lassen sich T-12, T-13, T-51, T-58 und T-133 in `docs/TODO.md` abhaken oder als Fehler melden.

## 0. Voraussetzungen

- Eine Testinstallation (Staging oder lokal), **nicht** der Produktivshop. HTTPS ist Pflicht.
- WooCommerce mit dem offiziellen Plugin **WooCommerce Stripe Gateway** in Version 9.6.0 bis 11.2.x. Neuere Versionen funktionieren voraussichtlich, sind aber nicht geprüft; die Seite „WooCommerce → License settings“ zeigt dann einen Hinweis.
- WP License Captain aus dem Branch dieses Pull Requests, aktiviert.
- WP-CLI auf dem Server (für das Auslösen der Verlängerung ohne Wartezeit).
- Ein Stripe-Konto mit Zugriff auf den Testmodus.
- Für Webhooks auf einem nicht öffentlichen Server: die [Stripe CLI](https://docs.stripe.com/stripe-cli).

## 1. Einrichtung (10 Minuten)

1. **Stripe-Gateway im Testmodus.** WooCommerce → Einstellungen → Zahlungen → Stripe: Testmodus an, Test-API-Schlüssel (Publishable und Secret Key aus dem Stripe-Dashboard, Modus „Test“) eintragen, „Zahlungsmethoden speichern“ erlauben. Den Webhook des Stripe-Plugins einrichten, wie es das Plugin verlangt.
2. **Webhook des Lizenzservers.** Er ist ein zweiter, eigener Endpunkt:
   - URL: `https://<shop>/wp-json/wplc/v1/webhooks/stripe` (steht auch unter WooCommerce → License settings, mit Kopieren-Button).
   - Ereignisse: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.dispute.created`.
   - Öffentlich erreichbarer Shop: im Stripe-Dashboard unter Entwickler → Webhooks anlegen und das Signaturgeheimnis kopieren.
   - Nicht öffentlich erreichbar: `stripe listen --forward-to https://<shop>/wp-json/wplc/v1/webhooks/stripe --events payment_intent.succeeded,payment_intent.payment_failed,charge.dispute.created` starten und das ausgegebene `whsec_…` verwenden. Das Fenster offen lassen.
3. **Geheimnis in `wp-config.php`:**

   ```php
   define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_...' );
   ```

   Danach zeigt WooCommerce → License settings beim Stripe-Webhook-Geheimnis einen grünen Haken.
4. **Lizenzprodukt.** Ein einfaches Produkt anlegen, z. B. 49,00 €, jährlich, im Reiter „Lizenz“ als Lizenzprodukt markieren (Produktschlüssel und Tarif beliebig).
5. **Testkunde.** Ein Kundenkonto anlegen; der Kauf einer Lizenz verlangt ein Konto.
6. **Prüfen:** WooCommerce → License diagnostics zeigt „Stripe gateway class: Loaded“ und keine Warnung zur Stripe-Version.

## 2. Testkarten

Alle mit beliebigem zukünftigem Ablaufdatum, beliebiger Prüfziffer und Postleitzahl. Quelle: [Stripe-Testkarten](https://docs.stripe.com/testing).

| Karte | Verhalten |
| --- | --- |
| `4242 4242 4242 4242` | Zahlung gelingt immer |
| `4000 0025 0000 3155` | Verlangt 3-D Secure beim Kauf; spätere Abbuchungen ohne Kunde gelingen |
| `4000 0027 6000 3184` | Verlangt 3-D Secure bei jeder Zahlung, auch bei späteren Abbuchungen ohne Kunde |
| `4000 0000 0000 0341` | Lässt sich speichern, spätere Abbuchungen werden abgelehnt |
| `4000 0000 0000 0259` | Zahlung gelingt, danach entsteht ein Zahlungsstreit (Dispute) |

## 3. Hilfsbefehle

Lizenz und Verlängerung eines Kunden finden (Tabellenpräfix ggf. anpassen):

```bash
wp db query "SELECT id, status, paid_until, renewal_enabled, cancel_at_period_end FROM wp_wplc_licenses ORDER BY id DESC LIMIT 5"
```

```bash
wp db query "SELECT id, license_id, status, attempt_count, retry_at, renewal_order_id FROM wp_wplc_renewals ORDER BY id DESC LIMIT 5"
```

Verlängerung sofort auslösen, ohne auf das Laufzeitende zu warten (`123` = ID aus `wp_wplc_renewals`):

```bash
wp eval 'WPLC\Billing\RenewalProcessor::process( 123 );'
```

Wartende Webhook-Verarbeitung sofort ausführen:

```bash
wp action-scheduler run --group=wplc
```

Gibt es für eine Lizenz noch keine Verlängerungszeile, legt der Planer sie beim Kauf an. Fehlt sie trotzdem, hilft der tägliche Lauf: `wp cron event run wplc_daily_lifecycle`.

## 4. Fälle

Nach jedem Fall die Lizenz-Detailseite (WooCommerce → Licenses → Lizenz öffnen) ansehen: Status, „Bezahlt bis“, Verlängerungen und Audit-Protokoll.

### F1 · Erstkauf mit Opt-in, Karte wird gespeichert

1. Als Testkunde das Lizenzprodukt kaufen, Karte `4242…`, Checkbox „Lizenz automatisch verlängern“ **ankreuzen**.
2. Erwartet:
   - Bestellung „Abgeschlossen“ oder „In Bearbeitung“, Lizenz `active`, „Verlängerung: Automatisch“.
   - Im Kundenkonto unter „Zahlungsmethoden“ ist die Karte gespeichert.
   - In der Bestellung steht die Meta `_wplc_recurring_consent_at`.
3. Gegenprobe: zweiter Kauf **ohne** Häkchen → Lizenz mit „Verlängerung: Manuell“, keine Abbuchung später.
4. Zusatz T-133: Ist im Stripe-Plugin „Checkout Sessions“ oder „Adaptive Pricing“ verfügbar, F1 damit wiederholen und prüfen, ob die Karte gespeichert wird. Wenn nicht: als Abweichung notieren.

### F2 · Erfolgreiche automatische Verlängerung

1. Lizenz aus F1. Verlängerungs-ID suchen, `RenewalProcessor::process( ID )` ausführen.
2. Erwartet:
   - Neue Verlängerungsbestellung, Status abgeschlossen; im Stripe-Dashboard ein PaymentIntent mit Metadaten `wplc_renewal_id`, `wplc_license_id`, `order_id` und einer Beschreibung „<Shop>: Verlängerung von <Produkt>, Bestellung #…“.
   - Lizenz: „Bezahlt bis“ um ein Jahr verschoben, nächste Verlängerung geplant.
   - Kunde erhält die Mail „Lizenz verlängert“.
   - Nach `wp action-scheduler run --group=wplc`: Webhook-Ereignis `payment_intent.succeeded` mit Ergebnis `already_reconciled` (die Verlängerung war schon gebucht).
3. **T-13 Doppelbelastung:** denselben Befehl sofort noch einmal ausführen. Erwartet: keine zweite Abbuchung in Stripe, die Verlängerung ist bereits `paid`.

### F3 · Abgelehnte Verlängerung mit Wiederholungen

1. Neuer Kauf mit Opt-in und Karte `4000 0000 0000 0341`.
2. Verlängerung auslösen.
3. Erwartet:
   - Lizenz `past_due`, Kunde erhält „Zahlung fehlgeschlagen“, Verlängerungsbestellung „Fehlgeschlagen“.
   - Verlängerung `failed`, `attempt_count` = 1, `retry_at` einen Tag nach Periodenbeginn.
   - Webhook `payment_intent.payment_failed` kommt an; Ergebnis im Event-Eingang notieren (`failure_observed` erwartet, siehe docs/STRIPE-WEBHOOKS.md, „Known gaps“).
4. Wiederholung sofort auslösen (`process( ID )` erneut). Erwartet: **neuer** PaymentIntent in Stripe (neuer Idempotency-Key `…:1`), wieder abgelehnt, `attempt_count` = 2.
5. Gegenprobe Erfolg nach Ablehnung: Im Kundenkonto eine neue Karte `4242…` speichern und als Standard setzen, dann „Jetzt verlängern“ (manuelle Verlängerung) bezahlen. Erwartet: Lizenz wieder `active`, kein späterer Kulanz-Ablauf.

### F4 · 3-D Secure

1. Kauf mit Opt-in und Karte `4000 0025 0000 3155`: Die 3-D-Secure-Abfrage erscheint beim Kauf, bestätigen. Erwartet wie F1.
2. Verlängerung auslösen. Erwartet: gelingt ohne Kunde (wie F2).
3. Kauf mit Opt-in und Karte `4000 0027 6000 3184`, dann Verlängerung auslösen. Erwartet:
   - Stripe lehnt mit `authentication_required` ab.
   - Verlängerung `manual_action_required`, Lizenz `past_due`, Bestellnotiz „requires customer authentication“; keine weiteren automatischen Versuche.
   - Kunde erhält die Mail „Zahlung fehlgeschlagen“ mit einem Link zur Zahlung der Verlängerungsbestellung.
   - Bezahlt der Kunde über den Link (mit Bestätigung), wird die Lizenz verlängert und es gibt keine zweite Abbuchung.

### F5 · Zeitüberschreitung (unklares Ergebnis)

1. Lizenz mit Opt-in und Karte `4242…`.
2. Stripe-Anfragen vorübergehend blockieren, z. B. mit einem MU-Plugin `wp-content/mu-plugins/stripe-timeout.php`:

   ```php
   <?php
   add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
       return false !== strpos( $url, 'api.stripe.com/v1/payment_intents' ) ? new WP_Error( 'http_request_failed', 'Simulated timeout' ) : $pre;
   }, 10, 3 );
   ```

3. Verlängerung auslösen. Erwartet:
   - Verlängerung `reconciling`, Lizenz bleibt `active`, **keine** Mail „Zahlung fehlgeschlagen“, Bestellnotiz zum unklaren Ergebnis.
4. MU-Plugin löschen und die Prüfung auslösen (`process( ID )`). Erwartet: Stripe bekommt dieselbe Anfrage mit demselben Idempotency-Key, die Zahlung wird einmal gebucht, die Lizenz verlängert.
5. Variante „Prüfung“: MU-Plugin aktiv lassen und die Verlängerung in der Datenbank 13 Stunden zurückdatieren (oder warten). Erwartet: Status `review_required`, Eintrag unter WooCommerce → Renewals to review, Alarm `wplc/health_alert`. Dort „Als nicht bezahlt markieren“ ausprobieren.

### F6 · Widerrufene Karte

1. Lizenz mit Opt-in und Karte `4242…`.
2. Im Stripe-Dashboard beim Kunden die Zahlungsmethode entfernen (Detach) **oder** im Kundenkonto des Shops die Karte löschen.
3. Verlängerung auslösen. Erwartet: keine Abbuchung, eindeutige Ablehnung (Lizenz `past_due`, Mail an den Kunden) bzw. bei gelöschtem Token im Shop: Verlängerung `manual_action_required` oder `failed` mit Begründung im Audit-Protokoll. Ergebnis notieren.

### F7 · Zahlungsstreit (T-51)

1. Im Stripe-Webhook-Endpunkt muss `charge.dispute.created` aktiviert sein.
2. Kauf mit Karte `4000 0000 0000 0259` (mit oder ohne Opt-in).
3. Stripe erzeugt nach kurzer Zeit einen Dispute. `wp action-scheduler run --group=wplc` ausführen.
4. Erwartet: Lizenz `suspended`, Audit `chargeback_received`, Website-Aktivierungen bekommen bei der nächsten Rechteprüfung keine Premium-Rechte mehr.

### F8 · Erstattung einer Verlängerung

1. Verlängerungsbestellung aus F2 in WooCommerce vollständig erstatten (über Stripe).
2. Erwartet: Lizenz bleibt `active`, „Bezahlt bis“ springt auf das alte Datum zurück, automatische Verlängerung aus, Audit `renewal_refunded`.
3. Variante Teilerstattung (z. B. 10 €): Laufzeit bleibt, Audit `renewal_partially_refunded`, Bestellnotiz mit Hinweis auf „Laufzeit ändern“.

### F9 · Kündigung durch den Kunden

1. Lizenz mit automatischer Verlängerung. Im Kundenkonto „Zum Ende des Zeitraums kündigen“ wählen und bestätigen.
2. Verlängerung auslösen. Erwartet: keine Abbuchung, Verlängerung `cancelled`, Lizenz läuft bis „Bezahlt bis“ und endet dann.

## 5. Zum Schluss

- Event-Eingang ansehen: `wp db query "SELECT id, event_type, outcome FROM wp_wplc_event_inbox ORDER BY id DESC LIMIT 20"`. Kein Ereignis sollte `pending_reconciliation` bleiben; `manual_review` nur dort, wo die Tabelle es erwartet.
- WooCommerce → License diagnostics: keine offenen Zähler außer den bewusst erzeugten Prüffällen.
- Testkunde und Testbestellungen nach Belieben löschen; der Shop bleibt im Testmodus, bis die Abnahme abgeschlossen ist.

## 6. Ergebnisprotokoll

Datum: ________ · Stripe-Plugin-Version: ________ · WooCommerce: ________ · Shop-URL: ________

| Fall | Ergebnis | Bestellung / Lizenz | Abweichung |
| --- | --- | --- | --- |
| F1 Erstkauf mit Opt-in | | | |
| F1 Gegenprobe ohne Opt-in | | | |
| F1 Checkout Sessions (T-133) | | | |
| F2 Erfolgreiche Verlängerung | | | |
| F2 Keine Doppelbelastung (T-13) | | | |
| F3 Ablehnung und Wiederholung | | | |
| F3 Manuelle Verlängerung nach Ablehnung | | | |
| F4 3-D Secure beim Kauf | | | |
| F4 3-D Secure bei der Verlängerung | | | |
| F5 Zeitüberschreitung, Abgleich | | | |
| F5 Prüffall | | | |
| F6 Widerrufene Karte | | | |
| F7 Zahlungsstreit (T-51) | | | |
| F8 Voll- und Teilerstattung | | | |
| F9 Kündigung | | | |

Bitte das ausgefüllte Protokoll in `docs/LAUNCH-EVIDENCE.md` übernehmen und Abweichungen als neue Aufgaben in `docs/TODO.md` eintragen.
