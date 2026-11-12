# WP License Captain

WP License Captain is planned as a WooCommerce plugin that issues and validates licenses for WP Affiliate Captain, Voucher Captain, and WP Pass Captain.

> **Development status:** early scaffold; not ready for production or license sales.

## Requirements

- WordPress 6.5 or later
- WooCommerce active
- PHP 7.4 or later

The product client plugins have their own minimum PHP versions. Those must be audited before client code is shared or released.

## Current scope

The current branch contains the plugin bootstrap and an implementation plan. It does not yet issue licenses, process renewals, validate activations, or provide premium updates.

See the [implementation plan](docs/IMPLEMENTATION-PLAN.md) for the ordered engineering steps and acceptance criteria.

## Development

The plugin uses the `WPLC\\` PHP namespace and loads its bootstrap from `wp-license.php`. Composer's PSR-4 mapping is provided for future classes; no runtime Composer packages are required by the current scaffold.

## Licensing and privacy

WP License Captain is intended for license and update entitlement management. It must not store card numbers or CVV values and must not transmit customer order, voucher, ticket, or affiliate-user data from the product client sites.
