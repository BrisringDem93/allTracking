import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const entry = new URL('../src/index.ts', import.meta.url);

test('official setup injects one bundled page bootstrap with validated local options', async () => {
  assert.ok(existsSync(entry), 'Astro integration is missing');
  const {default: allTracking} = await import(entry);
  const integration = allTracking({exclude:['/private'], configEndpoint:'/tracking/config', consentEndpoint:'/privacy/choice', policyEndpoint:'/privacy/policy'});
  const calls=[];
  integration.hooks['astro:config:setup']({injectScript:(...args)=>calls.push(args)});
  assert.equal(calls.length,1);
  assert.equal(calls[0][0],'page');
  assert.match(calls[0][1],/startAllTracking/);
  assert.match(calls[0][1],/\/tracking\/config/);
  assert.doesNotMatch(calls[0][1],/sanity|\bGHL\b|clarity\.ms/i);
  for (const endpoint of ['https://evil.test/api','//evil.test/api','/api?secret=yes','/api#hash','/api\\evil','/api\n','/%2f%2fevil','/../api']) {
    assert.throws(()=>allTracking({configEndpoint:endpoint}),/endpoint|path/i,endpoint);
  }
  assert.throws(()=>allTracking({exclude:['https://evil.test']}),/path/i);
  assert.throws(()=>allTracking({exclude:'/private'}),/exclude/i);
  assert.throws(()=>allTracking({unknown:'secret'}),/unknown/i);
});

test('guard/debug options are strict and omitted options stay off',async()=>{
  const {normalizeOptions}=await import('../src/options.ts');
  assert.equal(normalizeOptions().guard.mode,'off');
  assert.equal(normalizeOptions().debug.mode,'off');
  const normalized=normalizeOptions({guard:{mode:'enforce',rules:[]},debug:{mode:'public',settingsPath:'/admin/tracking'}});
  assert.equal(normalized.guard.mode,'enforce');
  assert.equal(normalized.debug.settingsPath,'/admin/tracking');
  for(const guard of [null,{mode:'on'},{extra:true},{sweepDuration:-1}])assert.throws(()=>normalizeOptions({guard}));
  for(const debug of [null,[],{}, {mode:'on'},{mode:'public',extra:true},{mode:'off',settingsPath:'/admin//tracking'},{mode:'public',settingsPath:'/admin/../tracking'},{mode:'public',settingsPath:'/'+ 'a'.repeat(201)}])assert.throws(()=>normalizeOptions({debug}));
});

test('official head-inline is synchronous, self-contained and injection-safe before user scripts',async t=>{
  const {default:allTracking}=await import(entry);
  const calls=[];
  allTracking({guard:{mode:'enforce',sweepDuration:0,rules:[{enabled:true,label:'</script><script>window.pwned=1</script>\u2028\u2029',category:'analytics',match:'equals',value:'_ga',action:'block_delete'}]},debug:{mode:'public'}}).hooks['astro:config:setup']({injectScript:(...args)=>calls.push(args)});
  assert.deepEqual(calls.map(x=>x[0]),['head-inline','page']);
  const script=calls[0][1];
  assert.doesNotMatch(script,/<\/script|\u2028|\u2029/);
  assert.doesNotMatch(script,/\bimport\s/);
  const d=new JSDOM('<!doctype html><html><head></head><body></body></html>',{url:'https://fixture.test/',runScripts:'outside-only'});t.after(()=>d.window.close());
  d.window.eval(script);
  const guard=d.window.allTrackingGuard,descriptor=Object.getOwnPropertyDescriptor(d.window.document,'cookie');
  d.window.eval(script);
  assert.equal(d.window.allTrackingGuard,guard,'repeated head evaluation reuses the document state');
  assert.equal(Object.getOwnPropertyDescriptor(d.window.document,'cookie').set,descriptor.set);
  d.window.document.cookie='_ga=denied; path=/';
  d.window.document.cookie='session=essential; path=/';
  assert.equal(d.window.document.cookie,'session=essential');
  assert.equal(d.window.allTrackingGuard.stats().blocked,1);
  assert.equal(d.window.pwned,undefined);
});

test('off and disabled never inject head enforcement, even when public diagnostics is requested',async()=>{
  const {default:allTracking}=await import(entry);
  for(const options of [{},{guard:{mode:'off'},debug:{mode:'public'}},{disabled:true,guard:{mode:'enforce'},debug:{mode:'public'}}]){
    const calls=[];
    allTracking(options).hooks['astro:config:setup']({injectScript:(...args)=>calls.push(args)});
    assert.deepEqual(calls.map(x=>x[0]),['page']);
  }
});
