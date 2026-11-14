# Konzept: Checkout und Verlängerung

Stand: 13.11.2026 (ergänzt nach dem Audit: A-01, A-02, A-10, A-56, A-70, B-30, B-37, B-74, B-105, B-113, B-126). Dieses Dokument legt fest, wie Kauf und Verlängerung in Version 1 funktionieren. Es ersetzt die frühere Annahme, dass jeder Lizenzkauf eine Zustimmung zur automatischen Verlängerung voraussetzt.

## Grundsatz

Ein Lizenzkauf darf nie an der Verlängerung scheitern. Die Lizenz ist ein Laufzeitprodukt (Monat oder Jahr). Automatische Verlängerung ist eine freiwillige Zusatzoption; die manuelle Verlängerung ist der Standardweg, der mit jeder Zahlungsart funktioniert.

## Kauf

| Regel | Umsetzung | Status |
|---|---|---|
| Jede im Shop aktive Zahlungsart kann eine Lizenz kaufen. | Keine Checkout-Sperre mehr. | umgesetzt |
| „Ist Lizenzprodukt“ hat genau eine Quelle. | `Catalog::find_mapping( $product_id, $variation_id )` (Tabelle `wplc_plans`). Eine Variante ohne eigenen Tarif nutzt den Tarif des Hauptprodukts; die Verlängerung kostet dann den regulären Preis der gekauften Variante. Checkout (`RecurringConsent::cart_contains_license()`, `order_contains_license()`), Block-Checkout und `LicenseIssuer` fragen alle diese Methode. Das Produkt-Meta `_wplc_is_license_product` ist nur noch ein Spiegel der Formularwerte. | umgesetzt (*Integration*) |
| Lizenzen gehören zu einem Kundenkonto. | `woocommerce_checkout_registration_required` erzwingt ein Konto, sobald ein Lizenzprodukt im Warenkorb liegt; `woocommerce_checkout_registration_enabled` schaltet für diesen Warenkorb die Kontoerstellung im Checkout ein, damit Neukunden auch mit den WooCommerce-Standardeinstellungen kaufen können. Der Block-Checkout wertet denselben Filter aus (legt das Konto an oder lehnt den Gast ab); zusätzlich weist `BlockCheckoutConsent::after_order_processed()` eine Lizenzbestellung ohne Kundenkonto vor der Zahlung ab. | umgesetzt (beide Checkouts); Block-Pfad nur am Quelltext geprüft |
| Bezahlte Bestellung ohne Kunde bekommt keine herrenlose Lizenz. | `LicenseIssuer` ordnet die Lizenz dem Konto mit der Rechnungs-E-Mail zu (Filter `wplc_license_owner_for_guest_order`). Gibt es keines, wird nichts ausgestellt: Bestellnotiz, Bestell-Meta `_wplc_issuance_problem`, Log, Aktion `wplc/license_issuance_failed`; der Tageslauf versucht es erneut, sobald ein Kunde zugeordnet ist. Dasselbe gilt, wenn eine Lizenz technisch nicht angelegt werden konnte. | umgesetzt (*Integration*) |
| Laufzeit und Verlängerungspreis stehen vor dem Kauf da. | `TermDisplay`: Zusatz am Preis („für 1 Jahr“), im Warenkorb und in beiden Checkouts die Zeilen „Lizenzlaufzeit“ und „Automatische Verlängerung (optional): regulärer Preis pro Jahr/Monat“ mit der Steueranzeige des Shops. Wer schon eine Lizenz des Produkts hat, sieht den Hinweis, dass ein Neukauf einen neuen Schlüssel erzeugt. | umgesetzt (*Integration*), nicht im Browser |
| Auto-Verlängerung ist ein nicht vorausgewähltes Opt-in. | Klassisch: Checkbox vor dem Bestellbutton, nur wenn ein Gateway mit Renewal-Adapter verfügbar ist. Block-Checkout: zusätzliches Checkout-Feld `wplc/recurring-consent` (siehe unten). | umgesetzt (beide Checkouts); Block-Pfad nur am Quelltext geprüft |
| Beide Checkouts schreiben dieselbe Zustimmung. | Bestell-Meta `_wplc_recurring_consent_at` (UTC), nur wenn die Bestellung ein Lizenzprodukt enthält und die gewählte Zahlungsart einen bereiten Adapter hat (`RecurringConsent::decide()`). Der Wortlaut ist für beide Checkouts `RecurringConsent::label()`; er verweist auf den je Lizenz angezeigten Verlängerungspreis statt auf „den angezeigten Preis“. | umgesetzt |
| Die Zustimmung ist je Lizenz belegbar. | Beim Opt-in speichert die Bestellung `_wplc_recurring_consent_evidence` (Wortlaut samt Verlängerungszeilen, SHA-256, Sprache, Checkout). `LicenseIssuer` schreibt für jede Lizenz mit Auto-Verlängerung eine Zeile in `wplc_consents` (Zeitpunkt, Text, Text-Hash, Sprache, Betrag, Währung, Intervall, Bestellung, Kunde). Die Tabelle wird von der Datenbereinigung nicht angefasst. | umgesetzt (*Integration*); Auskunft/Löschung und Frist offen |
| Mit Opt-in wird die Karte gespeichert. | Filter `wc_stripe_force_save_payment_method( $force, $order_id )`; die Zustimmung wird aus der Bestellung gelesen, nicht aus `$_POST`. Der veraltete Filter `wc_stripe_force_save_source` wird nicht mehr verwendet. | umgesetzt, in der Stripe-Sandbox zu prüfen |
| Opt-in mit ungeeigneter Zahlungsart blockiert nicht. | Bestellnotiz, Lizenz ohne Auto-Verlängerung. Die Notiz wird erst geschrieben, wenn die Bestellung gespeichert ist (vorher ging sie bei neuen Bestellungen verloren). Der Kunde erfährt es in den Bestell-E-Mails und auf der klassischen Danke-Seite (`woocommerce_thankyou`). | umgesetzt; block-basierte Danke-Seite offen |
| Express-Buttons umgehen Konto und Opt-in nicht. | Für nicht angemeldete Besucher blenden die Filter `wc_stripe_hide_payment_request_on_product_page`, `wc_stripe_show_payment_request_on_cart` und `wc_stripe_show_payment_request_on_checkout` Apple Pay, Google Pay und Link aus, sobald ein Lizenzprodukt betroffen ist. | umgesetzt (*Integration* der Filter), nicht im Browser |
| Schlüssel-Mail hängt nicht am Checkout-Request. | `LicenseIssuer` stellt je Lizenz den Auftrag `wplc_license_issued_followup` ein; er und der Tageslauf (Lizenzen der letzten drei Tage) holen die Mail nach. Doppelversand verhindert das Mail-Protokoll. `pending`-Lizenzzeilen abgebrochener Requests werden nach 15 Minuten verworfen und neu ausgestellt. | umgesetzt (*Integration*) |
| Nachnahme bekommt die Lizenz erst bei „abgeschlossen“. | Filter `wplc_issue_after_completion_gateways` (Standard `cod`). Zusätzlich kann `wplc_can_issue_for_order` die Ausstellung zurückhalten (z. B. bis zur Zustimmung zum sofortigen Leistungsbeginn). | umgesetzt (*Integration*) |
| Der Kunde erfährt, wo sein Schlüssel liegt. | Hinweis mit Link in den Bestell-E-Mails. | umgesetzt |
| Checkout Blocks | Als kompatibel deklariert (`cart_checkout_blocks`). Opt-in, Zustimmung und Kontopflicht laufen über die Store API. | umgesetzt, ohne Browser- und Sandbox-Test |

## Block-Checkout

Umsetzung in `src/Billing/BlockCheckoutConsent.php`, ohne eigenes JavaScript.

| Schritt | Umsetzung | Beleg im WooCommerce-Quelltext (11.2.2) |
|---|---|---|
| Feld anmelden | `woocommerce_register_additional_checkout_field()` auf `woocommerce_init`: `id` `wplc/recurring-consent`, `type` `checkbox`, `location` `order`, nicht erforderlich. | `src/Blocks/Domain/Services/functions.php:13`, `CheckoutFields.php:209` |
| Wert speichern | WooCommerce legt den Wert als Bestell-Meta `_wc_other/wplc/recurring-consent` ab (`'1'` oder `'0'`). Felder des Orts `order` werden nicht am Kundenkonto gespeichert, das Feld ist beim nächsten Kauf also wieder leer. | `CheckoutFieldsStorage.php:63-82`, `CheckoutFields.php:867` |
| Zustimmung übernehmen | Hook `woocommerce_store_api_checkout_update_order_from_request( $order, $request )`, nur bei `POST`. Er läuft, nachdem Zahlungsart und Zusatzfelder an der Bestellung stehen, und vor der Zahlung. | `src/StoreApi/Utilities/CheckoutTrait.php:202-279` |
| Kontopflicht | `WC()->checkout()->is_registration_required()` wird in der Store API ausgewertet: Konto wird angelegt und der Kunde angemeldet, oder der Gast wird mit 403 abgelehnt. | `src/StoreApi/Routes/V1/Checkout.php:1020-1085`, `1144-1171` |
| Sicherung und Notiz | Hook `woocommerce_store_api_checkout_order_processed`: Lizenzbestellung ohne Kunden-ID wird mit `wplc_account_required` (403) abgelehnt; Bestellnotiz bei ungeeigneter Zahlungsart. | `Checkout.php:708` |

WooCommerce 8.9.0 lehnt einen Gast noch nicht selbst ab, wenn die Registrierung im Checkout abgeschaltet ist (`validate_user_can_place_order()` fehlt dort). In diesem Fall greift die eigene Sicherung im letzten Schritt.

Sichtbarkeit des Feldes:

- Das Feld wird nur angemeldet, wenn ein Renewal-Adapter bereit ist (`Registry::any_supports_checkout()`) und der Shop mindestens ein Lizenzprodukt hat. Abschaltbar über den Filter `wplc_block_checkout_consent_enabled`.
- Ab WooCommerce 9.9 blendet eine `hidden`-Regel das Feld aus, solange kein Lizenzprodukt im Warenkorb liegt (`cart.items` enthält keine der Lizenz-Produkt-IDs). Die IDs kommen aus dem Lizenzkatalog (`Catalog::license_product_ids()`, einschließlich der Varianten von Produkten, die am Hauptprodukt zugeordnet sind) und liegen in der automatisch geladenen Option `wplc_license_product_ids`; sie wird bei Katalog- und Produktänderungen sowie bei Statuswechseln (geplante Veröffentlichung) verworfen und beim nächsten Aufruf neu aufgebaut. Der Produktstatus spielt für die Liste keine Rolle.
- WooCommerce 8.9 bis 9.8 kennt keine bedingte Anzeige: Das Feld erscheint dort bei jedem Warenkorb. Für Bestellungen ohne Lizenz wird der Wert ignoriert.
- Bei mehr als 300 Lizenzprodukten wird die Regel nicht gesendet; das Feld ist dann ebenfalls immer sichtbar.
- Das Feld erscheint mit seinem Wert in Bestellbestätigung und Bestell-E-Mails (`show_in_order_confirmation`), als eigener Beleg für den Kunden.
- Die gewählte Zahlungsart fließt nicht in die Anzeige ein (eine Regel über `checkout.payment_method` ist ohne Browsertest nicht umgesetzt). Wählt der Kunde das Opt-in mit einer Zahlungsart ohne Adapter, gilt dasselbe wie im klassischen Checkout: Bestellnotiz, keine Auto-Verlängerung; der Feldwert an der Bestellung wird auf „Nein“ gesetzt.
- Das Feld steht im Abschnitt für zusätzliche Bestellinformationen des Checkout-Blocks. Position und Darstellung bestimmt WooCommerce.

Nicht zur Laufzeit geprüft: Darstellung im Browser, Auswertung der `hidden`-Regel im Browser, Kartenspeicherung mit Stripe im Block-Checkout. Gelesen wurden WooCommerce 8.9.0, 9.4.3 und 11.2.2 (Git-Tags aus `github.com/woocommerce/woocommerce`).

## Stripe-Gateway

Der Adapter nutzt interne Funktionen des Plugins „WooCommerce Stripe Gateway“. Geprüft am Quelltext der Versionen 9.6.0 und 11.2.0 (Git-Tags aus `github.com/woocommerce/woocommerce-gateway-stripe`); Zeilenangaben für 11.2.0:

| Annahme | Befund |
|---|---|
| `WC_Stripe_API::request( $request, $api, $method )` ist statisch. | Stimmt (`includes/class-wc-stripe-api.php:241`). Netzwerkfehler, HTTP-Ausfälle und leere Antworten werfen `WC_Stripe_Exception`; Stripe-Fehler stehen als `->error` in der Antwort. |
| Filter `wc_stripe_idempotency_key( $key, $request )` | Stimmt (`class-wc-stripe-api.php:250`). |
| `WC_Stripe_Helper::get_stripe_amount( $total, $currency )` liefert einen Integer. | Stimmt; wirft ab 11.2.0 bei zu großen Beträgen (`class-wc-stripe-helper.php:211-239`). Der Adapter fängt das ab. |
| Kunden-ID in der User-Option `_stripe_customer_id` | Stimmt (`class-wc-stripe-customer.php:1137,1146`). |
| Gespeicherte Karte: `WC_Payment_Token_CC`, Gateway `stripe`, Token `pm_…` | Stimmt (`payment-methods/class-wc-stripe-upe-payment-method-cc.php:85-92`). Link-Tokens haben dasselbe Gateway und werden jetzt über den Token-Typ ausgeschlossen. |
| Karte bei Zustimmung speichern | `wc_stripe_force_save_payment_method( $force, $order_id )` (`class-wc-stripe-helper.php:2193-2219`, Aufruf mit Bestell-ID in `payment-methods/class-wc-stripe-upe-payment-gateway.php:4211`). Wirkt nur, wenn das Speichern von Zahlungsarten im Gateway aktiv ist und der Kunde angemeldet ist. `wc_stripe_force_save_source` ist seit 9.6.0 veraltet. |
| Bezahlte Karte der Bestellung | Das Gateway hängt das Token nicht an die Bestellung, schreibt aber die PaymentMethod-ID in die Bestell-Meta `_stripe_source_id`. Der Adapter sucht zuerst das Kunden-Token mit dieser ID und fällt dann auf das Standard-Token zurück. |
| Off-Session-Zahlung | Der Adapter sendet jetzt `payment_method_types[]=card`, wie das Gateway selbst (`abstracts/abstract-wc-stripe-payment-gateway.php:2305`). |

`Requirements::stripe_adapter_status()` liefert `ok` und `reason` (Filter `wplc_stripe_adapter_status`). Der Adapter ist aus, wenn das Gateway fehlt, älter als 9.6.0 ist, eine der genutzten Klassen oder Methoden fehlt, Stripe als Zahlungsart deaktiviert ist oder das Speichern von Zahlungsarten abgeschaltet ist. Neuere Versionen als 11.2.0 bleiben aktiv, mit Hinweis im `reason`.

Bekannte Grenze: Im Stripe-Modus mit Checkout Sessions (Adaptive Pricing) fordert der Browser das Speichern der Karte an; ob der Filter dort ausreicht, ist offen. In der Sandbox prüfen.

## Mindestversionen

| Komponente | Minimum | Grund |
|---|---|---|
| WooCommerce | 8.9 (`WC requires at least` im Plugin-Kopf, `Requirements::MIN_WC`) | Additional Checkout Fields API ist ab 8.9.0 stabil. Darunter erscheint ein Admin-Hinweis; das Plugin läuft weiter, bietet im Block-Checkout aber kein Opt-in. |
| WooCommerce für bedingte Feldanzeige | 9.9 | `hidden`-Regeln für Zusatzfelder. |
| Action Scheduler | 3.6.0 für das Argument `$unique` (in WooCommerce ab 7.9), 4.0.0 für eine Eindeutigkeit, die auch die Argumente vergleicht (in WooCommerce ab 11.0) | Siehe unten. |
| `FeaturesUtil::declare_compatibility`, HPOS | älter als 8.9 | Durch das WooCommerce-Minimum abgedeckt. |
| WooCommerce Stripe Gateway | 9.6.0, geprüft bis 11.2.0 | Siehe oben. |

Action Scheduler vor 4.0.0 vergleicht bei `$unique = true` nur Hook und Gruppe, nicht die Argumente (`ActionScheduler_DBStore::build_where_clause_for_insert()`). Ein als eindeutig geplanter Auftrag für eine Lizenz verhindert dort denselben Hook für jede andere Lizenz, solange er aussteht. `Requirements::schedule_single_action()` und `Requirements::enqueue_async_action()` übergeben `$unique` deshalb erst ab 4.0.0 und prüfen darunter vorher mit `as_has_scheduled_action()` auf Hook, Argumente und Gruppe. Die bestehenden Aufrufe in `RenewalScheduler` und `RenewalProcessor` nutzen diese Wrapper noch nicht.

## Datenbank-Voraussetzungen

Das Plugin serialisiert kritische Abläufe mit MySQL-`GET_LOCK`. Diese Sperren gelten nur auf dem Server, auf dem sie gesetzt werden. Voraussetzung ist deshalb eine einzelne schreibende Datenbankinstanz (ein Primary, Replikate nur lesend). Galera, Percona XtraDB Cluster, Group Replication im Multi-Primary-Modus und Proxys, die Schreibzugriffe auf mehrere Knoten verteilen, werden nicht unterstützt: Zwei Knoten könnten dieselbe Sperre gleichzeitig vergeben. Ein Verbindungspooler, der Verbindungen zwischen Anfragen teilt, ist ebenfalls ungeeignet, weil `GET_LOCK` an die Verbindung gebunden ist.

| Stelle | Sperre schützt |
|---|---|
| `src/Api/V1/ActivationService.php` (zwei Stellen) | Aktivierung (Platzvergabe) und Deaktivierung über die API |
| `src/License/ActivationManager.php` | Deaktivierung einer Aktivierung |
| `src/Billing/RenewalProcessor.php` | Verarbeitung einer Verlängerung (eine Abbuchung je Verlängerung) |
| `src/Infrastructure/Database/Schema.php` | Schema-Migration |

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
