// Readers browse the site through Varnish at a fixed rate while an editor updates and publishes articles
// through the editor service, then measures how long each change takes to become visible through the cache.
import http from 'k6/http';
import exec from 'k6/execution';
import { check, randomSeed, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const SITE = __ENV.SITE_URL || 'http://varnish';
const EDITOR = __ENV.EDITOR_URL || 'http://editor:3000';
// Open model: readers send RATE requests per second whatever the response times, so strategies serve the same load.
const RATE = Number(__ENV.RATE || 1000);
const DURATION = __ENV.DURATION || '3m';
const EDIT_EVERY = Number(__ENV.EDIT_EVERY || 10);
const PUBLISH_EVERY = Number(__ENV.PUBLISH_EVERY || 6);
const PROBE_TIMEOUT = Number(__ENV.PROBE_TIMEOUT || 120);
const PROBE_INTERVAL = 0.5;
const SEED = Number(__ENV.SEED || 42);
// WARMUP=1 runs readers only, to fill the cache before a measured run.
const WARMUP = __ENV.WARMUP === '1';

// Readers favour recent articles: this share of article views goes to the newest HOT_ARTICLES.
const HOT_ARTICLES = 200;
const HOT_SHARE = 0.8;

const urls = JSON.parse(open('./urls.json'));

// Time from the editor's successful response to the change being served by Varnish.
const updateVisible = new Trend('update_visible_after', true);
const publishVisible = new Trend('publish_visible_after', true);
const probeTimeouts = new Counter('probe_timeouts');

const scenarios = {
    readers: {
        executor: 'constant-arrival-rate',
        exec: 'reader',
        rate: RATE,
        timeUnit: '1s',
        duration: DURATION,
        preAllocatedVUs: Math.max(20, Math.ceil(RATE / 20)),
        maxVUs: Math.max(100, RATE),
    },
};
if (!WARMUP) {
    scenarios.editor = {
        executor: 'constant-arrival-rate',
        exec: 'editor',
        rate: 1,
        timeUnit: `${EDIT_EVERY}s`,
        duration: DURATION,
        // Probes can outlive one edit interval (TTL-only strategy), so allow overlapping editor iterations.
        preAllocatedVUs: 2,
        maxVUs: Math.ceil(PROBE_TIMEOUT / EDIT_EVERY) + 2,
        // Let the last probes finish after readers stop; run.sh only measures the readers' window.
        gracefulStop: `${PROBE_TIMEOUT + 10}s`,
    };
}

export const options = {
    scenarios,
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
    thresholds: {
        // Also makes k6 report reader-only metrics, excluding editor and probe requests.
        'http_req_failed{scenario:readers}': ['rate<0.01'],
        'http_req_duration{scenario:readers}': ['p(95)<10000'],
        'http_reqs{scenario:readers}': ['count>0'],
        'dropped_iterations{scenario:readers}': ['count>=0'],
        update_visible_after: ['p(95)>=0'],
        publish_visible_after: ['p(95)>=0'],
    },
};

// Same seed per VU on every run, so each VU requests the same page sequence.
randomSeed(SEED * 1000 + __VU);

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

function articleIndex(random = Math.random) {
    return random() < HOT_SHARE
        ? Math.floor(random() * HOT_ARTICLES)
        : HOT_ARTICLES + Math.floor(random() * (urls.articles.length - HOT_ARTICLES));
}

// Page mix: 15% home, 20% category, 10% author, 55% article.
function readerPath() {
    const roll = Math.random();
    if (roll < 0.15) return { path: '/', type: 'home' };
    if (roll < 0.35) return { path: pick(urls.categories), type: 'category' };
    if (roll < 0.45) return { path: pick(urls.authors), type: 'author' };
    return { path: urls.articles[articleIndex()], type: 'article' };
}

export function reader() {
    const { path, type } = readerPath();
    const res = http.get(`${SITE}${path}`, { tags: { page: type, name: type } });
    check(res, { 'page 200': (r) => r.status === 200 });
}

// Deterministic per editor action, whichever VU runs it.
function seededRandom(seed) {
    let state = seed >>> 0;
    return () => {
        state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
        return state / 4294967296;
    };
}

// Polls a page through Varnish until isFresh(body) and records the delay since the edit.
function probe(path, isFresh, trend, editedAt) {
    while ((Date.now() - editedAt) / 1000 < PROBE_TIMEOUT) {
        const res = http.get(`${SITE}${path}`, { tags: { name: 'probe' } });
        if (res.status === 200 && isFresh(res.body)) {
            trend.add(Date.now() - editedAt);
            return;
        }
        sleep(PROBE_INTERVAL);
    }
    probeTimeouts.add(1);
}

// Every PUBLISH_EVERY-th action publishes a new article, the others update a (mostly recent) one.
export function editor() {
    const action = exec.scenario.iterationInTest;

    if (action % PUBLISH_EVERY === PUBLISH_EVERY - 1) {
        const res = http.post(`${EDITOR}/articles`, null, { tags: { name: 'publish' } });
        if (!check(res, { 'publish 201': (r) => r.status === 201 })) return;
        const slug = res.json('slug');
        probe('/', (body) => body.includes(`/article/${slug}"`), publishVisible, Date.now());
        return;
    }

    const index = articleIndex(seededRandom(SEED * 100000 + action));
    const res = http.post(`${EDITOR}/articles/${urls.articleIds[index]}`, null, { tags: { name: 'update' } });
    if (!check(res, { 'update 200': (r) => r.status === 200 })) return;
    const revision = res.json('revision');
    // The article's own meta line is the first "rev N" on its page.
    probe(urls.articles[index], (body) => Number((body.match(/rev (\d+)/) || [])[1]) >= revision, updateVisible, Date.now());
}

export function handleSummary(data) {
    const out = { stdout: textSummary(data) };
    if (__ENV.SUMMARY_FILE) {
        out[__ENV.SUMMARY_FILE] = JSON.stringify(data);
    }
    return out;
}

function trendLine(label, metric) {
    if (!metric) return `${label}: n/a`;
    const v = metric.values;
    return `${label}: p50 ${(v.med / 1000).toFixed(2)} s, p95 ${(v['p(95)'] / 1000).toFixed(2)} s, max ${(v.max / 1000).toFixed(2)} s`;
}

// Reader rate over the readers' window only (the editor's last probes may run longer).
function seconds(duration) {
    const match = /^(?:(\d+)m)?(?:(\d+)s)?$/.exec(duration);
    return match && (match[1] || match[2]) ? Number(match[1] || 0) * 60 + Number(match[2] || 0) : Number(duration);
}

function textSummary(data) {
    const m = data.metrics;
    const d = m['http_req_duration{scenario:readers}'];
    const reqs = m['http_reqs{scenario:readers}'];
    const failed = m['http_req_failed{scenario:readers}'];
    const dropped = m['dropped_iterations{scenario:readers}'];
    return [
        `readers: ${reqs.values.count} requests, ${(reqs.values.count / seconds(DURATION)).toFixed(1)} req/s (target ${RATE}), dropped ${dropped ? dropped.values.count : 0}`,
        `latency: p50 ${d.values.med.toFixed(1)} ms, p95 ${d.values['p(95)'].toFixed(1)} ms, p99 ${d.values['p(99)'].toFixed(1)} ms`,
        `failed: ${failed ? (failed.values.rate * 100).toFixed(2) : '0.00'}%`,
        trendLine('update visible after', m.update_visible_after),
        trendLine('publish visible after', m.publish_visible_after),
        `probe timeouts: ${m.probe_timeouts ? m.probe_timeouts.values.count : 0}`,
        '',
    ].join('\n');
}
