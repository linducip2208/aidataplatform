#!/bin/sh
#
# Contract check for CI: docs/api.md <-> the routes Laravel actually registers.
#
# docs/api.md is a document, not code, and this repository has already shipped a
# version of it describing five endpoints that were never implemented. This
# script is the cheap half of the answer — it needs php plus awk/sed, no
# database, no running server, no composer install of its own — and it fails the
# build with an exact set difference when the two disagree.
#
# The PHP feature test (application/tests/Feature/ApiContractTest.php) is the
# thorough half: it also checks the engine routes, the response envelopes and
# the status codes. This one is deliberately small enough to run anywhere.
#
# Usage: sh tests/verify-contract.sh   (from the repository root or anywhere else)
# Exit:  0 when the contract and the routes agree, 1 otherwise.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DOC="$ROOT/docs/api.md"
APP="$ROOT/application"

if [ ! -f "$DOC" ]; then
    printf 'verify-contract: docs/api.md not found at %s\n' "$DOC" >&2
    exit 1
fi

if [ ! -f "$APP/artisan" ]; then
    printf 'verify-contract: %s is not a Laravel application\n' "$APP" >&2
    exit 1
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT HUP INT TERM

# ---------------------------------------------------------------------------
# 1. What docs/api.md claims
#
# A row of an endpoint table is recognised by its first cell naming an HTTP verb
# and one of its cells holding a backticked `/path`. Rows the engine owns are
# skipped: they are marked `(engine)` in the contract, or they live under the
# `/api/v1` prefix the FastAPI app serves, and neither is registered in Laravel.
#
# The role column is found by content rather than position: a bare `any`, or a
# comma-separated list of role names. Prose in the Notes column never looks like
# that, so the parser does not have to track column indexes — and a row with no
# role column at all is compared as unrestricted, which makes a role gate that
# the contract stopped documenting show up as a mismatch instead of a silence.
# ---------------------------------------------------------------------------

awk '
    function trim(s) { gsub(/^[ \t]+|[ \t]+$/, "", s); return s }

    # Insertion sort: asort() is a gawk extension and this must run anywhere.
    function sort(a, n,   i, j, t) {
        for (i = 2; i <= n; i++) {
            t = a[i]

            for (j = i - 1; j >= 1 && a[j] > t; j--) {
                a[j + 1] = a[j]
            }

            a[j + 1] = t
        }
    }

    # Every backticked token in the cell that starts with a slash, one per line.
    function paths(cell,   out, rest) {
        out = ""
        rest = cell

        while (match(rest, /`\/[^`]*`/)) {
            token = substr(rest, RSTART, RLENGTH)
            gsub(/`/, "", token)
            out = out token "\n"
            rest = substr(rest, RSTART + RLENGTH)
        }

        return out
    }

    function role_of(n,   i, c, k, seen) {
        for (i = 2; i <= n; i++) {
            c = tolower(trim(cell[i]))

            if (c == "any") {
                return "-"
            }

            if (c !~ /^(admin|analyst|viewer)(, *(admin|analyst|viewer))*$/) {
                continue
            }

            k = split(c, seen, ",")
            gsub(/[ \t]/, "", seen[1])

            for (j = 2; j <= k; j++) {
                gsub(/[ \t]/, "", seen[j])
            }

            sort(seen, k)

            out = ""

            for (j = 1; j <= k; j++) {
                out = out (j > 1 ? "," : "") seen[j]
            }

            return out
        }

        return "-"
    }

    { line = $0 }

    line ~ /^[|][ \t]*[-:| \t]+$/ { next }
    line !~ /^[|]/ { next }
    line ~ /\(engine\)/ { next }

    {
        n = split(line, cell, "[|]")

        verb = ""

        for (i = 2; i <= n; i++) {
            c = toupper(trim(cell[i]))

            if (c == "GET" || c == "POST" || c == "PUT" || c == "PATCH" || c == "DELETE") {
                verb = c
                break
            }
        }

        if (verb == "") { next }

        # The path is the first cell after the verb that carries a backticked
        # path. Engine call columns come later, so they are never mistaken for it.
        found = ""
        seen = 0

        for (i = 2; i <= n; i++) {
            if (paths(cell[i]) != "") {
                found = paths(cell[i])
                seen = 1
                break
            }
        }

        if (!seen) { next }

        role = role_of(n)
        k = split(found, items, "\n")

        for (i = 1; i <= k; i++) {
            p = items[i]

            if (substr(p, 1, 8) == "/api/v1/") { continue }

            # Normalise exactly as the route side does: the `/api` prefix is a
            # mounting detail, and a placeholder name is not part of the wire
            # contract, so `/api/datasets/{uuid}` and `api/datasets/{dataset}`
            # are the same endpoint.
            if (substr(p, 1, 5) == "/api/") { p = substr(p, 6) }
            else { sub(/^\//, "", p) }

            gsub(/\{[^}]+\}/, "{}", p)

            if (p == "") { continue }

            printf "%s\t%s\t%s\n", verb, p, role
        }
    }
' "$DOC" | sort -u > "$WORK/documented"

# ---------------------------------------------------------------------------
# 2. What Laravel actually registers
#
# `php -r` reads route:list --json rather than jq, which is not guaranteed to be
# installed. The program is passed through a variable so no layer of shell
# quoting has to survive a round trip through php's argument parser.
# ---------------------------------------------------------------------------

ROUTE_PARSER=$(cat <<'PHP'
$routes = json_decode(stream_get_contents(STDIN), true);

if (! is_array($routes)) {
    fwrite(STDERR, "verify-contract: php artisan route:list --json did not return JSON" . PHP_EOL);
    exit(2);
}

foreach ($routes as $route) {
    $uri = (string) $route['uri'];

    if (strncmp($uri, 'api/', 4) === 0) {
        $uri = substr($uri, 4);
    } elseif ($uri !== 'up') {
        continue;
    }

    $uri = (string) preg_replace('/\{[^}]+\}/', '{}', $uri);
    $roles = '-';

    foreach ((array) ($route['middleware'] ?? []) as $middleware) {
        $at = strpos((string) $middleware, 'EnsureRole:');

        if ($at === false) {
            continue;
        }

        $roles = explode(',', substr((string) $middleware, $at + strlen('EnsureRole:')));
        sort($roles);
        $roles = implode(',', $roles);
    }

    foreach (explode('|', (string) $route['method']) as $method) {
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            continue;
        }

        echo $method, chr(9), $uri, chr(9), $roles, chr(10);
    }
}
PHP
)

if ! command -v php >/dev/null 2>&1; then
  echo "FAIL: php is not on PATH. This check compares docs/api.md against the real" >&2
  echo "      route table, so it cannot degrade to a partial result: without php it" >&2
  echo "      would report every documented endpoint as missing, which is a false" >&2
  echo "      alarm rather than a useful signal." >&2
  echo "      Run this inside the application/ directory with php available, or in CI." >&2
  exit 2
fi

(cd "$APP" && php artisan route:list --json) | php -r "$ROUTE_PARSER" | sort -u > "$WORK/registered"

if [ ! -s "$WORK/registered" ]; then
  echo "FAIL: the route table came back empty. Something other than a documentation" >&2
  echo "      mismatch is wrong; treat this as an environment failure, not a contract break." >&2
  exit 2
fi

# ---------------------------------------------------------------------------
# 3. Compare, both directions
# ---------------------------------------------------------------------------

comm -23 "$WORK/documented" "$WORK/registered" > "$WORK/only-documented" || true
comm -13 "$WORK/documented" "$WORK/registered" > "$WORK/only-registered" || true

DOCUMENTED_COUNT=$(awk 'END { print NR + 0 }' "$WORK/documented")
REGISTERED_COUNT=$(awk 'END { print NR + 0 }' "$WORK/registered")

printf 'contract: docs/api.md vs php artisan route:list\n'
printf 'contract: %s documented endpoint(s), %s registered route(s)\n' "$DOCUMENTED_COUNT" "$REGISTERED_COUNT"
printf 'contract: columns are VERB<TAB>path<TAB>role constraint ("-" means unrestricted)\n'

STATUS=0

if [ -s "$WORK/only-documented" ]; then
    STATUS=1
    printf '\n'
    printf 'FAIL: documented in docs/api.md but missing from routes/api.php:\n'
    sed 's/^/  /' "$WORK/only-documented"
fi

if [ -s "$WORK/only-registered" ]; then
    STATUS=1
    printf '\n'
    printf 'FAIL: registered in routes/api.php but absent from docs/api.md:\n'
    printf '  (an unadvertised public endpoint: document it or delete the route)\n'
    sed 's/^/  /' "$WORK/only-registered"
fi

if [ "$STATUS" -eq 0 ]; then
    printf '\nOK: docs/api.md and the Laravel routes agree on every path, verb and role gate.\n'
else
    printf '\nFAIL: docs/api.md and the Laravel routes disagree. Decide which side is wrong; do not\n'
    printf '      quietly edit one to match the other.\n'
fi

exit "$STATUS"
