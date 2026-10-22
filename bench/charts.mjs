// Builds the README results: median per strategy over every run in bench/results/results.md,
// printed as a Markdown section, plus one themed SVG bar chart per measure in docs/charts/.
// Usage: node bench/charts.mjs <cpus>   (run by bench/report.sh in a node container)
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const TABLE = 'bench/results/results.md';
const OUT = 'docs/charts';
const CPUS = process.argv[2] ?? '?';

// Fixed order and color slot per strategy (dataviz reference palette, validated light and dark).
const STRATEGIES = [
  { key: 'full', name: 'A. Full purge', plain: 'Purge everything', light: '#eb6834', dark: '#d95926' },
  { key: 'tags', name: 'B. Tag-based', plain: 'Purge only what changed', light: '#2a78d6', dark: '#3987e5' },
  { key: 'ttl', name: 'TTL only', plain: 'Wait for expiry', light: '#1baf7a', dark: '#199e70' },
];

// results.md column names, in order after the Run and Strategy columns.
const COLUMNS = ['target', 'readerRps', 'dropped', 'originRps', 'hitRatio', 'p50', 'p95', 'cpu', 'updateVisible', 'publishVisible', 'timeouts'];

const rows = readFileSync(TABLE, 'utf8')
  .split('\n')
  .slice(2)
  .filter((line) => line.startsWith('|'))
  .map((line) => line.split('|').slice(1, -1).map((cell) => cell.trim()));

const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = sorted.length / 2;
  return sorted.length % 2 ? sorted[Math.floor(mid)] : (sorted[mid - 1] + sorted[mid]) / 2;
};

const stats = Object.fromEntries(
  STRATEGIES.map(({ key }) => {
    const runs = rows.filter((row) => row[1] === key);
    const values = Object.fromEntries(COLUMNS.map((column, i) => [column, median(runs.map((row) => parseFloat(row[i + 2])))]));
    return [key, { runs: runs.length, ...values }];
  }),
);

// Numbers people read: no needless decimals, but small values keep one.
const short = (value) => (value >= 10 ? Math.round(value).toLocaleString('en-US') : value.toFixed(value >= 1 ? 1 : 2).replace(/0$/, ''));

const CHARTS = [
  { file: 'origin', column: 'originRps', unit: ' req/s', title: 'Requests reaching the server, per second', note: 'Fewer means the cache absorbs more of the traffic' },
  { file: 'cpu', column: 'cpu', unit: '%', title: 'How busy the server is', note: '100% = one processor core fully busy' },
  { file: 'latency', column: 'p95', unit: ' ms', title: 'Slow page loads', note: '95% of pages load faster than this' },
  { file: 'update-visible', column: 'updateVisible', unit: ' s', title: 'Time before readers see an edited article', note: '95% of edits show up faster than this · checked every 0.5 s' },
  { file: 'publish-visible', column: 'publishVisible', unit: ' s', title: 'Time before a new article shows on the home page', note: '95% of new articles show up faster than this · checked every 0.5 s' },
];

const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;');

// Horizontal bars, square at the baseline with a 4px rounded data end (a bare 2px sliver when tiny).
function bar(x, y, width, height) {
  if (width < 6) return `<rect x="${x}" y="${y}" width="${Math.max(width, 2)}" height="${height}" />`;
  const r = 4;
  return `<path d="M${x},${y}h${width - r}a${r},${r} 0 0 1 ${r},${r}v${height - 2 * r}a${r},${r} 0 0 1 -${r},${r}h-${width - r}z" />`;
}

function svg({ column, unit, title, note }) {
  const W = 720, PAD = 24, LABEL = 200, VALUE = 90, BAR = 22, STEP = 38, TOP = 84;
  const max = Math.max(...STRATEGIES.map(({ key }) => stats[key][column]));
  const plot = W - PAD * 2 - LABEL - VALUE;
  const x0 = PAD + LABEL;
  const H = TOP + STRATEGIES.length * STEP + PAD - (STEP - BAR);

  const marks = STRATEGIES.map(({ key, plain }, i) => {
    const value = stats[key][column];
    const y = TOP + i * STEP;
    const width = (value / max) * plot;
    return `
  <text class="label" x="${PAD}" y="${y + BAR / 2}" dominant-baseline="central">${escape(plain)}</text>
  <g class="s-${key}">${bar(x0, y, width, BAR)}</g>
  <text class="value" x="${x0 + Math.max(width, 2) + 8}" y="${y + BAR / 2}" dominant-baseline="central">${short(value)}${unit}</text>`;
  }).join('');

  const series = (mode) => STRATEGIES.map((s) => `.s-${s.key}{fill:${s[mode]}}`).join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="${escape(title)}">
  <style>
    svg{--surface:#fcfcfb;--ink:#0b0b0b;--ink-2:#52514e;--axis:#c3c2b7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif}
    ${series('light')}
    @media (prefers-color-scheme: dark){svg{--surface:#1a1a19;--ink:#f0efec;--ink-2:#c3c2b7;--axis:#383835}${series('dark')}}
    .bg{fill:var(--surface)} .title{fill:var(--ink);font-size:17px;font-weight:600} .note{fill:var(--ink-2);font-size:13px}
    .label{fill:var(--ink);font-size:14px} .value{fill:var(--ink);font-size:14px;font-weight:600;font-variant-numeric:tabular-nums}
    .axis{stroke:var(--axis);stroke-width:1}
  </style>
  <rect class="bg" width="${W}" height="${H}" rx="12" />
  <text class="title" x="${PAD}" y="38">${escape(title)}</text>
  <text class="note" x="${PAD}" y="60">${escape(note)} · lower is better</text>
  <line class="axis" x1="${x0}" y1="${TOP - 6}" x2="${x0}" y2="${TOP + (STRATEGIES.length - 1) * STEP + BAR + 6}" />${marks}
</svg>
`;
}

mkdirSync(OUT, { recursive: true });
for (const chart of CHARTS) {
  writeFileSync(`${OUT}/${chart.file}.svg`, svg(chart));
}

const runs = stats.tags.runs;
const fixed = (value, digits) => value.toFixed(digits);
console.log(
  [
    `Median of ${runs} runs per strategy (${fixed(stats.tags.target, 0)} req/s target, ${CPUS} CPUs given to Docker, k6, Varnish and the app on the same machine). Compare strategies with each other rather than reading absolute numbers; \`make report\` rebuilds this section and the charts from \`bench/results/results.md\`.`,
    '',
    '| Strategy | Reader req/s | Dropped | Origin req/s | Hit ratio | p50 (ms) | p95 (ms) | Origin CPU | Update visible p95 (s) | Publish visible p95 (s) | Probe timeouts |',
    '|---|---|---|---|---|---|---|---|---|---|---|',
    ...STRATEGIES.map(({ key, name }) => {
      const s = stats[key];
      return `| ${name} | ${fixed(s.readerRps, 1)} | ${fixed(s.dropped, 0)} | ${fixed(s.originRps, 1)} | ${fixed(s.hitRatio, 1)}% | ${fixed(s.p50, 1)} | ${fixed(s.p95, 1)} | ${fixed(s.cpu, 1)}% | ${fixed(s.updateVisible, 2)} | ${fixed(s.publishVisible, 2)} | ${fixed(s.timeouts, 0)} |`;
    }),
    '',
    `In the charts, **purge everything** is strategy A, **purge only what changed** is strategy B (tags) and **wait for expiry** is TTL only (pages refresh on their own after 60 s). Every number is the median of ${runs} runs.`,
    '',
    ...CHARTS.map(({ file, title }) => `![${title}](${OUT}/${file}.svg)\n`),
    'Both purge strategies show changes in under a second; next to the 30 to 55 seconds of waiting for expiry their bars are barely visible. Times are checked every half second, so anything under a second counts as immediate.',
  ].join('\n'),
);
