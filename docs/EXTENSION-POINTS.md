# Extension points

Actions and filters of the license server plugin (A-134). The tables are generated from `src/` with `php bin/list-hooks.php` (summary: the docblock above the call); `tests/Contract/ExtensionPointsDocTest.php` fails when a hook in the code is missing here, and when an action has no "Since" version or no "After commit" entry (the two columns are maintained by hand). The bundled client library has its own hooks with the add-on's prefix, see [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md).

## Conventions

- **Actions** are named `wplc/<event>` (past tense: something happened). Two older actions keep their underscore names because cron and queued jobs are stored under them: `wplc_daily_lifecycle` and `wplc_release_package_missing`.
- **Filters** are named `wplc_<thing>`. Numeric filters are clamped where a wrong value would be dangerous (for example `wplc_renewal_grace_days`, 1 to 90 days).
- Listeners run inside the request that triggered them. A listener that throws can break that request unless the documentation of the hook says errors are ignored; queued jobs are isolated by `DailyRunner`.
- **`wplc/license_issued` passes the plaintext license key** (arguments: license ID, key, order ID). It exists so the key email can be sent; the key is never stored in plaintext. Listeners that do not need the key must use `wplc/license_issued_followup` (license ID, order ID), which also runs when the issuing request ended early. Never log or store the key argument.
- Hooks not listed here are internal (WordPress, WooCommerce or Action Scheduler hooks the plugin listens to) and can change without notice.

## Hook contract for plugins in the shop (version 1.0.0)

For plugins that run in the same WordPress as the server (the workflow add-on is the intended receiver); the full description is [LICENSE-EVENTS.md](LICENSE-EVENTS.md).

- **Contract version:** constant `WPLC_CONTRACT_VERSION` in `wp-license.php` (prefix of `WPLC_VERSION`, suite spec R-3.1), currently `1.0.0`, semantic versioning independent of the plugin version. MAJOR when an action, an argument or a public method of `LicenseReader` is removed or changed incompatibly; MINOR for new actions, optional arguments or methods; PATCH for clarifications. **Stability promise:** an action of the Actions table is not removed or reinterpreted without a new MAJOR version; before removal it stays documented as deprecated for at least two MINOR versions and keeps firing through `do_action_deprecated()`.
- **Since and after commit (R-3.4, R-3.9):** the [Actions](#actions) table names for every action the plugin version since which it exists and whether it fires after the commit. "yes" means: after the change is committed and the license lock and the activation lock are released, never for a refused or rolled-back change (`WPLC\Events\AfterCommit`; evidence `LicenseEventsIntegrationTest`, `LicenseEventsAfterCommitIntegrationTest`). "–" means not promised. Versions before 0.2.0 are the unreleased engineering preview 0.1.0 (taken from the history of `src/`).
- **No envelope, no event ID:** the server fires plain PHP actions with scalar IDs; the consumer builds the envelope, its ID and the idempotency key (suite spec R-6.1). The subject of a license action is the record ID `license_id` (`id_kind` = `record`). The key prefix (`WPLC-XXXXXXXX`) is part of the license key and is never passed.
- **Actions of the suite catalog:**

  | Action | Arguments | Suite event |
  | --- | --- | --- |
  | `wplc/license_issued_followup` | `int $license_id, int $order_id` (0 for a license issued by hand) | `license.license.issued` |
  | `wplc/license_status_changed` | `int $license_id, string $old_status, string $new_status, string $action` | `license.license.status_changed` |
  | `wplc/license_plan_changed` | `int $license_id` | `license.license.plan_changed` |
  | `wplc/activation_created` | `int $license_id, int $activation_id, string $environment` (`live`, `staging`, `development`) | `license.activation.created` (reserved) |
  | `wplc/activation_removed` | `int $license_id, int $activation_id, string $environment, string $initiator` (`api`, `customer`, `support`, `system`) | `license.activation.removed` (reserved) |
  | `wplc/site_limit_reached` | `int $license_id, string $environment, int $limit` | `license.license.site_limit_reached` (reserved) |
  | `wplc/release_published` | `int $release_id, string $product_key, string $version, string $channel` | `license.release.published` (reserved) |
  | `wplc/release_withdrawn` | as `wplc/release_published` | not in the suite catalog v1 (extra) |

  None of them passes a license key or part of it, a key hash, a site address, an email address or payment data. A listener of the five new actions that throws is reported through `wplc/job_failed` (job `action:wplc/<name>`) and does not break the operation; the three existing actions behave as before.
- **Never attach a receiver to** `wplc/license_issued`, `wplc/license_key_reissued` or `wplc/license_key_resend_requested`: they pass the plaintext key.

### Read-only facade `WPLC\Events\LicenseReader`

Lookups by `license_id` from an action, optionally narrowed by the owner's user ID: `get( $license_id, $customer_user_id = 0 )` (summary: license_id, product_key, plan_key, status, access, expires_at, access_until, customer_user_id), `status()`, `plan()`, `expires_at()`, `entitlements()` (granted now; empty without access), `is_entitled( $license_id, $key )` and `for_user( $user_id, $product_key = '' )`. Every method runs SELECT queries only and never returns a key, key prefix, key hash, email address, site address, payment data or secret. Changing a license stays behind the capabilities `wplc_*` and nonces of the plugin's own screens and APIs.

## Co-users and transfers (B-139)

The co-user and transfer hooks (`wplc/license_member_*`, `wplc/license_transfer_started`, `wplc/license_transferred`, `wplc_license_member_limit`, `wplc_license_invitation_days`, `wplc_license_transfer_enabled`, `wplc_license_transfer_new_key`; their defaults are the settings under License settings → Co-users and transfer, 5, 14, on and off unless changed) are described in [SUPPORT-ACTIONS.md](SUPPORT-ACTIONS.md#co-users-and-transfers-by-the-customer). An accepted transfer also fires `wplc/license_owner_changed` first, like the owner change in the admin. The account template `myaccount/wplc-license.php` prints the co-user and transfer forms through its template action `wplc_account_license_sharing` (license row, display values); a theme copy made before this version needs that line added.

## Catalog files instead of filters

Products, entitlement keys and their labels are data: an add-on ships a catalog file (`wplc-catalog.json`) and the shop imports it ([CATALOG-FILE.md](CATALOG-FILE.md)). This is the supported way to make a product and its entitlements known to the product editor. The filters `wplc_entitlement_catalog`, `wplc_known_product_keys` and `wplc_offer_planned_entitlements` remain for shops that adjust what the editor offers in code; they receive what the imported catalog offers.

Project products (connectors for one customer project, decision 17) use the catalog file too: it comes with the connector's first release package and makes product key, prefix and entitlements known ([CATALOG-FILE.md](CATALOG-FILE.md#project-products)). The filters `wplc_entitlement_catalog` and `wplc_known_product_keys` (and an MU plugin that sets them) are not needed for them (`ProjectProductsIntegrationTest::test_pk04_the_first_release_of_a_connector_brings_product_and_rights_into_the_catalog`). The product editor maps a product key to its group only through the catalog, never by substring; a group added with `wplc_entitlement_catalog` needs an entry in `wplc_known_product_keys` to be offered for a product key.

## Package resolvers and built-in object storage

`wplc_resolve_update_package_url` (arguments: stored package URL, license ID; `0` when the server verifies a release on publish) turns the stored location of a URL release into the URL a client is redirected to. Two resolvers are built in: `PackageStream::resolve` (priority 10, uploaded packages) and `S3Storage::resolve` (priority 20, T-31), which signs an unchanged object URL of the bucket configured with the `WPLC_S3_*` constants or the object storage fields of the settings page (SET-LIC-30; see [UPDATE-STORAGE.md](UPDATE-STORAGE.md)). A custom resolver on priority 10 (or anything below 20) runs first; a URL it changed carries a query and is not signed again, so custom resolvers keep precedence. `wplc_s3_presign_expires` sets the validity of the signed links (default 300 s, clamped to 1 s … 7 days). The bucket's request host is added to `wplc_update_package_hosts` (priority 1000) while the storage is configured. There is no filter for the credentials: they come from wp-config.php only.

## Actions

| Hook | Where | Since | After commit | Summary |
| --- | --- | --- | --- | --- |
| `wplc/activation_created` | `src/Events/LicenseEvents.php` | 0.2.0 | yes | Fires after a new site activation was stored (suite event `license.activation.created`). |
| `wplc/activation_removed` | `src/Events/LicenseEvents.php` | 0.2.0 | yes | Fires after a site activation ended (suite event `license.activation.removed`). |
| `wplc/activation_transferred` | `src/License/ActivationTransfer.php` | 0.2.0 | – | Fires after a customer moved a site activation to another of their licenses (A-22). |
| `wplc/api_request_rejected` | `src/Api/V1/ApiLog.php` | 0.1.0 | – | Fires for every error answer of the license API. |
| `wplc/auto_renewal_disabled` | `src/Maintenance/RenewalSwitch.php` | 0.1.0 | – | Fires after automatic renewal was switched off because its basis is gone. |
| `wplc/cancellation_scheduled` | `src/Notifications/Notifications.php` | 0.1.0 | – |  |
| `wplc/chargeback` | `src/License/LicenseLifecycle.php` | 0.1.0 | – | Fires when a payment for a license was disputed. |
| `wplc/dispute_closed` | `src/Gateway/StripeEventHandler.php` | 0.1.0 | – | Fires when a dispute about a license payment was closed without being won. |
| `wplc/dispute_funds_reinstated` | `src/Gateway/StripeEventHandler.php` | 0.1.0 | – | Fires when Stripe returned the money of a dispute. |
| `wplc/dispute_won` | `src/Gateway/StripeEventHandler.php` | 0.1.0 | – | Fires when a dispute about a license payment was decided for the shop. |
| `wplc/gap_detected` | `src/Maintenance/GapGuard.php` | 0.1.0 | – | Fires when a processing gap was recorded. |
| `wplc/health_alert` | `src/Admin/Diagnostics.php` | 0.1.0 | – |  |
| `wplc/job_failed` | `src/Maintenance/DailyRunner.php` | 0.1.0 | – | Fires after a scheduled job threw. Errors of listeners are ignored. |
| `wplc/license_entitlements_synced` | `src/License/EntitlementSync.php` | 0.2.0 | – | Fires after the entitlement snapshot of a license was brought up to its plan's current settings. |
| `wplc/license_issuance_failed` | `src/License/LicenseIssuer.php` | 0.1.0 | – | Fires when a paid order could not get all its licenses. |
| `wplc/license_issued` | `src/License/LicenseIssuer.php` | 0.1.0 | – | Fires after a license is issued. The key is derived and never persisted in plaintext. |
| `wplc/license_issued_followup` | `src/License/LicenseIssuer.php` | 0.1.0 | yes | Fires once the follow-up work for an issued license is done. No key is passed. |
| `wplc/license_key_reissued` | `src/Admin/SupportActions.php` | 0.1.0 | – | Fires after a license received a new key. |
| `wplc/license_key_resend_requested` | `src/Admin/SupportActions.php` | 0.1.0 | – | Fires when an administrator asks for the key email to be sent again. |
| `wplc/license_member_added` | `src/License/LicenseMembers.php` | 0.2.0 | – | Fires after a user accepted a co-user invitation. |
| `wplc/license_member_invited` | `src/License/LicenseMembers.php` | 0.2.0 | – | Fires after the owner invited a co-user. |
| `wplc/license_member_removed` | `src/License/LicenseMembers.php` | 0.2.0 | – | Fires after a co-user was removed or an open invitation withdrawn. |
| `wplc/license_needs_review` | `src/License/LicenseLifecycle.php` | 0.1.0 | – | Fires when a license was suspended and needs a decision by the shop. |
| `wplc/license_owner_changed` | `src/Admin/SupportActions.php` | 0.1.0 | – | Fires after a license was handed over to another user. |
| `wplc/license_plan_changed` | `src/License/PlanChange.php` | 0.2.0 | yes | Fires after the plan of a license changed (upgrade, downgrade at renewal, administrator change, revert). |
| `wplc/license_status_changed` | `src/License/LicenseLifecycle.php` | 0.1.0 | yes | Fires after every successful license status transition and renewal. |
| `wplc/license_transfer_started` | `src/License/LicenseMembers.php` | 0.2.0 | – | Fires after the owner started a transfer of a license. |
| `wplc/license_transferred` | `src/License/LicenseMembers.php` | 0.2.0 | – | Fires after a transfer started by the owner was accepted by the recipient. |
| `wplc/loaded` | `src/Plugin.php` | 0.1.0 | – | Fires after WP License Captain has confirmed its WooCommerce dependency. |
| `wplc/price_change_not_applied` | `src/Billing/PriceChange.php` | 0.2.0 | – | Fires once when the date of an announced renewal price passed without a recorded notice email. |
| `wplc/release_published` | `src/Events/LicenseEvents.php` | 0.2.0 | yes | Fires after a release was published (suite event `license.release.published`). |
| `wplc/release_withdrawn` | `src/Events/LicenseEvents.php` | 0.2.0 | yes | Fires after a published release was withdrawn (not in the suite catalog v1). |
| `wplc/renewal_blocked` | `src/Billing/RenewalProcessor.php` | 0.1.0 | – | Fires when a renewal could not be attempted because of a shop-side problem. |
| `wplc/renewal_disabled_token_deleted` | `src/Maintenance/Orphans.php` | 0.1.0 | – | Fires after automatic renewal was switched off because the saved payment method was deleted. |
| `wplc/renewal_lock_unavailable` | `src/Billing/RenewalProcessor.php` | 0.1.0 | – | Fires when the lock of a renewal could repeatedly not be taken. |
| `wplc/renewal_needs_review` | `src/Billing/RenewalOrder.php` | 0.1.0 | – | Fires when a paid renewal order could not be applied to its license. |
| `wplc/renewal_order_voided` | `src/Gateway/StripeRecurringAdapter.php` | 0.1.0 | – | Fires when a paid renewal order was cancelled, i.e. its payment was taken back. |
| `wplc/renewal_payment_method_adopted` | `src/Gateway/StripeRecurringAdapter.php` | 0.1.0 | – | Fires after a license was moved to the card a renewal order was paid with. |
| `wplc/renewal_payment_method_missing` | `src/Gateway/PaymentReference.php` | 0.1.0 | – | Fires when a due renewal has no usable payment method. |
| `wplc/renewal_unavailable_for_order` | `src/Gateway/StripeRecurringAdapter.php` | 0.1.0 | – | Fires when a purchase with renewal consent has no payment method that can renew. |
| `wplc/renewals_disabled_for_deleted_token` | `src/Maintenance/Orphans.php` | 0.1.0 | – | Fires once per deleted payment method after automatic renewal was switched off for its licenses. |
| `wplc/response_signing_key_created` | `src/Security/ResponseSigner.php` | 0.1.0 | – | Fires once when the server creates its Ed25519 response signing key. |
| `wplc/response_signing_key_unavailable` | `src/Security/ResponseSigner.php` | 0.1.0 | – | Fires when responses cannot be signed with the server key. |
| `wplc/review_case_changed` | `src/Billing/RenewalReview.php` | 0.2.0 | – |  |
| `wplc/site_limit_reached` | `src/Events/LicenseEvents.php` | 0.2.0 | yes | Fires when an activation was refused because a site limit was reached (suite event `license.license.site_limit_reached`). |
| `wplc/stripe_early_fraud_warning` | `src/Gateway/StripeEventHandler.php` | 0.1.0 | – | Fires when Stripe reports an early fraud warning for a license payment. |
| `wplc/stripe_webhook_received` | `src/Api/V1/StripeWebhook.php` | 0.1.0 | – |  |
| `wplc_daily_lifecycle` | `src/Maintenance/DailyRunner.php` | 0.1.0 | – |  |
| `wplc_release_package_missing` | `src/Update/ReleaseRegistry.php` | 0.1.0 | – | Fires when a published release cannot be offered because its file is missing on this server (A-111). |
| `wplc_settings_page_tools` | `src/Admin/Settings.php` | 0.2.0 | – | Fires below the settings form: tools that work on the settings (country profiles, export, import, reset). |

## Filters

| Hook | Where | Summary |
| --- | --- | --- |
| `wplc_account_endpoint_slug` | `src/Account/CustomerPortal.php` | Filter the URL slug of the license page in the customer account, |
| `wplc_account_license_view` | `src/Account/CustomerPortal.php` | Filter the display values of one license in the customer account. |
| `wplc_admin_can_raise_renewal_amount` | `src/Admin/SupportActions.php` | Filters whether an administrator may raise the automatic renewal amount of a license. |
| `wplc_audit_action_labels` | `src/Admin/Format.php` | Filters the readable names of audit actions on the admin screens. |
| `wplc_audit_source` | `src/License/AuditLog.php` | Filter the source stored with an audit entry (A-76). |
| `wplc_billing_paused` | `src/Maintenance/SiteGuard.php` | Filter whether billing is paused on this installation. |
| `wplc_block_checkout_consent_enabled` | `src/Billing/BlockCheckoutConsent.php` | Filters whether the automatic renewal opt-in is offered in the block checkout. |
| `wplc_block_checkout_consent_without_rule` | `src/Billing/BlockCheckoutConsent.php` | Filters whether the block checkout offers the automatic renewal opt-in when |
| `wplc_block_product_editor_enabled` | `src/Catalog/ProductEditor.php` | Filters whether the block-based product editor counts as switched on. |
| `wplc_can_issue_for_order` | `src/License/LicenseIssuer.php` | Filters whether licenses may be issued for a paid order right now. |
| `wplc_can_override_renewal_amount` | `src/Billing/RenewalOrder.php` | Filters whether the current user may change the amount of a renewal order. |
| `wplc_capability` | `src/Admin/Capabilities.php` | Filters the capability required for a license administration action. |
| `wplc_catalog_auto_import` | `src/Catalog/ReleaseCatalog.php` | Filters whether the catalog file of an uploaded release package is imported. |
| `wplc_catalog_csv_time_budget` | `src/Admin/CatalogCsv.php` | Filters how many seconds one catalog CSV import may run before it stops and reports where. |
| `wplc_client_ip` | `src/Api/V1/ClientIp.php` | Filter the client address used for anonymous rate limiting. |
| `wplc_combined_renewals` | `src/Billing/CombinedRenewal.php` | Filters whether due automatic renewals of one customer are combined into one order and one charge (B-191). |
| `wplc_currency_decimals` | `src/Billing/Money.php` | Filters the precision of a currency the shop does not enter prices in (B-189). |
| `wplc_customer_download_hourly_limit` | `src/Update/CustomerDownload.php` | Filters how many downloads from the customer account one license may start per hour. |
| `wplc_customer_download_version_choices` | `src/Update/CustomerDownload.php` | Filters how many versions the customer can choose from. |
| `wplc_daily_run_split` | `src/Maintenance/DailyRunner.php` | Filter whether the daily run queues every job as its own Action Scheduler action. |
| `wplc_development_site_limit` | `src/Api/V1/ActivationService.php` | Filter the number of development activations per license. Zero forbids them. |
| `wplc_disable_renewal_on_token_deletion` | `src/Maintenance/Orphans.php` | Filter whether deleting a payment method switches automatic renewal off. |
| `wplc_download_grant_retention_days` | `src/Maintenance/Retention.php` |  |
| `wplc_download_grant_seconds` | `src/Api/V1/UpdateService.php` | Filters how many seconds a download link of an update can be redeemed. |
| `wplc_download_reuse_seconds` | `src/Update/PackageStream.php` | Filters how long a download token stays valid after its first use. |
| `wplc_entitlement_catalog` | `src/Catalog/EntitlementCatalog.php` | Filters the catalog of known boolean entitlements offered in the product editor. |
| `wplc_expired_license_download` | `src/Update/CustomerDownload.php` | Filters whether a license whose paid period ended may still download |
| `wplc_expiry_time_budget` | `src/License/LicenseLifecycle.php` |  |
| `wplc_gap_due_margin_days` | `src/Maintenance/GapGuard.php` | Filter how many days before the gap a due date is still protected. |
| `wplc_gap_hold_days` | `src/Maintenance/GapGuard.php` | Filter the longest hold after a gap in days. |
| `wplc_gap_reconcile_days` | `src/Maintenance/GapReconciler.php` | Filter how many days of orders and refunds are reconciled. |
| `wplc_health_alert_recipients` | `src/Admin/HealthAlertMail.php` | Filters the recipients of health alert emails. Wins over the setting. |
| `wplc_health_alert_thresholds` | `src/Admin/Diagnostics.php` | Filters the alert thresholds per figure of the diagnostics page. Wins over the settings. |
| `wplc_install_steps` | `src/Notifications/Notifications.php` |  |
| `wplc_is_local_development_url` | `src/Api/V1/ActivationService.php` | Allow a shop owner to explicitly approve a custom development hostname. |
| `wplc_is_staging_host` | `src/Api/V1/SiteUrl.php` | Filter whether a host is accepted as the staging copy of a live site. |
| `wplc_issue_after_completion_gateways` | `src/License/LicenseIssuer.php` | Filters the payment methods whose orders only get their licenses once the order is completed. |
| `wplc_job_runs_while_billing_paused` | `src/Maintenance/DailyRunner.php` | Filter whether a job runs although billing is paused. |
| `wplc_key_rotation_reminder_months` | `src/Security/KeyAge.php` | Filters after how many months a key should be rotated. Wins over the setting "Remind to rotate keys after" (SET-LIC-28; 0 = no reminder). |
| `wplc_known_product_keys` | `src/Catalog/EntitlementCatalog.php` | Filter the product keys suggested in the product editor. |
| `wplc_license_email_locale` | `src/Notifications/PurchaseLocale.php` | Filter the locale used for the emails of one license. |
| `wplc_license_email_recipient` | `src/Notifications/Emails/LicenseEmail.php` | Filter the recipient of the emails of one license. |
| `wplc_license_invitation_days` | `src/License/LicenseMembers.php` | Filters how many days an invitation link can be used. |
| `wplc_license_lock_timeout` | `src/License/LicenseLifecycle.php` | Seconds a lifecycle change waits for another request that changes the same license. |
| `wplc_license_member_limit` | `src/License/LicenseMembers.php` | Filters how many co-users a license can have (accepted plus open invitations). |
| `wplc_license_owner_for_guest_order` | `src/License/LicenseIssuer.php` | Filters the owner of the licenses of an order without a customer account. |
| `wplc_license_transfer_enabled` | `src/License/LicenseMembers.php` | Filters whether the owner can transfer a license to another customer from the account. |
| `wplc_license_transfer_new_key` | `src/License/LicenseMembers.php` | Filters whether a transfer accepted in the account also issues a new license key. |
| `wplc_local_host_suffixes` | `src/Api/V1/SiteUrl.php` | Filters the endings of local addresses, without the leading dot (SET-LIC-09). Wins over the setting. |
| `wplc_lock_self_test` | `src/Admin/Diagnostics.php` | Lets the package that owns the lock helper answer the self-test with its own implementation. |
| `wplc_log_api_rejection` | `src/Api/V1/ApiLog.php` | Filter whether this rejection is written to the log. |
| `wplc_log_debug` | `src/Infrastructure/Log.php` | Filters whether the plugin writes debug lines to the WooCommerce log (A-133). |
| `wplc_manual_renewal_for_unsold_products` | `src/Billing/ManualRenewal.php` | Filters whether customers may renew a license in their account when its product is not for sale (private, draft, not purchasable). Default false: such licenses are extended only by the shop owner with "Change term"; return true to allow the customer renewal at the plan price. |
| `wplc_manual_renewal_window_days` | `src/License/LicenseStateMachine.php` | Filters the window of manual renewal. Wins over the two settings. |
| `wplc_max_license_quantity_per_line` | `src/Catalog/QuantityLimit.php` | Filters the largest quantity of a license product in one order line. Wins over the setting. |
| `wplc_new_activations_per_hour` | `src/Api/V1/ActivationService.php` | Filter how many new installations one license may activate per hour. |
| `wplc_offer_planned_entitlements` | `src/Catalog/EntitlementCatalog.php` | Filters whether entitlements that are planned but not shipped by any add-on yet are offered in the product editor (default: no). |
| `wplc_offline_cache_days` | `src/Api/V1/EntitlementService.php` | Filter the number of days a client may rely on a signed snapshot offline. |
| `wplc_package_max_upload_bytes` | `src/Update/LocalPackageStorage.php` |  |
| `wplc_package_offload_header` | `src/Update/PackageStream.php` | Filters a header that hands the file to the web server instead of PHP (B-25). |
| `wplc_package_storage_dir` | `src/Update/LocalPackageStorage.php` | Filters the private package directory, e.g. to move it outside the web root. |
| `wplc_package_storage_probe` | `src/Update/PackageStorageCheck.php` | Filters whether the package directory may be probed over HTTP. |
| `wplc_payment_token_id_for_order` | `src/License/LicenseIssuer.php` |  |
| `wplc_plan_label` | `src/Notifications/Notifications.php` | Filter the plan name shown to customers. |
| `wplc_price_change_notice_days` | `src/Billing/PriceChange.php` | Filters the notice period of a renewal price change in days. |
| `wplc_pricing_table_plans` | `src/Account/PricingTable.php` | Filters the plans of the pricing table (block `wplc/pricing`, shortcode `[wplc_pricing]`). |
| `wplc_product_docs_url` | `src/Notifications/Notifications.php` | Filter the documentation URL shown with the installation steps. |
| `wplc_public_cancel_hourly_limit` | `src/Account/PublicCancellation.php` | Filter how many public cancellation requests are accepted per hour, |
| `wplc_public_cancel_order_id` | `src/Account/PublicCancellation.php` | Filter the order ID for an order number typed on the public cancellation |
| `wplc_recurring_consent_text` | `src/Billing/RecurringConsent.php` | Filters the wording of the consent to automatic renewal (classic and block checkout). Wins over the setting. |
| `wplc_recurring_gateway_adapters` | `src/Gateway/Registry.php` |  |
| `wplc_registrable_domain` | `src/Api/V1/SiteUrl.php` | Filter the registrable domain derived from a host (for example with a public-suffix list). |
| `wplc_release_product_slug` | `src/Update/ReleaseRegistry.php` | Filters the plugin folder a product's packages must contain. |
| `wplc_reminder_lead_days` | `src/Notifications/Notifications.php` | Filter the days before paid_until on which expiry reminders are sent. |
| `wplc_reminder_time_budget` | `src/Notifications/Notifications.php` | Filter the seconds a reminder run may take before it continues in a later run. |
| `wplc_renew_instead_notice` | `src/Billing/RenewInsteadNotice.php` | Filter the "Renew instead" notice for one license in the cart (A-22). |
| `wplc_renewal_charge_allowed` | `src/Billing/RenewalProcessor.php` | Filters whether a due renewal may be charged now (B-41). |
| `wplc_renewal_currency_supported` | `src/Billing/RenewalOrder.php` | Filters whether a renewal may be charged in the license's currency (B-189). |
| `wplc_renewal_display_amount` | `src/Notifications/RenewalTerms.php` | Filter the displayed amount of the next renewal. |
| `wplc_renewal_grace_days` | `src/License/LicenseLifecycle.php` | Filters the renewal grace period in days (A-135). |
| `wplc_renewal_notice_lead_days` | `src/Notifications/Notifications.php` | Filter the lead time of the automatic renewal notice. Zero disables the |
| `wplc_renewal_price_in_order_currency` | `src/License/LicenseIssuer.php` | Filters the renewal price of a license bought in a currency other than the shop currency (B-189). |
| `wplc_renewal_requires_advance_notice` | `src/Notifications/Notifications.php` | Filter whether an automatic charge needs the logged advance notice. |
| `wplc_renewal_retry_days` | `src/Billing/RenewalProcessor.php` | Filters the retry schedule of failed renewal charges (A-135). |
| `wplc_require_package_resolver` | `src/Api/V1/UpdateService.php` | Filters whether packages may only be delivered through a resolver. |
| `wplc_resolve_update_package_url` | `src/Update/ReleaseRegistry.php` |  |
| `wplc_retention_days` | `src/Maintenance/Retention.php` | Filter the retention periods in days. |
| `wplc_retention_time_budget` | `src/Maintenance/Retention.php` | Filter the seconds one retention run may take. |
| `wplc_review_payment_reference` | `src/Billing/RenewalReview.php` | Filters the transaction ID stored when a reviewed renewal order is booked as paid. |
| `wplc_s3_presign_expires` | `src/Update/S3Storage.php` | Filters how long a pre-signed object storage URL is valid. |
| `wplc_schema_can_migrate` | `src/Infrastructure/Database/Schema.php` | Filter whether this request may run a schema migration. |
| `wplc_self_update_check_enabled` | `src/Infrastructure/SelfUpdateCheck.php` | Filters whether the plugin looks for new releases of itself (A-42). |
| `wplc_self_update_release_api` | `src/Infrastructure/SelfUpdateCheck.php` | Filters the API address of the latest release (A-42). |
| `wplc_settings_country_profiles` | `src/Settings/Presets.php` | Filters the country profiles (suggestions for the settings with legal weight). |
| `wplc_shop_base_currency` | `src/Catalog/Catalog.php` | Filters the currency product prices are entered in (B-189). |
| `wplc_show_license_term` | `src/Catalog/TermDisplay.php` | Filters whether the license term is appended to the price of a license product. |
| `wplc_sites_endpoint_slug` | `src/Account/SiteOverview.php` | Filter the URL slug of the page "Sites" in the customer account (default: the setting "Address of the site page", SET-LIC-25). Rewrite rules are refreshed automatically. |
| `wplc_staging_host_suffixes` | `src/Api/V1/SiteUrl.php` | Filters the hosting provider domains whose subdomains are staging copies, without the leading dot (SET-LIC-09). Wins over the setting. |
| `wplc_staging_labels` | `src/Api/V1/SiteUrl.php` | Filters the name parts that mark a staging copy (SET-LIC-09). Wins over the setting "Staging: name parts". |
| `wplc_staging_site_limit` | `src/Api/V1/ActivationService.php` | Filter the number of staging activations per live site. Zero forbids them. |
| `wplc_stripe_adapter_status` | `src/Compatibility/Requirements.php` | Filters whether the Stripe renewal adapter is usable. |
| `wplc_stripe_mode` | `src/Gateway/StripeRecurringAdapter.php` | Filters the mode the Stripe gateway is considered to run in. |
| `wplc_stripe_site_id` | `src/Gateway/StripeRecurringAdapter.php` | Filters the site identifier sent to Stripe and expected in webhook events. |
| `wplc_stripe_webhook_secrets` | `src/Api/V1/StripeWebhook.php` | Filters the Stripe webhook signing secrets of the current site. |
| `wplc_support_email` | `src/Notifications/Notifications.php` | Filter the support contact named in license emails. Wins over the setting. |
| `wplc_support_url` | `src/Notifications/Notifications.php` | Filter the address of the support page that the customer account and the cancellation page link to. Wins over the setting. |
| `wplc_suppress_renewal_order_email` | `src/Gateway/RenewalOrderEmails.php` | Filters whether a WooCommerce customer mail is suppressed for an automatic renewal order. |
| `wplc_suspend_license_on_order_item_change` | `src/License/LicenseLifecycle.php` | Filters whether licenses are suspended when their order line is removed or its quantity lowered. |
| `wplc_suspend_license_on_order_status` | `src/License/LicenseLifecycle.php` |  |
| `wplc_suspended_license_ends_after_days` | `src/License/EndedLicenses.php` | Filters the days after which a suspended license counts as ended (0 = never). Wins over the setting. |
| `wplc_translation_fallback_locale` | `src/Plugin.php` | Filters the shipped translation used for a locale without its own file (B-120). |
| `wplc_update_check_issues_download` | `src/Api/V1/UpdateService.php` | Filters whether an update check without the `download` parameter creates a download grant. |
| `wplc_update_package_hosts` | `src/Update/PackageStream.php` |  |
| `wplc_update_packages` | `src/Api/V1/UpdateService.php` | Filters the update packages on offer. |
| `wplc_update_product_homepage` | `src/Update/ReleaseRegistry.php` | Filters the homepage shown for a product in the WordPress update details. |
| `wplc_update_product_icons` | `src/Update/ReleaseRegistry.php` | Filters the icons of a product, in the shape WordPress expects: array( '1x' => url, '2x' => url, 'svg' => url ). |
| `wplc_vendor_license_config` | `src/Infrastructure/SelfLicense.php` | Filters where this plugin gets its own updates from (the license server of its vendor). |
| `wplc_verify_url_release_on_publish` | `src/Update/ReleaseRegistry.php` | Filters whether a URL package is downloaded and verified when its release is published. |
| `wplc_withdrawal_consent_enabled` | `src/Billing/WithdrawalConsent.php` | Filters whether the checkout asks for consent to the immediate start of the license. |
| `wplc_withdrawal_consent_required` | `src/Billing/WithdrawalConsent.php` | Filters whether a license can only be bought with the consent to the immediate start. |
| `wplc_withdrawal_consent_text` | `src/Billing/WithdrawalConsent.php` | Filters the wording of the withdrawal consent. Legal wording is the shop owner's decision. |

