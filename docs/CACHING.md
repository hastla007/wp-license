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
- Do not cache error responses (`401`, `403`, `404`, `409`, `429`, `503`) for these paths either; some CDNs do by default. Do not rely on response headers here: not every error response of the API carries `Cache-Control: no-store`, so the exclusion has to be a path rule in the cache.
- `/wp-admin/admin-post.php` is also used by **customers** (deactivate a site, cancel or resume renewal, start a renewal, download). If wp-admin is protected with HTTP basic auth, an IP allow-list or a WAF rule, exempt `admin-post.php` in the same way as `admin-ajax.php`, otherwise these customer actions fail.

## Bot protection and web application firewalls

Every API call comes from a customer's web server, not from a browser: no cookies, no JavaScript, a PHP user agent, often a data-centre IP address. That is exactly what bot protection is built to stop. A blocked request does not reach WordPress, so nothing appears in the plugin's logs; the customer only sees "license check failed", and a challenge page (HTML with status 200 or 403) is not a valid API response.

Configure every layer in front of the shop so that these requests pass without a challenge:

| Path | Method | Who calls it |
|---|---|---|
| `/wp-json/wplc/v1/*` (and `/?rest_route=/wplc/v1/...`) | `GET`, `POST`, `DELETE` | Customer sites (activation, entitlement check, update check, downloads) and Stripe (`/wp-json/wplc/v1/webhooks/stripe`) |

- **Cloudflare**: add a WAF custom rule or configuration rule for the path that skips Bot Fight Mode / Super Bot Fight Mode, Browser Integrity Check, managed challenges and "Under Attack" mode. Bot Fight Mode on the free plan cannot be skipped per path; switch it off for the zone or put the API on a host name without it. Do not enable Rocket Loader, email obfuscation or HTML rewriting for the path.
- **Security plugins** (Wordfence, iThemes/Solid Security, All-In-One Security, "disable REST API" plugins): do not restrict the REST API to logged-in users for the `wplc/v1` namespace, do not block requests without a browser user agent, and do not block the `DELETE` method. Allow-list the namespace where the plugin offers it.
- **Host firewalls / ModSecurity**: signed requests carry JSON bodies and custom headers; rules against "missing Accept header", "unknown user agent" or "data-centre IP ranges" produce false positives. Exempt the path.
- **Rate limiting at the edge**: many customer sites behind one hosting company share few IP addresses. Keep edge limits for the path well above the plugin's own limits (30 activation attempts per minute and address), or leave rate limiting to the plugin.
- **Geo blocking**: customer servers are located where the hosting company is, not where the customer is.
- **Stripe**: webhook deliveries come from Stripe's published IP ranges and must reach PHP unchallenged and with an unmodified body.

Check after every change of CDN, WAF or security plugin ("activation from a foreign server"):

1. From a server outside your own network (a small VPS, not your office line and not a browser), run `curl -i -X POST https://<shop>/wp-json/wplc/v1/activations -H 'Content-Type: application/json' -d '{}'`.
2. The answer must be JSON from WordPress with an API error code (for example a 4xx with `"code"`), not an HTML page, not a redirect and not a challenge. A `Server: cloudflare` response with `cf-mitigated: challenge`, an HTML body or a 403 without JSON means the request was stopped in front of WordPress.
3. Repeat the request ten times within a few seconds; the answers must stay JSON.
4. Activate a test license from a real test site hosted at a typical hosting company, run an update check and download the package.

## Download URLs are single-use

Two `GET` routes deliver packages. Although they are `GET`, neither is cacheable:

| Route | Used for | Behaviour |
|---|---|---|
| `GET /wp-json/wplc/v1/updates/download/{token}` | The `package_url` returned by `POST /wp-json/wplc/v1/updates/check` | The token is valid for a short time and is consumed by the first request. The license is checked again, then the response redirects to the package: to the package host for releases stored by URL, or to the stream route below for packages uploaded to the shop |
| `GET /wp-json/wplc/v1/packages/{token}` | Packages stored on the shop itself (update downloads and downloads from the customer account) | The 64-character token is the only credential, is valid for five minutes and is consumed by the first request. PHP streams the ZIP file itself; the response can be many megabytes and occupies a PHP worker for the whole transfer |

Consequences:

- A cache, link scanner, "preload/prefetch" feature, uptime monitor, antivirus proxy or security proxy that requests one of these URLs first uses the grant up, and the customer's download then fails. Exclude `/wp-json/wplc/v1/updates/download/` and `/wp-json/wplc/v1/packages/` from every component that issues its own requests.
- Do not cache either response. A cached redirect or a cached ZIP would hand the package to requests without a valid grant.
- Do not buffer, compress, transform or scan the stream route at the edge (no "optimisation", no on-the-fly gzip of the ZIP, no response size limit below the package size), and set proxy read timeouts long enough for a slow customer server to fetch the largest package.
- The tokens are part of the URL path. Do not write full request URLs of these two routes to shared or long-lived access logs, analytics or error trackers; if they are logged, they are worthless after the first use or after a few minutes.
- Clients must not retry the same URL; they request a new grant with another update check.

## Object cache

A persistent object cache (Redis, Memcached) is supported as far as WordPress and WooCommerce support it. The plugin reads license, activation, nonce and rate-limit state directly from its own tables, not through the object cache, so no cache group needs special handling. Two points still matter:

- Options are cached by WordPress. After restoring a database backup or copying a site, flush the object cache; otherwise stale options (settings, the installation fingerprint of the staging guard, the reminder cursor) can survive the restore. A stale cached schema version alone is detected and corrected by the plugin without running a migration.
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
3. Run an update check from a test site and download the package once. A second request for the same download URL must fail. Do the same for a package uploaded to the shop (stream route) and compare the SHA-256 of the downloaded file with the release.
4. Trigger more requests than the rate limit allows from one client and confirm that a second client behind the same proxy is not blocked.
