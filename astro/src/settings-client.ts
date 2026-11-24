import {normalizeConfig, record} from './tracking.ts';
import {normalizeGuard, defaultRules, PROTECTED_PATTERNS, type CookieRule} from './guard-config.ts';
import {evaluateCookie} from './cookie-guard.ts';
import {safeSettingsPath} from './diagnostics.ts';

// normalizeGuard speaks English with 0-based indexes; the editor is Italian with a 1-based <ol>.
const GUARD_ERRORS: Array<[RegExp,(match:RegExpMatchArray)=>string]> = [
  [/rules\[(\d+)\]\.regex$/, m=>`Regola ${Number(m[1])+1}: espressione regolare non valida`],
  [/rules\[(\d+)\]$/, m=>`Regola ${Number(m[1])+1}: campi non validi (il pattern è obbligatorio)`],
  [/rules$/, ()=>'Regole non valide'],
  [/mode$/, ()=>'Modalità guard non valida'],
  [/allowlist$/, ()=>'Allowlist: un pattern per riga, senza spazi, «;» o «=»'],
  [/sweepInterval$/, ()=>'Intervallo passate: numero intero tra 250 e 2147483647 ms'],
  [/sweepDuration$/, ()=>'Durata passate: numero intero tra 0 e 2147483647 ms'],
];
function guardSettings(value: unknown) {
  let guard: ReturnType<typeof normalizeGuard>;
  try { guard=normalizeGuard(value as Parameters<typeof normalizeGuard>[0]); }
  catch(error) {
    const message=error instanceof Error ? error.message : '';
    for(const [pattern,text] of GUARD_ERRORS){const match=message.match(pattern);if(match)throw Error(text(match));}
    throw Error('Configurazione guard non valida');
  }
  // Protected CMP/session patterns are always re-added by the integration: expose only custom entries.
  return {...guard,allowlist:guard.allowlist.filter(pattern=>!PROTECTED_PATTERNS.includes(pattern))};
}

/** Public projection and validation only; never accepts backend credentials. */
export function validateSettings(value: unknown) {
  const v=record(value), tracking=record(v.tracking), debug=record(v.debug);
  for(const [key,pattern,label] of [['gtmId',/^GTM-[A-Z0-9]{4,20}$/,'GTM'],['ga4Id',/^G-[A-Z0-9]{4,20}$/,'GA4'],['metaPixelId',/^\d{5,25}$/,'Meta']] as const){
    if(tracking[key] !== undefined && (typeof tracking[key] !== 'string' || (tracking[key] !== '' && !pattern.test(tracking[key] as string))))throw Error(`${label}: ID non valido`);
  }
  for(const key of ['enabled','attribution','formEvents','serverN8n','serverMeta','serverGA4'])if(tracking[key]!==undefined && typeof tracking[key]!=='boolean')throw Error(`${key}: booleano richiesto`);
  if(tracking.mode!==undefined && (typeof tracking.mode!=='string' || !['off','gtm','direct'].includes(tracking.mode)))throw Error('Modalità tracking non valida');
  if(tracking.cmp!==undefined && (typeof tracking.cmp!=='string' || !['bridge','cookiebot','iubenda'].includes(tracking.cmp)))throw Error('CMP non supportato');
  if(debug.mode!==undefined && debug.mode!=='off' && debug.mode!=='public')throw Error('Modalità diagnostica non valida');
  const settingsPath=safeSettingsPath(debug.settingsPath);
  if(debug.settingsPath!==undefined && debug.settingsPath!=='' && !settingsPath)throw Error('Impostazioni: usare un percorso locale senza query, punti o segmenti vuoti (max 200 caratteri)');
  return {tracking:normalizeConfig(tracking),guard:guardSettings(v.guard),debug:{mode:debug.mode==='public' ? 'public' as const : 'off' as const,...(settingsPath ? {settingsPath} : {})}};
}

export function mountSettings(w: Window) {
  const browser=w as Window & typeof globalThis;
  const root=w.document.querySelector<HTMLElement>('[data-tracking-settings]');
  if(!root || root.dataset.mounted)return;
  root.dataset.mounted='true';
  const initial=validateSettings(JSON.parse(root.dataset.config || '{}'));
  const form=root.querySelector<HTMLFormElement>('form')!, list=root.querySelector<HTMLElement>('[data-rules]')!, status=root.querySelector<HTMLElement>('[role=status]')!;
  const cleanups:Array<()=>void>=[];let alive=true;
  const on=(target:EventTarget,event:string,fn:EventListener)=>{target.addEventListener(event,fn);cleanups.push(()=>target.removeEventListener(event,fn));};
  const control=(name:string)=>form.elements.namedItem(name) as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
  const text=(name:string)=>control(name).value;
  const checked=(name:string)=>(control(name) as HTMLInputElement).checked;
  for(const [group,values] of Object.entries(initial))for(const [name,value]of Object.entries(values)){
    const node=form.elements.namedItem(`${group}.${name}`) as HTMLInputElement | null;if(!node)continue;
    if(node.type==='checkbox')node.checked=value===true;else node.value=Array.isArray(value) ? value.join('\n') : String(value);
  }
  const add=(rule:CookieRule)=>{
    const row=w.document.createElement('li');row.dataset.rule='';
    const field=(name:string,label:string,values?:string[])=>{
      const wrap=w.document.createElement('label');wrap.append(label+' ');
      const input=w.document.createElement(values?'select':'input') as HTMLInputElement | HTMLSelectElement;input.name=`rule.${name}`;input.dataset.field=name;
      if(values)for(const value of values){const option=w.document.createElement('option');option.value=value;option.textContent=value;input.append(option);}
      else if(name==='enabled' || name==='ci')(input as HTMLInputElement).type='checkbox';else (input as HTMLInputElement).type='text';
      const value=rule[name as keyof CookieRule];if(input.type==='checkbox')(input as HTMLInputElement).checked=value===true;else input.value=String(value ?? '');
      wrap.append(input);row.append(wrap);
    };
    field('enabled','Attiva');field('label','Etichetta');field('category','Categoria',['analytics','marketing','preferences','always']);field('match','Operatore',['equals','contains','starts_with','ends_with','starts_ends','wildcard','regex','domain']);field('value','Pattern nome/dominio (mai valore cookie)');field('value2','Suffisso');field('ci','Ignora maiuscole');field('action','Azione',['block','delete','block_delete']);
    // Move the SIBLING, never the row holding the focused button (that would drop focus to <body>).
    for(const [label,action] of [['Su',()=>{if(row.previousElementSibling)list.insertBefore(row.previousElementSibling,row.nextElementSibling);}],['Giù',()=>{if(row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);}],['Elimina',()=>{const next=row.nextElementSibling || row.previousElementSibling;row.remove();(next?.querySelector<HTMLElement>('input') ?? root.querySelector<HTMLElement>('[data-add-rule]'))?.focus();}]] as const){
      const button=w.document.createElement('button');button.type='button';button.textContent=label;on(button,'click',action);row.append(button);
    }
    list.append(row);
  };
  const rules=()=>[...list.children].map(row=>{
    const result:Record<string,unknown>={};for(const node of row.querySelectorAll<HTMLInputElement|HTMLSelectElement>('[data-field]'))result[node.dataset.field!]=node.type==='checkbox' ? (node as HTMLInputElement).checked : node.value;return result;
  });
  // An empty number field means "default", not 0 (Number('') would silently disable timed sweeps).
  const num=(name:string)=>{const value=text(name).trim();return value==='' ? undefined : Number(value);};
  const guardInput=()=>({mode:text('guard.mode'),rules:rules(),allowlist:text('guard.allowlist').split('\n').map(v=>v.trim()).filter(Boolean),sweepInterval:num('guard.sweepInterval'),sweepDuration:num('guard.sweepDuration')});
  const read=()=>validateSettings({tracking:{enabled:checked('tracking.enabled'),mode:text('tracking.mode'),gtmId:text('tracking.gtmId'),ga4Id:text('tracking.ga4Id'),metaPixelId:text('tracking.metaPixelId'),attribution:checked('tracking.attribution'),formEvents:checked('tracking.formEvents'),cmp:text('tracking.cmp'),serverN8n:initial.tracking.serverN8n,serverMeta:initial.tracking.serverMeta,serverGA4:initial.tracking.serverGA4},guard:guardInput(),debug:{mode:text('debug.mode'),settingsPath:text('debug.settingsPath')}});
  initial.guard.rules.forEach(add);
  const action=(selector:string,fn:()=>void)=>on(root.querySelector(selector)!,'click',()=>{if(!alive)return;fn();status.textContent='Modifiche locali; esportare per scaricare. Non attive sul sito.';});
  action('[data-add-rule]',()=>add({enabled:true,label:'',category:'analytics',match:'equals',value:'',value2:'',ci:false,action:'block_delete'}));
  action('[data-clear-rules]',()=>list.replaceChildren());
  action('[data-default-rules]',()=>{list.replaceChildren();defaultRules().forEach(add);});
  for(const [selector,enabled]of [['[data-enable-rules]',true],['[data-disable-rules]',false]] as const)action(selector,()=>{for(const node of list.querySelectorAll<HTMLInputElement>('[data-field=enabled]'))node.checked=enabled;});
  on(root.querySelector('[data-test-rule]')!,'click',()=>{
    // The tester depends on the rules only: an unrelated tracking-ID error must not block it.
    try{const options=validateSettings({guard:guardInput()}).guard,name=text('test.name'),domain=text('test.domain');if(!name || /[;=\s]/.test(name))throw Error('Inserire soltanto il nome del cookie');
      const result=evaluateCookie(name,domain,{analytics:checked('test.analytics'),marketing:checked('test.marketing')},options);
      root.querySelector('[data-test-result]')!.textContent=JSON.stringify(result,null,2);
    }catch(error){root.querySelector('[data-test-result]')!.textContent=error instanceof Error ? error.message : 'Configurazione non valida';}
  });
  on(form,'submit',event=>{
    event.preventDefault();if(!alive)return;
    try{
      // Native constraint messages (min/max/step) first, localized by the browser.
      if(!form.reportValidity())return;
      const result=read();
      const url=browser.URL.createObjectURL(new (w as Window & typeof globalThis).Blob([JSON.stringify(result,null,2)],{type:'application/json'}));
      const link=w.document.createElement('a');link.href=url;link.download='alltracking-config.json';link.click();
      w.setTimeout(()=>browser.URL.revokeObjectURL(url),0);
      status.textContent='File JSON esportato, non attivo sul sito e non salvato remotamente. Applicarlo nel CMS/configurazione host.';
    }catch(error){status.textContent=error instanceof Error ? error.message : 'Configurazione non valida';}
  });
  const destroy=()=>{alive=false;cleanups.forEach(fn=>fn());delete root.dataset.mounted;};
  // A BFCache-restored page reuses this DOM without re-running modules: keep it mounted.
  on(w,'pagehide',event=>{if(!(event as PageTransitionEvent).persisted)destroy();});
  return {read,destroy};
}
