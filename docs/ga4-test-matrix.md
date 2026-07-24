# GA4 Server-Side — Matrice di test

Legenda esito:
- **PASS (auto)** — verificato dal test automatico `tests/run-tests.php` (funzioni pure).
- **PASS (code)** — garantito dalla logica implementata e verificato per lettura del codice.
- **NOT RUN** — richiede un ambiente WordPress reale (DB/HTTP/cron) non disponibile in
  questa sessione; indicato cosa manca. Non dichiarato superato.

Ambiente disponibile: PHP 8.2.12 CLI, Node 22. **Non** è disponibile un'installazione
WordPress con DB attivo per l'esecuzione end-to-end.

| # | Scenario | Risultato atteso | Esito | Note |
|---|----------|------------------|-------|------|
| 1 | Google Tag attivo + GA4 server attivo | page_view una sola volta dal browser | **PASS (code)** | Server page_view OFF di default (`ati_ga4_server_pageview`); gate in `server-tracking.php`. |
| 2 | Consenso analytics negato | nessun evento GA4 MP | **PASS (auto)** + code | `detect_analytics_from_cmps` ⇒ false; `ati_track_confirmed_event` esce con `no_consent`. |
| 3 | Marketing negato, analytics concesso | GA4 ok; Meta indipendente | **PASS (auto)** | Consenso analytics distinto; Meta/n8n invariati. |
| 4 | Submit DOM senza conferma | nessun generate_lead | **PASS (code)** | Esclusione incondizionata `Lead`/`FormSubmit` dal legacy; pipeline solo su conferma provider. |
| 5 | Form confermato dal provider | un solo generate_lead accodato | **PASS (code)** | `ati_confirmed_lead` → `ati_track_confirmed_event` → `enqueue`. |
| 6 | Stesso event_id due volte | un solo invio GA4 | **PASS (auto)** + code | dedup_key deterministica + `UNIQUE` + `INSERT IGNORE` + `is_duplicate`. |
| 7 | Evento fuori allowlist | richiesta rifiutata | **PASS (code)** | REST `event_not_allowed` (422). |
| 8 | Parametro fuori allowlist | parametro rimosso | **PASS (auto)** | Normalizer scarta chiavi non-allowlist. |
| 9 | Parametri commerciali configurati | presenti nel payload | **PASS (auto)** | Classificazione + payload verificati. |
| 10 | client_id/session_id disponibili | evento associato alla sessione | **PASS (auto)** | Payload include client_id + session_id. |
| 11 | client_id assente | degrado esplicito, nessun UUID casuale | **PASS (auto)** | `degraded_attribution`; nessun client_id iniettato; policy configurabile. |
| 12 | Payload non valido | validation messages nella modalità test | **PASS (code)** | `ATI_GA4_Adapter::validate` + endpoint `/debug/mp/collect` + UI test. |
| 13 | Errore di rete | evento in coda e ritentato | **PASS (code)** | `deliver` su `is_wp_error`/HTTP non-2xx ⇒ retry con backoff. |
| 14 | API secret | mai in HTML/JS/log/Git/REST | **PASS (auto)** + code | Sanitizer preserva/rimuove; nessun echo; secret scan pulito. |
| 15 | Form con email/telefono | non entrano nel payload/coda GA4 | **PASS (auto)** | Denylist PII nel normalizer; serializzazione priva di PII. |
| 16 | Utente loggato con esclusione | nessun tracking | **PASS (code)** | Gate `ati_disable_logged_in` in REST, bridge enqueue, handler legacy. |
| 17 | Aggiornamento da versione precedente | impostazioni preservate, migrazione una volta | **PASS (code)** | `maybe_migrate` idempotente; `add_option` non sovrascrive. |
| 18 | Disinstallazione | nessun dato indesiderato | **PASS (code)** | `uninstall.php` rimuove opzioni + tabelle + cron. |
| 19 | Due processi elaborano la coda | nessun doppio invio | **PASS (code)** | Claim atomico `UPDATE ... WHERE status='pending'` (rows_affected==1). |
| 20 | Elemento stuck in processing dopo crash | recuperato dopo timeout | **PASS (code)** | Reclaim `processing` con `locked_at < now-STUCK_SECONDS`. |

## Test NON eseguiti end-to-end (motivo)

I seguenti aspetti sono implementati e verificati per lettura del codice e/o test unitari,
ma **non** sono stati eseguiti end-to-end perché manca un WordPress reale con DB/cron/HTTP
in questa sessione:

- Creazione/uso reale della tabella `wp_ati_event_queue` (dbDelta) — *manca DB WordPress*.
- Claim atomico in concorrenza reale (Test 19) e recupero stuck (Test 20) — *manca DB*.
- Chiamata reale all'endpoint di validazione GA4 (Test 12) — *manca rete + credenziali reali; il secret non va incollato in ambienti tracciati*.
- Ciclo cron WP-Cron / Action Scheduler (Test 13) — *manca runtime WP*.
- Hook reali Elementor/Fluent Forms (Test 5) — *manca WP + plugin installati*.
- Endpoint REST con richiesta HTTP reale (Test 7, 8, 16) — *manca runtime WP REST*.

Per eseguirli servono: un sito WordPress con il plugin attivo, un GA4 stream con API secret
valido (impostato via costante), i plugin form installati, e WP-Cron abilitato.

## Controlli tecnici eseguiti

- `php -l` su **tutti** i file PHP (21 file): nessun errore di sintassi.
- `node --check` su `assets/js/ga4-bridge.js` e `assets/js/facebook-pixel.js`: OK.
- `tests/run-tests.php`: **39 PASS / 0 FAIL**.
- Secret scan (grep): nessun secret reale hardcoded.
- Scan log: nessun `error_log` con payload/cookie/PII completi residuo.
