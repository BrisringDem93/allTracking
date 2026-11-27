import {collectAttribution, normalizeConfig, normalizeConsent, readConsent, record, UUID} from './tracking.ts';

type TrackingWindow = Window & {
  dataLayer?: unknown[];
  gtag?: (...args: unknown[]) => void;
  fbq?: ((...args: unknown[]) => void) & {queue?: unknown[][]; loaded?: boolean; version?: string; callMethod?: (...args: unknown[]) => void};
};

declare global {
  interface Window {
    nativeTracking?: ReturnType<typeof createTracking>;
  }
}

const gtmClients = new WeakMap<Document, ReturnType<typeof trackingClient>>();

/** Nessun consenso viene salvato qui: la CMP resta l'unica fonte. */
export function createTracking(w: TrackingWindow, value: unknown, active: () => boolean = () => true) {
  const config = normalizeConfig(value);
  if (config.enabled && config.mode === 'gtm' && config.gtmId) {
    const current = gtmClients.get(w.document);
    if (current) return current;
  }
  return trackingClient(w, config, active);
}

function trackingClient(w: TrackingWindow, config: ReturnType<typeof normalizeConfig>, active: () => boolean) {
  w.nativeConsent?.useCMP(config.cmp,config.ga4Id);
  let attribution: Record<string, string> = {};
  let gaLoaded = false, metaLoaded = false;
  let lastGTM: ReturnType<typeof normalizeConsent> | undefined;
  const storage = (analytics: boolean, marketing: boolean) => ({analytics_storage: analytics ? 'granted' : 'denied', ad_storage: marketing ? 'granted' : 'denied', ad_user_data: marketing ? 'granted' : 'denied', ad_personalization: marketing ? 'granted' : 'denied'});
  const page = w.location.origin + w.location.pathname;
  const tag = (src: string) => {
    const script = w.document.createElement('script');
    script.async = true;
    script.src = src;
    w.document.head.append(script);
  };
  const google = (...args: unknown[]) => {
    w.dataLayer ??= [];
    w.gtag ??= function () { w.dataLayer!.push(arguments); };
    w.gtag(...args);
  };
  const payload = () => {
    const consent = config.enabled && active() ? readConsent(config.cmp, w) : {analytics: false, marketing: false};
    if (config.enabled && config.attribution) {
      let cookies = '';
      if (consent.marketing) {
        try { cookies = w.document.cookie; } catch { /* storage bloccato */ }
      }
      attribution = collectAttribution(w.location.href, cookies, attribution, consent, Date.now());
      // ponytail: pseudonimo solo in memoria per questa pagina, non cookie persistenti.
      if (consent.marketing) attribution.external_id ??= w.crypto.randomUUID();
    }
    let clientId: string | undefined;
    if (consent.analytics) {
      try { clientId = w.document.cookie.match(/(?:^|;\s*)_ga=GA\d+\.\d+\.(\d{1,20}\.\d{1,20})(?:;|$)/)?.[1]; } catch { /* cookie non disponibile: nessun ID inventato */ }
    }
    const receipt = active() ? w.nativeConsent?.receipt() : undefined;
    return {consent, attribution, ...(receipt ? {receipt} : {}), ...(clientId ? {clientId} : {})};
  };
  const sync = () => {
    const {consent, attribution: fields} = payload();
    for (const form of w.document.querySelectorAll<HTMLFormElement>('form[data-landing-form]')) {
      // Every associated control (select, textarea, form="id", added later) counts as application-owned.
      const appOwns = (name: string) => [...form.elements].some(el => (el as HTMLInputElement).name === name && !('attribution' in (el as HTMLElement).dataset));
      for (const input of form.querySelectorAll<HTMLInputElement>('input[data-attribution]')) {
        if (!Object.hasOwn(fields, input.name) || appOwns(input.name)) input.remove();
      }
      for (const [name, value] of Object.entries(fields)) {
        if (appOwns(name)) continue;
        let input = form.querySelector<HTMLInputElement>(`input[data-attribution][name="${name}"]`);
        if (!input) {
          input = w.document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.dataset.attribution = '';
          form.append(input);
        }
        input.value = value;
      }
    }
    if (!config.enabled) return;
    // Consent Mode denial alone can still send cookieless pings after load.
    if (config.ga4Id) (w as unknown as Record<string, unknown>)[`ga-disable-${config.ga4Id}`] = !consent.analytics;
    if (config.mode === 'gtm') {
      if (!config.gtmId || (!lastGTM && !active())) return;
      if (lastGTM?.analytics === consent.analytics && lastGTM.marketing === consent.marketing) return;
      const first = !lastGTM;
      lastGTM = consent;
      if (first) {
        google('consent', 'default', storage(false, false));
        w.dataLayer!.push({'gtm.start': Date.now(), event: 'gtm.js'});
      }
      google('consent', 'update', storage(consent.analytics, consent.marketing));
      w.dataLayer!.push({event:'alltracking_consent_update', analytics:consent.analytics, marketing:consent.marketing});
      if (first) tag(`https://www.googletagmanager.com/gtm.js?id=${config.gtmId}`);
      return;
    }
    if (gaLoaded) google('consent', 'update', storage(consent.analytics, consent.marketing));
    if (metaLoaded) w.fbq?.('consent', consent.marketing ? 'grant' : 'revoke');
    if (!gaLoaded && consent.analytics && config.mode === 'direct' && config.ga4Id) {
      google('consent', 'default', storage(false, false));
      google('consent', 'update', storage(true, consent.marketing));
      google('js', new Date());
      google('config', config.ga4Id, {send_page_view: false, allow_google_signals: false, page_location: page, page_referrer: ''});
      google('event', 'page_view', {page_location: page, page_referrer: ''});
      tag(`https://www.googletagmanager.com/gtag/js?id=${config.ga4Id}`);
      gaLoaded = true;
    }
    if (!metaLoaded && consent.marketing && config.mode === 'direct' && config.metaPixelId) {
      const fbq: NonNullable<TrackingWindow['fbq']> = function (...args) {
        if (fbq.callMethod) fbq.callMethod(...args);
        else fbq.queue!.push(args);
      };
      fbq.queue = []; fbq.loaded = true; fbq.version = '2.0';
      w.fbq = fbq;
      fbq('consent', 'grant');
      fbq('init', config.metaPixelId);
      fbq('set', 'autoConfig', false, config.metaPixelId);
      fbq('track', 'PageView');
      tag('https://connect.facebook.net/en_US/fbevents.js');
      metaLoaded = true;
    }
  };
  for (const event of ['alltracking:consent', 'CookiebotOnConsentReady', 'CookiebotOnAccept', 'CookiebotOnDecline', 'iubenda:consent']) w.addEventListener(event, sync);
  w.document.addEventListener('submit', sync, true);
  const seen = new Set<string>();
  const confirm = (value: unknown, submittedConsent: unknown) => {
    const result = record(value), server = record(result.tracking);
    if (!active() || !config.enabled || !config.formEvents || result.ok !== true || result.confirmed !== true || server.enabled !== true || typeof result.eventId !== 'string' || !UUID.test(result.eventId) || seen.has(result.eventId)) return;
    seen.add(result.eventId);
    sync();
    const current = payload().consent, submitted = normalizeConsent(submittedConsent);
    const analytics = current.analytics && submitted.analytics, marketing = current.marketing && submitted.marketing;
    if (config.mode === 'gtm') {
      if (analytics && marketing) w.dataLayer?.push({event: 'generate_lead', event_id: result.eventId});
      return;
    }
    if (analytics && gaLoaded && server.serverGA4 !== true) google('event', 'generate_lead', {event_id: result.eventId, page_location: page, page_referrer: ''});
    if (marketing && metaLoaded) w.fbq?.('track', 'Lead', {}, {eventID: result.eventId});
  };
  const tracker = {payload, confirm};
  if (config.enabled && config.mode === 'gtm' && config.gtmId && active()) gtmClients.set(w.document, tracker);
  sync();
  return tracker;
}
