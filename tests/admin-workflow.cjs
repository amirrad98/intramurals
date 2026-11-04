/** Admin controls: score intent, scoped filters and accurate batch selections. */
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const dom = new JSDOM( `<!doctype html><html><body>
<form id="filters">
  <select id="leagueflow-filter-by-sport-top" name="lf_sport"><option value="soccer">Soccer</option><option value="volleyball">Volleyball</option></select>
  <select name="lf_competition_filter"><option value="">All competitions</option><option value="12" selected>Soccer cup</option></select>
  <select name="lf_season_filter"><option value="">All seasons</option><option value="15" selected>Soccer season</option></select>
  <input name="lf_batch" value="previous-soccer-run">
  <select name="lf_league_level"><option value="recreational">Recreational</option></select>
</form>
<form id="selection"><input id="select-all" type="checkbox" data-leagueflow-select-all="records[]">
  <input id="record-a" name="records[]" type="checkbox" checked>
  <input id="record-b" name="records[]" type="checkbox">
  <input id="record-disabled" name="records[]" type="checkbox" disabled>
</form>
<form id="result">
  <input id="lf_home_score" type="number"><input id="lf_away_score" type="number">
  <select id="lf_status"><option value="scheduled">Scheduled</option><option value="live">Live</option><option value="finished">Finished</option><option value="postponed">Postponed</option><option value="cancelled">Cancelled</option></select>
  <select id="lf_outcome"><option value="">Played</option><option value="forfeit_home">Home forfeit</option><option value="forfeit_away">Away forfeit</option><option value="double_forfeit">Double forfeit</option></select>
  <input id="lf_keep_status" type="checkbox">
  <p data-leagueflow-result-preview data-final-text="Will save as Finished." data-status-text="Will save as %s." data-incomplete-text="Enter both scores." data-invalid-text="Invalid score." data-score-max="9223372036854775807">Complete scores finish Scheduled matches.</p>
</form></body></html>`, { runScripts: 'outside-only', url: 'http://leagueflow.test/wp-admin/edit.php' } );
const { document, Event } = dom.window;
dom.window.eval( fs.readFileSync( path.join( __dirname, '../assets/js/admin.js' ), 'utf8' ) );
document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
const byId = ( id ) => document.getElementById( id );
const changed = ( field ) => field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
const set = ( id, value ) => { byId( id ).value = value; changed( byId( id ) ); };
const preview = () => document.querySelector( '[data-leagueflow-result-preview]' ).textContent;

set( 'leagueflow-filter-by-sport-top', 'volleyball' );
assert.equal( byId( 'filters' ).elements.namedItem( 'lf_competition_filter' ).value, '' );
assert.equal( byId( 'filters' ).elements.namedItem( 'lf_season_filter' ).value, '' );
assert.equal( byId( 'filters' ).elements.namedItem( 'lf_batch' ).value, '' );
assert.equal( byId( 'filters' ).elements.namedItem( 'lf_league_level' ).value, 'recreational' );

assert.equal( byId( 'select-all' ).indeterminate, true );
byId( 'select-all' ).checked = true;
changed( byId( 'select-all' ) );
assert.equal( byId( 'record-a' ).checked, true );
assert.equal( byId( 'record-b' ).checked, true );
assert.equal( byId( 'record-disabled' ).checked, false );
assert.equal( byId( 'select-all' ).indeterminate, false );
byId( 'record-b' ).checked = false;
changed( byId( 'record-b' ) );
assert.equal( byId( 'select-all' ).checked, false );
assert.equal( byId( 'select-all' ).indeterminate, true );

assert.equal( preview(), 'Complete scores finish Scheduled matches.' );
set( 'lf_home_score', '0' );
assert.equal( preview(), 'Complete scores finish Scheduled matches.', 'A blank away score is not zero' );
set( 'lf_away_score', '0' );
assert.equal( preview(), 'Will save as Finished.', 'An explicit 0–0 is complete' );
set( 'lf_status', 'live' );
assert.equal( preview(), 'Will save as Live.' );
set( 'lf_status', 'postponed' );
assert.equal( preview(), 'Will save as Postponed.' );
set( 'lf_status', 'cancelled' );
assert.equal( preview(), 'Will save as Cancelled.' );
set( 'lf_status', 'scheduled' );
byId( 'lf_keep_status' ).checked = true;
changed( byId( 'lf_keep_status' ) );
assert.equal( preview(), 'Will save as Scheduled.', 'Explicit status retention is visible' );
byId( 'lf_keep_status' ).checked = false;
changed( byId( 'lf_keep_status' ) );
assert.equal( preview(), 'Will save as Finished.' );
set( 'lf_home_score', '' );
set( 'lf_away_score', '' );
for ( const outcome of [ 'forfeit_home', 'forfeit_away', 'double_forfeit' ] ) {
  set( 'lf_outcome', outcome );
  assert.equal( preview(), 'Will save as Finished.', outcome );
}
set( 'lf_outcome', '' );
set( 'lf_status', 'finished' );
assert.equal( preview(), 'Enter both scores.' );
set( 'lf_home_score', '3.5' );
assert.equal( preview(), 'Invalid score.' );
set( 'lf_home_score', '9223372036854775808' );
set( 'lf_away_score', '2' );
assert.equal( preview(), 'Invalid score.', 'Overflow comparison preserves integer precision' );
set( 'lf_home_score', '9223372036854775807' );
assert.equal( preview(), 'Will save as Finished.', 'Largest valid integer remains acceptable' );
set( 'lf_home_score', '2e3' );
assert.equal( preview(), 'Invalid score.', 'Exponent notation is not a whole-number score field' );

dom.window.close();
console.log( 'Admin workflow controls passed: result intent, zero/blank/overflow, sport filters and batch selection.' );
