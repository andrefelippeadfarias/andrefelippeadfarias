#!/usr/bin/env bash
#
# End-to-end test of the plugin (milestones M1 to M4) on throwaway WordPress sites
# (bin/wp-test-site.sh) against the local simulator (tests/fake-apis), with Playwright:
#
#   1. "wizard": a fresh site; the setup wizard of Settings (tests/e2e/m4-screens.js wizard),
#      which connects, creates the property and its rooms, confirms the safety limits and runs in
#      shadow; the simulator receives only reads.
#   2. a new fresh site, seeded (tests/e2e/seed.php), then the passes of M1 to M3 on the M4 screens:
#      "setup" (connections, shadow run, nights, REST, diagnostics; only reads and the calendar
#      read), "rerun" (the calendar read switched off: only GET), "operations" (approval mode:
#      nothing leaves before the approval; the approved write only on the write routes, never a
#      price or a booking to the PMS; the emergency switch stops every write);
#   3. a new seeded site: the M4 screens (tests/e2e/m4-screens.js, with its own approval), the
#      editors and the market (tests/e2e/m4-editors.js), the reports (tests/e2e/seed-reports.php,
#      tests/e2e/m4-reports.js);
#   4. "pt_BR": the administrator's language set to Portuguese; every screen without an English
#      sentence of the plugin (tests/e2e/m4-i18n.js);
#   5. tests/e2e/wordpress-checks.php: the emergency switch across processes, the keys at rest,
#      the capabilities after a new activation and a RITMO_SECRET_KEY that is too short;
#   6. "update": a site built and seeded with the M3 plugin (git commit $RITMO_E2E_M3_REF,
#      default 336ced7, read with git archive), switched to this code: migrations, roles and
#      capabilities and every screen without error (tests/e2e/upgrade-checks.php).
#
# Run it alone, not next to bin/test.sh: both load the machine, and the built-in PHP servers of the
# sites then drop connections (the helper requests of the passes are retried a few times).
#
# After each site it checks that WordPress logged no PHP error from the plugin. Screenshots of
# every screen in English and Portuguese, at 1280 and 390 px, go to the screenshot folder.
#
#   bin/e2e.sh [screenshot folder]    default: tests/e2e/screenshots (outside git)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCREENS="${1:-$ROOT/tests/e2e/screenshots}"
WP="$ROOT/.wp-test/wordpress"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/ritmo-e2e-XXXXXX")"
M3_REF="${RITMO_E2E_M3_REF:-336ced7}"

cleanup() {
    "$ROOT/bin/wp-test-site.sh" stop
    rm -rf "$WORK"
}
trap cleanup EXIT

# PHP reports a plugin file by its real path (the plugin folder is linked into the site).
no_plugin_errors() {
    local files="$ROOT/(src|templates)/|$ROOT/(ritmo-revenue|uninstall)\.php|wp-content/plugins/ritmo-revenue/"
    if [ -f "$ROOT/.wp-test/debug.log" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$ROOT/.wp-test/debug.log" | grep -Eq "$files"; then
        echo "WordPress logged PHP errors of the plugin ($1):" >&2
        grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$ROOT/.wp-test/debug.log" | grep -E "$files" | head -20 >&2
        exit 1
    fi
}

rm -rf "$SCREENS"
mkdir -p "$SCREENS"
export RITMO_E2E_SCREENS="$SCREENS"

echo "== 1. wizard on a fresh site"
RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" start
RITMO_E2E_WP="$WP" node "$ROOT/tests/e2e/m4-screens.js" wizard
no_plugin_errors "wizard"

echo "== 2. M1-M3 passes on a seeded site"
RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" start
php "$ROOT/tests/e2e/seed.php" "$WP"
RITMO_E2E_PASS=setup RITMO_PRICING_CALENDAR_READ= node "$ROOT/tests/e2e/shadow-run.js"
RITMO_PRICING_CALENDAR_READ=0 "$ROOT/bin/wp-test-site.sh" restart
RITMO_E2E_PASS=rerun RITMO_PRICING_CALENDAR_READ=0 node "$ROOT/tests/e2e/shadow-run.js"
RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" restart
node "$ROOT/tests/e2e/operations.js"

echo "== 3. M4 screens, editors and market, reports (a new seeded site, in shadow mode)"
no_plugin_errors "M1-M3 passes"
RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" start
php "$ROOT/tests/e2e/seed.php" "$WP"
node "$ROOT/tests/e2e/m4-screens.js"
RITMO_E2E_WP_ROOT="$WP" RITMO_E2E_TMP="$WORK" node "$ROOT/tests/e2e/m4-editors.js"
php "$ROOT/tests/e2e/seed-reports.php" "$WP" example-inn
node "$ROOT/tests/e2e/m4-reports.js"

echo "== 4. pt_BR"
php "$ROOT/tests/e2e/locale.php" "$WP" pt_BR
node "$ROOT/tests/e2e/m4-i18n.js"
php "$ROOT/tests/e2e/locale.php" "$WP" en_US

echo "== 5. WordPress checks"
php "$ROOT/tests/e2e/wordpress-checks.php" "$WP"
no_plugin_errors "seeded site"

echo "== 6. update from M3 ($M3_REF)"
mkdir -p "$WORK/m3"
git -C "$(git -C "$ROOT" rev-parse --show-toplevel)" archive "$M3_REF:$(git -C "$ROOT" rev-parse --show-prefix)" | tar -x -C "$WORK/m3"
RITMO_E2E_PLUGIN_SOURCE="$WORK/m3" RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" start
php "$WORK/m3/tests/e2e/seed.php" "$WP"
"$ROOT/bin/wp-test-site.sh" relink "$ROOT"
php "$ROOT/tests/e2e/upgrade-checks.php" "$WP"
no_plugin_errors "update"

echo "E2E OK. Screenshots in $SCREENS"
