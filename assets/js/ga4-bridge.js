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
  var GTAG_TIMEOUT_MS = 2000;

  function log() {
    if (DEBUG && window.console) {
      console.log.apply(console, ['[ATI GA4 bridge]'].concat([].slice.call(arguments)));
    }
  }

  // Recupera un campo dal Google Tag con timeout; risolve '' se non disponibile.
  function gtagGet(field) {
    return new Promise(function (resolve) {
      var done = false;
      function finish(val) {
        if (done) return;
        done = true;
        resolve(val || '');
      }
      var timer = setTimeout(function () { finish(''); }, GTAG_TIMEOUT_MS);

      try {
        if (typeof window.gtag === 'function' && CFG.measurementId) {
          window.gtag('get', CFG.measurementId, field, function (value) {
            clearTimeout(timer);
            finish(value);
          });
          return;
        }
      } catch (e) {
        log('gtag get error', field, e);
      }
      clearTimeout(timer);
      finish('');
    });
  }

  // Costruisce il contesto identità/pagina (nessuna PII).
  function buildContext() {
    return Promise.all([gtagGet('client_id'), gtagGet('session_id')]).then(function (vals) {
      return {
        client_id: vals[0] || '',
        session_id: vals[1] || '',
        page_location: window.location.href,
        page_title: document.title,
        page_referrer: document.referrer || '',
        engagement_time_msec: 1
      };
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

  // Tentativo (OFF di default): NON è una conversione. Non emette generate_lead.
  if (CFG.submitAttempt) {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      var formId = (form && (form.id || form.getAttribute('name'))) || '';
      window.atiGa4.trackSubmitAttempt({ form_id: formId }, { form_id: formId });
    }, true);
  }

  log('ready', CFG.measurementId || '(no id)');
})();
