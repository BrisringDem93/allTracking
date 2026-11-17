export interface CookieRule {
  enabled: boolean;
  label: string;
  category: 'analytics' | 'marketing' | 'preferences' | 'always';
  match: 'equals' | 'contains' | 'starts_with' | 'ends_with' | 'starts_ends' | 'wildcard' | 'regex' | 'domain';
  value: string;
  value2?: string;
  ci?: boolean;
  action: 'block' | 'delete' | 'block_delete';
}
export type GuardOptions = {
  mode?: 'off' | 'monitor' | 'enforce';
  rules?: CookieRule[];
  allowlist?: string[];
  sweepInterval?: number;
  sweepDuration?: number;
};
export type NormalizedGuard = Required<GuardOptions>;

// Prefixes alone are NOT safety signals: __Host-_ga must remain blockable.
const sessionPatterns = ['PHPSESSID','JSESSIONID','ASP.NET_SessionId','connect.sid','session','sessionid','session_id','sid','csrf','csrftoken','XSRF-TOKEN','authjs.session-token*','next-auth.session-token*'];
export const PROTECTED_PATTERNS: readonly string[] = Object.freeze([
  'cmplz_*','complianz*','_iub_cs-*','CookieConsent*','OptanonConsent','OptanonAlertBoxClosed',
  'eupubconsent*','euconsent-v2','borlabs-cookie','moove_gdpr_popup','cookielawinfo-*',
  'cookieyes*','cky-*','CookieScriptConsent','alltracking_consent',
  ...sessionPatterns,...sessionPatterns.flatMap(p => [`__Host-${p}`,`__Secure-${p}`]),
]);
export const protectedPatterns = (): string[] => [...PROTECTED_PATTERNS];

/** WP presets, without introducing persistent identifiers into Astro. */
export function defaultRules(): CookieRule[] {
  const rows: [string,CookieRule['category'],CookieRule['match'],string][] = [
    ['Google Analytics','analytics','wildcard','_ga*'],
    ['Google Analytics (legacy)','analytics','equals','_gid'],
    ['Google Analytics (throttle)','analytics','wildcard','_gat*'],
    ['Urchin / Universal Analytics','analytics','starts_with','__utm'],
    ['Google Ads / conversion linker','marketing','wildcard','_gcl_*'],
    ['Google Ads (session)','marketing','wildcard','_gac_*'],
    ['DoubleClick','marketing','equals','IDE'],
    ['DoubleClick (test)','marketing','equals','test_cookie'],
    ['Meta Pixel (browser id)','marketing','equals','_fbp'],
    ['Meta Pixel (click id)','marketing','equals','_fbc'],
    ['Hotjar','analytics','wildcard','_hj*'],
    ['Microsoft Clarity','analytics','wildcard','_cl*k'],
    ['Matomo / Piwik','analytics','wildcard','_pk_*'],
    ['Microsoft Advertising (UET)','marketing','wildcard','_uet*'],
    ['LinkedIn Insight','marketing','equals','li_fat_id'],
    ['LinkedIn (browser id)','marketing','equals','bcookie'],
    ['LinkedIn (routing)','marketing','equals','lidc'],
    ['TikTok Pixel','marketing','wildcard','_tt*'],
    ['Pinterest','marketing','wildcard','_pin_*'],
    ['X / Twitter','marketing','equals','personalization_id'],
    ['Yandex Metrica','analytics','wildcard','_ym_*'],
    ['Snapchat','marketing','wildcard','_scid*'],
    ['GoHighLevel / LeadConnector (session)','marketing','starts_with','lc_session_tk_'],
    ['allTracking pseudonym (fst_uid)','marketing','equals','fst_uid'],
    ['allTracking click id (fst_clid)','marketing','equals','fst_clid'],
  ];
  return rows.map(([label,category,match,value]) => ({enabled:true,label,category,match,value,value2:'',ci:false,action:'block_delete'}));
}

const invalid = (field: string): never => { throw new TypeError(`Invalid cookie guard ${field}`); };
function object(value: unknown, keys: string[], field: string): asserts value is Record<string,unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value)
    || ![Object.prototype,null].includes(Object.getPrototypeOf(value))
    || Object.keys(value).some(k => !keys.includes(k))) invalid(field);
}

/** Trusted application configuration only: regex syntax is checked, not its computational complexity. */
export function normalizeGuard(input: GuardOptions = {}): NormalizedGuard {
  object(input,['mode','rules','allowlist','sweepInterval','sweepDuration'],'options');
  const mode = input.mode === undefined ? 'off' : input.mode;
  if (!['off','monitor','enforce'].includes(mode)) invalid('mode');
  const rules = input.rules === undefined ? defaultRules() : input.rules;
  if (!Array.isArray(rules)) invalid('rules');
  const normalized = Array.from(rules,(r,i) => {
    const field=`rules[${i}]`;
    object(r,['enabled','label','category','match','value','value2','ci','action'],field);
    if (typeof r.enabled !== 'boolean' || typeof r.label !== 'string' || typeof r.value !== 'string' || !r.value
      || (r.value2 !== undefined && typeof r.value2 !== 'string') || (r.ci !== undefined && typeof r.ci !== 'boolean')
      || !['analytics','marketing','preferences','always'].includes(r.category)
      || !['equals','contains','starts_with','ends_with','starts_ends','wildcard','regex','domain'].includes(r.match)
      || !['block','delete','block_delete'].includes(r.action)) invalid(field);
    if (r.match === 'regex') { try { new RegExp(r.value,r.ci ? 'i' : ''); } catch { invalid(`${field}.regex`); } }
    return {...r,ci:r.ci ?? false,value2:r.value2 ?? ''};
  });
  const allowlist = input.allowlist === undefined ? [] : input.allowlist;
  if (!Array.isArray(allowlist) || Array.from(allowlist).some(p => typeof p !== 'string' || !p || /[\s;=\x00-\x1f\x7f]/.test(p))) invalid('allowlist');
  const sweepInterval=input.sweepInterval === undefined ? 2000 : input.sweepInterval;
  const sweepDuration=input.sweepDuration === undefined ? 60000 : input.sweepDuration;
  if (!Number.isInteger(sweepInterval) || sweepInterval < 250 || sweepInterval > 2147483647) invalid('sweepInterval');
  if (!Number.isInteger(sweepDuration) || sweepDuration < 0 || sweepDuration > 2147483647) invalid('sweepDuration');
  return {mode,rules:normalized,allowlist:[...new Set([...protectedPatterns(),...allowlist])],sweepInterval,sweepDuration};
}
