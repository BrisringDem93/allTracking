import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync,readFileSync} from 'node:fs';
import {dirname,resolve} from 'node:path';
import {build} from 'esbuild';
import {transform} from '@astrojs/compiler-rs';
import {experimental_AstroContainer as AstroContainer} from 'astro/container';
import {JSDOM} from 'jsdom';

async function load(entry) {
  assert.ok(existsSync(entry),`${entry} exists`);
  const result=await build({entryPoints:[entry],bundle:true,write:false,platform:'node',format:'esm',plugins:[{name:'astro-test',setup(b){
    b.onResolve({filter:/^astro\/(runtime\/|compiler-runtime)/},a=>({path:import.meta.resolve(a.path),external:true}));
    b.onLoad({filter:/\.astro$/},a=>({contents:transform(readFileSync(a.path,'utf8'),{filename:a.path.replaceAll('\\','/'),internalURL:'astro/compiler-runtime',resolvePath:s=>s}).code,loader:'ts',resolveDir:dirname(a.path)}));
  }}]});
  return import(`data:text/javascript;base64,${Buffer.from(result.outputFiles[0].text).toString('base64')}`);
}
async function setup(t,initial) {
  const component=await load('src/TrackingSettings.astro'), container=await AstroContainer.create();
  const html=await container.renderToString(component.default,{props:{initial}});
  const dom=new JSDOM(html,{url:'https://local.test/settings'});t.after(()=>dom.window.close());
  const client=await load('src/settings-client.ts');
  const api=client.mountSettings(dom.window);
  return {w:dom.window,d:dom.window.document,api,...client};
}

test('compiled settings CSS includes padding and borders in control widths',async t=>{
  const {d,w,api}=await setup(t,{guard:{rules:[]}});
  const filename=resolve('src/TrackingSettings.astro').replaceAll('\\','/');
  const style=d.createElement('style');
  style.textContent=transform(readFileSync(filename,'utf8'),{filename}).css.join('\n');
  d.head.append(style);
  d.querySelector('[data-add-rule]').click();
  const controls=d.querySelectorAll('.tracking-settings input,.tracking-settings select,.tracking-settings textarea,.tracking-settings button');
  assert.ok(controls.length>0);
  for(const control of controls)assert.ok([...style.sheet.cssRules].some(rule=>control.matches(rule.selectorText)&&rule.style.getPropertyValue('box-sizing')==='border-box'),control.name||control.textContent);
  api.destroy();
});

test('real Astro editor renders safe OFF controls and exports validated public configuration only',async t=>{
  const {d,w,api}=await setup(t,{tracking:{mode:'direct',enabled:true,ga4Id:'G-TEST123',token:'SECRET'},guard:{mode:'off',rules:[]},debug:{mode:'off'},webhook:'https://SECRET.test'});
  assert.match(d.body.textContent,/non.*salvat.*remot|non.*remot.*salvat/i);
  assert.doesNotMatch(d.documentElement.outerHTML,/SECRET/);
  assert.equal(d.querySelector('[name="tracking.ga4Id"]').value,'G-TEST123');
  assert.equal(d.querySelector('[name="guard.mode"]').value,'off');
  assert.equal(d.querySelector('[name="debug.mode"]').value,'off');
  assert.equal(d.querySelector('[data-rules]').children.length,0);
  for(const input of d.querySelectorAll('input,select,textarea'))assert.ok(input.labels.length,`label: ${input.name}`);
  let blob,downloaded=false;
  w.URL.createObjectURL=value=>{blob=value;return 'blob:local';};w.URL.revokeObjectURL=()=>{};
  w.HTMLAnchorElement.prototype.click=function(){downloaded=this.download==='alltracking-config.json';};
  d.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true,bubbles:true}));
  assert.equal(downloaded,true);assert.ok(blob);
  assert.match(d.querySelector('[role=status]').textContent,/esportat.*non attiv/i);
  assert.deepEqual(api.read().guard.rules,[]);
  d.querySelector('[name="tracking.ga4Id"]').value='bad-id';
  downloaded=false;d.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true}));
  assert.equal(downloaded,false);
  assert.match(d.querySelector('[role=status]').textContent,/GA4/);
  api.destroy();
});

test('rule editor uses actual evaluator and preserves empty/custom rules without changing live site',async t=>{
  const {d,w,api,validateSettings}=await setup(t,{guard:{rules:[]}});
  const click=selector=>d.querySelector(selector).click();
  click('[data-add-rule]');
  const row=d.querySelector('[data-rule]');
  row.querySelector('[data-field=label]').value='Test GA';row.querySelector('[data-field=value]').value='_ga';
  d.querySelector('[name="test.name"]').value='_ga';click('[data-test-rule]');
  assert.equal(JSON.parse(d.querySelector('[data-test-result]').textContent).blocked,true);
  assert.equal(w.document.cookie,'');assert.equal(w.allTrackingGuard,undefined);
  d.querySelector('[name="test.analytics"]').checked=true;click('[data-test-rule]');
  assert.equal(JSON.parse(d.querySelector('[data-test-result]').textContent).blocked,false);
  click('[data-disable-rules]');assert.equal(api.read().guard.rules[0].enabled,false);
  click('[data-enable-rules]');assert.equal(api.read().guard.rules[0].enabled,true);
  row.querySelector('[data-field=match]').value='regex';row.querySelector('[data-field=value]').value='[';
  assert.throws(()=>api.read(),/Error: Regola 1: espressione regolare non valida$/,'Italian, 1-based like the visible <ol>');
  click('[data-clear-rules]');assert.deepEqual(api.read().guard.rules,[]);
  click('[data-default-rules]');assert.ok(api.read().guard.rules.length>5);
  assert.throws(()=>validateSettings({tracking:{mode:['off']}}),/Modalità/);
  assert.throws(()=>validateSettings({tracking:{cmp:['bridge']}}),/CMP/);
  assert.throws(()=>validateSettings({debug:{settingsPath:'//evil.test'}}),/percorso/);
  assert.throws(()=>validateSettings({guard:{sweepInterval:0}}),/Intervallo passate/);
  api.destroy();
});

test('editor round-trips with the integration and keeps focus/BFCache/tester usable',async t=>{
  const {d,w,api,validateSettings}=await setup(t,{guard:{rules:[],allowlist:['my_cookie']},debug:{mode:'off',settingsPath:'/admin/tracking'}});
  assert.equal(d.querySelector('[name="guard.allowlist"]').value,'my_cookie','built-in protected patterns are not shown as custom');
  assert.deepEqual(api.read().guard.allowlist,['my_cookie']);
  for(const settingsPath of ['/admin//tracking','/'+'a'.repeat(201),'/admin/tracking.html'])assert.throws(()=>validateSettings({debug:{settingsPath}}),/percorso/,settingsPath);
  d.querySelector('[name="guard.sweepDuration"]').value='';d.querySelector('[name="guard.sweepInterval"]').value='';
  assert.equal(api.read().guard.sweepDuration,60000,'empty means default, not 0');assert.equal(api.read().guard.sweepInterval,2000);
  d.querySelector('[name="tracking.ga4Id"]').value='bad-id';
  d.querySelector('[name="test.name"]').value='_ga';d.querySelector('[data-test-rule]').click();
  assert.equal(JSON.parse(d.querySelector('[data-test-result]').textContent).blocked,false,'tester ignores unrelated tracking errors');
  d.querySelector('[data-add-rule]').click();d.querySelector('[data-add-rule]').click();
  const second=d.querySelectorAll('[data-rule]')[1],up=[...second.querySelectorAll('button')].find(b=>b.textContent==='Su');
  up.focus();up.click();
  assert.equal(d.querySelectorAll('[data-rule]')[0],second);assert.equal(d.activeElement,up,'moving up keeps keyboard focus');
  for(const row of [...d.querySelectorAll('[data-rule]')]){const del=[...row.querySelectorAll('button')].find(b=>b.textContent==='Elimina');del.focus();del.click();}
  assert.equal(d.activeElement,d.querySelector('[data-add-rule]'),'deleting the last rule never drops focus to <body>');
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:true}));
  assert.equal(d.querySelector('[data-tracking-settings]').dataset.mounted,'true','BFCache-restored editor stays alive');
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:false}));
  assert.equal(d.querySelector('[data-tracking-settings]').dataset.mounted,undefined);
});
