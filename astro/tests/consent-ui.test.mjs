import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';
import {dirname} from 'node:path';
import {build} from 'esbuild';
import {transform} from '@astrojs/compiler-rs';
import {experimental_AstroContainer as AstroContainer} from 'astro/container';
import {JSDOM} from 'jsdom';

// ponytail: compile real Astro in memory; no dev server or build artifacts.
async function load(entry, {wip = false} = {}) {
  assert.ok(existsSync(entry), `${entry} must exist`);
  const result = await build({entryPoints:[entry], bundle:true, write:false, platform:'node', format:'esm',
    define:{'import.meta.env':'{}'}, plugins:[{name:'astro-test', setup(b) {
      b.onResolve({filter:/^astro\/(runtime\/|compiler-runtime)/}, a => ({path:import.meta.resolve(a.path), external:true}));
      b.onResolve({filter:/\.css$|^@fontsource/}, () => ({path:'styles', namespace:'empty'}));
      b.onLoad({filter:/.*/, namespace:'empty'}, () => ({contents:'', loader:'js'}));
      b.onLoad({filter:/\.astro$/}, async a => ({
        // Container's createManifest omits site in this Astro version; supply the local test site to real components.
        contents:transform(readFileSync(a.path,'utf8').replaceAll('Astro.site', 'new URL("https://local.test")'), {filename:a.path, internalURL:'astro/compiler-runtime', resolvePath:s=>s}).code,
        loader:'ts', resolveDir:dirname(a.path),
      }));
      b.onLoad({filter:/src\/lib\/site\.ts$/}, a => ({contents:readFileSync(a.path,'utf8').replace(/export const workInProgress = (?:true|false);/, `export const workInProgress = ${wip};`), loader:'ts'}));
    }}]});
  return import(`data:text/javascript;base64,${Buffer.from(result.outputFiles[0].text).toString('base64')}`);
}
async function renderPanel() {
  const panel = await load('src/ConsentPanel.astro');
  const container = await AstroContainer.create();
  return container.renderToString(panel.default);
}

async function setup(t, ready = true, signals = {}) {
  const dom = new JSDOM(await renderPanel(), {url:'https://local.test/scopri-ora'});
  t.after(() => dom.window.close());
  const w = dom.window, d = w.document;
  for (const [key,value] of Object.entries(signals)) Object.defineProperty(w.navigator,key,{value, configurable:true});
  // jsdom does not implement the browser dialog top layer/default cancel action.
  const dialog = d.querySelector('dialog');
  dialog.showModal = function () { this.open = true; this.querySelector('[autofocus]').focus(); };
  dialog.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
  const {createConsent} = await load('src/consent-client.ts');
  w.fetch=async(_url,init)=>Response.json({ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}});
  const cmp = createConsent(w,'ui-test');
  const writes = [];
  const instrumented = {...cmp, set(value) { writes.push(value); return cmp.set(value); }};
  const {mountConsentUI} = await load('src/consent-ui.ts');
  mountConsentUI(w,instrumented,ready);
  return {w,d,dialog,cmp,writes, mountConsentUI, instrumented};
}

test('real Astro panel has a nonmodal labelled region, equal actions, permanent preferences and safe defaults', async () => {
  const dom = new JSDOM(await renderPanel());
  const d = dom.window.document;
  const banner = d.querySelector('[data-consent-banner]');
  assert.equal(banner.getAttribute('role'), 'region');
  assert.ok(d.getElementById(banner.getAttribute('aria-labelledby')).textContent.trim());
  assert.equal(banner.hasAttribute('aria-modal'), false);
  const actions = [...banner.querySelectorAll('button')];
  assert.deepEqual(actions.map(b=>b.textContent.trim()), ['Accetta','Rifiuta','Personalizza']);
  assert.equal(new Set(actions.map(b=>b.className)).size, 1);
  for (const href of ['/privacy-policy','/cookie-policy']) assert.ok(banner.querySelector(`a[href="${href}"]`));
  const reopen = d.querySelector('[data-consent-reopen]');
  assert.equal(reopen.tagName, 'BUTTON');
  assert.equal(reopen.textContent.trim(), 'Preferenze cookie');
  assert.equal(banner.contains(reopen), false);
  const dialog = d.querySelector('dialog');
  assert.equal(dialog.open, false);
  assert.ok(d.getElementById(dialog.getAttribute('aria-labelledby')));
  for (const category of ['necessary','analytics','marketing']) {
    const input = dialog.querySelector(`input[name="${category}"]`);
    assert.equal(input.type, 'checkbox');
    assert.equal(input.getAttribute('role'), 'switch');
    assert.equal(input.checked, category === 'necessary');
    assert.equal(input.disabled, category === 'necessary');
    assert.ok(input.labels.length);
  }
  assert.equal(dialog.querySelector('[data-consent-audit][role="status"]').textContent, '');
  assert.equal(d.querySelector('[data-consent-receipt]'), null);
  assert.doesNotMatch(d.body.textContent,/GHL|Clarity|Deminicis/i);
  const css = readFileSync('src/ConsentPanel.astro','utf8');
  assert.match(css, /:focus-visible/);
  assert.doesNotMatch(css, /var\(--color-/); // independent from the site's theme
  assert.match(css, /max-inline-size/);
  dom.window.close();
});

test('choice lifecycle uses the existing CMP; close and native Escape never grant consent', async t => {
  const {w,d,dialog,cmp,writes} = await setup(t);
  const banner = d.querySelector('[data-consent-banner]');
  const reopen = d.querySelector('[data-consent-reopen]');
  const customize = d.querySelector('[data-consent-customize]');
  assert.equal(banner.hidden, false);
  assert.equal(reopen.hidden, false);
  assert.equal(writes.length, 0);
  customize.focus(); customize.click();
  assert.equal(dialog.open,true);
  assert.equal(d.activeElement.id,'consent-preferences-title');
  d.querySelector('[name="analytics"]').click();
  d.querySelector('[data-consent-close]').click();
  assert.equal(dialog.open,false);
  assert.equal(d.activeElement,customize);
  assert.equal(writes.length,0);
  customize.click();
  assert.equal(d.querySelector('[name="analytics"]').checked,false);
  const cancel = new w.Event('cancel',{cancelable:true});
  dialog.dispatchEvent(cancel);
  if (!cancel.defaultPrevented) dialog.close(); // Native Escape default action.
  assert.equal(dialog.open,false);
  assert.equal(writes.length,0);
  customize.click();
  const analytics = d.querySelector('[name="analytics"]');
  analytics.focus(); analytics.click();
  d.querySelector('form').requestSubmit();
  await new Promise(r=>setTimeout(r,0));
  assert.deepEqual(cmp.get(),{necessary:true,analytics:true,marketing:false});
  assert.equal(banner.hidden,true);
  assert.equal(dialog.open,false);
  assert.equal(d.activeElement,reopen);
  reopen.click();
  assert.equal(analytics.checked,true);
  dialog.querySelector('[data-consent-reject]').click();
  assert.deepEqual(cmp.get(),{necessary:true,analytics:false,marketing:false});
  assert.equal(reopen.hidden,false);
  // External/tab updates must also update the visible banner and categories.
  w.localStorage.removeItem('alltracking_consent');
  w.dispatchEvent(new w.StorageEvent('storage',{key:'alltracking_consent'}));
  assert.equal(banner.hidden,false);
  d.querySelector('[data-consent-accept]').click();
  await new Promise(r=>setTimeout(r,0));
  assert.deepEqual(cmp.get(),{necessary:true,analytics:true,marketing:true});
});

test('audit status stays silent on success, never shows receipts and surfaces only failures', async t => {
  const {w,d} = await setup(t);
  const status = d.querySelector('[data-consent-audit]');
  const panel = d.querySelector('[data-consent-ui]');
  const receiptId = 'a811ff7b-5393-4ef9-82df-47be6d21cb42';
  assert.equal(status.textContent,'');
  d.querySelector('[data-consent-accept]').click();
  const emit = detail => w.dispatchEvent(new w.CustomEvent('alltracking:audit',{detail}));
  emit({status:'saving'});
  assert.equal(status.textContent,'');
  emit({status:'saved',receiptId});
  assert.equal(status.textContent,'');
  assert.doesNotMatch(panel.textContent,/registrata|ricevuta|a811ff7b/i);
  emit({status:'failed'});
  assert.match(status.textContent,/non confermata/i);
  emit({status:'made-up'});
  assert.match(status.textContent,/non confermata/i);
  d.querySelector('[data-consent-reopen]').click();
  d.querySelector('dialog [data-consent-reject]').click();
  assert.equal(status.textContent,'');
  emit({status:'failed'});
  emit({status:'saving'});
  assert.equal(status.textContent,'');
});

test('policy unavailable disables optional inputs and rejects manipulated submits', async t => {
  const {d,cmp} = await setup(t,false,{});
  assert.equal(d.querySelector('[data-consent-accept]').disabled,true);
  for (const p of d.querySelectorAll('[data-consent-reason]')) {
    assert.equal(p.hidden,false);
    assert.match(p.textContent,/informativ/i);
  }
  d.querySelector('[data-consent-reopen]').click();
  for (const input of d.querySelectorAll('[name="analytics"], [name="marketing"]')) {
    assert.equal(input.disabled,true);
    assert.equal(input.checked,false);
    input.checked = true;
  }
  d.querySelector('form').requestSubmit();
  assert.deepEqual(cmp.get(),{necessary:true,analytics:false,marketing:false});
  assert.equal(cmp.hasChoice(),true);
});

for (const [name,signals] of [
  ['GPC',{globalPrivacyControl:true}],
  ['DNT',{doNotTrack:'1'}],
  ['legacy DNT',{msDoNotTrack:'yes'}],
]) test(`${name} leaves the explicit consent choices available`, async t => {
  const {d,cmp}=await setup(t,true,signals);
  assert.equal(d.querySelector('[data-consent-accept]').disabled,false);
  assert.equal(d.querySelector('[data-consent-reason]').hidden,true);
  d.querySelector('[data-consent-accept]').click();
  await new Promise(resolve=>setTimeout(resolve,0));
  assert.deepEqual(cmp.get(),{necessary:true,analytics:true,marketing:true});
});
