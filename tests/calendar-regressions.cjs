// Calendar regression fixture: source-level DOM checks, not real browser evidence.
// Run npm run test:calendar; the runner exercises three browser timezones.
const { JSDOM } = require('jsdom');
const fs = require('fs');
const src = fs.readFileSync(process.argv[2] || require('path').join(__dirname, '../assets/js/calendar.js'), 'utf8');
const data = {
  config: { today: '2026-10-13', monthNames: ['January','February','March','April','May','June','July','August','September','October','November','December'], weekdays: ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], weekdaysShort: ['Su','Mo','Tu','We','Th','Fr','Sa'] },
  strings: { today: 'Today', date: 'Date' },
  // Site timezone UTC: server emits ISO 8601 with +00:00 (DateTime::format('c')).
  events: [
    { id: 1, source: 'match', day: '2026-10-15', start: '2026-10-15T18:30:00+00:00', end: '2026-10-15T19:30:00+00:00', time: '6:30 pm', endTime: '7:30 pm', home: 'Fixture A', away: 'Fixture B', sport: 'volleyball', sportLabel: 'Volleyball', url: 'https://example.test/m/1' }
  ]
};
const markup = `<div data-leagueflow-calendar>
 <button data-calendar-view="month">Month</button><button data-calendar-view="day">Day</button>
 <input data-calendar-search>
 <h3 data-calendar-range-label></h3>
 <button data-calendar-today>Today</button><button data-calendar-prev></button><button data-calendar-next></button>
 <div data-calendar-stage></div>
 <h3 data-calendar-panel-title></h3><button data-calendar-clear hidden>Show full month</button>
 <div data-calendar-events></div>
 <div data-calendar-dialog hidden><div data-calendar-dialog-close></div><article role="dialog" aria-modal="true"><button class="leagueflow-calendar__dialog-close" data-calendar-dialog-close>x</button><div data-calendar-dialog-content></div></article></div>
 <script type="application/json" data-calendar-data>${JSON.stringify(data)}</script></div>`;
function run() {
  const dom = new JSDOM(`<!doctype html><body><a href="#before" id="before">before</a>${markup}</body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: false, addEventListener() {} });
  // jsdom has no layout; treat every element as rendered for the focus-trap visibility filter.
  w.HTMLElement.prototype.getClientRects = function () { return [ {} ]; };
  w.eval(src);
  return new Promise(r => w.document.readyState === 'loading' ? w.document.addEventListener('DOMContentLoaded', () => r(w)) : r(w));
}
let fail = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fail = 1; };
(async () => {
const w = await run(), d = w.document;
console.log('TZ=' + (process.env.TZ || '(unset)') + ' offsetMinutes=' + new Date('2026-10-15T00:00:00Z').getTimezoneOffset());
// F-012: day view block position uses site wall-clock (6:30 pm => (18.5 - 8) * 72 = 756px).
d.querySelector('[data-calendar-view="day"]').click();
d.querySelector('[data-calendar-today]').click();
d.querySelector('[data-calendar-next]').click();
d.querySelector('[data-calendar-next]').click();
const block = d.querySelector('.leagueflow-calendar__time-event');
ok(!!block, 'day view renders a timed block for Oct 15');
ok(block && block.style.top === '756px', 'F-012 timed block top is 756px (6:30 pm wall clock): got ' + (block && block.style.top));
// Open dialog from the timed block.
block.focus();
block.click();
const dialog = d.querySelector('[data-calendar-dialog]');
ok(!dialog.hidden, 'dialog opens');
const dts = Array.from(dialog.querySelectorAll('dt')).map(n => n.textContent);
ok(dts[0] === 'Date' && dts.indexOf('Today') === -1, 'F-011 date row labelled "Date", not "Today": ' + JSON.stringify(dts));
// ICS/Google keep the real instant (18:30Z).
const google = Array.from(dialog.querySelectorAll('a')).find(a => /calendar\.google/.test(a.href));
ok(google && /dates=20261015T183000Z/.test(google.href), 'Google link keeps UTC instant 20261015T183000Z');
// F-016: focus trap.
const close = dialog.querySelector('.leagueflow-calendar__dialog-close');
ok(d.activeElement === close, 'focus moves to Close on open');
const tab = (shift) => { const e = new w.KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, bubbles: true, cancelable: true }); d.activeElement.dispatchEvent(e); return e.defaultPrevented; };
const prevented = tab(true);
ok(prevented && dialog.querySelector('article').contains(d.activeElement), 'F-016 Shift+Tab from Close wraps inside dialog (to ' + d.activeElement.textContent + ')');
const links = dialog.querySelectorAll('article a[href]');
links[links.length - 1].focus();
ok(tab(false) && d.activeElement === close, 'F-016 Tab from last action wraps to Close');
d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
ok(dialog.hidden && d.activeElement === block, 'Escape closes and restores focus to opener');
process.exit(fail);
})();
