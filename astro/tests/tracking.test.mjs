import test from 'node:test';
import assert from 'node:assert/strict';

const tracking = await import('../src/tracking.ts');

test('attribution rifiuta consensi truthy non booleani anche al confine pubblico', () => {
  const input = {fbclid: 'click', utm_source: 'ads'};
  assert.deepEqual(tracking.sanitizeAttribution(input, {analytics: 'true', marketing: 'true'}), {});
  assert.deepEqual(tracking.sanitizeAttribution(input, null), {});
});

test('consenso esplicito separato, CMP e precedenza del diniego', () => {
  assert.equal(typeof tracking.readConsent, 'function');
  const {readConsent} = tracking;
  assert.deepEqual(readConsent('bridge', {}), {analytics: false, marketing: false});
  assert.deepEqual(readConsent('bridge', {allTrackingConsent: {analytics: 'true', marketing: true}}), {analytics: false, marketing: true});
  assert.deepEqual(readConsent('cookiebot', {Cookiebot: {consent: {statistics: true, marketing: false}}}), {analytics: true, marketing: false});
  assert.deepEqual(readConsent('iubenda', {_iub: {cs: {api: {getPreferences: () => ({consent: true, purposes: {4: true, 5: false}})}}}}), {analytics: true, marketing: false});
  assert.deepEqual(readConsent('iubenda', {_iub: {cs: {api: {getPreferences: () => ({consent: true})}}}}), {analytics: false, marketing: false});
  assert.deepEqual(readConsent('iubenda', {_iub: {cs: {api: {getPreferences: () => {throw Error('CMP');}}}}}), {analytics: false, marketing: false});
  assert.deepEqual(readConsent('bridge', {allTrackingConsent: {analytics: true, marketing: true}, navigator: {globalPrivacyControl: true}}), {analytics: true, marketing: true});
});

test('attribution solo dopo consenso, whitelist e limiti, mai PII o external_id da URL', () => {
  assert.equal(typeof tracking.collectAttribution, 'function');
  const {collectAttribution, sanitizeAttribution} = tracking;
  const url = 'https://site.test/?fbclid=CLICK&gclid=GOOGLE&gbraid=GB&wbraid=WB&msclkid=MS&ttclid=TT&twclid=TW&li_fat_id=LI&utm_source=search&utm_campaign=safe&cmp=campaign42&utm_custom=bounded&utm_email=person%40example.test&utm_token=SECRET_MARKER&external_id=untrusted&email=private';
  const cookies = '_fbc=fb.1.100.OLD; _fbp=fb.1.100.123; broken=%ZZ';
  assert.deepEqual(collectAttribution(url, cookies, {}, {analytics:false,marketing:false}, 1000), {});
  assert.deepEqual(collectAttribution(url, cookies, {}, {analytics:true,marketing:false}, 1000), {utm_source:'search',utm_campaign:'safe',cmp:'campaign42'});
  const a = collectAttribution(url, cookies, {utm_medium:'prior'}, {analytics:true,marketing:true}, 1000);
  for (const key of ['fbclid','gclid','fbc','fbp','gbraid','wbraid','msclkid','ttclid','twclid','li_fat_id','utm_source','utm_campaign','utm_medium','cmp']) assert.ok(a[key], key);
  for (const key of ['utm_custom','utm_email','utm_token']) assert.equal(a[key], undefined, key);
  assert.equal(a.fbc, 'fb.1.1000.CLICK');
  assert.equal(a.external_id, undefined);
  assert.equal(a.email, undefined);
  assert.deepEqual(sanitizeAttribution({utm_source:'x'.repeat(201),fbclid:'<script>',utm_bad:'a\nb',external_id:'not-uuid',unexpected:'x'}, {analytics:true,marketing:true}), {});
  assert.deepEqual(sanitizeAttribution(a, {analytics:false,marketing:false}), {});
});

test('configurazione fail-closed, whitelist pubblica e GTM esclusivo', () => {
  assert.equal(typeof tracking.normalizeConfig, 'function', 'manca la configurazione nativa');
  const {normalizeConfig} = tracking;
  assert.equal(normalizeConfig(null).enabled, false);
  assert.equal(normalizeConfig({enabled: 'true'}).enabled, false);
  const config = normalizeConfig({enabled: true, mode: 'gtm', gtmId: 'GTM-TEST123', ga4Id: 'G-TEST123', metaPixelId: '123456789', token: 'NEVER_PUBLIC', serverGA4: true});
  assert.equal(config.gtmId, 'GTM-TEST123');
  assert.equal(config.ga4Id, '');
  assert.equal(config.metaPixelId, '');
  assert.equal(config.serverGA4, false, 'GTM è proprietario degli eventi, niente GA4 duplicato');
  assert.equal(JSON.stringify(config).includes('NEVER_PUBLIC'), false);
  assert.equal(normalizeConfig({enabled: true, mode: 'direct', ga4Id: '<script>'}).ga4Id, '');
  assert.equal(normalizeConfig({enabled: true, mode: 'direct'}, true).enabled, false);
});
