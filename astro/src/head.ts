import {normalizeOptions, type Options} from './options.ts';

/** Script-safe JSON for an inline <script>: no "</script>", U+2028 or U+2029. */
export const serializeOptions = (config: ReturnType<typeof normalizeOptions>) => JSON.stringify(config).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');

/** Runtime-neutral (no node:*): fills the bundled head guard with strictly validated options.
 * `inline` is dist/early.inline.js, imported as text by the caller (Vite `?raw` in TrackingHead). */
export function guardScript(inline: string, options: Options = {}): string {
  const config = normalizeOptions(options);
  if (config.disabled || config.guard.mode === 'off') return '';
  return inline.replace('__ALLTRACKING_OPTIONS__', () => serializeOptions(config));
}
