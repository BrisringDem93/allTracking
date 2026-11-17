import type {AstroIntegration} from 'astro';
import {fileURLToPath} from 'node:url';
import {readFileSync} from 'node:fs';
import {normalizeOptions, type Options} from './options.ts';
export type {Options} from './options.ts';
export type {GuardOptions, CookieRule} from './guard-config.ts';
export type {GuardAPI, GuardConsent} from './cookie-guard.ts';
export type {DebugSettings} from './diagnostics.ts';

const serializeOptions = (config: ReturnType<typeof normalizeOptions>) => JSON.stringify(config).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');

/** Trusted local bundle with strictly validated, script-safe options for a FIRST-head component. */
export function cookieGuardScript(options: Options = {}): string {
  const config = normalizeOptions(options);
  if (config.disabled || config.guard.mode === 'off') return '';
  const inline = readFileSync(new URL('./early.inline.js', import.meta.resolve('@alltracking/astro')), 'utf8');
  return inline.replace('__ALLTRACKING_OPTIONS__', () => serializeOptions(config));
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
