/**
 * Test dell'external_id (pseudonimo `fst_uid`) e degli event_id end-to-end lato browser.
 *
 * Esegui: node tests/external-id-tests.js
 *
 * Esegue gli script REALI del plugin — i due script inline di
 * includes/tag-inserter.php (resi senza PHP, ramo WP_DEBUG spento) e
 * assets/js/form-fields.js — in un browser simulato: cookie jar, bus di eventi,
 * timer manuali e un `fetch` che fa da server. Il server simulato replica
 * fst_get_uid(): cookie presente -> lo usa; cookie assente + consenso -> genera
 * un id e lo scrive con Set-Cookie, applicato solo all'arrivo della risposta
 * (che il test pilota, per riprodurre le richieste in parallelo).
 *
 * Invarianti verificate: in una visita esiste UN SOLO external_id, identico su
 * server (CAPI/n8n), Pixel, cookie e campi hidden dei form; ogni evento ha il suo
 * event_id; un doppio click o un doppio invio involontario non produce due eventi.
 * Nessuna dipendenza.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const TAG_INSERTER = fs.readFileSync(path.join(ROOT, 'includes', 'tag-inserter.php'), 'utf8');
const FORM_FIELDS = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'form-fields.js'), 'utf8');
const COOKIE_GUARD = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'cookie-guard.js'), 'utf8');

// Configurazione del blocco cookie come la stampa il plugin: modalità enforce e la
// regola predefinita di fst_uid (categoria marketing, blocca e cancella).
const GUARD_CONFIG = {
  enabled: true, mode: 'enforce', expose: true, debug: false,
  rules: [{
    enabled: 1, label: 'Quick Tracking Integration — pseudonimo (fst_uid)', category: 'marketing',
    match: 'equals', value: 'fst_uid', value2: '', ci: 0, action: 'block_delete',
  }],
  allowlist: ['wordpress*', 'wp-*', 'PHPSESSID', 'cmplz_*', '_iub_cs-*', 'CookieConsent*', 'OptanonConsent', 'ati_*'],
  cmp: 'auto',
  customCookies: { marketing: '', analytics: '', preferences: '' },
  iubPurposes: { preferences: 3, analytics: 4, marketing: 5 },
  consentEvents: [], sweepInterval: 2000, sweepDuration: 0, v: 'test',
};

let pass = 0, fail = 0;
function ok(cond, msg) {
  if (cond) { pass++; console.log('  PASS  ' + msg); }
  else { fail++; console.log('  FAIL  ' + msg); }
}

// ---------------------------------------------------------------------------
// Resa degli script inline: toglie il PHP lasciando il JS di produzione.
// ---------------------------------------------------------------------------
function renderInlineScripts(opts) {
  const start = TAG_INSERTER.indexOf('function fst_inline_tracking_js()');
  let s = TAG_INSERTER.slice(start);
  s = s.replace(
    /<\?php if \( defined\( 'WP_DEBUG' \) && WP_DEBUG \) : \?>([\s\S]*?)<\?php endif; \?>/g,
    (m, body) => {
      const parts = body.split(/<\?php else : \?>/);
      return parts.length > 1 ? parts[1] : '';
    }
  );
  s = s.replace(/<\?php echo \$gtm_enabled \? 'true' : 'false'; \?>/g, opts.gtm ? 'true' : 'false');
  s = s.replace(/<\?php echo esc_js\( admin_url\('admin-ajax\.php'\) \); \?>/g, '/wp-admin/admin-ajax.php');
  s = s.replace(/<\?php echo esc_js\( home_url\( '\/wp-json\/fst\/v1\/event' \) \); \?>/g, '/wp-json/fst/v1/event');
  s = s.replace(/(window\.consentCookieName = ')<\?php[\s\S]*?\?>(')/, '$1$2');
  s = s.replace(/(const fstCustomSuccessEvent = ')<\?php[\s\S]*?\?>(')/, '$1$2');
  s = s.replace(/<\?php echo [\s\S]*?\?>/g, '0');
  s = s.replace(/<\?php[\s\S]*?\?>/g, ';');

  const scripts = [];
  const re = /<script[^>]*>([\s\S]*?)<\/script>/g;
  let m;
  while ((m = re.exec(s))) scripts.push(m[1]);
  if (scripts.length !== 2) throw new Error('attesi 2 script inline, trovati ' + scripts.length);
  return scripts;
}

// ---------------------------------------------------------------------------
// Browser + server simulati.
// ---------------------------------------------------------------------------
const CONSENT_COOKIE = 'cmplz_marketing';

function makeWorld(opts) {
  opts = opts || {};
  const jar = Object.assign({}, opts.cookies || {});
  if (opts.consent) jar[CONSENT_COOKIE] = 'allow';

  // Ogni valore mai assunto dal cookie fst_uid (browser o server), in ordine.
  const uidWrites = [];
  if (jar.fst_uid) uidWrites.push(jar.fst_uid);
  function setJar(name, value) {
    jar[name] = value;
    if ('fst_uid' === name) uidWrites.push(value);
  }

  function makeBus() {
    const map = {};
    return {
      map,
      addEventListener(type, fn) { (map[type] = map[type] || []).push(fn); },
      removeEventListener(type, fn) { map[type] = (map[type] || []).filter(f => f !== fn); },
      dispatchEvent(ev) { (map[ev.type] || []).slice().forEach(fn => fn(ev)); return true; },
    };
  }
  const docBus = makeBus();
  const winBus = makeBus();

  const timers = [];
  let timerId = 0;
  function addTimer(fn, delay, repeat) {
    timers.push({ id: ++timerId, fn, delay: delay || 0, repeat });
    return timerId;
  }
  function clearTimer(id) {
    const i = timers.findIndex(t => t.id === id);
    if (i !== -1) timers.splice(i, 1);
  }

  // Server: decide l'external_id all'ARRIVO della richiesta, con i cookie di quel momento.
  const requests = [];
  let serverSeq = 0;
  function fetchStub(url, init) {
    const cookiesAtSend = Object.assign({}, jar);
    const body = init && init.body;
    let kind = 'other', eventName = '', eventId = '', data = { success: false };
    if (typeof body === 'string') {
      const json = JSON.parse(body);
      kind = 'event'; eventName = json.type; eventId = json.eventID;
    } else if (body && typeof body.get === 'function') {
      const action = body.get('action');
      if ('fst_pageview' === action) { kind = 'event'; eventName = 'PageView'; eventId = body.get('event_id'); }
      else if ('ati_reload_facebook_pixel' === action) { kind = 'pixel'; data = { success: true, pixel_id: '123' }; }
      else if ('ati_load_tracking_tags' === action) { kind = 'tags'; }
    }

    const req = { kind, eventName, eventId, externalId: '', setCookie: '', done: false };
    if ('event' === kind) {
      const serverConsent = 'allow' === cookiesAtSend[CONSENT_COOKIE];
      if (cookiesAtSend.fst_uid) {
        req.externalId = cookiesAtSend.fst_uid;
      } else if (serverConsent) {
        req.externalId = 'server-' + (++serverSeq);
        req.setCookie = req.externalId;
      }
    }
    req.promise = new Promise(resolve => {
      req.respond = () => {
        if (req.done) return;
        req.done = true;
        if (req.setCookie) setJar('fst_uid', req.setCookie);
        resolve({ status: 200, text: async () => 'ok', json: async () => data });
      };
    });
    requests.push(req);
    return req.promise;
  }

  const fbqCalls = [];
  const inputs = opts.inputs || [];

  // Orologio pilotabile dal test (finestre anti doppione).
  let clockOffset = 0;
  class FakeDate extends Date {
    constructor(...args) {
      if (args.length) super(...args); else super(Date.now() + clockOffset);
    }
    static now() { return Date.now() + clockOffset; }
  }

  const document = Object.assign(docBus, {
    readyState: 'complete',
    title: 'Pagina di test',
    scripts: [],
    documentElement: { scrollHeight: 0 },
    head: {
      appendChild(el) {
        // Caricamento dinamico del Pixel: definisce fbq e chiama onload.
        if (el && el.src) {
          window.fbq = function () { fbqCalls.push([].slice.call(arguments)); };
          if (typeof el.onload === 'function') el.onload();
        }
      },
    },
    createElement() { return {}; },
    querySelectorAll() { return inputs; },
  });
  Object.defineProperty(document, 'cookie', {
    configurable: true, // come nel browser: il blocco cookie ne sostituisce il setter
    get() { return Object.keys(jar).map(k => k + '=' + jar[k]).join('; '); },
    set(v) {
      const eq = v.indexOf('=');
      const name = v.slice(0, eq);
      const rest = v.slice(eq + 1).split(';');
      const expired = /max-age=0|expires=Thu, 01 Jan 1970/i.test(v);
      if (expired) delete jar[name]; else setJar(name, rest[0]);
    },
  });

  const window = Object.assign(winBus, {
    document,
    location: {
      href: 'https://example.test/landing/', search: opts.search || '',
      hostname: 'example.test', pathname: '/landing/',
    },
    innerHeight: 800,
    scrollY: 0,
    dataLayer: [],
    atiFormFields: { enabled: true, debug: false, consentEvent: '', v: 'test' },
    sessionStorage: { getItem: () => null, setItem: () => {} },
    MutationObserver: null,
    fetch: fetchStub,
    setTimeout: (fn, delay) => addTimer(fn, delay, false),
    setInterval: (fn, delay) => addTimer(fn, delay, true),
    clearTimeout: clearTimer,
    clearInterval: clearTimer,
    URLSearchParams,
    Date: FakeDate,
    WeakMap,
    console: { log() {}, warn() {}, error() {}, group() {}, groupEnd() {} },
    CustomEvent: function (type, init) { this.type = type; this.detail = init && init.detail; },
    Event: function (type) { this.type = type; },
  });
  window.window = window;

  const world = {
    jar, requests, fbqCalls, uidWrites, window, document, inputs,

    boot() {
      const ctx = vm.createContext(window);
      if (opts.guard) {
        // Il blocco cookie è nell'<head>: gira prima di tutto il resto.
        window.atiCookieGuard = JSON.parse(JSON.stringify(GUARD_CONFIG));
        vm.runInContext(COOKIE_GUARD, ctx, { filename: 'cookie-guard.js' });
      }
      renderInlineScripts({ gtm: !!opts.gtm }).forEach((code, i) => {
        vm.runInContext(code, ctx, { filename: 'inline-script-' + (i + 1) + '.js' });
      });
      vm.runInContext(FORM_FIELDS, ctx, { filename: 'form-fields.js' });
      return world;
    },

    // Lascia girare le promise in sospeso (then/finally delle fetch).
    flush() { return new Promise(resolve => setImmediate(resolve)); },

    // Esegue i setTimeout(…, 0) pendenti (syncConsentAfterCmpUpdate).
    runImmediateTimers() {
      timers.filter(t => !t.repeat && 0 === t.delay).forEach(t => { clearTimer(t.id); t.fn(); });
    },

    // Un giro del polling del consenso (setInterval a 500 ms) e dei timer brevi.
    tick() {
      world.runImmediateTimers();
      timers.filter(t => t.repeat && t.delay <= 1000).forEach(t => t.fn());
      world.runImmediateTimers();
    },

    // Passate differite di form-fields (1,5 s e 4 s).
    runFieldTimers() {
      timers.filter(t => !t.repeat && t.delay >= 1000 && t.delay <= 4000)
        .forEach(t => { clearTimer(t.id); t.fn(); });
    },

    events() { return requests.filter(r => 'event' === r.kind); },
    pending(name) { return requests.filter(r => !r.done && (!name || r.eventName === name)); },

    async respond(filter) {
      requests.filter(r => !r.done && (!filter || filter(r))).forEach(r => r.respond());
      await world.flush();
      await world.flush();
    },

    // Evento che i CMP emettono a OGNI caricamento quando il consenso c'è già.
    cmpAnnouncesConsent() {
      document.dispatchEvent({ type: 'cmplz_enable_category', detail: { category: 'marketing' } });
      world.runImmediateTimers();
    },

    accept() { setJar(CONSENT_COOKIE, 'allow'); world.tick(); },

    // Revoca: il CMP toglie soltanto il proprio consenso, senza emettere eventi (come
    // iubenda). Nessuna passata del blocco cookie: fst_uid deve sparire comunque.
    revoke() { delete jar[CONSENT_COOKIE]; world.tick(); },

    // Fa avanzare l'orologio della pagina.
    advance(ms) { clockOffset += ms; },

    // Un pulsante della pagina: lo stesso oggetto per i click ripetuti sullo stesso elemento.
    button(label) {
      const btn = {
        id: label, type: 'button', textContent: label,
        getAttribute: () => 'button',
        closest: sel => (/form/.test(sel) && !/button/.test(sel) ? null : btn),
        classList: { contains: () => false },
        querySelector: () => null,
      };
      return btn;
    },

    // Click su un pulsante: un'etichetta crea ogni volta un elemento nuovo.
    clickButton(target) {
      const btn = 'string' === typeof target ? world.button(target) : target;
      document.dispatchEvent({ type: 'click', target: btn });
      return btn;
    },

    // Un form: `cssClass` decide se ha un evento di successo gestito (es. 'wpcf7-form').
    form(id, cssClass) {
      return {
        nodeName: 'FORM', tagName: 'FORM', id, action: '/invia',
        matches: sel => !!cssClass && sel.indexOf('.' + cssClass) !== -1,
        querySelector: () => null,
        querySelectorAll: () => [],
      };
    },

    submit(form) { document.dispatchEvent({ type: 'submit', target: form }); },

    named(name) { return world.events().filter(r => name === r.eventName); },

    // Tutti gli external_id non vuoti visti in qualsiasi punto della visita.
    allIds() {
      const ids = [];
      world.events().forEach(r => { if (r.externalId) ids.push(r.externalId); });
      fbqCalls.forEach(c => { if (c[2] && c[2].external_id) ids.push(c[2].external_id); });
      uidWrites.forEach(v => ids.push(v));
      inputs.forEach(i => (i.history || []).forEach(v => { if (v) ids.push(v); }));
      return Array.from(new Set(ids));
    },
  };
  return world;
}

// Campo hidden che ricorda ogni valore ricevuto.
function makeInput(name) {
  const store = { type: 'hidden', name };
  const input = {
    nodeName: 'INPUT', nodeType: 1, id: '', className: '', history: [],
    getAttribute(n) { return store[n] !== undefined ? store[n] : null; },
    setAttribute(n, v) { store[n] = v; },
    dispatchEvent() { return true; },
  };
  let value = '';
  Object.defineProperty(input, 'value', {
    get() { return value; },
    set(v) { value = v; input.history.push(v); },
  });
  return input;
}

(async function main() {
  console.log('\n== Consenso già presente, nessun fst_uid: il CMP segnala il consenso durante il PageView ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: true, inputs: [field] }).boot();
    // Il PageView è in viaggio (il server attende n8n) quando il CMP emette il suo evento.
    w.cmpAnnouncesConsent();
    w.clickButton('cta-1');
    await w.respond();
    w.clickButton('cta-2');
    await w.respond();
    w.runFieldTimers();
    const ids = w.allIds();
    ok(1 === ids.length, 'un solo external_id in tutta la visita -> ' + JSON.stringify(ids));
    ok(w.events().every(r => r.externalId === w.jar.fst_uid), 'ogni evento server usa l\'id del cookie');
    ok(field.value === w.jar.fst_uid, 'campo hidden uguale al cookie');
    ok(w.events().every(r => !r.setCookie), 'il server non genera id: lo riceve dal browser');
  }

  console.log('\n== Consenso già presente, nessun fst_uid: due richieste partono prima della risposta ==');
  {
    const w = makeWorld({ consent: true }).boot();
    w.clickButton('cta-veloce'); // PageView ancora senza risposta.
    const sent = w.events();
    ok(2 === sent.length, 'PageView e ButtonClick entrambi in viaggio');
    ok(sent[0].externalId && sent[0].externalId === sent[1].externalId,
      'stesso external_id sulle due richieste parallele -> ' + sent.map(r => r.externalId).join(' / '));
    await w.respond();
    ok(1 === w.allIds().length, 'un solo external_id dopo le risposte -> ' + JSON.stringify(w.allIds()));
  }

  console.log('\n== Stesso scenario con Pixel caricato: Pixel e server ricevono lo stesso id ==');
  {
    const w = makeWorld({ consent: true }).boot();
    w.cmpAnnouncesConsent(); // carica il Pixel (fbq)
    await w.respond(r => 'pixel' === r.kind);
    w.clickButton('cta-pixel');
    await w.respond();
    const pixelIds = w.fbqCalls.filter(c => c[2] && c[2].external_id).map(c => c[2].external_id);
    ok(pixelIds.length > 0, 'il Pixel ha ricevuto un external_id');
    ok(1 === w.allIds().length, 'Pixel, server, cookie: un solo id -> ' + JSON.stringify(w.allIds()));
  }

  console.log('\n== Modalità GTM, consenso già presente, nessun fst_uid ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: true, gtm: true, inputs: [field] }).boot();
    w.clickButton('cta-gtm');
    await w.respond();
    ok(1 === w.allIds().length, 'un solo external_id -> ' + JSON.stringify(w.allIds()));
    ok(field.value && field.value === w.jar.fst_uid, 'campo hidden compilato con lo stesso id');
  }

  console.log('\n== Visitatore di ritorno: il cookie esistente resta l\'unica fonte ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: true, cookies: { fst_uid: 'uid-esistente' }, inputs: [field] }).boot();
    w.cmpAnnouncesConsent();
    w.clickButton('cta');
    await w.respond();
    ok('["uid-esistente"]' === JSON.stringify(w.allIds()), 'nessun nuovo id generato');
    ok('uid-esistente' === field.value, 'campo hidden dal cookie esistente');
  }

  console.log('\n== Nessun consenso: nessun id da nessuna parte ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: false, inputs: [field] }).boot();
    w.clickButton('cta');
    await w.respond();
    w.runFieldTimers();
    ok(0 === w.allIds().length, 'nessun external_id generato o inviato');
    ok(!w.jar.fst_uid, 'nessun cookie fst_uid');
    ok('' === field.value, 'campo hidden vuoto');
    ok(!w.window._fstTempUid, 'nessun id nemmeno in memoria');
  }

  console.log('\n== Consenso dato dopo il caricamento ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: false, inputs: [field] }).boot();
    await w.respond();
    w.runFieldTimers(); // passate a 1,5 s e 4 s: ancora nessun consenso
    ok('' === field.value, 'prima di «Accetta» il campo è vuoto');
    w.accept();
    ok(!!w.jar.fst_uid, 'dopo «Accetta» fst_uid viene creato');
    ok(field.value === w.jar.fst_uid, 'campo hidden compilato subito, senza submit');
    w.clickButton('cta');
    await w.respond();
    ok(1 === w.allIds().length, 'un solo external_id -> ' + JSON.stringify(w.allIds()));
  }

  console.log('\n== Revoca nella stessa pagina, poi nuovo consenso ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: true, inputs: [field] }).boot();
    await w.respond();
    const first = w.jar.fst_uid;
    ok(!!first && field.value === first, 'con consenso il campo ha l\'id');
    w.revoke();
    ok(!w.jar.fst_uid, 'dopo la revoca il cookie fst_uid viene cancellato dal plugin');
    ok('' === field.value, 'dopo la revoca il campo hidden viene svuotato');
    ok(!w.window._fstTempUid, 'dopo la revoca l\'id non resta in memoria');
    w.clickButton('cta-senza-consenso');
    const noConsent = w.events().pop();
    ok('' === noConsent.externalId, 'evento dopo la revoca senza external_id');
    await w.respond();
    w.accept();
    ok(!!w.jar.fst_uid && w.jar.fst_uid !== first, 'nuovo consenso -> nuovo id, non quello revocato');
    ok(field.value === w.jar.fst_uid, 'campo hidden aggiornato al nuovo id');
  }

  console.log('\n== Con il blocco cookie reale (enforce): consenso già presente, nessun fst_uid ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: true, guard: true, inputs: [field] }).boot();
    ok(!!w.jar.fst_uid, 'la scrittura di fst_uid al caricamento non viene bloccata');
    w.cmpAnnouncesConsent();
    w.clickButton('cta');
    await w.respond();
    ok(1 === w.allIds().length, 'un solo external_id -> ' + JSON.stringify(w.allIds()));
    ok(0 === w.window.atiCookieGuardApi.stats().blocked, 'nessuna scrittura bloccata dal guard');
    w.revoke();
    ok(!w.jar.fst_uid, 'revoca: fst_uid cancellato anche senza una passata del guard');
    ok('' === field.value, 'revoca: campo hidden svuotato');
    w.accept();
    ok(!!w.jar.fst_uid && field.value === w.jar.fst_uid, 'nuovo consenso: nuovo id nel cookie e nel campo');
    ok(2 === w.allIds().length, 'in tutto due id: uno prima della revoca, uno dopo');
  }

  console.log('\n== Con il blocco cookie reale: fst_uid rimasto da un consenso scaduto ==');
  {
    const field = makeInput('external_id');
    const w = makeWorld({ consent: false, guard: true, cookies: { fst_uid: 'uid-vecchio' }, inputs: [field] }).boot();
    w.clickButton('cta');
    await w.respond();
    w.runFieldTimers();
    ok(!w.jar.fst_uid, 'il guard cancella il vecchio fst_uid prima di qualsiasi richiesta');
    ok(w.events().every(r => '' === r.externalId), 'nessun evento porta il vecchio id');
    ok('' === field.value, 'campo hidden vuoto');
  }

  console.log('\n== event_id: ogni evento ha il suo, anche se inviati a raffica ==');
  {
    const w = makeWorld({ consent: true }).boot();
    await w.respond();
    w.clickButton('tab-1');
    w.clickButton('tab-2'); // prima della risposta del primo
    const clicks = w.events().filter(r => 'ButtonClick' === r.eventName);
    ok(2 === clicks.length && clicks[0].eventId !== clicks[1].eventId,
      'due ButtonClick ravvicinati hanno event_id diversi -> ' + clicks.map(r => r.eventId).join(' / '));
    const pageView = w.events().find(r => 'PageView' === r.eventName);
    ok(clicks.every(r => r.eventId !== pageView.eventId), 'event_id del PageView non riusato');
  }

  console.log('\n== PageView al Pixel: una sola volta, anche con il Pixel caricato dopo ==');
  {
    const w = makeWorld({ consent: true }).boot();
    w.cmpAnnouncesConsent(); // il CMP fa caricare il Pixel: al load parte il PageView
    await w.respond();
    w.tick(); // il tentativo periodico (ogni secondo) trova fbq già caricato
    w.tick();
    const pageViews = w.fbqCalls.filter(c => 'PageView' === c[1]);
    ok(1 === pageViews.length, 'un solo PageView inviato al Pixel -> ' + pageViews.length);
    const server = w.named('PageView')[0];
    ok(pageViews[0] && pageViews[0][3].eventID === server.eventId, 'stesso event_id di quello inviato al server');
  }

  console.log('\n== Doppio click involontario sullo stesso pulsante ==');
  {
    const w = makeWorld({ consent: true }).boot();
    w.cmpAnnouncesConsent(); // carica il Pixel
    await w.respond();
    const cta = w.button('cta-principale');
    w.clickButton(cta);
    w.advance(180);
    w.clickButton(cta); // secondo click del doppio click
    ok(1 === w.named('ButtonClick').length, 'un solo ButtonClick inviato al server');
    ok(1 === w.fbqCalls.filter(c => 'ButtonClick' === c[1]).length, 'un solo ButtonClick inviato al Pixel');
    w.advance(3000);
    w.clickButton(cta);
    ok(2 === w.named('ButtonClick').length, 'un nuovo click dopo qualche secondo viene tracciato');
    w.clickButton(w.button('altro-pulsante'));
    ok(3 === w.named('ButtonClick').length, 'il click immediato su un ALTRO pulsante viene tracciato');
    const ids = w.named('ButtonClick').map(r => r.eventId);
    ok(3 === new Set(ids).size, 'ogni ButtonClick inviato ha il suo event_id');
  }

  console.log('\n== Doppio invio dello stesso form (Lead al submit) ==');
  {
    const w = makeWorld({ consent: true }).boot();
    w.cmpAnnouncesConsent();
    await w.respond();
    const form = w.form('contatti', '');
    w.submit(form);
    w.advance(250);
    w.submit(form); // doppio click su «Invia»
    ok(1 === w.named('Lead').length, 'un solo Lead inviato al server');
    ok(1 === w.fbqCalls.filter(c => 'Lead' === c[1]).length, 'un solo Lead inviato al Pixel');
    w.advance(10000);
    w.submit(form);
    ok(2 === w.named('Lead').length, 'un nuovo invio dopo 10 secondi è un nuovo Lead');
  }

  console.log('\n== Form con conferma di successo: più segnali per lo stesso invio ==');
  {
    const w = makeWorld({ consent: true }).boot();
    await w.respond();
    const form = w.form('cf7', 'wpcf7-form');
    w.submit(form);
    w.submit(form);
    ok(0 === w.named('Lead').length, 'nessun Lead finché il provider non conferma');
    w.document.dispatchEvent({ type: 'wpcf7mailsent', target: form });
    w.advance(300);
    w.document.dispatchEvent({ type: 'wpcf7mailsent', target: form });
    ok(1 === w.named('Lead').length, 'due conferme ravvicinate: un solo Lead');
  }

  console.log('\n== Eventi una tantum: FormStart una volta per form ==');
  {
    const w = makeWorld({ consent: true }).boot();
    await w.respond();
    const form = w.form('contatti', '');
    const field = { closest: sel => ('form' === sel ? form : null) };
    w.document.dispatchEvent({ type: 'focusin', target: field });
    w.document.dispatchEvent({ type: 'focusin', target: field });
    ok(1 === w.named('FormStart').length, 'FormStart inviato una sola volta');
  }

  console.log('\n---------------------------------------');
  console.log('PASS: ' + pass + '  FAIL: ' + fail);
  process.exit(fail ? 1 : 0);
})().catch(err => {
  console.error(err);
  process.exit(1);
});
