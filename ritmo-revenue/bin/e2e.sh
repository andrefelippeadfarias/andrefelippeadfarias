#!/usr/bin/env bash
#
# End-to-end test of milestone M1: builds the throwaway WordPress (bin/wp-test-site.sh), seeds it
# (tests/e2e/seed.php) and runs tests/e2e/shadow-run.js twice with Playwright:
#
#   1. "setup": connections, setup, dashboard, shadow run, REST, diagnostics; the pricing
#      calendar read is not allowed, so only GET may reach the simulator;
#   2. "rerun": the site restarted with RITMO_PRICING_CALENDAR_READ=1; a second shadow run, and
#      only GET plus the POST of /listing_prices may reach the simulator.
#
# Then it checks that WordPress logged no PHP error from the plugin, and stops both servers.
#
#   bin/e2e.sh [screenshot folder]    default: tests/e2e/screenshots (outside git)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCREENS="${1:-$ROOT/tests/e2e/screenshots}"

cleanup() {
    "$ROOT/bin/wp-test-site.sh" stop
}
trap cleanup EXIT

rm -rf "$SCREENS"
mkdir -p "$SCREENS"

RITMO_PRICING_CALENDAR_READ=0 "$ROOT/bin/wp-test-site.sh" start
php "$ROOT/tests/e2e/seed.php" "$ROOT/.wp-test/wordpress"

RITMO_E2E_PASS=setup RITMO_PRICING_CALENDAR_READ=0 RITMO_E2E_SCREENS="$SCREENS" node "$ROOT/tests/e2e/shadow-run.js"

RITMO_PRICING_CALENDAR_READ=1 "$ROOT/bin/wp-test-site.sh" restart
RITMO_E2E_PASS=rerun RITMO_PRICING_CALENDAR_READ=1 RITMO_E2E_SCREENS="$SCREENS" node "$ROOT/tests/e2e/shadow-run.js"

# PHP reports a plugin file by its real path (the plugin folder is linked into the site).
PLUGIN_FILES="$ROOT/(src|templates)/|$ROOT/(ritmo-revenue|uninstall)\.php|wp-content/plugins/ritmo-revenue/"
if [ -f "$ROOT/.wp-test/debug.log" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$ROOT/.wp-test/debug.log" | grep -Eq "$PLUGIN_FILES"; then
    echo "WordPress logged PHP errors of the plugin:" >&2
    grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$ROOT/.wp-test/debug.log" | grep -E "$PLUGIN_FILES" | head -20 >&2
    exit 1
fi
echo "E2E OK. Screenshots in $SCREENS"
