#!/usr/bin/env bash
#
# Installs everything the integration suite needs, for CI and for local runs:
#   1. WordPress core and the WordPress PHPUnit test library (pinned installer script),
#   2. the WooCommerce release ZIP from wordpress.org, unpacked into the test site.
#
# Usage:
#   bin/install-test-env.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version] [skip-db-create]
#
# Defaults: db-host=127.0.0.1, wp-version=latest, wc-version=$DEFAULT_WC_VERSION below.
#
# Environment (all optional, same meaning as in the WordPress installer script):
#   WP_TESTS_DIR  test library target   (default: ${TMPDIR:-/tmp}/wordpress-tests-lib)
#   WP_CORE_DIR   WordPress core target (default: ${TMPDIR:-/tmp}/wordpress)
#
# Afterwards run:
#   WP_TESTS_DIR=... WP_CORE_DIR=... WPLC_TEST_HPOS=1 composer test:integration
#   WP_TESTS_DIR=... WP_CORE_DIR=... WPLC_TEST_HPOS=0 composer test:integration
#
# Needs: bash, curl, unzip, svn or git (for the test library), a mysql/mariadb client.

set -euo pipefail

# Newest WooCommerce release this plugin is tested against. The oldest one is set in the CI matrix.
DEFAULT_WC_VERSION="11.2.2"

# https://github.com/wp-cli/scaffold-command, templates/install-wp-tests.sh at a fixed commit.
INSTALLER_COMMIT="12391865aaa11b96bf040e20e0558d619b7dafd0"
INSTALLER_URL="https://raw.githubusercontent.com/wp-cli/scaffold-command/${INSTALLER_COMMIT}/templates/install-wp-tests.sh"

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version] [skip-db-create]" >&2
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"
WC_VERSION="${6:-$DEFAULT_WC_VERSION}"
SKIP_DB_CREATE="${7:-false}"

TMP_BASE="${TMPDIR:-/tmp}"
TMP_BASE="${TMP_BASE%/}"
export WP_TESTS_DIR="${WP_TESTS_DIR:-$TMP_BASE/wordpress-tests-lib}"
export WP_CORE_DIR="${WP_CORE_DIR:-$TMP_BASE/wordpress}"
WP_CORE_DIR="${WP_CORE_DIR%/}"

case "$WC_VERSION" in
	*[!0-9.]* | "")
		echo "WooCommerce version must be a release number such as 9.4.3, got: ${WC_VERSION}" >&2
		exit 1
		;;
esac

WORK_DIR="$(mktemp -d "${TMP_BASE}/wplc-test-env.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "==> WordPress ${WP_VERSION} and the test library"
# The pinned installer would otherwise compare itself with the unpinned main branch on every run.
export WP_INSTALL_TESTS_SKIP_UPDATE_CHECK=true
curl -fsSL --retry 3 "$INSTALLER_URL" -o "$WORK_DIR/install-wp-tests.sh"
bash "$WORK_DIR/install-wp-tests.sh" "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION" "$SKIP_DB_CREATE"

if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ] || [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
	echo "WordPress or its test library was not installed into ${WP_CORE_DIR} / ${WP_TESTS_DIR}." >&2
	exit 1
fi

echo "==> WooCommerce ${WC_VERSION}"
PLUGINS_DIR="$WP_CORE_DIR/wp-content/plugins"
mkdir -p "$PLUGINS_DIR"
curl -fsSL --retry 3 "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o "$WORK_DIR/woocommerce.zip"
rm -rf "$PLUGINS_DIR/woocommerce"
unzip -q "$WORK_DIR/woocommerce.zip" -d "$PLUGINS_DIR"

INSTALLED_WC="$(sed -n 's/^ \* Version: *//p' "$PLUGINS_DIR/woocommerce/woocommerce.php" | head -n 1 | tr -d '\r')"
if [ "$INSTALLED_WC" != "$WC_VERSION" ]; then
	echo "Expected WooCommerce ${WC_VERSION}, the ZIP contains ${INSTALLED_WC:-unknown}." >&2
	exit 1
fi

INSTALLED_WP="$(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$WP_CORE_DIR/wp-includes/version.php" | head -n 1)"

echo "==> Ready"
echo "WordPress:     ${INSTALLED_WP} in ${WP_CORE_DIR}"
echo "WooCommerce:   ${INSTALLED_WC} in ${PLUGINS_DIR}/woocommerce"
echo "Test library:  ${WP_TESTS_DIR}"
echo "Database:      ${DB_NAME} on ${DB_HOST}"
