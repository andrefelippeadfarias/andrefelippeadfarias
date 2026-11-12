#!/usr/bin/env bash
#
# A throwaway WordPress for the end-to-end test, in .wp-test/ (outside git):
#
#   bin/wp-test-site.sh start     build a fresh site and start both servers
#   bin/wp-test-site.sh restart   restart the site server only (keeps the database), e.g. with
#                                 RITMO_PRICING_CALENDAR_READ=0 in the environment
#   bin/wp-test-site.sh stop      stop both servers
#
# The site: WordPress from https://wordpress.org/latest.zip on SQLite (the plugin
# https://downloads.wordpress.org/plugin/sqlite-database-integration.zip and its db.php drop-in),
# this plugin linked into wp-content/plugins and activated, a test-only administrator, RITMO_DEV
# and the connector base URLs pointing at the simulator. Every outgoing request of WordPress is
# limited to 127.0.0.1 (WP_HTTP_BLOCK_EXTERNAL).
#
#   site:       http://127.0.0.1:8090  (php -S)
#   simulator:  http://127.0.0.1:8091  (php -S tests/fake-apis/router.php), its scenario moved to
#               start today in the property's timezone
#
# Environment: RITMO_PRICING_CALENDAR_READ=0 switches off the pricing system's calendar read (a POST
# that reads; on by default).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="$ROOT/.wp-test"
SITE="$BASE/wordpress"
DOWNLOADS="$BASE/downloads"
SITE_PORT=8090
API_PORT=8091
TIMEZONE="America/Sao_Paulo"
ADMIN_USER="ritmo-e2e-admin"
ADMIN_PASSWORD="ritmo-e2e-only-password"

download() {
    local url="$1" file="$2"
    if [ ! -s "$file" ]; then
        echo "Downloading $url" >&2
        curl -fsSL --max-time 300 -o "$file.part" "$url"
        mv -f "$file.part" "$file"
    fi
}

wait_for() {
    local url="$1"
    for _ in $(seq 1 50); do
        if curl -s -o /dev/null --max-time 2 "$url"; then
            return 0
        fi
        sleep 0.2
    done
    echo "Nothing answers at $url" >&2
    return 1
}

# Each server runs in its own process group (setsid), so stopping it also stops its workers.
stop_pid() {
    local file="$1"
    if [ -f "$file" ]; then
        kill -TERM -- "-$(cat "$file")" 2>/dev/null || kill "$(cat "$file")" 2>/dev/null || true
        rm -f "$file"
    fi
}

write_config() {
    cat > "$SITE/wp-config.php" <<PHP
<?php
// Test-only site built by bin/wp-test-site.sh. Never use these values anywhere else.
define('DB_NAME', 'unused');
define('DB_USER', 'unused');
define('DB_PASSWORD', 'unused');
define('DB_HOST', 'unused');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('DB_ENGINE', 'sqlite');
define('DB_DIR', __DIR__ . '/wp-content/database/');
define('DB_FILE', 'e2e.sqlite');
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as \$name) {
    define(\$name, 'e2e-only-' . \$name);
}
\$table_prefix = 'wp_';
define('WP_HOME', 'http://127.0.0.1:$SITE_PORT');
define('WP_SITEURL', 'http://127.0.0.1:$SITE_PORT');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '$BASE/debug.log');
define('WP_DEBUG_DISPLAY', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISABLE_WP_CRON', true);
define('FS_METHOD', 'direct');
define('RITMO_DEV', true);
define('RITMO_BEDS24_BASE_URL', 'http://127.0.0.1:$API_PORT/beds24/v2');
define('RITMO_PRICELABS_BASE_URL', 'http://127.0.0.1:$API_PORT/pricelabs/v1');
if (getenv('RITMO_PRICING_CALENDAR_READ') === '0') {
    define('RITMO_PRICING_CALENDAR_READ', false);
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
PHP
}

start_site() {
    stop_pid "$BASE/site.pid"
    PHP_CLI_SERVER_WORKERS=4 setsid php -S "127.0.0.1:$SITE_PORT" -t "$SITE" >> "$BASE/site.out" 2>&1 < /dev/null &
    echo $! > "$BASE/site.pid"
    wait_for "http://127.0.0.1:$SITE_PORT/wp-login.php"
}

start_api() {
    stop_pid "$BASE/api.pid"
    rm -f "$BASE/fake-apis.log" "$BASE/fake-apis.log".*
    RITMO_FAKE_API_LOG="$BASE/fake-apis.log" RITMO_FAKE_API_START="$(TZ="$TIMEZONE" date +%F)" \
        setsid php -S "127.0.0.1:$API_PORT" "$ROOT/tests/fake-apis/router.php" >> "$BASE/api.out" 2>&1 < /dev/null &
    echo $! > "$BASE/api.pid"
    wait_for "http://127.0.0.1:$API_PORT/__log"
}

build() {
    mkdir -p "$DOWNLOADS"
    download "https://wordpress.org/latest.zip" "$DOWNLOADS/wordpress.zip"
    download "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip" "$DOWNLOADS/sqlite-database-integration.zip"

    rm -rf "$SITE" "$BASE/debug.log" "$BASE/site.out" "$BASE/api.out"
    unzip -q "$DOWNLOADS/wordpress.zip" -d "$BASE"
    unzip -q "$DOWNLOADS/sqlite-database-integration.zip" -d "$SITE/wp-content/plugins"
    cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
    mkdir -p "$SITE/wp-content/database"
    ln -s "$ROOT" "$SITE/wp-content/plugins/ritmo-revenue"
    write_config
    php "$ROOT/tests/e2e/install.php" "$SITE" "$ADMIN_USER" "$ADMIN_PASSWORD"
}

case "${1:-start}" in
    start)
        "$0" stop
        build
        start_api
        start_site
        echo "Site http://127.0.0.1:$SITE_PORT (user $ADMIN_USER), simulator http://127.0.0.1:$API_PORT"
        ;;
    restart)
        start_site
        ;;
    stop)
        stop_pid "$BASE/site.pid"
        stop_pid "$BASE/api.pid"
        ;;
    *)
        echo "Usage: bin/wp-test-site.sh start|restart|stop" >&2
        exit 2
        ;;
esac
