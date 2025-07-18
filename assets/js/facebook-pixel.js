(function () {
  const pixelId = window.atiFbPixelId;
  const debug = !!window.atiFbPixelDebug;

  if (!pixelId) {
    if (debug) {
      console.warn('[FST] Facebook Pixel ID non configurato');
    }
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
   if (debug) {
    console.log('[FST] 📘 Facebook Pixel inizializzato (consenso OK) - ID:', pixelId);
    console.log('[FST] 📘 Tracciamento automatico DISABILITATO - solo eventi manuali con eventID');
  }

  // // Blocca gli eventi privi di eventID per evitare duplicazioni
  // const realFbq = window.fbq;
  // function guardedFbq() {
  //   const cmd = arguments[0];
  //   if ((cmd === 'track' || cmd === 'trackCustom') && (!arguments[3] || !arguments[3].eventID)) {
  //     if (debug) {
  //       console.warn('[FST] Evento bloccato - eventID mancante per', arguments[1]);
  //     }
  //     return;
  //   }
  //   return realFbq.apply(this, arguments);
  // }
  // guardedFbq.callMethod = realFbq.callMethod;
  // guardedFbq.queue = realFbq.queue;
  // guardedFbq.loaded = realFbq.loaded;
  // guardedFbq.version = realFbq.version;
  // guardedFbq.push = realFbq.push.bind(realFbq);
  // window.fbq = guardedFbq;

})();
