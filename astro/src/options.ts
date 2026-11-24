import {normalizeGuard, type GuardOptions} from './guard-config.ts';
import {safeSettingsPath, type DebugSettings} from './diagnostics.ts';

export interface Options {
  disabled?: boolean;
  /** Exact pathname and its descendants, not a glob. */
  exclude?: string[];
  configEndpoint?: string;
  policyEndpoint?: string;
  consentEndpoint?: string;
  guard?: GuardOptions;
  debug?: DebugSettings;
}

export function normalizeOptions(value: Options = {}) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new TypeError('Invalid options');
  const keys = ['disabled','exclude','configEndpoint','policyEndpoint','consentEndpoint','guard','debug'];
  for (const key of Object.keys(value)) if (!keys.includes(key)) throw new TypeError(`Unknown option: ${key}`);
  if (value.disabled !== undefined && typeof value.disabled !== 'boolean') throw new TypeError('disabled must be boolean');
  // Mandatory separators keep matching linear: an optional "/" between repeated segments backtracks exponentially.
  const path = (input: unknown): string => {
    if (typeof input !== 'string' || input.length > 200 || !/^\/(?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*(?:\/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)*\/?)?$(?![\s\S])/.test(input)) throw new TypeError('Invalid local endpoint/path');
    return input;
  };
  if (value.exclude !== undefined && (!Array.isArray(value.exclude) || value.exclude.length > 100)) throw new TypeError('Invalid exclude paths');
  const debug = value.debug ?? {mode:'off' as const};
  if (value.debug === null || !debug || typeof debug !== 'object' || Array.isArray(debug)
    || Object.keys(debug).some(key => !['mode','settingsPath'].includes(key))
    || !['off','public'].includes(debug.mode)) throw new TypeError('Invalid debug options');
  if (debug.settingsPath !== undefined && (!safeSettingsPath(debug.settingsPath) || !path(debug.settingsPath))) throw new TypeError('Invalid settings path');
  return {
    disabled: value.disabled === true,
    exclude: (value.exclude ?? ['/admin']).map(path),
    configEndpoint: path(value.configEndpoint ?? '/api/tracking-config'),
    policyEndpoint: path(value.policyEndpoint ?? '/api/consent-policy'),
    consentEndpoint: path(value.consentEndpoint ?? '/api/consent'),
    guard: normalizeGuard(value.guard),
    debug: {...debug},
  };
}
