# GA4 Server-Side — Audit e piano di potenziamento

> Plugin: **Quick Tracking Integration** (alias "All Tracking")
> Documento generato durante il lavoro sul branch `feat/ga4-server-side-first`.
> Ambito: **esclusivamente l'architettura GA4**. Meta Pixel, Meta Conversions API,
> Advanced Matching e n8n non vengono modificati funzionalmente.

---

## 1. File individuati (audit dello scope reale)

| File | Ruolo attuale |
|------|---------------|
| `plugin.php` | Bootstrap. Header `Version: 0.7.9`. Include gli altri file, registra `fst_create_cookie_table` all'attivazione. |
| `includes/tag-inserter.php` (1480 righe) | Cuore del plugin. Consenso **marketing** (PHP + JS), output Google Tag / GTM / Consent Mode, Facebook Pixel dinamico, e **tutto il JavaScript di tracking** (PageView, ButtonClick, FormStart, `submit`→Lead, scroll/deep events). |
| `includes/server-tracking.php` (620 righe) | Endpoint AJAX `fst_pageview` + REST `fst/v1/event`. Costruisce user_data Meta, invia a n8n e a GA4 (`fst_send_to_ga4`). Mappa `Lead`/`FormSubmit`→`generate_lead`. |
| `includes/settings-page.php` (206 righe) | Pagina impostazioni (`ati_settings`). |
| `includes/facebook-pixel.php` | Output Pixel Meta (fuori scope). |
| `includes/db_cookies.php` | Tabella `wp_fst_user_cookies` (persistenza cookie Meta, fuori scope). |
| `assets/js/facebook-pixel.js` | Init Pixel Meta (fuori scope). |
| `uninstall.php` | Cancella un sottoinsieme di opzioni. |
| `README.txt` | Documentazione consenso marketing. |

Non esistono: cartella `docs/` (creata ora), test automatici, meccanismo di versione schema/migrazione, coda, deduplica server-side.

---

## 2. Flusso attuale ricostruito

1. **Google Tag / GTM** — caricati lato browser da `ati_output_tags()` / `ati_output_gtm_head()`. Con GTM attivo il plugin non carica GA4/Pixel diretti. Consent Mode default (denied) emesso solo nel ramo GTM.
2. **PageView client-side** — il Google Tag (`gtag('config', …)`) invia `page_view` automaticamente.
3. **PageView server-side** — il JS invia **sempre** (anche senza consenso) un POST AJAX `fst_pageview`; il server, se `ati_enable_ga4_server=1`, invia **anche** `page_view` via Measurement Protocol → **duplicazione**.
4. **FormStart** — `focusin` su `<form>` → REST `type=FormStart` → GA4 `form_start`.
5. **Lead** — listener globale `document.addEventListener('submit', …)` invia `type: 'Lead'` **su ogni submit DOM**, senza conferma reale.
6. **FormSubmit / Lead → generate_lead** — mappati in `fst_send_to_ga4` a `generate_lead`.
7. **event_id** — generato lato client (`evt_<ts>_<rand>`), in cookie `fst_ev_id`. Usato per la dedup **Meta**, non per GA4.
8. **client_id GA4** — `fst_send_to_ga4()` usa `fst_get_uid()` (pseudonimo `fst_uid`); fallback cookie `_ga`; **fallback finale: UUID casuale per richiesta**.
9. **fst_uid** — cookie pseudonimo (2 anni), scritto solo con consenso marketing.
10. **Consenso analytics** — **non esiste**. Solo `ati_has_marketing_consent()`.
11. **Consenso marketing** — PHP `ati_has_marketing_consent()` + JS `hasMarketingConsent()`; CMP: Complianz, iubenda, Cookiebot, OneTrust, cookie custom.
12. **GA4 Measurement Protocol** — `fst_send_to_ga4()`: payload minimo `{client_id, events:[{name, params:{label}}]}`. Nessun `timestamp_micros`, `session_id`, `engagement_time_msec`. Fire-and-forget.
13. **n8n** — `fst_send_to_n8n()` (fuori scope, invariato).
14. **Meta Pixel** — fuori scope, invariato.
15. **Deduplicazione** — solo Meta (via event_id condiviso). Nessuna dedup GA4 interna.
16. **Gestione errori** — minima; `error_log` verbosi con payload completi.

---

## 3. Problemi trovati (checklist della traccia)

| # | Verifica | Esito |
|---|----------|-------|
| 1 | `Lead` → `generate_lead` | **VERO** (`server-tracking.php:293-307`). |
| 2 | `FormSubmit` → `generate_lead` | **VERO** (stessa mappa). |
| 3 | GA4 riceve solo `label` | **VERO** (`fst_send_to_ga4($name, ['label'=>$label])`). |
| 4 | `submit` generico conta invii non confermati | **VERO** (`tag-inserter.php:979-992`). |
| 5 | `page_view` inviabile sia da Tag sia da server | **VERO** (`server-tracking.php:168-170`). |
| 6 | `fst_uid` usato come client_id GA4 | **VERO** (`server-tracking.php:586`). |
| 7 | UUID casuale se manca client_id | **VERO** (`server-tracking.php:601-603`). |
| 8 | manca `session_id` | **VERO**. |
| 9 | manca `engagement_time_msec` | **VERO**. |
| 10 | manca `timestamp_micros` | **VERO**. |
| 11 | analytics/marketing accorpati | **VERO** (esiste solo marketing). |
| 12 | REST pubblico con payload liberi | **VERO** (`permission_callback => __return_true`, nessuna allowlist). |
| 13 | payload completi nei log | **VERO** (`print_r($_POST)`, cookie, IP, UA, event completo). |
| 14 | nessuna coda affidabile | **VERO**. |
| 15 | nessuna dedup GA4 interna | **VERO**. |
| 16 | API secret esponibile | **PARZIALE** — reso in HTML nel campo password admin (`settings-page.php:69`); mai in JS/REST. Da mascherare. |
| 17 | uninstall incompleto | **VERO** — mancano `ati_ga4_server_id`, `ati_enable_ga4_server`, `ati_consent_cookie_name`, `ati_consent_custom_event`, tabella e nuove opzioni. |
| 18 | versione incoerente | **VERO** — header `0.7.9` vs docblock `@version 1.1`. |

### Rischi
- **PII nei log** (email/telefono hashati, IP, UA, cookie completi) con `WP_DEBUG`.
- **Conversioni gonfiate**: ogni `submit` (anche validazione fallita) genera `generate_lead`.
- **Sessioni GA4 sbagliate**: client_id non è quello reale di GA4 → attribuzione degradata, doppio conteggio utenti.
- **PageView duplicati** in GA4.
- **Perdita eventi**: invio fire-and-forget senza retry.
- **Endpoint REST abusabile**: chiunque può postare eventi arbitrari.

---

## 4. Architettura proposta ("server-side first")

Il Google Tag / GTM **resta lato browser** per: `page_view`, `session_start`, `first_visit`,
`user_engagement`, attribuzione, sessioni, generazione `client_id`, `session_id`, Consent Mode,
enhanced measurement. Il backend invia via Measurement Protocol **solo eventi di business confermati**
(priorità: `generate_lead`).

Componenti (namespace concettuale `ATI_`, sotto `includes/ga4/`):

| Componente | Classe/funzione | Responsabilità |
|------------|-----------------|----------------|
| Event model | `ATI_Event` | Modello neutrale (no PII), separa contesto/identità/consenso/params/destinazione/stato. |
| Event Collector / API | `ati_track_confirmed_event()`, hook `ati_confirmed_lead` | Punto d'ingresso stabile per lead confermati. |
| Event Normalizer | `ATI_Event_Normalizer` | Costruisce l'evento neutrale, applica classificazione, sanifica, calcola `event_timestamp_micros`. |
| Consent Service | `ATI_Consent_Service` + `ati_has_analytics_consent()` / `ati_has_marketing_consent()` | Separa analytics/marketing, adapter CMP estendibili. |
| Project Classification | `ATI_Project_Classification` | Risolve business_area/service_type/audience_type/site_section con priorità mapping→filtri→globale→fallback. |
| GA4 Destination Adapter | `ATI_GA4_Adapter` + `ATI_GA4_Endpoints` | Costruisce payload GA4 completo, endpoint EU/Global, collect vs debug/validation. |
| Deduplication | `ATI_Event_Deduplicator` | Chiave `event_id+event_name+destination`, TTL 24h (configurabile). |
| Event Queue | `ATI_Event_Queue` | Tabella `wp_ati_event_queue`, worker WP-Cron, lock, retry/backoff, recupero stuck, cleanup. |
| Form Provider Adapters | `ATI_Form_Provider_Interface` + registry | HTML, Elementor, Fluent Forms, Breakdance (solo con hook verificabili). |
| Client bridge | `assets/js/ga4-bridge.js` | Recupera client_id/session_id/page context da gtag, invia evento confermato al REST first-party. |
| REST hardening | `ATI_GA4_REST` | Allowlist eventi/param, size/type limits, rate limit, token first-party, dedup. |
| Admin | `ATI_GA4_Admin` | Classificazione, mapping form, secret mascherato, regione, "Testa configurazione GA4". |
| Migration | `ATI_GA4_Migration` | Schema versionato idempotente, feature flag off di default. |

### Flusso target `generate_lead`
```
provider (submit riuscito) ──do_action('ati_confirmed_lead', …)──▶ ati_track_confirmed_event()
   ▶ ATI_Event_Normalizer (classificazione + sanitizzazione, NO PII)
   ▶ ATI_Consent_Service::has_analytics_consent()  (se no ⇒ scarta/degrada)
   ▶ ATI_Event_Deduplicator (già visto? ⇒ stop)
   ▶ ATI_Event_Queue::enqueue()  (ritorna subito, non blocca il lead)
   ▶ [cron] ATI_GA4_Adapter::send()  →  GA4 MP (EU region1 / global)
```

Il client bridge fornisce `client_id`/`session_id` reali (da `gtag('get', …)`); in loro assenza
l'attribuzione è marcata "degradata" e il comportamento (accoda/scarta) è configurabile —
**nessun UUID casuale silenzioso per evento**.

---

## 5. Piano di migrazione

1. Nuove opzioni con default sicuri; nessuna opzione esistente cancellata o cambiata.
2. `ati_ga4_schema_version` versiona lo schema; migrazione idempotente su `plugins_loaded`/attivazione.
3. Le funzionalità **server-confirmed sono OFF di default** dopo l'aggiornamento (`ati_ga4_confirmed_enabled=0`).
4. Il vecchio `page_view` server-side viene **disattivato di default** (`ati_ga4_server_pageview=0`) ma resta come "modalità avanzata" con avviso.
5. Il vecchio ramo `Lead`/`FormSubmit`→`generate_lead` in `fst_send_to_ga4` viene disattivato quando la nuova pipeline confirmed è attiva, così da non emettere `generate_lead` sul submit generico. Se la nuova pipeline è OFF, il comportamento legacy resta invariato (retro-compatibilità).
6. `uninstall.php` esteso per rimuovere tutte le opzioni + tabella coda (regole documentate).

## 6. File previsti

**Nuovi:** `includes/ga4/` (event model, consent service, normalizer, classification, GA4 adapter+endpoints, deduplicator, queue, form providers, REST, admin, migration, bootstrap, public functions), `assets/js/ga4-bridge.js`, `tests/` (unit PHP), `docs/ga4-configuration.md`, `CHANGELOG.md`.

**Modificati:** `plugin.php` (versione + require bootstrap), `includes/server-tracking.php` (gating legacy GA4), `includes/tag-inserter.php` (submit non genera più Lead-confermato; hook bridge), `includes/settings-page.php` (link/sezioni GA4), `uninstall.php`, `README.txt`.

**Meta/n8n:** invariati.
