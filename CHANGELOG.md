# Changelog — Quick Tracking Integration

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/).

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
