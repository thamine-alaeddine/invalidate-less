#!/usr/bin/env bash
# Runs one benchmark for a strategy: fresh data, empty cache, warm-up, measured k6 run, Varnish/CPU deltas.
# Usage: bench/run.sh full|tags|ttl
# Tuning via env: RATE, DURATION, WARMUP_DURATION, EDIT_EVERY, PUBLISH_EVERY, PROBE_TIMEOUT, SEED, SHORT_TTL
set -euo pipefail

STRATEGY="${1:?usage: bench/run.sh full|tags|ttl}"
RATE="${RATE:-1000}"
DURATION="${DURATION:-3m}"
WARMUP_DURATION="${WARMUP_DURATION:-30s}"
EDIT_EVERY="${EDIT_EVERY:-10}"
PUBLISH_EVERY="${PUBLISH_EVERY:-6}"
PROBE_TIMEOUT="${PROBE_TIMEOUT:-120}"
SEED="${SEED:-42}"
# Exported so every compose call keeps the app and editor on this strategy.
export INVALIDATION_STRATEGY="$STRATEGY"
export SHORT_TTL="${SHORT_TTL:-60}"

cd "$(dirname "$0")/.."
RESULTS=bench/results
STAMP="$(date +%Y%m%d-%H%M%S)"
SUMMARY="$RESULTS/$STAMP-$STRATEGY.json"
mkdir -p "$RESULTS"

# "3m", "90s", "1m30s" or plain seconds.
seconds() {
    local d="$1" m=0 s=0
    [[ "$d" =~ ^([0-9]+)m ]] && m="${BASH_REMATCH[1]}"
    [[ "$d" =~ ([0-9]+)s$ ]] && s="${BASH_REMATCH[1]}"
    [[ "$d" =~ ^[0-9]+$ ]] && s="$d"
    echo $(( m * 60 + s ))
}
RUN_SECONDS="$(seconds "$DURATION")"

compose() { docker compose "$@"; }
console() { compose exec -T app php bin/console "$@"; }
stats() { compose exec -T varnish varnishstat -1 -j; }
counter() { jq -r --arg c "$2" '(.counters // .)[$c].value' "$1"; }
# --no-deps: otherwise compose re-creates the app without our environment.
k6() {
    compose --profile bench run --rm --no-deps \
        -e RATE="$RATE" -e EDIT_EVERY="$EDIT_EVERY" -e PUBLISH_EVERY="$PUBLISH_EVERY" \
        -e PROBE_TIMEOUT="$PROBE_TIMEOUT" -e SEED="$SEED" "$@"
}
metric() { jq -r --arg m "$1" --arg v "$2" '.metrics[$m].values[$v] // empty' "$SUMMARY"; }
# Divides $1 by $2 with $3 decimals (default 1), or prints n/a when the metric is missing.
fmt() { if [ -n "$1" ]; then awk -v x="$1" -v d="$2" -v p="${3:-1}" 'BEGIN { printf "%.*f", p, x / d }'; else echo "n/a"; fi; }

echo "==> strategy=$STRATEGY short_ttl=${SHORT_TTL}s rate=${RATE}/s duration=$DURATION warmup=$WARMUP_DURATION edit_every=${EDIT_EVERY}s publish_every=$PUBLISH_EVERY"

echo "==> Fresh containers (strategy $STRATEGY, empty Varnish cache)"
compose up -d --build --force-recreate app varnish editor >/dev/null 2>&1
until curl -sf -o /dev/null http://localhost:8002/; do sleep 1; done
# Any answer means the editor listens (POST-only routes give 404 on GET).
until curl -s -o /dev/null http://localhost:3002/; do sleep 1; done

echo "==> Reseeding data"
console app:seed -q
console app:bench:urls -q
# Reseeding happened behind Varnish's back: start from an empty cache.
compose restart varnish >/dev/null 2>&1
sleep 2

echo "==> Warm-up ($WARMUP_DURATION, readers only)"
k6 -e WARMUP=1 -e DURATION="$WARMUP_DURATION" k6 run --quiet scenario.js >/dev/null 2>&1 || true

echo "==> Measured run ($DURATION of readers, then the editor's last probes)"
TMP="$(mktemp -d)"
trap 'pkill -P $$ 2>/dev/null || true; rm -rf "$TMP"' EXIT
stats >"$TMP/before.json"
APP_CONTAINER="$(compose ps -q app)"

# Varnish counters and CPU cover the readers' window only, not the probes' tail.
(
    end=$(( $(date +%s) + RUN_SECONDS ))
    while [ "$(date +%s)" -lt "$end" ]; do
        docker stats --no-stream --format '{{.CPUPerc}}' "$APP_CONTAINER" | tr -d '%' >>"$TMP/cpu.log"
    done
) &
( sleep "$RUN_SECONDS"; stats >"$TMP/after.json" ) &

k6 -e DURATION="$DURATION" -e SUMMARY_FILE="/bench/results/$STAMP-$STRATEGY.json" k6 run --quiet scenario.js 2>/dev/null \
    || echo "!! k6 thresholds failed (see above); results still recorded"
wait

delta() { echo $(( $(counter "$TMP/after.json" "$1") - $(counter "$TMP/before.json" "$1") )); }
HITS=$(delta MAIN.cache_hit)
MISSES=$(delta MAIN.cache_miss)
BACKEND=$(delta MAIN.backend_req)

READER_RPS=$(fmt "$(metric 'http_reqs{scenario:readers}' count)" "$RUN_SECONDS")
DROPPED=$(metric 'dropped_iterations{scenario:readers}' count); DROPPED=${DROPPED:-0}
P50=$(fmt "$(metric 'http_req_duration{scenario:readers}' med)" 1)
P95=$(fmt "$(metric 'http_req_duration{scenario:readers}' 'p(95)')" 1)
UPDATE_P95=$(fmt "$(metric update_visible_after 'p(95)')" 1000 2)
PUBLISH_P95=$(fmt "$(metric publish_visible_after 'p(95)')" 1000 2)
TIMEOUTS=$(metric probe_timeouts count); TIMEOUTS=${TIMEOUTS:-0}
ORIGIN_RPS=$(fmt "$BACKEND" "$RUN_SECONDS")
HIT_RATIO=$(awk -v h="$HITS" -v m="$MISSES" 'BEGIN { printf "%.1f", (h + m) ? 100 * h / (h + m) : 0 }')
CPU_AVG=$(awk '{ s += $1; n++ } END { if (n) printf "%.1f", s / n; else print "n/a" }' "$TMP/cpu.log")

TABLE="$RESULTS/results.md"
if [ ! -f "$TABLE" ] || ! grep -q "Target req/s" "$TABLE"; then
    {
        echo "| Run | Strategy | Target req/s | Reader req/s | Dropped | Origin req/s | Hit ratio | p50 (ms) | p95 (ms) | Origin CPU | Update visible p95 (s) | Publish visible p95 (s) | Probe timeouts |"
        echo "|---|---|---|---|---|---|---|---|---|---|---|---|---|"
    } >"$TABLE"
fi
echo "| $STAMP | $STRATEGY | $RATE | $READER_RPS | $DROPPED | $ORIGIN_RPS | $HIT_RATIO% | $P50 | $P95 | $CPU_AVG% | $UPDATE_P95 | $PUBLISH_P95 | $TIMEOUTS |" >>"$TABLE"

echo "==> Result (also appended to $TABLE)"
echo "active strategy: app $(compose exec -T app printenv INVALIDATION_STRATEGY), editor $(compose exec -T editor printenv INVALIDATION_STRATEGY)"
echo "strategy $STRATEGY: origin ${ORIGIN_RPS} req/s, hit ratio ${HIT_RATIO}%, p50 ${P50} ms, p95 ${P95} ms, origin CPU ${CPU_AVG}%, update visible p95 ${UPDATE_P95} s, publish visible p95 ${PUBLISH_P95} s (hits $HITS, misses $MISSES, backend requests $BACKEND over ${RUN_SECONDS}s, dropped $DROPPED, probe timeouts $TIMEOUTS)"
