/**
 * Test del blocco cookie lato browser (assets/js/cookie-guard.js).
 *
 * Esegui: node tests/cookie-guard-tests.js
 *
 * Lo script viene eseguito con window/document simulati (nessuna dipendenza:
 * niente jsdom). Il `document` finto espone `cookie` come property accessor
 * configurabile, esattamente come il browser, così il guard può sostituirne il
 * setter e i test possono osservare cosa viene realmente scritto.
 *
 * Copre: blocco in scrittura per categoria, sblocco al concedere il consenso,
 * allowlist, cancellazioni mai bloccate, passata di pulizia, modalità monitor/off,
 * rilevamento del consenso dai CMP, parsing degli attributi e parità della logica
 * di matching con la controparte PHP (tests/cookie-guard-tests.php).
 */
const fs = require('fs');
const path = require('path');

const CODE = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'js', 'cookie-guard.js'),
  'utf8'
);

let pass = 0, fail = 0;
function ok(cond, msg) {
  if (cond) { pass++; console.log('  PASS  ' + msg); }
  else { fail++; console.log('  FAIL  ' + msg); }
}
function section(t) { console.log('\n== ' + t + ' =='); }

/** Regola completa a partire dai soli campi rilevanti. */
function rule(overrides) {
  return Object.assign({
    enabled: 1,
    label: 'test',
    category: 'marketing',
    match: 'starts_with',
    value: '',
    value2: '',
    ci: 0,
    action: 'block_delete',
  }, overrides || {});
}

/**
 * Ambiente simulato. `store` è lo stato reale dei cookie del "browser":
 * le scritture bloccate dal guard non devono mai arrivarci.
 */
function makeEnv(options) {
  const opts = options || {};
  const store = Object.assign({}, opts.cookies || {});
  const timers = { intervals: [], timeouts: [] };
  const listeners = {};

  function applyWrite(raw) {
    const parts = String(raw).split(';');
    const first = parts[0] || '';
    const eq = first.indexOf('=');
    const name = (eq === -1 ? first : first.slice(0, eq)).trim();
    const value = eq === -1 ? '' : first.slice(eq + 1);
    let expiring = false;

    for (let i = 1; i < parts.length; i++) {
      const seg = parts[i];
      const j = seg.indexOf('=');
      const key = (j === -1 ? seg : seg.slice(0, j)).trim().toLowerCase();
      const val = j === -1 ? '' : seg.slice(j + 1).trim();
      if (key === 'max-age' && parseInt(val, 10) <= 0) expiring = true;
      if (key === 'expires' && Date.parse(val) <= Date.now()) expiring = true;
    }
    if (!name) return;
    if (expiring) delete store[name];
    else store[name] = value;
  }

  const documentStub = {
    readyState: 'complete',
    get cookie() {
      return Object.keys(store).map(k => k + '=' + store[k]).join('; ');
    },
    set cookie(v) { applyWrite(v); },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  };

  const windowStub = {
    atiCookieGuard: Object.assign({
      enabled: true,
      mode: 'enforce',
      expose: true,
      debug: false,
      rules: [],
      allowlist: ['wordpress*', 'wp-*', 'PHPSESSID', 'cmplz_*', '_iub_cs-*', 'CookieConsent*', 'OptanonConsent', 'fst_*'],
      cmp: 'auto',
      customCookies: { marketing: '', analytics: '', preferences: '' },
      iubPurposes: { preferences: 3, analytics: 4, marketing: 5 },
      consentEvents: [],
      sweepInterval: 2000,
      sweepDuration: 0,
      v: 'test',
    }, opts.config || {}),
    location: { hostname: 'www.example.com', pathname: '/' },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    setInterval(fn, ms) { timers.intervals.push({ fn, ms }); return timers.intervals.length; },
    clearInterval() {},
    setTimeout(fn, ms) { timers.timeouts.push({ fn, ms }); return timers.timeouts.length; },
    console: opts.quiet === false ? console : { log() {} },
  };

  const fn = new Function('window', 'document', CODE);
  fn(windowStub, documentStub);

  return {
    store,
    timers,
    listeners,
    window: windowStub,
    document: documentStub,
    api: windowStub.atiCookieGuardApi,
    /** Scrive un cookie come farebbe un pixel (passa dal setter del guard). */
    write(v) { documentStub.cookie = v; },
    has(name) { return Object.prototype.hasOwnProperty.call(store, name); },
  };
}

// -------------------------------------------------------------------------
section('Blocco in scrittura senza consenso');
{
  const env = makeEnv({ config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] } });
  env.write('_ga_ABC123=GS1.1.123; path=/');
  ok(!env.has('_ga_ABC123'), 'cookie analytics non scritto senza consenso');

  env.write('sessione_sito=xyz; path=/');
  ok(env.store.sessione_sito === 'xyz', 'cookie non coperto da regole scritto normalmente');
}

// -------------------------------------------------------------------------
section('Il consenso sblocca la scrittura (senza ricaricare)');
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] },
    cookies: { cmplz_statistics: 'allow' },
  });
  env.write('_ga=GA1.1.1.1');
  ok(env.store._ga === 'GA1.1.1.1', 'con cmplz_statistics=allow il cookie analytics passa');
}
{
  const env = makeEnv({ config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] } });
  env.write('_ga=bloccato');
  ok(!env.has('_ga'), 'prima del consenso: bloccato');

  // L'utente accetta: il cookie del CMP compare e il guard rivaluta al volo.
  env.store.cmplz_statistics = 'allow';
  env.api.consent();
  env.write('_ga=consentito');
  ok(env.store._ga === 'consentito', 'dopo il consenso: consentito, senza ricaricare la pagina');
}

// -------------------------------------------------------------------------
section('Separazione delle categorie');
{
  const rules = [
    rule({ label: 'GA', category: 'analytics', match: 'wildcard', value: '_ga*' }),
    rule({ label: 'Meta', category: 'marketing', match: 'equals', value: '_fbp' }),
  ];
  const env = makeEnv({ config: { rules }, cookies: { cmplz_marketing: 'allow' } });
  env.write('_fbp=fb.1.1.1');
  env.write('_ga=GA1.1');
  ok(env.store._fbp === 'fb.1.1.1', 'consenso marketing -> _fbp scritto');
  ok(!env.has('_ga'), 'consenso marketing NON sblocca analytics');
}

// -------------------------------------------------------------------------
section('Categoria "always" (blacklist)');
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'always', match: 'equals', value: 'spia' })] },
    cookies: { cmplz_marketing: 'allow', cmplz_statistics: 'allow', cmplz_preferences: 'allow' },
  });
  env.write('spia=1');
  ok(!env.has('spia'), 'bloccato anche con tutti i consensi concessi');
}

// -------------------------------------------------------------------------
section('Allowlist: i cookie di sistema non vengono mai toccati');
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'always', match: 'contains', value: 'o' })] },
    cookies: { wordpress_logged_in_abc: 'sessione', PHPSESSID: 'abc', cmplz_marketing: 'deny', tracker_o: 'x' },
  });
  env.api.sweep();
  ok(env.has('wordpress_logged_in_abc'), 'sessione WordPress intatta');
  ok(env.has('PHPSESSID'), 'sessione PHP intatta');
  ok(env.has('cmplz_marketing'), 'scelta di consenso del CMP intatta');
  ok(!env.has('tracker_o'), 'il cookie non protetto viene invece cancellato');

  env.write('wp-settings-1=abc');
  ok(env.store['wp-settings-1'] === 'abc', 'scrittura di un cookie in allowlist consentita');
}

// -------------------------------------------------------------------------
section('Le cancellazioni non vengono MAI bloccate');
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'starts_with', value: '_ga' })] },
    cookies: { _ga: 'presente' },
  });
  env.write('_ga=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/');
  ok(!env.has('_ga'), 'cancellazione con expires nel passato eseguita');
}
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'starts_with', value: '_ga' })] },
    cookies: { _ga: 'presente' },
  });
  env.write('_ga=; max-age=0; path=/');
  ok(!env.has('_ga'), 'cancellazione con max-age=0 eseguita');
}
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'starts_with', value: '_ga' })] },
  });
  env.write('_ga=valore; expires=Fri, 01 Jan 2100 00:00:00 GMT');
  ok(!env.has('_ga'), 'una scadenza futura NON è una cancellazione: resta bloccata');
}

// -------------------------------------------------------------------------
section('Passata di pulizia sui cookie già presenti');
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] },
    cookies: { _ga: 'GA1.1', _ga_ABC: 'GS1.1', _fbp: 'fb.1', altro: 'ok' },
  });
  // La passata iniziale parte all'avvio del guard.
  ok(!env.has('_ga') && !env.has('_ga_ABC'), 'cookie analytics preesistenti cancellati all\'avvio');
  ok(env.has('_fbp') && env.has('altro'), 'cookie non coperti da regole non toccati');
}
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*', action: 'block' })] },
    cookies: { _ga: 'GA1.1' },
  });
  ok(env.has('_ga'), 'action=block: il cookie preesistente NON viene cancellato');
  env.write('_ga_NEW=x');
  ok(!env.has('_ga_NEW'), 'action=block: la nuova scrittura è comunque bloccata');
}
{
  const env = makeEnv({
    config: { rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*', action: 'delete' })] },
    cookies: { _ga: 'GA1.1' },
  });
  ok(!env.has('_ga'), 'action=delete: il cookie preesistente viene cancellato');
  env.write('_ga_NEW=x');
  ok(env.store._ga_NEW === 'x', 'action=delete: la scrittura non viene bloccata');
}

// -------------------------------------------------------------------------
section('Cookie reali con le regole predefinite');
{
  // Nomi presi da un browser reale. Le regole sono quelle predefinite del plugin
  // per Google Analytics e Meta (vedi ATI_Cookie_Rules::default_rules()).
  const rules = [
    rule({ label: 'Google Analytics', category: 'analytics', match: 'wildcard', value: '_ga*' }),
    rule({ label: 'Meta Pixel (browser id)', category: 'marketing', match: 'equals', value: '_fbp' }),
    rule({ label: 'Meta Pixel (click id)', category: 'marketing', match: 'equals', value: '_fbc' }),
  ];
  const cookies = {
    _fbp: 'fb.1.1785335293927.317406226',
    _ga: 'GA1.2.1218859942.1775642418',
    _ga_0RVDVFM24W: 'GS2.1.s1785337982$o2$g0$t1785337982$j60$l0$h0',
    _ga_MX6Z1X7L8K: 'GS2.1.s1785337982$o2$g0$t1785337982$j60$l0$h0',
  };

  const env = makeEnv({ config: { rules }, cookies: Object.assign({}, cookies) });
  ok(Object.keys(cookies).every(n => !env.has(n)), 'senza consenso: _fbp, _ga, _ga_0RVDVFM24W, _ga_MX6Z1X7L8K cancellati');

  // Il pixel riprova a scriverli: la scrittura viene scartata.
  env.write('_ga=GA1.2.1218859942.1775642418; domain=.milanoviainganni.it; path=/');
  env.write('_fbp=fb.1.1785335293927.317406226; domain=.milanoviainganni.it; path=/');
  ok(!env.has('_ga') && !env.has('_fbp'), 'senza consenso: nuove scritture bloccate');

  // Con i consensi concessi tutto torna a funzionare come prima.
  const consented = makeEnv({
    config: { rules },
    cookies: { cmplz_statistics: 'allow', cmplz_marketing: 'allow' },
  });
  consented.write('_ga=GA1.2.1218859942.1775642418');
  consented.write('_ga_0RVDVFM24W=GS2.1.s1785337982');
  consented.write('_fbp=fb.1.1785335293927.317406226');
  ok(consented.has('_ga') && consented.has('_ga_0RVDVFM24W') && consented.has('_fbp'),
    'con consenso analytics + marketing: tutti scritti normalmente');

  // Consenso parziale: analytics sì, marketing no.
  const partial = makeEnv({ config: { rules }, cookies: { cmplz_statistics: 'allow' } });
  partial.write('_ga=GA1.2.1');
  partial.write('_fbp=fb.1.1');
  ok(partial.has('_ga') && !partial.has('_fbp'), 'solo consenso analytics: _ga passa, _fbp resta bloccato');
}

// -------------------------------------------------------------------------
section('Modalità monitor e off');
{
  const env = makeEnv({
    config: { mode: 'monitor', rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] },
    cookies: { _ga: 'presente' },
  });
  env.write('_ga_NEW=x');
  ok(env.has('_ga'), 'monitor: nessuna cancellazione');
  ok(env.store._ga_NEW === 'x', 'monitor: nessun blocco in scrittura');
  const events = env.api.stats().events;
  ok(events.some(e => e.kind === 'would-delete'), 'monitor: la cancellazione mancata viene registrata');
  ok(events.some(e => e.kind === 'would-block'), 'monitor: il blocco mancato viene registrato');
}
{
  const env = makeEnv({
    config: { mode: 'off', rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] },
    cookies: { _ga: 'presente' },
  });
  env.write('_ga_NEW=x');
  ok(env.has('_ga') && env.store._ga_NEW === 'x', 'off: nessun intervento');
  ok(!!env.api, 'off: API di anteprima comunque esposta (pannello admin)');
  ok(env.api.evaluate('_ga_ABC', '').blocked, 'off: la valutazione funziona lo stesso');
}

// -------------------------------------------------------------------------
section('Parsing degli attributi della scrittura');
{
  const env = makeEnv({ config: { rules: [rule({ match: 'domain', value: 'doubleclick.net' })] } });
  env.write('IDE=xyz; domain=.doubleclick.net; path=/');
  ok(!env.has('IDE'), 'regola per dominio: scrittura bloccata');
  env.write('IDE2=xyz; domain=.example.com; path=/');
  ok(env.store.IDE2 === 'xyz', 'regola per dominio: altro dominio non toccato');
}
{
  const env = makeEnv({ config: { rules: [rule({ match: 'equals', value: 'nome con spazi' })] } });
  env.write('  nome con spazi = valore ; path=/');
  ok(!env.has('nome con spazi'), 'nome con spazi attorno: trim corretto prima del match');
}
{
  // Alcune librerie url-encodano il nome: entrambe le forme devono essere valutate.
  const env = makeEnv({ config: { rules: [rule({ match: 'equals', value: '_ga:test' })] } });
  env.write('_ga%3Atest=1');
  ok(!env.has('_ga%3Atest'), 'nome url-encodato riconosciuto e bloccato');
}
{
  const env = makeEnv({ config: { rules: [rule({ match: 'starts_with', value: '_ga' })] } });
  env.write('_ga');
  ok(!env.has('_ga'), 'scrittura senza "=" gestita senza errori');
}

// -------------------------------------------------------------------------
section('Rilevamento del consenso dai CMP');
{
  const cases = [
    ['Complianz', { cmplz_statistics: 'allow', cmplz_marketing: 'allow', cmplz_preferences: 'allow' }, { analytics: true, marketing: true, preferences: true }],
    ['Complianz solo marketing', { cmplz_marketing: 'allow' }, { analytics: false, marketing: true, preferences: false }],
    ['Cookiebot', { CookieConsent: 'stamp:x,preferences:true,statistics:true,marketing:false' }, { analytics: true, marketing: false, preferences: true }],
    ['OneTrust', { OptanonConsent: 'groups=C0001:1,C0002:1,C0003:0,C0004:1' }, { analytics: true, marketing: true, preferences: false }],
    ['iubenda purposes', { '_iub_cs-s459': '{"purposes":{"1":true,"3":true,"4":true,"5":false}}' }, { analytics: true, marketing: false, preferences: true }],
    ['iubenda consent globale', { '_iub_cs-99': '{"consent":true}' }, { analytics: true, marketing: true, preferences: true }],
    ['nessun CMP', {}, { analytics: false, marketing: false, preferences: false }],
  ];
  cases.forEach(function (c) {
    const env = makeEnv({ config: { mode: 'off' }, cookies: c[1] });
    const consent = env.api.consent();
    const expected = c[2];
    const okAll = ['analytics', 'marketing', 'preferences'].every(k => consent[k] === expected[k]);
    ok(okAll, c[0] + ': ' + JSON.stringify({ a: consent.analytics, m: consent.marketing, p: consent.preferences }));
  });
}
{
  // Cookie di consenso personalizzato (opzione del plugin).
  const env = makeEnv({
    config: { mode: 'off', customCookies: { marketing: 'mio_marketing', analytics: '', preferences: '' } },
    cookies: { mio_marketing: 'allow' },
  });
  ok(env.api.consent().marketing === true, 'cookie di consenso personalizzato riconosciuto');
}
{
  // CMP forzato: gli altri provider vengono ignorati.
  const env = makeEnv({
    config: { mode: 'off', cmp: 'cookiebot' },
    cookies: { cmplz_marketing: 'allow' },
  });
  ok(env.api.consent().marketing === false, 'CMP forzato su Cookiebot: Complianz ignorato');
}
{
  const env = makeEnv({
    config: { mode: 'off' },
    cookies: { CookieConsent: 'x', OptanonConsent: 'groups=' },
  });
  const providers = env.api.providers();
  ok(providers.indexOf('cookiebot') !== -1 && providers.indexOf('onetrust') !== -1, 'rilevamento multiplo dei CMP presenti');
}
{
  // Purpose iubenda personalizzabili.
  const env = makeEnv({
    config: { mode: 'off', iubPurposes: { preferences: 3, analytics: 7, marketing: 5 } },
    cookies: { '_iub_cs-1': '{"purposes":{"7":true}}' },
  });
  ok(env.api.consent().analytics === true, 'purpose iubenda analytics configurabile (7)');
}

// -------------------------------------------------------------------------
section('Operatori di match (parità con la controparte PHP)');
{
  const env = makeEnv({ config: { mode: 'off' } });
  const m = env.api.matches;
  const cases = [
    ['equals _fbp / _fbp', { match: 'equals', value: '_fbp' }, '_fbp', '', true],
    ['equals _fbp / _fbpx', { match: 'equals', value: '_fbp' }, '_fbpx', '', false],
    ['contains analytics', { match: 'contains', value: 'analytics' }, 'my_analytics_id', '', true],
    ['contains assente', { match: 'contains', value: 'analytics' }, 'my_stats_id', '', false],
    ['starts_with _ga', { match: 'starts_with', value: '_ga' }, '_ga_ABC123', '', true],
    ['starts_with non in testa', { match: 'starts_with', value: '_ga' }, 'x_ga', '', false],
    ['ends_with _id', { match: 'ends_with', value: '_id' }, 'visitor_id', '', true],
    ['ends_with non in coda', { match: 'ends_with', value: '_id' }, '_id_visitor', '', false],
    ['starts_ends _pk_ .1', { match: 'starts_ends', value: '_pk_', value2: '.1' }, '_pk_id.1', '', true],
    ['starts_ends suffisso diverso', { match: 'starts_ends', value: '_pk_', value2: '.1' }, '_pk_id.2', '', false],
    ['starts_ends sovrapposizione', { match: 'starts_ends', value: 'abc', value2: 'bcd' }, 'abcd', '', false],
    ['wildcard _hj*', { match: 'wildcard', value: '_hj*' }, '_hjSessionUser', '', true],
    ['wildcard _cl?k', { match: 'wildcard', value: '_cl?k' }, '_clck', '', true],
    ['wildcard ? non doppio', { match: 'wildcard', value: '_cl?k' }, '_clsck', '', false],
    ['wildcard *uet*', { match: 'wildcard', value: '*uet*' }, '_uetsid', '', true],
    ['wildcard punto letterale', { match: 'wildcard', value: '_pk.id' }, '_pkXid', '', false],
    ['regex GA', { match: 'regex', value: '^_ga(_[A-Z0-9]+)?$' }, '_ga_ABC123', '', true],
    ['regex GA no _gali', { match: 'regex', value: '^_ga(_[A-Z0-9]+)?$' }, '_gali', '', false],
    ['regex non valida', { match: 'regex', value: '^[unclosed' }, 'qualsiasi', '', false],
    ['ci=0 ide/IDE', { match: 'equals', value: 'ide' }, 'IDE', '', false],
    ['ci=1 ide/IDE', { match: 'equals', value: 'ide', ci: 1 }, 'IDE', '', true],
    ['domain esatto', { match: 'domain', value: 'doubleclick.net' }, 'IDE', 'doubleclick.net', true],
    ['domain con punto', { match: 'domain', value: 'doubleclick.net' }, 'IDE', '.doubleclick.net', true],
    ['domain sottodominio', { match: 'domain', value: 'doubleclick.net' }, 'IDE', 'ad.doubleclick.net', true],
    ['domain confine di etichetta', { match: 'domain', value: 'doubleclick.net' }, 'IDE', 'notdoubleclick.net', false],
    ['domain ignoto', { match: 'domain', value: 'doubleclick.net' }, 'IDE', '', false],
  ];
  cases.forEach(function (c) {
    ok(m(rule(c[1]), c[2], c[3]) === c[4], 'match: ' + c[0]);
  });
}

// -------------------------------------------------------------------------
section('Valutazione: motivazioni esposte al pannello admin');
{
  const env = makeEnv({
    config: { mode: 'off', rules: [rule({ label: 'GA', category: 'analytics', match: 'wildcard', value: '_ga*' })] },
  });
  ok(env.api.evaluate('PHPSESSID', '').reason === 'allowlist', 'reason=allowlist');
  ok(env.api.evaluate('cookie_ignoto', '').reason === 'no_match', 'reason=no_match');
  const blocked = env.api.evaluate('_ga_ABC', '');
  ok(blocked.reason === 'blocked' && blocked.category === 'analytics' && blocked.label === 'GA', 'reason=blocked con categoria ed etichetta');
}
{
  const env = makeEnv({
    config: { mode: 'off', rules: [rule({ category: 'analytics', match: 'wildcard', value: '_ga*' })] },
    cookies: { cmplz_statistics: 'allow' },
  });
  ok(env.api.evaluate('_ga_ABC', '').reason === 'consent_granted', 'reason=consent_granted');
}

// -------------------------------------------------------------------------
section('Robustezza');
{
  // Una regola malformata non deve impedire le scritture legittime.
  const env = makeEnv({ config: { rules: [null, { match: 'equals' }, rule({ match: 'equals', value: '_fbp' })] } });
  env.write('normale=1');
  ok(env.store.normale === '1', 'regole malformate ignorate, scritture normali consentite');
  env.write('_fbp=x');
  ok(!env.has('_fbp'), 'la regola valida continua a funzionare');
}
{
  const env = makeEnv({ config: { enabled: false, rules: [rule({ match: 'equals', value: '_fbp' })] } });
  env.write('_fbp=x');
  ok(env.store._fbp === 'x', 'enabled=false: il guard non si installa affatto');
  ok(!env.window.atiCookieGuardApi, 'enabled=false: nessuna API esposta');
}
{
  const env = makeEnv({ config: { rules: [] } });
  env.write('qualsiasi=1');
  ok(env.store.qualsiasi === '1', 'nessuna regola: tutte le scritture passano');
}
{
  // Il getter deve restare trasparente.
  const env = makeEnv({ config: { rules: [rule({ match: 'equals', value: '_fbp' })] }, cookies: { visibile: '1' } });
  ok(/visibile=1/.test(env.document.cookie), 'lettura di document.cookie invariata');
}
{
  // Passate periodiche pianificate solo se richieste.
  const env = makeEnv({ config: { sweepDuration: 30000, sweepInterval: 1500, rules: [rule({ match: 'equals', value: '_fbp' })] } });
  ok(env.timers.intervals.length === 1 && env.timers.intervals[0].ms === 1500, 'intervallo di pulizia pianificato con il valore configurato');
  const envNone = makeEnv({ config: { sweepDuration: 0, rules: [rule({ match: 'equals', value: '_fbp' })] } });
  ok(envNone.timers.intervals.length === 0, 'durata 0: nessuna passata periodica');
}

// -------------------------------------------------------------------------
console.log('\n---------------------------------------');
console.log('RISULTATO: ' + pass + ' PASS / ' + fail + ' FAIL');
process.exit(fail > 0 ? 1 : 0);
