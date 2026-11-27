import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
const client = await import('../src/tracking-client.ts');

test('revoca rimuove hidden, azzera payload e notifica i tag già caricati', () => {
  const d = dom(), w = d.window;
  w.allTrackingConsent = {analytics:true, marketing:true};
  const tracker = client.createTracking(w, config);
  w.allTrackingConsent = {analytics:false, marketing:false};
  w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(w.document.querySelectorAll('[data-attribution]').length, 0);
  assert.deepEqual(tracker.payload().attribution, {});
  assert.deepEqual(Array.from(w.dataLayer.at(-1)), ['consent', 'update', {analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'}]);
  assert.deepEqual(w.fbq.queue.at(-1), ['consent','revoke']);
  d.window.close();
});
const dom = () => new JSDOM('<form data-landing-form><input name="email" value="private@example.test"></form>', {url:'https://site.test/?fbclid=CLICK&utm_source=test'});
const config = {enabled:true,mode:'direct',ga4Id:'G-TEST123',metaPixelId:'123456789',attribution:true,formEvents:true};

test('GTM starts denied once; only effective consent changes notify the container', () => {
  const d = dom(), w = d.window;
  w.Cookiebot = {consent:{statistics:false,marketing:false}};
  client.createTracking(w, {...config, mode:'gtm', gtmId:'GTM-TEST123', cmp:'cookiebot'});
  client.createTracking(w, {...config, mode:'gtm', gtmId:'GTM-TEST123', cmp:'cookiebot'});
  assert.equal(w.document.scripts.length, 1);
  const states = [[false,false],[true,false],[true,true],[false,false]];
  for (const [statistics,marketing] of states.slice(1)) {
    Object.assign(w.Cookiebot.consent,{statistics,marketing});
    w.dispatchEvent(new w.Event('CookiebotOnAccept'));
    w.dispatchEvent(new w.Event('CookiebotOnConsentReady'));
    w.document.querySelector('form').dispatchEvent(new w.Event('submit',{bubbles:true}));
  }
  const storage = (a,m) => ({analytics_storage:a?'granted':'denied',ad_storage:m?'granted':'denied',ad_user_data:m?'granted':'denied',ad_personalization:m?'granted':'denied'});
  const [initial,start,...changes] = w.dataLayer;
  assert.deepEqual(Array.from(initial), ['consent','default',storage(false,false)]);
  assert.equal(start.event,'gtm.js');
  assert.deepEqual(changes.map(x => x.event ? x : Array.from(x)), states.flatMap(([analytics,marketing]) => [
    ['consent','update',storage(analytics,marketing)], {event:'alltracking_consent_update',analytics,marketing}
  ]));
  assert.deepEqual(Array.from(w.document.scripts,s=>s.src), ['https://www.googletagmanager.com/gtm.js?id=GTM-TEST123']);
  assert.equal(w.fbq, undefined);
  d.window.close();
});

test('Astro GTM Meta example: no WP transport, one loader/PageView, revoke and regrant', () => {
  const html = readFileSync(new URL('../gtm/meta-pageview.html',import.meta.url),'utf8');
  const script = html.trim().slice('<script>'.length,-'</script>'.length).replace('REPLACE_WITH_PIXEL_ID','123456789');
  const d = dom(), w = d.window;
  let marketing = false;
  w.nativeConsent = {get:() => ({marketing})};
  const fire = () => runInNewContext(script,{window:w,document:w.document});
  fire(); assert.equal(w.fbq,undefined);
  marketing = true; fire(); fire();
  assert.equal(w.fbq.queue.length,0,'do not queue hits while the vendor loader is pending');
  marketing = false; fire(); w.document.scripts[0].onload();
  assert.equal(w.fbq.queue.length,0);
  marketing = true; fire(); fire();
  marketing = false; fire(); marketing = true; fire();
  assert.equal(w.fbq,w._fbq);
  assert.equal(w.document.scripts.length,1);
  const calls = Array.from(w.fbq.queue,x=>Array.from(x));
  assert.deepEqual(calls.filter(x=>x[0]==='init'),[['init','123456789']]);
  assert.deepEqual(calls.filter(x=>x[0]==='track'),[['track','PageView']]);
  assert.deepEqual(calls.slice(-2),[['consent','revoke'],['consent','grant']]);
  assert.equal(w.document.cookie,'');
  d.window.close();
});

test('lead solo confermato dal Worker, dedup Meta e proprietà GA4 server esclusiva', () => {
  const d = dom(), w = d.window;
  const consent = {analytics:true,marketing:true};
  w.allTrackingConsent = consent;
  const tracker = client.createTracking(w, config);
  assert.equal(typeof tracker.confirm, 'function');
  const eventId = '11111111-1111-4111-8111-111111111111';
  const response = {ok:true,confirmed:true,eventId,tracking:{enabled:true,serverGA4:true}};
  for (const result of [null, {ok:true}, {...response,ok:false}, {...response,confirmed:false}]) tracker.confirm(result, consent);
  assert.equal(w.fbq.queue.filter(x => x[1] === 'Lead').length, 0);
  tracker.confirm(response, consent);
  tracker.confirm(response, consent);
  assert.deepEqual(w.fbq.queue.filter(x => x[1] === 'Lead'), [['track','Lead',{}, {eventID:eventId}]]);
  assert.equal(w.dataLayer.filter(x => x[1] === 'generate_lead').length, 0);
  tracker.confirm({...response,eventId:'22222222-2222-4222-8222-222222222222',tracking:{enabled:true,serverGA4:false}}, consent);
  assert.equal(w.dataLayer.filter(x => x[1] === 'generate_lead').length, 1);
  w.allTrackingConsent = {analytics:false,marketing:false};
  tracker.confirm({...response,eventId:'33333333-3333-4333-8333-333333333333'}, consent);
  assert.equal(w.fbq.queue.filter(x => x[1] === 'Lead').length, 2);
  d.window.close();
});

test('payload legge GA clientId reale solo dopo analytics, CMP tardiva e form dinamici', () => {
  const d = dom(), w = d.window;
  w.document.cookie = '_ga=GA1.1.12345.67890';
  const tracker = client.createTracking(w, {...config, cmp:'iubenda'});
  assert.equal(tracker.payload().clientId, undefined);
  w._iub = {cs:{api:{getPreferences:() => ({purposes:{4:true,5:false}})}}};
  w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(tracker.payload().clientId, '12345.67890');
  const form = w.document.createElement('form'); form.dataset.landingForm = ''; w.document.body.append(form);
  form.dispatchEvent(new w.Event('submit', {bubbles:true}));
  assert.equal(form.querySelector('[name="utm_source"]').value, 'test');
  d.window.close();
});

test('browser: zero tag/storage/hidden prima del consenso; grant separati e nessuna duplicazione', () => {
  assert.equal(typeof client.createTracking, 'function', 'manca il runtime browser');
  const d = dom(), w = d.window;
  const tracker = client.createTracking(w, config);
  assert.equal(w.document.scripts.length, 0);
  assert.equal(w.sessionStorage.length, 0);
  assert.equal(w.document.querySelectorAll('[data-attribution]').length, 0);
  w.allTrackingConsent = {analytics:true,marketing:false};
  w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(w.document.scripts.length, 1);
  assert.match(w.document.scripts[0].src, /googletagmanager.com\/gtag\/js/);
  assert.equal(w.document.querySelector('[name="fbclid"]'), null);
  assert.equal(w.document.querySelector('[name="utm_source"]').value, 'test');
  w.allTrackingConsent = {analytics:true,marketing:true};
  w.dispatchEvent(new w.Event('alltracking:consent'));
  w.dispatchEvent(new w.Event('alltracking:consent'));
  assert.equal(w.document.scripts.length, 2);
  assert.equal(w.document.querySelectorAll('[name="fbclid"]').length, 1);
  assert.equal(w.document.querySelector('[name="fbclid"]').value, 'CLICK');
  assert.match(tracker.payload().attribution.external_id, /^[0-9a-f-]{36}$/);
  assert.equal(JSON.stringify(w.dataLayer).includes('private@'), false);
  assert.equal(JSON.stringify(w.dataLayer).includes('fbclid='), false);
  d.window.close();
});
