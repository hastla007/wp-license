#!/usr/bin/env bash
# Stress checks that are not part of CI: fuzzing of the pure classes and races between
# parallel PHP processes on one database. See README.md in this directory.
#
# usage: tests/stress/run.sh [fuzz|race|all] [workers=8]
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
what="${1:-all}"; N="${2:-8}"
SCENARIOS="activate_same activate_diff activate_diff3 activate_samesite nonce_replay deact_vs_check deact_parallel staging_vs_deact staging_parallel dl_token issue_order process_parallel process_vs_manualpay process_vs_extend manual_start manual_paid refund_vs_pay status_vs_cancel extend_vs_renew"

if [ "$what" = "fuzz" ] || [ "$what" = "all" ]; then
	echo "=== fuzz1: pure classes (no WordPress)"
	php "$HERE/fuzz/fuzz1.php" | sed -n '/^==== FAILURES/,/^==== mm/p' | sed '$d'
fi
if [ "$what" = "race" ] || [ "$what" = "all" ]; then
	: "${WP_TESTS_DIR:?Set WP_TESTS_DIR, WP_CORE_DIR and WPLC_WC_DIR as for the integration suite}"
	if [ "${WPLC_STRESS_ALLOW_TRUNCATE:-0}" != "1" ]; then
		echo "The race harness truncates plugin, order and queue tables of the test database. Re-run with WPLC_STRESS_ALLOW_TRUNCATE=1."; exit 2
	fi
	for sc in $SCENARIOS; do
		echo "=== race: $sc ($N processes)"
		"$HERE/race/run.sh" "$sc" "$N"
	done
	echo "=== fuzz2: REST envelope, release registry, storage, search (needs the test site)"
	QA_WORKER=1 php "$HERE/fuzz/fuzz2.php" | tail -n 40
fi
