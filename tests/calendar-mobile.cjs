// Phone month and list layout: source-level DOM checks, not real browser evidence.
// Run npm run test:calendar; the runner exercises three browser timezones.
const { JSDOM } = require('jsdom');
const fs = require('fs');
const src = fs.readFileSync(process.argv[2] || require('path').join(__dirname, '../assets/js/calendar.js'), 'utf8');
const match = (id, day, time, home, away, sport) => ({ id, source: 'match', day, start: `${day}T${time}:00+00:00`, end: `${day}T${time.replace(/^(\d\d)/, h => String(+h + 1).padStart(2, '0'))}:00+00:00`, time, endTime: '', home, away, sport, sportLabel: sport, url: `https://example.test/m/${id}` });
const data = {
  config: { today: '2026-10-13', monthDisplay: 'sidebar', showWeek: true, showDay: true, monthNames: ['January','February','March','April','May','June','July','August','September','October','November','December'], weekdays: ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], weekdaysShort: ['Su','Mo','Tu','We','Th','Fr','Sa'] },
  strings: { event: 'event', eventsPlural: 'events' },
  sports: [ { slug: 'volleyball', color: '#1f5f3f' }, { slug: 'soccer', color: '#c5a04a' } ],
  events: [
    match(1, '2026-10-13', '18:30', 'Alpha', 'Bravo', 'volleyball'),
    match(2, '2026-10-15', '18:30', 'Charlie', 'Delta', 'volleyball'),
    match(3, '2026-10-15', '20:30', 'Echo', 'Foxtrot', 'soccer'),
    match(4, '2026-10-20', '19:00', 'Golf', 'Hotel', 'soccer')
  ]
};
const markup = `<div class="leagueflow leagueflow-calendar" id="cal" data-leagueflow-calendar>
 <button data-calendar-view="month" aria-pressed="true">Month</button><button data-calendar-view="list" aria-pressed="false">List</button><button data-calendar-view="week" aria-pressed="false">Week</button><button data-calendar-view="day" aria-pressed="false">Day</button>
 <input data-calendar-search>
 <div class="leagueflow-calendar__layout"><section class="leagueflow-calendar__board"><h3 data-calendar-range-label></h3>
 <button data-calendar-today>Today</button><button data-calendar-prev></button><button data-calendar-next></button>
 <div data-calendar-stage></div></section>
 <aside class="leagueflow-calendar__panel" data-calendar-panel><div class="leagueflow-calendar__panel-head"><h3 data-calendar-panel-title></h3><button data-calendar-clear hidden>Show full month</button><button data-calendar-panel-close hidden>Close day</button></div>
 <div data-calendar-events></div></aside></div>
 <div data-calendar-dialog hidden><div data-calendar-dialog-close></div><article role="dialog" aria-modal="true"><button class="leagueflow-calendar__dialog-close" data-calendar-dialog-close>x</button><div data-calendar-dialog-content></div></article></div>
 <script type="application/json" data-calendar-data>${JSON.stringify(data)}</script></div>`;
function run(mobile) {
  const dom = new JSDOM(`<!doctype html><body>${markup}</body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: mobile, addEventListener() {}, removeEventListener() {} });
  w.HTMLElement.prototype.getClientRects = function () { return [ {} ]; };
  w.eval(src);
  return new Promise(r => w.document.readyState === 'loading' ? w.document.addEventListener('DOMContentLoaded', () => r(w)) : r(w));
}
let fail = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fail = 1; };
(async () => {
  const w = await run(true), d = w.document, root = d.getElementById('cal');
  ok(root.classList.contains('is-mobile-calendar') && root.classList.contains('is-view-month'), 'phone month: root carries is-mobile-calendar and is-view-month');
  const cells = Array.from(d.querySelectorAll('.leagueflow-calendar__grid > .leagueflow-calendar__day'));
  ok(cells.length % 7 === 0 && cells.length >= 28, 'phone month renders a 7-column grid of day cells: ' + cells.length);
  const today = d.querySelector('[data-day="2026-10-13"]');
  ok(today && today.tagName === 'BUTTON' && today.classList.contains('is-selected') && today.getAttribute('aria-expanded') === 'true', 'today is the selected day by default and reports aria-expanded');
  ok(today && today.getAttribute('aria-controls') === d.querySelector('[data-calendar-panel]').id, 'day buttons control the day panel');
  const dots = d.querySelectorAll('[data-day="2026-10-15"] .leagueflow-calendar__day-event');
  ok(dots.length === 2, 'a day with two games shows two event markers (dots on phones): ' + dots.length);
  ok(/15/.test(d.querySelector('[data-day="2026-10-15"]').getAttribute('aria-label')) && /2 events/.test(d.querySelector('[data-day="2026-10-15"]').getAttribute('aria-label')), 'day button names the date and event count for screen readers');
  const panel = d.querySelector('[data-calendar-panel]');
  ok(!panel.hidden, 'selected-day list (card footer) is visible');
  let count = d.querySelector('[data-calendar-panel-count]');
  ok(count && !count.hidden && count.textContent === '1 event', 'panel count pill reads "1 event" for today: ' + (count && count.textContent));
  ok(count && count.previousElementSibling === d.querySelector('[data-calendar-panel-title]'), 'count pill sits beside the panel title');
  d.querySelector('[data-day="2026-10-15"]').click();
  count = d.querySelector('[data-calendar-panel-count]');
  ok(d.querySelector('[data-day="2026-10-15"]').classList.contains('is-selected') && !d.querySelector('[data-day="2026-10-13"]').classList.contains('is-selected'), 'tapping a day moves the selection');
  ok(d.querySelectorAll('[data-calendar-events] .leagueflow-calendar__event').length === 2 && count.textContent === '2 events', 'selected day lists its two games with a "2 events" pill');
  ok(d.activeElement === d.querySelector('[data-day="2026-10-15"]'), 'focus stays on the tapped day');
  ok(d.querySelectorAll('[data-calendar-panel-count]').length === 1, 'the panel count pill is created once');
  ok(d.querySelector('[data-calendar-clear]').hidden, 'the month view has no "Show full month" deselect button');
  d.querySelector('[data-day="2026-10-15"]').click();
  ok(d.querySelector('[data-day="2026-10-15"]').classList.contains('is-selected') && !d.querySelector('[data-day="2026-10-13"]').classList.contains('is-selected'), 'tapping the selected day on a phone keeps it selected');
  d.querySelector('[data-calendar-view="week"]').click();
  ok(root.classList.contains('is-view-month') && !root.classList.contains('is-view-week'), 'week view falls back to month on phones');

  // List view: date heading, hairline and count pill.
  d.querySelector('[data-calendar-view="list"]').click();
  const header = d.querySelector('.leagueflow-calendar__list-date');
  const parts = header ? Array.from(header.children).map(n => n.tagName + '.' + (n.className || '')) : [];
  ok(header && header.children.length === 3 && header.children[0].tagName === 'H4', 'list date header: heading first: ' + JSON.stringify(parts));
  ok(header && header.children[1].classList.contains('leagueflow-calendar__list-rule') && header.children[1].getAttribute('aria-hidden') === 'true', 'list date header: decorative hairline is aria-hidden');
  ok(header && header.children[2].classList.contains('leagueflow-calendar__count') && header.children[2].textContent === '1 event', 'list date header: count pill "1 event"');
  const groups = Array.from(d.querySelectorAll('.leagueflow-calendar__list-group'));
  ok(groups.some(g => g.querySelector('.leagueflow-calendar__count').textContent === '2 events'), 'a two-game date reads "2 events"');
  ok(d.querySelector('[data-calendar-panel]').hidden && d.querySelector('[data-calendar-panel-count]').hidden, 'list view hides the panel and its count');

  // Desktop month keeps full cells with an event summary per day and a month count.
  const dw = await run(false), dd = dw.document;
  ok(!dd.getElementById('cal').classList.contains('is-mobile-calendar'), 'desktop month is not marked mobile');
  ok(dd.querySelector('[data-calendar-panel-count]').textContent === '4 events', 'desktop sidebar shows the month count pill: ' + dd.querySelector('[data-calendar-panel-count]').textContent);
  const pill = dd.querySelector('[data-day="2026-10-15"] .leagueflow-calendar__day-event');
  ok(pill && /Charlie vs Delta/.test(pill.textContent), 'desktop day cell lists event text');
  dd.querySelector('[data-day="2026-10-15"]').click();
  ok(dd.querySelector('[data-calendar-panel-count]').textContent === '2 events' && dd.querySelector('[data-calendar-clear]').hidden, 'desktop: selecting a day filters the sidebar without a deselect button');
  dd.querySelector('[data-day="2026-10-15"]').click();
  ok(!dd.querySelector('[data-day="2026-10-15"]').classList.contains('is-selected') && dd.querySelector('[data-calendar-panel-count]').textContent === '4 events', 'desktop: selecting the day again returns the sidebar to the whole month');
  process.exit(fail);
})().catch(e => { console.error(e); process.exit(1); });
