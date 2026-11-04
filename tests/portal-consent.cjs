'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM(`<!doctype html><div data-leagueflow-user-id="42"><form data-leagueflow-onboarding data-onboarding-kind="captain">
<fieldset data-leagueflow-step="1"><input name="lf_captain_name" value="" required></fieldset>
<fieldset data-leagueflow-step="2"><input name="lf_consent_version[waiver]" type="hidden" value="current-revision"><label><input type="checkbox" name="lf_consent[waiver]" value="1" required> I accept</label></fieldset>
</form></div>`, { url: 'https://leagueflow.test/portal/', runScripts: 'outside-only' });
const { window } = dom;
const storageKey = 'leagueflow-onboarding:captain:42';
window.sessionStorage.setItem(storageKey, JSON.stringify({
  lf_captain_name: 'Retained participant name',
  'lf_consent[waiver]': ['1'],
  'lf_consent_version[waiver]': 'old-revision'
}));
window.eval(fs.readFileSync(path.join(__dirname, '../assets/js/portal.js'), 'utf8'));
window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
const form = window.document.querySelector('form');
const checkbox = window.document.querySelector('input[type="checkbox"]');
assert.equal(form.elements.namedItem('lf_captain_name').value, 'Retained participant name', 'Ordinary draft fields must still restore.');
assert.equal(checkbox.checked, false, 'Consent must never restore checked from an old draft.');
assert.equal(form.elements.namedItem('lf_consent_version[waiver]').value, 'current-revision', 'The form must retain its server-rendered document revision.');
assert.equal(checkbox.checkValidity(), false, 'Unchecked acknowledgement must fail browser validation.');
checkbox.checked = true;
checkbox.dispatchEvent(new window.Event('change', { bubbles: true }));
const draft = JSON.parse(window.sessionStorage.getItem(storageKey));
assert.equal(draft.lf_captain_name, 'Retained participant name');
assert.equal(Object.hasOwn(draft, 'lf_consent[waiver]'), false, 'Personal agreement must not be stored in a reusable draft.');
assert.equal(Object.hasOwn(draft, 'lf_consent_version[waiver]'), false, 'Revision tokens must not be stored in a reusable draft.');
window.close();
console.log('Portal consent draft regressions: 7 assertions passed.');
