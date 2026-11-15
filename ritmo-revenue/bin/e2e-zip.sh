#!/usr/bin/env bash
#
# The distributed zip on a clean WordPress, installed the way a customer installs it:
#
#   bin/e2e-zip.sh [zip] [screenshot folder]
#
# Without a zip it builds one first (bin/build.sh, into a temporary folder). Then:
#   1. bin/wp-test-site.sh builds the throwaway site (WordPress and SQLite from wordpress.org, the
#      local simulator of the PMS and pricing APIs on :8091); the site is then rebuilt clean:
#      fresh WordPress files, a new database, only the SQLite plugin, no trace of this plugin, and
#      things that are not the plugin's and must survive it (tests/e2e/zip-site.php install), with
#      a snapshot of the site;
#   2. Plugins > Add New > Upload Plugin with the zip, "Activate Plugin", the version on the
#      Plugins screen, every screen before any setup (tests/e2e/zip-install.js install);
#   3. the setup wizard up to the first shadow run against the simulator, only reads (wizard);
#   4. every screen and tab in English, then in Portuguese (screens; tests/e2e/locale.php);
#   5. the plugin made its tables, settings, roles, capabilities and cron events (zip-site.php present);
#   6. Settings > Uninstall "delete the data", Deactivate and Delete on the Plugins screen (remove);
#   7. nothing of the plugin is left (tables, options, transients, user meta, roles, capabilities,
#      cron events, files) and everything of the snapshot still is (zip-site.php residue);
#   8. WordPress logged no PHP error of the plugin.
#
# The site's upload limit is 64 MB (a php.ini of this test only), the common value of hosting
# plans; the install pass fails when the zip is larger than the limit the upload form shows.
# Needs Node with Playwright. Every outgoing request of the site stays on 127.0.0.1.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="$ROOT/.wp-test"
WP="$BASE/wordpress"
DOWNLOADS="$BASE/downloads"
ADMIN_USER="ritmo-e2e-admin"
ADMIN_PASSWORD="ritmo-e2e-only-password"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/ritmo-e2e-zip-XXXXXX")"
ZIP="${1:-}"
export RITMO_E2E_SCREENS="${2:-$ROOT/tests/e2e/screenshots/zip}"

cleanup() {
    "$ROOT/bin/wp-test-site.sh" stop || true
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
    echo "E2E ZIP FAILED: $*" >&2
    exit 1
}

# PHP errors of the plugin as installed from the zip (wp-content/plugins/ritmo-revenue/).
no_plugin_errors() {
    if [ -f "$BASE/debug.log" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$BASE/debug.log" | grep -q "wp-content/plugins/ritmo-revenue/"; then
        grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$BASE/debug.log" | grep "wp-content/plugins/ritmo-revenue/" | head -20 >&2
        fail "WordPress logged PHP errors of the plugin ($1)."
    fi
}

echo "== 0. the zip"
if [ -z "$ZIP" ]; then
    "$ROOT/bin/build.sh" "$WORK/dist"
    ZIP="$(ls "$WORK"/dist/ritmo-revenue-*.zip)"
fi
[ -f "$ZIP" ] || fail "no zip at $ZIP"
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"
mkdir -p "$WORK/unzipped"
unzip -q "$ZIP" -d "$WORK/unzipped"
[ -f "$WORK/unzipped/ritmo-revenue/ritmo-revenue.php" ] || fail "the zip has no ritmo-revenue/ritmo-revenue.php"
VERSION="$(sed -n 's/^[ *]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$WORK/unzipped/ritmo-revenue/ritmo-revenue.php" | head -1)"
[ -n "$VERSION" ] || fail "the zip's plugin header has no version"
echo "zip $ZIP, version $VERSION"
rm -rf "$RITMO_E2E_SCREENS"
mkdir -p "$RITMO_E2E_SCREENS"

echo "== 1. a clean WordPress"
# The script builds the site with a plugin activated (here: the unzipped package); the site is
# then rebuilt without it, keeping its wp-config.php and the simulator it started.
RITMO_E2E_PLUGIN_SOURCE="$WORK/unzipped/ritmo-revenue" RITMO_PRICING_CALENDAR_READ= "$ROOT/bin/wp-test-site.sh" start
if [ -f "$BASE/site.pid" ]; then
    kill -TERM -- "-$(cat "$BASE/site.pid")" 2>/dev/null || kill "$(cat "$BASE/site.pid")" 2>/dev/null || true
    rm -f "$BASE/site.pid"
fi
cp "$WP/wp-config.php" "$WORK/wp-config.php"
rm -rf "$WP" "$BASE/debug.log"
unzip -q "$DOWNLOADS/wordpress.zip" -d "$BASE"
unzip -q "$DOWNLOADS/sqlite-database-integration.zip" -d "$WP/wp-content/plugins"
cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
mkdir -p "$WP/wp-content/database"
cp "$WORK/wp-config.php" "$WP/wp-config.php"
php "$ROOT/tests/e2e/zip-site.php" install "$WP" "$ADMIN_USER" "$ADMIN_PASSWORD"
php "$ROOT/tests/e2e/zip-site.php" snapshot "$WP" "$WORK/before.json"
mkdir -p "$WORK/php-ini"
printf 'upload_max_filesize = 64M\npost_max_size = 64M\n' > "$WORK/php-ini/uploads.ini"
# An empty first entry keeps PHP's own folder of .ini files.
PHP_INI_SCAN_DIR=":$WORK/php-ini" "$ROOT/bin/wp-test-site.sh" restart

export RITMO_E2E_ZIP="$ZIP" RITMO_E2E_ZIP_VERSION="$VERSION"
echo "== 2. upload and activate"
node "$ROOT/tests/e2e/zip-install.js" install
no_plugin_errors "install"

echo "== 3. wizard up to the first shadow run"
node "$ROOT/tests/e2e/zip-install.js" wizard
no_plugin_errors "wizard"

echo "== 4. every screen in English and in Portuguese"
RITMO_E2E_LANG=en node "$ROOT/tests/e2e/zip-install.js" screens
php "$ROOT/tests/e2e/locale.php" "$WP" pt_BR
RITMO_E2E_LANG=pt_BR node "$ROOT/tests/e2e/zip-install.js" screens
php "$ROOT/tests/e2e/locale.php" "$WP" en_US
no_plugin_errors "screens"

echo "== 5. what the plugin made"
php "$ROOT/tests/e2e/zip-site.php" present "$WP"

echo "== 6. deactivate and delete"
node "$ROOT/tests/e2e/zip-install.js" remove
no_plugin_errors "delete"

echo "== 7. nothing of the plugin left, everything else kept"
php "$ROOT/tests/e2e/zip-site.php" residue "$WP" "$WORK/before.json"

echo "E2E ZIP OK: $(basename "$ZIP") ($VERSION) installed, used and removed. Screenshots in $RITMO_E2E_SCREENS"
