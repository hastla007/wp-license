# 11. Troubleshooting and FAQ

Start with **WooCommerce → License diagnostics**: every row with a problem names the cause and what to do. The WooCommerce log (WooCommerce → Status → Logs, sources `wp-license` and `wplc-api`) and the license audit log tell what happened when.

## Activation and license checks

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| The add-on says the license server answered with an HTML page / "blocked by firewall" | A CDN, WAF or security plugin challenges the request before WordPress | Let `/wp-json/wplc/v1/*` pass without challenge; test with the `curl` check in chapter 9. |
| `wplc_server_signature_invalid` on every site | The response signing key changed (restore, lost option, `--rotate --force`), or the add-on pins another public key | Compare `wp wplc signing-key --field=public_key` with the key in the add-on. Restore the old key (constant or option) or ship an add-on release with the new key. |
| Answers lose their signature, diagnostics report the signing key as `undecryptable` or `constant_invalid` | Encryption key ring changed, or an invalid `WPLC_RESPONSE_SIGNING_KEY` | Put the old encryption key back and run `wp wplc rotate-installation-secrets --apply`; fix the constant. Do not use `--rotate --force`. |
| Many sites fail with `401 installation_secret_invalid` | An encryption key left the ring too early, or one web node lacks the current constants | Restore the ring entry on every node; finish the rotation until exit code 0. |
| Customers see "Currently unavailable" instead of their key | License-key secret or salts changed, or a ring entry was renamed | Restore the old secret under its old ID, or use `wp wplc recover-license-key-ids`. The diagnostics row "License keys verifiable with the current secret" shows the scope. |
| Paid orders issue no license | The current key ID names no ring entry (log: `License not issued: the current license key ID ... has no secret`), or the product has no plan | Fix `wp-config.php` or the product mapping, then "Issue missing licenses for an order" or `wp wplc repair issue-order <id>`. |
| `site_limit` (409) | All live sites of the license are in use | The customer releases a site in the account or in the add-on; or you with `wp wplc activation release`. A lower limit after a plan change never deactivates sites. |
| `rate_limited` (429) for many customers at once | The shop is behind a proxy/CDN without `WPLC_TRUSTED_PROXY_HEADER` and `WPLC_TRUSTED_PROXIES` | Configure both (chapter 9). |
| `503 retry_later` on every activation | The database refuses named locks (connection proxy, multi-writer cluster) | Check the diagnostics row "Database locks"; route the connection to a primary without multiplexing. |
| `request_expired` | The clock of the customer server or of the shop is off by more than five minutes | The client corrects small offsets itself; check NTP on both sides. Diagnostics compares web server and database time. |
| The add-on shows `stale` with reason `clock_ahead` | The customer site's clock runs ahead | Fix the time of the customer's web server. |
| The add-on shows `site_changed` | The site was copied or moved | On a copy: activate the copy (as staging with `WP_ENVIRONMENT_TYPE=staging`). After a move: "transfer the activation" on the license screen. |
| A staging site is counted as a live site | Its host does not look like a staging host of the live site | Use a subdomain of the live site, a `staging` label or a hosting provider's staging domain; or filter `wplc_is_staging_host`. |

## Updates and downloads

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| The update is not offered | Release not published, site does not meet `requires_php`/`requires_wp`/`requires_core`, license without access, wrong channel | Check the releases screen; the plugin row on the site names the reason. |
| Download fails right after "Update" | A cache, scanner or proxy used the one-time link first | Exclude `/wp-json/wplc/v1/updates/download/` and `/wp-json/wplc/v1/packages/` from such components; or allow a short reuse window (`wplc_download_reuse_seconds`). |
| `wplc_update_checksum` | The package changed after it was registered (overwritten object) | Never overwrite packages; withdraw and publish a new version. |
| Update checks answer 503 `package_configuration_invalid` | A URL release points to a host that is no longer allowed (often after a domain move) | Add the host under trusted package hosts or publish a new release. |
| "The uploaded package … is missing on this server" | Several web nodes without shared storage, or a restore without the package directory | Share the directory between nodes; restore the files. |
| A URL release is never delivered | No resolver returns a pre-signed URL | Add a `wplc_resolve_update_package_url` resolver (chapter 5). |
| Object storage downloads fail with 403 at the storage | Wrong key or region, the key may not read the object, or the server clock is off | Run `wp wplc storage check --key=<key>`; fix the `WPLC_S3_*` constants or the key's permissions; check NTP. |
| An `s3://` package location is refused | Object storage not configured, another bucket than `WPLC_S3_BUCKET`, or a key with `.`/`..` segments | Check the diagnostics row "Object storage (S3-compatible)" and the location. |
| The upload is refused | More than one top-level folder, wrong folder name, `Version:` header differs from the release version, PHP `zip` missing, file too large | Fix the package; raise `upload_max_filesize`/`post_max_size` or `wplc_package_max_upload_bytes`; or use `wp wplc release create --zip=…`. |
| "This product key is not registered" when uploading | No WooCommerce product carries the product key yet | Create the license product first (chapter 4). |
| The package directory is "Publicly readable" | nginx ignores `.htaccess` | Set `WPLC_PACKAGE_STORAGE_DIR` or add the deny rule (chapter 5). |

## Renewals and Stripe

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Automatic renewal is not offered at checkout | Stripe gateway missing, too old or disabled, saved cards off, or a block checkout on a WooCommerce version without additional fields | The settings row "Automatic renewal (Stripe)" names the reason. |
| Renewals are not charged, audit shows `renewal_blocked` with `renewal_notice_not_sent` | The advance-notice email was switched off or refused by the mailer | Enable "Upcoming automatic renewal" and fix the mail transport. |
| Nothing is charged or expired at all | Billing is paused (staging fingerprint, `WPLC_PAUSE_BILLING`, environment type) or WP-Cron does not run | Answer the billing-pause notice; set up a system cron; check "Last daily run" on the diagnostics page. |
| Stripe webhook deliveries fail with 400 `wplc_stripe_signature_invalid` | The secret does not belong to this endpoint or mode | Copy the signing secret of exactly this endpoint; use `WPLC_STRIPE_WEBHOOK_SECRET_TEST` for the test-mode endpoint. |
| Webhook answers 503 | No secret configured for this site | Define the constant (on a network: an entry in `WPLC_STRIPE_WEBHOOK_SECRETS`). |
| Events stay "pending" | The background queue does not run | Check Action Scheduler (WooCommerce → Status → Scheduled Actions, group `wplc`); `wp wplc repair webhook <inbox-id>`. |
| A case under "Renewals to review" | Money may have been taken without extending the license | Compare with the Stripe dashboard; book, mark as not paid, or refund and close (chapter 8). |

## FAQ

**Is the license key in the emails?** Not by default. You can enable it for the "License issued" email; email is not a secure channel.

**Can a customer use one license on a staging site?** Yes: one staging site per live site is free, and development activations on local addresses do not use a live slot (capped at twice the live-site limit, at least 5; filter `wplc_development_site_limit`). Both numbers and the lists of staging and local addresses are settings under **License settings → Customer sites** (chapter 13).

**What happens when a license expires?** The add-on switches its premium features off at the end of the signed offline window. Data the customer created stays, and the free core plugin is not affected. Updates stop.

**What happens when my license server is unreachable?** Installed add-ons keep working on their signed snapshot for up to 14 days (never beyond the end of access), then switch premium features off until the server answers again.

**Does deactivating the plugin break customer sites?** While the plugin is off the API answers 404; add-ons keep their snapshot until its deadline. On the next activation missed orders and renewals are caught up.

**Can I change a customer's plan or extend a license without payment?** Yes, on the license detail screen ("Change plan", "Change term"), with a reason; both are audited.

**Can customers renew with PayPal?** Manually, yes (any WooCommerce payment method on the pay page). Automatic renewal exists for Stripe cards only.

**Does the plugin work with WooCommerce Subscriptions?** It renews licenses itself; do not map subscription products to license plans (the mapping is refused).

**Which languages does the server speak?** English and German (informal `de_DE` and formal `de_DE_formal`; other German locales fall back to formal German). API error messages are fixed English texts; your add-on shows translated texts by error code.
