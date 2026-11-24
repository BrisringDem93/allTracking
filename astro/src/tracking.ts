/** Contratto pubblico condiviso da browser e Worker: mai segreti o codice CMS. */
export const record = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};

export function normalizeConfig(value: unknown, workInProgress = false) {
  const v = record(value);
  const mode = v.mode === 'gtm' ? 'gtm' : v.mode === 'direct' ? 'direct' : 'off';
  const id = (key: string, pattern: RegExp) => typeof v[key] === 'string' && pattern.test(v[key]) ? v[key] : '';
  return {
    enabled: !workInProgress && v.enabled === true && mode !== 'off',
    mode,
    gtmId: mode === 'gtm' ? id('gtmId', /^GTM-[A-Z0-9]{4,20}$/) : '',
    ga4Id: mode === 'direct' ? id('ga4Id', /^G-[A-Z0-9]{4,20}$/) : '',
    metaPixelId: mode === 'direct' ? id('metaPixelId', /^\d{5,25}$/) : '',
    attribution: v.attribution === true,
    formEvents: v.formEvents === true,
    serverN8n: v.serverN8n === true,
    serverMeta: mode === 'direct' && v.serverMeta === true,
    serverGA4: mode === 'direct' && v.serverGA4 === true,
    cmp: v.cmp === 'cookiebot' ? 'cookiebot' : v.cmp === 'iubenda' ? 'iubenda' : 'bridge',
  } as const;
}
export type TrackingConfig = ReturnType<typeof normalizeConfig>;
export const CLICK_FIELDS = ['fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id'] as const;
export const CAMPAIGN_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'cmp'] as const;
export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

const campaignSafe = (v: string) => /^[A-Za-z0-9._~+/% -]+$/.test(v) && !/(?:secret|token|password|passwd|bearer|api[_-]?key)/i.test(v);
/** Undo repeated percent-encoding so "mario%2540mail.test" cannot hide an email/URL/secret marker. */
const fullyDecoded = (v: string) => {
  for (let i = 0; i < 3 && /%[0-9a-f]{2}/i.test(v); i++) {
    try { v = decodeURIComponent(v); } catch { break; }
  }
  return v;
};

export function sanitizeAttribution(value: unknown, consent: Consent): Record<string, string> {
  consent = normalizeConsent(consent);
  const clean: Record<string, string> = {};
  for (const [key, v] of Object.entries(record(value)).slice(0, 40)) {
    if (typeof v !== 'string' || !v || v.length > 200 || /[<>\x00-\x1f\x7f]/.test(v)) continue;
    if (
      consent.analytics
      && CAMPAIGN_FIELDS.some(field => field === key)
      && campaignSafe(v)
      && campaignSafe(fullyDecoded(v))
    ) clean[key] = v;
    if (!consent.marketing) continue;
    if (CLICK_FIELDS.some(field => field === key) && /^[A-Za-z0-9._~-]+$/.test(v)) clean[key] = v;
    if ((key === 'fbc' || key === 'fbp') && /^fb\.\d\.\d{1,13}\.[A-Za-z0-9._~-]+$/.test(v)) clean[key] = v;
    if (key === 'external_id' && UUID.test(v)) clean[key] = v;
  }
  return clean;
}

export function collectAttribution(url: string, cookies: string, previous: unknown, consent: Consent, now: number) {
  if (!consent.analytics && !consent.marketing) return {};
  const values: Record<string, unknown> = sanitizeAttribution(previous, consent);
  try {
    for (const [k, v] of new URL(url).searchParams) {
      if (CAMPAIGN_FIELDS.some(field => field === k) || CLICK_FIELDS.some(field => field === k)) values[k] = v;
    }
  } catch { /* URL non valida: resta solo lo stato consentito */ }
  if (consent.marketing) {
    const derived = String(values.fbc ?? '');
    for (const part of cookies.split(';')) {
      const [key, ...rest] = part.trim().split('=');
      if (key === '_fbc' || key === '_fbp') {
        try { values[key.slice(1)] = decodeURIComponent(rest.join('=')); } catch { /* ignora cookie malformato */ }
      }
    }
    const click = sanitizeAttribution(values, consent).fbclid;
    // A stale _fbc cookie must not re-stamp the fbc already derived for this click on every call.
    if (click && !String(values.fbc ?? '').endsWith(`.${click}`)) values.fbc = derived.endsWith(`.${click}`) ? derived : `fb.1.${now}.${click}`;
  }
  return sanitizeAttribution(values, consent);
}

export const CONSENT_MAX_AGE = 180 * 86400 * 1000;
export type Consent = {analytics: boolean; marketing: boolean};
export const normalizeConsent = (value: unknown): Consent => {
  const v = record(value);
  return {analytics: v.analytics === true, marketing: v.marketing === true};
};

export function privacySignal(scope: unknown): 'none' | 'gpc' | 'dnt' {
  const w = record(scope), n = record(w.navigator);
  if (n.globalPrivacyControl === true) return 'gpc';
  return [n.doNotTrack, w.doNotTrack, n.msDoNotTrack].some(v => v === '1' || v === 'yes') ? 'dnt' : 'none';
}

const readingPreferences = new WeakSet<object>();
export function readConsent(cmp: string, scope: unknown, nativeChoice?: Consent): Consent {
  const w = record(scope);
  const constrain = (value: unknown) => {
    const external=normalizeConsent(value);
    if (!w.nativeConsent || cmp === 'bridge') return external;
    const own=normalizeConsent(nativeChoice ?? w.allTrackingConsent);
    return {analytics:own.analytics && external.analytics,marketing:own.marketing && external.marketing};
  };
  try {
    if (cmp === 'cookiebot') {
      const c = record(record(w.Cookiebot).consent);
      return constrain({analytics: c.statistics, marketing: c.marketing});
    }
    if (cmp === 'iubenda') {
      if (readingPreferences.has(w)) return normalizeConsent(null);
      let preferences: Record<string, unknown> | undefined;
      readingPreferences.add(w);
      try {
        let cookies = '';
        try { cookies = String(record(w.document).cookie ?? ''); } catch { /* Cookie access unavailable: use API. */ }
        for (const part of cookies.split(';')) {
          const [name = '', ...value] = part.trim().split('=');
          if (!/^_iub_cs-[\w-]+$/.test(name) || name.endsWith('-uspr')) continue;
          const raw = value.join('=');
          let parsed: unknown;
          try { parsed = JSON.parse(raw); }
          catch { try { parsed = JSON.parse(decodeURIComponent(raw)); } catch { continue; } }
          const candidate = record(parsed), purposes = candidate.purposes;
          if (purposes !== null && typeof purposes === 'object' && !Array.isArray(purposes)) { preferences = candidate; break; }
          if (!preferences && typeof candidate.consent === 'boolean') preferences = candidate;
        }
        if (!preferences) {
          const api = record(record(record(w._iub).cs).api);
          preferences = record(typeof api.getPreferences === 'function' ? api.getPreferences() : null);
        }
      } finally { readingPreferences.delete(w); }
      if (preferences.consent === false) return normalizeConsent(null);
      const p = record(preferences.purposes);
      return constrain({analytics: p['4'], marketing: p['5']});
    }
    return normalizeConsent(w.allTrackingConsent);
  } catch {
    return normalizeConsent(null);
  }
}
