/**
 * Test della compilazione automatica dei campi hidden (assets/js/form-fields.js).
 *
 * Esegui: node tests/form-fields-tests.js
 *
 * Lo script viene eseguito con window/document simulati (nessuna dipendenza:
 * niente jsdom). Copre: risoluzione dei valori dalle varie sorgenti, formato di
 * `fbc`, divieto di inventare `fbp`, varianti di naming dei campi, campi estranei
 * e valori preesistenti, persistenza tra pagine, gating del consenso, refill al
 * submit e toggle di disattivazione.
 */
const fs = require('fs');
const path = require('path');

const CODE = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'js', 'form-fields.js'),
  'utf8'
);

let pass = 0, fail = 0;
function ok(cond, msg) {
  if (cond) { pass++; console.log('  PASS  ' + msg); }
  else { fail++; console.log('  FAIL  ' + msg); }
}

function makeInput(attrs) {
  const store = Object.assign({ type: 'hidden' }, attrs);
  return {
    nodeName: 'INPUT',
    nodeType: 1,
    value: attrs.value || '',
    id: attrs.id || '',
    className: attrs.class || '',
    getAttribute(n) { return store[n] !== undefined ? store[n] : null; },
    setAttribute(n, v) { store[n] = v; },
    dispatchEvent() { return true; },
  };
}

function run(scenario) {
  const inputs = scenario.inputs;
  const cookies = scenario.cookies || {};
  const listeners = {};
  const sessionData = {};

  const documentStub = {
    readyState: 'complete',
    documentElement: {},
    get cookie() {
      return Object.keys(cookies).map(k => k + '=' + cookies[k]).join('; ');
    },
    set cookie(v) {
      const eq = v.indexOf('=');
      cookies[v.slice(0, eq)] = v.slice(eq + 1).split(';')[0];
    },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    querySelectorAll() { return inputs; },
  };

  const windowStub = {
    atiFormFields: { enabled: true, debug: false, consentEvent: '', v: 'test' },
    location: { search: scenario.search || '' },
    marketingConsent: !!scenario.consent,
    sessionStorage: {
      getItem: k => (sessionData[k] === undefined ? null : sessionData[k]),
      setItem: (k, v) => { sessionData[k] = v; },
    },
    MutationObserver: null,
    console,
  };

  const fn = new Function('window', 'document', 'setTimeout', 'URLSearchParams', 'Event', CODE);
  fn(windowStub, documentStub, () => {}, URLSearchParams, function () {});
  return { listeners, cookies, sessionData, documentStub };
}

console.log('\n== Compilazione base: fbclid/gclid da URL, fbc costruito, fbp da cookie ==');
{
  const fields = {
    fbclid: makeInput({ name: 'fbclid' }),
    gclid: makeInput({ name: 'gclid' }),
    fbc: makeInput({ name: 'fbc' }),
    fbp: makeInput({ name: 'fbp' }),
  };
  run({
    inputs: Object.values(fields),
    search: '?fbclid=ABC123&gclid=XYZ789&utm_source=meta',
    cookies: { _fbp: 'fb.1.1700000000.9988776655' },
  });
  ok(fields.fbclid.value === 'ABC123', 'fbclid compilato da URL');
  ok(fields.gclid.value === 'XYZ789', 'gclid compilato da URL');
  ok(/^fb\.1\.\d+\.ABC123$/.test(fields.fbc.value), 'fbc costruito come fb.1.<ts>.<fbclid> -> ' + fields.fbc.value);
  ok(fields.fbp.value === 'fb.1.1700000000.9988776655', 'fbp preso dal cookie _fbp');
}

console.log('\n== fbp NON viene mai inventato ==');
{
  const fbp = makeInput({ name: 'fbp' });
  run({ inputs: [fbp], search: '?fbclid=ABC123', cookies: {} });
  ok(fbp.value === '', 'fbp resta vuoto senza cookie _fbp');
}

console.log('\n== Cookie _fbc reale ha precedenza sul valore costruito ==');
{
  const fbc = makeInput({ name: 'fbc' });
  const fbclid = makeInput({ name: 'fbclid' });
  run({ inputs: [fbc, fbclid], search: '', cookies: { _fbc: 'fb.1.1699999999.COOKIEID' } });
  ok(fbc.value === 'fb.1.1699999999.COOKIEID', 'fbc dal cookie _fbc');
  ok(fbclid.value === 'COOKIEID', 'fbclid estratto dalla coda di _fbc');
}

console.log('\n== gclid dal cookie _gcl_aw quando manca in URL ==');
{
  const gclid = makeInput({ name: 'gclid' });
  run({ inputs: [gclid], search: '', cookies: { _gcl_aw: 'GCL.1700000000.CjwKCAtest' } });
  ok(gclid.value === 'CjwKCAtest', 'gclid estratto da _gcl_aw');
}

console.log('\n== Varianti di naming: Elementor, id, classe, data-attr, utm, external_id ==');
{
  const el = makeInput({ name: 'form_fields[fbclid]' });
  const byId = makeInput({ name: 'unrelated_1', id: 'form-field-gclid' });
  const byClass = makeInput({ name: 'wpforms[fields][7]', class: 'wpf-hidden ati-field-fbc' });
  const byData = makeInput({ name: 'input_12', 'data-ati-field': 'utm_source' });
  const extId = makeInput({ name: 'external_id' });
  run({
    inputs: [el, byId, byClass, byData, extId],
    search: '?fbclid=EL1&gclid=EL2&utm_source=newsletter',
    cookies: { fst_uid: 'uid-123-abc' },
  });
  ok(el.value === 'EL1', 'name Elementor form_fields[fbclid]');
  ok(byId.value === 'EL2', 'id form-field-gclid');
  ok(/^fb\.1\.\d+\.EL1$/.test(byClass.value), 'classe ati-field-fbc');
  ok(byData.value === 'newsletter', 'data-ati-field="utm_source"');
  ok(extId.value === 'uid-123-abc', 'external_id dal cookie fst_uid');
}

console.log('\n== Campi non riconosciuti e valori esistenti ==');
{
  const nonce = makeInput({ name: '_wpnonce', value: 'abc123' });
  const other = makeInput({ name: 'redirect_to', value: '/grazie' });
  const preset = makeInput({ name: 'gclid', value: 'IMPOSTATO-DAL-SITO' });
  run({ inputs: [nonce, other, preset], search: '?gclid=NUOVO', cookies: {} });
  ok(nonce.value === 'abc123', '_wpnonce non toccato');
  ok(other.value === '/grazie', 'campo hidden estraneo non toccato');
  ok(preset.value === 'IMPOSTATO-DAL-SITO', 'valore preesistente non sovrascritto');
}

console.log('\n== Persistenza: click id disponibile nelle pagine successive ==');
{
  const first = run({ inputs: [], search: '?gclid=PERSIST1&fbclid=PERSIST2', consent: true });
  const sessionSeed = first.sessionData['fst_clid'];
  ok(!!sessionSeed && sessionSeed.indexOf('PERSIST1') !== -1, 'click id salvati in sessionStorage');
  ok(!!first.cookies['fst_clid'], 'cookie fst_clid scritto con consenso marketing');

  // Seconda pagina: nessun parametro in URL, ma il cookie persiste.
  const gclid = makeInput({ name: 'gclid' });
  const fbc = makeInput({ name: 'fbc' });
  run({ inputs: [gclid, fbc], search: '', cookies: { fst_clid: first.cookies['fst_clid'] } });
  ok(gclid.value === 'PERSIST1', 'gclid recuperato dalla persistenza in pagina 2');
  ok(/^fb\.1\.\d+\.PERSIST2$/.test(fbc.value), 'fbc ricostruito dalla persistenza in pagina 2');
}

console.log('\n== Senza consenso marketing: niente cookie di persistenza ==');
{
  const r = run({ inputs: [], search: '?gclid=NOCONSENT', consent: false });
  ok(!r.cookies['fst_clid'], 'nessun cookie fst_clid senza consenso');
  ok(!!r.sessionData['fst_clid'], 'sessionStorage comunque valorizzato');
}

console.log('\n== Refill in capture sul submit (cookie comparso dopo il consenso) ==');
{
  const fbp = makeInput({ name: 'fbp' });
  const cookies = {};
  const r = run({ inputs: [fbp], search: '', cookies });
  ok(fbp.value === '', 'al load fbp vuoto (nessun cookie)');
  cookies._fbp = 'fb.1.1700000001.5566778899';
  const form = { nodeName: 'FORM', querySelectorAll: () => [fbp] };
  r.listeners.submit[0]({ target: form });
  ok(fbp.value === 'fb.1.1700000001.5566778899', 'fbp compilato al submit dopo il consenso');
}

console.log('\n== Toggle disattivato ==');
{
  const fbclid = makeInput({ name: 'fbclid' });
  const fn = new Function('window', 'document', 'setTimeout', 'URLSearchParams', 'Event', CODE);
  fn(
    { atiFormFields: { enabled: false }, location: { search: '?fbclid=NO' } },
    { readyState: 'complete', cookie: '', addEventListener() {}, querySelectorAll: () => [fbclid] },
    () => {}, URLSearchParams, function () {}
  );
  ok(fbclid.value === '', 'nessuna compilazione quando enabled=false');
}

console.log('\n---------------------------------------');
console.log('PASS: ' + pass + '  FAIL: ' + fail);
process.exit(fail ? 1 : 0);
