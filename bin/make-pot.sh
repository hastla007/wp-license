#!/usr/bin/env bash
#
# Regenerates languages/wp-license.pot and recompiles the shipped translations.
#
# Requires WP-CLI (no WordPress install needed) and, for the validation step, GNU gettext.
# Use `wp` from PATH or point WP_CLI at a phar:
#
#   bin/make-pot.sh
#   WP_CLI=/path/to/wp-cli.phar bin/make-pot.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -n "${WP_CLI:-}" ]; then
	wp_cli=( php "$WP_CLI" )
elif command -v wp >/dev/null 2>&1; then
	wp_cli=( wp )
else
	echo "WP-CLI not found. Install it or set WP_CLI=/path/to/wp-cli.phar." >&2
	exit 1
fi

# build/ and dist/ hold the output of bin/build-zip.sh (a copy of src/), examples/ has its own text domain.

# The catalog header carries the plugin version (wp i18n make-pot would otherwise keep an old one).
version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' wp-license.php | head -n 1)"

# src/Client is the library the add-ons ship. Its texts (all in src/Client/Messages.php) belong to
# this catalog too since the server shows its own license screen with the same library
# (Infrastructure\SelfLicense, text domain wp-license): without them that screen stays English.
# --allow-root is harmless for normal users and required in CI containers.
"${wp_cli[@]}" --allow-root i18n make-pot . languages/wp-license.pot \
	--slug=wp-license \
	--domain=wp-license \
	--package-name="WP License Captain" \
	--include=src,templates,wp-license.php,assets/js \
	--exclude=vendor,tests,tools,docs,bin,build,dist,examples,.github,.claude \
	--headers="{\"Project-Id-Version\":\"WP License Captain ${version}\",\"Report-Msgid-Bugs-To\":\"\",\"Last-Translator\":\"\",\"Language-Team\":\"\"}"

# Merge new/changed strings into the translations (marks changed ones fuzzy).
"${wp_cli[@]}" --allow-root i18n update-po languages/wp-license.pot languages/

# update-po keeps the old header lines: carry the version and the creation date of the template over.
pot_date="$(sed -n 's/^"POT-Creation-Date: \(.*\)\\n"$/\1/p' languages/wp-license.pot | head -n 1)"
for po in languages/*.po; do
	[ -e "$po" ] || continue
	sed -i \
		-e "s/^\"Project-Id-Version: .*\\\\n\"\$/\"Project-Id-Version: WP License Captain ${version}\\\\n\"/" \
		-e "s/^\"POT-Creation-Date: .*\\\\n\"\$/\"POT-Creation-Date: ${pot_date}\\\\n\"/" \
		"$po"
done

if command -v msgfmt >/dev/null 2>&1; then
	for po in languages/*.po; do
		[ -e "$po" ] || continue
		msgfmt --check --statistics -o /dev/null "$po"
	done
fi

# Compile .mo files. No make-json step: assets/js contains no translatable strings.
"${wp_cli[@]}" --allow-root i18n make-mo languages/
