# Changelog

## Unreleased

- Site binding: authenticated requests may carry `site_url`; a credential used from another website gets `409 site_mismatch`. Signed entitlement data includes the activation's `site_url`. Requests without `site_url` keep working.

## 0.1.0 — Engineering preview

- Added WooCommerce catalog mapping and order-based license issuance.
- Added license lifecycle, activation limits, customer account portal, and administrative controls.
- Added authenticated REST API, renewal adapter contract, premium update delivery, and shared client SDK.
- Not production-ready; see docs/RELEASE-READINESS.md.
