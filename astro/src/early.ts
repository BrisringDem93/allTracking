import {normalizeOptions, type Options} from './options.ts';
import {installCookieGuard, type GuardConsent} from './cookie-guard.ts';

declare global {
  interface Window { allTrackingEarly?: {active():boolean; stop():void}; allTrackingConsentReady?:boolean; }
}

/** Runs synchronously in head; the page module reuses this document-owned state. */
export function startEarlyTracking(w:Window, options:Options = {}) {
  if (w.allTrackingEarly) return w.allTrackingEarly;
  const config=normalizeOptions(options), initialPath=w.location.pathname;
  let stopped=false;
  const active=()=>{
    if(stopped || !w.document || config.disabled || w.location.pathname !== initialPath || w.document.documentElement.dataset.runtime === 'off') return false;
    try {
      const url=new URL(decodeURIComponent(w.location.pathname),w.location.origin);
      if(url.origin !== w.location.origin) return false;
      return !config.exclude.some(value => value === '/' || url.pathname === value.replace(/\/$/,'') || url.pathname.startsWith(value.replace(/\/$/,'')+'/'));
    } catch { return false; }
  };
  const denied=():GuardConsent=>({analytics:false,marketing:false});
  const live=():GuardConsent=>active() ? w.nativeConsent?.get() ?? denied() : denied();
  let frozen:GuardConsent|undefined;
  const stop=()=>{
    if(stopped) return;
    // Departure is not withdrawal: freeze the consent in force when the page is left.
    // The setter stays installed so unload-time writers remain gated, while the
    // purge gate below refuses every sweep, so the runtime's departure broadcast
    // never deletes consented cookies.
    try { frozen=live(); } catch { frozen=denied(); }
    stopped=true;
    w.removeEventListener('pagehide',stop,true);
    w.removeEventListener('pageshow',restore,true);
  };
  const restore=(event:PageTransitionEvent)=>{if(event.persisted)stop();};
  const state={active,stop};
  w.allTrackingEarly=state;
  // Retain existing cookies until current policy validates the native receipt.
  // New writes remain denied; runtime finishes restoration/denial before its first purge.
  if(active()) w.allTrackingConsentReady=false;
  // Purge only while this document is still the active one: after departure or a
  // pathname change the consent seen here is not a withdrawal.
  const guard=active() && config.guard.mode !== 'off'
    ? installCookieGuard(w,config.guard,()=>frozen ?? live(),()=>w.allTrackingConsentReady===true && active())
    : undefined;
  if(guard){
    // Capture listeners on the target run first, before consent/runtime teardown.
    w.addEventListener('pagehide',stop,true);
    w.addEventListener('pageshow',restore,true);
  }
  return state;
}