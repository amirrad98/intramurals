// Calendar event posters: card thumbnail, dialog poster and full-size viewer.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../assets/js/calendar.js'), 'utf8');
const base = {
  source: 'event', kind: 'tournament', kindLabel: 'Tournament', sport: 'volleyball', sportLabel: 'Volleyball',
  day: '2038-04-20', start: '2038-04-20T18:30:00+00:00', end: '2038-04-20T19:30:00+00:00',
  time: '6:30 pm', endTime: '7:30 pm', status: 'scheduled', statusLabel: 'Scheduled', url: 'https://example.test/event',
};
const poster = { src: 'https://example.test/poster-768.jpg', width: 768, height: 1086, full: 'https://example.test/poster.jpg', fullWidth: 1414, fullHeight: 2000, alt: 'Spring tournament poster' };
const data = {
  config: {
    today: '2038-04-20', initialView: 'list',
    monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    weekdaysShort: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
  },
  strings: { viewPoster: 'View poster full size', backToEvent: 'Back to details', openPoster: 'Open in new tab' },
  events: [
    { ...base, id: 'event-1', postId: 1, title: 'Spring tournament', poster },
    { ...base, id: 'event-2', postId: 2, title: 'Open gym', poster: null },
  ],
};
const markup = `<div data-leagueflow-calendar>
  <button data-calendar-view="list">List</button><button data-calendar-view="month">Month</button>
  <input data-calendar-search><h3 data-calendar-range-label></h3>
  <button data-calendar-today>Today</button><button data-calendar-prev></button><button data-calendar-next></button>
  <div data-calendar-stage></div><h3 data-calendar-panel-title></h3><button data-calendar-clear hidden>Show full month</button>
  <div data-calendar-events></div>
  <div data-calendar-dialog hidden><article class="leagueflow-calendar__dialog-card" role="dialog" aria-modal="true"><button class="leagueflow-calendar__dialog-close" data-calendar-dialog-close>Close</button><div data-calendar-dialog-content></div></article></div>
  <script type="application/json" data-calendar-data>${JSON.stringify(data)}</script>
</div>`;

(async () => {
  const dom = new JSDOM(`<!doctype html><body>${markup}</body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.matchMedia = () => ({ matches: false, addEventListener() {} });
  w.HTMLElement.prototype.getClientRects = function () { return [{}]; };
  w.eval(source);
  if (w.document.readyState === 'loading') {
    await new Promise(resolve => w.document.addEventListener('DOMContentLoaded', resolve));
  }
  const d = w.document;
  const card = name => Array.from(d.querySelectorAll('[data-calendar-stage] .leagueflow-calendar__event')).find(node => node.textContent.includes(name));
  const dialog = d.querySelector('[data-calendar-dialog]');
  const article = dialog.querySelector('[role="dialog"]');
  const key = k => d.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true }));

  const thumb = card('Spring tournament').querySelector('.leagueflow-calendar__event-poster');
  assert.ok(thumb && thumb.getAttribute('src') === poster.src && thumb.alt === '', 'A list card shows a decorative poster thumbnail.');
  assert.ok(card('Spring tournament').classList.contains('has-poster'));
  assert.equal(card('Open gym').querySelector('.leagueflow-calendar__event-poster'), null, 'Events without a poster have no thumbnail.');

  card('Spring tournament').click();
  const open = dialog.querySelector('.leagueflow-calendar__poster');
  assert.ok(open && 'BUTTON' === open.tagName, 'The dialog shows the poster as a button.');
  assert.equal(open.getAttribute('aria-label'), 'View poster full size: Spring tournament poster');
  assert.equal(open.querySelector('img').getAttribute('src'), poster.src);
  const viewer = dialog.querySelector('.leagueflow-calendar__poster-viewer');
  assert.equal(viewer.hidden, true);
  assert.equal(viewer.querySelector('img').getAttribute('src'), null, 'The full image loads only when asked for.');

  open.click();
  assert.equal(viewer.hidden, false, 'Activating the poster shows the full image.');
  assert.ok(article.classList.contains('is-viewing-poster'));
  assert.equal(viewer.querySelector('img').getAttribute('src'), poster.full);
  assert.equal(viewer.querySelector('img').alt, 'Spring tournament poster');
  assert.equal(d.activeElement.textContent, '‹ Back to details', 'Focus moves to the way back.');
  const tab = Array.from(viewer.querySelectorAll('a')).find(link => link.textContent === 'Open in new tab');
  assert.equal(tab.href, poster.full);

  key('Escape');
  assert.equal(dialog.hidden, false, 'Escape first leaves the full-size poster.');
  assert.equal(viewer.hidden, true);
  assert.ok(!article.classList.contains('is-viewing-poster'));
  assert.equal(d.activeElement, open, 'Focus returns to the poster.');
  key('Escape');
  assert.equal(dialog.hidden, true, 'A second Escape closes the dialog.');

  card('Spring tournament').click();
  dialog.querySelector('.leagueflow-calendar__poster').click();
  dialog.querySelector('[data-calendar-dialog-close]').click();
  card('Open gym').click();
  assert.equal(dialog.querySelector('.leagueflow-calendar__poster'), null, 'Events without a poster show none.');
  assert.ok(!article.classList.contains('is-viewing-poster'), 'A closed viewer does not carry over to the next event.');
  dom.window.close();
  console.log('PASS Calendar event posters (' + (process.env.TZ || 'default timezone') + ').');
})().catch(error => { console.error(error); process.exitCode = 1; });
