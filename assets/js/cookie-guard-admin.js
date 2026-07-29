/**
 * Pannello admin del blocco cookie.
 *
 * Non contiene logica di valutazione: usa `window.atiCookieGuardApi`, esposto dallo
 * stesso `cookie-guard.js` che gira sul front-end (caricato qui con mode=off, quindi
 * in sola lettura). Così l'anteprima mostrata all'amministratore non può divergere
 * dal comportamento reale sul sito.
 *
 * Riempie: stato consenso live, CMP rilevati, elenco dei cookie del browser con
 * l'esito di ciascuno, tester interattivo e aggiunta di righe alla tabella regole.
 */
(function () {
  'use strict';

  var API = window.atiCookieGuardApi;
  var CFG = window.atiCookieGuardAdmin || {};
  var CATEGORY_LABELS = CFG.categoryLabels || {};

  if (!API) {
    return;
  }

  function el(selector) {
    return document.querySelector(selector);
  }

  function all(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function escapeHtml(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function yesNo(value) {
    return value
      ? '<span style="color:green">✅ concesso</span>'
      : '<span style="color:#b32d2e">❌ non concesso</span>';
  }

  /** Etichetta e colore dell'esito di una valutazione. */
  function verdict(ev) {
    if (ev.reason === 'allowlist') {
      return { text: '🛡️ allowlist — mai toccato', color: '#2271b1' };
    }
    if (ev.blocked) {
      var what = ev.action === 'block' ? 'bloccato in scrittura'
        : (ev.action === 'delete' ? 'cancellato se presente' : 'bloccato e cancellato');
      return { text: '⛔ ' + what, color: '#b32d2e' };
    }
    if (ev.reason === 'consent_granted') {
      return { text: '✅ consentito (consenso ' + (CATEGORY_LABELS[ev.category] || ev.category) + ')', color: 'green' };
    }
    return { text: '— nessuna regola', color: '' };
  }

  // =========================================================================
  // STATO CONSENSO LIVE
  // =========================================================================

  function renderConsent() {
    var consent = API.consent();

    all('[data-ati-consent]').forEach(function (cell) {
      cell.innerHTML = yesNo(consent[cell.getAttribute('data-ati-consent')]);
    });

    var providers = el('[data-ati-providers]');
    if (providers) {
      var found = API.providers();
      providers.textContent = found.length ? found.join(', ') : 'nessuno';
    }
  }

  // =========================================================================
  // ELENCO COOKIE DEL BROWSER
  // =========================================================================

  function renderCookieList() {
    var container = el('[data-ati-cookie-list]');
    if (!container) return;

    var names = API.cookies().sort();
    if (!names.length) {
      container.innerHTML = '<p><em>Nessun cookie leggibile da JavaScript su questo dominio.</em></p>';
      return;
    }

    var rows = names.map(function (name) {
      var ev = API.evaluate(name, '');
      var v = verdict(ev);
      var rule = ev.label || (ev.rule !== null ? '#' + (ev.rule + 1) : '—');
      return '<tr>' +
        '<td><code>' + escapeHtml(name) + '</code></td>' +
        '<td style="color:' + v.color + '">' + v.text + '</td>' +
        '<td>' + escapeHtml(ev.category ? (CATEGORY_LABELS[ev.category] || ev.category) : '—') + '</td>' +
        '<td>' + escapeHtml(rule) + '</td>' +
        '</tr>';
    }).join('');

    container.innerHTML =
      '<table class="widefat striped" style="max-width:900px">' +
      '<thead><tr><th style="width:32%">Nome</th><th style="width:26%">Esito</th><th style="width:22%">Categoria</th><th>Regola</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table>' +
      '<p class="description">' + names.length + ' cookie leggibili.</p>';
  }

  // =========================================================================
  // TESTER
  // =========================================================================

  function renderTest() {
    var out = el('[data-ati-test-result]');
    var nameInput = el('[data-ati-test-name]');
    if (!out || !nameInput) return;

    var name = nameInput.value.trim();
    if (!name) {
      out.innerHTML = '';
      return;
    }

    var domainInput = el('[data-ati-test-domain]');
    var domain = domainInput ? domainInput.value.trim() : '';
    var ev = API.evaluate(name, domain);
    var v = verdict(ev);

    var details = '';
    if (ev.rule !== null) {
      var rule = API.rules[ev.rule] || {};
      details = '<p class="description">Regola #' + (ev.rule + 1) + ' «' + escapeHtml(rule.label || '') + '» — ' +
        escapeHtml((CFG.matchLabels && CFG.matchLabels[rule.match]) || rule.match) + ' <code>' + escapeHtml(rule.value) +
        (rule.value2 ? '</code> … <code>' + escapeHtml(rule.value2) : '') + '</code> · categoria ' +
        escapeHtml(CATEGORY_LABELS[ev.category] || ev.category) + '</p>';
    }

    out.innerHTML = '<div class="notice notice-' + (ev.blocked ? 'error' : 'success') + ' inline" style="padding:8px;max-width:900px">' +
      '<p><code>' + escapeHtml(name) + '</code> → <strong style="color:' + v.color + '">' + v.text + '</strong></p>' +
      details + '</div>';
  }

  // =========================================================================
  // RIGHE DELLA TABELLA REGOLE
  // =========================================================================

  function addRuleRow() {
    var table = el('[data-ati-rules]');
    if (!table) return;
    var body = table.querySelector('tbody');
    if (!body || !body.rows.length) return;

    var index = body.rows.length;
    var row = body.rows[body.rows.length - 1].cloneNode(true);

    Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
      field.name = field.name.replace(/ati_cg_rules\[\d+\]/, 'ati_cg_rules[' + index + ']');
      if (field.type === 'checkbox') {
        field.checked = false;
      } else if (field.tagName === 'SELECT') {
        field.selectedIndex = 0;
      } else {
        field.value = '';
      }
    });
    row.removeAttribute('style');

    body.appendChild(row);
  }

  // =========================================================================
  // AVVIO
  // =========================================================================

  function refresh() {
    renderConsent();
    renderCookieList();
    renderTest();
  }

  function init() {
    refresh();

    var button = el('[data-ati-refresh]');
    if (button) {
      button.addEventListener('click', function (e) {
        e.preventDefault();
        refresh();
      });
    }

    ['[data-ati-test-name]', '[data-ati-test-domain]'].forEach(function (selector) {
      var input = el(selector);
      if (input) input.addEventListener('input', renderTest);
    });

    var add = el('[data-ati-add-rule]');
    if (add) {
      add.addEventListener('click', function (e) {
        e.preventDefault();
        addRuleRow();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
