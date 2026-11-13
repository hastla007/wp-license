# Caching and reverse proxies

WP License Captain answers every request per license, per installation or per customer. None of its responses may be served from a shared cache. This page lists what to exclude and why. It describes required configuration; it does not verify that a given host or CDN is configured this way.

## What must never be cached

| Path | Why | Exclude from |
|---|---|---|
| `/wp-json/wplc/*` (and `/?rest_route=/wplc/...` on sites without pretty permalinks) | Activation, entitlement and update responses are signed for one installation and one request (nonce and timestamp). A cached response fails signature verification on the client or, worse, tells a site that an expired license is still valid. The Stripe webhook under this prefix must reach PHP every time. | Page cache, CDN/edge cache, reverse-proxy cache, "REST API cache" plugins |
| My Account and all its endpoints, including `/my-account/wplc-licenses/` (the slug depends on the WooCommerce account page) | Shows license keys, activated sites and forms protected by nonces. A cached copy can leak one customer's key to another or make every form fail with an expired nonce. | Page cache, CDN/edge cache |
| `/wp-admin/admin-post.php` | Receives the customer and admin actions (deactivate site, cancel renewal, start renewal, admin changes, CSV export). Every request changes state or returns private data. | Page cache, CDN/edge cache; do not rate-limit it so tightly that legitimate form posts are rejected |
| Cart, checkout and order-pay pages | Standard WooCommerce requirement; renewal payments use the order-pay page. | Page cache, CDN/edge cache |

Rules of thumb for the cache configuration:

- Exclude by path prefix, not by cookie. API clients send no WordPress cookies, so "bypass cache for logged-in users" does not protect `/wp-json/wplc/`.
- Do not cache responses to `POST` and `DELETE` at all. Do not let a CDN convert or retry them.
- Do not strip or rewrite the request headers the API uses for signing, and forward the request body unchanged. Body transformations (minification, "security" rewriting) break the request signature.
- Do not cache error responses (`401`, `403`, `409`, `429`) for these paths either; some CDNs do by default.

## Download URLs are single-use

`POST /wp-json/wplc/v1/updates/check` returns a `package_url` that points to `GET /wp-json/wplc/v1/updates/download/{token}`. Although it is a `GET`, it is not cacheable:

- the token is valid for a short time and is consumed by the first request; the second request for the same URL is rejected by design;
- the response is a redirect to the package host, and the license is checked again at that moment.

Consequences:

- A cache, link scanner, "preload/prefetch" feature, uptime monitor or security proxy that requests the URL first uses the grant up, and the customer's update then fails. Exclude `/wp-json/wplc/v1/updates/download/` from every component that issues its own requests.
- Do not cache the redirect response. A cached redirect would hand the package location to requests without a valid grant.
- Clients must not retry the same URL; they request a new grant with another update check.

## Object cache

A persistent object cache (Redis, Memcached) is supported as far as WordPress and WooCommerce support it. The plugin reads license, activation, nonce and rate-limit state directly from its own tables, not through the object cache, so no cache group needs special handling. Two points still matter:

- Options are cached by WordPress. After restoring a database backup or copying a site, flush the object cache; otherwise stale schema-version or reminder-cursor options can survive the restore.
- Do not enable full-page or fragment caching features of an object-cache plugin for the paths in the table above.

## Reverse proxies and client IP addresses

Rate limits and abuse protection use the client IP address. Behind a load balancer, CDN or reverse proxy, PHP only sees the proxy's address in `REMOTE_ADDR` unless the proxy chain is declared. Then all customers share one rate-limit bucket, or an attacker can spoof a forwarded header to evade limits.

The trusted-proxy handling is configured with two constants in `wp-config.php`:

- `WPLC_TRUSTED_PROXIES` — the proxy addresses or networks whose forwarded header may be believed;
- `WPLC_TRUSTED_PROXY_HEADER` — which forwarded header carries the client address.

This page references them by name only. Their exact format and behaviour belong to the API security documentation ([Security and key operations](SECURITY-OPERATIONS.md) is the place to look and to extend); what matters here is that they must be set whenever a proxy sits in front of the site. Checklist:

- List only proxies you operate or contract. Never trust a forwarded header from arbitrary addresses.
- Make sure the web server cannot be reached directly, bypassing the proxy; otherwise the forwarded header can be forged.
- Terminate TLS at the proxy only if the connection from proxy to origin is also protected, and make sure WordPress knows the request was HTTPS, so generated URLs (pay links, download URLs, account links in emails) use `https://`.

## Verification after changes

Run these checks after changing the cache, CDN or proxy configuration. They need a staging shop; nothing here is verified by the plugin's automated tests.

1. Request an API route twice and compare response headers: no cache hit indicator (`X-Cache: HIT`, `CF-Cache-Status: HIT`, `Age` greater than zero) may appear for `/wp-json/wplc/`.
2. Log in as two different customers in two browsers and open the license page: each sees only their own licenses, and the deactivate and cancel forms work.
3. Run an update check from a test site and download the package once. A second request for the same download URL must fail.
4. Trigger more requests than the rate limit allows from one client and confirm that a second client behind the same proxy is not blocked.
