// Readers browse the site through Varnish while an editor updates and publishes articles on the origin.
import http from 'k6/http';
import { check, randomSeed } from 'k6';

const SITE = __ENV.SITE_URL || 'http://varnish';
const ORIGIN = __ENV.ORIGIN_URL || 'http://app';
const READERS = Number(__ENV.READERS || 50);
const DURATION = __ENV.DURATION || '3m';
const EDIT_EVERY = Number(__ENV.EDIT_EVERY || 10);
const PUBLISH_EVERY = Number(__ENV.PUBLISH_EVERY || 6);
const SEED = Number(__ENV.SEED || 42);
// WARMUP=1 runs readers only, to fill the cache before a measured run.
const WARMUP = __ENV.WARMUP === '1';

// Readers favour recent articles: this share of article views goes to the newest HOT_ARTICLES.
const HOT_ARTICLES = 200;
const HOT_SHARE = 0.8;

const urls = JSON.parse(open('./urls.json'));

const scenarios = {
    readers: {
        executor: 'constant-vus',
        exec: 'reader',
        vus: READERS,
        duration: DURATION,
    },
};
if (!WARMUP) {
    scenarios.editor = {
        executor: 'constant-arrival-rate',
        exec: 'editor',
        rate: 1,
        timeUnit: `${EDIT_EVERY}s`,
        duration: DURATION,
        preAllocatedVUs: 1,
        maxVUs: 2,
    };
}

export const options = {
    scenarios,
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
    thresholds: {
        // Also makes k6 report reader-only metrics, excluding editor requests.
        'http_req_failed{scenario:readers}': ['rate<0.01'],
        'http_req_duration{scenario:readers}': ['p(95)<10000'],
        'http_reqs{scenario:readers}': ['count>0'],
    },
};

// Same seed per VU on every run, so each run requests the same page sequence.
randomSeed(SEED * 1000 + __VU);

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

function articleIndex() {
    return Math.random() < HOT_SHARE
        ? Math.floor(Math.random() * HOT_ARTICLES)
        : HOT_ARTICLES + Math.floor(Math.random() * (urls.articles.length - HOT_ARTICLES));
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

// Every PUBLISH_EVERY-th action publishes a new article, the others update a (mostly recent) one.
export function editor() {
    const iteration = __ITER;
    if (iteration % PUBLISH_EVERY === PUBLISH_EVERY - 1) {
        const res = http.post(`${ORIGIN}/editor/articles`, null, { tags: { name: 'publish' } });
        check(res, { 'publish 201': (r) => r.status === 201 });
        return;
    }

    const id = urls.articleIds[articleIndex()];
    const res = http.post(`${ORIGIN}/editor/articles/${id}`, null, { tags: { name: 'update' } });
    check(res, { 'update 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const out = { stdout: textSummary(data) };
    if (__ENV.SUMMARY_FILE) {
        out[__ENV.SUMMARY_FILE] = JSON.stringify(data);
    }
    return out;
}

function textSummary(data) {
    const d = data.metrics['http_req_duration{scenario:readers}'] || data.metrics.http_req_duration;
    const reqs = data.metrics['http_reqs{scenario:readers}'] || data.metrics.http_reqs;
    const failed = data.metrics['http_req_failed{scenario:readers}'];
    return [
        `readers: ${reqs ? reqs.values.count : 0} requests, ${reqs ? reqs.values.rate.toFixed(1) : 0} req/s`,
        `latency: p50 ${d.values.med.toFixed(1)} ms, p95 ${d.values['p(95)'].toFixed(1)} ms, p99 ${d.values['p(99)'].toFixed(1)} ms`,
        `failed: ${failed ? (failed.values.rate * 100).toFixed(2) : '0.00'}%`,
        '',
    ].join('\n');
}
