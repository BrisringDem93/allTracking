import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync,readFileSync} from 'node:fs';
import {dirname} from 'node:path';
import {build} from 'esbuild';
import {transform} from '@astrojs/compiler-rs';
import {experimental_AstroContainer as AstroContainer} from 'astro/container';
import {JSDOM} from 'jsdom';

async function load(entry) {
  assert.ok(existsSync(entry),`${entry} exists`);
  const result=await build({entryPoints:[entry],bundle:true,write:false,platform:'node',format:'esm',plugins:[{name:'astro-test',setup(b){
    b.onResolve({filter:/^astro\/(runtime\/|compiler-runtime)/},a=>({path:import.meta.resolve(a.path),external:true}));
    b.onLoad({filter:/\.astro$/},a=>({contents:transform(readFileSync(a.path,'utf8'),{filename:a.path,internalURL:'astro/compiler-runtime',resolvePath:s=>s}).code,loader:'ts',resolveDir:dirname(a.path)}));
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
  assert.throws(()=>api.read(),/rule|regex/i);
  click('[data-clear-rules]');assert.deepEqual(api.read().guard.rules,[]);
  click('[data-default-rules]');assert.ok(api.read().guard.rules.length>5);
  assert.throws(()=>validateSettings({tracking:{mode:['off']}}),/Modalità/);
  assert.throws(()=>validateSettings({tracking:{cmp:['bridge']}}),/CMP/);
  assert.throws(()=>validateSettings({debug:{settingsPath:'//evil.test'}}),/percorso/);
  assert.throws(()=>validateSettings({guard:{sweepInterval:0}}),/sweep/);
  api.destroy();
});
