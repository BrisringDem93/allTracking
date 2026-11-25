import test, {afterEach} from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {JSDOM} from 'jsdom';

const windows = new Set();
afterEach(() => { for (const w of windows) w.close(); windows.clear(); });
const load = async (name) => {
  const url = new URL(`../src/${name}.ts`, import.meta.url);
  assert.ok(existsSync(url), `${name} implementation missing`);
  return import(url.href);
};
const dom = () => { const {window:w} = new JSDOM('', {url:'https://www.example.test/shop/item/'}); windows.add(w); return w; };
const deny = {analytics:false,marketing:false};
const rule = (overrides={}) => ({enabled:true,label:'Test',category:'marketing',match:'equals',value:'tracker',action:'block_delete',...overrides});

test('settings reject malformed values instead of coercing or silently enabling defaults', async () => {
  const {normalizeGuard}=await load('guard-config');
  for (const bad of [null,[],true,new Date(),Object.create({mode:'enforce'}),{extra:1},{mode:'ENFORCE'},{mode:null},{rules:null},{rules:[{}]},
    {rules:[rule({enabled:1})]},{rules:[rule({ci:'0'})]},{rules:[rule({category:'necessary'})]},
    {rules:[rule({match:'prefix'})]},{rules:[rule({action:'allow'})]},{rules:[rule({value:''})]},
    {rules:[rule({label:3})]},{rules:[rule({match:'regex',value:'['})]},
    {rules:[rule({extra:1})]},{allowlist:['']},{allowlist:['session;evil']},{allowlist:[3]},
    {sweepInterval:249},{sweepInterval:1.5},{sweepDuration:-1},{sweepDuration:Infinity}]) {
    assert.throws(()=>normalizeGuard(bad),TypeError,JSON.stringify(bad));
  }
  const original=rule();const options=normalizeGuard({rules:[original],allowlist:['app_session']});
  original.value='mutated';
  assert.equal(options.rules[0].value,'tracker','configuration snapshots must not retain caller objects');
  assert.equal(normalizeGuard({sweepDuration:0}).sweepDuration,0);
});

test('pure evaluator matches all WP operators and keeps first denying match after granted matches', async () => {
  const {evaluateCookie}=await load('cookie-guard');
  for (const [match,value,value2,name,domain] of [
    ['equals','Ab','','aB',''],['contains','mid','','xxMIDxx',''],['starts_with','pre','','PREfix',''],
    ['ends_with','end','','theEND',''],['starts_ends','pre','end','PREmiddleEND',''],
    ['wildcard','a*?z','','ABz',''],['regex','^a[0-9]+$','','A12',''],['domain','.example.test','','x','sub.example.test']]) {
    assert.equal(evaluateCookie(name,domain,deny,{rules:[rule({match,value,value2,ci:true})]}).blocked,true,match);
  }
  assert.equal(evaluateCookie('aba','',deny,{rules:[rule({match:'starts_ends',value:'ab',value2:'ba'})]}).blocked,false);
  assert.equal(evaluateCookie('x','badexample.test',deny,{rules:[rule({match:'domain',value:'example.test'})]}).blocked,false);
  const rules=[rule({category:'analytics'}),rule({category:'always',label:'deny'})];
  assert.equal(evaluateCookie('tracker','',{analytics:true,marketing:true},{rules}).label,'deny');
  assert.equal(evaluateCookie('tracker','',deny,{rules:[rule({enabled:false})]}).blocked,false);
  assert.equal(evaluateCookie('tracker','',{...deny,marketing:true},{rules:[rule()]}).reason,'consent_granted');
  assert.equal(evaluateCookie('tracker','',deny,{rules:[rule({category:'preferences'})]}).blocked,true);
  assert.equal(evaluateCookie('tracker','',{...deny,preferences:true},{rules:[rule({category:'preferences'})]}).blocked,false);
  assert.equal(evaluateCookie('tracker','',{marketing:'true'},{rules:[rule()]}).blocked,true,'only literal true grants');
  assert.equal(evaluateCookie('%5Fga','',deny,{}).blocked,true,'decoded names share the same evaluator');
  assert.equal(evaluateCookie('','',deny,{}).reason,'empty_name');
});

test('nonremovable safety allowlist protects CMP and precise session names, not every secure prefix or own tracking ids', async () => {
  const {evaluateCookie}=await load('cookie-guard');
  const options={allowlist:[],rules:[rule({match:'wildcard',value:'*',category:'always'})]};
  for (const name of ['_iub_cs-123-uspr','CookieConsent','__Host-session','__Secure-next-auth.session-token.0','PHPSESSID'])
    assert.equal(evaluateCookie(name,'',deny,options).reason,'allowlist',name);
  for (const name of ['__Host-tracker','__Secure-_ga','fst_uid','fst_clid'])
    assert.equal(evaluateCookie(name,'',deny,options).blocked,true,name);
  assert.equal(evaluateCookie('tracker','',deny,{...options,allowlist:['TRACK*']}).reason,'allowlist');
});

test('off exposes pure diagnostics without patching cookies, timers, events or storage', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  const setter=Object.getOwnPropertyDescriptor(w.document,'cookie');
  const interval=t.mock.method(w,'setInterval',()=>{throw Error('timer');});
  const timeout=t.mock.method(w,'setTimeout',()=>{throw Error('timer');});
  const storage=t.mock.method(w.Storage.prototype,'setItem',()=>{throw Error('storage');});
  const api=installCookieGuard(w);
  assert.equal(w.allTrackingGuard,api);
  assert.equal(installCookieGuard(w,{mode:'enforce'}),api,'singleton requires destroy before reconfiguration');
  assert.equal(api.mode,'off');assert.equal(api.evaluate('_ga').blocked,true);
  api.refresh();w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.deepEqual(Object.getOwnPropertyDescriptor(w.document,'cookie'),setter);
  assert.equal(interval.mock.callCount()+timeout.mock.callCount()+storage.mock.callCount(),0);
  assert.equal(api.stats().installed,false);api.destroy();assert.equal(w.allTrackingGuard,undefined);
});

test('enforce uses fresh callback after sweeps end and never trusts CMP globals', async t => {
  const {installCookieGuard,evaluateCookie}=await load('cookie-guard'),w=dom();
  let consent=deny;
  w.Cookiebot={consent:{statistics:true,marketing:true}};
  w._iub={cs:{api:{getPreferences:()=>({consent:true})}}};
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0},()=>consent);
  t.after(()=>api.destroy());
  w.document.cookie='_ga=denied; Path=/';assert.equal(w.document.cookie,'');
  assert.equal(api.stats().blocked,1);assert.equal(api.stats().sweeping,false);
  consent={analytics:true,marketing:false};
  w.document.cookie='_ga=granted; Path=/';assert.ok(w.document.cookie.includes('_ga=granted'));
  assert.deepEqual(api.evaluate('_ga'),evaluateCookie('_ga','',consent,{}));
  consent=deny;w.document.cookie='_ga=changed; Path=/';
  assert.ok(w.document.cookie.includes('_ga=granted'),'revoked writes blocked before sweep');
  w.dispatchEvent(new w.Event('alltracking:consent'));assert.equal(w.document.cookie,'');
  assert.equal(api.stats().deleted,1);assert.equal(api.stats().installed,true);
});

test('monitor records verdicts but refresh, consent events and sweeps never write/delete', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  w.document.cookie='_ga=existing; Path=/';
  const native=Object.getOwnPropertyDescriptor(w.Document.prototype,'cookie');let writes=0;
  Object.defineProperty(w.document,'cookie',{configurable:true,get(){return native.get.call(this);},set(v){writes++;native.set.call(this,v);}});
  const before=Object.getOwnPropertyDescriptor(w.document,'cookie');
  const api=installCookieGuard(w,{mode:'monitor',sweepDuration:0});
  api.refresh();w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(writes,0);assert.ok(w.document.cookie.includes('_ga=existing'));
  w.document.cookie='_ga=allowed; Path=/';assert.equal(writes,1);
  assert.ok(w.document.cookie.includes('_ga=allowed'));
  assert.equal(api.stats().blocked+api.stats().deleted,0);
  assert.equal(api.stats().wouldBlock,1);assert.ok(api.stats().wouldDelete>=1);
  api.destroy();assert.deepEqual(Object.getOwnPropertyDescriptor(w.document,'cookie'),before);
});

test('native deletion bypass preserves Max-Age precedence and distinct block/delete actions', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  w.document.cookie='tracker=old; Path=/';
  const api=installCookieGuard(w,{mode:'enforce',rules:[rule({action:'block'})],sweepDuration:0});t.after(()=>api.destroy());
  assert.ok(w.document.cookie.includes('tracker=old'),'block-only does not purge');
  w.document.cookie='tracker=; Max-Age=0; Path=/';assert.equal(w.document.cookie,'');
  w.document.cookie='tracker=not-deletion; Max-Age=60; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/';
  assert.equal(w.document.cookie,'','positive Max-Age cannot bypass blocking with stale Expires');
  w.document.cookie='tracker=bad; Max-Age=0oops; Path=/';assert.equal(w.document.cookie,'');
  api.destroy();
  const deleter=installCookieGuard(w,{mode:'enforce',rules:[rule({action:'delete'})],sweepDuration:0});t.after(()=>deleter.destroy());
  w.document.cookie='tracker=present; Path=/';assert.ok(w.document.cookie.includes('tracker=present'));
  deleter.refresh();assert.equal(w.document.cookie,'');
});

test('sweeps purge raw encoded names over ancestor/trailing paths and parent domains without touching CMP/session state', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  for(const path of ['/','/shop','/shop/','/shop/item/']) w.document.cookie=`%5Fga=old; Path=${path}; Domain=.example.test`;
  w.document.cookie='_iub_cs-123=consent; Path=/';w.document.cookie='__Host-session=auth; Secure; Path=/';
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0});t.after(()=>api.destroy());
  assert.deepEqual(api.cookies().sort(),['__Host-session','_iub_cs-123'].sort());
  assert.equal(api.stats().deleted,1);
  assert.ok(api.stats().deleteAttempts>1);
});

test('sweep schedule ends, consent listener remains fresh, and destroy cancels every owned resource', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();let tick,stop;
  const intervals=t.mock.method(w,'setInterval',f=>{tick=f;return 42;});
  const timeouts=t.mock.method(w,'setTimeout',f=>{stop=f;return 43;});
  const clearI=t.mock.method(w,'clearInterval',()=>{}),clearT=t.mock.method(w,'clearTimeout',()=>{});
  let consent={analytics:true,marketing:true};
  const api=installCookieGuard(w,{mode:'enforce'},()=>consent);
  assert.equal(api.stats().sweeping,true);assert.equal(intervals.mock.callCount(),1);assert.equal(timeouts.mock.callCount(),1);
  w.document.cookie='_ga=present; Path=/';consent=deny;tick();assert.equal(w.document.cookie,'');
  stop();assert.equal(api.stats().sweeping,false);
  consent={analytics:true,marketing:true};w.document.cookie='_ga=fresh; Path=/';assert.ok(w.document.cookie.includes('_ga=fresh'));
  api.destroy();api.destroy();
  assert.ok(clearI.mock.calls.some(c=>c.arguments[0]===42));assert.ok(clearT.mock.calls.some(c=>c.arguments[0]===43));
  assert.equal(Object.hasOwn(w.document,'cookie'),false);
  w.document.cookie='_ga=after; Path=/';tick();w.dispatchEvent(new w.Event('alltracking:consent'));api.refresh();
  assert.ok(w.document.cookie.includes('_ga=after'));assert.equal(api.stats().active,false);
});

test('failed installation is visible and never schedules enforcement or claims success', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  Object.defineProperty(w.document,'cookie',{configurable:false,get:()=> '_ga=present',set:()=>{}});
  const timer=t.mock.method(w,'setInterval',()=>{throw Error('unexpected timer');});
  const api=installCookieGuard(w,{mode:'enforce'});
  assert.equal(api.stats().installed,false);assert.equal(api.stats().active,false);
  assert.match(api.stats().errors.join(' '),/nonconfigurable/);assert.equal(timer.mock.callCount(),0);
  assert.equal(api.evaluate('_ga').blocked,true);assert.equal(api.stats().deleted,0);api.destroy();
});

test('throwing consent callback fails closed while native deletion remains available', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0},()=>{throw Error('unavailable');});t.after(()=>api.destroy());
  w.document.cookie='_ga=denied; Path=/';assert.equal(w.document.cookie,'');
  assert.match(api.stats().errors.join(' '),/Consent callback failed/);
  w.document.cookie='_ga=; Max-Age=0; Path=/';
  const stats=api.stats();stats.errors.push('changed');assert.equal(api.stats().errors.includes('changed'),false);
});

test('receipt callback cannot grant from external CMP state before server confirmation', async t => {
  const {installCookieGuard}=await load('cookie-guard'),{createConsent}=await import('../src/consent-client.ts'),w=dom();
  let resolve;w.fetch=()=>new Promise(r=>resolve=r);
  const native=createConsent(w,'v1');
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0},()=>native.get());t.after(()=>api.destroy());
  w.Cookiebot={consent:{statistics:true,marketing:true}};
  const saving=native.set({analytics:true,marketing:true});
  w.document.cookie='_fbp=pending; Path=/';assert.equal(w.document.cookie,'');
  resolve(Response.json({ok:false},{status:503}));await saving;
  w.document.cookie='_fbp=failed; Path=/';assert.equal(w.document.cookie,'');
  w.fetch=async(_url,init)=>Response.json({ok:true,receipt:{...JSON.parse(init.body),signal:'none',createdAt:new Date().toISOString()}});
  await native.set({analytics:true,marketing:true});w.document.cookie='_fbp=confirmed; Path=/';
  assert.ok(w.document.cookie.includes('_fbp=confirmed'));
  w.fetch=async()=>{throw Error('offline');};await native.set(deny);assert.equal(w.document.cookie,'');
});

test('validation rejects sparse settings arrays rather than deferring crashes to the cookie setter', async () => {
  const {normalizeGuard}=await load('guard-config');
  assert.throws(()=>normalizeGuard({rules:new Array(1)}),TypeError);
  assert.throws(()=>normalizeGuard({allowlist:new Array(1)}),TypeError);
});

test('failed cookie readback never counts a deletion as confirmed', async t => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();let fail=false;
  Object.defineProperty(w.document,'cookie',{configurable:true,get(){if(fail)throw Error('unreadable');return '_ga=still';},set(){fail=true;}});
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0});t.after(()=>api.destroy());
  assert.equal(api.stats().deleted,0);
  assert.match(api.stats().errors.join(' '),/unavailable/);
});

test('stats report a lost setter and destroy never overwrites a later patch', async () => {
  const {installCookieGuard}=await load('cookie-guard'),w=dom();
  const api=installCookieGuard(w,{mode:'enforce',sweepDuration:0});
  const later={configurable:true,get:()=>'',set:()=>{}};Object.defineProperty(w.document,'cookie',later);
  assert.equal(api.stats().installed,false);
  assert.match(api.stats().errors.join(' '),/replaced/);
  api.destroy();assert.equal(Object.getOwnPropertyDescriptor(w.document,'cookie').get,later.get);
});

test('guard defaults are opt-in, preserve an empty rule set and return fresh presets', async () => {
  const {normalizeGuard,defaultRules,protectedPatterns} = await load('guard-config');
  const defaults=normalizeGuard();
  assert.equal(defaults.mode,'off');
  assert.equal(defaults.sweepInterval,2000);
  assert.equal(defaults.sweepDuration,60000);
  assert.equal(defaults.rules.length,25);
  assert.deepEqual(normalizeGuard({rules:[]}).rules,[]);
  assert.deepEqual(defaults.rules,defaultRules());
  defaults.rules[0].value='changed';
  assert.equal(defaultRules()[0].value,'_ga*');
  assert.ok(protectedPatterns().includes('_iub_cs-*'));
  assert.ok(normalizeGuard({allowlist:[]}).allowlist.includes('_iub_cs-*'));
});
