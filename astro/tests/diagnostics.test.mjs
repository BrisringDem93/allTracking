import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';

async function load(entry='src/diagnostics.ts') {
  assert.ok(existsSync(entry), `${entry} exists`);
  const result = await build({entryPoints:[entry],bundle:true,write:false,format:'esm',platform:'node'});
  return import(`data:text/javascript;base64,${Buffer.from(result.outputFiles[0].text).toString('base64')}`);
}
const config = {enabled:true,mode:'direct',ga4Id:'G-TEST123',metaPixelId:'123456789',attribution:true,formEvents:true,cmp:'bridge'};

test('off is inert; public five-tab diagnostics exports only safe live facts', async t => {
  const {mountDiagnostics,diagnosticSnapshot} = await load();
  const dom = new JSDOM('',{url:'https://local.test/private?token=SECRET#PII'}), w=dom.window, d=w.document;
  t.after(()=>w.close());
  w.document.cookie='_ga=COOKIE_SECRET'; w.document.cookie='_fbp=PIXEL_SECRET';
  w.allTrackingConsent={analytics:false,marketing:false};
  w.dataLayer=[{email:'PRIVATE@example.test'}];
  w.allTrackingGuard={mode:'monitor',cookies:()=>['_ga','_fbp'],consent:()=>w.allTrackingConsent,
    evaluate:()=>({blocked:true,action:'block_delete',reason:'consent_denied',rule:{label:'SECRET_RULE'}}),stats:()=>({blocked:2,deleted:0,secret:'SECRET_STATS'})};
  let calls=0; w.fetch=()=>{calls++;throw Error('no network');};
  assert.equal(mountDiagnostics(w,{mode:'off'},config),undefined);
  assert.equal(d.body.childElementCount,0);
  const api=mountDiagnostics(w,{mode:'public',settingsPath:'/settings'}, {...config,token:'SECRET_CONFIG',webhook:'https://secret.test/full'});
  assert.ok(api);
  d.querySelector('[data-debug-toggle]').click();
  assert.deepEqual([...d.querySelectorAll('[role=tab]')].map(x=>x.textContent),['Consenso','Cookie','Attesi','Blocco','Tag & GA4']);
  assert.match(d.body.textContent,/staging/i);
  assert.equal(d.querySelector('[data-debug-settings]').getAttribute('href'),'/settings');
  const snapshot=diagnosticSnapshot(w,config);
  assert.deepEqual(snapshot.cookies.map(c=>c.name),['_fbp','_ga']);
  assert.equal(snapshot.problems,2);
  const json=JSON.stringify(snapshot);
  assert.doesNotMatch(json,/SECRET|PII|private|example.test|webhook|email|referrer/i);
  assert.equal(snapshot.backend.pipeline,'unavailable');
  assert.equal(calls,0);
  api.destroy(); assert.equal(d.querySelector('[data-alltracking-debug]'),null);
});

test('failed cookie setter installation warns before any cookie is written', async t=>{
  const {mountDiagnostics,diagnosticSnapshot}=await load();
  const {installCookieGuard}=await load('src/cookie-guard.ts');
  for(const mode of ['enforce','monitor']){
    const w=new JSDOM('',{url:'https://local.test'}).window,d=w.document;t.after(()=>w.close());
    Object.defineProperty(d,'cookie',{...Object.getOwnPropertyDescriptor(w.Document.prototype,'cookie'),configurable:false});
    const guard=installCookieGuard(w,{mode,sweepDuration:0});t.after(()=>guard.destroy());
    assert.equal(guard.stats().active,false);
    assert.match(guard.stats().errors[0],/nonconfigurable/);
    const api=mountDiagnostics(w,{mode:'public'},config);t.after(()=>api.destroy());
    assert.equal(d.querySelector('[data-debug-badge]').textContent,'!','no cookies does not mean the guard is healthy');
    assert.match(d.querySelector('[data-debug-badge]').getAttribute('aria-label'),/guard/i);
    d.querySelector('[data-debug-toggle]').click();
    [...d.querySelectorAll('[role=tab]')].find(b=>b.textContent==='Blocco').click();
    assert.match(d.querySelector('[data-debug-body]').textContent,mode==='enforce'?/enforcement richiesta ma non attiva/i:/monitor richiesto ma non attivo/i);
    assert.deepEqual(diagnosticSnapshot(w,config).guard,{mode,installed:false,active:false,sweeping:false,errors:1,blocked:0,deleted:0});
    d.cookie='_ga=UNPROTECTED';assert.match(d.cookie,/_ga=UNPROTECTED/);
  }
});

test('off, missing, healthy and replaced guards expose only truthful public status',async t=>{
  const {mountDiagnostics,diagnosticSnapshot}=await load();
  const {installCookieGuard}=await load('src/cookie-guard.ts');
  const w=new JSDOM('',{url:'https://local.test'}).window,d=w.document;t.after(()=>w.close());
  const api=mountDiagnostics(w,{mode:'public'},config);t.after(()=>api.destroy());
  d.querySelector('[data-debug-toggle]').click();
  [...d.querySelectorAll('[role=tab]')].find(b=>b.textContent==='Blocco').click();
  const refresh=()=>[...d.querySelectorAll('button')].find(b=>b.textContent==='Aggiorna').click();
  const body=()=>d.querySelector('[data-debug-body]').textContent,badge=()=>d.querySelector('[data-debug-badge]').textContent;
  assert.equal(badge(),'!');assert.match(body(),/stato non disponibile/i);
  const off=installCookieGuard(w,{mode:'off',sweepDuration:0});refresh();
  assert.equal(badge(),'off');assert.match(body(),/disattivato intenzionalmente/i);off.destroy();
  const monitor=installCookieGuard(w,{mode:'monitor',sweepDuration:0});refresh();
  assert.equal(badge(),'monitor');assert.match(body(),/monitor attivo/i);
  assert.equal(diagnosticSnapshot(w,config).guard.active,true);
  assert.equal(diagnosticSnapshot(w,config).guard.sweeping,false);monitor.destroy();
  const enforce=installCookieGuard(w,{mode:'enforce',sweepDuration:0});refresh();assert.equal(badge(),'ok');
  delete d.cookie;refresh();assert.equal(badge(),'!');assert.match(body(),/enforcement richiesta ma non attiva/i);enforce.destroy();
  w.allTrackingGuard={mode:'enforce',stats:()=>({installed:'true',active:1,sweeping:'SECRET',errors:['SECRET_TOKEN','PRIVATE_EMAIL'],blocked:0,deleted:0})};
  refresh();const snapshot=diagnosticSnapshot(w,config);
  assert.equal(snapshot.guard.installed,null);assert.equal(snapshot.guard.active,null);assert.equal(snapshot.guard.sweeping,null);
  assert.equal(snapshot.guard.errors,2);assert.equal(badge(),'!');assert.doesNotMatch(JSON.stringify(snapshot),/SECRET|PRIVATE/);
  assert.deepEqual(snapshot.backend,{snapshot:'unavailable',queue:'unavailable',pipeline:'unavailable'});
});

test('drag clamps and persists, tab keys/reset work, pagehide stops polling and pending copy feedback',async t=>{
  const {mountDiagnostics}=await load();
  const dom=new JSDOM('',{url:'https://local.test'}),w=dom.window,d=w.document;t.after(()=>w.close());
  let polls=0,refresh,cleared=false,resolveCopy;
  w.setInterval=fn=>{refresh=fn;return 99;};w.clearInterval=id=>{cleared=id===99;};
  w.nativeConsent={get:()=>{polls++;return {analytics:false,marketing:false};}};
  Object.defineProperty(w.navigator,'clipboard',{value:{writeText:()=>new Promise(r=>{resolveCopy=r;})}});
  const api=mountDiagnostics(w,{mode:'public',settingsPath:'https://evil.test/?token=SECRET'},config);
  const root=d.querySelector('[data-alltracking-debug]');
  assert.equal(d.querySelector('[data-debug-settings]'),null);
  root.getBoundingClientRect=()=>({left:8,top:8,width:300,height:200});
  const toggle=d.querySelector('[data-debug-toggle]');
  const pointer=(type,x,y)=>{const e=new w.Event(type,{bubbles:true,cancelable:true});Object.assign(e,{button:0,pointerId:1,clientX:x,clientY:y});toggle.dispatchEvent(e);};
  pointer('pointerdown',10,10);pointer('pointermove',99999,99999);pointer('pointerup',99999,99999);toggle.click();
  assert.equal(d.querySelector('[data-debug-panel]').hidden,true,'drag end must not activate toggle');
  assert.equal(parseFloat(root.style.left),w.innerWidth-308);
  assert.equal(parseFloat(root.style.top),w.innerHeight-208);
  assert.ok(JSON.parse(w.localStorage.getItem('alltracking.debug.pos')).x>0);
  toggle.click();const tabs=[...d.querySelectorAll('[role=tab]')];
  tabs[0].dispatchEvent(new w.KeyboardEvent('keydown',{key:'End',bubbles:true}));
  assert.equal(tabs[4].getAttribute('aria-selected'),'true');assert.equal(d.activeElement,tabs[4]);
  [...d.querySelectorAll('button')].find(b=>b.textContent==='Reimposta posizione').click();
  assert.equal(root.style.left,'');
  const copy=[...d.querySelectorAll('button')].find(b=>b.textContent==='Copia JSON');copy.click();
  w.dispatchEvent(new w.Event('pagehide'));assert.equal(cleared,true);assert.equal(root.isConnected,false);
  const count=polls;refresh();w.dispatchEvent(new w.Event('alltracking:consent'));resolveCopy();await Promise.resolve();
  assert.equal(polls,count);assert.equal(copy.textContent,'Copia JSON');api.destroy();
});
