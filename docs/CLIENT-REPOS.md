# Product client integration status

## Repository and compatibility snapshot

| Product | Repository | Declared minimum PHP | License client branch |
| --- | --- | ---: | --- |
| Voucher Captain | [wp-couponmaster](https://github.com/hastla007/wp-couponmaster) | 8.1 | codex/wplc-client |
| WP Pass Captain | [wp-passmaster](https://github.com/hastla007/wp-passmaster) | 8.2 | codex/wplc-client |
| WP Affiliate Captain | [wpaffiliatemaster](https://github.com/hastla007/wpaffiliatemaster) | 7.4 | codex/wplc-client |

The shared client source is written to PHP 7.4 syntax so the Affiliate Captain minimum remains supported.

## Open activation PRs

- Voucher Captain: [PR #10](https://github.com/hastla007/wp-couponmaster/pull/10)
- WP Pass Captain: [PR #20](https://github.com/hastla007/wp-passmaster/pull/20)
- WP Affiliate Captain: [PR #9](https://github.com/hastla007/wpaffiliatemaster/pull/9)

Each adds a Settings → License screen with a configurable HTTPS license-server URL, one-time license key entry, activation status, entitlement display, and site deactivation. Product plugins persist only the installation UUID, activation ID, and installation secret in a non-autoloaded option; they do not retain the entered license key.

## Not included yet

- Product-specific entitlement-to-feature maps and new premium feature gates.
- Offline signed-response cache with an explicit maximum age and behavior during license-server outages.
- WordPress update transient integration, package download and ZIP checksum validation.
- Staging-site activation UX and pairing with a live installation.
- A common release mechanism for the vendored SDK source.

The activation PRs are the first integration slice. Existing product behavior remains available; they do not activate premium feature gates by themselves.
