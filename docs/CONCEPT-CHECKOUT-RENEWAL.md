# Konzept: Checkout und Verlängerung

Stand: 13.11.2026. Dieses Dokument legt fest, wie Kauf und Verlängerung in Version 1 funktionieren. Es ersetzt die frühere Annahme, dass jeder Lizenzkauf eine Zustimmung zur automatischen Verlängerung voraussetzt.

## Grundsatz

Ein Lizenzkauf darf nie an der Verlängerung scheitern. Die Lizenz ist ein Laufzeitprodukt (Monat oder Jahr). Automatische Verlängerung ist eine freiwillige Zusatzoption; die manuelle Verlängerung ist der Standardweg, der mit jeder Zahlungsart funktioniert.

## Kauf

| Regel | Umsetzung | Status |
|---|---|---|
| Jede im Shop aktive Zahlungsart kann eine Lizenz kaufen. | Keine Checkout-Sperre mehr. | umgesetzt |
| Lizenzen gehören zu einem Kundenkonto. | `woocommerce_checkout_registration_required` erzwingt ein Konto, sobald ein Lizenzprodukt im Warenkorb liegt. | umgesetzt |
| Auto-Verlängerung ist ein nicht vorausgewähltes Opt-in. | Checkbox nur, wenn ein Gateway mit Renewal-Adapter verfügbar ist. | umgesetzt (klassischer Checkout) |
| Mit Opt-in wird die Karte gespeichert. | Filter `wc_stripe_force_save_source` und `wc_stripe_force_save_payment_method`. | umgesetzt, in der Stripe-Sandbox zu prüfen |
| Opt-in mit ungeeigneter Zahlungsart blockiert nicht. | Bestellnotiz, Lizenz ohne Auto-Verlängerung. | umgesetzt |
| Der Kunde erfährt, wo sein Schlüssel liegt. | Hinweis mit Link in den Bestell-E-Mails. | umgesetzt |
| Checkout Blocks | Nicht unterstützt und so deklariert. Lizenzen aus dem Block-Checkout verlängern sich nie automatisch. | offen: Store-API-Erweiterung oder Shop auf klassischen Checkout festlegen |

## Laufzeit, Kulanz und Ablauf

Die Regel steht an einer Stelle im Code: `LicenseLifecycle::has_access()`.

- Lizenz ohne Auto-Verlängerung oder gekündigt: Rechte enden exakt bei `paid_until`.
- Lizenz mit Auto-Verlängerung: Rechte bleiben nach `paid_until` 14 Tage bestehen, solange die Verlängerung läuft oder fehlschlägt (`past_due`). Das gilt für Rechteprüfung, neue Aktivierungen, Updates und Downloads.
- Nach der Kulanz setzt der Ablauf die Lizenz auf `expired`. `expired` und `revoked` schalten die Auto-Verlängerung ab und stornieren offene Verlängerungen, damit nie eine gesperrte Lizenz belastet wird.

## Manuelle Verlängerung (noch zu bauen)

Ziel: Der Kunde verlängert dieselbe Lizenz mit demselben Schlüssel, ohne Websites neu zu aktivieren.

1. **Auslöser:** Button „Jetzt verlängern“ im Kundenkonto. Sichtbar ab 30 Tagen vor `paid_until` und bis 30 Tage nach Ablauf.
2. **Bestellung:** Das Plugin legt eine normale WooCommerce-Bestellung mit dem Ursprungsprodukt an (Metadaten `_wplc_renewal_for_license`, `_wplc_renewal_id`) und leitet auf die Bezahlseite (`order-pay`). Jede Zahlungsart ist erlaubt.
3. **Preis:** der aktuelle Katalogpreis des Tarifs, Steuern berechnet WooCommerce. Kein gespeicherter Altpreis.
4. **Wirkung:** Bei `woocommerce_payment_complete` verlängert `mark_renewed()` die Lizenz. Neue Laufzeit beginnt bei `max(paid_until, jetzt)`, damit frühes Verlängern keine Tage kostet und spätes Verlängern keine Lücke rückwirkend bezahlt.
5. **Abgelaufene Lizenz:** Die Statusmaschine erhält den Übergang `expired → active` für die Aktion `renewal_succeeded`, begrenzt auf die 30 Tage Nachfrist. Danach ist ein Neukauf nötig.
6. **Idempotenz:** Pro Lizenz und Zielperiode genau eine offene Verlängerungsbestellung (bestehender Schlüssel `idempotency_key` in `wplc_renewals`).
7. **Admin:** Dieselbe Funktion als Admin-Aktion „Laufzeit verlängern“ mit Pflichtbegründung und Audit-Eintrag, für Kulanzfälle ohne Zahlung.

Der vorhandene Abgleich `RenewalProcessor::reconcile_paid_renewal_order()` deckt Schritt 4 bereits teilweise ab, ist aber auf Stripe und auf fehlgeschlagene Auto-Verlängerungen beschränkt. Er wird dafür verallgemeinert.

## Erinnerungen (noch zu bauen)

- 30 und 7 Tage vor Ablauf: E-Mail mit Verlängerungslink (ohne Auto-Verlängerung) oder Ankündigung der Abbuchung mit Betrag und Datum (mit Auto-Verlängerung).
- Bei fehlgeschlagener Abbuchung: E-Mail mit Link zur Bezahlseite und dem Enddatum der Kulanz.

## Offene Entscheidungen

| Frage | Empfehlung |
|---|---|
| Eigenbau-Renewals behalten oder WooCommerce Subscriptions einsetzen? | Manuelle Verlängerung zuerst fertigstellen. Die automatische Verlängerung erst nach bestandener Stripe-Sandbox freischalten; scheitert sie dort, auf WooCommerce Subscriptions wechseln. |
| Verlängerungspreis: aktueller Katalogpreis oder Kaufpreis? | Aktueller Katalogpreis, bei Auto-Verlängerung mit Ankündigung per E-Mail. Der heute gespeicherte Kaufpreis übernimmt sonst Aktionspreise dauerhaft. |
| Preise im Shop inklusive oder exklusive Steuer? | Vor dem ersten Renewal-Test festlegen; die Renewal-Bestellung muss den Nettobetrag an WooCommerce übergeben. |
