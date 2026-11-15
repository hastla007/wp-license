# Stripe test plan (T-12, B-150)

Nothing in the Stripe integration has run against a Stripe account. The automated tests use a fake of the gateway's API class (`tests/integration/helpers/StripeApiStub.php`) and constructed webhook events: they prove what the plugin does with an answer, not that Stripe gives that answer. This plan is the list of things to run by hand against a Stripe **test** account before the first real sale.

The original 16 steps of the Stripe review were not kept; these are reconstructed from the findings (B-57 to B-63, B-146 to B-150, A-15, A-16, A-20, A-113, A-114, B-10, B-29). Each fix has one step.

## Preparation

- A staging shop with WooCommerce, the WooCommerce Stripe gateway (run the plan once with 9.6.x and once with the current release) and this plugin. Gateway in test mode, "saved cards" enabled.
- Two webhook endpoints in the Stripe test dashboard: the gateway's own and `https://<shop>/wp-json/wplc/v1/webhooks/stripe` with the events listed in [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md). Secret of the second one in `WPLC_STRIPE_WEBHOOK_SECRET_TEST` (or `WPLC_STRIPE_WEBHOOK_SECRET`).
- A license product with a yearly plan. To make a renewal due, set `paid_until` and `next_payment_at` of the license and `period_start` of its renewal row to a time in the past (or use the admin "extend" action with a past date), then run the action `wplc_process_renewal` for the renewal ID in Action Scheduler.
- Keep the Stripe dashboard (Payments, Events, Webhooks → deliveries) and WooCommerce → License diagnostics open.
- Stripe test cards: `4242 4242 4242 4242` (success), `4000 0025 0000 3155` (3-D Secure), `4000 0000 0000 9995` (insufficient funds), `4000 0000 0000 9987` (lost card), `4000 0000 0000 9979` (stolen card), `4100 0000 0000 0019` (blocked by Radar), `4000 0000 0000 0341` (attaches, then declines), `4000 0000 0000 0259` (dispute), `4000 0000 0000 5423` (early fraud warning). Check the numbers against Stripe's current testing page before use.

For every step note: gateway version, date, PaymentIntent ID, result, deviations.

## Steps

| # | Finding | Do | Expect |
| --- | --- | --- | --- |
| 1 | T-12, B-57 | Buy the license with `4242…` and tick automatic renewal. | Card is saved without ticking the gateway's own box. License: `renewal_enabled = 1`, `gateway_customer_ref = cus_…`, `gateway_method_ref = pm_…`, `gateway_mode = test`. |
| 2 | T-12, B-60, A-20, B-147, B-149 | Make the renewal due and run it. | One PaymentIntent in Stripe with description "{shop} - Order {number}" and metadata `order_id`, `order_key`, `site_url`, `signature`, `payment_type=recurring`, `wplc_renewal_id`, `wplc_license_id`, `wplc_period`, `wplc_site`, `wplc_attempt`. Order paid, transaction ID `ch_…`, metas `_stripe_intent_id`, `_stripe_charge_captured = yes`, `_stripe_customer_id`, `_stripe_source_id`. License extended once. The gateway's webhook adds no note of its own to the order. |
| 3 | B-149 | Look at the mailbox of the customer and of the shop after step 2 and after step 6. | Customer: the plugin's own mail and Stripe's receipt (if receipts are on); no WooCommerce "processing", "completed" or "failed order" mail for the renewal order. Shop: WooCommerce's admin mails unchanged. |
| 4 | B-60, A-20 | Refund the renewal order of step 2 from WooCommerce ("Refund via Stripe"). Renew again, then refund the new payment in the Stripe dashboard. Repeat on gateway 9.6. | Both refunds arrive as WooCommerce refunds and the license loses the period. On 9.6 the order must not end as `cancelled`; if it does, the action `wplc/renewal_order_voided` fired and the audit log has `renewal_order_voided`. |
| 5 | T-12 | Buy with `4000 0025 0000 3155`, complete 3-D Secure, make the renewal due and run it. | The off-session charge either succeeds or Stripe answers `authentication_required`: renewal `manual_action_required`, license `past_due`, the customer account links to the pay page. |
| 6 | B-62 | Renew a license whose card is `…9995`. Separately: `…9987`, `…9979`, `…0019`. (Attach these through a purchase with `…0341` is not possible; replace the card of an existing test customer in the Stripe dashboard instead.) | `…9995`: renewal `failed` with a retry date, `last_error = stripe_insufficient_funds`. Lost, stolen, Radar block: renewal `manual_action_required` at once, no retry date, order metas `_wplc_stripe_decline_code`, `_wplc_stripe_advice_code`, `_wplc_stripe_outcome_type` filled, order note names them. Check that `outcome.type` really arrives in the error answer; if the meta stays empty for the Radar block, the adapter needs `expand[]=latest_charge`. |
| 7 | B-58 | After a failed renewal, pay the renewal order on the pay page with a new card `4242…`, without ticking "save". | Card is saved. License: `payment_token_id` and `gateway_method_ref` point to the new card, audit `payment_method_adopted`. The next renewal charges the new card. |
| 8 | B-59 | After a failed renewal, open the pay page with `…3155` and stop at the 3-D Secure dialog. Now run the renewal job. Then complete 3-D Secure. | While the dialog is open: no second PaymentIntent, renewal `last_error = renewal_order_in_use`. After completion: one payment, license extended once. |
| 9 | B-59 | Force a double payment: pay the order on the pay page, then send `payment_intent.succeeded` of another PaymentIntent with the renewal metadata (Stripe CLI `stripe trigger` with overridden metadata, or resend an older event after changing the order's `_stripe_intent_id`). | Inbox outcome `manual_review`, audit `renewal_double_payment`, order note, `wplc/renewal_needs_review` fired. License extended once. |
| 10 | B-61, B-78 | Buy with Link (and Amazon Pay, if enabled) and tick automatic renewal. The customer has another, older saved card. | License `renewal_enabled = 0`, no token. Order note "payment method of this order (link) is not a saved card", customer note with the hint, visible in the account and mailed. The older card is not used. |
| 11 | B-57 | Live-shop rehearsal on staging: after step 1 switch the gateway to live mode (any live keys of a second test account will do), open My account → Payment methods as the customer, switch back to test mode. Make the renewal due and run it in both modes. | In the other mode: nothing is sent to Stripe, `last_error = renewal_gateway_mode_mismatch`, attempt count unchanged, license stays `active`. Back in the original mode: the gateway has recreated the token row; the renewal finds the card by `pm_…` (audit `payment_method_relinked`) and charges. The license kept `renewal_enabled = 1` throughout. |
| 12 | B-78, B-57 | Detach the card in the Stripe dashboard (or delete it under Payment methods), then run the renewal. | Deleted by the customer in the shop: automatic renewal is switched off (other component). Detached at Stripe: nothing is charged, `last_error = renewal_payment_method_missing`, note on the first order, action `wplc/renewal_payment_method_missing`; no other card is charged. |
| 13 | A-114, B-146 | Timeout: block outgoing requests to `api.stripe.com` at the firewall after the request left (or kill PHP during the charge), then let the retry run with the connection restored. | First run: `renewal_result_unknown`, no failed attempt, license `active`. Retry: the adapter asks Stripe first (`GET /v1/payment_intents/search`), finds the payment and books it without a second charge; exactly one PaymentIntent exists. Check how long the search needs to see a new PaymentIntent (Stripe documents about a minute). |
| 14 | B-10, B-01, B-29 | Copy the database after a paid renewal, restore the copy from **before** that renewal on the same site and run the renewal. Separately: clone the shop to a second URL with the same Stripe account. | Restore: the adapter finds the existing payment by `wplc_renewal_id` and `wplc_period` and books it; no second charge. Clone: billing is paused by the site guard; with the pause lifted by hand the clone's events are ignored on the original (`"reason":"other_site"`) and the other way round. |
| 15 | B-148, T-51 | Buy with `…0259` (dispute) and with `…5423` (early fraud warning). Close the dispute as won and as lost (test dashboard: evidence text `winning_evidence` / `losing_evidence`). Send one test-mode event to a shop in live mode. | Dispute created: license `suspended`. Won: audit `dispute_won`, action fired, license stays `suspended`. Lost: audit `dispute_closed`. Fraud warning: audit `stripe_early_fraud_warning`, license unchanged. Test event in live mode: 200 with `"reason":"test_event_in_live_mode"`. Diagnostics shows the time of the last valid event. Resend one event from the dashboard: 200 `duplicate`. |
| 16 | B-63, A-15, A-16 | Enable SEPA Direct Debit in the gateway and buy with it, ticking automatic renewal. Then, with a card license: replace the secret key by one of another account and run a renewal; fire many renewals at once to hit the test-mode rate limit. | SEPA: no automatic renewal, note and hint as in step 10 (decision in [PAYPAL-COMPATIBILITY.md](PAYPAL-COMPATIBILITY.md)). Wrong account: `last_error = stripe_resource_missing` (or the code Stripe returns), no failed attempt, license `active`, `wplc/renewal_blocked` fired; after restoring the key the next run charges. Rate limit: `renewal_gateway_rate_limited`, retried after 30 minutes without a failed attempt. |

## What the automated tests already cover (against fakes)

`tests/integration/StripeAdapterIntegrationTest.php` and `tests/integration/StripeWebhookIntegrationTest.php` cover the plugin's side of every step: what is sent, and what happens for each answer. Not covered by any test: whether Stripe's answers have the assumed shape (`decline_code`, `advice_code`, `outcome`, `latest_charge`), the behaviour of the search API, the gateway's own webhook and token synchronisation, card saving on the pay page, and mails.

---

## Anhang (Deutsch): Stripe-Testmodus, Abnahme Schritt für Schritt (T-12)

Die folgende Anleitung stammt aus der parallelen Entwicklungslinie und beschreibt dieselbe Abnahme aus Sicht des Shopbetreibers; die Schritte der Tabelle oben gehen den einzelnen Funden nach.

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
