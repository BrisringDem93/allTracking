/**
 * GA4 client-side bridge.
 *
 * Recupera dal Google Tag (gtag) il client_id e il session_id reali e invia gli
 * eventi CONFERMATI all'endpoint REST first-party del plugin.
 *
 * Principi:
 * - Funziona con Google Tag caricato direttamente o via GTM.
 * - Attende gtag con timeout controllato; non blocca mai l'invio del form.
 * - Non contiene mai l'API secret.
 * - Non raccoglie PII.
 * - Non crea identificatori analytics: usa solo quelli generati dal Google Tag.
 * - Fallisce in modo controllato.
 *
 * Config iniettata da PHP in window.atiGa4Bridge:
 *   { endpoint, token, measurementId, enabled, submitAttempt, debug }
 */
(function () {
  'use strict';

  var CFG = window.atiGa4Bridge || {};
  if (!CFG.enabled || !CFG.endpoint || !CFG.token) {
    return;
  }

  var DEBUG = !!CFG.debug;
  var GTAG_WAIT_MS = 3000;   // Attesa massima che gtag diventi disponibile.
  var GTAG_GET_MS = 2000;    // Timeout della singola get.

  function log() {
    if (DEBUG && window.console) {
      console.log.apply(console, ['[ATI GA4 bridge]'].concat([].slice.call(arguments)));
    }
  }

  // Garantisce l'esistenza della funzione gtag anche quando il Google Tag è
  // gestito da GTM: GTM crea/usa lo stesso dataLayer, quindi uno shim gtag che vi
  // scrive è processato da gtag.js caricato da GTM. Non crea alcuna identità.
  function ensureGtag() {
    if (typeof window.gtag === 'function') {
      return true;
    }
    if (window.dataLayer && typeof window.dataLayer.push === 'function') {
      window.gtag = function () { window.dataLayer.push(arguments); };
      return true;
    }
    return false;
  }

  // Attende che gtag sia disponibile, con timeout complessivo.
  function waitForGtag() {
    return new Promise(function (resolve) {
      if (ensureGtag()) { resolve(true); return; }
      var waited = 0;
      var step = 100;
      var iv = setInterval(function () {
        waited += step;
        if (ensureGtag()) { clearInterval(iv); resolve(true); return; }
        if (waited >= GTAG_WAIT_MS) { clearInterval(iv); resolve(false); }
      }, step);
    });
  }

  // FONTE PRIMARIA dell'identità: gtag('get', measurementId, field).
  // Nessun parsing di cookie lato client (il fallback cookie è SOLO server-side).
  // Risolve '' se non disponibile: nessuna identità inventata.
  function gtagGet(field) {
    return new Promise(function (resolve) {
      var done = false;
      function finish(val) {
        if (done) return;
        done = true;
        resolve(typeof val === 'string' || typeof val === 'number' ? String(val) : '');
      }
      if (typeof window.gtag !== 'function' || !CFG.measurementId) {
        finish('');
        return;
      }
      var timer = setTimeout(function () { finish(''); }, GTAG_GET_MS);
      try {
        window.gtag('get', CFG.measurementId, field, function (value) {
          clearTimeout(timer);
          finish(value);
        });
      } catch (e) {
        clearTimeout(timer);
        log('gtag get error', field); // PII-free: nessun valore, nessun dettaglio cookie.
        finish('');
      }
    });
  }

  // Costruisce il contesto identità/pagina (nessuna PII).
  function buildContext() {
    return waitForGtag().then(function (ready) {
      if (!ready) {
        // gtag non disponibile entro il timeout: nessuna identità inventata.
        log('gtag non disponibile: identita delegata al fallback server-side (cookie)');
        return {
          client_id: '',
          session_id: '',
          page_location: window.location.href,
          page_title: document.title,
          page_referrer: document.referrer || '',
          engagement_time_msec: 1,
          identity_source: 'none'
        };
      }
      return Promise.all([gtagGet('client_id'), gtagGet('session_id')]).then(function (vals) {
        var cid = vals[0] || '';
        log('identita', cid ? 'client_id via gtag' : 'client_id assente (degradato)');
        return {
          client_id: cid,
          session_id: vals[1] || '',
          page_location: window.location.href,
          page_title: document.title,
          page_referrer: document.referrer || '',
          engagement_time_msec: 1,
          identity_source: cid ? 'gtag' : 'none'
        };
      });
    });
  }

  // Invia un evento confermato. params: solo parametri commerciali (no PII).
  function sendEvent(eventName, params, extraContext) {
    return buildContext().then(function (ctx) {
      var body = {
        event: eventName,
        token: CFG.token,
        client_id: ctx.client_id,
        session_id: ctx.session_id,
        page_location: ctx.page_location,
        page_title: ctx.page_title,
        page_referrer: ctx.page_referrer,
        engagement_time_msec: ctx.engagement_time_msec,
        params: params || {}
      };
      if (extraContext && extraContext.form_id) {
        body.form_id = String(extraContext.form_id);
      }
      if (extraContext && extraContext.event_id) {
        body.event_id = String(extraContext.event_id);
      }

      var payload = JSON.stringify(body);

      // sendBeacon non blocca; fallback fetch keepalive.
      try {
        if (navigator.sendBeacon) {
          var blob = new Blob([payload], { type: 'application/json' });
          navigator.sendBeacon(CFG.endpoint, blob);
          log('sent via beacon', eventName, body.client_id ? 'cid ok' : 'cid missing');
          return true;
        }
      } catch (e) {
        log('beacon error', e);
      }

      fetch(CFG.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function (err) { log('fetch error', err); });

      return true;
    });
  }

  // API pubblica per integrazioni manuali (chiamare DOPO il successo reale del form).
  window.atiGa4 = {
    trackConfirmedLead: function (params, ctx) {
      return sendEvent('generate_lead', params || {}, ctx || {});
    },
    trackSubmitAttempt: function (params, ctx) {
      if (!CFG.submitAttempt) {
        return Promise.resolve(false);
      }
      return sendEvent('form_submit_attempt', params || {}, ctx || {});
    }
  };

  // Debounce per evitare doppi invii dallo stesso form (doppio click / re-submit).
  var _lastSubmit = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
  function debounced(form) {
    if (!_lastSubmit || !form) return false;
    var now = new Date().getTime();
    var prev = _lastSubmit.get(form) || 0;
    if (now - prev < 3000) return true;
    _lastSubmit.set(form, now);
    return false;
  }
  function formIdOf(form) {
    return (form && (form.getAttribute('data-form_id') || form.id || form.getAttribute('name'))) || '';
  }

  // Invia generate_lead per un form. Con debounce anti doppio-invio.
  function sendLead(form, sourceLabel) {
    if (debounced(form)) { log('lead ignorato (debounce)'); return; }
    var formId = formIdOf(form);
    log('lead [' + (sourceLabel || 'submit') + ']', formId || '(no id)');
    window.atiGa4.trackConfirmedLead({ form_id: formId }, { form_id: formId });
  }

  // Riconosce i form gestiti via AJAX (che fanno preventDefault e hanno un evento di
  // successo dedicato): per questi NON si conta il submit grezzo (eviterebbe i falsi
  // positivi sugli invii falliti); si usa invece l'evento di successo.
  function isAjaxForm(form) {
    if (!form || typeof form.matches !== 'function') return false;
    try {
      return form.matches('.frm-fluent-form, [class*="fluent_form"], .elementor-form, .wpcf7-form, .wpforms-form, .gform_wrapper form, [data-form_id]');
    } catch (e) { return false; }
  }

  // Modalità "invio form come lead" (opt-in): funziona con qualsiasi form, ma conta
  // SOLO gli invii realmente riusciti (eventi di successo del provider), non i tentativi.
  if (CFG.leadOnSubmit) {
    // 1) Fluent Forms: evento ufficiale che scatta SOLO su invio riuscito.
    if (window.jQuery) {
      try {
        window.jQuery(document).on('fluentform_submission_success', function (e, data) {
          var form = (data && data.form && data.form[0]) ? data.form[0] : (e && e.target) || null;
          sendLead(form, 'fluent_success');
        });
      } catch (err) { log('jQuery hook non disponibile'); }
    }

    // 2) Contact Form 7: evento nativo di successo.
    document.addEventListener('wpcf7mailsent', function (e) {
      sendLead(e.target, 'cf7_success');
    }, false);

    // 3) Form nativi (non-AJAX): il submit non viene prevenuto -> invio reale.
    //    Le AJAX form note vengono saltate (gestite dai loro eventi di successo).
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (isAjaxForm(form)) { log('submit AJAX form: atteso evento di successo'); return; }
      if (e.defaultPrevented) { log('submit prevenuto: skip (nessun invio reale)'); return; }
      sendLead(form, 'native_submit');
    }, false);
  }

  // Evento JS personalizzato per i form custom (invio riuscito). Attivo appena è
  // configurato, indipendentemente dalla modalità: è un segnale esplicito di successo.
  // Il form custom deve emettere:
  //   document.dispatchEvent(new CustomEvent('<nome>', { detail: { form_id: '...' } }))
  if (CFG.customSuccessEvent) {
    document.addEventListener(CFG.customSuccessEvent, function (e) {
      var detail = e && e.detail ? e.detail : {};
      var form = detail.form || (e && e.target && e.target.tagName === 'FORM' ? e.target : null);
      // form_id da detail o dal form; niente PII nel payload GA4.
      if (form) {
        sendLead(form, 'custom_event');
      } else {
        // Nessun elemento form: l'evento custom è un segnale esplicito, invio diretto.
        var formId = detail.form_id || detail.formId || '';
        log('lead [custom_event]', formId || '(no id)');
        window.atiGa4.trackConfirmedLead({ form_id: formId }, { form_id: formId });
      }
    }, false);
    log('custom success event attivo:', CFG.customSuccessEvent);
  }

  // Tentativo (OFF di default): NON è una conversione. Non emette generate_lead.
  if (CFG.submitAttempt) {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      var formId = formIdOf(form);
      window.atiGa4.trackSubmitAttempt({ form_id: formId }, { form_id: formId });
    }, true);
  }

  log('ready', CFG.measurementId || '(no id)');
})();
