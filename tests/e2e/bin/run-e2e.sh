#!/usr/bin/env bash
#
# End-to-end run of WP License Captain in a real WordPress + WooCommerce site.
#
# Builds (idempotently) a throw-away site, resets its database, copies the plugin
# WITHOUT vendor/ into it, starts a local PHP server and runs every flow script.
# Prints PASS/FAIL per assertion and exits non-zero when any assertion fails.
#
# Usage: tests/e2e/bin/run-e2e.sh [flow-prefix ...]   e.g. "04" or "07-renewals-auto" (no arguments: all flows)
#
# Environment (all optional):
#   WPLC_E2E_SITE        site directory            (default: ${TMPDIR:-/tmp}/wplc-e2e-site)
#   WPLC_E2E_DB_NAME     database, DROPPED+CREATED (default: wplc_e2e)
#   WPLC_E2E_DB_USER     (default: root)   WPLC_E2E_DB_PASS (default: root)
#   WPLC_E2E_DB_HOST     (default: 127.0.0.1)
#   WPLC_E2E_WP_VERSION  WordPress version         (default: latest)
#   WPLC_E2E_WC_VERSION  WooCommerce version       (default: 11.2.2)
#   WPLC_E2E_PORT        local HTTP port           (default: 8765)
#   WPLC_E2E_SERVER_WORKERS  worker processes of the PHP server (default: 8; flow 11 needs >= 4)
#   WPLC_E2E_KEEP_DB=1   do not reset the database (re-run flows on the existing state)
#
# Needs: bash, php (with mysqli, curl, zip), curl, tar, a mysql/mariadb client, and
# either access to wordpress.org or composer + git (fallback sources, see README).

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$(dirname "$HERE")"
PLUGIN_DIR="$(cd "$E2E_DIR/../.." && pwd)"

TMP_BASE="${TMPDIR:-/tmp}"
SITE="${WPLC_E2E_SITE:-${TMP_BASE%/}/wplc-e2e-site}"
DB_NAME="${WPLC_E2E_DB_NAME:-wplc_e2e}"
DB_USER="${WPLC_E2E_DB_USER:-root}"
DB_PASS="${WPLC_E2E_DB_PASS:-root}"
DB_HOST="${WPLC_E2E_DB_HOST:-127.0.0.1}"
WP_VERSION="${WPLC_E2E_WP_VERSION:-latest}"
WC_VERSION="${WPLC_E2E_WC_VERSION:-11.2.2}"
PORT="${WPLC_E2E_PORT:-8765}"
SERVER_WORKERS="${WPLC_E2E_SERVER_WORKERS:-8}"
SITE_URL="https://shop.test"

TOOLS="$SITE/.e2e-tools"
WPCLI="$TOOLS/wp-cli.phar"
DEBUG_LOG="$SITE/wp-content/debug.log"
SERVER_LOG="$SITE/.e2e-server.log"
export WPLC_E2E_PORT="$PORT"
export WPLC_E2E_SERVER_WORKERS="$SERVER_WORKERS"
export WPLC_E2E_DEBUG_LOG="$DEBUG_LOG"
export WPLC_E2E_DIR="$E2E_DIR"
export COMPOSER_ALLOW_SUPERUSER=1

wp() {
	php -d memory_limit=1G -d display_errors=stderr "$WPCLI" --path="$SITE" --allow-root "$@"
}

step() {
	echo
	echo "==> $*"
}

die() {
	echo "SETUP FAILED: $*" >&2
	exit 2
}

mkdir -p "$SITE" "$TOOLS"

# ---------------------------------------------------------------------------
# 1. Tools and sources (cached in the site directory between runs).
# ---------------------------------------------------------------------------
if [ ! -s "$WPCLI" ]; then
	step "Downloading WP-CLI"
	curl -fsSL --retry 3 -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar || die "WP-CLI download failed"
fi

if [ ! -f "$SITE/wp-settings.php" ]; then
	step "Installing WordPress core ($WP_VERSION)"
	CORE_ARGS=()
	[ "$WP_VERSION" != "latest" ] && CORE_ARGS+=(--version="$WP_VERSION")
	if ! wp core download "${CORE_ARGS[@]}" 2>"$TOOLS/core-download.err"; then
		echo "wordpress.org not reachable ($(head -c 200 "$TOOLS/core-download.err")); using the Composer mirror johnpbloch/wordpress-core."
		CONSTRAINT="*"
		[ "$WP_VERSION" != "latest" ] && CONSTRAINT="$WP_VERSION"
		rm -rf "$TOOLS/core-src" && mkdir -p "$TOOLS/core-src"
		( cd "$TOOLS/core-src" && composer init -n -q --name wplc/e2e-core && composer require -n -q "johnpbloch/wordpress-core:$CONSTRAINT" ) || die "composer could not fetch WordPress core"
		cp -r "$TOOLS/core-src/vendor/johnpbloch/wordpress-core/." "$SITE/"
	fi
fi
[ -f "$SITE/wp-settings.php" ] || die "WordPress core is missing in $SITE"

WC_DIR="$SITE/wp-content/plugins/woocommerce"
if [ ! -f "$WC_DIR/woocommerce.php" ] || ! grep -q "Version: $WC_VERSION" "$WC_DIR/woocommerce.php"; then
	step "Installing WooCommerce $WC_VERSION"
	rm -rf "$WC_DIR"
	mkdir -p "$SITE/wp-content/plugins"
	if curl -fsSL --retry 2 -o "$TOOLS/woocommerce.zip" "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" 2>/dev/null; then
		( cd "$SITE/wp-content/plugins" && php -r '$z = new ZipArchive(); exit( true === $z->open( $argv[1] ) && $z->extractTo( "." ) ? 0 : 1 );' "$TOOLS/woocommerce.zip" ) || die "could not unpack the WooCommerce ZIP"
	else
		echo "downloads.wordpress.org not reachable; building WooCommerce $WC_VERSION from its Git tag (PHP only, no compiled JS/CSS)."
		if [ ! -d "$TOOLS/wc-src-$WC_VERSION/plugins/woocommerce" ]; then
			rm -rf "$TOOLS/wc-src-$WC_VERSION"
			git clone -q --depth 1 --branch "$WC_VERSION" --filter=blob:none --sparse https://github.com/woocommerce/woocommerce.git "$TOOLS/wc-src-$WC_VERSION" || die "git clone of WooCommerce failed"
			( cd "$TOOLS/wc-src-$WC_VERSION" && git sparse-checkout set plugins/woocommerce packages/php ) || die "sparse checkout failed"
			( cd "$TOOLS/wc-src-$WC_VERSION/plugins/woocommerce" && composer install --no-dev -n -q --no-scripts && composer dump-autoload -n -q ) || die "composer install for WooCommerce failed"
			# Generated by the release build; WooCommerce fatals without it.
			( cd "$TOOLS/wc-src-$WC_VERSION/plugins/woocommerce" && php bin/generate-feature-config.php ) || die "could not generate the WooCommerce feature config"
		fi
		# The monorepo uses a path repository (packages/php); dereference symlinks while copying.
		cp -rL "$TOOLS/wc-src-$WC_VERSION/plugins/woocommerce" "$WC_DIR"
		# The classic admin enqueues compiled scripts and throws without their asset registries.
		# Give every one it names an empty registry (the React admin is switched off by the mu-plugin).
		mkdir -p "$WC_DIR/assets/client/admin/wp-admin-scripts"
		for stub in command-palette command-palette-analytics $(grep -rhoE "register_script\( *'wp-admin-scripts', *'[a-z-]+'" "$WC_DIR/src" "$WC_DIR/includes" | sed -E "s/.*'([a-z-]+)'\$/\1/" | sort -u); do
			printf '<?php return array( "dependencies" => array(), "version" => "e2e-stub" );\n' >"$WC_DIR/assets/client/admin/wp-admin-scripts/$stub.asset.php"
		done
	fi
fi
[ -f "$WC_DIR/woocommerce.php" ] || die "WooCommerce is missing"

# ---------------------------------------------------------------------------
# 2. Fresh database, config, install.
# ---------------------------------------------------------------------------
case "$DB_NAME" in
	*[!A-Za-z0-9_]* | "") die "unsafe database name: $DB_NAME" ;;
esac

if [ "${WPLC_E2E_KEEP_DB:-0}" != "1" ]; then
	step "Resetting database $DB_NAME and site state"
	mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4" || die "cannot create database $DB_NAME"
	rm -f "$SITE/wp-config.php" "$DEBUG_LOG"
	rm -rf "$SITE/wp-content/uploads" "$SITE/wp-content/mu-plugins" "$SITE/wp-content/plugins/wp-license"

	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --force --extra-php <<'PHP' || die "wp config create failed"
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost,shop.test' );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'e2e-2026' => 'e2e-only-installation-encryption-secret-0123456789' ) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'e2e-2026' );
define( 'WPLC_LICENSE_KEY_SECRETS', array( 'e2e-2026' => 'e2e-only-license-key-secret-0123456789abcdef' ) );
define( 'WPLC_LICENSE_KEY_ID', 'e2e-2026' );
PHP
	wp core install --url="$SITE_URL" --title="WPLC E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@shop.test --skip-email || die "wp core install failed"
	wp rewrite structure '/%postname%/' >/dev/null || die "rewrite structure"

	mkdir -p "$SITE/wp-content/mu-plugins"
	cp "$E2E_DIR/mu-plugins/"*.php "$SITE/wp-content/mu-plugins/"

	wp plugin activate woocommerce || die "WooCommerce activation failed"
	# No onboarding redirect; the pages WooCommerce needs; a fixed currency and base country.
	wp transient delete _wc_activation_redirect >/dev/null 2>&1 || true
	wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
	wp wc tool run install_pages --user=admin >/dev/null 2>&1 || true
	wp option update woocommerce_currency EUR >/dev/null
	wp option update woocommerce_default_country DE >/dev/null
	# Offline gateways are off in a new store.
	for gateway in bacs cheque cod; do
		wp option update "woocommerce_${gateway}_settings" '{"enabled":"yes"}' --format=json >/dev/null
	done
	# A new store starts in "coming soon" mode, which hides the checkout and pay pages.
	wp option update woocommerce_coming_soon no >/dev/null
fi

# The plugin is copied without vendor/, .git and tests so the fallback autoloader is what runs.
mkdir -p "$SITE/wp-content/plugins/wp-license"
( cd "$PLUGIN_DIR" && tar -cf - --exclude=./vendor --exclude=./.git --exclude=./tests --exclude=./node_modules --exclude=./.claude . ) | ( cd "$SITE/wp-content/plugins/wp-license" && tar -xf - ) || die "plugin copy failed"
[ ! -e "$SITE/wp-content/plugins/wp-license/vendor" ] || die "vendor/ must not exist in the site copy"
cp "$E2E_DIR/mu-plugins/"*.php "$SITE/wp-content/mu-plugins/"

# ---------------------------------------------------------------------------
# 3. Local HTTP server (plain HTTP on 127.0.0.1, presented to WordPress as https://shop.test).
# ---------------------------------------------------------------------------
if command -v fuser >/dev/null 2>&1; then
	fuser -k "$PORT/tcp" >/dev/null 2>&1 || true
fi
PHP_CLI_SERVER_WORKERS="$SERVER_WORKERS" php -d memory_limit=1G -S "127.0.0.1:$PORT" -t "$SITE" "$E2E_DIR/router.php" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" >/dev/null 2>&1 || true' EXIT
for _ in $(seq 1 50); do
	curl -s -o /dev/null "http://127.0.0.1:$PORT/wp-login.php" && break
	sleep 0.2
done

# ---------------------------------------------------------------------------
# 4. Flows.
# ---------------------------------------------------------------------------
step "Versions"
echo "PHP $(php -r 'echo PHP_VERSION;') / WordPress $(wp core version) / WooCommerce $(wp plugin get woocommerce --field=version 2>/dev/null) / $(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e 'SELECT VERSION()' 2>/dev/null)"

RESULTS="$SITE/.e2e-results.tsv"
: >"$RESULTS"
export WPLC_E2E_RESULTS="$RESULTS"

FLOWS=()
if [ $# -gt 0 ]; then
	for n in "$@"; do
		for f in "$E2E_DIR/flows/$n"*.php; do [ -f "$f" ] && FLOWS+=("$f"); done
	done
else
	for f in "$E2E_DIR"/flows/[0-9][0-9]-*.php; do FLOWS+=("$f"); done
fi

[ "${#FLOWS[@]}" -gt 0 ] || die "no flow script matches: $*"

FAILED=0
for flow in "${FLOWS[@]}"; do
	name="$(basename "$flow" .php)"
	step "Flow $name"
	export WPLC_E2E_FLOW="$name"
	log_before=0
	[ -f "$DEBUG_LOG" ] && log_before=$(wc -l <"$DEBUG_LOG")
	if ! wp eval-file "$flow"; then
		echo "FAIL  [$name] flow script aborted (fatal error or uncaught exception)"
		printf '%s\tFAIL\tflow script aborted\n' "$name" >>"$RESULTS"
	fi
	# Anything the flow (CLI process or HTTP server) wrote to debug.log is a finding.
	log_after=0
	[ -f "$DEBUG_LOG" ] && log_after=$(wc -l <"$DEBUG_LOG")
	if [ "$log_after" -gt "$log_before" ]; then
		new_lines="$(tail -n +"$((log_before + 1))" "$DEBUG_LOG")"
		# Lines that start a log entry and name plugin code (stack-trace lines of foreign errors do not count).
		# The site path itself may contain "wplc" (default: .../wplc-e2e-site), so it is removed before matching.
		ours="$(printf '%s\n' "$new_lines" | grep -E '^\[' | awk -v site="$SITE" '{ line = $0; while ( ( i = index( line, site ) ) > 0 ) { line = substr( line, 1, i - 1 ) "<site>" substr( line, i + length( site ) ) } print line }' | grep -E 'plugins/wp-license/|WPLC\\|wp-license</code>|wplc' | cut -c1-600 || true)"
		if [ -n "$ours" ]; then
			echo "FAIL  [$name] debug.log has new entries from wp-license code:"
			printf '%s\n' "$ours" | head -20 | sed 's/^/        /'
			printf '%s\tFAIL\tdebug.log entries from wp-license\n' "$name" >>"$RESULTS"
		else
			echo "PASS  [$name] debug.log: no new entries from wp-license code ($((log_after - log_before)) foreign line(s), see $DEBUG_LOG)"
			printf '%s\tPASS\tdebug.log clean for wp-license\n' "$name" >>"$RESULTS"
		fi
	else
		echo "PASS  [$name] debug.log: no new entries"
		printf '%s\tPASS\tdebug.log clean\n' "$name" >>"$RESULTS"
	fi
done

step "Summary"
awk -F '\t' '
	{ total[$1]++; if ($2 == "PASS") pass[$1]++; else fail[$1]++; if (!($1 in seen)) { order[++n] = $1; seen[$1] = 1 } }
	END {
		for (i = 1; i <= n; i++) {
			f = order[i];
			printf "%-28s %s  %d/%d assertions passed\n", f, (fail[f] > 0 ? "FAIL" : "PASS"), pass[f] + 0, total[f];
		}
	}' "$RESULTS"
FAILED=$(grep -c $'\tFAIL\t' "$RESULTS" || true)
echo
if [ "$FAILED" -gt 0 ]; then
	echo "E2E RESULT: FAIL ($FAILED failed assertion(s))"
	exit 1
fi
echo "E2E RESULT: PASS"
