#!/usr/bin/env bash
# Syntax-check every PHP file of the plugin with the PHP binary on PATH.
# Used by CI on each supported PHP version and by "composer lint".
set -euo pipefail

cd "$(dirname "$0")/.."

status=0
count=0
while IFS= read -r -d '' file; do
	count=$((count + 1))
	if ! output=$(php -l "$file" 2>&1); then
		echo "$output"
		status=1
	fi
done < <(find . -type f -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -not -path './.git/*' -print0)

echo "Checked ${count} PHP files with PHP $(php -r 'echo PHP_VERSION;')."
exit "$status"
