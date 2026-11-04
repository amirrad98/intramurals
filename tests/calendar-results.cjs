// Calendar result presentation against the actual frontend script and DOM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../assets/js/calendar.js'), 'utf8');
const base = {
  source: 'match', kindLabel: 'Match', sport: 'soccer', sportLabel: 'Soccer',
  day: '2038-04-20', start: '2038-04-20T18:30:00+00:00', end: '2038-04-20T19:30:00+00:00',
  time: '6:30 pm', endTime: '7:30 pm', status: 'finished', statusLabel: 'Finished',
  away: 'Away', url: 'https://example.test/match',
};
const data = {
  config: {
    today: '2038-04-20', initialView: 'list',
    monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    weekdaysShort: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
  },
  strings: { score: 'Score', vs: 'vs' },
  events: [
    { ...base, id: 'forfeit', home: 'Forfeit Home', scoreline: '', outcomeLabel: 'Home team forfeited', resultLabel: 'Home team forfeited', description: 'Home team forfeited' },
    { ...base, id: 'zero', home: 'Draw Home', scoreline: '0 - 0', resultLabel: '', description: '' },
    { ...base, id: 'tied', home: 'Tied Home', scoreline: '2 - 2', resultLabel: 'Winner: Away', description: 'Winner: Away' },
    { ...base, id: 'invalid', home: 'Legacy Home', scoreline: '', resultLabel: 'Score unavailable', description: 'Score unavailable' },
  ],
};
const markup = `<div data-leagueflow-calendar>
  <button data-calendar-view="list">List</button><button data-calendar-view="month">Month</button>
  <input data-calendar-search><h3 data-calendar-range-label></h3>
  <button data-calendar-today>Today</button><button data-calendar-prev></button><button data-calendar-next></button>
  <div data-calendar-stage></div><h3 data-calendar-panel-title></h3><button data-calendar-clear hidden>Show full month</button>
  <div data-calendar-events></div>
  <div data-calendar-dialog hidden><article role="dialog" aria-modal="true"><button class="leagueflow-calendar__dialog-close" data-calendar-dialog-close>Close</button><div data-calendar-dialog-content></div></article></div>
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
  const close = () => dialog.querySelector('[data-calendar-dialog-close]').click();
  const details = () => Object.fromEntries(Array.from(dialog.querySelectorAll('dt')).map(node => [node.textContent, node.nextElementSibling.textContent]));

  assert.equal(card('Forfeit Home').querySelector('.leagueflow-calendar__event-score').textContent, '—');
  assert.equal(card('Forfeit Home').querySelector('.leagueflow-calendar__event-result').textContent, 'Home team forfeited');
  card('Forfeit Home').click();
  assert.equal(dialog.hidden, false);
  assert.equal(dialog.querySelector('.leagueflow-calendar__dialog-description').textContent, 'Home team forfeited');
  assert.equal(details().Score, undefined, 'A forfeit must not present a played score.');
  const google = Array.from(dialog.querySelectorAll('a')).find(link => link.href.includes('calendar.google'));
  assert.equal(new URL(google.href).searchParams.get('details'), 'Home team forfeited', 'Calendar exports preserve the explanation.');
  close();

  assert.equal(card('Draw Home').querySelector('.leagueflow-calendar__event-score').textContent, '0 - 0');
  assert.equal(card('Draw Home').querySelector('.leagueflow-calendar__event-result'), null);
  card('Draw Home').click();
  assert.equal(details().Score, '0 - 0', 'Dialog displays its played score, including two explicit zeros.');
  close();

  assert.equal(card('Tied Home').querySelector('.leagueflow-calendar__event-result').textContent, 'Winner: Away');
  card('Tied Home').click();
  assert.equal(details().Score, '2 - 2');
  assert.equal(dialog.querySelector('.leagueflow-calendar__dialog-description').textContent, 'Winner: Away', 'A tied knockout decision retains its winner in details.');
  close();

  assert.equal(card('Legacy Home').querySelector('.leagueflow-calendar__event-score').textContent, '—');
  assert.equal(card('Legacy Home').querySelector('.leagueflow-calendar__event-result').textContent, 'Score unavailable');
  dom.window.close();
  console.log('PASS Calendar result cards, details and export descriptions (' + (process.env.TZ || 'default timezone') + ').');
})().catch(error => { console.error(error); process.exitCode = 1; });
