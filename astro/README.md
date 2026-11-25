# allTracking per Astro

Integrazione ESM `@alltracking/astro`, separata dal [plugin WordPress](../README.txt).
Include il runtime browser estratto dal sito: consenso nativo con ricevute, adapter Cookiebot/iubenda, GA4/Meta oppure GTM, attribuzione in memoria e conversioni **confermate dal backend**. Nessuna dipendenza runtime aggiuntiva oltre al peer Astro. Sanity non è richiesto.

**Non comprende un backend HTTP/database, un CRM, l'invio server-side GA4/CAPI/n8n, Clarity, tracker GHL o testi legali del sito originale.** Il backend del progetto deve implementare il contratto sotto. Senza backend/policy validi il consenso opzionale resta negato. Non sostituire la verifica delle ricevute con un endpoint che si limita a ripetere il JSON ricevuto.

## Installazione locale

Pacchetto non pubblicato su npm. Dal checkout di questo repository:

```sh
cd astro
npm ci --ignore-scripts --no-audit --no-fund
npm run build
npm pack --ignore-scripts
```

Nel **progetto Astro destinatario**, installare il tarball prodotto:

```sh
npm install /percorso/allTracking/astro/alltracking-astro-0.2.2.tgz
```

Richiede Astro `^7.2.0`; la fixture verifica **Astro 7.2.0**, Node 22.22.1 e npm 10.9.4. Il pacchetto dichiara Node >=22.18.0: rispettare anche i requisiti della versione Astro scelta (versioni più recenti possono richiedere Node più recente). Non installare la radice Git come dipendenza npm: quella contiene WordPress.

`astro.config.mjs`:

```js
import {defineConfig} from 'astro/config';
import allTracking from '@alltracking/astro';

export default defineConfig({
  integrations: [allTracking({
    exclude: ['/admin', '/preview'],
    configEndpoint: '/api/tracking-config',
    policyEndpoint: '/api/consent-policy',
    consentEndpoint: '/api/consent',
  })],
});
```

Le opzioni mostrate corrispondono ai default, salvo `exclude` che per default contiene solo `/admin`. `disabled: true` impedisce richieste, consenso e tracking; restano installate solo le guardie di navigazione e dei form del ClientRouter descritte sotto. Opzioni sconosciute, booleani non booleani, endpoint esterni e percorsi invalidi sono rifiutati alla configurazione. Gli endpoint sono percorsi assoluti **same-origin**, senza query/fragment, percent-encoding o traversal; caratteri ammessi: lettere, cifre, `_`, `-`, punti interni e `/`. Per un progetto sotto `base`, specificare esplicitamente gli endpoint con il prefisso corretto: non viene indovinato.

`exclude` sostituisce la lista di default: ogni voce esclude il percorso esatto e tutti i discendenti, non prefissi simili (`/admin` non esclude `/admin-help`), né glob. `/` esclude tutto. Il confronto decodifica il pathname. Anche `<html data-runtime="off">` esclude il documento. Le esclusioni sono un gate tracking, **non** un controllo di accesso alla pagina.

L'integrazione usa l'hook ufficiale [`astro:config:setup` con `injectScript('page', ...)`](https://docs.astro.build/en/reference/integrations-reference/#injectscript-option); Vite risolve e compila il runtime, senza snippet CMS o `eval` nell'applicazione.

### Navigazioni e ClientRouter

**Scelta deliberata: navigazione multipagina, non tracking SPA.** Se il progetto usa `<ClientRouter />`, il runtime annulla `astro:before-preparation`. Astro 7.2.0 passa quindi alla navigazione completa prima di fetch/swap del nuovo documento: i tag già eseguiti non possono sopravvivere in una route esclusa, né accumulare listener o pageview errate. Vale anche partendo da una pagina esclusa. Lo smontaggio avviene su `pagehide`, cioè solo quando il documento viene davvero lasciato: se la navigazione forzata non scarica la pagina (download, risposta 204, Stop) banner, consenso e conferme restano operativi. **I form non passano dal ClientRouter**: a ogni `submit` il runtime marca il form con `data-astro-reload`, così il browser esegue l'invio nativo con metodo, corpo POST e `target` originali (il ripiego di Astro dopo una preparazione annullata sarebbe un GET senza corpo, con perdita dei dati inviati). Al ripristino dalla BFCache (`pageshow.persisted`) il runtime nega il tracking nel vecchio documento e richiede un reload completo per rileggere configurazione e policy, senza riattivare closure o risposte asincrone precedenti. Non modificare questa guardia senza una gestione completa del ciclo di vita dei provider. Le transizioni native *cross-document* del browser restano compatibili; gli elementi `transition:persist` non persistono nelle navigazioni complete.

Non cambiare route con `history.pushState`/DOM swap personalizzati: fuori dal router supportato il payload e `confirm()` negano l'accesso quando cambia il pathname, ma il pacchetto non può disinstallare codice di terzi già eseguito. Analogamente non usare iframe persistenti per trasportare i tag fra documenti. I tag caricati devono comunque rispettare la revoca durante il documento corrente.

## Popup diagnostico e impostazioni (0.2.0)

Il **popup di controllo WordPress** è distinto dal banner consenso: ora è disponibile anche in Astro, con cinque schede **Consenso / Cookie / Attesi / Blocco / Tag & GA4**, badge, aggiornamento live, copia JSON, trascinamento mouse/touch/penna, posizione e scheda persistenti, reset e navigazione da tastiera. Non invia eventi e non esporta valori dei cookie, query string o segreti. Stato server, HttpOnly, coda e consegna provider sono dichiarati **non disponibili**, non simulati.

Guard e diagnostica sono **OFF per default**. La modalità diagnostica `public` è esplicitamente visibile a tutti: usarla solo su staging/test, non è un login amministrativo. Nessuna route amministrativa viene creata. Per attivare le opzioni creare nel progetto destinatario un file condiviso `alltracking-options.mjs`:

```js
export default {
  exclude: ['/admin', '/preview'],
  guard: {mode: 'enforce', sweepInterval: 2000, sweepDuration: 60000},
  debug: {mode: 'off', settingsPath: '/admin/tracking'},
};
```

Usare **lo stesso oggetto** nell'integrazione (`allTracking(options)`) e nel layout. Per proteggere anche gli script classici scritti dal progetto dentro head, collocare `TrackingHead` **PRIMA di ogni script**:

```astro
---
import TrackingHead from '@alltracking/astro/TrackingHead.astro';
import ConsentPanel from '@alltracking/astro/ConsentPanel.astro';
import options from '../../alltracking-options.mjs'; // adattare il percorso
---
<html lang="it">
  <head>
    <TrackingHead options={options} />
    <title>Il mio progetto</title>
  </head>
  <body><main><slot /></main><ConsentPanel /></body>
</html>
```

L'iniezione automatica ufficiale `head-inline` resta un fallback, ma Astro la aggiunge **dopo** i contenuti head dell'autore: da sola non può bloccare script precedenti. Il componente e il fallback condividono un singleton, senza duplicare setter/listener. `TrackingHead` include il bundle head come stringa durante la build (import Vite `?raw`): non legge file né risolve pacchetti al render, quindi funziona anche su pagine on-demand con adapter serverless/edge. CSP deve autorizzare lo script inline locale; non abbassare una CSP esistente per nascondere un errore di installazione.

Il guard riusa il contratto WordPress: `off`, `monitor` (osserva senza bloccare/cancellare), `enforce`; 25 preset, otto operatori, regole ordinate, azioni blocco/cancellazione, allowlist protetta CMP/sessione più personalizzazioni. `rules` omesso usa i preset; `rules: []` rimane vuoto. La scelta viene riletta a ogni scrittura. Regex e regole sono configurazione amministrativa fidata, non input di visitatori; la validazione sintattica non rende una regex resistente a ReDoS. La categoria `preferences` resta negata nel runtime integrato: il banner e il contratto ricevute gestiscono analytics/marketing; non viene dedotta da altre categorie. Non sono portati pulizia HTTP server-side, esclusione automatica degli utenti WP, Complianz/OneTrust o gestione credenziali.

Prima della validazione di policy e ricevuta, il guard **blocca nuove scritture opzionali** ma rinvia la pulizia dei cookie esistenti per non perdere gli ID già consentiti tra pagine. Dopo `consent.configure()` pulisce quelli effettivamente negati/scaduti; una revoca successiva è immediata. Il rinvio comprende il caricamento del runtime/DOMContentLoaded e poi le due letture parallele con timeout di 4 secondi: **non è un limite assoluto di 4 secondi da head**. Se il modulo non parte, la pulizia non si sblocca. Il passaggio a un'altra pagina non è una revoca e non cancella i cookie già consentiti: alla partenza il guard resta installato con il consenso **congelato** a quel momento e senza più pulizie, quindi le scritture non consentite di listener `pagehide`/`unload` restano bloccate. Lo stesso vale se il pathname cambia via `history`: nuove scritture negate, nessuna cancellazione. Le scritture "in scadenza" sono sempre lasciate passare, ma per i nomi bloccati vengono forzate a cancellazione (`Max-Age=0`), anche quando la data `Expires` non è valida per i parser cookie. Il guard non intercetta storage, header Set-Cookie, HttpOnly o altri domini.

### Editor impostazioni senza nuovo CMS

Su una pagina del proprio progetto (per esempio `/admin/tracking`, esclusa dal tracking):

```astro
---
import TrackingSettings from '@alltracking/astro/TrackingSettings.astro';
---
<html lang="it"><head><title>Impostazioni tracking</title></head>
  <body><TrackingSettings initial={{tracking: {cmp: 'bridge'}, guard: {mode: 'off'}, debug: {mode: 'off'}}} /></body>
</html>
```

L'editor comprende modalità/ID pubblici, attribution/moduli/CMP, guard, aggiunta/rimozione/riordino/abilitazione regole, preset, allowlist, tester condiviso col motore reale e diagnostica. L'allowlist mostra ed esporta solo i pattern personalizzati: quelli protetti CMP/sessione sono sempre aggiunti dal pacchetto. Campi numerici vuoti esportano i default (2000/60000 ms). Il percorso impostazioni segue le stesse regole dell'integrazione (niente punti, segmenti vuoti o oltre 200 caratteri), così il JSON esportato è accettato da `allTracking()`. I flag server sono sola informazione. **Esporta un file JSON validato, non salva remotamente e non attiva il sito**: nessun endpoint di scrittura anonimo o CMS inventato. Applicare `json.tracking` alla risposta `configEndpoint`; usare `json.guard` e `json.debug` nelle opzioni condivise e ricostruire il progetto. Per editing persistente live occorre integrare il proprio CMS autenticato con autorizzazione e readback server-side; non è incluso. Proteggere la pagina host se contiene configurazione interna. Non inserirvi credenziali.

## Pannello consenso

Nel layout pubblico, una sola istanza:

```astro
---
import ConsentPanel from '@alltracking/astro/ConsentPanel.astro';
---
<html lang="it">
  <head><title>Il mio progetto</title></head>
  <body>
    <main><slot /></main>
    <ConsentPanel>
      <Fragment slot="links">
        <a href="/privacy-policy">Privacy policy</a>
        <a href="/cookie-policy">Cookie policy</a>
      </Fragment>
    </ConsentPanel>
  </body>
</html>
```

Il componente non inizializza un secondo runtime. Il suo slot `links` sostituisce i collegamenti di default, in banner e dialog. Fornisce regione non modale, azioni equivalenti, controlli etichettati, focus visibile, dialog nativo/Escape e pulsante permanente di riapertura. Se la ricevuta non viene confermata, un avviso `role="status"` resta visibile accanto al pulsante di riapertura fino al salvataggio successivo; mentre il dialog è aperto le modifiche non salvate non vengono sovrascritte da focus, storage o eventi CMP. Non renderizzarlo su pagine escluse; il runtime comunque non lo attiva lì. Le informative complete, raggiungibili anche senza JS, vanno scritte per il progetto: i testi generici del pannello **non** sono una privacy/cookie policy.

Alternativa senza il componente: usare i metodi di `window.nativeConsent` dopo `window.allTrackingRuntime`:

```js
await window.allTrackingRuntime;
await window.nativeConsent?.set({analytics: true, marketing: false});
const consent = window.nativeConsent?.get();
// Revoca immediata anche se il backend fallisce:
await window.nativeConsent?.set({analytics: false, marketing: false});
```

Non assegnare `window.allTrackingConsent`: è un getter protetto. In TypeScript, aggiungere `import type {} from '@alltracking/astro/client';` a `src/env.d.ts` se il modulo client non è già importato: rende disponibili i tipi delle proprietà `window` senza avviare un secondo runtime. I grant restano negati fino a risposta server valida; rifiuto/revoca diventano effettivi immediatamente. La scelta necessaria usa `localStorage.alltracking_consent` (solo categorie/versione/UUID/data/conferma), mai campagne persistenti. Scadenza massima: 180 giorni dalla data server. Versione diversa, stato pending, storage corrotto e conferma incoerente non autorizzano tag. Se anche scrittura e rimozione storage falliscono, un tombstone in memoria impedisce ripristini del vecchio grant nello stesso documento; non garantisce persistenza dopo chiusura totale del browser con storage inaccessibile.

- `bridge`: consenso nativo; è il default.
- `cookiebot`: scelta nativa intersecata con `Cookiebot.consent.statistics` e `.marketing`; eventi `CookiebotOnConsentReady`, `CookiebotOnAccept`, `CookiebotOnDecline`.
- `iubenda`: scelta nativa intersecata con i purpose `[4]`/`[5]`. Cerca fra i cookie `_iub_cs-*`, ignora `-uspr` e preferisce un vero cookie di consenso all'API eventualmente obsoleta; in assenza di cookie valido usa `getPreferences()`. `consent:false` e i dinieghi espliciti prevalgono. Un osservatore della pagina visibile aggiorna anche le revoche senza eventi; nei callback ufficiali è comunque possibile notificare `window.dispatchEvent(new Event('iubenda:consent'))`. Nessun nuovo grant senza scelta nativa e ricevuta confermata.
- GPC/DNT sono metadata della ricevuta, non sostituiscono la scelta esplicita. Valutare la policy applicabile al proprio progetto prima dell'attivazione.

Con guard OFF il runtime non intercetta cookie/storage né cancella cookie. Con guard attivato intercetta soltanto `document.cookie` e applica pulizia limitata alle regole; non può annullare richieste già partite. Revoca: attribution/hidden rimossi, Consent Mode aggiornato, `ga-disable-*` attivato, Meta `consent/revoke`. Contenitore GTM e tag esterni devono rispettarla, anche evitando ping cookieless.

## Backend richiesto

Si può usare Astro SSR con un adapter, un Worker/reverse proxy o un backend esistente. Un sito solo statico deve fornire separatamente l'endpoint POST delle ricevute; `astro preview` da solo non lo implementa. Nessuna route viene creata automaticamente. Le richieste usano `credentials:'same-origin'`, `redirect:'error'`, `referrerPolicy:'no-referrer'`, timeout 4 secondi e, per i GET, `cache:'no-store'`. Il backend deve usare risposte JSON, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, senza CORS pubblico né redirect.

### GET configEndpoint

Oggetto pubblico, mai token/password/URL webhook. Esempio **da adattare**, tutto spento inizialmente:

```json
{
  "enabled": false,
  "mode": "off",
  "cmp": "bridge",
  "attribution": false,
  "formEvents": false
}
```

`mode`: `off`, `direct` o `gtm`. Abilitare esplicitamente `enabled:true` e la modalità desiderata. Direct accetta `ga4Id: "G-..."` e/o `metaPixelId` numerico; GTM accetta `gtmId: "GTM-..."` e ignora gli ID diretti. ID invalidi vengono eliminati; stringhe truthy non attivano booleani. La configurazione è riletta a ogni documento; modifiche a una pagina aperta richiedono reload. GET fallito/config assente: tracking spento. Anche il consenso opzionale resta negato finché il JSON non contiene un `cmp` esplicito valido (`bridge`, `cookiebot`, `iubenda`): un errore di configurazione non deve eliminare l'intersezione con la CMP esterna. I flag normalizzati `serverGA4`, `serverMeta`, `serverN8n` mantengono il contratto upstream ma **non installano invii server** in questo pacchetto.

### GET policyEndpoint

```json
{
  "ready": true,
  "version": "policy-REVISION-HASH",
  "privacy": "Informativa completa del progetto in testo semplice",
  "cookie": "Informativa cookie completa del progetto in testo semplice"
}
```

`ready:true` va restituito **solo dopo revisione delle informative effettive**. Versione: 1–128 lettere/cifre/`.`/`_`/`-`; testi non vuoti, massimo 50.000 caratteri ciascuno. Versionare lato backend con un hash di revisione e testi completi: qualunque modifica deve cambiare versione e invalidare le vecchie scelte. Non copiare la versione personale del sito sorgente. La modalità CMP è posseduta da configEndpoint, non dal JSON policy. Se necessario, il frontend riporta i testi tramite `textContent` in elementi `[data-policy-text="privacy"]` / `[data-policy-text="cookie"]` e la versione in `[data-policy-version]`: nessun HTML remoto eseguito.

### POST consentEndpoint

Corpo JSON esatto:

```json
{
  "subjectId": "11111111-1111-4111-8111-111111111111",
  "receiptId": "22222222-2222-4222-8222-222222222222",
  "version": "policy-REVISION-HASH",
  "necessary": true,
  "analytics": true,
  "marketing": false,
  "signal": "none"
}
```

Entrambi gli identificatori sono UUID v4 casuali; `signal` è `none`, `gpc` o `dnt`. Il server deve verificare metodo POST, Origin esatto/same-origin e allowlist del proprio ambiente, `Sec-Fetch-Site` se presente, Content-Type JSON, schema esatto senza chiavi duplicate, limiti di corpo (riferimento upstream: 1.024 byte), categorie booleane e versione corrente. Usare query parametrizzate e rate limit/WAF, non fidarsi degli UUID contro gli abusi.

Registrare la ricevuta **durevolmente**, append-only con timestamp server, prima della conferma; retry identico restituisce l'originale, collisione con dati diversi restituisce conflitto senza sovrascrittura. Rileggere e verificare la riga esatta. Risposta solo dopo prova della scrittura:

```json
{
  "ok": true,
  "receipt": {
    "subjectId": "11111111-1111-4111-8111-111111111111",
    "receiptId": "22222222-2222-4222-8222-222222222222",
    "version": "policy-REVISION-HASH",
    "necessary": true,
    "analytics": true,
    "marketing": false,
    "signal": "none",
    "createdAt": "2026-01-01T12:00:00.000Z"
  }
}
```

La data d'esempio non è riutilizzabile: restituire la vera data server ISO corrente. Il browser richiede HTTP OK e tutti i campi coerenti e un timestamp più recente di 180 giorni, non oltre 24 ore avanti rispetto all'orologio del browser: la tolleranza evita di rifiutare ogni ricevuta ai visitatori con l'orologio indietro; la data salvata è limitata all'ora locale, quindi la scadenza non supera mai 180 giorni dalla data server. Il segnale server può differire dal client. Timeout, risposta parziale o mismatch non abilitano grant e non provocano retry automatici. Una ricevuta non autentica la persona, non lega un lead a un'identità, non è una protezione anti-bot: chi ne possiede una copia può ripresentarla durante la validità. Definire retention, diritti/cancellazione e audit per il progetto; questo pacchetto non promette revoca globale di copie esterne.

## Moduli e conversioni confermate

Aggiungere `data-landing-form` ai form che devono ricevere hidden di attribuzione. I campi creati dal runtime sono marcati `data-attribution`, non sovrascrivono campi applicativi preesistenti e vengono aggiornati anche sui submit di form dinamici. L'applicazione resta responsabile di validazione, invio form, gestione errori e UI di conferma: un submit/click o HTTP 2xx non genera un lead automaticamente.

Nel submit handler applicativo, conservare lo snapshot prima della richiesta:

```js
await window.allTrackingRuntime;
const tracker = window.nativeTracking;
const tracking = tracker?.payload();
const response = await fetch('/api/lead', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({/* campi operativi validati, submissionId stabile */ tracking}),
});
const result = await response.json();
if (response.ok) tracker?.confirm(result, tracking?.consent);
```

Il backend del form deve validare campi operativi, origine/rate limit, idempotenza e **conferma effettiva del salvataggio** prima di rispondere:

```json
{
  "ok": true,
  "confirmed": true,
  "eventId": "33333333-3333-4333-8333-333333333333",
  "tracking": {"enabled": true, "serverGA4": false}
}
```

Generare un `eventId` UUID v4 stabile per la stessa operazione accettata. `confirmed:true` non deve comparire per honeypot, errori di validazione, backend indisponibile o salvataggi dal risultato incerto. Bloccare submit concorrenti e riusare la stessa chiave di idempotenza negli eventuali retry applicativi; la deduplica browser è per documento/eventId, non sostituisce l'idempotenza durevole del backend.

`payload()` include `{consent, attribution, receipt?, clientId?}`. **Prima** di usare attribution o inviare conversioni server, rileggere dal database la coppia `receipt.subjectId`/`receipt.receiptId`, versione corrente, timestamp <180 giorni, necessario e categorie identiche a quelle dichiarate. Se mancante/invalida/DB indisponibile: continuare il flusso operativo senza attribution né tracking opzionale. Non usare hidden/top-level non filtrati, non inoltrare riferimenti ricevuta a provider, CRM o log. Per normalizzazione/filtraggio puro si possono importare `normalizeConfig`, `normalizeConsent`, `sanitizeAttribution` da `@alltracking/astro/shared`; questi helper **non verificano ricevute**.

Il browser emette solo con configurazione attiva, `formEvents:true`, risposta confermata valida e intersezione fra consenso al submit e consenso corrente. Il medesimo eventId è consumato una volta nel documento:

| Modalità | Visita | Conversione |
|---|---|---|
| Direct GA4 | Dopo analytics, senza query/referrer nel payload proprio | `generate_lead`, salvo `result.tracking.serverGA4 === true` |
| Direct Meta | Dopo marketing | Pixel `Lead`, `eventID` uguale all'eventuale CAPI server |
| GTM | Contenitore solo dopo **entrambi** i consensi | `dataLayer` `generate_lead` con `event_id`, mai tag diretti duplicati |

Se il backend ha tentato GA4 Measurement Protocol, `serverGA4:true` deve restare vero anche con timeout (consegna incerta), evitando un secondo invio browser. Non impostarlo solo perché il flag è configurato: senza vero clientId `_ga`, consenso e credenziali server l'evento resta del browser. Per CAPI usare lo stesso eventId e marketing verificato. In GA4 disabilitare eventi automatici dei moduli/Misurazione avanzata; in GTM usare solo `generate_lead` confermato. Non installare snippet GA/Meta/GTM/allTracking duplicati. La configurazione interna dei provider resta fuori dal controllo del pacchetto.

## Dati e limiti

- Analytics: UTM standard e `cmp`, whitelist e massimo 200 caratteri; scartati email/URL/marker di segreti riconoscibili. Non è un classificatore universale di dati personali: non inserire PII nei parametri campagne.
- Marketing: click ID supportati (`fbclid`, `gclid`, `gbraid`, `wbraid`, `msclkid`, `ttclid`, `twclid`, `li_fat_id`), `_fbc`/`_fbp` validi. `fbc` può derivare da un fbclid reale; `fbp` non viene inventato. `external_id` UUID solo in memoria e mai preso dall'URL.
- Attribution dura il documento, non 90 giorni né cross-page. Query/referrer non entrano nei payload GA propri; un provider caricato è comunque codice di terzi e può leggere il documento/URL. Evitare dati personali in URL e contenuti non protetti.
- I placeholder opzionali upstream restano limitati a moduli locali `/_astro/NOME.js` senza query/inline/src attivo: `<script type="text/plain" data-consent-category="analytics" data-consent-src="/_astro/NOME.js"></script>`. Non funzionano con un diverso prefisso `base`/`assets`; usare un modulo applicativo esplicito in quel caso. I moduli già eseguiti devono fermare i propri effetti su `alltracking:consent`; nessuna promessa di unload automatico.
- Policy/backend/provider reali, CSP, retention e conformità vanno verificati nel progetto destinatario. Una suite offline non prova consegna GA4/Meta né conformità legale.

## Verifica riproducibile

```sh
cd astro
npm ci --ignore-scripts --no-audit --no-fund
npm run verify
# test Node/JSDOM + componenti Astro reali, tsc noEmit, compilazione JS/d.ts,
# npm pack reale, installazione tarball offline in .fixture,
# typecheck del consumatore e build Astro statica di quattro pagine.
```

I test importano direttamente i sorgenti `.ts` e richiedono il type stripping di Node ≥22.18; su Node 22.6–22.17 eseguirli con `NODE_OPTIONS=--experimental-strip-types`. Test e fixture girano anche su Windows. `npm run test:fixture` richiede `npm run build` già completato. La fixture usa solo dati sintetici e trasporti intercettati: esegue il JavaScript effettivamente prodotto dal build in JSDOM, su quattro pagine incluse route escluse ed editor, con script head/classici/moduli reali, senza richieste a CRM/provider. JSDOM non è Chromium e non implementa il top layer/focus trap del dialog; i test UI simulano soltanto le API native mancanti. La fixture e i tarball sono in `.fixture/`, ignorata. Il lockfile fissa le dipendenze di sviluppo; il pacchetto installato contiene JS/d.ts, bundle head locale, tre componenti Astro e README. Nessun publish/deploy eseguito.

## Allineamento alle versioni WordPress

La base di pubblicazione è `main` **`f7da8a349c4f024ee4a3b7970b4b995075149568`**, WordPress **0.14.1**, che comprende la PR #29 (`fix/iubenda-marketing-consent-unslash`, **`08e0ca38e3fc7557813173e8adbd6e8301f3cb94`**). È stato ricontrollato anche `feat/debug-bar-public-drag` **`963e584b9ea350aebebff3573d8a9055e268a82c`**, ancora nella PR #28 aperta: contiene ulteriori fix distinti già considerati nell'adattamento Astro. Il branch Astro preserva integralmente i file WordPress dell'attuale `main`; non integra la PR #28 in WordPress.

Portati widget diagnostico/trascinamento/impostazioni non segrete, motore cookie e preset correnti, selezione multi-cookie iubenda, lettura fresca del consenso e aggiornamento senza reload. Preservati identità marketing stabile nel documento, pulizia dei soli hidden proprietari e deduplica per evento confermato. Non è un clone integrale del backend WP: cookie persistenti `fst_uid/fst_clid`, PHP unslash, SQL/cron/coda/REST/utenti WP, gestione segreti e salvataggio CMS non sono inclusi. Le conversioni restano più restrittive: solo conferma effettiva del backend, mai semplice submit o debounce globale.

## Provenienza e differenze deliberate

Sorgente letto: sito `protocollodeminicis.com`, commit **`c4c8663a1200c161aaed108e75e679173932acc9`**. Base originaria dell'estrazione allTracking: **`2f1721120ae3a384a34d9e3108fe2b3e76766950`**; base aggiornata del branch di pubblicazione: **`f7da8a349c4f024ee4a3b7970b4b995075149568`**. Il sito documenta l'antenato WordPress `2d198e42d9f1deeda128b6eaf26bb26ef5c78f2f`; questa estrazione non modifica WordPress.

- `src/lib/tracking.ts` → `src/tracking.ts`: contratto pubblico e helper condivisi.
- `src/scripts/tracking-client.ts` → `src/tracking-client.ts`: tag/attribuzione/conferme esistenti, escluso il bootstrap CMS-specifico; aggiunti gate di documento e notifica iubenda diretta.
- `src/scripts/consent-client.ts` → `src/consent-client.ts`: ricevute, revoca, storage e adapter esistenti; endpoint configurabile e gate di documento, senza policy personale/debug del sito.
- `src/scripts/consent-ui.ts` e `src/components/ConsentPanel.astro`: controller e markup accessibile riusati, disclosure generica e CSS autonomo invece del tema/disclosure GHL/Clarity.
- Nuovi `src/index.ts`, `src/options.ts`, `src/runtime.ts`: integrazione ufficiale e bootstrap minimo. Test glue scritti/eseguiti RED prima del codice; regressioni esistenti portate da `tracking.test.mjs`, `tracking-browser.test.mjs`, `consent-browser.test.mjs`, `consent-ui.test.mjs`, escludendo controlli specifici di layout/CMS/backend del sito.
- Letti anche `worker/tracking.ts`, `worker/consent.ts`, `migrations/0001_consent.sql`, schema Sanity trackingSettings, `docs/tracking.md` e `docs/consent.md`: **non inclusi come backend**; il contratto sopra conserva i loro confini senza hardcode di dominio/CRM/segreti/policy.

`UNLICENSED` è intenzionale: nessuna licenza di redistribuzione o pubblicazione npm viene concessa/inventata da questa estrazione. Il proprietario deve decidere la licenza prima di una pubblicazione separatamente autorizzata.
