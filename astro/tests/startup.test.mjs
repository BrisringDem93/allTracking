import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM,CookieJar} from 'jsdom';
import {installCookieGuard} from '../src/cookie-guard.ts';
import {startEarlyTracking} from '../src/early.ts';
import {startAllTracking} from '../src/runtime.ts';
import {CONSENT_MAX_AGE} from '../src/tracking.ts';
const config={enabled:true,mode:'direct',cmp:'bridge',ga4Id:'G-TEST123'};
const policy={ready:true,version:'startup-v1',privacy:'Privacy',cookie:'Cookie'};
const options={guard:{mode:'enforce',sweepDuration:0}};
const page=(jar=new CookieJar(),path='/')=>new JSDOM('<!doctype html><html><head></head><body></body></html>',{url:'https://startup.test'+path,cookieJar:jar});
const transport=w=>{w.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?policy:config);};
const confirmed=async(t,jar=new CookieJar())=>{
  const d=page(jar),w=d.window;t.after(()=>w.close());transport(w);
  await startAllTracking(w,options);await w.nativeConsent.set({analytics:true,marketing:true});
  assert.equal(w.nativeConsent.get().analytics,true);
  w.document.cookie='_ga=previously-granted; path=/';
  const saved=w.localStorage.getItem('alltracking_consent');
  w.dispatchEvent(new w.PageTransitionEvent('pagehide'));
  assert.match(w.document.cookie,/_ga=previously-granted/);
  return saved;
};
const hold=w=>{
  const pending=[];w.fetch=url=>new Promise(resolve=>pending.push({url,resolve}));
  return {pending,finish(){for(const {url,resolve} of pending)resolve(Response.json(url.includes('policy')?policy:config));}};
};

test('purge readiness defers deletion only, never denied setters; default and monitor remain unchanged',()=>{
  for(const mode of ['enforce','monitor','off']){
    const d=page(),w=d.window;let ready=false;
    try{
      w.document.cookie='_ga=existing; path=/';
      const guard=installCookieGuard(w,{mode,sweepDuration:0},()=>({analytics:false,marketing:false}),()=>ready);
      assert.match(w.document.cookie,/_ga=existing/);
      w.document.cookie='_fbp=pending; path=/';
      assert.equal(w.document.cookie.includes('_fbp='),mode!=='enforce');
      guard.refresh();assert.match(w.document.cookie,/_ga=existing/);
      ready=true;guard.refresh();
      assert.equal(w.document.cookie.includes('_ga='),mode!=='enforce');
      if(mode==='monitor')assert.ok(guard.stats().wouldDelete>0);
      guard.destroy();
    }finally{w.close();}
  }
  const d=page();try{d.window.document.cookie='_ga=default; path=/';installCookieGuard(d.window,{mode:'enforce',sweepDuration:0});assert.equal(d.window.document.cookie,'');}finally{d.window.close();}
});

test('two pages sharing cookies preserve confirmed identity through pending policy restoration, never new writes',async t=>{
  const jar=new CookieJar(),saved=await confirmed(t,jar),d=page(jar),w=d.window;t.after(()=>w.close());
  w.localStorage.setItem('alltracking_consent',saved);
  const held=hold(w);startEarlyTracking(w,options);
  assert.match(w.document.cookie,/_ga=previously-granted/,'next head must not erase a prior confirmed identity');
  assert.equal(w.allTrackingConsentReady,false);
  w.document.cookie='_ga=overwrite-pending; path=/';w.document.cookie='_fbp=new-pending; path=/';
  assert.equal(w.document.cookie,'_ga=previously-granted');
  const loading=startAllTracking(w,options);
  await new Promise(resolve=>setTimeout(resolve,0));assert.equal(held.pending.length,2);
  w.allTrackingGuard.refresh();assert.equal(w.document.cookie,'_ga=previously-granted');
  assert.equal(w.nativeConsent.get().analytics,false,'storage cannot grant before current policy validation');
  held.finish();await loading;
  assert.equal(w.allTrackingConsentReady,true);assert.equal(w.nativeConsent.get().analytics,true);
  assert.equal(w.document.cookie,'_ga=previously-granted');
  transport(w);w.document.cookie='_fbp=now-confirmed; path=/';assert.match(w.document.cookie,/_fbp=now-confirmed/);
  const revoke=w.nativeConsent.set({analytics:false,marketing:false});
  assert.equal(w.document.cookie,'','actual revoke purges synchronously after readiness');await revoke;
});

test('invalid, expired, mismatched, unconfirmed and denied receipts purge only after policy resolution',async t=>{
  const saved=JSON.parse(await confirmed(t));
  for(const change of [{receiptId:'invalid'},{updatedAt:Date.now()-CONSENT_MAX_AGE-1},{version:'old-policy'},{confirmed:false},{analytics:false,marketing:false}]){
    const d=page(),w=d.window;t.after(()=>w.close());
    w.localStorage.setItem('alltracking_consent',JSON.stringify({...saved,...change}));w.document.cookie='_ga=old; path=/';
    const held=hold(w),loading=startAllTracking(w,options);
    assert.equal(w.document.cookie,'_ga=old');
    await new Promise(resolve=>setTimeout(resolve,0));
    assert.equal(w.nativeConsent.get().analytics,false);assert.equal(w.allTrackingConsentReady,false);
    held.finish();await loading;
    assert.equal(w.allTrackingConsentReady,true);assert.equal(w.nativeConsent.get().analytics,false);
    assert.equal(w.document.cookie,'',JSON.stringify(change));
  }
});

test('failed or timed-out configuration completes denial and purges existing cookies',async t=>{
  const keepAlive=setTimeout(()=>{},5000);t.after(()=>clearTimeout(keepAlive));
  for(const timeout of [false,true]){
    const d=page(),w=d.window;t.after(()=>w.close());w.document.cookie='_ga=old; path=/';
    let aborted=0;
    w.fetch=(_url,init)=>timeout?new Promise((_resolve,reject)=>init.signal.addEventListener('abort',()=>{aborted++;reject(init.signal.reason);},{once:true})):Promise.reject(Error('offline'));
    const loading=startAllTracking(w,options);
    assert.equal(w.document.cookie,'_ga=old');w.document.cookie='_fbp=pending; path=/';assert.equal(w.document.cookie,'_ga=old');
    await loading;
    assert.equal(w.allTrackingConsentReady,true);assert.equal(w.nativeConsent.get().analytics,false);assert.equal(w.document.cookie,'');
    if(timeout)assert.equal(aborted,2,'both real 4000ms AbortSignals fired');
  }
});

test('late policy and queued sweeps do no work after departure or a changed path',async t=>{
  for(const departure of ['pagehide','astro:before-preparation','path']){
    const d=page(),w=d.window;t.after(()=>w.close());w.document.cookie='_ga=old; path=/';
    const callbacks=[];w.setInterval=fn=>{callbacks.push(fn);return 100;};w.setTimeout=fn=>{callbacks.push(fn);return 101;};
    const held=hold(w),loading=startAllTracking(w,{guard:{mode:'enforce'},debug:{mode:'public'}});
    await new Promise(resolve=>setTimeout(resolve,0));assert.equal(held.pending.length,2);
    const guard=w.allTrackingGuard;
    if(departure==='path')w.history.replaceState(null,'','/admin');
    else (departure==='pagehide'?w:w.document).dispatchEvent(new w.Event(departure,{cancelable:true}));
    const counts=guard.stats();held.finish();await loading;for(const fn of callbacks)fn();guard.refresh();
    assert.equal(w.document.cookie,'_ga=old');assert.equal(w.allTrackingConsentReady,false);
    assert.equal(guard.stats().deleteAttempts,counts.deleteAttempts);assert.equal(w.nativeTracking,undefined);
    assert.equal(w.document.querySelector('[data-alltracking-debug]'),null);assert.equal(held.pending.length,2);
  }
});

test('excluded documents leave existing cookies, readiness and setter untouched',async t=>{
  for(const path of ['/admin','/%61dmin/settings']){
    const d=page(undefined,path),w=d.window;t.after(()=>w.close());w.document.cookie='_ga=excluded; path=/';
    w.fetch=()=>{throw Error('excluded network');};await startAllTracking(w,options);
    assert.equal(w.document.cookie,'_ga=excluded');assert.equal(w.allTrackingConsentReady,undefined);
    assert.equal(w.allTrackingGuard,undefined);assert.equal(Object.hasOwn(w.document,'cookie'),false);
    w.document.cookie='_fbp=untouched; path=/';assert.match(w.document.cookie,/_fbp=untouched/);
  }
});
