#!/usr/bin/env bash
# Run one race scenario with N parallel PHP processes that start at the same instant.
# usage: tests/stress/race/run.sh <scenario> [N=8]     (scenarios: see setup.php)
# Prints the fixture and one JSON line per worker (status/result and PHP notices from src/),
# then the verdict of check.php. Exit code: 0 pass, 1 fail, 2 harness error (B-196).
set -u
cd "$(dirname "$0")" || exit 2
sc="${1:?scenario}"; N="${2:-8}"
OUT="${WPLC_STRESS_OUT:-${TMPDIR:-/tmp}/wplc-stress}"; mkdir -p "$OUT"
rm -f charges.log
php setup.php "$sc" 2>"$OUT/setup-$sc.err" | tail -1 >"$OUT/fx-$sc.json"
if ! grep -q '"scenario"' "$OUT/fx-$sc.json"; then echo "setup failed, see $OUT/setup-$sc.err"; tail -5 "$OUT/setup-$sc.err"; exit 2; fi
T=$(php -r 'echo microtime(true)+4;')
for i in $(seq 0 $((N-1))); do php worker.php "$OUT/fx-$sc.json" "$T" "$i" 2>/dev/null | tail -1 >"$OUT/out-$sc-$i.json" & done; wait
cut -c1-300 "$OUT/fx-$sc.json"; echo; cat "$OUT"/out-"$sc"-*.json
[ -f charges.log ] && { echo "charges (one line per charge request the fake gateway received):"; cat charges.log; }
outs=()
for i in $(seq 0 $((N-1))); do outs+=("$OUT/out-$sc-$i.json"); done
php check.php "$OUT/fx-$sc.json" "${outs[@]}" 2>"$OUT/check-$sc.err"
rc=$?
rm -f charges.log
if [ "$rc" -gt 1 ]; then echo "check failed to run, see $OUT/check-$sc.err"; tail -5 "$OUT/check-$sc.err"; exit 2; fi
exit "$rc"
