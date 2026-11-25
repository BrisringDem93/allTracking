import {normalizeGuard} from './guard-config.ts';
import type {CookieRule,GuardOptions,NormalizedGuard} from './guard-config.ts';

/** Caller must supply receipt-confirmed consent. No CMP/global/storage inference.
 * Native Astro has two categories: preferences stays denied unless explicitly confirmed by the host. */
export type GuardConsent = {analytics:boolean; marketing:boolean; preferences?:boolean};
export type CookieEvaluation = {blocked:boolean;action:CookieRule['action'] | '';reason:'no_match'|'empty_name'|'allowlist'|'consent_granted'|'blocked';rule:number|null;label:string;category:CookieRule['category']|''};
const denied = (): GuardConsent => ({analytics:false,marketing:false});
const wildcard = (p:string,ci=false) => new RegExp('^'+p.replace(/[.*+?^${}()|[\]\\]/g,'\\$&').replace(/\\\*/g,'.*').replace(/\\\?/g,'.')+'$',ci?'i':'');
function matches(r:CookieRule,name:string,domain:string): boolean {
  const s=r.ci?name.toLowerCase():name, v=r.ci?r.value.toLowerCase():r.value, v2=r.ci?(r.value2??'').toLowerCase():r.value2??'';
  switch(r.match) {
    case 'equals': return s===v;
    case 'contains': return s.includes(v);
    case 'starts_with': return s.startsWith(v);
    case 'ends_with': return s.endsWith(v);
    case 'starts_ends': return s.startsWith(v) && (!v2 || (s.length>=v.length+v2.length && s.endsWith(v2)));
    case 'wildcard': return wildcard(r.value,r.ci).test(name);
    case 'regex': return new RegExp(r.value,r.ci?'i':'').test(name);
    case 'domain': {
      const candidate=r.value.trim().toLowerCase().replace(/^\.+/,''), host=domain.trim().toLowerCase().replace(/^\.+/,'');
      return !!candidate && (host===candidate || host.endsWith('.'+candidate));
    }
  }
}
function evaluate(name:string,domain:string,consent:GuardConsent,options:NormalizedGuard): CookieEvaluation {
  const out:CookieEvaluation={blocked:false,action:'',reason:'no_match',rule:null,label:'',category:''};
  if (!name) return {...out,reason:'empty_name'};
  let decoded=name; try { decoded=decodeURIComponent(name); } catch { /* Preserve malformed raw names. */ }
  const variants=[...new Set([name,decoded])];
  // Safety applies to the actual cookie identity before either matching pass.
  if (variants.some(n=>options.allowlist.some(p=>wildcard(p,true).test(n)))) return {...out,reason:'allowlist'};
  for (const variant of variants) {
    for (const [i,r] of options.rules.entries()) {
      if (!r.enabled || !matches(r,variant,domain)) continue;
      Object.assign(out,{action:r.action,rule:i,label:r.label,category:r.category});
      if (r.category!=='always' && consent?.[r.category]===true) { out.reason='consent_granted'; continue; }
      return {...out,reason:'blocked',blocked:true};
    }
  }
  return out;
}
/** Pure evaluator shared by enforcement and diagnostic/settings previews, including raw/decoded names. */
export function evaluateCookie(name:string,domain='',consent:GuardConsent=denied(),options:GuardOptions={}): CookieEvaluation {
  return evaluate(name,domain,consent,normalizeGuard(options));
}

export type GuardStats = {
  blocked:number; deleted:number; wouldBlock:number; wouldDelete:number; deleteAttempts:number;
  installed:boolean; active:boolean; sweeping:boolean; mode:NormalizedGuard['mode']; errors:string[];
};
export interface GuardAPI {
  readonly mode:NormalizedGuard['mode'];
  evaluate(name:string,domain?:string):CookieEvaluation;
  cookies():string[];
  consent():GuardConsent;
  stats():GuardStats;
  refresh():void;
  sweep():void;
  destroy():void;
}
declare global { interface Window { allTrackingGuard?:GuardAPI; } }

export function installCookieGuard(w:Window,options:GuardOptions={},getConsent:()=>GuardConsent=denied,canPurge:()=>boolean=()=>true): GuardAPI {
  if (w.allTrackingGuard) return w.allTrackingGuard;
  const config=normalizeGuard(options), doc=w.document, own=Object.getOwnPropertyDescriptor(doc,'cookie');
  let native=own, prototype=Object.getPrototypeOf(doc);
  while (!native && prototype) { native=Object.getOwnPropertyDescriptor(prototype,'cookie'); prototype=Object.getPrototypeOf(prototype); }
  const state:GuardStats={mode:config.mode,blocked:0,deleted:0,wouldBlock:0,wouldDelete:0,deleteAttempts:0,installed:false,active:false,sweeping:false,errors:[]};
  let disposed=false, readFailed=false, interval:number|undefined, timeout:number|undefined;
  const error=(message:string) => { state.errors.push(message); if(state.errors.length>20) state.errors.shift(); };
  const raw=():string => { readFailed=false; try { return String(native?.get ? native.get.call(doc) : doc.cookie); } catch { readFailed=true;error('Cookie inventory unavailable'); return ''; } };
  const cookies=() => [...new Set(raw().split(';').map(p=>p.split('=',1)[0].trim()).filter(Boolean))];
  const consent=():GuardConsent => {
    try { const c=getConsent(); return {analytics:c?.analytics===true,marketing:c?.marketing===true,preferences:c?.preferences===true}; }
    catch { error('Consent callback failed; denied'); return denied(); }
  };
  const verdict=(name:string,domain='') => evaluate(name,domain,consent(),config);
  const paths=new Set(['/']);
  let path='';
  for (const segment of w.location.pathname.split('/').filter(Boolean).slice(0,6)) { path+='/'+segment; paths.add(path); paths.add(path+'/'); }
  const domains=new Set(['']);
  const host=w.location.hostname.toLowerCase();
  if (host && !/^[\d.]+$/.test(host) && !host.includes(':')) {
    const labels=host.split('.');
    for(let i=0;i<=labels.length-2;i++) { const d=labels.slice(i).join('.'); domains.add(d); domains.add('.'+d); }
  }
  const nativeSet=(value:string) => native!.set!.call(doc,value);
  const sweep=() => {
    if (!state.active || disposed || (config.mode==='enforce' && !canPurge())) return;
    for (const name of cookies()) {
      // document.cookie does not expose Domain metadata: domain-only rules cannot purge unknown origins.
      const ev=verdict(name);
      if (!ev.blocked || ev.action==='block') continue;
      if(config.mode==='monitor') { state.wouldDelete++; continue; }
      for (const p of paths) for (const d of domains) {
        const value=`${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=${p}${d?`; domain=${d}`:''}${w.location.protocol==='https:'?'; Secure':''}`;
        state.deleteAttempts++;
        try { nativeSet(value); } catch { error('Cookie deletion write failed'); }
      }
      if (!cookies().includes(name) && !readFailed) state.deleted++;
      else error('Cookie still visible after deletion attempts');
    }
  };
  const getter=() => raw();
  const setter=(value:string) => {
    if (disposed) { nativeSet(value); return; }
    const text=String(value), parts=text.split(';'), first=parts.shift()??'', eq=first.indexOf('='), name=(eq<0?first:first.slice(0,eq)).trim();
    const attrs=new Map<string,string>();
    let maxAge:string|undefined;
    for (const p of parts) {
      const i=p.indexOf('='), key=(i<0?p:p.slice(0,i)).trim().toLowerCase(), v=i<0?'':p.slice(i+1).trim();
      attrs.set(key,v);
      // RFC 6265: an invalid Max-Age is ignored, so the last VALID one wins.
      if (key==='max-age' && /^-?\d+$/.test(v)) maxAge=v;
    }
    let ev=verdict(name,attrs.get('domain')??'');
    // "=_ga=1" has an empty name, but browsers serialize it as "_ga=1": judge the visible key too.
    if (eq===0 && !ev.blocked) {
      const visible=first.slice(1).split('=',1)[0].trim();
      if (visible) ev=verdict(visible,attrs.get('domain')??'');
    }
    // Max-Age takes precedence over Expires, as it does in native cookie storage.
    const expiring=maxAge!==undefined?Number(maxAge)<=0:Date.parse(attrs.get('expires')??'')<=Date.now();
    if (expiring) {
      // Deletions stay allowed, but Date.parse accepts dates cookie parsers reject (e.g. "expires=0"
      // becomes a session cookie): a blocked name may only ever be deleted, whatever the browser parses.
      nativeSet(ev.blocked && ev.action!=='delete' && config.mode==='enforce' ? `${text}; Max-Age=0` : text);
      return;
    }
    if (ev.blocked && ev.action!=='delete') {
      if(config.mode==='enforce') {state.blocked++;return;}
      state.wouldBlock++;
    }
    const writtenPath=attrs.get('path');
    if(writtenPath?.startsWith('/') && paths.size<64) paths.add(writtenPath);
    nativeSet(text);
  };
  let replacedReported=false;
  // Read-only: reporting a replaced setter must not switch off sweeps, which use the native
  // descriptor directly and keep purging/revoking whether or not diagnostics looked.
  const stats=():GuardStats => {
    const current=Object.getOwnPropertyDescriptor(doc,'cookie');
    const replaced=state.installed && (current?.get!==getter || current?.set!==setter);
    if(replaced && !replacedReported) { replacedReported=true; error('Cookie setter replaced by another script; writes may bypass blocking'); }
    return {...state,installed:state.installed && !replaced,errors:[...state.errors]};
  };
  const api:GuardAPI={mode:config.mode,evaluate:verdict,cookies,consent,stats,refresh:sweep,sweep,destroy(){
    if(disposed) return;
    disposed=true;state.active=false;state.sweeping=false;
    if(interval!==undefined) w.clearInterval(interval);
    if(timeout!==undefined) w.clearTimeout(timeout);
    w.removeEventListener('alltracking:consent',sweep);doc.removeEventListener('alltracking:consent',sweep);
    w.removeEventListener('load',sweep);doc.removeEventListener('DOMContentLoaded',sweep);
    const current=Object.getOwnPropertyDescriptor(doc,'cookie');
    if (current?.get===getter && current?.set===setter) {
      try { if(own) Object.defineProperty(doc,'cookie',own); else delete (doc as unknown as {cookie?:string}).cookie; }
      catch { error('Cookie descriptor restoration failed'); }
    }
    state.installed=false;
    if(w.allTrackingGuard===api) delete w.allTrackingGuard;
  }};
  w.allTrackingGuard=api;
  if(config.mode==='off') return api;
  if(!native?.get || !native.set || own?.configurable===false) { error('Cookie setter unavailable or nonconfigurable'); return api; }
  try { Object.defineProperty(doc,'cookie',{configurable:true,enumerable:native.enumerable??true,get:getter,set:setter}); }
  catch { error('Cookie setter installation failed'); return api; }
  state.installed=true;state.active=true;
  w.addEventListener('alltracking:consent',sweep);doc.addEventListener('alltracking:consent',sweep);
  w.addEventListener('load',sweep);doc.addEventListener('DOMContentLoaded',sweep);
  sweep();
  if(config.sweepDuration>0) {
    state.sweeping=true;
    interval=w.setInterval(sweep,config.sweepInterval);
    timeout=w.setTimeout(()=>{w.clearInterval(interval);state.sweeping=false;},config.sweepDuration);
  }
  return api;
}
