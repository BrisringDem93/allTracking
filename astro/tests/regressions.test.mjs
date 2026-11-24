import test, {afterEach} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {normalizeOptions} from '../src/options.ts';
import {createConsent} from '../src/consent-client.ts';
import {createTracking} from '../src/tracking-client.ts';
import {collectAttribution, sanitizeAttribution} from '../src/tracking.ts';
import {installCookieGuard} from '../src/cookie-guard.ts';
import {startAllTracking} from '../src/runtime.ts';
import {mountDiagnostics, diagnosticSnapshot} from '../src/diagnostics.ts';
import {guardScript} from '../src/head.ts';

// ponytail: one regression per audited defect (0.2.2); behaviour, not implementation details.
const windows = new Set();
afterEach(() => { for (const w of windows) w.close(); windows.clear(); });
const page = (html = '', url = 'https://site.test/') => { const {window: w} = new JSDOM(html, {url}); windows.add(w); return w; };
const receipt = (skew = 0) => async (_url, init) => Response.json({ok:true, receipt:{...JSON.parse(init.body), createdAt:new Date(Date.now() + skew).toISOString()}});
const config = {enabled:true, mode:'direct', cmp:'bridge', ga4Id:'G-TEST123', metaPixelId:'123456789', attribution:true, formEvents:true};
const policy = {ready:true, version:'reg-v1', privacy:'Privacy', cookie:'Cookie'};
const tick = () => new Promise(resolve => setTimeout(resolve, 0));

test('local path validation is linear-time and keeps the accepted language', () => {
  const started = performance.now();
  for (const bad of ['/' + 'a'.repeat(60) + '!', '/' + 'ab/'.repeat(30) + '?', '/' + 'a'.repeat(250)]) {
    assert.throws(() => normalizeOptions({configEndpoint:bad}), /path/i);
  }
  assert.ok(performance.now() - started < 500, 'invalid paths are rejected without catastrophic backtracking');
  for (const ok of ['/', '/api', '/api/', '/a.b/c-d_e/', '/v1.2.3/x']) assert.equal(normalizeOptions({configEndpoint:ok}).configEndpoint, ok);
  for (const bad of ['', '//', '/a//b', '/.a', '/a.', '/a..b', 'api', '/a/./b']) assert.throws(() => normalizeOptions({configEndpoint:bad}), /path/i, bad);
});

test('receipts stamped by a server clock ahead of the visitor still confirm, clamped to local time', async () => {
  const w = page(); w.fetch = receipt(90_000);
  const cmp = createConsent(w, 'reg-v1');
  await cmp.set({analytics:true, marketing:false});
  assert.equal(cmp.get().analytics, true);
  const stored = JSON.parse(w.localStorage.getItem('alltracking_consent'));
  assert.equal(stored.confirmed, true); assert.ok(stored.updatedAt <= Date.now());
  const far = page(); far.fetch = receipt(25 * 3600 * 1000);
  const statuses = []; far.addEventListener('alltracking:audit', event => statuses.push(event.detail.status));
  const other = createConsent(far, 'reg-v1');
  await other.set({analytics:true});
  assert.equal(other.get().analytics, false); assert.equal(statuses.at(-1), 'failed');
});

test('CMP events never turn a pending or failed native grant into a fabricated denial receipt', async () => {
  const w = page(), posts = [];
  w.fetch = async (_url, init) => { posts.push(JSON.parse(init.body)); throw Error('offline'); };
  w.Cookiebot = {consent:{statistics:true, marketing:true}};
  const cmp = createConsent(w, 'reg-v1'); cmp.useCMP('cookiebot');
  await cmp.set({analytics:true, marketing:true});
  assert.equal(posts.length, 1); assert.equal(cmp.get().analytics, false, 'unconfirmed grant stays denied');
  w.dispatchEvent(new w.Event('CookiebotOnAccept')); await tick();
  assert.equal(posts.length, 1, 'an Accept event records no denial');
  assert.equal(JSON.parse(w.localStorage.getItem('alltracking_consent')).analytics, true);
  // A confirmed grant that the CMP now denies IS a revocation and is recorded.
  w.fetch = async (url, init) => { posts.push(JSON.parse(init.body)); return receipt()(url, init); };
  await cmp.set({analytics:true, marketing:true}); assert.equal(cmp.get().analytics, true);
  w.Cookiebot.consent = {statistics:false, marketing:true};
  w.dispatchEvent(new w.Event('CookiebotOnDecline')); await tick();
  assert.deepEqual([posts.at(-1).analytics, posts.at(-1).marketing], [false, true]);
});

test('a storage quota failure keeps the receipt-confirmed in-memory choice across focus/storage', async () => {
  const w = page(); w.fetch = receipt();
  w.Storage.prototype.setItem = function () { throw new w.DOMException('full', 'QuotaExceededError'); };
  const cmp = createConsent(w, 'reg-v1');
  await cmp.set({analytics:true, marketing:true});
  assert.equal(cmp.get().analytics, true);
  w.dispatchEvent(new w.Event('focus')); w.dispatchEvent(new w.StorageEvent('storage', {key:'alltracking_consent'}));
  assert.equal(cmp.get().analytics, true); assert.equal(cmp.hasChoice(), true);
});

test('attribution hidden fields never duplicate application-owned controls', () => {
  const w = page('<form data-landing-form id="lead"><select name="utm_source"><option value="app" selected>app</option></select><textarea name="utm_medium">app</textarea></form><input form="lead" name="utm_campaign" value="app">',
    'https://site.test/?utm_source=ad&utm_medium=cpc&utm_campaign=spring&utm_term=kw');
  w.allTrackingConsent = {analytics:true, marketing:false};
  createTracking(w, config);
  const form = w.document.getElementById('lead'), entries = name => new w.FormData(form).getAll(name);
  assert.deepEqual(entries('utm_source'), ['app']); assert.deepEqual(entries('utm_medium'), ['app']); assert.deepEqual(entries('utm_campaign'), ['app']);
  assert.deepEqual(entries('utm_term'), ['kw'], 'free names still receive a runtime field');
  const late = w.document.createElement('input'); late.name = 'utm_term'; late.value = 'mine'; form.prepend(late);
  form.dispatchEvent(new w.Event('submit', {bubbles:true, cancelable:true}));
  assert.deepEqual(entries('utm_term'), ['mine'], 'a later application control takes the name over');
});

test('derived fbc keeps its first timestamp while a stale _fbc cookie exists', () => {
  const consent = {analytics:false, marketing:true}, stale = '_fbc=fb.1.1600000000000.OLDCLICK';
  const first = collectAttribution('https://site.test/?fbclid=NEWCLICK', stale, {}, consent, 1700000000000);
  const second = collectAttribution('https://site.test/?fbclid=NEWCLICK', stale, first, consent, 1700000009999);
  assert.equal(first.fbc, 'fb.1.1700000000000.NEWCLICK'); assert.equal(second.fbc, first.fbc);
});

test('campaign values cannot smuggle emails, URLs or secret markers through repeated encoding', () => {
  const clean = sanitizeAttribution({utm_source:'mario.rossi%40mail.test', utm_medium:'https%3A%2F%2Fevil.test', utm_campaign:'api%5Fkey',
    utm_content:'50%-off spring', utm_term:'summer%20sale'}, {analytics:true, marketing:false});
  assert.deepEqual(clean, {utm_content:'50%-off spring', utm_term:'summer%20sale'});
});

test('expiring writes to blocked names can only delete; empty-name writes are judged by their visible key', () => {
  const w = page('', 'https://www.example.test/');
  const guard = installCookieGuard(w, {mode:'enforce', sweepDuration:0}, () => ({analytics:false, marketing:false}));
  // "expires=0" parses as a past date in V8 but as a session cookie in cookie parsers.
  for (const write of ['_ga=GA1.1.1; expires=0; path=/', '_ga=GA1.1.2; Max-Age=0; Max-Age=oops; path=/', '=_ga=GA1.1.3; path=/']) w.document.cookie = write;
  assert.equal(w.document.cookie, '');
  w.document.cookie = 'session=1; path=/'; assert.equal(w.document.cookie, 'session=1');
  guard.destroy();
});

test('reporting a replaced setter never switches off purge and revocation sweeps', () => {
  const w = page('', 'https://www.example.test/'); let consent = {analytics:true, marketing:true};
  const guard = installCookieGuard(w, {mode:'enforce', sweepDuration:0}, () => consent);
  w.document.cookie = '_ga=granted; path=/';
  const ours = Object.getOwnPropertyDescriptor(w.document, 'cookie');
  Object.defineProperty(w.document, 'cookie', {configurable:true, get() { return ours.get.call(this); }, set(v) { ours.set.call(this, v); }});
  assert.equal(guard.stats().installed, false); assert.match(guard.stats().errors.join(' '), /replaced/);
  consent = {analytics:false, marketing:false}; w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(w.document.cookie, '', 'revocation still purges after diagnostics looked');
  assert.equal(guard.stats().errors.filter(e => /replaced/.test(e)).length, 1);
});

test('a pathname change after readiness never purges consented cookies', async () => {
  const w = page();
  w.fetch = async (url, init) => init?.method === 'POST' ? receipt()(url, init) : Response.json(url.includes('policy') ? policy : config);
  await startAllTracking(w, {guard:{mode:'enforce', sweepDuration:0}});
  await w.nativeConsent.set({analytics:true, marketing:true});
  w.document.cookie = '_ga=granted; path=/';
  w.history.pushState(null, '', '/other');
  w.allTrackingGuard.refresh(); w.dispatchEvent(new w.Event('load'));
  assert.match(w.document.cookie, /_ga=granted/);
  w.document.cookie = '_fbp=after-route; path=/';
  assert.doesNotMatch(w.document.cookie, /_fbp/, 'new writes stay denied off the original path');
});

test('diagnostics: guard off is a configuration, nameless values stay hidden, invalid CMP is explained, selection survives polls', () => {
  const w = page('<!doctype html><body></body>');
  w.document.cookie = 'SECRETVALUE'; w.document.cookie = '_ga=1';
  const context = {guard:'off', cmpValid:false, ready:false};
  const snap = diagnosticSnapshot(w, config, context);
  assert.equal(snap.guard.mode, 'off'); assert.deepEqual(snap.cookies.map(c => c.name), ['_ga']);
  assert.equal(snap.cmp.configured, 'assente/non valido'); assert.equal(snap.cmp.ready, false);
  assert.doesNotMatch(JSON.stringify(snap), /SECRETVALUE/);
  const api = mountDiagnostics(w, {mode:'public'}, config, context), d = w.document;
  assert.notEqual(d.querySelector('[data-debug-badge]').textContent, '!', 'default guard off is not an alarm');
  d.querySelector('[data-debug-toggle]').click();
  const body = d.querySelector('[data-debug-body]'), tab = name => [...d.querySelectorAll('[role=tab]')].find(b => b.textContent === name).click();
  tab('Consenso'); assert.match(body.textContent, /assente\/non valido/); assert.match(body.textContent, /abilitabile.*: no/);
  tab('Blocco'); assert.match(body.textContent, /disattivato intenzionalmente/i); assert.doesNotMatch(body.textContent, /ATTENZIONE/);
  const node = body.firstChild;
  [...d.querySelectorAll('button')].find(b => b.textContent === 'Aggiorna').click();
  assert.equal(body.firstChild, node, 'unchanged text keeps its node, so a selection is not collapsed');
  api.destroy();
});

test('diagnostics: a header tap right after a touch drag is not swallowed', () => {
  const w = page('<!doctype html><body></body>'), d = w.document;
  const api = mountDiagnostics(w, {mode:'public'}, config, {guard:'off'});
  d.querySelector('[data-debug-toggle]').click();
  const root = d.querySelector('[data-alltracking-debug]'), head = d.querySelector('[data-debug-head]');
  root.getBoundingClientRect = () => ({left:8, top:8, width:300, height:200});
  const close = [...head.querySelectorAll('button')].find(b => b.textContent === 'Chiudi');
  const pointer = (target, type, x, y) => { const e = new w.Event(type, {bubbles:true, cancelable:true}); Object.assign(e, {button:0, pointerId:3, clientX:x, clientY:y}); target.dispatchEvent(e); };
  pointer(head, 'pointerdown', 20, 20); pointer(head, 'pointermove', 120, 120); pointer(head, 'pointerup', 120, 120); // touch: no trailing click
  pointer(close, 'pointerdown', 30, 30); close.click();
  assert.equal(d.querySelector('[data-debug-panel]').hidden, true);
  api.destroy();
});

test('TrackingHead path is runtime-neutral and matches the integration output', async () => {
  const inline = readFileSync(new URL('../dist/early.inline.js', import.meta.url), 'utf8');
  const {cookieGuardScript} = await import('../src/index.ts');
  const options = {guard:{mode:'enforce'}};
  assert.equal(guardScript(inline, options), cookieGuardScript(options));
  assert.equal(guardScript(inline, {}), ''); assert.equal(guardScript(inline, {disabled:true, guard:{mode:'enforce'}}), '');
  for (const file of ['head', 'options', 'guard-config', 'diagnostics', 'tracking']) {
    assert.doesNotMatch(readFileSync(new URL(`../dist/${file}.js`, import.meta.url), 'utf8'), /from ['"]node:|readFileSync|import\.meta\.resolve/, file);
  }
  const component = readFileSync(new URL('../src/TrackingHead.astro', import.meta.url), 'utf8');
  assert.match(component, /early\.inline\.js\?raw/);
  assert.doesNotMatch(component, /cookieGuardScript|import\s*\{[^}]*\}\s*from '\.\.\/dist\/index\.js'/);
});
