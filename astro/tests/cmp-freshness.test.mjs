import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
import {readConsent} from '../src/tracking.ts';
import {createConsent} from '../src/consent-client.ts';
import {createTracking} from '../src/tracking-client.ts';
import {installCookieGuard} from '../src/cookie-guard.ts';

const grant={analytics:true,marketing:true}, deny={analytics:false,marketing:false};
const cookie=(value,name='_iub_cs-s4602417')=>`${name}=${encodeURIComponent(JSON.stringify(value))}`;
const preferences=purposes=>({consent:true,purposes});

test('iubenda selects real purpose cookies over US privacy, malformed and generic candidates',()=>{
  for(const value of [JSON.stringify(preferences({4:true,5:false})),encodeURIComponent(JSON.stringify(preferences({4:true,5:false})))]) {
    let reads=0;
    const scope={document:{cookie:`${cookie({consent:true},'_iub_cs-99-uspr')}; _iub_cs-10=broken; ${cookie({consent:true},'_iub_cs-11')}; _iub_cs-s4602417-extra=${value}`},_iub:{cs:{api:{getPreferences(){reads++;return preferences({4:false,5:true});}}}}};
    assert.deepEqual(readConsent('iubenda',scope),{analytics:true,marketing:false});
    assert.equal(reads,0,'persisted purposes precede stale API');
  }
});

test('persisted false flags and explicit denial win; cookie JSON is not unslashed',()=>{
  for(const value of [preferences({4:false,5:false}),{consent:false,purposes:{4:true,5:true}},{consent:false},{consent:true}, {...preferences({4:true,5:false}),label:'quote " and slash \\'}]) {
    const expected=value.purposes && value.consent!==false ? {analytics:value.purposes[4],marketing:value.purposes[5]} : deny;
    assert.deepEqual(readConsent('iubenda',{document:{cookie:cookie(value)},_iub:{cs:{api:{getPreferences:()=>preferences({4:true,5:true})}}}}),expected);
  }
});

test('invalid or US-only cookie data falls back to API without bypassing native intersection',()=>{
  for(const raw of ['_iub_cs-1=%broken','_iub_cs-1=[]','_iub_cs-1={"purposes":true}',cookie({consent:true},'_iub_cs-1-uspr')]) {
    const scope={document:{cookie:raw},nativeConsent:{},allTrackingConsent:{analytics:true,marketing:false},_iub:{cs:{api:{getPreferences:()=>preferences({4:true,5:true})}}}};
    assert.deepEqual(readConsent('iubenda',scope),{analytics:true,marketing:false});
  }
  assert.deepEqual(readConsent('iubenda',{document:{cookie:cookie(preferences({4:true,5:true}))},nativeConsent:{},allTrackingConsent:deny}),deny);
});

function browser(t) {
  const w=new JSDOM('<form data-landing-form></form>',{url:'https://fresh.test/',pretendToBeVisual:true}).window;
  t.after(()=>w.close());
  const intervals=new Map(), requests=[];
  let id=0;
  t.mock.method(w,'setInterval',callback=>{intervals.set(++id,callback);return id;});
  t.mock.method(w,'clearInterval',key=>intervals.delete(key));
  w.fetch=async(_url,init)=>{const body=JSON.parse(init.body);requests.push(body);return Response.json({ok:true,receipt:{...body,createdAt:new Date().toISOString()}});};
  w._iub={cs:{api:{getPreferences:()=>preferences({4:true,5:true})}}};
  return {w,intervals,requests,tick:async()=>{for(const callback of [...intervals.values()])callback();await new Promise(setImmediate);}};
}

test('cookie-only revocation persists once, updates loaded providers/hidden fields and never auto-regrants',async t=>{
  const {w,intervals,requests,tick}=browser(t), native=createConsent(w,'v1');
  await native.set(grant);
  w.document.cookie=cookie(preferences({4:true,5:true}));
  createTracking(w,{enabled:true,mode:'direct',cmp:'iubenda',ga4Id:'G-TEST123',metaPixelId:'123456789',attribution:true});
  assert.ok(w.document.querySelector('input[name="external_id"]'));
  assert.equal(intervals.size,1,'one bounded observer for active iubenda');
  native.useCMP('iubenda');assert.equal(intervals.size,1,'reconfiguration does not duplicate observer');
  w.document.cookie=cookie(preferences({4:false,5:false}));
  assert.equal(native.get().analytics,false,'reads deny before poll');
  await tick();
  assert.equal(w['ga-disable-G-TEST123'],true);
  assert.deepEqual(w.fbq.queue.at(-1),['consent','revoke']);
  assert.equal(w.document.querySelector('input[name="external_id"]'),null);
  assert.equal(requests.length,2,'one native choice and one durable revocation');
  assert.equal(JSON.parse(w.localStorage.getItem('alltracking_consent')).marketing,false);
  await tick();assert.equal(requests.length,2,'unchanged cookies do not re-audit');
  w.document.cookie=cookie(preferences({4:true,5:true}));await tick();
  assert.deepEqual(native.get(),{necessary:true,...deny},'external approval cannot overwrite native revocation');
  assert.equal(requests.length,2);
  await native.set(grant);assert.equal(native.get().analytics,true,'new confirmed native choice can grant');
});

test('guard decisions read fresh cookies after sweeps stop, retaining the native receipt gate',async t=>{
  const {w}=browser(t), native=createConsent(w,'v1');
  await native.set(grant);
  w.document.cookie=cookie(preferences({4:false,5:false}));native.useCMP('iubenda');
  const guard=installCookieGuard(w,{mode:'enforce',sweepDuration:0},()=>native.get());
  t.after(()=>guard.destroy());
  assert.equal(guard.stats().sweeping,false);
  w.document.cookie='_ga=blocked';assert.doesNotMatch(w.document.cookie,/_ga=/);
  w.document.cookie=cookie(preferences({4:true,5:true}));
  w.document.cookie='_ga=fresh';assert.match(w.document.cookie,/_ga=fresh/,'persisted approval unlocks existing confirmed choice without events');
  w.document.cookie=cookie({consent:false,purposes:{4:true,5:true}});
  w.document.cookie='_ga=stale';assert.match(w.document.cookie,/_ga=fresh/,'fresh denial blocks overwrite before polling');
  await native.set(deny);
  w.document.cookie=cookie(preferences({4:true,5:true}));
  assert.equal(native.get().analytics,false,'external cookie never overrides own explicit rejection');
});

test('pagehide permanently retires polling and old consent callbacks, including persisted pageshow',async t=>{
  const {w,intervals,requests}=browser(t), native=createConsent(w,'v1');
  await native.set(grant);native.useCMP('iubenda');
  assert.equal(intervals.size,1);
  const late=[...intervals.values()][0];
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:true}));
  assert.equal(intervals.size,0);
  let events=0;w.addEventListener('alltracking:consent',()=>events++);
  w.document.cookie=cookie(preferences({4:false,5:false}));
  late();
  for(const event of ['iubenda:consent','focus','storage'])w.dispatchEvent(new w.Event(event));
  w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));
  await native.set(grant);
  await new Promise(setImmediate);
  assert.equal(intervals.size,0,'old document never restarts observation');
  assert.equal(requests.length,1,'retired callbacks cannot write receipts');
  assert.equal(events,0,'retired callbacks cannot publish');
  assert.deepEqual(native.get(),{necessary:true,...deny});
});

test('retired expiry callbacks and readers do no storage or CMP work',async t=>{
  const {w}=browser(t), timeouts=[];
  t.mock.method(w,'setTimeout',callback=>{timeouts.push(callback);return timeouts.length;});
  const native=createConsent(w,'v1');await native.set(grant);native.useCMP('iubenda');
  w.dispatchEvent(new w.Event('pagehide'));
  let reads=0;
  t.mock.method(w.Storage.prototype,'getItem',()=>{reads++;return null;});
  w._iub.cs.api.getPreferences=()=>{reads++;return preferences({4:true,5:true});};
  for(const callback of timeouts)callback();
  assert.deepEqual(native.get(),{necessary:true,...deny});
  assert.equal(reads,0);
});

test('closed document is checked before a route-dependent active callback',async t=>{
  const {w}=browser(t);
  const native=createConsent(w,'v1',true,'/api/consent',()=>!!w.location.pathname);
  w.close();
  assert.doesNotThrow(()=>native.get());
  assert.deepEqual(native.get(),{necessary:true,...deny});
});

for(const state of ['none','pending','rejected','denied'])test(`cookie approval cannot grant native ${state} choice`,async t=>{
  const {w,tick,requests}=browser(t), native=createConsent(w,'v1');
  if(state==='rejected')w.fetch=async()=>Response.json({ok:false},{status:400});
  let resolve;
  if(state==='pending')w.fetch=()=>resolve ? Promise.resolve(Response.json({ok:false},{status:400})) : new Promise(done=>{resolve=done;});
  const saving=state==='none'?undefined:native.set(state==='denied'?deny:grant);
  if(state!=='pending')await saving;
  native.useCMP('iubenda');
  w.document.cookie=cookie(preferences({4:true,5:true}));await tick();
  assert.deepEqual(native.get(),{necessary:true,...deny});
  assert.equal(native.receipt(),undefined);
  if(state==='pending'){resolve(Response.json({ok:false},{status:400}));await saving;}
  if(state==='none')assert.equal(requests.length,0,'external approval never creates a native grant receipt');
});
