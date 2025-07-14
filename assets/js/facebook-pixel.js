(function(){
  const pixelId = window.atiFbPixelId;
  const debug = !!window.atiFbPixelDebug;
  if(!pixelId){
    console.warn('[FST] Facebook Pixel ID non configurato');
    return;
  }

  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');

  fbq('init', pixelId, {autoConfig:false, debug:debug});
  fbq('set', 'autoConfig', false, pixelId);
  console.log('[FST] \uD83D\uDCD8 Facebook Pixel inizializzato (consenso OK) - ID:', pixelId);
  console.log('[FST] \uD83D\uDCD8 Tracciamento automatico DISABILITATO - solo eventi manuali con eventID');

  window.original_fbq = window.fbq;
  window.fbq = function(action, event, params, options){
    if(action === 'track' && event !== 'PageView'){
      if(!options || !options.eventID){
        console.warn('[FST] \u26A0\uFE0F Evento Facebook bloccato - manca eventID:', event, params);
        return;
      }
    } else if(action === 'track' && event === 'PageView'){
      if(!options || !options.eventID){
        console.warn('[FST] \u26A0\uFE0F PageView Facebook senza eventID - potrebbe causare duplicati');
      }
    }
    return window.original_fbq.apply(this, arguments);
  };
  for (let prop in window.original_fbq){
    if(window.original_fbq.hasOwnProperty(prop)){
      window.fbq[prop]=window.original_fbq[prop];
    }
  }
  console.log('[FST] \uD83D\uDCD8 Facebook Pixel wrapper installato - eventID obbligatorio per tutti gli eventi');
})();

