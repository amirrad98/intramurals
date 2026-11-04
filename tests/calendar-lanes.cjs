// Week/Day lane regression fixture: source-level DOM checks with synthetic events,
// not real browser evidence. Run npm run test:calendar (three browser timezones).
// Usage: node tests/calendar-lanes.cjs [path/to/calendar.js]
const { JSDOM } = require('jsdom');
const fs = require('fs');
const src = fs.readFileSync(process.argv[2] || require('path').join(__dirname, '../assets/js/calendar.js'), 'utf8');

// Site timezone UTC; the week of Sun 2026-10-11 .. Sat 2026-10-17.
const ev = (id, day, start, end) => ({
  id, source: 'match', day,
  start: `${day}T${start}:00+00:00`, end: `${day}T${end}:00+00:00`,
  time: start, endTime: end, home: `Home ${id}`, away: `Away ${id}`,
  sport: 'soccer', sportLabel: 'Soccer', url: `https://example.test/m/${id}`
});
const events = [
  ev(1, '2026-10-15', '18:30', '19:30'), ev(2, '2026-10-15', '18:30', '19:30'), // two simultaneous
  ev(3, '2026-10-14', '19:00', '20:00'), ev(4, '2026-10-14', '19:00', '20:00'), ev(5, '2026-10-14', '19:00', '20:00'), // three
  ev(6, '2026-10-16', '18:00', '19:00'), ev(7, '2026-10-16', '18:30', '19:30'), ev(8, '2026-10-16', '19:00', '20:00'), // chain
  ev(9, '2026-10-13', '18:00', '19:00'), ev(10, '2026-10-13', '19:00', '20:00'), // adjacent, no overlap
  ev(11, '2026-10-12', '18:30', '19:30'), ev(12, '2026-10-17', '18:30', '19:30') // same time, other days
];
const data = {
  config: { today: '2026-10-13', monthNames: ['January','February','March','April','May','June','July','August','September','October','November','December'], weekdays: ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], weekdaysShort: ['Su','Mo','Tu','We','Th','Fr','Sa'] },
  strings: { today: 'Today', date: 'Date' },
  events
};
const markup = `<div data-leagueflow-calendar>
 <button data-calendar-view="month">Month</button><button data-calendar-view="week">Week</button><button data-calendar-view="day">Day</button>
 <input data-calendar-search>
 <h3 data-calendar-range-label></h3>
 <button data-calendar-today>Today</button><button data-calendar-prev></button><button data-calendar-next></button>
 <div data-calendar-stage></div>
 <h3 data-calendar-panel-title></h3><button data-calendar-clear hidden>Show full month</button>
 <div data-calendar-events></div>
 <div data-calendar-dialog hidden><div data-calendar-dialog-close></div><article role="dialog" aria-modal="true"><button class="leagueflow-calendar__dialog-close" data-calendar-dialog-close>x</button><div data-calendar-dialog-content></div></article></div>
 <script type="application/json" data-calendar-data>${JSON.stringify(data)}</script></div>`;

let fail = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fail = 1; };

(async () => {
  const dom = new JSDOM(`<!doctype html><body>${markup}</body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, d = w.document;
  w.matchMedia = () => ({ matches: false, addEventListener() {} });
  w.HTMLElement.prototype.getClientRects = function () { return [ {} ]; };
  w.eval(src);
  if (d.readyState === 'loading') await new Promise((r) => d.addEventListener('DOMContentLoaded', r));
  console.log('TZ=' + (process.env.TZ || '(unset)'));

  const blocksIn = (col) => Array.from(col.querySelectorAll('.leagueflow-calendar__time-event')).map((b) => ({
    b, id: +b.querySelector('strong').textContent.replace(/^Home (\d+).*/, '$1'),
    top: parseFloat(b.style.top), bottom: parseFloat(b.style.top) + parseFloat(b.style.height),
    lane: b.classList.contains('is-concurrent') ? +b.style.getPropertyValue('--lf-cal-lane') : 0,
    lanes: b.classList.contains('is-concurrent') ? +b.style.getPropertyValue('--lf-cal-lanes') : 1
  }));
  // Every pair of overlapping blocks in one column must sit in different lanes.
  const noVisualOverlap = (list) => list.every((a, i) => list.every((c, j) => j <= i || !(a.top < c.bottom && c.top < a.bottom) || (a.lane !== c.lane && a.lanes === c.lanes)));

  d.querySelector('[data-calendar-view="week"]').click();
  const cols = Array.from(d.querySelectorAll('.leagueflow-calendar__week-col'));
  ok(cols.length === 7, 'week view renders 7 day columns');
  const byDay = Object.fromEntries(cols.map((c, i) => [11 + i, blocksIn(c)]));
  ok(Object.values(byDay).flat().length === events.length, 'every synthetic event renders exactly once in the week');

  const pair = byDay[15];
  ok(pair.length === 2 && pair.every((x) => x.lanes === 2) && new Set(pair.map((x) => x.lane)).size === 2, 'two simultaneous events get two lanes: ' + JSON.stringify(pair.map((x) => [x.id, x.lane, x.lanes])));
  const three = byDay[14];
  ok(three.length === 3 && three.every((x) => x.lanes === 3) && new Set(three.map((x) => x.lane)).size === 3, 'three simultaneous events get three lanes: ' + JSON.stringify(three.map((x) => [x.id, x.lane, x.lanes])));
  const chain = Object.fromEntries(byDay[16].map((x) => [x.id, x]));
  ok(chain[6] && chain[6].lanes === 2 && chain[7].lanes === 2 && chain[8].lanes === 2 && chain[6].lane === 0 && chain[7].lane === 1 && chain[8].lane === 0, 'chained overlaps (6-7, 7-8) share two lanes and 8 reuses lane 0: ' + JSON.stringify(byDay[16].map((x) => [x.id, x.lane, x.lanes])));
  ok(byDay[13].length === 2 && byDay[13].every((x) => x.lanes === 1 && !x.b.classList.contains('is-concurrent')), 'adjacent events (one ends as the next starts) stay full width');
  ok(byDay[12].length === 1 && byDay[17].length === 1 && byDay[12][0].lanes === 1 && byDay[17][0].lanes === 1, 'same time on different days is not treated as concurrent');
  ok(cols.every((c) => noVisualOverlap(blocksIn(c))), 'no two overlapping blocks share a lane in any column');
  ok(pair.every((x) => x.top === 756), 'wall-clock position unchanged (6:30 pm => 756px)');

  // Event identity and links are preserved: the second lane opens event 2's own dialog.
  const second = pair.find((x) => x.lane === 1) || pair[1];
  second.b.click();
  const dialog = d.querySelector('[data-calendar-dialog]');
  const links = Array.from(dialog.querySelectorAll('a[href]')).map((a) => a.href);
  ok(!dialog.hidden && dialog.textContent.includes(`Home ${second.id}`) && links.includes(`https://example.test/m/${second.id}`), `lane ${second.lane} block opens its own event (${second.id}) and link`);
  d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

  // Day view uses the same lanes.
  d.querySelector('[data-calendar-view="day"]').click();
  d.querySelector('[data-calendar-next]').click();
  d.querySelector('[data-calendar-next]').click(); // Oct 13 -> Oct 15
  const dayBlocks = blocksIn(d.querySelector('.leagueflow-calendar__day-col'));
  ok(dayBlocks.length === 2 && dayBlocks.every((x) => x.lanes === 2) && new Set(dayBlocks.map((x) => x.lane)).size === 2, 'day view (Oct 15) splits the simultaneous pair into lanes');

  // Filters still apply before layout: searching one event leaves it full width.
  const search = d.querySelector('[data-calendar-search]');
  search.value = 'Home 2';
  search.dispatchEvent(new w.Event('input', { bubbles: true }));
  const filtered = blocksIn(d.querySelector('.leagueflow-calendar__day-col'));
  ok(filtered.length === 1 && filtered[0].id === 2 && filtered[0].lanes === 1, 'search filter runs before lane layout (single result is full width)');
  process.exit(fail);
})();
