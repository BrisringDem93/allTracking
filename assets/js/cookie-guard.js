/**
 * Blocco cookie senza consenso (cookie guard).
 *
 * Sostituisce il setter di `document.cookie` con una versione che, prima di
 * scrivere, valuta il nome (e il dominio) del cookie contro le regole configurate
 * e lo stato di consenso per categoria. Se la categoria non è consentita, la
 * scrittura viene scartata. In più, a intervalli regolari, cancella i cookie già
 * presenti che violano le regole (utile quando un cookie è stato scritto prima
 * dell'installazione del guard o da una libreria caricata altrove).
 *
 * Principi:
 * - NON tocca mai i cookie in allowlist (sessione WordPress/WooCommerce, cookie
 *   dei CMP, cookie del plugin): bloccarli romperebbe il sito o cancellerebbe la
 *   scelta di consenso dell'utente.
 * - NON blocca MAI una scrittura di cancellazione (expires nel passato o
 *   max-age<=0): altrimenti nessuno potrebbe più rimuovere un cookie.
 * - Rivaluta il consenso a ogni passata: appena l'utente accetta, le scritture
 *   tornano a passare senza ricaricare la pagina.
 * - Fallisce in modo controllato: qualunque eccezione lascia il comportamento
 *   nativo del browser intatto.
 * - Non può intercettare i cookie di terze parti impostati via header HTTP
 *   `Set-Cookie` (fuori dalla portata di JavaScript): per quelli esiste la
 *   pulizia lato server, opzionale.
 *
 * La logica di matching replica ESATTAMENTE ATI_Cookie_Rules (PHP). Le due
 * implementazioni sono coperte da test paralleli e vanno mantenute allineate.
 *
 * Config iniettata da PHP in window.atiCookieGuard:
 *   { enabled, mode, expose, debug, rules, allowlist, cmp, customCookies,
 *     iubPurposes, consentEvents, sweepInterval, sweepDuration, v }
 */
(function () {
  'use strict';

  var CFG = window.atiCookieGuard || {};
  if (CFG.enabled === false) {
    return;
  }

  var MODE = CFG.mode || 'off';
  var DEBUG = !!CFG.debug;
  var RULES = Array.isArray(CFG.rules) ? CFG.rules : [];
  var ALLOWLIST = Array.isArray(CFG.allowlist) ? CFG.allowlist : [];
  var CUSTOM = CFG.customCookies || {};
  var IUB = CFG.iubPurposes || { preferences: 3, analytics: 4, marketing: 5 };
  var CMP = CFG.cmp || 'auto';

  function log() {
    if (!DEBUG || !window.console) return;
    var args = ['[ATI COOKIE GUARD]'].concat(Array.prototype.slice.call(arguments));
    window.console.log.apply(window.console, args);
  }

  // =========================================================================
  // MATCHING (specchio di ATI_Cookie_Rules)
  // =========================================================================

  function escapeRegex(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  /** Pattern wildcard (* = qualsiasi sequenza, ? = un carattere) -> RegExp ancorata. */
  function wildcardToRegex(pattern, ci) {
    var body = escapeRegex(pattern).replace(/\\\*/g, '.*').replace(/\\\?/g, '.');
    try {
      return new RegExp('^' + body + '$', ci ? 'i' : '');
    } catch (e) {
      return null;
    }
  }

  function compileRegex(pattern, ci) {
    if (!pattern) return null;
    try {
      return new RegExp(pattern, ci ? 'i' : '');
    } catch (e) {
      log('regex non valida, regola ignorata:', pattern);
      return null;
    }
  }

  /** `example.com` copre `example.com` e `www.example.com` (il punto iniziale è ignorato). */
  function domainMatches(candidate, domain) {
    candidate = String(candidate || '').trim().toLowerCase().replace(/^\.+/, '');
    domain = String(domain || '').trim().toLowerCase().replace(/^\.+/, '');
    if (!candidate || !domain) return false;
    if (candidate === domain) return true;
    return domain.length > candidate.length &&
      domain.slice(-(candidate.length + 1)) === '.' + candidate;
  }

  function ruleMatches(rule, name, domain) {
    if (!rule) return false;
    var type = rule.match || '';
    var value = rule.value === undefined ? '' : String(rule.value);
    var value2 = rule.value2 === undefined ? '' : String(rule.value2);
    var ci = !!rule.ci && rule.ci !== '0';
    name = String(name === undefined ? '' : name);

    if (!value) return false;
    if (type === 'domain') return domainMatches(value, domain);

    var subject = ci ? name.toLowerCase() : name;
    var needle = ci ? value.toLowerCase() : value;
    var needle2 = ci ? value2.toLowerCase() : value2;

    switch (type) {
      case 'equals':
        return subject === needle;
      case 'contains':
        return !!needle && subject.indexOf(needle) !== -1;
      case 'starts_with':
        return subject.indexOf(needle) === 0;
      case 'ends_with':
        return !!needle && subject.slice(-needle.length) === needle;
      case 'starts_ends':
        if (!needle2) return subject.indexOf(needle) === 0;
        if (subject.length < needle.length + needle2.length) return false;
        return subject.indexOf(needle) === 0 && subject.slice(-needle2.length) === needle2;
      case 'wildcard': {
        var wre = wildcardToRegex(value, ci);
        return !!wre && wre.test(name);
      }
      case 'regex': {
        var re = compileRegex(value, ci);
        return !!re && re.test(name);
      }
      default:
        return false;
    }
  }

  /** L'allowlist è sempre case-insensitive: un errore qui romperebbe la sessione. */
  function isAllowlisted(name) {
    for (var i = 0; i < ALLOWLIST.length; i++) {
      var pattern = ALLOWLIST[i];
      if (!pattern) continue;
      var re = wildcardToRegex(pattern, true);
      if (re && re.test(String(name))) return true;
    }
    return false;
  }

  /**
   * Valuta un cookie: allowlist -> regole -> consenso.
   * @returns {{blocked:boolean, action:string, reason:string, rule:(number|null), label:string, category:string}}
   */
  function evaluate(name, domain, consent) {
    var out = { blocked: false, action: '', reason: 'no_match', rule: null, label: '', category: '' };
    name = String(name === undefined ? '' : name);
    if (!name) {
      out.reason = 'empty_name';
      return out;
    }
    if (isAllowlisted(name)) {
      out.reason = 'allowlist';
      return out;
    }

    consent = consent || currentConsent();

    for (var i = 0; i < RULES.length; i++) {
      var rule = RULES[i];
      if (!rule || !rule.enabled || rule.enabled === '0') continue;
      if (!ruleMatches(rule, name, domain)) continue;

      var category = rule.category || 'marketing';
      var granted = category !== 'always' && !!consent[category];

      out.rule = i;
      out.label = rule.label || '';
      out.category = category;
      out.action = rule.action || 'block_delete';

      if (granted) {
        // Consenso presente per questa regola: prosegue, un'altra regola
        // (categoria diversa o "always") potrebbe comunque bloccare il cookie.
        out.reason = 'consent_granted';
        out.blocked = false;
        continue;
      }

      out.reason = 'blocked';
      out.blocked = true;
      return out;
    }

    return out;
  }

  function shouldBlock(ev) {
    return ev.blocked && (ev.action === 'block' || ev.action === 'block_delete');
  }

  function shouldDelete(ev) {
    return ev.blocked && (ev.action === 'delete' || ev.action === 'block_delete');
  }

  // =========================================================================
  // CONSENSO GRANULARE (specchio di ATI_Cookie_Consent)
  // =========================================================================

  function rawCookies() {
    try {
      return String(nativeGet() || '');
    } catch (e) {
      return '';
    }
  }

  function readCookie(name) {
    if (!name) return null;
    var escaped = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var m = rawCookies().match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
    if (!m) return null;
    try {
      return decodeURIComponent(m[1]);
    } catch (e) {
      return m[1];
    }
  }

  function providerAllowed(candidate) {
    return CMP === 'auto' || CMP === candidate;
  }

  /** Payload di consenso iubenda (il nome del cookie contiene un id variabile). */
  function iubendaData() {
    var m = rawCookies().match(/(?:^|;\s*)_iub_cs-[\w-]+=([^;]*)/);
    if (!m) return null;
    try {
      return JSON.parse(decodeURIComponent(m[1]));
    } catch (e) {
      try {
        return JSON.parse(m[1]);
      } catch (e2) {
        return null;
      }
    }
  }

  function iubendaPurpose(data, index) {
    if (!data) return false;
    if (data.consent === true) return true;
    if (!data.purposes) return false;
    return data.purposes[index] === true || data.purposes[String(index)] === true;
  }

  function oneTrustGroups() {
    var raw = readCookie('OptanonConsent');
    if (!raw) return '';
    var m = String(raw).match(/(?:^|&)groups=([^&]*)/);
    if (!m) return '';
    try {
      return decodeURIComponent(m[1]);
    } catch (e) {
      return m[1];
    }
  }

  var CATEGORY_SIGNALS = {
    preferences: { cmplz: 'cmplz_preferences', cookiebot: 'preferences:true', onetrust: 'C0003:1', iub: 'preferences' },
    analytics: { cmplz: 'cmplz_statistics', cookiebot: 'statistics:true', onetrust: 'C0002:1', iub: 'analytics' },
    marketing: { cmplz: 'cmplz_marketing', cookiebot: 'marketing:true', onetrust: 'C0004:1', iub: 'marketing' }
  };

  function detectCategory(category) {
    var sig = CATEGORY_SIGNALS[category];
    if (!sig) return false;

    // Cookie personalizzato: vale sempre (anche con CMP forzato).
    var custom = CUSTOM[category];
    if (custom && readCookie(custom) === 'allow') return true;
    if (CMP === 'custom') return false;

    if (providerAllowed('complianz') && readCookie(sig.cmplz) === 'allow') return true;

    if (providerAllowed('iubenda')) {
      var idx = parseInt(IUB[sig.iub], 10);
      if (!isNaN(idx) && iubendaPurpose(iubendaData(), idx)) return true;
    }

    if (providerAllowed('cookiebot')) {
      var cb = readCookie('CookieConsent');
      if (cb && cb.indexOf(sig.cookiebot) !== -1) return true;
    }

    if (providerAllowed('onetrust') && oneTrustGroups().indexOf(sig.onetrust) !== -1) return true;

    return false;
  }

  function detectedProviders() {
    var found = [];
    if (/(?:^|;\s*)_iub_cs-/.test(rawCookies())) found.push('iubenda');
    if (readCookie('cmplz_marketing') !== null || readCookie('cmplz_statistics') !== null ||
        readCookie('cmplz_preferences') !== null || readCookie('cmplz_consent_status') !== null) {
      found.push('complianz');
    }
    if (readCookie('CookieConsent') !== null) found.push('cookiebot');
    if (readCookie('OptanonConsent') !== null) found.push('onetrust');
    for (var k in CUSTOM) {
      if (CUSTOM[k] && readCookie(CUSTOM[k]) !== null) { found.push('custom'); break; }
    }
    return found;
  }

  var _consent = null;

  function refreshConsent() {
    _consent = {
      necessary: true,
      preferences: detectCategory('preferences'),
      analytics: detectCategory('analytics'),
      marketing: detectCategory('marketing'),
      providers: detectedProviders()
    };
    return _consent;
  }

  function currentConsent() {
    return _consent || refreshConsent();
  }

  // =========================================================================
  // ACCESSO NATIVO A document.cookie
  // =========================================================================

  function findDescriptor() {
    var d = Object.getOwnPropertyDescriptor(document, 'cookie');
    if (d && d.get && d.set && d.configurable) return d;
    if (typeof Document !== 'undefined' && Document.prototype) {
      d = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
      if (d && d.get && d.set && d.configurable) return d;
    }
    return null;
  }

  var NATIVE = findDescriptor();

  function nativeGet() {
    return NATIVE ? NATIVE.get.call(document) : '';
  }

  function nativeSet(value) {
    if (NATIVE) NATIVE.set.call(document, value);
  }

  // =========================================================================
  // PARSING DELLA STRINGA DI SCRITTURA
  // =========================================================================

  function parseWrite(str) {
    var s = String(str === undefined ? '' : str);
    var parts = s.split(';');
    var first = parts[0] || '';
    var eq = first.indexOf('=');
    var rawName = (eq === -1 ? first : first.slice(0, eq)).trim();
    var value = eq === -1 ? '' : first.slice(eq + 1);

    var out = { name: rawName, value: value, domain: '', expires: '', maxAge: null };
    for (var i = 1; i < parts.length; i++) {
      var seg = parts[i];
      var j = seg.indexOf('=');
      var key = (j === -1 ? seg : seg.slice(0, j)).trim().toLowerCase();
      var val = j === -1 ? '' : seg.slice(j + 1).trim();
      if (key === 'domain') out.domain = val;
      else if (key === 'expires') out.expires = val;
      else if (key === 'max-age') out.maxAge = val;
    }
    return out;
  }

  /** Nome anche nella forma decodificata: alcune librerie url-encodano il nome. */
  function nameVariants(name) {
    var out = [name];
    try {
      var decoded = decodeURIComponent(name);
      if (decoded !== name) out.push(decoded);
    } catch (e) { /* nome non decodificabile: resta quello grezzo */ }
    return out;
  }

  /** La scrittura sta CANCELLANDO un cookie? Non va mai bloccata. */
  function isExpiring(parsed) {
    if (parsed.maxAge !== null && parsed.maxAge !== '') {
      var ma = parseInt(parsed.maxAge, 10);
      if (!isNaN(ma) && ma <= 0) return true;
    }
    if (parsed.expires) {
      var t = Date.parse(parsed.expires);
      if (!isNaN(t) && t <= new Date().getTime()) return true;
    }
    return false;
  }

  /** Valuta tutte le varianti del nome; restituisce il primo esito bloccante. */
  function evaluateName(name, domain) {
    var variants = nameVariants(name);
    var last = null;
    for (var i = 0; i < variants.length; i++) {
      var ev = evaluate(variants[i], domain, currentConsent());
      if (ev.blocked) return ev;
      last = last || ev;
    }
    return last || { blocked: false, action: '', reason: 'no_match', rule: null, label: '', category: '' };
  }

  // =========================================================================
  // CANCELLAZIONE DEI COOKIE GIÀ PRESENTI
  // =========================================================================

  function pathCandidates() {
    var paths = ['/'];
    var pathname = '';
    try {
      pathname = (window.location && window.location.pathname) || '';
    } catch (e) { /* location non disponibile (test) */ }
    if (!pathname || pathname === '/') return paths;

    var segments = pathname.split('/').filter(Boolean);
    var acc = '';
    for (var i = 0; i < segments.length && i < 6; i++) {
      acc += '/' + segments[i];
      paths.push(acc);
    }
    return paths;
  }

  function domainCandidates() {
    var host = '';
    try {
      host = ((window.location && window.location.hostname) || '').toLowerCase();
    } catch (e) { /* location non disponibile (test) */ }
    var domains = [''];
    if (!host || /^[\d.]+$/.test(host)) return domains;

    var parts = host.split('.');
    for (var i = 0; i <= parts.length - 2; i++) {
      domains.push('.' + parts.slice(i).join('.'));
    }
    return domains;
  }

  function deleteCookie(name) {
    var base = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0';
    var paths = pathCandidates();
    var domains = domainCandidates();
    for (var p = 0; p < paths.length; p++) {
      for (var d = 0; d < domains.length; d++) {
        var str = base + '; path=' + paths[p];
        if (domains[d]) str += '; domain=' + domains[d];
        try {
          nativeSet(str);
        } catch (e) { /* una variante non applicabile non deve fermare le altre */ }
      }
    }
  }

  function presentCookieNames() {
    var raw = rawCookies();
    if (!raw) return [];
    var names = [];
    var parts = raw.split(';');
    for (var i = 0; i < parts.length; i++) {
      var seg = parts[i];
      var eq = seg.indexOf('=');
      var name = (eq === -1 ? seg : seg.slice(0, eq)).trim();
      if (name && names.indexOf(name) === -1) names.push(name);
    }
    return names;
  }

  var stats = { blocked: 0, deleted: 0, events: [] };

  function record(kind, name, ev) {
    stats.events.push({ kind: kind, name: name, category: ev.category, label: ev.label, rule: ev.rule });
    if (stats.events.length > 200) stats.events.shift();
  }

  /** Passata di pulizia sui cookie già presenti. */
  function sweep() {
    refreshConsent();
    var names = presentCookieNames();
    for (var i = 0; i < names.length; i++) {
      var name = names[i];
      var ev = evaluateName(name, '');
      if (!shouldDelete(ev)) continue;

      if (MODE === 'enforce') {
        deleteCookie(name);
        stats.deleted++;
        record('deleted', name, ev);
        log('cookie cancellato:', name, '(categoria ' + ev.category + ', regola "' + ev.label + '")');
      } else {
        record('would-delete', name, ev);
        log('MONITOR: cancellerei il cookie', name, '(categoria ' + ev.category + ')');
      }
    }
  }

  // =========================================================================
  // INSTALLAZIONE
  // =========================================================================

  function installSetter() {
    if (!NATIVE) {
      log('descrittore di document.cookie non accessibile: blocco in scrittura non installato');
      return false;
    }
    try {
      Object.defineProperty(document, 'cookie', {
        configurable: true,
        enumerable: true,
        get: function () {
          return nativeGet();
        },
        set: function (value) {
          try {
            var parsed = parseWrite(value);

            // Una cancellazione non va MAI bloccata.
            if (isExpiring(parsed)) {
              nativeSet(value);
              return;
            }

            var ev = evaluateName(parsed.name, parsed.domain);
            if (shouldBlock(ev)) {
              if (MODE === 'enforce') {
                stats.blocked++;
                record('blocked', parsed.name, ev);
                log('scrittura bloccata:', parsed.name, '(categoria ' + ev.category + ', regola "' + ev.label + '")');
                return;
              }
              record('would-block', parsed.name, ev);
              log('MONITOR: bloccherei la scrittura di', parsed.name, '(categoria ' + ev.category + ')');
            }
          } catch (e) {
            // Nessun errore del guard deve impedire una scrittura legittima.
            log('errore nella valutazione, scrittura consentita:', e);
          }
          nativeSet(value);
        }
      });
      return true;
    } catch (e) {
      log('impossibile sostituire document.cookie:', e);
      return false;
    }
  }

  function scheduleSweeps() {
    var interval = parseInt(CFG.sweepInterval, 10);
    if (isNaN(interval) || interval < 250) interval = 2000;
    var duration = parseInt(CFG.sweepDuration, 10);
    if (isNaN(duration) || duration < 0) duration = 60000;

    sweep();

    if (typeof document.addEventListener === 'function') {
      document.addEventListener('DOMContentLoaded', sweep, false);
    }
    if (typeof window.addEventListener === 'function') {
      window.addEventListener('load', sweep, false);

      // Il cambio di consenso deve avere effetto immediato, senza ricaricare.
      var events = Array.isArray(CFG.consentEvents) ? CFG.consentEvents.slice() : [];
      events = events.concat(['cmplz_status_change', 'cmplz_fire_categories', 'CookiebotOnAccept',
        'CookiebotOnDecline', 'OneTrustGroupsUpdated', 'iubenda_consent_given', 'iubenda_preference_expressed']);
      for (var i = 0; i < events.length; i++) {
        if (!events[i]) continue;
        try {
          window.addEventListener(events[i], sweep, false);
          if (typeof document.addEventListener === 'function') {
            document.addEventListener(events[i], sweep, false);
          }
        } catch (e) { /* evento non registrabile: ignorato */ }
      }
    }

    if (typeof window.setInterval !== 'function' || duration === 0) return;

    var timer = window.setInterval(sweep, interval);
    if (typeof window.setTimeout === 'function') {
      window.setTimeout(function () {
        window.clearInterval(timer);
        log('passate periodiche terminate dopo ' + duration + 'ms');
      }, duration);
    }
  }

  // API di sola lettura per il pannello admin (anteprima delle regole).
  if (CFG.expose) {
    window.atiCookieGuardApi = {
      version: CFG.v || '',
      mode: MODE,
      rules: RULES,
      allowlist: ALLOWLIST,
      evaluate: function (name, domain) { return evaluate(name, domain, refreshConsent()); },
      matches: ruleMatches,
      isAllowlisted: isAllowlisted,
      consent: refreshConsent,
      providers: detectedProviders,
      cookies: presentCookieNames,
      readCookie: readCookie,
      // Passata di pulizia su richiesta. Con mode diverso da 'enforce' non
      // cancella nulla: si limita a registrare cosa cancellerebbe.
      sweep: sweep,
      stats: function () { return stats; }
    };
  }

  if (MODE === 'off') {
    return;
  }

  refreshConsent();
  installSetter();
  scheduleSweeps();
  log('attivo in modalità ' + MODE + ' — ' + RULES.length + ' regole, consenso:', currentConsent());
})();
