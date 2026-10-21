#!/usr/bin/env bash
# Runs one benchmark for a strategy: fresh data, empty cache, warm-up, measured k6 run, Varnish/CPU deltas.
# Usage: bench/run.sh full|tags|ttl   (tuning via env: READERS, DURATION, WARMUP_DURATION, EDIT_EVERY, PUBLISH_EVERY, SEED, SHORT_TTL)
set -euo pipefail

STRATEGY="${1:?usage: bench/run.sh full|tags|ttl}"
READERS="${READERS:-50}"
DURATION="${DURATION:-3m}"
WARMUP_DURATION="${WARMUP_DURATION:-30s}"
EDIT_EVERY="${EDIT_EVERY:-10}"
PUBLISH_EVERY="${PUBLISH_EVERY:-6}"
SEED="${SEED:-42}"
# Exported so every compose call keeps the app on this strategy.
export INVALIDATION_STRATEGY="$STRATEGY"
export SHORT_TTL="${SHORT_TTL:-60}"

cd "$(dirname "$0")/.."
RESULTS=bench/results
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$RESULTS"

compose() { docker compose "$@"; }
console() { compose exec -T app php bin/console "$@"; }
stats() { compose exec -T varnish varnishstat -1 -j; }
counter() { jq -r --arg c "$2" '(.counters // .)[$c].value' <<<"$1"; }
k6() { compose --profile bench run --rm --no-deps -e READERS="$READERS" -e EDIT_EVERY="$EDIT_EVERY" -e PUBLISH_EVERY="$PUBLISH_EVERY" -e SEED="$SEED" "$@"; }

echo "==> strategy=$STRATEGY short_ttl=${SHORT_TTL}s readers=$READERS duration=$DURATION warmup=$WARMUP_DURATION edit_every=${EDIT_EVERY}s publish_every=$PUBLISH_EVERY"

echo "==> Fresh containers (strategy $STRATEGY, empty Varnish cache)"
compose up -d --force-recreate app varnish >/dev/null 2>&1
until curl -sf -o /dev/null http://localhost:8002/; do sleep 1; done

echo "==> Reseeding data"
console app:seed -q
console app:bench:urls -q
# Reseeding happened behind Varnish's back: start from an empty cache.
compose restart varnish >/dev/null 2>&1
sleep 2

echo "==> Warm-up ($WARMUP_DURATION, readers only)"
k6 -e WARMUP=1 -e DURATION="$WARMUP_DURATION" k6 run --quiet scenario.js >/dev/null

echo "==> Measured run ($DURATION)"
BEFORE="$(stats)"
CPU_LOG="$(mktemp)"
APP_CONTAINER="$(compose ps -q app)"
( while true; do docker stats --no-stream --format '{{.CPUPerc}}' "$APP_CONTAINER" | tr -d '%' >>"$CPU_LOG"; done ) &
CPU_PID=$!
trap 'kill $CPU_PID 2>/dev/null || true' EXIT

SUMMARY="$RESULTS/$STAMP-$STRATEGY.json"
k6 -e DURATION="$DURATION" -e SUMMARY_FILE="/bench/results/$STAMP-$STRATEGY.json" k6 run --quiet scenario.js \
    || echo "!! k6 thresholds failed (see above); results still recorded"

# Stop the sampler and its running docker stats child.
pkill -P $CPU_PID 2>/dev/null || true
kill $CPU_PID 2>/dev/null || true
wait $CPU_PID 2>/dev/null || true
AFTER="$(stats)"

delta() { echo $(( $(counter "$AFTER" "$1") - $(counter "$BEFORE" "$1") )); }
HITS=$(delta MAIN.cache_hit)
MISSES=$(delta MAIN.cache_miss)
BACKEND=$(delta MAIN.backend_req)
SECONDS_RUN=$(jq '.state.testRunDurationMs / 1000 | floor' "$SUMMARY")
P50=$(jq '.metrics["http_req_duration{scenario:readers}"].values.med' "$SUMMARY")
P95=$(jq '.metrics["http_req_duration{scenario:readers}"].values["p(95)"]' "$SUMMARY")
READER_RPS=$(jq '.metrics["http_reqs{scenario:readers}"].values.rate' "$SUMMARY")
CPU_AVG=$(awk '{ s += $1; n++ } END { if (n) printf "%.1f", s / n; else print "n/a" }' "$CPU_LOG")
rm -f "$CPU_LOG"

ORIGIN_RPS=$(awk -v b="$BACKEND" -v s="$SECONDS_RUN" 'BEGIN { printf "%.1f", b / s }')
HIT_RATIO=$(awk -v h="$HITS" -v m="$MISSES" 'BEGIN { printf "%.1f", (h + m) ? 100 * h / (h + m) : 0 }')
P50=$(printf '%.1f' "$P50"); P95=$(printf '%.1f' "$P95"); READER_RPS=$(printf '%.1f' "$READER_RPS")

ROW="| $STAMP | $STRATEGY | $READERS | $DURATION | $READER_RPS | $ORIGIN_RPS | $HIT_RATIO% | $P50 | $P95 | $CPU_AVG% |"
TABLE="$RESULTS/results.md"
if [ ! -f "$TABLE" ]; then
    {
        echo "| Run | Strategy | Readers | Duration | Reader req/s | Origin req/s | Hit ratio | p50 (ms) | p95 (ms) | Origin CPU |"
        echo "|---|---|---|---|---|---|---|---|---|---|"
    } >"$TABLE"
fi
echo "$ROW" >>"$TABLE"

echo "==> Result (also appended to $TABLE)"
echo "active strategy in app: $(compose exec -T app printenv INVALIDATION_STRATEGY)"
echo "strategy $STRATEGY: origin ${ORIGIN_RPS} req/s, hit ratio ${HIT_RATIO}%, p50 ${P50} ms, p95 ${P95} ms, origin CPU ${CPU_AVG}% (hits $HITS, misses $MISSES, backend requests $BACKEND over ${SECONDS_RUN}s)"
