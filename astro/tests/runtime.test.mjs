import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const entry=new URL('../src/runtime.ts',import.meta.url);
const config={enabled:true,mode:'direct',cmp:'bridge',ga4Id:'G-TEST123',attribution:true,formEvents:true};
const policy={version:'policy-v1',ready:true,privacy:'Privacy di questo progetto',cookie:'Cookie di questo progetto',cmp:'bridge'};
const dom=()=>new JSDOM('<form data-landing-form></form><div data-policy-text="privacy"></div>',{url:'https://another.test/?utm_source=campaign'});
const load=async()=>{assert.ok(existsSync(entry),'Reusable bootstrap is missing');return import(entry);};

test('bootstrap is singleton, mounts receipt-gated tracking and uses configured same-origin endpoints',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  const calls=[];
  w.fetch=async(url,init)=>{calls.push({url,init});return Response.json(url==='/track/config'?config:url==='/track/policy'?policy:{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}});};
  const options={configEndpoint:'/track/config',policyEndpoint:'/track/policy',consentEndpoint:'/track/choice'};
  const first=startAllTracking(w,options),second=startAllTracking(w,options);
  assert.equal(first,second,'one initialization promise per document');
  await first;
  assert.equal(calls.length,2);
  assert.equal(w.document.querySelectorAll('script[src]').length,0);
  assert.deepEqual(w.nativeTracking.payload().consent,{analytics:false,marketing:false});
  await w.nativeConsent.set({analytics:true});
  assert.equal(calls[2].url,'/track/choice');
  assert.equal(w.document.querySelectorAll('script[src]').length,1);
  assert.equal(w.document.querySelector('[data-policy-text]').textContent,policy.privacy);
  assert.equal(w.nativeTracking.payload().attribution.utm_source,'campaign');
  assert.ok(calls.every(x=>x.init.redirect==='error'&&x.init.referrerPolicy==='no-referrer'&&x.init.credentials==='same-origin'));
});

test('disabled, runtime-off and decoded excluded paths never fetch or install consent/tracking',async t=>{
  const {startAllTracking}=await load();
  for(const path of ['/admin','/admin/secret','/%61dmin/settings','/%2fadmin','/%5cadmin','/%E0%A4%A','/normal']) {
    const d=dom(),w=d.window;t.after(()=>w.close());d.reconfigure({url:'https://another.test'+path});
    let requests=0;w.fetch=async()=>{requests++;return Response.json({});};
    if(path==='/normal')w.document.documentElement.dataset.runtime='off';
    await startAllTracking(w);
    assert.equal(requests,0,path);assert.equal(w.nativeConsent,undefined);assert.equal(w.nativeTracking,undefined);
  }
  const d=dom();t.after(()=>d.window.close());d.window.fetch=()=>{throw Error('must not fetch');};
  await startAllTracking(d.window,{disabled:true});assert.equal(d.window.nativeConsent,undefined);
});

test('Astro preparation falls back to full document navigation before any DOM swap, even when initially excluded',async t=>{
  const {startAllTracking}=await load();
  for(const path of ['/','/admin']) {
    const d=dom(),w=d.window;t.after(()=>w.close());d.reconfigure({url:'https://another.test'+path});
    w.fetch=async url=>Response.json(url.includes('policy')?policy:config);
    await startAllTracking(w);await startAllTracking(w);
    const event=new w.Event('astro:before-preparation',{cancelable:true});
    w.document.dispatchEvent(event);
    assert.equal(event.defaultPrevented,true);
  }
});

test('BFCache restoration reloads once without reopening stale consent or tracker closures',async t=>{
  const {startAllTracking}=await load();
  for(const astroNavigation of [false,true]) {
    const d=dom();t.after(()=>d.window.close());
    let reloads=0,finishReceipt;
    const w=new Proxy(d.window,{get(target,key){
      if(key==='location')return {
        pathname:target.location.pathname,origin:target.location.origin,href:target.location.href,
        reload(){
          reloads++;
          assert.equal(w.nativeTracking.payload().consent.analytics,false,'deny before requesting a fresh document');
        },
      };
      return Reflect.get(target,key,target);
    }});
    w.fetch=async(url,init)=>{
      if(init.method==='POST') {
        const response=Response.json({ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}});
        return finishReceipt===null?new Promise(resolve=>{finishReceipt=()=>resolve(response);}):response;
      }
      return Response.json(url.includes('policy')?policy:config);
    };
    await startAllTracking(w);await startAllTracking(w);
    await w.nativeConsent.set({analytics:true});
    w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:false}));
    assert.equal(reloads,0,'ordinary pageshow must not reload');
    assert.equal(w.nativeTracking.payload().consent.analytics,true);
    finishReceipt=null;
    const pending=w.nativeConsent.set({analytics:true});
    assert.equal(typeof finishReceipt,'function','receipt really pending before departure');
    if(astroNavigation)w.document.dispatchEvent(new w.Event('astro:before-preparation',{cancelable:true}));
    w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:true}));
    w.dispatchEvent(new w.PageTransitionEvent('pageshow',{persisted:true}));
    assert.equal(reloads,1,'singleton runtime requests a fresh document on BFCache restoration');
    finishReceipt();await pending;
    w.dispatchEvent(new w.Event('focus'));
    assert.equal(w.nativeConsent.get().analytics,false,'late receipt/focus cannot reopen the old document');
    assert.deepEqual(w.nativeTracking.payload().attribution,{});
    assert.equal(w['ga-disable-G-TEST123'],true);
  }
});

test('canceled preparation keeps the runtime until the page really unloads; stale tracker references deny on route changes',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  const pending=[];w.fetch=(url)=>new Promise(resolve=>pending.push({url,resolve}));
  const loading=startAllTracking(w);
  await new Promise(resolve=>setTimeout(resolve,0));
  assert.equal(pending.length,2,'both requests really started before navigation');
  const event=new w.Event('astro:before-preparation',{cancelable:true});w.document.dispatchEvent(event);
  assert.equal(event.defaultPrevented,true);
  for(const x of pending)x.resolve(Response.json(x.url.includes('policy')?policy:config));
  // A forced navigation that never unloads (download, 204, Stop) must not leave a dead banner/runtime.
  await loading;assert.ok(w.nativeTracking,'runtime still serves the visible document');
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:false}));
  assert.deepEqual(w.nativeTracking.payload().consent,{analytics:false,marketing:false},'real unload denies');
  const second=dom(),v=second.window;t.after(()=>v.close());
  v.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?policy:config);
  await startAllTracking(v);await v.nativeConsent.set({analytics:true});
  const tracker=v.nativeTracking;v.history.replaceState(null,'','/admin');
  assert.deepEqual(tracker.payload().consent,{analytics:false,marketing:false});
  assert.deepEqual(tracker.payload().attribution,{});
  tracker.confirm({ok:true,confirmed:true,eventId:'11111111-1111-4111-8111-111111111111',tracking:{enabled:true}},{analytics:true});
  assert.equal(v.dataLayer.filter(x=>x[1]==='generate_lead').length,0);
});

test('unknown CMP configuration cannot unlock consent or declarative optional scripts',async t=>{
  const {startAllTracking}=await load();
  for(const settings of [null,{}, {...config,cmp:'unknown'}]) {
    const d=dom(),w=d.window;t.after(()=>w.close());
    w.document.body.insertAdjacentHTML('beforeend','<script type="text/plain" data-consent-category="analytics" data-consent-src="/_astro/optional.js"></script>');
    w.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?policy:settings);
    await startAllTracking(w);await w.nativeConsent.set({analytics:true,marketing:true});
    assert.equal(w.nativeConsent.get().analytics,false);
    assert.equal(w.document.querySelectorAll('script[src]').length,0);
  }
});

test('missing, malformed or unready policy never grants even if tracking config is enabled',async t=>{
  const {startAllTracking}=await load();
  for(const response of [null,{}, {...policy,ready:false},{...policy,version:'v1\n'},{...policy,privacy:''}]) {
    const d=dom(),w=d.window;t.after(()=>w.close());
    w.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?response:config);
    await startAllTracking(w);await w.nativeConsent.set({analytics:true,marketing:true});
    assert.equal(w.nativeConsent.get().analytics,false);assert.equal(w.document.querySelectorAll('script[src]').length,0);
  }
});

test('manual bootstrap installs synchronous guard; departure preserves cookies but withdrawal purges',async t=>{
  const {startAllTracking}=await load();
  for(const persisted of [false,true]) {
    const d=dom(),w=d.window;t.after(()=>w.close());
    w.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?policy:config);
    const loading=startAllTracking(w,{guard:{mode:'enforce',sweepDuration:0},debug:{mode:'public'}});
    w.document.cookie='_ga=before-runtime; path=/';
    assert.equal(w.document.cookie,'','guard exists before the first await/DOMContentLoaded');
    const guard=w.allTrackingGuard;assert.ok(guard);
    await loading;
    await w.nativeConsent.set({analytics:true,marketing:true});
    w.document.cookie='_ga=granted; path=/';w.document.cookie='_fbp=granted; path=/';w.document.cookie='session=essential; path=/';
    assert.match(w.document.cookie,/_ga=granted/);
    const withdrawn=w.nativeConsent.set({analytics:false,marketing:false});
    assert.equal(w.document.cookie,'session=essential','genuine withdrawal sweeps synchronously');
    assert.equal(w.allTrackingGuard,guard);await withdrawn;
    await w.nativeConsent.set({analytics:true,marketing:true});
    w.document.cookie='_ga=retained; path=/';w.document.cookie='_fbp=retained; path=/';
    const preparation=new w.Event('astro:before-preparation',{cancelable:true});w.document.dispatchEvent(preparation);
    assert.equal(preparation.defaultPrevented,true);assert.equal(w.nativeConsent.get().analytics,true,'cancel alone is not departure');
    w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted}));
    // The setter stays: consent is frozen as it was when the page was left, and sweeps are inert.
    assert.equal(w.allTrackingGuard,guard);
    assert.equal(Object.hasOwn(w.document,'cookie'),true);
    assert.match(w.document.cookie,/_ga=retained/,'navigation denial must not delete granted cookies');
    assert.match(w.document.cookie,/_fbp=retained/);
    assert.deepEqual(w.nativeTracking.payload().consent,{analytics:false,marketing:false});
    assert.equal(w.document.querySelector('[data-alltracking-debug]'),null);
    guard.sweep();w.dispatchEvent(new w.Event('alltracking:consent'));
    assert.match(w.document.cookie,/_ga=retained/,'queued sweeps are inert');
    w.document.cookie='_ga=unload; path=/';assert.match(w.document.cookie,/_ga=unload/,'frozen grant still allows consented writers');
  }
});

test('departure keeps blocking unconsented writes made by unload-time listeners',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  w.fetch=async(url,init)=>Response.json(init.method==='POST'?{ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}:url.includes('policy')?policy:config);
  await startAllTracking(w,{guard:{mode:'enforce',sweepDuration:0}});
  w.addEventListener('pagehide',()=>{w.document.cookie='_ga=on-pagehide; path=/';w.document.cookie='session=kept; path=/';});
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:false}));
  w.document.cookie='_gcl_au=on-unload; path=/';
  assert.equal(w.document.cookie,'session=kept');
  assert.ok(w.allTrackingGuard.stats().blocked>=2);
});

test('debug uses resolved normalized config even with tracking disabled; omitted debug/guard stay off',async t=>{
  const {startAllTracking}=await load();
  for(const options of [{},{debug:{mode:'off'}},{debug:{mode:'public',settingsPath:'/admin/tracking'}}]) {
    const d=dom(),w=d.window;t.after(()=>w.close());
    w.fetch=async url=>Response.json(url.includes('policy')?policy:{...config,enabled:false,secret:'never expose'});
    await startAllTracking(w,options);
    assert.equal(w.allTrackingGuard,undefined);
    assert.equal(Object.hasOwn(w.document,'cookie'),false);
    const root=w.document.querySelector('[data-alltracking-debug]');
    assert.equal(!!root,options.debug?.mode==='public');
    if(root){
      assert.equal(root.querySelectorAll('[role=tab]').length,5);
      root.querySelectorAll('[role=tab]')[4].click();
      assert.match(root.querySelector('[data-debug-body]').textContent,/disattiva/);
      assert.doesNotMatch(root.textContent,/never expose/);
      assert.equal(root.querySelector('[data-debug-settings]').getAttribute('href'),'/admin/tracking');
    }
  }
});

test('excluded, disabled and runtime-off documents do no early guard/UI/timer/request work',async t=>{
  const {startAllTracking}=await load();
  for(const path of ['/admin','/%61dmin/settings','/%2fadmin','/%5cadmin','/%E0%A4%A','/disabled','/runtime-off']) {
    const d=dom(),w=d.window;t.after(()=>w.close());d.reconfigure({url:'https://another.test'+path});
    let requests=0,timers=0;w.fetch=async()=>{requests++;return Response.json({});};w.setInterval=()=>{timers++;return 1;};w.setTimeout=()=>{timers++;return 1;};
    if(path==='/runtime-off')w.document.documentElement.dataset.runtime='off';
    await startAllTracking(w,{disabled:path==='/disabled',guard:{mode:'enforce'},debug:{mode:'public'}});
    assert.equal(w.allTrackingGuard,undefined,path);assert.equal(Object.hasOwn(w.document,'cookie'),false,path);
    assert.equal(w.document.querySelector('[data-alltracking-debug]'),null,path);
    assert.equal(requests,0,path);assert.equal(timers,0,path);
  }
});

test('pending config cannot mount diagnostics after ordinary pagehide',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  const pending=[];w.fetch=url=>new Promise(resolve=>pending.push({url,resolve}));
  const loading=startAllTracking(w,{guard:{mode:'enforce'},debug:{mode:'public'}});
  await new Promise(resolve=>setTimeout(resolve,0));assert.equal(pending.length,2);
  w.dispatchEvent(new w.PageTransitionEvent('pagehide',{persisted:false}));
  for(const x of pending)x.resolve(Response.json(x.url.includes('policy')?policy:config));
  await loading;
  assert.equal(w.nativeTracking,undefined);assert.equal(w.allTrackingConsentReady,false);
  // Guard stays as a frozen, purge-free blocker until the document is gone.
  assert.ok(w.allTrackingGuard);w.document.cookie='_ga=late; path=/';assert.equal(w.document.cookie,'');
  assert.equal(w.document.querySelector('[data-alltracking-debug]'),null);
});

test('ClientRouter cannot turn form submissions into body-less GET navigations',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  w.fetch=async url=>Response.json(url.includes('policy')?policy:config);
  await startAllTracking(w,{disabled:true});
  const form=w.document.createElement('form');form.method='post';form.action='/thanks';w.document.body.append(form);
  // Same check ClientRouter runs in its bubbling document submit listener (isReloadEl).
  let routerSkips;w.document.addEventListener('submit',event=>{routerSkips=event.target.dataset.astroReload!==undefined;event.preventDefault();});
  form.requestSubmit();
  assert.equal(routerSkips,true,'native POST is kept, even on disabled/excluded documents');
});

test('early guard never accepts a CMP/global grant or a pending native receipt',async t=>{
  const {startAllTracking}=await load(),d=dom(),w=d.window;t.after(()=>w.close());
  let finishReceipt;
  w.Cookiebot={consent:{statistics:true,marketing:true}};
  w.fetch=async(url,init)=>{
    if(init.method==='POST')return new Promise(resolve=>{finishReceipt=()=>resolve(Response.json({ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}}));});
    return Response.json(url.includes('policy')?policy:config);
  };
  const loading=startAllTracking(w,{guard:{mode:'enforce',sweepDuration:0}});
  w.document.cookie='_ga=external-cmp; path=/';assert.equal(w.document.cookie,'');
  await loading;
  const pending=w.nativeConsent.set({analytics:true,marketing:true});
  assert.equal(typeof finishReceipt,'function');
  assert.equal(w.nativeConsent.get().analytics,false);
  w.document.cookie='_ga=unconfirmed; path=/';assert.equal(w.document.cookie,'');
  finishReceipt();await pending;
  w.document.cookie='_ga=receipt-confirmed; path=/';assert.equal(w.document.cookie,'_ga=receipt-confirmed');
});
