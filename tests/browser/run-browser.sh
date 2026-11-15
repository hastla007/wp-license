#!/usr/bin/env bash
#
# Browser acceptance pass (T-130, automated part) against the E2E shop.
#
# Needs a site set up by tests/e2e/bin/run-e2e.sh (it keeps the database). Copies the
# current plugin into the site, starts the PHP server, prepares the browser data
# (tests/browser/fixtures.php) and runs tests/browser/acceptance.js with Playwright.
#
# Usage: tests/browser/run-browser.sh
#
# Environment (all optional):
#   WPLC_E2E_SITE          site directory (default: ${TMPDIR:-/tmp}/wplc-e2e-site), as for run-e2e.sh
#   WPLC_E2E_PORT          port of the PHP server (default: 8765)
#   WPLC_BROWSER_OUT       screenshots and report (default: $WPLC_E2E_SITE/.browser)
#   WPLC_BROWSER_PROXY_PORT  port of the local TLS proxy for https://shop.test (default: 8766)
#
# Exit code: 0 = every check passed, 1 = a check failed, 2 = setup failed.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"
E2E_DIR="$PLUGIN_DIR/tests/e2e"
TMP_BASE="${TMPDIR:-/tmp}"
SITE="${WPLC_E2E_SITE:-${TMP_BASE%/}/wplc-e2e-site}"
PORT="${WPLC_E2E_PORT:-8765}"
OUT="${WPLC_BROWSER_OUT:-$SITE/.browser}"
WPCLI="$SITE/.e2e-tools/wp-cli.phar"
WC_DIR="$SITE/wp-content/plugins/woocommerce"

die() {
	echo "SETUP FAILED: $*" >&2
	exit 2
}

[ -f "$SITE/wp-config.php" ] && [ -s "$WPCLI" ] || die "no E2E site in $SITE; run tests/e2e/bin/run-e2e.sh first"
command -v node >/dev/null 2>&1 || die "node is missing"
( cd "$HERE" && node -e "require('playwright')" ) >/dev/null 2>&1 || die "the Node module playwright is missing (npm i -g playwright && npx playwright install chromium)"
command -v openssl >/dev/null 2>&1 || die "openssl is missing (the TLS proxy needs a throw-away certificate)"

wp() {
	php -d memory_limit=1G -d display_errors=stderr "$WPCLI" --path="$SITE" --allow-root "$@"
}

# A WooCommerce built from its Git tag (run-e2e.sh fallback without wordpress.org) has no
# compiled scripts. The classic frontend scripts are plain jQuery files in client/legacy/js;
# copying them is what the release build does apart from minifying. Without them the
# classic checkout cannot refresh itself and A-118 cannot be checked.
if [ ! -f "$WC_DIR/assets/js/frontend/checkout.js" ] && [ -d "$WC_DIR/client/legacy/js" ]; then
	echo "WooCommerce source build: installing the uncompiled classic frontend scripts."
	mkdir -p "$WC_DIR/assets/js"
	cp -r "$WC_DIR/client/legacy/js/." "$WC_DIR/assets/js/"
	find "$WC_DIR/assets/js" -name '*.js' ! -name '*.min.js' | while read -r f; do cp "$f" "${f%.js}.min.js"; done
fi

# The current plugin, copied the same way as run-e2e.sh does.
mkdir -p "$SITE/wp-content/plugins/wp-license"
( cd "$PLUGIN_DIR" && tar -cf - --exclude=./vendor --exclude=./.git --exclude=./tests --exclude=./node_modules --exclude=./.claude . ) | ( cd "$SITE/wp-content/plugins/wp-license" && tar -xf - ) || die "plugin copy failed"
cp "$E2E_DIR/mu-plugins/"*.php "$HERE/mu-plugins/"*.php "$SITE/wp-content/mu-plugins/"

if command -v fuser >/dev/null 2>&1; then
	fuser -k "$PORT/tcp" >/dev/null 2>&1 || true
fi
PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=1G -S "127.0.0.1:$PORT" -t "$SITE" "$E2E_DIR/router.php" >"$SITE/.browser-server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" >/dev/null 2>&1 || true' EXIT
for _ in $(seq 1 50); do
	curl -s -o /dev/null "http://127.0.0.1:$PORT/wp-login.php" && break
	sleep 0.2
done

mkdir -p "$OUT"
export WPLC_E2E_DIR="$E2E_DIR" WPLC_E2E_PORT="$PORT"
FIXTURES="$(wp eval-file "$HERE/fixtures.php")" || die "fixtures failed"
printf '%s\n' "$FIXTURES" | tail -n 1 >"$OUT/fixtures.json"

DEBUG_LOG="$SITE/wp-content/debug.log"
log_before=0
[ -f "$DEBUG_LOG" ] && log_before=$(wc -l <"$DEBUG_LOG")

WPLC_BROWSER_FIXTURES="$OUT/fixtures.json" WPLC_BROWSER_OUT="$OUT" WPLC_E2E_PORT="$PORT" node "$HERE/acceptance.js"
status=$?

# Anything plugin code wrote to debug.log while the browser was clicking is a finding.
if [ -f "$DEBUG_LOG" ]; then
	ours="$(tail -n +"$((log_before + 1))" "$DEBUG_LOG" | grep -E '^\[' | sed "s#$SITE#<site>#g" | grep -E 'plugins/wp-license/|WPLC\\' | cut -c1-400 || true)"
	if [ -n "$ours" ]; then
		echo "FAIL  debug.log has new entries from wp-license code:"
		printf '%s\n' "$ours" | head -20 | sed 's/^/        /'
		status=1
	else
		echo "PASS  debug.log: no new entries from wp-license code"
	fi
fi

echo "Screenshots and report: $OUT"
exit "$status"
