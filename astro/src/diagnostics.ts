import {normalizeConfig, normalizeConsent, readConsent, record, type TrackingConfig} from './tracking.ts';

export type DebugSettings = {mode:'off'|'public'; settingsPath?:string};
/** Runtime facts the widget cannot infer from window globals alone. */
export type DiagnosticContext = {guard?:'off'|'monitor'|'enforce'; cmpValid?:boolean; ready?:boolean};
const TABS = ['Consenso','Cookie','Attesi','Blocco','Tag & GA4'];
const KEY = 'alltracking.debug.';
const GUARD_MODES = ['off','monitor','enforce'];

/** Same-origin path only: never forward query strings, credentials or external destinations.
 * Same rule as the integration's local paths (no empty segments, max 200), without dots; linear-time pattern. */
export function safeSettingsPath(value: unknown): string | undefined {
  return typeof value === 'string' && value.length <= 200 && /^\/(?:[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\/?)?$(?![\s\S])/.test(value) ? value : undefined;
}

export function diagnosticSnapshot(w: Window, input: TrackingConfig, context: DiagnosticContext = {}) {
  const config=normalizeConfig(input), scope=record(w), guard=record(scope.allTrackingGuard);
  let consent=normalizeConsent(null);
  try { consent=normalizeConsent(w.nativeConsent ? w.nativeConsent.get() : readConsent(config.cmp,w)); } catch { /* unavailable consent stays denied */ }
  let names:string[]=[];
  // A cookie without "=" is nameless: its text is a value and must never be shown as a name.
  try { names=[...new Set(w.document.cookie.split(';').filter(c=>c.includes('=')).map(c=>c.split('=',1)[0].trim()).filter(Boolean))].sort(); } catch { /* browser denies cookie access */ }
  const cookies=names.map(name=> {
    let result:Record<string,unknown>={};
    try { if (typeof guard.evaluate === 'function') result=record(guard.evaluate(name,'')); } catch { /* unavailable verdict */ }
    return {name, verdict:typeof result.blocked === 'boolean' ? (result.blocked ? 'denied' : 'allowed') : 'unavailable',
      action:['block','delete','block_delete'].includes(String(result.action)) ? String(result.action) : null};
  });
  // ponytail: provider cookies are conditional, not proof of successful provider delivery.
  const expected=[
    {name:'_ga',prefix:true,category:'analytics',configured:!!config.ga4Id},
    {name:'_fbp',prefix:false,category:'marketing',configured:!!config.metaPixelId},
    {name:'_fbc',prefix:false,category:'marketing',configured:!!config.metaPixelId},
  ].map(rule=> {
    const permitted=consent[rule.category as keyof typeof consent];
    const expectation=!config.enabled || !permitted ? 'no' : config.mode === 'gtm' || rule.configured ? 'maybe' : 'no';
    return {name:rule.name,expected:expectation,present:names.filter(n=>rule.prefix ? n === rule.name || n.startsWith(rule.name+'_') : n === rule.name)};
  });
  const forbidden=new Set(cookies.filter(c=>c.verdict === 'denied').map(c=>c.name));
  for (const row of expected) if (row.expected === 'no') for (const name of row.present) forbidden.add(name);
  let stats:Record<string,unknown>={};
  try { if (typeof guard.stats === 'function') stats=record(guard.stats()); } catch { /* unavailable stats */ }
  const count=(key:string)=>typeof stats[key] === 'number' && Number.isFinite(stats[key]) ? Math.max(0,stats[key] as number) : null;
  // The guard API exists only when a mode other than off was installed: off is a configuration, not a failure.
  const guardMode=GUARD_MODES.includes(String(guard.mode)) ? String(guard.mode) : context.guard === 'off' ? 'off' : 'unavailable';
  return {config,consent,cookies,expected,problems:forbidden.size,
    cmp:{configured:context.cmpValid === false ? 'assente/non valido' : config.cmp,native:!!w.nativeConsent,cookiebot:!!scope.Cookiebot,iubenda:!!scope._iub,
      ready:typeof context.ready === 'boolean' ? context.ready : null},
    guard:{mode:guardMode,
      installed:typeof stats.installed === 'boolean' ? stats.installed : null,active:typeof stats.active === 'boolean' ? stats.active : null,
      sweeping:typeof stats.sweeping === 'boolean' ? stats.sweeping : null,errors:Array.isArray(stats.errors) ? stats.errors.length : null,
      blocked:count('blocked'),deleted:count('deleted')},
    tags:{dataLayer:Array.isArray(scope.dataLayer) ? scope.dataLayer.length : null,gtag:typeof scope.gtag === 'function',fbq:typeof scope.fbq === 'function'},
    backend:{snapshot:'unavailable',queue:'unavailable',pipeline:'unavailable'},
  };
}

/** Read-only diagnostics; explicit public mode is for staging, not an authorization mechanism. */
export function mountDiagnostics(w: Window, settings: DebugSettings, config: TrackingConfig, context: DiagnosticContext = {}): {destroy():void} | undefined {
  if (settings.mode !== 'public' || !w.document.body || w.document.querySelector('[data-alltracking-debug]')) return;
  const d=w.document, root=d.createElement('section'); root.dataset.alltrackingDebug=''; root.setAttribute('aria-label','Diagnostica tracking');
  const cleanups:Array<()=>void>=[]; let alive=true;
  const on=(target:EventTarget,type:string,fn:EventListener,options?:boolean)=>{target.addEventListener(type,fn,options);cleanups.push(()=>target.removeEventListener(type,fn,options));};
  const store=(key:string,value?:string)=>{try {if(value !== undefined) w.localStorage.setItem(KEY+key,value); return w.localStorage.getItem(KEY+key);}catch{return null;}};
  const el=<K extends keyof HTMLElementTagNameMap>(tag:K,text='',parent:HTMLElement=root)=>{const node=d.createElement(tag);node.textContent=text;parent.append(node);return node;};
  const button=(text:string,parent:HTMLElement)=>{const node=el('button',text,parent);node.type='button';return node;};
  const style=el('style'); style.textContent=`[data-alltracking-debug]{position:fixed;z-index:2147483000;right:8px;bottom:8px;max-width:calc(100vw - 16px);color:#eef2f6;background:#151b23;border:1px solid #69788a;border-radius:8px;font:13px/1.5 system-ui;box-shadow:0 8px 30px #0006;box-sizing:border-box}[data-alltracking-debug] *{box-sizing:border-box}[data-alltracking-debug] button,[data-alltracking-debug] a{font:inherit;color:inherit;background:#253140;border:1px solid #8191a4;border-radius:4px;padding:6px 9px;cursor:pointer}[data-alltracking-debug] :focus-visible{outline:3px solid #81c7ff;outline-offset:2px}[data-debug-panel]{width:540px;max-width:calc(100vw - 16px);max-height:calc(100dvh - 70px);overflow:auto;padding:10px}[data-debug-head]{display:flex;gap:6px;flex-wrap:wrap;align-items:center;cursor:move;touch-action:none}[data-debug-tabs]{display:flex;gap:4px;flex-wrap:wrap;margin-block:10px}[data-debug-tabs] [aria-selected=true]{background:#435e80}[data-debug-body]{max-height:45dvh;overflow:auto;overflow-wrap:anywhere;white-space:pre-wrap}[data-debug-toggle]{touch-action:none}[data-alltracking-debug] [hidden]{display:none!important}[data-debug-warning]{color:#ffdb8b}`;
  const toggle=button('Tracking debug ',root); toggle.dataset.debugToggle=''; toggle.setAttribute('aria-controls','alltracking-debug-panel');
  const badge=el('span','',toggle); badge.dataset.debugBadge='';
  const panel=el('div');panel.dataset.debugPanel='';panel.id='alltracking-debug-panel';panel.hidden=true;
  const head=el('div','',panel);head.dataset.debugHead='';el('strong','Tracking debug',head);
  const refreshButton=button('Aggiorna',head), copyButton=button('Copia JSON',head), resetButton=button('Reimposta posizione',head), closeButton=button('Chiudi',head);
  const path=safeSettingsPath(settings.settingsPath);
  if(path){const a=el('a','Impostazioni',head);a.href=path;a.dataset.debugSettings='';}
  el('p','Modalità pubblica: visibile a tutti. SOLO STAGING / TEST, non produzione.',panel).dataset.debugWarning='';
  const tabs=el('div','',panel);tabs.dataset.debugTabs='';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Diagnostica');
  let current=Math.max(0,TABS.indexOf(store('tab') || ''));
  const body=el('div','',panel);body.dataset.debugBody='';body.setAttribute('role','tabpanel');body.id='alltracking-debug-body';body.tabIndex=0;
  const tabButtons=TABS.map((name,i)=>{const b=button(name,tabs);b.setAttribute('role','tab');b.id=`alltracking-debug-tab-${i}`;b.setAttribute('aria-controls',body.id);return b;});
  const snapshot=()=>diagnosticSnapshot(w,config,context);
  const render=()=>{
    if(!alive)return;
    const s=snapshot(),g=s.guard;
    const warning=g.mode === 'unavailable' ? 'Guard: stato non disponibile.'
      : g.mode !== 'off' && (g.installed !== true || g.active !== true) ? (g.mode === 'enforce' ? 'Enforcement richiesta ma non attiva.' : 'Monitor richiesto ma non attivo.')
      : g.errors === null ? (g.mode === 'off' ? '' : 'Guard: stato non disponibile.') : g.errors > 0 ? 'Guard: errori registrati.' : '';
    const status=g.mode === 'off' ? 'Disattivato intenzionalmente.' : g.active === true && g.installed === true ? (g.mode === 'monitor' ? 'Monitor attivo: osserva soltanto, non blocca.' : 'Enforcement attiva.') : '';
    badge.textContent=warning ? '!' : s.problems ? String(s.problems) : g.mode === 'off' || g.mode === 'monitor' ? g.mode : 'ok';
    badge.setAttribute('aria-label',`${s.problems} problemi cookie; Guard: ${warning || g.mode+'. '+status}`);
    tabButtons.forEach((b,i)=>{b.setAttribute('aria-selected',String(i===current));b.tabIndex=i===current ? 0 : -1;});body.setAttribute('aria-labelledby',tabButtons[current].id);
    const grant=(v:boolean)=>v?'concesso':'negato';
    const lines=[
      `Consenso live browser: analytics ${grant(s.consent.analytics)}, marketing ${grant(s.consent.marketing)}.\nCMP configurato: ${s.cmp.configured}; native ${s.cmp.native}; Cookiebot ${s.cmp.cookiebot}; iubenda ${s.cmp.iubenda}.\nConsenso opzionale abilitabile (CMP e policy validi): ${s.cmp.ready === null ? 'non disponibile' : s.cmp.ready ? 'sì' : 'no — consenso negato finché config/policy non sono validi'}.\nSnapshot server: non disponibile.\nPreferences: non supportate dal banner nativo; negate senza host verificato.`,
      `Solo nomi dei cookie JavaScript di questa pagina; HttpOnly/altri domini non visibili.\n${s.cookies.map(c=>`${c.name}: ${c.verdict}${c.action ? ' / '+c.action : ''}`).join('\n') || 'Nessun cookie visibile.'}`,
      `${s.expected.map(e=>`${e.name}: atteso ${e.expected === 'maybe'?'dipende (tag, browser, adblock)':e.expected}; presenti ${e.present.join(', ') || 'nessuno'}`).join('\n')}\nGTM: attese condizionali. fst_uid/fst_clid non attesi: attribuzione e identità in memoria, nessun cookie persistente del pacchetto.`,
      `Guard: ${g.mode} (modalità richiesta).\n${warning ? 'ATTENZIONE: '+warning+'\n' : ''}${status}\nInstallato: ${g.installed ?? 'non disponibile'}; attivo: ${g.active ?? 'non disponibile'}; sweep: ${g.sweeping ?? 'non disponibile'}; errori: ${g.errors ?? 'non disponibile'}.\nBloccati: ${g.blocked ?? 'non disponibile'}; cancellati: ${g.deleted ?? 'non disponibile'}.\nMonitor osserva soltanto; nessuna enforcement installata dal widget.\nPreferences: negate senza supporto host verificato.`,
      `Configurazione: ${s.config.enabled?'attiva':'disattiva'} / ${s.config.mode}\nGTM ${s.config.gtmId || '—'}; GA4 ${s.config.ga4Id || '—'}; Meta ${s.config.metaPixelId || '—'}.\ndataLayer: ${s.tags.dataLayer ?? 'non disponibile'} elementi; gtag: ${s.tags.gtag}; fbq: ${s.tags.fbq}.\nFlag server configurati (non prova di consegna): N8n ${s.config.serverN8n}, Meta ${s.config.serverMeta}, GA4 ${s.config.serverGA4}.\nBackend, queue/cron, pipeline e consegna: non disponibili nel pacchetto.`,
    ];
    // Rewriting identical text every poll would collapse the user's selection and spam assistive tech.
    if(body.textContent!==lines[current]){const scroll=body.scrollTop;body.textContent=lines[current];body.scrollTop=scroll;}
  };
  const clamp=()=>{if(root.style.left)place(parseFloat(root.style.left),parseFloat(root.style.top));};
  const place=(x:number,y:number)=>{const r=root.getBoundingClientRect();root.style.left=Math.round(Math.max(8,Math.min(x,Math.max(8,w.innerWidth-r.width-8))))+'px';root.style.top=Math.round(Math.max(8,Math.min(y,Math.max(8,w.innerHeight-r.height-8))))+'px';root.style.right=root.style.bottom='auto';};
  const open=(yes:boolean)=>{panel.hidden=!yes;toggle.setAttribute('aria-expanded',String(yes));store('open',yes?'1':'0');render();clamp();};
  on(toggle,'click',()=>open(panel.hidden === true));on(closeButton,'click',()=>{open(false);toggle.focus();});on(refreshButton,'click',render);
  const reset=()=>{root.style.left=root.style.top=root.style.right=root.style.bottom='';store('pos','');};
  on(resetButton,'click',reset);on(head,'dblclick',event=>{if(!(event.target as Element).closest('button,a'))reset();});
  tabButtons.forEach((b,i)=>{on(b,'click',()=>{current=i;store('tab',TABS[i]);render();});on(b,'keydown',event=>{const e=event as KeyboardEvent;let next=i;if(e.key==='ArrowRight')next=(i+1)%5;else if(e.key==='ArrowLeft')next=(i+4)%5;else if(e.key==='Home')next=0;else if(e.key==='End')next=4;else return;e.preventDefault();tabButtons[next].click();tabButtons[next].focus();});});
  on(root,'keydown',event=>{if((event as KeyboardEvent).key==='Escape'){open(false);toggle.focus();}});
  on(copyButton,'click',async()=>{
    try {await w.navigator.clipboard.writeText(JSON.stringify(snapshot(),null,2));if(alive)copyButton.textContent='JSON copiato';}
    catch {if(alive)copyButton.textContent='Copia non disponibile';}
  });
  for(const handle of [head,toggle]){
    let start:{x:number;y:number;px:number;py:number;id:number}|undefined,moved=false;
    // Touch/pen drags emit no trailing click, so a stale "moved" would swallow the next tap: reset on every press.
    on(handle,'pointerdown',event=>{const e=event as PointerEvent;moved=false;if(e.button!==0 || (handle===head && (e.target as Element).closest('button,a')))return;const r=root.getBoundingClientRect();start={x:r.left,y:r.top,px:e.clientX,py:e.clientY,id:e.pointerId};try{handle.setPointerCapture(e.pointerId);}catch{/* no capture */}});
    on(handle,'pointermove',event=>{const e=event as PointerEvent;if(!start || e.pointerId!==start.id)return;const dx=e.clientX-start.px,dy=e.clientY-start.py;if(!moved && Math.abs(dx)<4 && Math.abs(dy)<4)return;moved=true;place(start.x+dx,start.y+dy);e.preventDefault();});
    // The drag-end click (mouse) arrives in the same task; clear afterwards so a later keyboard activation is not swallowed.
    const end=()=>{if(!start)return;start=undefined;if(moved){store('pos',JSON.stringify({x:parseFloat(root.style.left),y:parseFloat(root.style.top)}));w.setTimeout(()=>{moved=false;},0);}};
    on(handle,'pointerup',end);on(handle,'pointercancel',end);on(handle,'click',e=>{if(moved){moved=false;e.preventDefault();e.stopImmediatePropagation();}},true);
  }
  d.body.append(root);
  try{const p=record(JSON.parse(store('pos') || 'null'));if(typeof p.x==='number' && Number.isFinite(p.x) && typeof p.y==='number' && Number.isFinite(p.y))place(p.x,p.y);}catch{/* ignore corrupted position */}
  open(store('open')==='1');
  on(w,'resize',clamp);
  for(const name of ['alltracking:consent','alltracking:audit','CookiebotOnConsentReady','CookiebotOnAccept','CookiebotOnDecline','iubenda:consent','focus','storage'])on(w,name,render);
  const interval=w.setInterval(render,1000);
  const destroy=()=>{if(!alive)return;alive=false;w.clearInterval(interval);cleanups.forEach(fn=>fn());root.remove();};
  on(w,'pagehide',destroy);
  return {destroy};
}
