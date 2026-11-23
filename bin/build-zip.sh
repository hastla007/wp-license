#!/usr/bin/env bash
# Build and verify the distributable plugin ZIP.
#
#   bin/build-zip.sh [version]
#
# Used by .github/workflows/release.yml (tag builds) and by the "Package" workflow on pull
# requests, so every pull request exercises exactly the release build. It copies an
# allow-list of runtime files (wp-license.php, uninstall.php, readme.txt, src/, mu-plugins/, catalogs/,
# languages/, assets/, templates/) and the vendor manual (docs/manual/*.md plus the catalog file
# schema docs/catalog-file.schema.json it links to; PROD-10) into build/wp-license/, generates vendor/
# there and writes dist/wp-license-captain-<version>.zip (one top-level wp-license/ folder) plus a
# .sha256 file. No other document of docs/ is copied: the to-do list, plans, reviews and evidence
# are internal. bin/check-zip-contents.php verifies the entries.
# It also writes the client library package for vendors, dist/license-client-<library version>.zip
# with its .sha256 (bin/package-client.php, PROD-08).
# The checkout itself is not modified; everything happens in build/ and dist/.
#
# Without an argument the version is read from wp-license.php. With an argument (the tag
# without "v") it must equal the plugin header Version, WPLC_VERSION and the readme.txt
# Stable tag. Needs php, composer (v2), zip, unzip and sha256sum.

set -euo pipefail

cd "$(dirname "$0")/.."

HEADER_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' wp-license.php | head -n 1)"
CONSTANT_VERSION="$(sed -n "s/^define([[:space:]]*'WPLC_VERSION',[[:space:]]*'\([^']*\)'.*$/\1/p" wp-license.php | head -n 1)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*$/\1/p' readme.txt | head -n 1)"
VERSION="${1:-${HEADER_VERSION}}"

echo "Version: ${VERSION} / plugin header: ${HEADER_VERSION} / WPLC_VERSION: ${CONSTANT_VERSION} / readme.txt Stable tag: ${STABLE_TAG}"
if [ -z "${VERSION}" ] || [ "${VERSION}" != "${HEADER_VERSION}" ] || [ "${VERSION}" != "${CONSTANT_VERSION}" ] || [ "${VERSION}" != "${STABLE_TAG}" ]; then
	echo "::error::Version ${VERSION} does not match the Version header (${HEADER_VERSION}), WPLC_VERSION (${CONSTANT_VERSION}) and the readme.txt Stable tag (${STABLE_TAG})."
	exit 1
fi
if ! printf '%s' "${VERSION}" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
	echo "::error::Version ${VERSION} is not of the form 1.2.3 or 1.2.3-rc.1."
	exit 1
fi

STAGE="build/wp-license"
export COMPOSER_ROOT_VERSION="${VERSION}"
ZIP="wp-license-captain-${VERSION}.zip"
rm -rf build dist
mkdir -p "${STAGE}" dist
cp wp-license.php uninstall.php readme.txt composer.json "${STAGE}/"
# catalogs/ holds the catalog files schema step 24 imports (docs/CATALOG-FILE.md).
cp -R src mu-plugins catalogs "${STAGE}/"
for OPTIONAL in languages assets templates; do
	if [ -d "${OPTIONAL}" ]; then
		cp -R "${OPTIONAL}" "${STAGE}/"
	fi
done
# The vendor manual for buyers of the server (PROD-10) and the JSON schema of the catalog file it
# links to (../catalog-file.schema.json). Nothing else of docs/ is shipped.
mkdir -p "${STAGE}/docs/manual"
cp docs/manual/*.md "${STAGE}/docs/manual/"
cp docs/catalog-file.schema.json "${STAGE}/docs/"

# Runtime dependencies and the autoloader are created inside the stage, so a local run never
# touches the development vendor/ of the checkout. composer.lock is not committed, so
# `composer install --no-dev` would first resolve the whole require-dev graph against
# Packagist (43 packages) only to install nothing. While "require" holds only php/ext-*,
# generate the optimized autoloader directly: no network, reproducible.
RUNTIME_PACKAGES="$(php -r '$c = json_decode((string) file_get_contents("composer.json"), true); echo implode(" ", array_filter(array_keys($c["require"] ?? array()), function ($p) { return "php" !== $p && 0 !== strpos($p, "ext-"); }));')"
if [ -z "${RUNTIME_PACKAGES}" ]; then
	# No Composer plugin and no script of composer.json runs in the build of the sold package.
	composer dump-autoload --working-dir="${STAGE}" --no-dev --optimize --no-interaction --no-plugins --no-scripts
else
	composer install --working-dir="${STAGE}" --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --no-plugins --no-scripts
fi
rm -f "${STAGE}/composer.json" "${STAGE}/composer.lock"
if [ ! -f "${STAGE}/vendor/autoload.php" ]; then
	echo "::error::vendor/autoload.php was not generated."
	exit 1
fi
# Every PHP file of src/ must be in the optimized class map (and nothing else), so the bundled
# autoloader finds each class. (Loading the classes needs WordPress and WooCommerce.)
php -r '
	$root = realpath( $argv[1] );
	$mapped = array();
	foreach ( require $root . "/vendor/composer/autoload_classmap.php" as $class => $file ) {
		if ( 0 === strpos( $class, "WPLC\\" ) ) {
			$mapped[] = realpath( $file );
		}
	}
	$files = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . "/src", FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		// src/Client/load.php declares no class: it is the loader add-ons require.
		if ( "php" === $f->getExtension() && $root . "/src/Client/load.php" !== $f->getRealPath() ) {
			$files[] = $f->getRealPath();
		}
	}
	sort( $mapped );
	sort( $files );
	if ( $mapped !== $files ) {
		fwrite( STDERR, "::error::Class map and src/ differ:\n" . implode( "\n", array_merge( array_diff( $files, $mapped ), array_diff( $mapped, $files ) ) ) . "\n" );
		exit( 1 );
	}
	echo count( $files ), " classes of src/ in the class map\n";
' "${STAGE}"

# Runtime files only: no VCS metadata, dotfiles, tests or docs from dependencies.
find "${STAGE}" -mindepth 1 -name '.*' -prune -exec rm -rf {} +
find "${STAGE}/vendor" -type d \( -name tests -o -name test -o -name docs \) -prune -exec rm -rf {} +
# Syntax check with the running PHP (the release job runs the minimum version, 8.5).
find "${STAGE}" -type f -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
(cd build && zip -r -q -X "../dist/${ZIP}" wp-license)
(cd dist && sha256sum "${ZIP}" > "${ZIP}.sha256")

# Verify the package contents.
unzip -Z1 "dist/${ZIP}" > build/contents.txt
# Required runtime files and manual chapters, compiled translations, catalog files, one top-level
# folder, no development files and no internal documents (rules in bin/check-zip-contents.php).
CHECK_ARGS=(--manual-dir=docs/manual)
if [ -d languages ]; then
	CHECK_ARGS+=(--require-mo)
fi
if [ -d catalogs ]; then
	CHECK_ARGS+=(--require-catalogs)
fi
php bin/check-zip-contents.php build/contents.txt "${CHECK_ARGS[@]}"
(cd dist && sha256sum -c "${ZIP}.sha256")
# The client library for vendors (PROD-08). Fails when the client classes disagree on VERSION or
# packaging/client/CHANGELOG-CLIENT.md has no section for it.
php bin/package-client.php --out=dist
(cd dist && sha256sum -c license-client-*.zip.sha256)
echo "Built dist/${ZIP} ($(wc -l < build/contents.txt) entries)"
