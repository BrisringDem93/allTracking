# Changelog — Quick Tracking Integration

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/).

## [0.12.0] - 2026-07-29 — Blocco dei cookie senza consenso

### Aggiunto
- **Nuovo tab "Blocco Cookie"** (`includes/cookie-guard/`, `assets/js/cookie-guard.js`):
  impedisce la scrittura dei cookie non consentiti e cancella quelli già presenti, con
  regole **granulari per categoria di consenso**. Il guard viene stampato inline in
  `wp_head` a **priorità 0** — prima del container GTM e di qualunque script accodato —
  e sostituisce il setter di `document.cookie`: la valutazione avviene quindi *prima*
  che un pixel possa scrivere. Una passata periodica cancella inoltre i cookie
  preesistenti che violano le regole.
- **Regole con operatori espliciti**: è esattamente / contiene / inizia con / finisce con
  / *inizia con … e finisce con …* / pattern con `*` e `?` / regex / appartiene al
  dominio (sottodomini inclusi). Ogni regola ha categoria
  (`marketing`, `analytics`, `preferences`, `always`), opzione maiuscole/minuscole e
  azione (blocca la scrittura, cancella se presente, entrambe). Vince la prima regola
  che blocca; una regola blocca **solo quando manca il consenso della sua categoria**.
- **22 regole predefinite ATTIVE out of the box** (GA `_ga`/`_ga_*`/`_gid`/`_gat*`,
  Google Ads/DoubleClick `_gcl_*`/`_gac_*`/`IDE`, Meta `_fbp`/`_fbc`, Hotjar, Clarity,
  Matomo, UET, LinkedIn, TikTok, Pinterest, X, Yandex, Snapchat): il blocco funziona
  appena installato, senza configurare nulla. Le regole sono modificabili dal backend —
  si disattivano una per una, si eliminano (svuotando il campo «Valore») o si
  ripristinano con **"Ripristina configurazione predefinita"**. Nessuna regola
  predefinita colpisce cookie di sistema o del CMP (verificato dai test).
- Le regole predefinite sono un **default virtuale**: finché l'amministratore non salva,
  l'opzione `ati_cg_rules` non esiste e `rules()` restituisce `default_rules()`. Chi
  elimina tutte le regole e salva non se le vede riapparire (opzione salvata vuota e
  opzione mai salvata sono stati distinti). Filtro `ati_cookie_guard_default_rules`.
- **Pannello di controllo del consenso**: stato per categoria affiancato lato **server**
  (cookie della richiesta) e lato **browser in tempo reale**, CMP rilevati, elenco dei
  cookie presenti con l'esito che ciascuno avrebbe, elenco dei cookie ricevuti dal
  server (inclusi gli `HttpOnly`) e **tester** interattivo su un nome di cookie.
  Il pannello usa lo stesso `cookie-guard.js` del front-end in sola lettura
  (`mode=off`, `expose=true`): l'anteprima non può divergere dal comportamento reale.
- **Rilevamento consenso granulare** (`ATI_Cookie_Consent`) per Complianz, iubenda,
  Cookiebot e OneTrust, con categoria "preferenze/funzionali" (nuova) oltre a marketing
  e analytics. Opzione per **forzare un CMP** quando sul sito ne convivono più di uno e
  indici dei purpose iubenda configurabili (default 3/4/5).
- **Pulizia lato server opzionale** (`ati_cg_server_cleanup`, disattivata): invalida via
  `Set-Cookie` scaduto i cookie della richiesta che violano le regole, su tutte le
  varianti di dominio. Intercetta anche i cookie scritti da header HTTP, invisibili a
  JavaScript.
- `tests/cookie-guard-tests.php` (129 test sul motore di regole PHP) e
  `tests/cookie-guard-tests.js` (87 test sul guard con DOM simulato). Gli stessi casi di
  matching sono verificati su entrambe le implementazioni, che devono restare allineate.
  Coperti anche: copertura delle regole predefinite sui nomi di cookie reali,
  disattivazione di una singola regola predefinita, salvataggio vuoto ed esclusione
  degli utenti loggati.
- Filtri: `ati_cookie_guard_protected_patterns`, `ati_cookie_guard_active`,
  `ati_cookie_guard_script_config`, `ati_cookie_guard_consent_state`.

### Sicurezza
- **Attivo di default** (modalità `enforce`) con le regole predefinite. Una regola blocca
  soltanto quando manca il consenso della sua categoria: con il consenso concesso il
  comportamento del sito è identico alla 0.11.0. Si spegne con un menu a tendina
  (**Disattivato**, che conserva le regole) o si porta in **Monitoraggio**, che logga in
  console cosa bloccherebbe senza toccare nulla.
- Il tab avvisa in modo esplicito quando **nessun CMP è rilevato** e il blocco è attivo:
  in quello scenario il consenso risulterebbe sempre assente e i cookie di analytics e
  marketing verrebbero bloccati per tutti.
- **Allowlist non modificabile** sui cookie che romperebbero il sito o cancellerebbero la
  scelta di consenso: sessione WordPress (`wordpress*`, `wp-*`, `wp_*`), `PHPSESSID`,
  WooCommerce, cookie dei CMP (`cmplz_*`, `_iub_cs-*`, `CookieConsent*`,
  `OptanonConsent`, `cookielawinfo-*`, `cky-*`, …) e del plugin (`fst_*`, `ati_*`).
  Ha la precedenza su qualunque regola, categoria `always` inclusa. Estendibile con
  un'allowlist personalizzata.
- Le **cancellazioni di cookie non vengono mai bloccate** (`expires` nel passato o
  `max-age<=0`): altrimenti nessuno potrebbe più rimuovere un cookie.
- Il blocco **non si applica agli utenti loggati** per default.
- Qualunque eccezione nella valutazione lascia passare la scrittura: il guard non può
  rompere una funzionalità del sito.
- Le regex fornite dall'amministratore vengono compilate in modo isolato: una regex non
  valida non produce match né errori, ed è segnalata nel pannello.

### Invariato
- GA4 server-side, Meta Pixel / Conversions API, n8n, compilazione dei campi hidden e
  rilevamento del consenso marketing esistente: nessuna logica modificata. Il consenso
  marketing e quello analytics del blocco cookie **delegano** alle funzioni già in uso
  (`ati_has_marketing_consent()`, `ATI_Consent_Service::has_analytics_consent()`), così
  le due parti non possono divergere.

## [0.11.0] - 2026-07-29 — Compilazione automatica dei campi hidden nei form

### Aggiunto
- **Campi hidden compilati automaticamente** (`assets/js/form-fields.js`,
  `includes/form-fields.php`): se un form contiene input hidden con nomi noti, il plugin
  li valorizza prima dell'invio, così il provider del form li salva e li inoltra a
  CRM/n8n/Meta CAPI. Nomi riconosciuti: `fbclid`, `gclid`, `fbc`, `fbp`, `gbraid`,
  `wbraid`, `msclkid`, `ttclid`, `twclid`, `li_fat_id`, `utm_source`, `utm_medium`,
  `utm_campaign`, `utm_term`, `utm_content`, `external_id`. Il match funziona anche su
  `form_fields[fbclid]` (Elementor), id `form-field-fbclid`, classe `ati-field-fbclid` e
  attributo `data-ati-field="fbclid"`.
- Sorgenti dei valori: parametri URL, cookie `_fbc` / `_fbp` / `_gcl_aw` / `fst_uid` e
  persistenza dei click id (cookie `fst_clid` 90 giorni + sessionStorage) **solo con
  consenso marketing**, così il click id sopravvive alla navigazione fino al form.
  Senza consenso i valori restano in memoria e i campi si compilano con quanto è
  nell'URL della pagina corrente.
- Nuova impostazione **"Campi hidden nei form"** (`ati_enable_form_fields`, attiva di
  default) nel tab Generale.
- `tests/form-fields-tests.js`: 35 test con DOM simulato (`node tests/form-fields-tests.js`).
- **`_fbc` generato prima del render della pagina** (`fst_capture_fbclid_from_url()`, hook
  `template_redirect`): se l'URL contiene `fbclid`, il cookie `_fbc` non esiste e c'è il
  consenso marketing, il valore viene costruito e persistito **al primo byte di HTML**,
  prima che parta qualsiasi JS. Il campo hidden `fbc` riceve quindi lo **stesso identico
  valore** inviato alla Conversions API. Prima il cookie nasceva solo al ritorno della
  chiamata AJAX/REST: i form compilati (o inviati) prima di quel momento restavano senza `fbc`.

### Corretto
- **`_fbc` con timestamp in millisecondi** (era in secondi): Meta specifica
  `fb.<subdomain>.<creation-time>.<fbclid>` con `creation-time` in millisecondi, come
  lo scrive il Pixel. Il valore costruito lato server era di 3 ordini di grandezza
  inferiore e incoerente con quello client-side. Logica estratta in
  `fst_build_fbc_from_fbclid()` (unica fonte di verità, riusata da
  `fst_build_user_data()` e dalla cattura su `template_redirect`) e coperta da 9 test
  in `tests/run-tests.php`.

### Privacy — nessuno storage senza consenso marketing
- **`_fbc` non viene più scritto senza consenso** (`fst_persist_fbc_cookie()`): prima il
  cookie veniva impostato in ogni caso, appena arrivava un `fbclid`. Il campo `fbc` del
  form resta comunque compilato: `form-fields.js` ricostruisce il valore dal `fbclid`
  dell'URL, senza toccare cookie o storage. `$_COOKIE` viene aggiornato solo quando il
  cookie viene davvero inviato, così rispecchia sempre lo stato del browser.
- **Cookie `fst_ev_id` rimosso** (`tag-inserter.php`): l'event_id per la deduplica Meta
  vive quanto il singolo invio, quindi ora è una variabile in memoria. Prima era un
  cookie da 1 ora scritto anche senza consenso. Nessuna funzione server-side lo leggeva:
  comportamento invariato, un cookie non consentito in meno.
- **Persistenza click id gated in lettura e scrittura**: senza consenso `form-fields.js`
  non scrive né legge `fst_clid` (né cookie né sessionStorage). Se il consenso arriva
  dopo, lo storage viene idratato e i valori in memoria persistiti.
- Audit completo: gli unici punti che scrivono storage sono ora `_fbc`, `fst_uid` e
  `fst_clid`, tutti e tre condizionati al consenso marketing.

### Note
- Nessun identificatore viene inventato: se il valore non esiste il campo resta vuoto.
  Unica costruzione ammessa è `fbc` = `fb.1.<timestamp>.<fbclid>` (stesso formato di
  `fst_build_user_data()`); il cookie `_fbc` reale ha sempre la precedenza. `fbp` è
  copiato solo dal cookie `_fbp` del Pixel, mai generato.
- I campi già valorizzati dal sito non vengono sovrascritti; i campi hidden non
  riconosciuti (es. `_wpnonce`) non vengono toccati.
- La compilazione viene ripetuta sui form inseriti via AJAX/popup, al cambio di consenso
  e in fase di **capture** del submit: i cookie `_fbp`/`_fbc` che compaiono dopo
  l'accettazione del banner finiscono comunque nell'invio.

## [0.10.0] - 2026-07-28 — Pagina settings unificata a tab + credenziali Meta per n8n

### Modificato
- **Menu unificato**: le due pagine "Tracking Integration" e "GA4 Server-Side" sono ora
  un'unica pagina a tab (Generale / Server-Side (n8n & Meta) / GA4 Server-Side) sotto
  un'unica voce di menu. Il vecchio slug `ati-ga4-settings` viene rediretto al tab GA4
  (link/bookmark salvati continuano a funzionare). Nessuna opzione è stata rinominata.
- Le impostazioni n8n (`ati_server_endpoint`, `ati_server_auth_key`,
  `ati_server_auth_value`) sono state spostate nel settings group dedicato
  `ati_server_settings` (stesso nome opzione, valori conservati): ogni tab salva solo
  le proprie opzioni.

### Aggiunto
- **Credenziali Meta CAPI nel tab Server-Side**: campi `ati_meta_dataset_id`
  (Pixel/Dataset ID, fallback sul Facebook Pixel ID client-side) e
  `ati_meta_capi_token` (access token, mascherato: mai renderizzato in HTML, stessa
  logica dell'API Secret GA4). Se impostati vengono inclusi nel payload inviato a n8n
  come `pixel_id` e `access_token` accanto a `data`: il workflow n8n li legge dalla
  richiesta e non deve più tenerli hardcodati.

## [0.9.0] - 2026-07-27 — Visibilità pipeline (log worker + tabella eventi)

### Aggiunto
- Log `[ATI GA4]` anche nel worker della coda: `event_sent`, `event_failed`,
  `event_discarded` (prima l'invio a GA4 dal cron non lasciava traccia nel log).
- Tabella **"Ultimi eventi"** nel pannello (evento, stato, reason_code, tentativi,
  event_id, aggiornamento): fonte di verità della pipeline **indipendente** dal file di
  log PHP (utile quando si consulta il log via FTP/snapshot). Se la tabella è vuota ma
  GA4 riceve comunque un lead, l'evento non arriva dal plugin (probabile tag GTM client-side).

## [0.8.9] - 2026-07-27 — Fix doppio Lead Meta (Fluent emette il successo 2 volte)

### Corretto
- Fluent Forms emette `fluentform_submission_success` **due volte** per lo stesso invio:
  il percorso Meta/n8n inviava il Lead 2 volte (stesso event_id → Facebook dedup, ma 2
  richieste a n8n). Aggiunto debounce per-form (4s) in `fstFlushLead`. Il bridge GA4 era
  già protetto dal proprio debounce (per questo GA4 riceveva un solo `generate_lead`).

## [0.8.8] - 2026-07-27 — Lead Meta su invio riuscito + evento custom form

### Corretto (Meta/n8n, su autorizzazione)
- Il Lead Meta/n8n (`tag-inserter.php`) ora parte **solo su invio riuscito** per i form
  con evento di successo affidabile (Fluent Forms `fluentform_submission_success`,
  Contact Form 7 `wpcf7mailsent`): niente più Lead sui tentativi falliti. Email/telefono
  per l'Advanced Matching vengono catturati al submit e usati alla conferma. Per gli altri
  form il comportamento resta invariato (invio al submit).

### Aggiunto
- Impostazione **"Evento successo form custom"** (`ati_ga4_custom_success_event`): nome di
  un evento JS che i form personalizzati emettono all'invio riuscito. Agganciato sia da
  GA4 (bridge) sia da Meta/n8n. Contratto:
  `document.dispatchEvent(new CustomEvent(nome, { detail: { form_id, email, phone } }))`.
  `email`/`phone` (opzionali) vanno solo a Meta (Advanced Matching), mai a GA4.

## [0.8.7] - 2026-07-27 — Lead solo su invio riuscito + cache-bust bridge

### Corretto
- Modalità "invio form client-side" ora conta il lead **solo su invio realmente
  riuscito**, non sul submit grezzo (che scattava anche sui tentativi falliti):
  - Fluent Forms → evento ufficiale `fluentform_submission_success`;
  - Contact Form 7 → evento nativo `wpcf7mailsent`;
  - form nativi non-AJAX → `submit` non prevenuto (invio reale);
  - le AJAX form note vengono saltate dal submit grezzo (niente falsi positivi).
- **Cache-bust del bridge JS**: la versione dello script ora è `ATI_PLUGIN_VERSION`
  (prima fissa `1.0.0`), così il file si aggiorna a ogni release senza restare in cache.

## [0.8.6] - 2026-07-27 — Trigger lead client-side (compatibile con tutti i form)

### Aggiunto
- Impostazione **"Trigger del lead"** (`ati_ga4_lead_trigger`):
  - `server` (default): hook PHP ufficiali dei provider;
  - `submit`: il bridge invia `generate_lead` all'invio di un **qualsiasi** form
    (utile con form/versioni non supportate, es. Fluent Forms datato). Legge
    client_id/session_id reali dal Google Tag, rispetta consenso e deduplica; con
    debounce anti doppio-invio (3s). Possibili falsi positivi su invii non riusciti.
- In modalità `submit`, i provider server-side **non** emettono il lead (nessun doppione).

### Motivazione
Su versioni datate di Fluent Forms l'hook `fluentform/submission_inserted` può non
scattare: la modalità client-side garantisce copertura ampia e indipendente dal plugin form.

## [0.8.5] - 2026-07-27 — Diagnostica Fluent Forms

### Aggiunto
- Adapter Fluent: registrazione anche dell'hook variante underscore
  `fluentform_submission_inserted` (compatibilità), protetto da dedup (`event_id`
  stabile `ff_<entry_id>`).
- Diagnostica log-only su `fluentform/before_insert_submission` (`fluent_before_insert`)
  per capire se Fluent avvia l'elaborazione della submission quando `submission_inserted`
  non scatta.

## [0.8.4] - 2026-07-27 — DebugView opzionale

### Aggiunto
- Opzione **`ati_ga4_debug_mode`** (OFF di default): aggiunge `debug_mode` agli invii
  `/mp/collect`, così gli eventi server-side diventano visibili in **GA4 DebugView**.
  Checkbox nel pannello con avviso "solo per test". Nota: NON influenza l'anteprima di
  Google Tag Manager (che mostra solo gli eventi client-side).

## [0.8.3] - 2026-07-27 — Indicatore versione / diagnostica deploy

### Aggiunto
- Box **"Stato / versione attiva"** nel pannello GA4: versione plugin runtime vs header,
  schema installato vs atteso, presenza colonna `reason_code`, firma (md5+mtime) del
  bridge JS su disco, stato pipeline/configurazione, provider attivi. Serve a smascherare
  codice servito da cache/OPcache.
- Campo `v` (versione plugin) nella config inline del bridge (`window.atiGa4Bridge`),
  visibile in "Visualizza sorgente" per confermare che il PHP in esecuzione è aggiornato.
- `providers_registered` nel log (provider form attivi/non disponibili).
- Adapter Fluent: `event_id` stabile `ff_<entry_id>` (dedup a prova di doppio scatto hook).

## [0.8.2] - 2026-07-27 — Bugfix consenso iubenda / GA4

### Corretto (bloccante)
- **Consenso analytics iubenda non rilevato lato server** → GA4 non inviava nulla.
  Causa: WordPress applica magic-quotes a `$_COOKIE`, quindi il JSON iubenda arriva con
  le virgolette escapate e `json_decode()` falliva. Aggiunto `wp_unslash()`
  (`clean_cookie()`) prima del parsing in `ATI_Consent_Service::detect_analytics_from_cmps()`.
  Ora vengono riconosciuti anche i nomi cookie con prefisso (es. `_iub_cs-s4597678`).
- **session_id non estratto dal cookie `_ga_*` in formato GS2**
  (`GS2.1.s<sessionId>$...`). Regex aggiornata per GS1 e GS2.

### Aggiunto
- Debug PII-free potenziato: `confirmed_event_received` (event, provider, source,
  has_cid, has_sid, consent), `confirmed_event_queued`/`duplicate`/`blocked`,
  `provider_hook_fired` (Elementor/Fluent), `rest_event_received`.
- Test: cookie iubenda slashato, nome con prefisso, purpose assente, session_id GS2 (58 asserzioni).

### Nota (non modificato)
- `ati_has_marketing_consent()` (percorso Meta/n8n, `tag-inserter.php`) ha lo stesso
  problema di slash sul JSON iubenda lato server: NON toccato per non alterare il
  comportamento Meta senza autorizzazione. Lato client il consenso marketing è corretto.

## [0.8.1] - 2026-07-24 — Hardening

### Corretto (bloccante)
- **Stati coda coerenti**: un evento non inviato non viene **mai** marcato `sent`.
  Estratta la logica pura `ATI_Event_Queue::classify_delivery()`; aggiunta colonna
  `reason_code` (schema v2) e lo stato **`discarded`**.
- **client_id assente**: nessun UUID casuale; l'evento è `discarded` con
  `reason_code=missing_client_id` (non recuperabile nel worker), conteggiato nella
  diagnostica coda (`discarded_reasons()`). Guardia difensiva anche in
  `ATI_GA4_Adapter::send()` (nessuna richiesta HTTP senza client_id).
- **Configurazione assente** → `failed` ritentabile (mai `sent`).

### Aggiunto
- Bridge: shim `gtag` per **GTM**, attesa con timeout (`waitForGtag`), diagnostica
  PII-free, priorità esplicita gtag→(fallback cookie solo server-side)→nessuna identità.
- Diagnostica consenso nel pannello (provider rilevati, stato analytics, modalità,
  purpose iubenda) senza mostrare i cookie.
- `ATI_Event_Queue::reclaim_stuck()` e `schema_sql()` (testabili).
- Test: `tests/db-tests.php` (13 test su **MariaDB reale**), suite unitaria estesa a 53
  asserzioni (macchina a stati, guardie adapter, consenso negato, parsing cookie).
- Documentazione: comportamento iubenda dettagliato e **limite di `sent`** (collect non
  valida semanticamente; validation endpoint obbligatorio).

### Note
- Il formato dei cookie `_ga`/`_ga_*` è trattato come **non stabile**: parsing difensivo
  con degrado esplicito.

## [0.8.0] - 2026-07-24

### Aggiunto — GA4 "server-side first"
- Modello evento interno **neutrale** (`ATI_Event`) senza PII, disaccoppiato da GA4/Meta/n8n.
- **Consenso analytics separato** dal marketing (`ATI_Consent_Service`,
  `ati_has_analytics_consent()`), con rilevamento della categoria *statistiche* per
  Complianz, iubenda, Cookiebot, OneTrust e cookie custom. Il consenso marketing non è
  mai usato come consenso analytics.
- **GA4 Measurement Protocol adapter** (`ATI_GA4_Adapter`) con payload completo:
  `client_id`, `timestamp_micros`, `session_id`, `engagement_time_msec`, `page_location`,
  `page_title`, `page_referrer`, parametri commerciali, `event_id`.
- **Endpoint regionali** EU (`region1`, default) / Global, con endpoint di
  validazione/debug, centralizzati in `ATI_GA4_Endpoints`.
- **API pubblica** `ati_track_confirmed_event()` e hook `ati_confirmed_lead` per i lead
  confermati.
- **Adapter form** basati su hook ufficiali: Elementor Pro
  (`elementor_pro/forms/new_record`), Fluent Forms (`fluentform/submission_inserted`).
  Breakdance predisposto ma non ancora supportato.
- **Bridge JavaScript** (`ga4-bridge.js`) che recupera `client_id`/`session_id` reali dal
  Google Tag; nessun secret, nessuna PII, fallback controllato.
- **Coda asincrona** (`wp_ati_event_queue`) con worker WP-Cron/Action Scheduler, claim
  atomico anti-concorrenza, retry con backoff, recupero elementi stuck, cleanup,
  diagnostica, retry/eliminazione manuale.
- **Deduplica interna** (`event_id + event_name + destination`, TTL 24h) con vincolo
  `UNIQUE` come backstop atomico.
- **Endpoint REST irrobustito** (`ati/v1/ga4-lead`): allowlist eventi/parametri, schema,
  limiti body/valori, validazione hostname, rate limiting (IP hashato), token first-party
  HMAC, deduplica.
- **Pannello "GA4 Server-Side"**: classificazione del progetto, mapping form, API secret
  mascherato, regione, "Testa configurazione GA4", diagnostica coda.
- Migrazione schema **idempotente e versionata**; documentazione (`docs/`).

### Modificato
- Il **submit DOM generico non genera più `generate_lead`** in GA4: la conversione parte
  solo dopo la conferma reale del provider.
- **PageView server-side GA4 disattivato di default** (modalità avanzata con avviso).
- `fst_send_to_ga4` usa il **client_id/session_id reali** dai cookie `_ga`/`_ga_*` e
  include timestamp/engagement/session; **rimosso l'UUID casuale per richiesta**.
- **Logging privo di PII**: rimossi `error_log` con `$_POST`/`$_COOKIE`/user_data/IP/UA/
  payload n8n completi.
- **API Secret mai reso in HTML**: gestione mascherata; supporto costante
  `ATI_GA4_API_SECRET`.
- `uninstall.php` rimuove tutte le opzioni, le tabelle personalizzate e gli eventi cron.
- Versione plugin allineata a **0.8.0** (`ATI_PLUGIN_VERSION`).

### Invariato
- Meta Pixel, Meta Conversions API, Advanced Matching e n8n mantengono il comportamento
  precedente (nessuna modifica funzionale).
