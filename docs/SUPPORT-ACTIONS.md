# Support: releasing an activation

Shop managers with `manage_woocommerce` can release one active site slot from the WooCommerce license list. The action requires a per-activation WordPress nonce and a reason, changes only the active activation row to `deactivated`, and writes an audit event with the site URL, actor, and reason. It does not delete the license or expose installation secrets.

The affected site will no longer authenticate. If it is still available, the customer can deactivate through the product's license settings first. For a lost/decommissioned site, support can release the slot and the customer can activate the license on the replacement site using the key in My Account. Confirm the customer/order identity before release and tell the customer the old site loses update/entitlement access.
