#!/usr/bin/env bash
# Writes the README "Results" section and docs/charts/*.svg: median per strategy over every run in bench/results/results.md.
# Usage: bench/report.sh [--stdout]
set -euo pipefail
cd "$(dirname "$0")/.."
TABLE=bench/results/results.md
README=README.md
[ -f "$TABLE" ] || { echo "no $TABLE, run make bench-all first" >&2; exit 1; }

# bench/charts.mjs computes the medians, writes docs/charts/*.svg and prints the section; run in a container so the host needs no Node.
SECTION="$(docker run --rm -v "$PWD":/w -w /w node:24-alpine node bench/charts.mjs "$(docker info --format '{{.NCPU}}')")"

if [ "${1:-}" = "--stdout" ]; then
    echo "$SECTION"
    exit 0
fi

# Replaces everything between the results markers in the README.
# Passed through the environment: BSD awk rejects newlines in -v values.
SECTION="$SECTION" awk '
    /<!-- results:start -->/ { print; print ENVIRON["SECTION"]; skip = 1; next }
    /<!-- results:end -->/ { skip = 0 }
    !skip { print }' "$README" >"$README.tmp"
mv "$README.tmp" "$README"
echo "README results section updated"
