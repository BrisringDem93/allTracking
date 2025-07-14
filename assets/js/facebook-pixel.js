(function () {
  const pixelId = window.atiFbPixelId;
  const debug = !!window.atiFbPixelDebug;

  if (!pixelId) {
    console.warn('[FST] Facebook Pixel ID non configurato');
    return;
  }

  // Carica lo script di Facebook Pixel
  !function (f, b, e, v, n, t, s) {
    if (f.fbq) return;
    n = f.fbq = function () {
      n.callMethod ?
        n.callMethod.apply(n, arguments) : n.queue.push(arguments)
    };
    if (!f._fbq) f._fbq = n;
    n.push = n;
    n.loaded = !0;
    n.version = '2.0';
    n.queue = [];
    t = b.createElement(e);
    t.async = !0;
    t.src = v;
    s = b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t, s);
  }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

  fbq('init', pixelId, { autoConfig: false, debug: debug });
  fbq('set', 'autoConfig', false, pixelId);
  console.log('[FST] 📘 Facebook Pixel inizializzato (consenso OK) - ID:', pixelId);
  console.log('[FST] 📘 Tracciamento automatico DISABILITATO - solo eventi manuali con eventID');

  // 🔐 Salva una copia del fbq PRIMA di wrappare
  const rawFbq = window.fbq;

  // Wrappa fbq per bloccare eventi senza eventID
  window.fbq = function (action, event, params, options) {
    if (action === 'track') {
      if (!options || !options.eventID) {
        console.warn('[FST] ❌ Evento Facebook bloccato - manca eventID:', event, params);
        return; // Blocca l'invio
      }
    }
    return rawFbq.apply(this, arguments);
  };

  // Copia le proprietà
  for (let prop in rawFbq) {
    if (rawFbq.hasOwnProperty(prop)) {
      window.fbq[prop] = rawFbq[prop];
    }
  }

  console.log('[FST] 📘 Facebook Pixel wrapper installato - eventID obbligatorio per tutti gli eventi');
})();
