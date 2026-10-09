/**
 * Widget di debug del tracking (front-end, solo amministratori con WP_DEBUG).
 *
 * Sola lettura: non scrive cookie, non invia eventi, non modifica il tracking.
 * L'unica cosa che persiste è lo stato aperto/chiuso del pannello e il tab
 * selezionato, in `localStorage` (non è un cookie e non riguarda l'utente finale,
 * che non vede mai questo widget).
 *
 * La valutazione dei cookie NON è reimplementata qui: si usa
 * `window.atiCookieGuardApi`, esposto da `assets/js/cookie-guard.js` — lo stesso
 * motore che gira sul sito. Se l'API non è disponibile (file del guard non
 * leggibile) il widget degrada: mostra i cookie senza esito e lo dichiara.
 *
 * Dati iniettati da PHP in `window.atiDebugBar` (vedi ATI_Debug_Bar::snapshot()):
 * nessun segreto, nessun valore di cookie.
 */
(function () {
  'use strict';

  var DATA = window.atiDebugBar;
  if (!DATA) {
    return;
  }

  var API = window.atiCookieGuardApi || null;
  var STORE_OPEN = 'atiDebugBar.open';
  var STORE_TAB = 'atiDebugBar.tab';
  var CATEGORY_LABELS = DATA.labels || {};

  // =========================================================================
  // UTILITÀ
  // =========================================================================

  function esc(value) {
    return String(value === undefined || value === null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function store(key, value) {
    try {
      if (value === undefined) return window.localStorage.getItem(key);
      window.localStorage.setItem(key, value);
    } catch (e) { /* storage non disponibile: il widget resta chiuso a ogni pagina */ }
    return null;
  }

  function code(value) {
    return '<code>' + esc(value) + '</code>';
  }

  /** I payload dei CMP sono lunghissimi: nel pannello serve solo il riconoscimento. */
  function shorten(value, max) {
    var text = String(value === undefined || value === null ? '' : value);
    var limit = max || 60;
    return text.length > limit ? text.slice(0, limit) + '…' : text;
  }

  function flag(value, yes, no) {
    return value
      ? '<span class="ok">' + esc(yes || 'sì') + '</span>'
      : '<span class="muted">' + esc(no || 'no') + '</span>';
  }

  function consentFlag(value) {
    return value ? '<span class="ok">✅ concesso</span>' : '<span class="bad">❌ assente</span>';
  }

  function kv(pairs) {
    var html = '<div class="ati-dbg-kv">';
    for (var i = 0; i < pairs.length; i++) {
      if (!pairs[i]) continue;
      html += '<span>' + esc(pairs[i][0]) + '</span><span>' + pairs[i][1] + '</span>';
    }
    return html + '</div>';
  }

  function table(headers, rows, emptyText) {
    if (!rows.length) {
      return '<p class="ati-dbg-empty">' + esc(emptyText) + '</p>';
    }
    var head = headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
    return '<table><thead><tr>' + head + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
  }

  function seconds(value) {
    if (value === null || value === undefined) return '<span class="warn">non pianificato</span>';
    if (value <= 0) return '<span class="warn">in attesa (in ritardo)</span>';
    if (value < 90) return esc(value + 's');
    return esc(Math.round(value / 60) + ' min');
  }

  // =========================================================================
  // LETTURA DELLO STATO REALE NEL BROWSER
  // =========================================================================

  /** Nomi dei cookie leggibili da JavaScript in questa pagina. */
  function presentCookies() {
    if (API && typeof API.cookies === 'function') {
      return API.cookies();
    }
    var out = [];
    var parts = String(document.cookie || '').split(';');
    for (var i = 0; i < parts.length; i++) {
      var eq = parts[i].indexOf('=');
      var name = (eq === -1 ? parts[i] : parts[i].slice(0, eq)).trim();
      if (name && out.indexOf(name) === -1) out.push(name);
    }
    return out;
  }

  /** Consenso live: dal motore del guard se c'è, altrimenti quello del server. */
  function liveConsent() {
    if (API && typeof API.consent === 'function') {
      return API.consent();
    }
    return null;
  }

  function evaluate(name) {
    if (API && typeof API.evaluate === 'function') {
      return API.evaluate(name, '');
    }
    return null;
  }

  /** Etichetta e classe dell'esito di un cookie presente. */
  function verdict(ev) {
    if (!ev) {
      return { text: 'esito non disponibile', cls: 'muted' };
    }
    if (ev.reason === 'allowlist') {
      return { text: '🛡️ allowlist', cls: 'info' };
    }
    if (ev.blocked) {
      var what = ev.action === 'block' ? 'da bloccare' : (ev.action === 'delete' ? 'da cancellare' : 'da bloccare e cancellare');
      return { text: '⛔ ' + what, cls: 'bad' };
    }
    if (ev.reason === 'consent_granted') {
      return { text: '✅ consentito', cls: 'ok' };
    }
    return { text: '— nessuna regola', cls: 'muted' };
  }

  /** Cookie presenti che corrispondono a una riga delle attese. */
  function matching(row, present) {
    return present.filter(function (name) {
      return row.match === 'prefix' ? name.indexOf(row.name) === 0 : name === row.name;
    });
  }

  /**
   * Confronto atteso/presente.
   * - atteso "no" ma presente  -> problema (il cookie non dovrebbe esistere);
   * - atteso "sì" ma assente   -> da verificare (tag non partito, cache, blocco);
   * - "dipende"                -> informativo, entrambi gli esiti sono legittimi.
   */
  function compare(row, present) {
    var found = matching(row, present);
    var here = found.length > 0;
    if (row.expected === 'no') {
      return here
        ? { level: 'error', text: '⛔ presente ma NON dovrebbe esserci', found: found }
        : { level: 'ok', text: '✅ assente, come previsto', found: found };
    }
    if (row.expected === 'yes') {
      return here
        ? { level: 'ok', text: '✅ presente, come previsto', found: found }
        : { level: 'warn', text: '⚠️ atteso ma assente', found: found };
    }
    return {
      level: 'info',
      text: here ? 'presente (ammesso)' : 'assente (ammesso)',
      found: found
    };
  }

  /** Cookie inviati al server ma non leggibili da JavaScript (HttpOnly o di terze parti). */
  function serverOnly(present) {
    return (DATA.cookies || []).filter(function (c) {
      return present.indexOf(c.name) === -1;
    });
  }

  /**
   * Conteggio dei problemi mostrato sul pulsante.
   *
   * I cookie sono contati per nome: un cookie presente che le regole bloccherebbero
   * e che le attese danno per assente è UN problema, non due.
   */
  function problems(present) {
    var flagged = {};

    (DATA.expected || []).forEach(function (row) {
      var res = compare(row, present);
      if (res.level !== 'error') return;
      (res.found.length ? res.found : [row.name]).forEach(function (name) { flagged[name] = 1; });
    });
    present.forEach(function (name) {
      var ev = evaluate(name);
      if (ev && ev.blocked) flagged[name] = 1;
    });

    var count = Object.keys(flagged).length;
    (DATA.notices || []).forEach(function (n) { if (n.level === 'error') count++; });
    return count;
  }

  // =========================================================================
  // TAB
  // =========================================================================

  function tabConsent() {
    var live = liveConsent();
    var rows = ['marketing', 'analytics', 'preferences'].map(function (key) {
      return '<tr><td>' + esc(CATEGORY_LABELS[key] || key) + '</td>' +
        '<td>' + consentFlag(DATA.consent[key]) + '</td>' +
        '<td>' + (live ? consentFlag(live[key]) : '<span class="muted">n/d</span>') + '</td></tr>';
    });

    var html = '<h4>Consenso per categoria</h4>' +
      table(['Categoria', 'Server (PHP)', 'Browser (ora)'], rows, '') +
      '<p class="ati-dbg-note">Se le due colonne differiscono, il consenso è cambiato dopo il caricamento della pagina.</p>';

    var custom = (DATA.guard && DATA.guard.custom_cookies) || {};
    var customRows = Object.keys(custom).filter(function (k) { return custom[k]; }).map(function (k) {
      var value = '<span class="muted">n/d</span>';
      if (API && typeof API.readCookie === 'function') {
        var raw = API.readCookie(custom[k]);
        value = null === raw ? '<span class="muted">assente</span>' : code(shorten(raw));
      }
      return '<tr><td>' + esc(k) + '</td><td>' + code(custom[k]) + '</td><td>' + value + '</td></tr>';
    });

    html += '<h4>Rilevamento CMP</h4>' + kv([
      ['CMP configurato', code((DATA.guard && DATA.guard.cmp) || 'auto')],
      ['Rilevati (server)', (DATA.guard.providers && DATA.guard.providers.length)
        ? code(DATA.guard.providers.join(', '))
        : '<span class="bad">nessuno</span>'],
      ['Rilevati (browser)', (API && typeof API.providers === 'function')
        ? (API.providers().length ? code(API.providers().join(', ')) : '<span class="bad">nessuno</span>')
        : '<span class="muted">n/d</span>'],
      ['Consenso analytics', code((DATA.ga4 && DATA.ga4.consent_mode) || 'auto')]
    ]);

    if (customRows.length) {
      html += '<h4>Cookie di consenso personalizzati</h4>' +
        table(['Categoria', 'Cookie', 'Valore ora'], customRows, '');
    }

    return html;
  }

  function tabCookies() {
    var present = presentCookies().sort();
    var rows = present.map(function (name) {
      var ev = evaluate(name);
      var v = verdict(ev);
      var rule = ev && ev.label ? ev.label : (ev && ev.rule !== null && ev.rule !== undefined ? '#' + (ev.rule + 1) : '—');
      return '<tr><td>' + code(name) + '</td>' +
        '<td class="' + v.cls + '">' + v.text + '</td>' +
        '<td>' + esc(ev && ev.category ? (CATEGORY_LABELS[ev.category] || ev.category) : '—') + '</td>' +
        '<td>' + esc(rule) + '</td></tr>';
    });

    var html = '<h4>Cookie nel browser (' + present.length + ')</h4>' +
      table(['Nome', 'Esito con le regole attive', 'Categoria', 'Regola'], rows,
        'Nessun cookie leggibile da JavaScript su questo dominio.');

    if (!API) {
      html += '<p class="ati-dbg-note warn">Motore del guard non disponibile: gli esiti non possono essere calcolati nel browser.</p>';
    }

    var hidden = serverOnly(present);
    var hiddenRows = hidden.map(function (c) {
      var text = c.reason === 'allowlist' ? '<span class="info">🛡️ allowlist</span>'
        : (c.blocked ? '<span class="bad">⛔ ' + (c.delete ? 'da cancellare' : 'da bloccare') + '</span>'
          : (c.reason === 'consent_granted' ? '<span class="ok">✅ consentito</span>' : '<span class="muted">— nessuna regola</span>'));
      return '<tr><td>' + code(c.name) + '</td><td>' + text + '</td><td>' + esc(c.label || '—') + '</td></tr>';
    });

    html += '<h4>Solo lato server (HttpOnly o di terze parti)</h4>' +
      table(['Nome', 'Esito', 'Regola'], hiddenRows,
        'Nessuno: tutti i cookie della richiesta sono leggibili da JavaScript.') +
      '<p class="ati-dbg-note">Questi cookie JavaScript non li vede: solo la pulizia lato server' +
      (DATA.guard.server_cleanup ? ' (attiva)' : ' (<span class="warn">disattivata</span>)') + ' può rimuoverli.</p>';

    return html;
  }

  function tabExpected() {
    var present = presentCookies();
    var expectedLabel = { yes: 'deve esserci', no: 'non deve esserci', maybe: 'dipende' };

    var rows = (DATA.expected || []).map(function (row) {
      var res = compare(row, present);
      var cls = res.level === 'error' ? 'bad' : (res.level === 'warn' ? 'warn' : (res.level === 'ok' ? 'ok' : 'muted'));
      return '<tr>' +
        '<td>' + code(row.name + (row.match === 'prefix' ? '*' : '')) + '<br /><span class="muted">' + esc(row.source) + '</span></td>' +
        '<td>' + esc(expectedLabel[row.expected] || row.expected) + '</td>' +
        '<td class="' + cls + '">' + res.text + '<br /><span class="muted">' + esc(row.why) + '</span></td>' +
        '</tr>';
    });

    return '<h4>Cookie attesi in base alla configurazione</h4>' +
      table(['Cookie', 'Atteso', 'Stato reale e perché'], rows, 'Nessuna attesa calcolabile.') +
      '<p class="ati-dbg-note">«dipende» = entrambi gli esiti sono legittimi (container GTM, click id non presente, evento non ancora inviato).</p>';
  }

  function tabGuard() {
    var g = DATA.guard || {};
    var stats = (API && typeof API.stats === 'function') ? API.stats() : null;

    var html = '<h4>Blocco cookie</h4>' + kv([
      ['Modalità', code(g.mode) + ' <span class="muted">' + esc(g.mode_label || '') + '</span>'],
      ['Attivo qui', g.active ? '<span class="ok">sì</span>' : '<span class="warn">no</span>'],
      ['Regole attive', esc(g.rules + ' / ' + g.rules_total) + (g.defaults ? ' <span class="muted">(predefinite)</span>' : '')],
      ['Escluso se loggato', flag(g.skip_logged_in, 'sì', 'no')],
      ['Pulizia server', flag(g.server_cleanup, 'attiva', 'disattivata')],
      ['Passate', esc('ogni ' + g.sweep_interval + 'ms per ' + g.sweep_duration + 'ms')],
      ['Log in console', flag(g.guard_log || (DATA.env && DATA.env.guard_log), 'attivo', 'spento')]
    ]);

    if (!g.active) {
      html += '<p class="ati-dbg-note warn">Il guard non sta agendo su questa pagina: gli esiti mostrati sono una simulazione con le regole attive.</p>';
    }

    // I contatori hanno senso solo se il guard sta davvero girando: con mode=off
    // sarebbero sempre 0 e si leggerebbero come «non c'era nulla da bloccare».
    if (stats && g.active) {
      html += '<h4>Interventi in questa pagina</h4>' + kv([
        ['Scritture bloccate', esc(stats.blocked)],
        ['Cookie cancellati', esc(stats.deleted)]
      ]);

      var events = (stats.events || []).slice(-8).reverse();
      if (events.length) {
        html += table(['Azione', 'Cookie', 'Categoria'], events.map(function (e) {
          return '<tr><td>' + esc(e.kind) + '</td><td>' + code(e.name) + '</td><td>' +
            esc(CATEGORY_LABELS[e.category] || e.category || '—') + '</td></tr>';
        }), '');
      }
    }

    return html;
  }

  function tabTracking() {
    var t = DATA.tracking || {};
    var ga4 = DATA.ga4 || {};
    var queue = ga4.queue;

    var html = '<h4>Tag client-side</h4>' + kv([
      ['GTM', t.gtm.enabled ? '<span class="ok">attivo</span> ' + code(t.gtm.id) : '<span class="muted">non attivo</span>'],
      ['GA4', t.ga4.enabled ? '<span class="ok">attivo</span> ' + code(t.ga4.id) : '<span class="muted">non attivo</span>'],
      ['Meta Pixel', t.fb.enabled ? '<span class="ok">attivo</span> ' + code(t.fb.id) : '<span class="muted">non attivo</span>'],
      ['Campi hidden', flag(t.form_fields, 'attivi', 'disattivati')],
      ['Spento se loggato', t.tracking_off ? '<span class="warn">sì — nessun tag qui</span>' : 'no']
    ]);

    html += '<h4>Presenti nella pagina ora</h4>' + kv([
      ['dataLayer', Array.isArray(window.dataLayer)
        ? '<span class="ok">' + window.dataLayer.length + ' elementi</span>'
        : (window.dataLayer ? '<span class="ok">presente</span>' : '<span class="muted">assente</span>')],
      ['gtag', flag(typeof window.gtag === 'function', 'caricato', 'assente')],
      ['fbq', flag(typeof window.fbq === 'function', 'caricato', 'assente')],
      ['Bridge GA4', flag(!!window.atiGa4, 'caricato', 'assente')],
      ['Campi hidden JS', flag(!!window.atiFormFields, 'caricato', 'assente')],
      ['window.marketingConsent', typeof window.marketingConsent === 'undefined'
        ? '<span class="muted">n/d</span>'
        : flag(window.marketingConsent, 'true', 'false')]
    ]);

    html += '<h4>GA4 server-side</h4>' + kv([
      ['Pipeline confermata', flag(ga4.confirmed, 'attiva', 'disattivata')],
      ['Configurazione', ga4.ready ? '<span class="ok">pronta</span>' : '<span class="bad">incompleta</span>'],
      ['Measurement ID', ga4.measurement_id ? code(ga4.measurement_id) : '<span class="bad">mancante</span>'],
      ['API Secret', ga4.has_secret
        ? '<span class="ok">impostato' + (ga4.secret_constant ? ' (costante)' : '') + '</span>'
        : '<span class="bad">mancante</span>'],
      ['Regione', code(ga4.region || '')],
      ['debug_mode', flag(ga4.debug_mode, 'attivo (DebugView)', 'spento')],
      ['Trigger lead', code(ga4.lead_trigger || '')],
      ['client_id', ga4.client_id ? code(ga4.client_id) : '<span class="warn">assente (cookie _ga mancante)</span>'],
      ['session_id', ga4.session_id ? code(ga4.session_id) : '<span class="warn">assente</span>'],
      ['Coda', queue
        ? esc('pending ' + queue.pending + ' · sent ' + queue.sent + ' · failed ' + queue.failed + ' · discarded ' + queue.discarded)
        : '<span class="muted">tabella non presente</span>'],
      ['Worker coda', ga4.cron ? seconds(ga4.cron.queue) : '<span class="muted">n/d</span>'],
      ['Tick orario', ga4.cron ? seconds(ga4.cron.tick) : '<span class="muted">n/d</span>']
    ]);

    html += '<h4>Server-side Meta / n8n</h4>' + kv([
      ['Endpoint n8n', t.n8n.configured ? '<span class="ok">configurato</span> ' + code(t.n8n.host) : '<span class="muted">non configurato</span>'],
      ['Header auth', flag(t.n8n.auth, 'impostato', 'assente')],
      ['Dataset Meta', t.meta_capi.dataset ? code(t.meta_capi.dataset) : '<span class="muted">usa il Pixel ID</span>'],
      ['Token CAPI', flag(t.meta_capi.has_token, 'impostato', 'assente')]
    ]);

    html += '<h4>Ambiente</h4>' + kv([
      ['Plugin', code(DATA.version)],
      ['WP_DEBUG', flag(DATA.env.wp_debug, 'true', 'false')],
      ['WP_DEBUG_LOG', flag(DATA.env.wp_debug_log, 'true', 'false')],
      ['Utente', code(DATA.env.user)],
      ['Widget', code(DATA.mode) + (DATA.forced ? ' <span class="muted">(costante ATI_DEBUG_BAR)</span>' : '')]
    ]);

    return html;
  }

  var TABS = [
    { id: 'consent', label: 'Consenso', render: tabConsent },
    { id: 'cookies', label: 'Cookie', render: tabCookies },
    { id: 'expected', label: 'Attesi', render: tabExpected },
    { id: 'guard', label: 'Blocco', render: tabGuard },
    { id: 'tracking', label: 'Tag & GA4', render: tabTracking }
  ];

  // =========================================================================
  // PANNELLO
  // =========================================================================

  var root;
  var current = store(STORE_TAB) || 'consent';

  function noticesHtml() {
    var list = DATA.notices || [];
    if (!list.length) return '';
    return list.map(function (n) {
      return '<p class="ati-dbg-msg ' + esc(n.level) + '">' + esc(n.text) + '</p>';
    }).join('');
  }

  function renderBody() {
    var tab = TABS.filter(function (t) { return t.id === current; })[0] || TABS[0];
    var body = root.querySelector('#ati-dbg-body');
    var html = '';
    try {
      html = tab.render();
    } catch (e) {
      html = '<p class="bad">Errore nel widget di debug: ' + esc(e && e.message) + '</p>';
    }
    body.innerHTML = (current === 'consent' ? noticesHtml() : '') + html;

    Array.prototype.forEach.call(root.querySelectorAll('#ati-dbg-tabs button'), function (button) {
      button.setAttribute('aria-selected', button.getAttribute('data-tab') === current ? 'true' : 'false');
    });
  }

  function renderBadge() {
    var count = problems(presentCookies());
    var pill = root.querySelector('#ati-dbg-toggle .ati-dbg-pill');
    pill.textContent = count ? String(count) : 'ok';
    pill.style.background = count ? '#f85149' : '#238636';
    pill.style.color = '#fff';
  }

  function refresh() {
    renderBadge();
    if (root.className.indexOf('ati-dbg-open') !== -1) {
      renderBody();
    }
  }

  function copyDump() {
    var dump = {
      snapshot: DATA,
      browser: {
        url: String(window.location.href),
        cookies: presentCookies().sort(),
        consent: liveConsent(),
        dataLayer: window.dataLayer ? window.dataLayer.length : null,
        gtag: typeof window.gtag === 'function',
        fbq: typeof window.fbq === 'function',
        guardStats: (API && typeof API.stats === 'function') ? API.stats() : null
      }
    };
    var text = JSON.stringify(dump, null, 2);
    var button = root.querySelector('[data-ati-dbg="copy"]');

    function done(label) {
      button.textContent = label;
      window.setTimeout(function () { button.textContent = 'Copia JSON'; }, 1500);
    }

    if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
      window.navigator.clipboard.writeText(text).then(function () { done('copiato ✓'); }, function () {
        if (window.console) window.console.log('[ATI DEBUG]', dump);
        done('in console');
      });
      return;
    }
    if (window.console) window.console.log('[ATI DEBUG]', dump);
    done('in console');
  }

  function open(isOpen) {
    root.className = isOpen ? 'ati-dbg-open' : '';
    store(STORE_OPEN, isOpen ? '1' : '0');
    if (isOpen) renderBody();
  }

  function build() {
    root = document.createElement('div');
    root.id = 'ati-dbg';

    root.innerHTML =
      '<button id="ati-dbg-toggle" type="button" title="Diagnostica tracking e cookie">' +
        '🍪 Tracking debug <span class="ati-dbg-pill">·</span>' +
      '</button>' +
      '<div id="ati-dbg-panel">' +
        '<div id="ati-dbg-head">' +
          '<strong>Tracking debug</strong> <span class="ati-dbg-ver">v' + esc(DATA.version) + '</span>' +
          '<span class="ati-dbg-actions">' +
            '<button type="button" data-ati-dbg="refresh">Aggiorna</button>' +
            '<button type="button" data-ati-dbg="copy">Copia JSON</button>' +
            '<button type="button" data-ati-dbg="settings">Impostazioni</button>' +
            '<button type="button" data-ati-dbg="close" title="Chiudi">✕</button>' +
          '</span>' +
        '</div>' +
        '<div id="ati-dbg-tabs">' +
          TABS.map(function (t) {
            return '<button type="button" role="tab" data-tab="' + t.id + '" aria-selected="false">' + esc(t.label) + '</button>';
          }).join('') +
        '</div>' +
        '<div id="ati-dbg-body"></div>' +
      '</div>';

    document.body.appendChild(root);

    root.querySelector('#ati-dbg-toggle').addEventListener('click', function () { open(true); });
    root.querySelector('[data-ati-dbg="close"]').addEventListener('click', function () { open(false); });
    root.querySelector('[data-ati-dbg="refresh"]').addEventListener('click', refresh);
    root.querySelector('[data-ati-dbg="copy"]').addEventListener('click', copyDump);
    root.querySelector('[data-ati-dbg="settings"]').addEventListener('click', function () {
      window.open(DATA.settings, '_blank');
    });

    Array.prototype.forEach.call(root.querySelectorAll('#ati-dbg-tabs button'), function (button) {
      button.addEventListener('click', function () {
        current = button.getAttribute('data-tab');
        store(STORE_TAB, current);
        renderBody();
      });
    });

    renderBadge();
    if (store(STORE_OPEN) === '1') {
      open(true);
    }

    // Il consenso può cambiare senza ricaricare: il pannello si riallinea.
    var events = ['cmplz_status_change', 'CookiebotOnAccept', 'CookiebotOnDecline',
      'OneTrustGroupsUpdated', 'iubenda_consent_given', 'iubenda_preference_expressed'];
    events.forEach(function (name) {
      try {
        window.addEventListener(name, refresh, false);
        document.addEventListener(name, refresh, false);
      } catch (e) { /* evento non registrabile */ }
    });
  }

  function init() {
    if (document.getElementById('ati-dbg')) return;
    try {
      build();
    } catch (e) {
      if (window.console) window.console.error('[ATI DEBUG] widget non inizializzato:', e);
    }
  }

  // API di sola lettura: utile dalla console del browser e usata da
  // tests/debug-bar-tests.js. Non dipende dal DOM, quindi resta disponibile
  // anche se il pannello non riesce a costruirsi.
  window.atiDebugBarApi = {
    version: DATA.version || '',
    snapshot: DATA,
    cookies: presentCookies,
    consent: liveConsent,
    evaluate: evaluate,
    matching: matching,
    compare: compare,
    serverOnly: serverOnly,
    problems: problems
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
