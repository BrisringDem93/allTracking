import test, {afterEach} from 'node:test';
const windows = new Set();
afterEach(() => { for (const w of windows) w.close(); windows.clear(); });
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const url = new URL('../src/consent-client.ts', import.meta.url);
const load = async () => {assert.ok(existsSync(url), 'native consent runtime missing'); return import(url.href);};
const dom = () => {
  const d=new JSDOM('<form data-landing-form></form>', {url:'https://another.test/'});
  windows.add(d.window);
  d.window.fetch=async(_url,init)=>Response.json({ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}});
  return d;
};
const version = 'policy-v1';

test('server-observed GPC metadata does not override an explicit client choice',async t=>{
  const {createConsent}=await load();const d=dom(),w=d.window;t.after(()=>w.close());
  w.fetch=async(_url,init)=>Response.json({ok:true,receipt:{...JSON.parse(init.body),signal:'gpc',createdAt:new Date().toISOString()}});
  const cmp=createConsent(w,version);
  await cmp.set({analytics:true,marketing:true});
  assert.deepEqual(cmp.get(),{necessary:true,analytics:true,marketing:true});
  assert.ok(cmp.receipt());
});

for(const invalidation of ['policy','expiry']) test(`memory-only confirmed receipt still obeys ${invalidation} invalidation`,async t=>{
  const {createConsent}=await load();const d=dom(),w=d.window;t.after(()=>w.close());
  const cmp=createConsent(w,version);
  w.Storage.prototype.setItem=function(){throw Error('blocked');};
  w.Storage.prototype.removeItem=function(){throw Error('blocked');};
  await cmp.set({analytics:true,marketing:true});
  assert.equal(cmp.get().analytics,true);
  const timers=t.mock.method(w,'setTimeout',()=>1);
  if(invalidation==='policy') cmp.configure('v2',true);
  else {const now=Date.now();t.mock.method(Date,'now',()=>now+180*86400*1000);w.dispatchEvent(new w.Event('focus'));}
  assert.deepEqual(cmp.get(),deny);
  assert.equal(cmp.hasChoice(),false);
  assert.equal(cmp.receipt(),undefined);
  assert.equal(timers.mock.callCount(),0,'expired or old-policy memory state cannot schedule a tight restore loop');
});

test('iubenda getPreferences can read localStorage without re-entering its adapter',async t=>{
  const {createConsent}=await load(),{createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;t.after(()=>w.close());
  const cmp=createConsent(w,version);await cmp.set({analytics:true,marketing:true});
  let depth=0,maxDepth=0;
  const reads=[];
  w._iub={cs:{api:{getPreferences(){
    depth++;maxDepth=Math.max(maxDepth,depth);
    try {
      // Bound the broken implementation so RED is fast instead of a stack overflow.
      if(depth>3) return {};
      reads.push(w.localStorage.getItem('_ga'));
      w.localStorage.getItem('_iub_cs-1234');
      return {purposes:{4:true,5:true}};
    } finally {depth--;}
  }}}};
  const tracker=createTracking(w,{enabled:true,mode:'direct',cmp:'iubenda',ga4Id:'G-ABCD'});
  assert.equal(tracker.payload().consent.analytics,true);
  assert.equal(maxDepth,1,'no recursive getPreferences');
  assert.ok(reads.length>0);assert.ok(reads.every(v=>v===null),'reentrant optional storage reads fail closed');
});

test('volatile revoke tombstone blocks old durable grant when both setItem and removeItem fail',async t=>{
  const {createConsent}=await load(),{createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;t.after(()=>w.close());
  const cmp=createConsent(w,version),tracker=createTracking(w,{enabled:true,mode:'direct',ga4Id:'G-ABCD',attribution:true});
  await cmp.set({analytics:true,marketing:true});
  const old=w.localStorage.getItem('alltracking_consent');
  const set=w.Storage.prototype.setItem,remove=w.Storage.prototype.removeItem;
  w.Storage.prototype.setItem=function(){throw Error('blocked');};
  w.Storage.prototype.removeItem=function(){throw Error('blocked');};
  w.fetch=async()=>{throw Error('DB down');};
  await cmp.set(deny);
  assert.deepEqual(cmp.get(),deny);
  assert.equal(w.localStorage.getItem('alltracking_consent'),old,'exact stale grant remains physically stored');
  for(const event of ['focus','pageshow','storage']) {
    w.dispatchEvent(event==='storage'?new w.StorageEvent(event,{key:'alltracking_consent'}):new w.Event(event));
    assert.deepEqual(cmp.get(),deny,event);
    assert.equal(tracker.payload().receipt,undefined);
    assert.deepEqual(tracker.payload().attribution,{});
    assert.equal(w['ga-disable-G-ABCD'],true);
  }
  w.Storage.prototype.setItem=set;w.Storage.prototype.removeItem=remove;
  await cmp.set(deny);
  assert.notEqual(w.localStorage.getItem('alltracking_consent'),old);
  w.dispatchEvent(new w.Event('focus'));assert.deepEqual(cmp.get(),deny);
});

for(const scenario of ['stale-rejected','subject-mismatch','receipt-mismatch','version-mismatch','categories-mismatch','necessary-mismatch','expired','valid','late-after-revoke']) test(`pending grant: ${scenario} never unlocks before coherent server confirmation`,async t=>{
  const {createConsent}=await load(),{createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;t.after(()=>w.close());
  w.document.body.insertAdjacentHTML('beforeend','<script type="text/plain" data-consent-category="analytics" data-consent-src="/_astro/stats.js"></script>');
  const pending=[];
  w.fetch=(_url,init)=>new Promise(resolve=>pending.push({resolve,body:JSON.parse(init.body)}));
  const cmp=createConsent(w,version),tracker=createTracking(w,{enabled:true,mode:'direct',ga4Id:'G-ABCD',metaPixelId:'123456789',attribution:true});
  const saving=cmp.set({analytics:true,marketing:true});
  assert.deepEqual(cmp.get(),deny);
  assert.equal(tracker.payload().receipt,undefined);
  assert.equal(w.document.querySelectorAll('script[src]').length,0);
  w.dispatchEvent(new w.Event('focus'));
  assert.deepEqual(cmp.get(),deny,'focus cannot turn pending persisted choice into grant');
  if(scenario==='late-after-revoke') cmp.set(deny);
  const receipt={...pending[0].body,createdAt:new Date().toISOString()};
  if(scenario==='subject-mismatch') receipt.subjectId='11111111-1111-4111-8111-111111111111';
  if(scenario==='receipt-mismatch') receipt.receiptId='22222222-2222-4222-8222-222222222222';
  if(scenario==='version-mismatch') receipt.version='v2';
  if(scenario==='categories-mismatch') receipt.marketing=false;
  if(scenario==='necessary-mismatch') receipt.necessary=false;
  if(scenario==='expired') receipt.createdAt=new Date(Date.now()-180*86400*1000).toISOString();
  pending[0].resolve(Response.json(scenario==='stale-rejected'?{ok:false}:{ok:true,receipt},{status:scenario==='stale-rejected'?400:200}));
  await saving;await new Promise(r=>setTimeout(r,0));
  const valid=scenario==='valid';
  assert.equal(cmp.get().analytics,valid);
  assert.equal(w.document.querySelectorAll('script[src]').length,valid?3:0);
  assert.equal(!!tracker.payload().receipt,valid);
  w.dispatchEvent(new w.Event('pageshow'));
  assert.equal(cmp.get().analytics,valid);
});

test('browser attaches only the current receipt reference to lead payload, never hidden fields',async t=>{
  const {createConsent}=await load(),{createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;t.after(()=>w.close());
  w.fetch=async(_url,init)=>Response.json({ok:true,receipt:{...JSON.parse(init.body),createdAt:new Date().toISOString()}});
  const cmp=createConsent(w,version),tracker=createTracking(w,{enabled:true,mode:'direct',attribution:true});
  assert.equal(tracker.payload().receipt,undefined);
  await cmp.set({analytics:true,marketing:true});
  const saved=JSON.parse(w.localStorage.getItem('alltracking_consent'));
  assert.deepEqual(tracker.payload().receipt,{subjectId:saved.subjectId,receiptId:saved.receiptId,version});
  assert.doesNotMatch(w.document.querySelector('form').innerHTML,/receiptId|subjectId/);
  await cmp.set(deny);
  assert.equal(tracker.payload().receipt,undefined);
});

test('explicit user choice prevails over browser DNT/GPC; cross-tab revocation still notifies allTracking', async () => {
  const {createConsent} = await load();
  const {readConsent} = await import('../src/tracking.ts');
  for (const signal of ['globalPrivacyControl','doNotTrack']) {
    const d = dom(), w = d.window;
    Object.defineProperty(w.navigator, signal, {value:signal === 'globalPrivacyControl' ? true : '1',configurable:true});
    const cmp = createConsent(w, version);
    await cmp.set({analytics:true,marketing:true});
    assert.deepEqual(cmp.get(), {necessary:true,analytics:true,marketing:true});
    w.Cookiebot = {consent:{statistics:true,marketing:true}};
    assert.deepEqual(readConsent('cookiebot',w), {analytics:true,marketing:true});
    w.close();
  }
  const d = dom(), w = d.window, cmp = createConsent(w,version);
  await cmp.set({analytics:true,marketing:true});
  let events = 0;
  w.addEventListener('alltracking:consent',()=>events++);
  w.localStorage.removeItem('alltracking_consent');
  w.dispatchEvent(new w.StorageEvent('storage',{key:'alltracking_consent'}));
  assert.deepEqual(cmp.get(),deny);
  assert.equal(events,1);
  w.Cookiebot = {consent:{statistics:true,marketing:true}};
  assert.deepEqual(readConsent('cookiebot',w), {analytics:false,marketing:false}, 'native refusal cannot be bypassed by adapter');
  w.close();
});
const deny = {necessary:true, analytics:false, marketing:false};

test('consent runtime does not monkeypatch document.cookie', async () => {
  const {createConsent} = await load();
  const d = dom(), w = d.window;
  const nativeCookie = Object.getOwnPropertyDescriptor(w.Document.prototype, 'cookie');
  w.document.cookie = 'CookieConsent=needed; path=/';
  w.document.cookie = '_ga=GA1.1.123.456; path=/';
  w.document.cookie = 'unmanaged=leave-me; path=/';
  const cmp = createConsent(w,version);
  assert.equal(Object.hasOwn(w.document,'cookie'),false);
  assert.match(w.document.cookie, /CookieConsent=needed/);
  assert.match(w.document.cookie, /_ga=GA1\.1\.123\.456/);
  assert.match(w.document.cookie, /unmanaged=leave-me/);
  w.document.cookie = '_fbp=available; path=/';
  assert.match(nativeCookie.get.call(w.document), /_fbp=available/);
  await cmp.set(deny);
  assert.match(w.document.cookie, /_fbp=available/);
  w.close();
});

test('declarative scripts stay inert until matching grant, load once and reject CMS-style inline/foreign sources', async () => {
  const {createConsent} = await load();
  const d = dom(), w = d.window;
  w.document.body.insertAdjacentHTML('beforeend', `
    <script type="text/plain" data-consent-category="analytics" data-consent-src="/_astro/stats.ABCD.js"></script>
    <script type="text/plain" data-consent-category="analytics" data-consent-src="/_astro/stats.ABCD.js"></script>
    <script type="text/plain" data-consent-category="marketing" data-consent-src="/_astro/ads.ABCD.js"></script>
    <script type="text/plain" data-consent-category="analytics" data-consent-src="https://evil.test/x.js"></script>
    <script type="text/plain" data-consent-category="analytics">window.pwned=true</script>
    <script type="text/plain" data-consent-category="unknown" data-consent-src="/_astro/x.js"></script>`);
  const cmp = createConsent(w,version);
  const active = () => [...w.document.querySelectorAll('script[src]')].map(s=>s.src);
  assert.deepEqual(active(),[]);
  await cmp.set({analytics:true});
  assert.deepEqual(active(),['https://another.test/_astro/stats.ABCD.js']);
  await cmp.set({analytics:true,marketing:true});
  await cmp.set({analytics:true,marketing:true});
  assert.equal(active().length,2);
  await cmp.set(deny);
  await cmp.set({analytics:true,marketing:true});
  assert.equal(active().length,2);
  assert.equal(w.pwned,undefined);
  const dynamic = w.document.createElement('script');
  dynamic.type='text/plain'; dynamic.dataset.consentCategory='analytics'; dynamic.dataset.consentSrc='/_astro/dynamic.ABCD.js';
  w.document.body.append(dynamic);
  await new Promise(resolve=>setTimeout(resolve,0));
  assert.equal(active().length,3);
  w.close();
});

test('consent runtime leaves application storage APIs untouched', async () => {
  const {createConsent} = await load();
  const d = dom(), w = d.window;
  w.localStorage.setItem('unmanaged','preserve');
  const getItem=w.Storage.prototype.getItem,setItem=w.Storage.prototype.setItem;
  const cmp = createConsent(w,version);
  assert.equal(w.Storage.prototype.getItem,getItem);
  assert.equal(w.Storage.prototype.setItem,setItem);
  w.localStorage.setItem('_ga','available');
  w.sessionStorage.setItem('ati_click_ids','available');
  await cmp.set(deny);
  assert.equal(w.localStorage.getItem('_ga'),'available');
  assert.equal(w.sessionStorage.getItem('ati_click_ids'),'available');
  assert.ok(w.localStorage.getItem('alltracking_consent'));
  assert.equal(w.localStorage.unmanaged,'preserve');
  w.close();
});

test('audit logging sends only pseudonymous receipt and never delays or undoes revocation on DB failure', async () => {
  const {createConsent} = await load();
  const {createTracking} = await import('../src/tracking-client.ts');
  const d = dom(), w = d.window, calls=[], statuses=[];
  w.addEventListener('alltracking:audit',e=>statuses.push(e.detail.status));
  w.fetch = async (url,init) => {calls.push({url,init});throw Error('DB unavailable');};
  const cmp = createConsent(w,version);
  const tracker = createTracking(w,{enabled:true,mode:'direct',ga4Id:'G-TEST123',metaPixelId:'123456789',attribution:true});
  assert.equal(calls.length,0);
  await cmp.set({analytics:true,marketing:true});
  await cmp.set(deny);
  assert.deepEqual(tracker.payload().consent,{analytics:false,marketing:false});
  assert.equal(w.fbq,undefined,'failed grant must never have loaded Meta');
  await new Promise(resolve=>setTimeout(resolve,0));
  assert.equal(calls.length,2);
  const body = JSON.parse(calls[1].init.body);
  assert.deepEqual(Object.keys(body).sort(),['subjectId','receiptId','version','necessary','analytics','marketing','signal'].sort());
  assert.equal(body.analytics,false);
  assert.equal(body.signal,'none');
  assert.equal(calls[1].url,'/api/consent');
  assert.equal(calls[1].init.referrerPolicy,'no-referrer');
  assert.equal(calls[1].init.redirect,'error');
  assert.equal(statuses.at(-1),'failed');
  assert.deepEqual(cmp.get(),deny);
  assert.equal(w.document.querySelectorAll('script[src]').length,0);
  w.close();
});

test('policy unavailable denies grants, configuring a current policy restores only matching choices', async () => {
  const {createConsent} = await load();
  const d = dom(),w=d.window;
  const cmp=createConsent(w,'pending',false);
  await cmp.set({analytics:true,marketing:true});
  assert.deepEqual(cmp.get(),deny);
  assert.equal(typeof cmp.configure,'function');
  cmp.configure(version,true);
  await cmp.set({analytics:true,marketing:false});
  assert.equal(cmp.get().analytics,true);
  cmp.configure('changed',true);
  assert.deepEqual(cmp.get(),deny);
  w.close();
});

test('legacy app state remains accessible and later browser signals do not override the choice', async()=>{
  const {createConsent}=await load();
  const {createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;
  w.document.cookie='fst_clid=legacy; path=/';
  w.sessionStorage.setItem('fst_clid','legacy');
  const native=Object.getOwnPropertyDescriptor(w.Document.prototype,'cookie');
  const cmp=createConsent(w,version);
  assert.match(native.get.call(w.document),/fst_clid=legacy/);
  assert.equal(w.sessionStorage.fst_clid,'legacy');
  const tracker=createTracking(w,{enabled:true,mode:'direct',metaPixelId:'123456789',attribution:true});
  await cmp.set({marketing:true});
  w.document.cookie='_fbp=fb.1.123.456';
  Object.defineProperty(w.navigator,'globalPrivacyControl',{value:true});
  w.dispatchEvent(new w.Event('focus'));
  assert.deepEqual(w.fbq.queue.at(-1),['consent','grant']);
  assert.equal(tracker.payload().consent.marketing,true);
  assert.match(native.get.call(w.document),/_fbp=/);
  w.close();
});

test('unavailable storage and UUID generation cannot break revocation propagation', async()=>{
  const {createConsent}=await load();
  const d=dom(),w=d.window,cmp=createConsent(w,version);
  await cmp.set({analytics:true,marketing:true});
  let events=0;
  w.addEventListener('alltracking:consent',()=>events++);
  w.crypto.randomUUID=()=>{throw Error('entropy unavailable');};
  assert.doesNotThrow(()=>cmp.set(deny));
  assert.deepEqual(cmp.get(),deny);
  assert.equal(events,1);
  w.close();
});

test('consent identifiers reject trailing line terminators at the shared boundary',async()=>{
  const {UUID}=await import('../src/tracking.ts');
  const id='11111111-1111-4111-8111-111111111111';
  assert.equal(UUID.test(id),true);
  for(const suffix of ['\n','\r','\u2028','\u2029']) assert.equal(UUID.test(id+suffix),false);
});

test('CMP adapters never enumerate browser globals or access forbidden storage getters',async()=>{
  const {readConsent}=await import('../src/tracking.ts');
  const scope={nativeConsent:{},allTrackingConsent:{analytics:true,marketing:false},Cookiebot:{consent:{statistics:true,marketing:true}}};
  Object.defineProperty(scope,'localStorage',{enumerable:true,get(){throw Error('storage denied');}});
  assert.deepEqual(readConsent('cookiebot',scope),{analytics:true,marketing:false});
});

test('revocation disables GA collection, not only cookie storage, and regrant re-enables without new tags',async()=>{
  const {createConsent}=await load();
  const {createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window,cmp=createConsent(w,version);
  createTracking(w,{enabled:true,mode:'direct',ga4Id:'G-TEST123'});
  await cmp.set({analytics:true});
  assert.equal(w['ga-disable-G-TEST123'],false);
  await cmp.set(deny);
  assert.equal(w['ga-disable-G-TEST123'],true);
  await cmp.set({analytics:true});
  assert.equal(w['ga-disable-G-TEST123'],false);
  assert.equal(w.document.querySelectorAll('script[src*="gtag/js"]').length,1);
  w.close();
});

test('revocation on a policy page does not globally delete cookies on another path',async()=>{
  const {createConsent}=await load();
  const d=dom(),w=d.window;
  const cookie=Object.getOwnPropertyDescriptor(w.Document.prototype,'cookie');
  cookie.set.call(w.document,'_fbp=old; Path=/scopri-ora');
  cookie.set.call(w.document,'CookieConsent=keep; Path=/scopri-ora');
  assert.match(d.cookieJar.getCookieStringSync('https://another.test/scopri-ora'),/_fbp=/);
  const cmp=createConsent(w,version);await cmp.set(deny);
  const remaining=d.cookieJar.getCookieStringSync('https://another.test/scopri-ora');
  assert.match(remaining,/_fbp=old/);assert.match(remaining,/CookieConsent=keep/);
  w.close();
});

test('live expiry denies payload and notifies loaded tags without focus or reload',async t=>{
  const {createConsent}=await load();
  const {createTracking}=await import('../src/tracking-client.ts');
  const d=dom(),w=d.window;
  let now=Date.now();
  t.mock.method(Date,'now',()=>now);
  const timers=[];
  t.mock.method(w,'setTimeout',(callback,delay)=>{timers.push({callback,delay});return timers.length;});
  t.mock.method(w,'clearTimeout',()=>{});
  w.localStorage.setItem('alltracking_consent',JSON.stringify({confirmed:true,necessary:true,analytics:true,marketing:true,version,subjectId:'11111111-1111-4111-8111-111111111111',receiptId:'22222222-2222-4222-8222-222222222222',updatedAt:now-180*86400*1000+100}));
  const cmp=createConsent(w,version),tracker=createTracking(w,{enabled:true,mode:'direct',ga4Id:'G-TEST123'});
  assert.equal(cmp.get().analytics,true);
  assert.equal(timers.at(-1).delay,100);
  now+=100;
  timers.at(-1).callback();
  assert.deepEqual(cmp.get(),deny);
  assert.equal(cmp.hasChoice(),false);
  assert.equal(w.localStorage.getItem('alltracking_consent'),null,'expired pseudonymous state must not be retained');
  assert.equal(tracker.payload().consent.analytics,false);
  assert.equal(w['ga-disable-G-TEST123'],true);
  w.close();
});

test('legacy CMP revocation gates tracker and optional scripts without intercepting cookies or storage',async t=>{
  const {createConsent}=await load(),{createTracking}=await import('../src/tracking-client.ts');
  for(const cmpName of ['cookiebot','iubenda']){
    const d=dom(),w=d.window;t.after(()=>w.close());
    w.Cookiebot={consent:{statistics:true,marketing:true}};
    let legacy=true;w._iub={cs:{api:{getPreferences:()=>({purposes:{4:legacy,5:legacy}})}}};
    const cmp=createConsent(w,version);await cmp.set({analytics:true,marketing:true});
    const tracker=createTracking(w,{enabled:true,cmp:cmpName,mode:'direct',ga4Id:'G-TEST123'});
    w.document.cookie='_ga=before';w.localStorage.setItem('_fbp','before');
    legacy=false;w.Cookiebot.consent={statistics:false,marketing:false};
    w.dispatchEvent(new w.Event(cmpName==='cookiebot'?'CookiebotOnDecline':'iubenda:consent'));
    assert.deepEqual(cmp.get(),deny);
    assert.equal(tracker.payload().consent.analytics,false);
    assert.match(w.document.cookie,/_ga=before/);assert.equal(w.localStorage.getItem('_fbp'),'before');
    w.document.cookie='_fbp=after';w.localStorage.setItem('_fbp','after');
    assert.match(w.document.cookie,/_fbp=after/);assert.equal(w.localStorage.getItem('_fbp'),'after');
    await cmp.set({analytics:true,marketing:true});
    assert.equal(JSON.parse(w.localStorage.getItem('alltracking_consent')).analytics,false,'persist and audit only the effective consent');
    w.document.body.innerHTML='<script type="text/plain" data-consent-category="marketing" data-consent-src="/_astro/blocked.js"></script>';
    await new Promise(r=>setTimeout(r,0));assert.equal(w.document.querySelector('script[src$="blocked.js"]'),null);
    w.close();
  }
});

test('native consent defaults deny, grants separate categories, rejects and restores only current unexpired policy', async () => {
  const {createConsent} = await load();
  const d = dom(), w = d.window;
  try {
    let events = 0;
    w.addEventListener('alltracking:consent', () => events++);
    const cmp = createConsent(w, version);
    assert.deepEqual(w.allTrackingConsent, deny);
    assert.equal(w.localStorage.length, 0);
    assert.equal(createConsent(w, version), cmp, 'single initialization');
    await cmp.set({analytics:true,marketing:false});
    assert.deepEqual(w.allTrackingConsent, {...deny,analytics:true});
    assert.equal(events, 3); // initialization, pending denial, confirmed grant
    const saved = w.localStorage.getItem('alltracking_consent');
    assert.ok(saved);
    const reload = dom();
    reload.window.localStorage.setItem('alltracking_consent', saved);
    createConsent(reload.window, version);
    assert.deepEqual(reload.window.allTrackingConsent, {...deny,analytics:true});
    reload.window.close();
    await cmp.set({analytics:false,marketing:false});
    assert.deepEqual(w.allTrackingConsent, deny);
    const changed = dom();
    changed.window.localStorage.setItem('alltracking_consent', saved);
    createConsent(changed.window, 'policy-v2');
    assert.deepEqual(changed.window.allTrackingConsent, deny);
    changed.window.close();
    const expired = dom();
    expired.window.localStorage.setItem('alltracking_consent', JSON.stringify({...JSON.parse(saved),updatedAt:0}));
    createConsent(expired.window, version);
    assert.deepEqual(expired.window.allTrackingConsent, deny);
    expired.window.close();
  } finally {w.close();}
});
