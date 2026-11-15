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

# src/Client is the library the add-ons ship: its texts use the text domain of the add-on and
# are shown on customer sites, never by this plugin, so they stay out of this catalog.
# --allow-root is harmless for normal users and required in CI containers.
"${wp_cli[@]}" --allow-root i18n make-pot . languages/wp-license.pot \
	--slug=wp-license \
	--domain=wp-license \
	--package-name="WP License Captain" \
	--include=src,templates,wp-license.php,assets/js \
	--exclude=vendor,tests,tools,docs,bin,.github,.claude,src/Client \
	--headers='{"Report-Msgid-Bugs-To":"","Last-Translator":"","Language-Team":""}'

# Merge new/changed strings into the translations (marks changed ones fuzzy).
"${wp_cli[@]}" --allow-root i18n update-po languages/wp-license.pot languages/

if command -v msgfmt >/dev/null 2>&1; then
	for po in languages/*.po; do
		[ -e "$po" ] || continue
		msgfmt --check --statistics -o /dev/null "$po"
	done
fi

# Compile .mo files. No make-json step: assets/js contains no translatable strings.
"${wp_cli[@]}" --allow-root i18n make-mo languages/
