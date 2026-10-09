/**
 * Test del confronto atteso/reale del widget di debug (assets/js/debug-bar.js).
 *
 * Esegui: node tests/debug-bar-tests.js
 *
 * Lo script viene eseguito con window/document simulati (nessuna dipendenza:
 * niente jsdom). Il pannello non viene costruito — il `document` finto non ha un
 * DOM — ma `window.atiDebugBarApi` resta disponibile: è la parte con logica, ed è
 * quella che i test verificano.
 *
 * Copre: corrispondenza esatta e per prefisso tra attese e cookie presenti,
 * classificazione dell'esito (cookie presente che non dovrebbe esserci = errore,
 * atteso e assente = avviso, "dipende" = informativo), individuazione dei cookie
 * visibili solo al server (HttpOnly) e conteggio dei problemi senza doppioni.
 *
 * La controparte PHP, che calcola le attese, è in tests/debug-bar-tests.php.
 */
const fs = require('fs');
const path = require('path');

const CODE = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'js', 'debug-bar.js'),
  'utf8'
);

let pass = 0, fail = 0;
function ok(cond, msg) {
  if (cond) { pass++; console.log('  PASS  ' + msg); }
  else { fail++; console.log('  FAIL  ' + msg); }
}
function section(t) { console.log('\n== ' + t + ' =='); }

/** Riga delle attese completa a partire dai soli campi rilevanti. */
function row(overrides) {
  return Object.assign({
    name: '_ga',
    match: 'equals',
    source: 'test',
    category: 'analytics',
    expected: 'no',
    why: 'test'
  }, overrides || {});
}

/**
 * Ambiente simulato.
 *
 * @param {object} opts cookies (nomi presenti nel browser), snapshot (override di
 *                      window.atiDebugBar), blocked (nomi che le regole bloccano),
 *                      noGuard (nessun motore di valutazione disponibile).
 */
function makeEnv(opts) {
  opts = opts || {};
  const cookies = opts.cookies || [];

  const documentStub = {
    readyState: 'complete',
    cookie: cookies.map(n => n + '=x').join('; '),
    getElementById() { return null; },
    addEventListener() {}
  };

  const windowStub = {
    atiDebugBar: Object.assign({
      version: 'test',
      mode: 'auto',
      consent: { marketing: false, analytics: false, preferences: false },
      guard: { mode: 'enforce', active: true, rules: 1, providers: [], custom_cookies: {} },
      cookies: [],
      expected: [],
      notices: [],
      tracking: {},
      ga4: {},
      labels: {},
      env: {},
      settings: 'https://example.com/wp-admin/'
    }, opts.snapshot || {}),
    addEventListener() {},
    setTimeout() {},
    console: { log() {}, error() {} }
  };

  if (!opts.noGuard) {
    const blocked = opts.blocked || [];
    windowStub.atiCookieGuardApi = {
      cookies() { return cookies.slice(); },
      consent() { return { necessary: true, marketing: false, analytics: false, preferences: false }; },
      evaluate(name) {
        return blocked.indexOf(name) !== -1
          ? { blocked: true, action: 'block_delete', reason: 'blocked', rule: 0, label: 'regola test', category: 'analytics' }
          : { blocked: false, action: '', reason: 'no_match', rule: null, label: '', category: '' };
      },
      readCookie(name) { return cookies.indexOf(name) !== -1 ? 'x' : null; },
      providers() { return []; },
      stats() { return { blocked: 0, deleted: 0, events: [] }; }
    };
  }

  const fn = new Function('window', 'document', CODE);
  fn(windowStub, documentStub);

  return { api: windowStub.atiDebugBarApi, window: windowStub };
}

// -------------------------------------------------------------------------
section('API disponibile anche senza DOM');

let env = makeEnv();
ok(!!env.api, 'atiDebugBarApi esposto anche se il pannello non può essere costruito');
ok(typeof env.api.compare === 'function', 'compare() disponibile');

// -------------------------------------------------------------------------
section('Lettura dei cookie presenti');

env = makeEnv({ cookies: ['_ga', '_ga_ABC123', 'wordpress_logged_in_x'] });
ok(env.api.cookies().length === 3, 'tre cookie letti dal motore del guard');

// Senza motore del guard i nomi si leggono comunque da document.cookie.
env = makeEnv({ cookies: ['_fbp', 'PHPSESSID'], noGuard: true });
ok(env.api.cookies().join(',') === '_fbp,PHPSESSID', 'fallback su document.cookie quando il guard non è disponibile');
ok(env.api.evaluate('_fbp') === null, 'senza motore nessun esito inventato (null)');
ok(env.api.consent() === null, 'senza motore nessun consenso live inventato (null)');

// -------------------------------------------------------------------------
section('Corrispondenza attese / cookie presenti');

env = makeEnv();
const present = ['_ga', '_ga_ABC123', '_fbp'];
ok(env.api.matching(row({ name: '_ga' }), present).length === 1, 'match esatto: solo _ga');
ok(env.api.matching(row({ name: '_ga_ABC123' }), present).length === 1, 'match esatto sul cookie di sessione');
ok(env.api.matching(row({ name: '_gid' }), present).length === 0, 'cookie assente: nessuna corrispondenza');

const prefix = env.api.matching(row({ name: '_ga_', match: 'prefix' }), present);
ok(prefix.length === 1 && prefix[0] === '_ga_ABC123', 'match per prefisso: _ga_ trova _ga_ABC123 e non _ga');

// -------------------------------------------------------------------------
section('Classificazione dell\'esito');

ok(env.api.compare(row({ name: '_ga', expected: 'no' }), present).level === 'error',
  'atteso "non deve esserci" ma presente -> errore');
ok(env.api.compare(row({ name: '_gid', expected: 'no' }), present).level === 'ok',
  'atteso "non deve esserci" e assente -> ok');
ok(env.api.compare(row({ name: '_ga', expected: 'yes' }), present).level === 'ok',
  'atteso "deve esserci" e presente -> ok');
ok(env.api.compare(row({ name: '_gid', expected: 'yes' }), present).level === 'warn',
  'atteso "deve esserci" ma assente -> avviso');
ok(env.api.compare(row({ name: '_ga', expected: 'maybe' }), present).level === 'info',
  '"dipende" con cookie presente -> informativo, non un problema');
ok(env.api.compare(row({ name: '_gid', expected: 'maybe' }), present).level === 'info',
  '"dipende" con cookie assente -> informativo');
ok(env.api.compare(row({ name: '_ga_', match: 'prefix', expected: 'no' }), present).found[0] === '_ga_ABC123',
  'l\'esito riporta il cookie realmente trovato dal prefisso');

// -------------------------------------------------------------------------
section('Cookie visibili solo al server (HttpOnly / terze parti)');

env = makeEnv({
  cookies: ['_ga'],
  snapshot: {
    cookies: [
      { name: '_ga', reason: 'blocked', blocked: true, delete: true, category: 'analytics', label: 'GA', rule: 0 },
      { name: 'wordpress_logged_in_abc', reason: 'allowlist', blocked: false, delete: false, category: '', label: '', rule: null },
      { name: 'PHPSESSID', reason: 'allowlist', blocked: false, delete: false, category: '', label: '', rule: null }
    ]
  }
});
const hidden = env.api.serverOnly(env.api.cookies());
ok(hidden.length === 2, 'i cookie della richiesta non leggibili da JavaScript vengono isolati');
ok(hidden.map(c => c.name).indexOf('_ga') === -1, 'un cookie leggibile da JavaScript non è "solo server"');

// -------------------------------------------------------------------------
section('Conteggio dei problemi');

// Nessuna attesa violata, nessun cookie bloccato, nessuna segnalazione.
env = makeEnv({ cookies: ['wordpress_logged_in_x'] });
ok(env.api.problems(env.api.cookies()) === 0, 'situazione pulita -> nessun problema');

// Lo stesso cookie violato dalle attese E bloccato dalle regole conta UNA volta.
env = makeEnv({
  cookies: ['_ga'],
  blocked: ['_ga'],
  snapshot: { expected: [row({ name: '_ga', expected: 'no' })] }
});
ok(env.api.problems(env.api.cookies()) === 1, 'attesa violata + regola che blocca sullo stesso cookie -> 1 problema, non 2');

// Cookie diversi -> problemi distinti.
env = makeEnv({
  cookies: ['_ga', '_fbp'],
  blocked: ['_fbp'],
  snapshot: { expected: [row({ name: '_ga', expected: 'no' })] }
});
ok(env.api.problems(env.api.cookies()) === 2, 'cookie diversi -> due problemi');

// Le segnalazioni di livello error del server si sommano.
env = makeEnv({
  cookies: [],
  snapshot: { notices: [{ level: 'error', text: 'x' }, { level: 'warn', text: 'y' }, { level: 'info', text: 'z' }] }
});
ok(env.api.problems(env.api.cookies()) === 1, 'solo le segnalazioni "error" contano come problemi');

// Un'attesa "deve esserci" non soddisfatta è un avviso, non un problema conteggiato.
env = makeEnv({
  cookies: [],
  snapshot: { expected: [row({ name: '_ga', expected: 'yes' })] }
});
ok(env.api.problems(env.api.cookies()) === 0, 'cookie atteso e assente -> avviso, non conteggiato tra i problemi');

// -------------------------------------------------------------------------
console.log('\n---------------------------------------');
console.log('RISULTATO: ' + pass + ' PASS / ' + fail + ' FAIL');
process.exit(fail > 0 ? 1 : 0);
