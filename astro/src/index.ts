import type {AstroIntegration} from 'astro';
import {fileURLToPath} from 'node:url';
import {readFileSync} from 'node:fs';
import {normalizeOptions, type Options} from './options.ts';
import {guardScript, serializeOptions} from './head.ts';
export type {Options} from './options.ts';
export type {GuardOptions, CookieRule} from './guard-config.ts';
export type {GuardAPI, GuardConsent} from './cookie-guard.ts';
export type {DebugSettings} from './diagnostics.ts';
export {guardScript} from './head.ts';

/** Node-only (build/config time): trusted local bundle with strictly validated, script-safe options.
 * TrackingHead does not use this, so on-demand pages never need node:fs or node_modules at runtime. */
export function cookieGuardScript(options: Options = {}): string {
  const config = normalizeOptions(options);
  if (config.disabled || config.guard.mode === 'off') return '';
  return guardScript(readFileSync(new URL('./early.inline.js', import.meta.resolve('@alltracking/astro')), 'utf8'), config);
}

export default function allTracking(options: Options = {}): AstroIntegration {
  const config = normalizeOptions(options);
  return {
    name: '@alltracking/astro',
    hooks: {
      'astro:config:setup': ({injectScript}) => {
        const serialized = serializeOptions(config), early = cookieGuardScript(config);
        if (early) injectScript('head-inline', early);
        const entry = fileURLToPath(new URL('./runtime.js', import.meta.url));
        injectScript('page', `import {startAllTracking} from ${JSON.stringify(entry)}; startAllTracking(window, ${serialized});`);
      },
    },
  };
}
