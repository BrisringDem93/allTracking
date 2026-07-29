/**
 * Compilazione automatica dei campi hidden nei form.
 *
 * Se un form contiene input hidden con nomi noti (fbclid, gclid, fbc, fbp, utm_*,
 * external_id, ...) il plugin li compila con i valori realmente disponibili, così
 * che il provider del form (Fluent Forms, Elementor, CF7, WPForms, custom, ...) li
 * salvi e li inoltri a CRM/n8n/Meta CAPI.
 *
 * Principi:
 * - Non inventa MAI identificatori: se il valore non esiste, il campo resta vuoto.
 *   Unica eccezione documentata: `fbc` viene costruito da `fbclid` con il formato
 *   ufficiale `fb.1.<timestamp>.<fbclid>` (stessa logica di fst_build_user_data()).
 *   `fbp` NON viene mai costruito: deve essere il cookie `_fbp` reale del Pixel.
 * - Non sovrascrive valori già presenti nel campo (impostati dal sito o dall'utente),
 *   salvo quelli scritti da questo script (che vengono aggiornati se cambiano).
 * - Riempie più volte: al load, sui form aggiunti dopo (AJAX/popup/multistep),
 *   al cambio di consenso e — soprattutto — in fase di capture del submit, quando
 *   i cookie `_fbp`/`_fbc` possono essere comparsi dopo l'accettazione del banner.
 * - Persistenza click id: sessionStorage sempre (continuità tra pagine nella stessa
 *   sessione), cookie 90 giorni SOLO con consenso marketing.
 * - Fallisce in modo controllato: nessun errore blocca l'invio del form.
 *
 * Config iniettata da PHP in window.atiFormFields:
 *   { enabled, debug, consentEvent, v }
 */
(function () {
  'use strict';

  var CFG = window.atiFormFields || {};
  if (CFG.enabled === false) {
    return;
  }

  var DEBUG = !!CFG.debug;
  var STORE_KEY = 'fst_clid';        // sessionStorage + cookie di persistenza click id.
  var STORE_MAX_AGE = 7776000;       // 90 giorni (come il cookie _fbc lato server).

  function log() {
    if (DEBUG && window.console) {
      console.log.apply(console, ['[ATI form-fields]'].concat([].slice.call(arguments)));
    }
  }

  // ========================================
  // CHIAVI SUPPORTATE
  // ========================================

  // Parametri di click/campagna letti dall'URL e memorizzati per le pagine successive.
  var URL_KEYS = [
    'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'
  ];

  // Alias accettati (name / id / classe / data-ati-field) -> chiave canonica.
  var ALIASES = {
    fbclid: 'fbclid', fb_clid: 'fbclid',
    fbc: 'fbc', _fbc: 'fbc', fb_fbc: 'fbc',
    fbp: 'fbp', _fbp: 'fbp', fb_fbp: 'fbp',
    gclid: 'gclid', gcl_id: 'gclid', gclid_field: 'gclid',
    gbraid: 'gbraid', wbraid: 'wbraid',
    msclkid: 'msclkid', ttclid: 'ttclid', twclid: 'twclid', li_fat_id: 'li_fat_id',
    utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign',
    utm_term: 'utm_term', utm_content: 'utm_content',
    external_id: 'external_id', externalid: 'external_id', fst_uid: 'external_id'
  };

  // ========================================
  // HELPER BASE
  // ========================================

  function nowMs() {
    return new Date().getTime();
  }

  function cookie(name) {
    try {
      var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      var m = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]+)'));
      return m ? decodeURIComponent(m[1]) : '';
    } catch (e) {
      return '';
    }
  }

  function urlParam(name) {
    try {
      return new URLSearchParams(window.location.search).get(name) || '';
    } catch (e) {
      return '';
    }
  }

  // Consenso marketing: calcolato dallo script inline del plugin (tag-inserter.php).
  // Se non disponibile si assume "nessun consenso" (nessun cookie di persistenza).
  function hasMarketingConsent() {
    return window.marketingConsent === true;
  }

  function parseJson(raw) {
    if (!raw) return null;
    try {
      var data = JSON.parse(raw);
      return (data && typeof data === 'object') ? data : null;
    } catch (e) {
      return null;
    }
  }

  // ========================================
  // PERSISTENZA CLICK ID
  // ========================================

  var _store = null;

  function sessionGet(key) {
    try { return window.sessionStorage.getItem(key) || ''; } catch (e) { return ''; }
  }

  function sessionSet(key, value) {
    try { window.sessionStorage.setItem(key, value); } catch (e) { /* quota/privacy mode */ }
  }

  function readStore() {
    if (_store) return _store;
    _store = {};
    // Il cookie è la fonte più vecchia, la sessione quella più recente: la sessione vince.
    var sources = [parseJson(cookie(STORE_KEY)), parseJson(sessionGet(STORE_KEY))];
    for (var i = 0; i < sources.length; i++) {
      var src = sources[i];
      if (!src) continue;
      for (var k in src) {
        if (Object.prototype.hasOwnProperty.call(src, k) && src[k]) {
          _store[k] = String(src[k]);
        }
      }
    }
    return _store;
  }

  function persistStore() {
    var raw;
    try { raw = JSON.stringify(_store || {}); } catch (e) { return; }
    sessionSet(STORE_KEY, raw);
    // Persistenza lunga solo con consenso marketing (stessa regola di fst_uid).
    if (hasMarketingConsent()) {
      try {
        document.cookie = STORE_KEY + '=' + encodeURIComponent(raw) +
          '; path=/; max-age=' + STORE_MAX_AGE + '; SameSite=Lax';
      } catch (e) { /* noop */ }
    }
  }

  // Cattura i parametri presenti nell'URL corrente (last click vince).
  function captureFromUrl() {
    var store = readStore();
    var changed = false;
    for (var i = 0; i < URL_KEYS.length; i++) {
      var key = URL_KEYS[i];
      var value = urlParam(key);
      if (!value || store[key] === value) continue;
      store[key] = value;
      changed = true;
      // Timestamp del click: serve a costruire un `fbc` stabile tra le pagine.
      if ('fbclid' === key) {
        store.fbclid_ts = String(nowMs());
      }
    }
    if (changed) {
      persistStore();
      log('click id catturati dall\'URL');
    }
  }

  // ========================================
  // RISOLUZIONE DEI VALORI
  // ========================================

  // Estrae il fbclid dalla coda del cookie _fbc (formato fb.<sub>.<ts>.<fbclid>).
  function fbclidFromFbc(fbc) {
    if (!fbc) return '';
    var parts = fbc.split('.');
    return parts.length >= 4 ? parts.slice(3).join('.') : '';
  }

  // Estrae il gclid dalla coda del cookie _gcl_aw (formato GCL.<ts>.<gclid>).
  function gclidFromGclAw(gcl) {
    if (!gcl) return '';
    var parts = gcl.split('.');
    return parts.length >= 3 ? parts.slice(2).join('.') : '';
  }

  // Costruisce `fbc` dal fbclid disponibile: fb.1.<timestamp>.<fbclid>.
  // Indice sottodominio 1 = stesso default usato lato server da fst_build_user_data().
  function buildFbc() {
    var store = readStore();
    var id = urlParam('fbclid') || store.fbclid || '';
    if (!id) return '';
    var ts = store.fbclid_ts || String(nowMs());
    return 'fb.1.' + ts + '.' + id;
  }

  function resolve(key) {
    var store = readStore();
    switch (key) {
      case 'fbc':
        // Il cookie reale del Pixel ha sempre la precedenza sul valore costruito.
        return cookie('_fbc') || buildFbc();
      case 'fbp':
        // Mai costruito: deve essere il cookie reale scritto dal Pixel.
        return cookie('_fbp');
      case 'fbclid':
        return urlParam('fbclid') || fbclidFromFbc(cookie('_fbc')) || store.fbclid || '';
      case 'gclid':
        return urlParam('gclid') || gclidFromGclAw(cookie('_gcl_aw')) || store.gclid || '';
      case 'external_id':
        // Pseudonimo persistente del plugin (cookie scritto solo dopo il consenso).
        return cookie('fst_uid') || window._fstTempUid || '';
      default:
        return urlParam(key) || store[key] || '';
    }
  }

  // ========================================
  // MATCH DEI CAMPI
  // ========================================

  function normalize(value) {
    return String(value == null ? '' : value).trim().toLowerCase();
  }

  // Ricava la chiave canonica di un input, se riconosciuto.
  // Supporta: data-ati-field, name (anche Elementor `form_fields[fbclid]`),
  // id (anche `form-field-fbclid`) e classe `ati-field-<chiave>`.
  function keyOf(input) {
    var explicit = normalize(input.getAttribute('data-ati-field'));
    if (explicit && ALIASES[explicit]) return ALIASES[explicit];

    var name = normalize(input.getAttribute('name'));
    if (name) {
      var bracket = name.match(/\[([^\[\]]+)\]\s*$/);
      if (bracket) name = normalize(bracket[1]);
      if (ALIASES[name]) return ALIASES[name];
    }

    var id = normalize(input.id).replace(/^form-field-/, '').replace(/^field-/, '');
    if (id && ALIASES[id]) return ALIASES[id];

    var cls = normalize(input.className).match(/(?:^|\s)ati-field-([a-z0-9_]+)/);
    if (cls && ALIASES[cls[1]]) return ALIASES[cls[1]];

    return '';
  }

  // Scrittura compatibile anche con i form gestiti da framework reattivi.
  function setValue(input, value) {
    try {
      var desc = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value');
      if (desc && desc.set) {
        desc.set.call(input, value);
      } else {
        input.value = value;
      }
    } catch (e) {
      input.value = value;
    }
    try {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) { /* browser datati: il valore è comunque impostato */ }
  }

  function fillInput(input) {
    var key = keyOf(input);
    if (!key) return false;

    var value = resolve(key);
    if (!value) return false;

    var current = input.value || '';
    // Valore già presente e non scritto da noi: è del sito, non si tocca.
    if (current && '1' !== input.getAttribute('data-ati-filled')) return false;
    if (current === value) return false;

    setValue(input, value);
    input.setAttribute('data-ati-filled', '1');
    log('campo compilato:', key);
    return true;
  }

  function fill(root) {
    var scope = root || document;
    var inputs;
    try {
      inputs = scope.querySelectorAll('input[type="hidden"], input[data-ati-field]');
    } catch (e) {
      return 0;
    }
    var filled = 0;
    for (var i = 0; i < inputs.length; i++) {
      try {
        if (fillInput(inputs[i])) filled++;
      } catch (e) { /* un campo problematico non blocca gli altri */ }
    }
    return filled;
  }

  // ========================================
  // AVVIO
  // ========================================

  function start() {
    // La cattura avviene qui (non a inizio file) perché window.marketingConsent è
    // definito dallo script inline del plugin, stampato nel footer.
    captureFromUrl();
    fill(document);

    // Form aggiunti dopo il load: AJAX, popup, multistep, lazy render.
    if (window.MutationObserver) {
      var scheduled = false;
      var observer = new MutationObserver(function (mutations) {
        if (scheduled) return;
        for (var i = 0; i < mutations.length; i++) {
          var added = mutations[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var node = added[j];
            if (!node || 1 !== node.nodeType) continue;
            if ('INPUT' === node.nodeName || (node.querySelector && node.querySelector('input'))) {
              scheduled = true;
              setTimeout(function () { scheduled = false; fill(document); }, 50);
              return;
            }
          }
        }
      });
      try {
        observer.observe(document.documentElement, { childList: true, subtree: true });
      } catch (e) { /* noop */ }
    }

    // Ultima passata prima dell'invio: in capture, così i valori sono aggiornati
    // prima di qualsiasi handler (serializzazione AJAX inclusa).
    document.addEventListener('submit', function (e) {
      if (e.target && 'FORM' === e.target.nodeName) {
        fill(e.target);
      }
    }, true);

    // Alcuni builder serializzano al click sul bottone senza evento submit nativo.
    document.addEventListener('click', function (e) {
      var target = e.target;
      if (!target || !target.closest) return;
      var btn = target.closest('button, input[type="submit"], [type="submit"]');
      if (!btn) return;
      var form = btn.closest('form');
      if (form) fill(form);
    }, true);

    // I cookie _fbp/_fbc compaiono solo dopo l'accettazione del banner: si ripassa.
    if (CFG.consentEvent) {
      document.addEventListener(CFG.consentEvent, function () {
        persistStore();
        fill(document);
      }, false);
    }
    setTimeout(function () { persistStore(); fill(document); }, 1500);
    setTimeout(function () { fill(document); }, 4000);

    log('attivo', CFG.v || '');
  }

  if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
