#!/usr/bin/env node
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {join} from 'node:path';
import {build, stop} from 'esbuild';
import {JSDOM,CookieJar} from 'jsdom';

const root = fileURLToPath(new URL('..', import.meta.url));
const fixture = join(root, '.fixture');
const run = (args, cwd = root) => execFileSync('npm', args, {cwd, encoding:'utf8', timeout:120000, env:{...process.env, ASTRO_TELEMETRY_DISABLED:'1'}, stdio:['ignore','pipe','pipe']});
// ponytail: disposable consumer installs the actual tarball, never a source alias.
rmSync(fixture, {recursive:true, force:true});
mkdirSync(join(fixture, 'src/pages/admin'), {recursive:true});
const [packed] = JSON.parse(run(['pack','--ignore-scripts','--json','--pack-destination',fixture]));
for(const path of ['dist/runtime.js','dist/early.inline.js','dist/settings-client.js','src/ConsentPanel.astro','src/TrackingSettings.astro','src/TrackingHead.astro'])assert.ok(packed.files.some(file=>file.path===path),`pack includes ${path}`);
assert.ok(packed.files.every(file => file.path === 'package.json' || file.path === 'README.md' || file.path.startsWith('dist/') || ['src/ConsentPanel.astro','src/TrackingSettings.astro','src/TrackingHead.astro'].includes(file.path)));
assert.equal(packed.version,'0.2.1');
writeFileSync(join(fixture,'package.json'), JSON.stringify({name:'alltracking-fixture', private:true, type:'module', scripts:{build:'astro build'}, dependencies:{astro:'7.2.0','@alltracking/astro':`file:./${packed.filename}`}}));
console.log(run(['install','--ignore-scripts','--no-audit','--no-fund','--offline'], fixture));
const installed=join(fixture,'node_modules/@alltracking/astro');
const manifest=JSON.parse(readFileSync(join(installed,'package.json'),'utf8'));
assert.equal(manifest.version,packed.version,'consumer installed the current tarball');
assert.equal(manifest.dependencies?.esbuild,undefined,'esbuild is build-time only');
writeFileSync(join(fixture,'tracking-options.mjs'), `export const trackingOptions={exclude:['/admin'],guard:{mode:'enforce',sweepDuration:0},debug:{mode:'public',settingsPath:'/admin/tracking'}};`);
writeFileSync(join(fixture,'astro.config.mjs'), `import {defineConfig} from 'astro/config'; import allTracking from '@alltracking/astro'; import {trackingOptions} from './tracking-options.mjs'; export default defineConfig({integrations:[allTracking(trackingOptions)], devToolbar:{enabled:false}});`);
// The explicit component must precede author head writers; head-inline alone cannot.
const sentinel=`<script is:inline>document.cookie='_ga=before-module; path=/';document.cookie='session=essential; path=/';window.sentinelDenied=!document.cookie.includes('_ga=before-module');</script>`;
const page = `---
import ConsentPanel from '@alltracking/astro/ConsentPanel.astro';
import TrackingHead from '@alltracking/astro/TrackingHead.astro';
import {trackingOptions} from '../../tracking-options.mjs';
import {ClientRouter} from 'astro:transitions';
---
<html lang="it"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><TrackingHead options={trackingOptions} />${sentinel}<title>Independent project fixture</title><ClientRouter /></head><body><main><h1>Independent fixture</h1><form data-landing-form><input name="email" value="private@example.test" /></form><a href="/admin">Admin</a></main><ConsentPanel /></body></html>`;
for (const name of ['index','second','admin/index']) writeFileSync(join(fixture,`src/pages/${name}.astro`), name.startsWith('admin/') ? page.replace('../../tracking-options.mjs','../../../tracking-options.mjs') : page);
writeFileSync(join(fixture,'src/pages/admin/tracking.astro'), `---
import TrackingSettings from '@alltracking/astro/TrackingSettings.astro';
---
<html lang="it"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Settings fixture</title></head><body>${sentinel}<TrackingSettings initial={{tracking:{enabled:true,mode:'direct',ga4Id:'G-TEST123',secret:'PRIVATE_BACKEND_SECRET'},guard:{mode:'off',rules:[]},debug:{mode:'off'},token:'PRIVATE_BACKEND_SECRET'}} /></body></html>`);
writeFileSync(join(fixture,'consumer.ts'), `import allTracking, {type Options,type GuardOptions,type CookieRule} from '@alltracking/astro'; import {startAllTracking} from '@alltracking/astro/client'; import {normalizeConfig, sanitizeAttribution} from '@alltracking/astro/shared'; const rules: CookieRule[]=[]; const guard: GuardOptions={mode:'enforce',rules}; const options: Options = {exclude:['/admin'],guard,debug:{mode:'public',settingsPath:'/admin/tracking'}}; allTracking(options); startAllTracking(window, options); window.allTrackingGuard?.evaluate('_ga'); window.nativeTracking?.confirm({ok:true}, {analytics:false,marketing:false}); window.nativeConsent?.set({analytics:true}); sanitizeAttribution({},normalizeConfig({}).enabled ? {analytics:true,marketing:true} : {analytics:false,marketing:false});`);
execFileSync(process.execPath, [join(root,'node_modules/typescript/lib/tsc.js'),'--ignoreConfig','--noEmit','--strict','--skipLibCheck','--module','NodeNext','--target','ES2022',join(fixture,'consumer.ts')], {cwd:fixture, encoding:'utf8', stdio:'pipe', timeout:60000});
console.log(run(['run','build'], fixture));
const html = readFileSync(join(fixture,'dist/index.html'),'utf8');
assert.match(html,/data-consent-ui/);
assert.doesNotMatch(html,/protocollodeminicis|external-tracking|clarity\.ms|sanity\.io/i);
const config = {enabled:true, mode:'direct', cmp:'bridge', ga4Id:'G-TEST123', metaPixelId:'123456789', attribution:true, formEvents:true, token:'PRIVATE_BACKEND_SECRET'};
const policy = {ready:true, version:'fixture-v1', privacy:'Fixture privacy', cookie:'Fixture cookie'};
let checked = 0, savedConsent;
const sharedJar=new CookieJar();
try {
  for (const path of ['/', '/second', '/admin','/admin/tracking']) {
    const document = readFileSync(join(fixture,`dist/${path === '/' ? '' : path.slice(1) + '/'}index.html`),'utf8');
    assert.doesNotMatch(document,/PRIVATE_BACKEND_SECRET/);
    const dom = new JSDOM(document, {url:`https://independent.test${path}?utm_source=fixture&fbclid=CLICK`, runScripts:'outside-only',cookieJar:path==='/second'||path==='/'?sharedJar:undefined});
    const w = dom.window, calls = [];
    if(path==='/second')w.localStorage.setItem('alltracking_consent',savedConsent);
    // JSDOM lacks the SVG anchor constructor used by Astro's ClientRouter.
    w.SVGAElement=class extends w.SVGElement {};
    const errors=[];w.addEventListener('error',event=>errors.push(event.error));
    w.matchMedia = () => ({matches:false, addEventListener(){}, removeEventListener(){}});
    w.scrollTo = () => {};
    w.fetch = async (url, init) => {
      calls.push(String(url));
      if (url === '/api/tracking-config') return Response.json(config);
      if (url === '/api/consent-policy') return Response.json(policy);
      if (url === '/api/consent') return Response.json({ok:true, receipt:{...JSON.parse(init.body), createdAt:new Date().toISOString()}});
      throw new Error('Unexpected network destination in offline fixture');
    };
    try {
      const classic=[...w.document.querySelectorAll('script:not([type]),script[type="text/javascript"]')];
      assert.ok(classic.length>=2);
      assert.equal(classic.filter(script=>script.textContent.includes('startEarlyTracking')).length,path==='/admin/tracking'?1:2,'component plus automatic fallback are both emitted');
      assert.match(classic[0].textContent,/startEarlyTracking/,'explicit FIRST-head component precedes the author sentinel');
      assert.equal(classic[0].parentElement.tagName,'HEAD');
      const listeners=[];
      for(const target of [w,w.document]){
        const add=target.addEventListener.bind(target);
        target.addEventListener=(type,handler,options)=>{listeners.push({target,type,handler});return add(type,handler,options);};
      }
      let firstEarly,firstSetter,earlyListeners;
      for(const script of classic){
        assert.equal(script.hasAttribute('src'),false);w.eval(script.textContent);
        if(script.textContent.includes('startEarlyTracking')){
          if(firstEarly){
            assert.equal(w.allTrackingEarly,firstEarly,'automatic fallback reuses first-head singleton');
            assert.equal(Object.getOwnPropertyDescriptor(w.document,'cookie')?.set,firstSetter);
            assert.equal(listeners.length,earlyListeners,'duplicate head bootstrap adds no listeners');
          }else{
            firstEarly=w.allTrackingEarly;firstSetter=Object.getOwnPropertyDescriptor(w.document,'cookie')?.set;earlyListeners=listeners.length;
          }
        }
      }
      const excluded=path.startsWith('/admin'),guard=w.allTrackingGuard;
      assert.equal(w.sentinelDenied,!excluded,'classic cookie write executes before ANY page modules');
      assert.equal(w.nativeConsent,undefined);
      if(path==='/second')assert.match(w.document.cookie,/_ga=retained/,'packaged FIRST head preserves cross-page identity before policy');
      assert.equal(!!guard,!excluded);
      const scripts = [...w.document.querySelectorAll('script[type="module"]')];
      assert.ok(scripts.length > 0);
      for (const script of scripts) {
        const result = await build({write:false, bundle:true, format:'iife', platform:'browser',
          ...(script.src ? {entryPoints:[join(fixture,'dist',new URL(script.src).pathname)]} : {stdin:{contents:script.textContent, resolveDir:join(fixture,'dist')}}),
          plugins:[{name:'built-assets', setup(b){b.onResolve({filter:/^\/_astro\//}, args => ({path:join(fixture,'dist',args.path)}));}}],
        });
        w.eval(result.outputFiles[0].text);
      }
      await w.allTrackingRuntime;
      assert.equal(w.allTrackingGuard,guard,'page module must reuse earliest-head guard');
      if(path==='/second'){assert.match(w.document.cookie,/_ga=retained/);assert.equal(w.nativeConsent.get().analytics,true);}
      if (excluded) {
        assert.equal(calls.length,0);
        assert.equal(w.nativeConsent,undefined);
        assert.equal(w.nativeTracking,undefined);
        assert.equal(w.document.querySelector('[data-alltracking-debug]'),null);
        assert.equal(Object.hasOwn(w.document,'cookie'),false);
        if(path==='/admin/tracking'){
          const editor=w.document.querySelector('[data-tracking-settings]');
          assert.equal(editor.dataset.mounted,'true','settings client came from installed tarball');
          for(const input of editor.querySelectorAll('input,select,textarea'))assert.ok(input.labels.length,input.name);
          assert.equal(editor.querySelector('[name="tracking.ga4Id"]').value,'G-TEST123');
          assert.equal(editor.querySelector('[name="guard.mode"]').value,'off');
          assert.equal(editor.querySelector('[name="debug.mode"]').value,'off');
          editor.querySelector('[data-add-rule]').click();
          editor.querySelector('[data-field=value]').value='_ga';
          editor.querySelector('[name="test.name"]').value='_ga';
          editor.querySelector('[data-test-rule]').click();
          assert.equal(JSON.parse(editor.querySelector('[data-test-result]').textContent).blocked,true);
          let blob,downloaded=false;
          w.URL.createObjectURL=value=>{blob=value;return 'blob:fixture';};w.URL.revokeObjectURL=()=>{};
          w.HTMLAnchorElement.prototype.click=function(){downloaded=this.download==='alltracking-config.json';};
          editor.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true,bubbles:true}));
          assert.equal(downloaded,true);assert.ok(blob);
          const exported=await new Promise((resolve,reject)=>{const reader=new w.FileReader();reader.onload=()=>resolve(JSON.parse(reader.result));reader.onerror=reject;reader.readAsText(blob);});
          assert.equal(exported.guard.rules[0].value,'_ga');
          assert.equal(exported.guard.mode,'off');assert.equal(exported.debug.mode,'off');
          assert.doesNotMatch(JSON.stringify(exported),/PRIVATE_BACKEND_SECRET/);
          assert.equal(calls.length,0,'export is local-only');
        }
      } else {
        assert.equal(w.document.querySelectorAll('script[src*="googletagmanager"]').length,path==='/second'?1:0,'restored confirmed grant may load its provider');
        const debug=w.document.querySelector('[data-alltracking-debug]');
        assert.ok(debug);assert.equal(debug.querySelectorAll('[role=tab]').length,5);
        assert.equal(debug.querySelector('[data-debug-settings]').getAttribute('href'),'/admin/tracking');
        debug.querySelector('[data-debug-toggle]').click();
        assert.match(debug.querySelector('[data-debug-body]').textContent,path==='/second'?/analytics concesso/:/analytics negato/);
        await w.nativeConsent.set({analytics:true, marketing:true});
        assert.match(debug.querySelector('[data-debug-body]').textContent,/analytics concesso/);
        w.document.cookie='_ga=granted; path=/';w.document.cookie='_fbp=granted; path=/';
        assert.match(w.document.cookie,/_ga=granted/);
        let copied='';Object.defineProperty(w.navigator,'clipboard',{value:{writeText:async text=>{copied=text;}}});
        for(const tab of debug.querySelectorAll('[role=tab]')){tab.click();assert.ok(debug.querySelector('[data-debug-body]').textContent);}
        [...debug.querySelectorAll('button')].find(button=>button.textContent==='Copia JSON').click();
        const snapshot=JSON.parse(copied);
        assert.equal(snapshot.config.ga4Id,'G-TEST123');
        assert.ok(snapshot.cookies.some(cookie=>cookie.name==='_ga'));
        assert.doesNotMatch(copied,/PRIVATE_BACKEND_SECRET|private@example\.test|granted;|CLICK/);
        assert.equal(w.nativeTracking.payload().attribution.utm_source,'fixture');
        const submitted = w.nativeTracking.payload().consent;
        const response = {ok:true,confirmed:true,eventId:'11111111-1111-4111-8111-111111111111',tracking:{enabled:true,serverGA4:false}};
        w.nativeTracking.confirm(response, submitted); w.nativeTracking.confirm(response, submitted);
        assert.equal(w.dataLayer.filter(event => event[1] === 'generate_lead').length,1);
        assert.equal(w.fbq.queue.filter(event => event[1] === 'Lead').length,1);
        assert.equal(JSON.stringify(w.dataLayer).includes('private@example.test'),false);
        const withdrawal=w.nativeConsent.set({analytics:false, marketing:false});
        assert.equal(w.document.cookie,'session=essential','withdrawal purges configured names only');await withdrawal;
        assert.equal(w.document.querySelectorAll('[data-attribution]').length,0);
        assert.equal(w['ga-disable-G-TEST123'],true);
        await w.nativeConsent.set({analytics:true,marketing:true});
        w.document.cookie='_ga=retained; path=/';w.document.cookie='_fbp=retained; path=/';
        savedConsent=w.localStorage.getItem('alltracking_consent');
      }
      const transition = new w.Event('astro:before-preparation',{cancelable:true});
      w.document.dispatchEvent(transition);
      assert.equal(transition.defaultPrevented,true,'installed runtime cancels SPA preparation before scripts can survive the swap');
      if(!excluded){
        assert.equal(w.allTrackingGuard,undefined);assert.equal(Object.hasOwn(w.document,'cookie'),false);
        assert.match(w.document.cookie,/_ga=retained/);assert.match(w.document.cookie,/_fbp=retained/);
        assert.equal(w.document.querySelector('[data-alltracking-debug]'),null);
        assert.equal(w.nativeTracking.payload().consent.analytics,false);
      }
      assert.deepEqual(errors,[],'no errors during built classic/module execution');
      checked++;
    } finally {w.close();}
  }
} finally {stop();}
console.log(JSON.stringify({pack:packed.filename, files:packed.files.length, size:packed.size, unpackedSize:packed.unpackedSize, pagesBuilt:4, builtRuntimeDocumentsChecked:checked, consumerTypes:'PASS', firstHeadBeforeAuthorScripts:'PASS', duplicateHeadBootstrap:'singleton PASS', settingsExport:'PASS', diagnostics:'PASS', limitation:'FIRST-head protection requires TrackingHead before author scripts with the same integration options', network:'offline fixture transport only', runtime:'JSDOM executes emitted classic scripts, then bundled built module scripts'}));
