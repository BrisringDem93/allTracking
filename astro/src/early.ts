import {normalizeOptions, type Options} from './options.ts';
import {installCookieGuard} from './cookie-guard.ts';

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
  const stop=()=>{
    stopped=true;
    guard?.destroy();
    w.document.removeEventListener('astro:before-preparation',stop,true);
    w.removeEventListener('pagehide',stop,true);
    w.removeEventListener('pageshow',restore,true);
  };
  const restore=(event:PageTransitionEvent)=>{if(event.persisted)stop();};
  const state={active,stop};
  w.allTrackingEarly=state;
  // Retain existing cookies until current policy validates the native receipt.
  // New writes remain denied; runtime finishes restoration/denial before its first purge.
  if(active()) w.allTrackingConsentReady=false;
  const guard=active() && config.guard.mode !== 'off'
    ? installCookieGuard(w,config.guard,()=>active() ? w.nativeConsent?.get() ?? {analytics:false,marketing:false} : {analytics:false,marketing:false},()=>w.allTrackingConsentReady===true)
    : undefined;
  if(guard){
    // Departure is not withdrawal: restore the setter BEFORE runtime broadcasts denial.
    w.document.addEventListener('astro:before-preparation',stop,true);
    w.addEventListener('pagehide',stop,true);
    w.addEventListener('pageshow',restore,true);
  }
  return state;
}