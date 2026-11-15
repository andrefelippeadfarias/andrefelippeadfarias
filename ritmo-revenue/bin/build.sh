#!/usr/bin/env bash
#
# Builds the distributed package: dist/ritmo-revenue-<version>.zip, with the folder ritmo-revenue/
# inside, ready for Plugins > Add New > Upload Plugin.
#
#   bin/build.sh [output folder]      default: dist/ (outside git)
#
# In order, and it stops at the first problem:
#   1. the translations: languages/ritmo-revenue.pot from the code (bin/make-pot.php), the .mo and
#      .l10n.php from the .po (bin/compile-l10n.php);
#   2. the sources: the same version in the plugin header, RITMO_VERSION and the "Stable tag" of
#      readme.txt, an entry for it in readme.txt and CHANGELOG.md, the same requirements in the
#      header and readme.txt, every text of the .pot translated in every .po;
#   3. the staged package: every file except what .distignore leaves out (tests/, the simulators,
#      tools/, bin/, docs/PLANO.md, .wp-test/, dist/...) and every hidden file;
#   4. php -l on every PHP file of it, and bin/lint-php81.php (nothing PHP 8.1 cannot run);
#   5. nothing of the owner's own properties (tests/fixtures/owner-terms.json), no key, token,
#      password, private key or test credential, only the file types a plugin ships;
#   6. the zip (sorted, fixed permissions, timestamp of the last commit, so the same commit gives
#      the same bytes), read back: one folder, the files a site needs, exactly the staged files;
#   7. its SHA-256, printed and written next to it (<zip>.sha256).
#
# The zip's timestamp is SOURCE_DATE_EPOCH when set, else the time of the last git commit; it is
# printed with the SHA-256, because a published checksum is reproduced only with the same value.
# Needs PHP 8.1+ with the zip extension. Never calls the network.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
SLUG="ritmo-revenue"

fail() {
    echo "BUILD FAILED: $*" >&2
    exit 1
}

php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || fail "PHP 8.1 or newer is needed."
php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || fail "PHP has no zip extension (install php-zip)."
[ -f "$ROOT/tests/fixtures/owner-terms.json" ] || fail "tests/fixtures/owner-terms.json is missing: build from the repository."

cd "$ROOT"
echo "== 1. translations"
php bin/make-pot.php
php bin/compile-l10n.php

echo "== 2. sources"
php bin/package/build.php check-source || fail "the sources are not ready for a release (above)."
VERSION="$(php bin/package/build.php version)"
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo none)"
    CHANGED="$(git -C "$ROOT" status --porcelain -- . 2>/dev/null | wc -l | tr -d ' ')"
    if [ "$CHANGED" != "0" ]; then
        echo "note: $CHANGED file(s) differ from commit $COMMIT; the zip is built from the files as they are now."
    fi
    if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
        SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || date +%s)"
    fi
fi
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(date +%s)}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/ritmo-build-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/$SLUG"

echo "== 3. staged package"
php bin/package/build.php stage "$STAGE"

echo "== 4. PHP"
if ! find "$STAGE" -type f -name '*.php' -print0 | xargs -0 -n 1 -P 8 sh -c 'php -l "$1" >/dev/null 2>&1 || { php -l "$1" 2>&1 | grep -v "^No syntax errors"; exit 1; }' sh; then
    fail "a PHP file of the package does not parse (above)."
fi
echo "ok   php -l ($(find "$STAGE" -type f -name '*.php' | wc -l | tr -d ' ') files)"
php bin/lint-php81.php "$STAGE" || fail "the package uses something PHP 8.1 does not have (above)."

echo "== 5. contents"
php bin/package/build.php scan "$STAGE" || fail "the package carries something it must not (above)."

echo "== 6. zip"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ZIP="$OUT/$SLUG-$VERSION.zip"
php bin/package/build.php zip "$STAGE" "$ZIP"
php bin/package/build.php verify "$ZIP" "$STAGE" || { rm -f "$ZIP"; fail "the zip is not what was staged (above)."; }
unzip -tqq "$ZIP" >/dev/null || { rm -f "$ZIP"; fail "unzip cannot test the zip."; }

echo "== 7. checksum"
SUM="$(sha256sum "$ZIP" | cut -d' ' -f1)"
(cd "$OUT" && echo "$SUM  $(basename "$ZIP")" > "$(basename "$ZIP").sha256")
SIZE="$(wc -c < "$ZIP" | tr -d ' ')"
echo "Built $ZIP ($SIZE bytes, version $VERSION)"
echo "sha256 $SUM"
echo "SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH (the time stamped on every file of the zip: the same files built with it give the same sha256)"
