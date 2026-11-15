#!/usr/bin/env bash
# Stress checks that are not part of CI: fuzzing of the pure classes and races between
# parallel PHP processes on one database. See README.md in this directory.
#
# usage: tests/stress/run.sh [fuzz|race|all] [workers=8]
# Exit code (B-196): 0 every scenario passed, 1 at least one failed, 2 harness/setup error.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
what="${1:-all}"; N="${2:-8}"
SCENARIOS="${WPLC_STRESS_SCENARIOS:-activate_same activate_diff activate_diff3 activate_samesite nonce_replay deact_vs_check deact_parallel staging_vs_deact staging_parallel dl_token issue_order process_parallel process_vs_manualpay process_vs_extend manual_start manual_paid refund_vs_pay status_vs_cancel extend_vs_renew}"
failed=(); broken=()

# Record the result of one step: 0 pass, 1 fail, anything else a harness error.
record() {
	case "$2" in
		0) ;;
		1) failed+=("$1") ;;
		*) broken+=("$1") ;;
	esac
}

if [ "$what" = "fuzz" ] || [ "$what" = "all" ]; then
	echo "=== fuzz1: pure classes (no WordPress)"
	out="$(php "$HERE/fuzz/fuzz1.php")"; rc=$?
	printf '%s\n' "$out" | sed -n '/^==== FAILURES/,/^==== COUNTS/p' | sed '$d'
	printf '%s\n' "$out" | grep -E '^(PASS|FAIL) ' || true
	record fuzz1 "$rc"
fi
if [ "$what" = "race" ] || [ "$what" = "all" ]; then
	: "${WP_TESTS_DIR:?Set WP_TESTS_DIR, WP_CORE_DIR and WPLC_WC_DIR as for the integration suite}"
	if [ "${WPLC_STRESS_ALLOW_TRUNCATE:-0}" != "1" ]; then
		echo "The race harness truncates plugin, order and queue tables of the test database. Re-run with WPLC_STRESS_ALLOW_TRUNCATE=1."; exit 2
	fi
	for sc in $SCENARIOS; do
		echo "=== race: $sc ($N processes)"
		"$HERE/race/run.sh" "$sc" "$N"
		record "$sc" $?
	done
	echo "=== fuzz2: REST envelope, release registry, storage, search (needs the test site)"
	out="$(QA_WORKER=1 php "$HERE/fuzz/fuzz2.php" 2>&1)"; rc=$?
	printf '%s\n' "$out" | tail -n 40
	# fuzz2 only prints; it fails when an exception from src/ aborts it.
	[ "$rc" -eq 0 ] && echo "PASS fuzz2" || { echo "FAIL fuzz2: aborted (exit $rc)"; rc=1; }
	record fuzz2 "$rc"
fi

echo "=== summary"
[ "${#failed[@]}" -gt 0 ] && echo "failed: ${failed[*]}"
[ "${#broken[@]}" -gt 0 ] && echo "harness errors: ${broken[*]}"
if [ "${#broken[@]}" -gt 0 ]; then exit 2; fi
if [ "${#failed[@]}" -gt 0 ]; then exit 1; fi
echo "all passed"
exit 0
