# Extension points

Actions and filters of the license server plugin (A-134). The tables are generated from `src/` with `php bin/list-hooks.php` (summary: the docblock above the call); `tests/Contract/ExtensionPointsDocTest.php` fails when a hook in the code is missing here. The bundled client library has its own hooks with the add-on's prefix, see [CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md).

## Conventions

- **Actions** are named `wplc/<event>` (past tense: something happened). Two older actions keep their underscore names because cron and queued jobs are stored under them: `wplc_daily_lifecycle` and `wplc_release_package_missing`.
- **Filters** are named `wplc_<thing>`. Numeric filters are clamped where a wrong value would be dangerous (for example `wplc_renewal_grace_days`, 1 to 90 days).
- Listeners run inside the request that triggered them. A listener that throws can break that request unless the documentation of the hook says errors are ignored; queued jobs are isolated by `DailyRunner`.
- **`wplc/license_issued` passes the plaintext license key** (arguments: license ID, key, order ID). It exists so the key email can be sent; the key is never stored in plaintext. Listeners that do not need the key must use `wplc/license_issued_followup` (license ID, order ID), which also runs when the issuing request ended early. Never log or store the key argument.
- Hooks not listed here are internal (WordPress, WooCommerce or Action Scheduler hooks the plugin listens to) and can change without notice.

## Actions

| Hook | Where | Summary |
| --- | --- | --- |
| `wplc/activation_transferred` | `src/License/ActivationTransfer.php` | Fires after a customer moved a site activation to another of their licenses (A-22). |
| `wplc/api_request_rejected` | `src/Api/V1/ApiLog.php` | Fires for every error answer of the license API. |
| `wplc/auto_renewal_disabled` | `src/Maintenance/RenewalSwitch.php` | Fires after automatic renewal was switched off because its basis is gone. |
| `wplc/cancellation_scheduled` | `src/Notifications/Notifications.php` |  |
| `wplc/chargeback` | `src/License/LicenseLifecycle.php` | Fires when a payment for a license was disputed. |
| `wplc/dispute_closed` | `src/Gateway/StripeEventHandler.php` | Fires when a dispute about a license payment was closed without being won. |
| `wplc/dispute_funds_reinstated` | `src/Gateway/StripeEventHandler.php` | Fires when Stripe returned the money of a dispute. |
| `wplc/dispute_won` | `src/Gateway/StripeEventHandler.php` | Fires when a dispute about a license payment was decided for the shop. |
| `wplc/gap_detected` | `src/Maintenance/GapGuard.php` | Fires when a processing gap was recorded. |
| `wplc/health_alert` | `src/Admin/Diagnostics.php` |  |
| `wplc/job_failed` | `src/Maintenance/DailyRunner.php` | Fires after a scheduled job threw. Errors of listeners are ignored. |
| `wplc/license_entitlements_synced` | `src/License/EntitlementSync.php` | Fires after the entitlement snapshot of a license was brought up to its plan's current settings. |
| `wplc/license_issuance_failed` | `src/License/LicenseIssuer.php` | Fires when a paid order could not get all its licenses. |
| `wplc/license_issued` | `src/License/LicenseIssuer.php` | Fires after a license is issued. The key is derived and never persisted in plaintext. |
| `wplc/license_issued_followup` | `src/License/LicenseIssuer.php` | Fires once the follow-up work for an issued license is done. No key is passed. |
| `wplc/license_key_reissued` | `src/Admin/SupportActions.php` | Fires after a license received a new key. |
| `wplc/license_key_resend_requested` | `src/Admin/SupportActions.php` | Fires when an administrator asks for the key email to be sent again. |
| `wplc/license_needs_review` | `src/License/LicenseLifecycle.php` | Fires when a license was suspended and needs a decision by the shop. |
| `wplc/license_owner_changed` | `src/Admin/SupportActions.php` | Fires after a license was handed over to another user. |
| `wplc/license_plan_changed` | `src/License/PlanChange.php` | Fires after the plan of a license changed (upgrade, downgrade at renewal, administrator change, revert). |
| `wplc/license_status_changed` | `src/License/LicenseLifecycle.php` | Fires after every successful license status transition and renewal. |
| `wplc/loaded` | `src/Plugin.php` | Fires after WP License Captain has confirmed its WooCommerce dependency. |
| `wplc/renewal_blocked` | `src/Billing/RenewalProcessor.php` | Fires when a renewal could not be attempted because of a shop-side problem. |
| `wplc/renewal_disabled_token_deleted` | `src/Maintenance/Orphans.php` | Fires after automatic renewal was switched off because the saved payment method was deleted. |
| `wplc/renewal_lock_unavailable` | `src/Billing/RenewalProcessor.php` | Fires when the lock of a renewal could repeatedly not be taken. |
| `wplc/renewal_needs_review` | `src/Billing/RenewalOrder.php` | Fires when a paid renewal order could not be applied to its license. |
| `wplc/renewal_order_voided` | `src/Gateway/StripeRecurringAdapter.php` | Fires when a paid renewal order was cancelled, i.e. its payment was taken back. |
| `wplc/renewal_payment_method_adopted` | `src/Gateway/StripeRecurringAdapter.php` | Fires after a license was moved to the card a renewal order was paid with. |
| `wplc/renewal_payment_method_missing` | `src/Gateway/PaymentReference.php` | Fires when a due renewal has no usable payment method. |
| `wplc/renewal_unavailable_for_order` | `src/Gateway/StripeRecurringAdapter.php` | Fires when a purchase with renewal consent has no payment method that can renew. |
| `wplc/renewals_disabled_for_deleted_token` | `src/Maintenance/Orphans.php` | Fires once per deleted payment method after automatic renewal was switched off for its licenses. |
| `wplc/response_signing_key_created` | `src/Security/ResponseSigner.php` | Fires once when the server creates its Ed25519 response signing key. |
| `wplc/response_signing_key_unavailable` | `src/Security/ResponseSigner.php` | Fires when responses cannot be signed with the server key. |
| `wplc/review_case_changed` | `src/Billing/RenewalReview.php` |  |
| `wplc/stripe_early_fraud_warning` | `src/Gateway/StripeEventHandler.php` | Fires when Stripe reports an early fraud warning for a license payment. |
| `wplc/stripe_webhook_received` | `src/Api/V1/StripeWebhook.php` |  |
| `wplc_daily_lifecycle` | `src/Maintenance/DailyRunner.php` |  |
| `wplc_release_package_missing` | `src/Update/ReleaseRegistry.php` | Fires when a published release cannot be offered because its file is missing on this server (A-111). |

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
| `wplc_catalog_csv_time_budget` | `src/Admin/CatalogCsv.php` | Filters how many seconds one catalog CSV import may run before it stops and reports where. |
| `wplc_client_ip` | `src/Api/V1/ClientIp.php` | Filter the client address used for anonymous rate limiting. |
| `wplc_customer_download_hourly_limit` | `src/Update/CustomerDownload.php` |  |
| `wplc_customer_download_version_choices` | `src/Update/CustomerDownload.php` | Filters how many versions the customer can choose from. |
| `wplc_daily_run_split` | `src/Maintenance/DailyRunner.php` | Filter whether the daily run queues every job as its own Action Scheduler action. |
| `wplc_development_site_limit` | `src/Api/V1/ActivationService.php` | Filter the number of development activations per license. Zero forbids them. |
| `wplc_disable_renewal_on_token_deletion` | `src/Maintenance/Orphans.php` | Filter whether deleting a payment method switches automatic renewal off. |
| `wplc_download_grant_retention_days` | `src/Maintenance/Retention.php` |  |
| `wplc_download_reuse_seconds` | `src/Update/PackageStream.php` | Filters how long a download token stays valid after its first use. |
| `wplc_entitlement_catalog` | `src/Catalog/EntitlementCatalog.php` | Filters the catalog of known boolean entitlements offered in the product editor. |
| `wplc_expired_license_download` | `src/Update/CustomerDownload.php` | Filters whether a license whose paid period ended may still download |
| `wplc_expiry_time_budget` | `src/License/LicenseLifecycle.php` |  |
| `wplc_gap_due_margin_days` | `src/Maintenance/GapGuard.php` | Filter how many days before the gap a due date is still protected. |
| `wplc_gap_hold_days` | `src/Maintenance/GapGuard.php` | Filter the longest hold after a gap in days. |
| `wplc_gap_reconcile_days` | `src/Maintenance/GapReconciler.php` | Filter how many days of orders and refunds are reconciled. |
| `wplc_install_steps` | `src/Notifications/Notifications.php` |  |
| `wplc_is_local_development_url` | `src/Api/V1/ActivationService.php` | Allow a shop owner to explicitly approve a custom development hostname. |
| `wplc_is_staging_host` | `src/Api/V1/SiteUrl.php` | Filter whether a host is accepted as the staging copy of a live site. |
| `wplc_issue_after_completion_gateways` | `src/License/LicenseIssuer.php` | Filters the payment methods whose orders only get their licenses once the order is completed. |
| `wplc_job_runs_while_billing_paused` | `src/Maintenance/DailyRunner.php` | Filter whether a job runs although billing is paused. |
| `wplc_known_product_keys` | `src/Catalog/EntitlementCatalog.php` | Filter the product keys suggested in the product editor. |
| `wplc_license_email_locale` | `src/Notifications/PurchaseLocale.php` | Filter the locale used for the emails of one license. |
| `wplc_license_email_recipient` | `src/Notifications/Emails/LicenseEmail.php` | Filter the recipient of the emails of one license. |
| `wplc_license_lock_timeout` | `src/License/LicenseLifecycle.php` | Seconds a lifecycle change waits for another request that changes the same license. |
| `wplc_license_owner_for_guest_order` | `src/License/LicenseIssuer.php` | Filters the owner of the licenses of an order without a customer account. |
| `wplc_lock_self_test` | `src/Admin/Diagnostics.php` | Lets the package that owns the lock helper answer the self-test with its own implementation. |
| `wplc_log_api_rejection` | `src/Api/V1/ApiLog.php` | Filter whether this rejection is written to the log. |
| `wplc_log_debug` | `src/Infrastructure/Log.php` | Filters whether the plugin writes debug lines to the WooCommerce log (A-133). |
| `wplc_new_activations_per_hour` | `src/Api/V1/ActivationService.php` | Filter how many new installations one license may activate per hour. |
| `wplc_offline_cache_days` | `src/Api/V1/EntitlementService.php` | Filter the number of days a client may rely on a signed snapshot offline. |
| `wplc_package_max_upload_bytes` | `src/Update/LocalPackageStorage.php` |  |
| `wplc_package_offload_header` | `src/Update/PackageStream.php` | Filters a header that hands the file to the web server instead of PHP (B-25). |
| `wplc_package_storage_dir` | `src/Update/LocalPackageStorage.php` | Filters the private package directory, e.g. to move it outside the web root. |
| `wplc_package_storage_probe` | `src/Update/PackageStorageCheck.php` | Filters whether the package directory may be probed over HTTP. |
| `wplc_payment_token_id_for_order` | `src/License/LicenseIssuer.php` |  |
| `wplc_plan_label` | `src/Notifications/Notifications.php` | Filter the plan name shown to customers. |
| `wplc_pricing_table_plans` | `src/Account/PricingTable.php` | Filters the plans of the pricing table (block `wplc/pricing`, shortcode `[wplc_pricing]`). |
| `wplc_product_docs_url` | `src/Notifications/Notifications.php` | Filter the documentation URL shown with the installation steps. |
| `wplc_public_cancel_hourly_limit` | `src/Account/PublicCancellation.php` | Filter how many public cancellation requests are accepted per hour, |
| `wplc_public_cancel_order_id` | `src/Account/PublicCancellation.php` | Filter the order ID for an order number typed on the public cancellation |
| `wplc_recurring_gateway_adapters` | `src/Gateway/Registry.php` |  |
| `wplc_registrable_domain` | `src/Api/V1/SiteUrl.php` | Filter the registrable domain derived from a host (for example with a public-suffix list). |
| `wplc_release_product_slug` | `src/Update/ReleaseRegistry.php` | Filters the plugin folder a product's packages must contain. |
| `wplc_reminder_lead_days` | `src/Notifications/Notifications.php` | Filter the days before paid_until on which expiry reminders are sent. |
| `wplc_reminder_time_budget` | `src/Notifications/Notifications.php` | Filter the seconds a reminder run may take before it continues in a later run. |
| `wplc_renew_instead_notice` | `src/Billing/RenewInsteadNotice.php` | Filter the "Renew instead" notice for one license in the cart (A-22). |
| `wplc_renewal_charge_allowed` | `src/Billing/RenewalProcessor.php` | Filters whether a due renewal may be charged now (B-41). |
| `wplc_renewal_display_amount` | `src/Notifications/RenewalTerms.php` | Filter the displayed amount of the next renewal. |
| `wplc_renewal_grace_days` | `src/License/LicenseLifecycle.php` | Filters the renewal grace period in days (A-135). |
| `wplc_renewal_notice_lead_days` | `src/Notifications/Notifications.php` | Filter the lead time of the automatic renewal notice. Zero disables the |
| `wplc_renewal_requires_advance_notice` | `src/Notifications/Notifications.php` | Filter whether an automatic charge needs the logged advance notice. |
| `wplc_renewal_retry_days` | `src/Billing/RenewalProcessor.php` | Filters the retry schedule of failed renewal charges (A-135). |
| `wplc_require_package_resolver` | `src/Api/V1/UpdateService.php` | Filters whether packages may only be delivered through a resolver. |
| `wplc_resolve_update_package_url` | `src/Update/ReleaseRegistry.php` |  |
| `wplc_retention_days` | `src/Maintenance/Retention.php` | Filter the retention periods in days. |
| `wplc_retention_time_budget` | `src/Maintenance/Retention.php` | Filter the seconds one retention run may take. |
| `wplc_review_payment_reference` | `src/Billing/RenewalReview.php` | Filters the transaction ID stored when a reviewed renewal order is booked as paid. |
| `wplc_schema_can_migrate` | `src/Infrastructure/Database/Schema.php` | Filter whether this request may run a schema migration. |
| `wplc_self_update_check_enabled` | `src/Infrastructure/SelfUpdateCheck.php` | Filters whether the plugin looks for new releases of itself (A-42). |
| `wplc_self_update_release_api` | `src/Infrastructure/SelfUpdateCheck.php` | Filters the API address of the latest release (A-42). |
| `wplc_show_license_term` | `src/Catalog/TermDisplay.php` | Filters whether the license term is appended to the price of a license product. |
| `wplc_stripe_adapter_status` | `src/Compatibility/Requirements.php` | Filters whether the Stripe renewal adapter is usable. |
| `wplc_stripe_mode` | `src/Gateway/StripeRecurringAdapter.php` | Filters the mode the Stripe gateway is considered to run in. |
| `wplc_stripe_site_id` | `src/Gateway/StripeRecurringAdapter.php` | Filters the site identifier sent to Stripe and expected in webhook events. |
| `wplc_stripe_webhook_secrets` | `src/Api/V1/StripeWebhook.php` | Filters the Stripe webhook signing secrets of the current site. |
| `wplc_support_email` | `src/Notifications/Notifications.php` | Filter the support contact named in license emails. |
| `wplc_suppress_renewal_order_email` | `src/Gateway/RenewalOrderEmails.php` | Filters whether a WooCommerce customer mail is suppressed for an automatic renewal order. |
| `wplc_suspend_license_on_order_item_change` | `src/License/LicenseLifecycle.php` | Filters whether licenses are suspended when their order line is removed or its quantity lowered. |
| `wplc_suspend_license_on_order_status` | `src/License/LicenseLifecycle.php` |  |
| `wplc_translation_fallback_locale` | `src/Plugin.php` | Filters the shipped translation used for a locale without its own file (B-120). |
| `wplc_update_check_issues_download` | `src/Api/V1/UpdateService.php` | Filters whether an update check without the `download` parameter creates a download grant. |
| `wplc_update_package_hosts` | `src/Update/PackageStream.php` |  |
| `wplc_update_packages` | `src/Api/V1/UpdateService.php` | Filters the update packages on offer. |
| `wplc_update_product_homepage` | `src/Update/ReleaseRegistry.php` | Filters the homepage shown for a product in the WordPress update details. |
| `wplc_update_product_icons` | `src/Update/ReleaseRegistry.php` | Filters the icons of a product, in the shape WordPress expects: array( '1x' => url, '2x' => url, 'svg' => url ). |
| `wplc_verify_url_release_on_publish` | `src/Update/ReleaseRegistry.php` | Filters whether a URL package is downloaded and verified when its release is published. |
| `wplc_withdrawal_consent_enabled` | `src/Billing/WithdrawalConsent.php` | Filters whether the checkout asks for consent to the immediate start of the license. |
| `wplc_withdrawal_consent_required` | `src/Billing/WithdrawalConsent.php` | Filters whether a license can only be bought with the consent to the immediate start. |
| `wplc_withdrawal_consent_text` | `src/Billing/WithdrawalConsent.php` | Filters the wording of the withdrawal consent. Legal wording is the shop owner's decision. |

