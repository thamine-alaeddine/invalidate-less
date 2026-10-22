#!/usr/bin/env bash
# Writes the README "Results" section: median per strategy over every run in bench/results/results.md, as a table and Mermaid charts.
# Usage: bench/report.sh [--stdout]
set -euo pipefail
cd "$(dirname "$0")/.."
TABLE=bench/results/results.md
README=README.md
[ -f "$TABLE" ] || { echo "no $TABLE, run make bench-all first" >&2; exit 1; }

# "strategy column value" for every numeric cell, then the median per strategy and column.
MEDIANS="$(
    awk -F'|' 'NR > 2 { for (c = 4; c <= 14; c++) { v = $c; gsub(/[ %]/, "", v); print $3, c, v } }' "$TABLE" \
        | awk '{ $1 = $1; print }' | sort -k1,1 -k2,2n -k3,3g \
        | awk '
            function flush() { if (n) printf "%s %s %s %d\n", key1, key2, (n % 2 ? v[(n + 1) / 2] : (v[n / 2] + v[n / 2 + 1]) / 2), n }
            $1 != key1 || $2 != key2 { flush(); key1 = $1; key2 = $2; n = 0 }
            { v[++n] = $3 }
            END { flush() }'
)"
# Median of a column (results.md column number) for a strategy, rounded to $3 decimals.
m() { awk -v s="$1" -v c="$2" -v p="${3:-1}" '$1 == s && $2 == c { printf "%.*f", p, $3 }' <<<"$MEDIANS"; }
runs() { awk -v s="$1" '$1 == s && $2 == 4 { print $4 }' <<<"$MEDIANS"; }
bars() { echo "[$(m full "$1" "${2:-1}"), $(m tags "$1" "${2:-1}"), $(m ttl "$1" "${2:-1}")]"; }
chart() {
    printf '```mermaid\nxychart-beta\n    title "%s"\n    x-axis ["A. Full purge", "B. Tag-based", "TTL only"]\n    y-axis "%s"\n    bar %s\n```\n\n' "$1" "$2" "$3"
}

SECTION="$(
    echo "Median of $(runs tags) runs per strategy ($(m tags 4 0) req/s target, $(docker info --format '{{.NCPU}} CPUs') given to Docker, k6, Varnish and the app on the same machine). Compare strategies with each other rather than reading absolute numbers; \`make report\` rebuilds this section from \`bench/results/results.md\`."
    echo
    echo "| Strategy | Reader req/s | Dropped | Origin req/s | Hit ratio | p50 (ms) | p95 (ms) | Origin CPU | Update visible p95 (s) | Publish visible p95 (s) | Probe timeouts |"
    echo "|---|---|---|---|---|---|---|---|---|---|---|"
    for s in full tags ttl; do
        case $s in (full) label="A. Full purge" ;; (tags) label="B. Tag-based" ;; (ttl) label="TTL only" ;; esac
        echo "| $label | $(m $s 5) | $(m $s 6 0) | $(m $s 7) | $(m $s 8)% | $(m $s 9) | $(m $s 10) | $(m $s 11)% | $(m $s 12 2) | $(m $s 13 2) | $(m $s 14 0) |"
    done
    echo
    chart "Origin requests per second (lower is better)" "req/s" "$(bars 7)"
    chart "Origin CPU, 100% = one core (lower is better)" "%" "$(bars 11)"
    chart "Reader p95 latency (lower is better)" "ms" "$(bars 10)"
    chart "Update visible after, p95 (lower is better)" "seconds" "$(bars 12 2)"
    chart "Publish visible after, p95 (lower is better)" "seconds" "$(bars 13 2)"
)"

if [ "${1:-}" = "--stdout" ]; then
    echo "$SECTION"
    exit 0
fi

# Replaces everything between the results markers in the README.
awk -v section="$SECTION" '
    /<!-- results:start -->/ { print; print section; skip = 1; next }
    /<!-- results:end -->/ { skip = 0 }
    !skip { print }' "$README" >"$README.tmp"
mv "$README.tmp" "$README"
echo "README results section updated"
