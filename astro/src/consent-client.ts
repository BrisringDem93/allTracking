import {normalizeConsent, readConsent, privacySignal, record, UUID, CONSENT_MAX_AGE} from './tracking.ts';
import {normalizeOptions} from './options.ts';

const STORE = 'alltracking_consent';
const MAX_AGE = CONSENT_MAX_AGE;
const denied = () => ({necessary:true as const, analytics:false, marketing:false});
type Choice = ReturnType<typeof denied>;
type Saved = Choice & {subjectId:string; receiptId:string; version:string; updatedAt:number; confirmed:boolean};

declare global {
  interface Window {
    allTrackingConsent?: Choice;
    nativeConsent?: ReturnType<typeof createConsent>;
  }
}

/** Necessario: memorizza solo scelta, versione e ricevuta casuale, mai attribuzione. */
export function createConsent(w: Window, version: string, ready = true, consentEndpoint = '/api/consent', active: () => boolean = () => true): {get: () => Choice; receipt: () => {subjectId:string;receiptId:string;version:string} | undefined; set: (value: unknown) => Promise<void>; hasChoice: () => boolean; useCMP: (cmp:string,ga4Id?:string) => void; configure: (version:string,ready:boolean) => void} {
  consentEndpoint = normalizeOptions({consentEndpoint}).consentEndpoint;
  if (w.nativeConsent) return w.nativeConsent;
  let disposed = false;
  const live = () => !disposed && !!w.document && active();
  let cmp='bridge';
  let choice: Choice = denied();
  let saved: Saved | undefined;
  let storageBlocked = false; // Volatile tombstone until replacement or removal is durable.
  const restore = () => {
    if (storageBlocked) return;
    choice = denied(); saved = undefined;
    try {
      const v = record(JSON.parse(w.localStorage.getItem(STORE) || 'null'));
      if (typeof v.updatedAt === 'number' && Date.now()-v.updatedAt >= MAX_AGE) { w.localStorage.removeItem(STORE); return; }
      if (v.version !== version || typeof v.updatedAt !== 'number' || v.updatedAt > Date.now() || Date.now() - v.updatedAt > MAX_AGE
        || typeof v.subjectId !== 'string' || !UUID.test(v.subjectId) || typeof v.receiptId !== 'string' || !UUID.test(v.receiptId)
        || v.necessary !== true || typeof v.analytics !== 'boolean' || typeof v.marketing !== 'boolean') return;
      choice = {necessary:true, ...normalizeConsent(v)};
      saved = {...choice,subjectId:v.subjectId,receiptId:v.receiptId,version,updatedAt:v.updatedAt,confirmed:v.confirmed === true};
    } catch { /* Storage indisponibile o corrotto: diniego. */ }
  };
  const hasChoice = () => !!saved && saved.version === version && saved.updatedAt <= Date.now() && Date.now()-saved.updatedAt < MAX_AGE;
  const get = () => {
    if (!live()) return denied();
    const own=ready && hasChoice() && saved?.confirmed ? {...choice} : denied();
    return cmp === 'bridge' ? own : {necessary:true as const,...readConsent(cmp,w,own)};
  };
  const browser = w as Window & typeof globalThis;
  const loaded = new Set<string>();
  const unlock = () => {
    if (!live()) return; // A queued observer may outlive a closed window.
    for (const placeholder of w.document.querySelectorAll<HTMLScriptElement>('script[type="text/plain"][data-consent-category]')) {
      const c = placeholder.dataset.consentCategory;
      const src = placeholder.dataset.consentSrc || '';
      // Only repository-built local modules. Provider tags remain owned by tracking-client.
      if (!['necessary','analytics','marketing'].includes(c || '') || !get()[c as keyof Choice]
        || !/^\/_astro\/[a-zA-Z0-9_.-]+\.js$/.test(src) || placeholder.textContent?.trim() || placeholder.hasAttribute('src') || loaded.has(src)) continue;
      loaded.add(src);
      const script = w.document.createElement('script');
      script.type = 'module'; script.src = src;
      w.document.head.append(script);
    }
  };
  const observer = new browser.MutationObserver(unlock);
  observer.observe(w.document.documentElement, {childList:true,subtree:true});
  let expiryTimer: number | undefined;
  let cmpTimer: number | undefined;
  let lastCookies = '';
  w.addEventListener('pagehide', () => { disposed=true; observer.disconnect(); w.clearTimeout(expiryTimer); w.clearInterval(cmpTimer); cmpTimer=undefined; });
  const notify = () => {
    if (!live()) return;
    w.clearTimeout(expiryTimer);
    if (saved && hasChoice()) expiryTimer=w.setTimeout(() => { if (live()) { restore(); notify(); } },Math.max(1,Math.min(2147483647,saved.updatedAt+MAX_AGE-Date.now())));
    w.dispatchEvent(new browser.Event('alltracking:consent')); unlock();
  };
  const audit = async (receipt: Saved) => {
    if (!live()) return;
    const status = (status: string) => {
      if (live() && saved?.receiptId === receipt.receiptId) w.dispatchEvent(new browser.CustomEvent('alltracking:audit', {detail:{status,receiptId:receipt.receiptId}}));
    };
    status('saving');
    try {
      const {updatedAt: _time, confirmed: _confirmed, ...body} = receipt;
      const signal = privacySignal(w);
      const response = await w.fetch(consentEndpoint, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,signal}),credentials:'same-origin',referrerPolicy:'no-referrer',redirect:'error',signal:AbortSignal.timeout(4000)});
      const result = record(await response.json()), stored = record(result.receipt);
      const createdAt = typeof stored.createdAt === 'string' ? Date.parse(stored.createdAt) : NaN;
      const valid = response.ok && result.ok === true && stored.subjectId === receipt.subjectId
        && stored.receiptId === receipt.receiptId && stored.version === receipt.version
        && stored.necessary === true && stored.analytics === receipt.analytics && stored.marketing === receipt.marketing
        && ['none','gpc','dnt'].includes(String(stored.signal)) && Number.isFinite(createdAt) && createdAt <= Date.now() && Date.now()-createdAt < MAX_AGE;
      if (!valid) { status('failed'); return; }
      if (!live() || saved?.receiptId !== receipt.receiptId || version !== receipt.version) return;
      saved = {...receipt,updatedAt:createdAt,confirmed:true};
      choice = {necessary:true,...normalizeConsent(saved)};
      try { w.localStorage.setItem(STORE,JSON.stringify(saved)); storageBlocked=false; } catch { /* Confirmed only in this runtime. */ }
      notify(); status('saved');
    } catch { status('failed'); }
  };
  const set = async (value: unknown) => {
    if (!live()) return;
    storageBlocked=true;
    choice = {necessary:true, ...normalizeConsent(active() && ready ? value : null)};
    if (cmp !== 'bridge') choice={necessary:true,...readConsent(cmp,w,choice)};
    try {
      saved = {...choice,subjectId:saved?.subjectId ?? w.crypto.randomUUID(),receiptId:w.crypto.randomUUID(),version,updatedAt:Date.now(),confirmed:false};
    } catch {
      saved=undefined; choice=denied();
      try { w.localStorage.removeItem(STORE); storageBlocked=false; } catch { /* no storage */ }
      notify();
      w.dispatchEvent(new browser.CustomEvent('alltracking:audit',{detail:{status:'failed'}}));
      return;
    }
    try { w.localStorage.setItem(STORE, JSON.stringify(saved)); storageBlocked=false; } catch {
      // A failed replacement must not leave an earlier grant for the next load.
      try { w.localStorage.removeItem(STORE); storageBlocked=false; } catch { /* memory-only choice */ }
    }
    notify();
    await audit(saved);
  };
  const syncCMP = () => {
    if (!live()) return;
    const effective=get();
    if ((choice.analytics && !effective.analytics) || (choice.marketing && !effective.marketing)) void set(effective);
    else notify();
  };
  const cmpCookies = () => {
    try { return w.document.cookie.split(';').map(part=>part.trim()).filter(part=>/^_iub_cs-[\w-]+=/.test(part) && !/^[^=]+-uspr=/.test(part)).join(';'); }
    catch { return ''; }
  };
  const observeCMP = () => {
    if (cmp !== 'iubenda' || !live()) { w.clearInterval(cmpTimer); cmpTimer=undefined; return; }
    if (cmpTimer !== undefined) return;
    lastCookies=cmpCookies();
    // ponytail: one 1s cookie comparison while visible; consent reads remain fresh between ticks.
    cmpTimer=w.setInterval(() => {
      if (!live() || cmp !== 'iubenda' || w.document.visibilityState === 'hidden') return;
      const current=cmpCookies();
      if (current === lastCookies) return;
      lastCookies=current; syncCMP();
    },1000);
  };
  restore();
  Object.defineProperty(w, 'allTrackingConsent', {get, configurable:false});
  const api = {get, set, hasChoice, receipt: () => {
    const effective=get();
    return saved && (effective.analytics || effective.marketing) ? {subjectId:saved.subjectId,receiptId:saved.receiptId,version:saved.version} : undefined;
  }, useCMP: (next:string,_ga4Id='') => {
    if (!live()) return;
    cmp=['cookiebot','iubenda'].includes(next) ? next : 'bridge'; observeCMP(); notify();
  }, configure: (nextVersion:string,nextReady:boolean) => {
    if (!live()) return;
    version=nextVersion; ready=nextReady; restore(); notify();
  }};
  w.nativeConsent = api;
  for (const event of ['CookiebotOnConsentReady','CookiebotOnAccept','CookiebotOnDecline','iubenda:consent']) w.addEventListener(event, () => {
    if (cmp !== (event.startsWith('Cookiebot') ? 'cookiebot' : 'iubenda')) return;
    syncCMP();
  });
  w.addEventListener('focus', () => { if (live()) { restore(); notify(); } });
  w.addEventListener('pageshow', () => { if (live()) { restore(); observer.observe(w.document.documentElement,{childList:true,subtree:true}); notify(); } });
  w.addEventListener('storage', event => {
    if (live() && (event.key === STORE || event.key === null)) { restore(); notify(); }
  });
  notify();
  return api;
}
