# Changelog — Quick Tracking Integration

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/).

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
