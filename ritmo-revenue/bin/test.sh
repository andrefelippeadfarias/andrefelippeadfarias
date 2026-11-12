#!/usr/bin/env bash
#
# Runs the PHPUnit suite with tests/phpunit.xml.
#
#   bin/test.sh                         whole suite
#   bin/test.sh tests/Unit/Domain       one folder or file (relative to the plugin root or to the cwd)
#   bin/test.sh NumTest                 anything that is not a path is passed as --filter
#   bin/test.sh tests/Unit --testdox    extra arguments go straight to PHPUnit
#
# PHPUnit is a .phar kept in tools/ (outside git), downloaded from phar.phpunit.de on first use and
# kept only when its sha256 matches one of the pinned releases below.
# The exit code is PHPUnit's.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHAR="$ROOT/tools/phpunit.phar"

# "<url> <sha256>": pinned releases whose checksum is verified. A file that does not match is
# discarded. There is no unverified fallback: when every pinned file fails, the script stops and
# asks for a new pinned entry (url and sha256) to be added here.
CANDIDATES=(
    "https://phar.phpunit.de/phpunit-10.5.66.phar 42bcac97bbf9fb1aecf5a7d6a1b37123a11e7e295458978f0e268999f6d9e50f"
    "https://phar.phpunit.de/phpunit-11.5.57.phar c704fdd37c6c6ab7adb386f31b4e186cd51156801fa9028b5ea8099a2cd7f106"
)

download_phpunit() {
    mkdir -p "$ROOT/tools"
    local tmp entry url sum
    tmp="$(mktemp "$ROOT/tools/phpunit.XXXXXX")"
    for entry in "${CANDIDATES[@]}"; do
        url="${entry% *}"
        sum="${entry##* }"
        echo "Downloading $url" >&2
        if ! curl -fsSL --max-time 180 -o "$tmp" "$url"; then
            continue
        fi
        if [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$sum" ]; then
            echo "Checksum mismatch for $url: discarded" >&2
            continue
        fi
        if php "$tmp" --version 2>/dev/null | grep -q '^PHPUnit '; then
            mv -f "$tmp" "$PHAR"
            return 0
        fi
    done
    rm -f "$tmp"
    echo "Could not download a PHPUnit release with a matching checksum from phar.phpunit.de." >&2
    echo "Add a pinned \"<url> <sha256>\" entry to CANDIDATES in bin/test.sh." >&2
    return 1
}

pinned_phar() {
    local entry have
    have="$(sha256sum "$PHAR" | cut -d' ' -f1)"
    for entry in "${CANDIDATES[@]}"; do
        [ "${entry##* }" = "$have" ] && return 0
    done
    return 1
}

if [ -s "$PHAR" ] && ! pinned_phar; then
    echo "tools/phpunit.phar matches no pinned checksum: downloading a pinned release again" >&2
    rm -f "$PHAR"
fi
if [ ! -s "$PHAR" ]; then
    download_phpunit
fi

ARGS=()
if [ "$#" -gt 0 ] && [ "${1#-}" = "$1" ]; then
    if [ -e "$1" ]; then
        ARGS+=("$(cd "$(dirname "$1")" && pwd)/$(basename "$1")")
    elif [ -e "$ROOT/$1" ]; then
        ARGS+=("$ROOT/$1")
    else
        ARGS+=(--filter "$1")
    fi
    shift
fi

exec php "$PHAR" --configuration "$ROOT/tests/phpunit.xml" "${ARGS[@]}" "$@"
