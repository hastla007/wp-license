# Product plugin client integration

Use `WPLC\\Client\\LicenseClient` as the shared protocol client in CouponMaster, PassMaster, AffiliateMaster, and future product plugins. Each product uses a distinct stable `product_key` and option key.

## Integration

1. Include the shared `src/Client/LicenseClient.php` file from the product release or vendor it under the product's namespace.
2. Construct the client with the HTTPS license-server URL, product key, plugin version, and a product-specific option key.
3. Provide an admin license-key field and call `activate()` on submission. The client stores only the installation UUID and per-installation secret after verifying the server signature; it does not persist the customer's license key.
4. Call `check_entitlements()` at a controlled interval and cache the signed result locally. Gate premium features on explicit entitlement keys; keep the plugin's core behavior available when the licensing server is temporarily unavailable.
5. Use `check_update()` to obtain metadata, verify the response signature, download the archive, verify SHA-256, and pass the package through WordPress's plugin upgrader.
6. Call `deactivate()` when the customer removes the site activation. Always handle network errors without breaking frontend requests.

## Client contract notes

The shared class handles HMAC request headers and response verification. Product plugins remain responsible for their settings screen, entitlement-to-feature mapping, cache expiry, update transient integration, and safe ZIP installation. Store activation credentials with non-autoloaded options and never log or expose the secret. Staging activation requires the production installation UUID supplied by the owning live site.

This milestone provides the reusable client implementation and integration contract. Product-specific admin screens and updater hooks are separate changes in each product repository.
