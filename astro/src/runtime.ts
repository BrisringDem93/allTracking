import {normalizeOptions, type Options} from './options.ts';
import {normalizeConfig, record} from './tracking.ts';
import {createConsent} from './consent-client.ts';
import {createTracking} from './tracking-client.ts';
import {mountConsentUI} from './consent-ui.ts';
import {mountDiagnostics} from './diagnostics.ts';
import {startEarlyTracking} from './early.ts';

declare global {
  interface Window {
    allTrackingRuntime?: Promise<void>;
    nativeConsent?: ReturnType<typeof createConsent>;
    nativeTracking?: ReturnType<typeof createTracking>;
  }
}

/** One runtime per document, including documents initially excluded. */
export function startAllTracking(w: Window, options: Options = {}): Promise<void> {
  if (w.allTrackingRuntime) return w.allTrackingRuntime;
  const config = normalizeOptions(options);
  const early = startEarlyTracking(w,config), active = early.active;
  let diagnostics: ReturnType<typeof mountDiagnostics>;
  const depart = () => {
    early.stop();
    diagnostics?.destroy();
    w.dispatchEvent(new (w as Window & typeof globalThis).Event('alltracking:consent'));
  };
  // ponytail: loaded provider code cannot be unloaded safely. Astro's canceled
  // preparation performs a full document navigation instead of reusing scripts.
  // Teardown waits for pagehide: a canceled preparation whose navigation never
  // unloads the page (download, 204, Stop) must leave a working runtime.
  w.document.addEventListener('astro:before-preparation', event => event.preventDefault());
  // Astro's fallback for a canceled preparation is a body-less GET, so forms
  // bypass ClientRouter and keep native submission (POST body, method, target).
  // Capture on document runs before ClientRouter's bubbling submit listener.
  w.document.addEventListener('submit', event => {
    const form = event.target as HTMLElement | null;
    if (form?.tagName === 'FORM') form.dataset.astroReload = '';
  }, true);
  w.addEventListener('pagehide', depart);
  // ponytail: BFCache restores old closures; reload instead of reviving stale config.
  w.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    depart();
    w.location.reload();
  });
  const run = async () => {
    if (!active()) return;
    const consent = createConsent(w, 'pending', false, config.consentEndpoint, active);
    const read = async (path: string) => {
      try {
        const response = await w.fetch(path, {cache:'no-store', credentials:'same-origin', referrerPolicy:'no-referrer', redirect:'error', signal:AbortSignal.timeout(4000)});
        return record(response.ok ? await response.json() : null);
      } catch { return {}; }
    };
    const [settings, policy] = await Promise.all([read(config.configEndpoint), read(config.policyEndpoint)]);
    if (!active()) return;
    const validVersion = typeof policy.version === 'string' && /^[A-Za-z0-9._-]{1,128}$(?![\s\S])/.test(policy.version);
    const text = (value: unknown): value is string => typeof value === 'string' && !!value.trim() && value.length <= 50000;
    const validCMP = settings.cmp === 'bridge' || settings.cmp === 'cookiebot' || settings.cmp === 'iubenda';
    const ready = validCMP && validVersion && policy.ready === true && text(policy.privacy) && text(policy.cookie);
    const tracking = normalizeConfig(settings);
    // The tracking endpoint owns CMP mode; policy cannot loosen this intersection.
    consent.useCMP(tracking.cmp);
    consent.configure(validVersion ? String(policy.version) : 'unavailable', ready);
    if (!active()) return;
    w.allTrackingConsentReady = true;
    w.allTrackingGuard?.refresh();
    for (const key of ['privacy','cookie'] as const) {
      const value = policy[key];
      if (text(value)) for (const element of w.document.querySelectorAll(`[data-policy-text="${key}"]`)) element.textContent = value;
    }
    for (const element of w.document.querySelectorAll('[data-policy-version]')) element.textContent = validVersion ? String(policy.version) : '';
    mountConsentUI(w, consent, ready);
    diagnostics = mountDiagnostics(w, config.debug, tracking, {guard:config.guard.mode, cmpValid:validCMP, ready});
    if (tracking.enabled) w.nativeTracking = createTracking(w, tracking, active);
  };
  // Modules injected at the page stage can also be called by a manual entry.
  w.allTrackingRuntime = w.document.readyState === 'loading'
    ? new Promise<void>(resolve => w.document.addEventListener('DOMContentLoaded', () => { run().then(resolve); }, {once:true}))
    : run();
  return w.allTrackingRuntime;
}
