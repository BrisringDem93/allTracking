# GA4 Server-Side — Matrice di test

## Legenda esiti (rigorosa)

- **PASS** — test **realmente eseguito** e superato in questa sessione.
- **FAIL** — test eseguito e fallito.
- **NOT RUN** — non eseguito.
- **IMPLEMENTED / NOT RUNTIME TESTED** — implementato e verificato per lettura del
  codice / test unitari, ma **non** verificato in un WordPress reale end-to-end.

## Ambiente di esecuzione

- PHP 8.2.12 CLI, Node 22 — disponibili.
- MariaDB 10.4.32 (XAMPP) — avviato temporaneamente per i test DB su un **database
  usa-e-getta** (`ati_ga4_tmp_test`), poi eliminato; MariaDB riportato a "fermo".
  Nessun sito esistente è stato toccato.
- WordPress reale con plugin attivo: **non usato**. Esiste una install locale
  (`progetti/blog_fra`) ma è un **sito distinto** con solo akismet/hello, **senza**
  Elementor/Fluent Forms e **senza credenziali GA4 di test**: non è stata modificata.
- WP-CLI: **non installato**.

## Suite eseguite

- `tests/run-tests.php` — **53 PASS / 0 FAIL** (funzioni pure + macchina a stati).
- `tests/db-tests.php` — **13 PASS / 0 FAIL** (coda su MariaDB reale).
- `php -l` su tutti i PHP, `node --check` sui JS: OK.

## Matrice principale (20 scenari della traccia)

| # | Scenario | Atteso | Esito | Evidenza |
|---|----------|--------|-------|----------|
| 1 | Google Tag + GA4 server attivi | page_view una sola volta dal browser | **IMPLEMENTED / NOT RUNTIME TESTED** | server page_view OFF di default; gate in `server-tracking.php`. Serve WP reale. |
| 2 | Consenso analytics negato | nessun evento GA4 MP | **PASS** | `run-tests`: track → `no_consent`; detect_analytics=false. |
| 3 | Marketing negato, analytics concesso | GA4 ok; Meta indipendente | **PASS** | `run-tests`: consenso analytics distinto (Cookiebot/Complianz/OneTrust). |
| 4 | Submit DOM senza conferma | nessun generate_lead | **IMPLEMENTED / NOT RUNTIME TESTED** | esclusione incondizionata in `server-tracking.php`; logica verificata, serve WP reale. |
| 5 | Form confermato dal provider | un solo generate_lead accodato | **IMPLEMENTED / NOT RUNTIME TESTED** | hook Elementor/Fluent; dedup provata su DB, ma hook provider non eseguiti (plugin assenti). |
| 6 | Stesso event_id due volte | un solo invio GA4 | **PASS** | `db-tests`: INSERT IGNORE → 1 riga; `is_duplicate`. |
| 7 | Evento fuori allowlist | richiesta rifiutata | **IMPLEMENTED / NOT RUNTIME TESTED** | REST `event_not_allowed` (422); logica verificata, REST non colpito via HTTP reale. |
| 8 | Parametro fuori allowlist | parametro rimosso | **PASS** | `run-tests`: normalizer scarta chiavi non-allowlist. |
| 9 | Parametri commerciali configurati | presenti nel payload | **PASS** | `run-tests`: classificazione + payload. |
| 10 | client_id/session_id disponibili | evento in sessione corretta | **PASS** | `run-tests`: payload con client_id + session_id. |
| 11 | client_id assente | degrado esplicito, nessun UUID casuale | **PASS** | `run-tests` + `db-tests`: nessun client_id iniettato; `deliver` → discarded/missing_client_id. |
| 12 | Payload non valido | validation messages in modalità test | **IMPLEMENTED / NOT RUNTIME TESTED** | `validate()` + endpoint `/debug/mp/collect`; nessuna credenziale GA4 di test → non chiamato. |
| 13 | Errore di rete | evento in coda e ritentato | **PASS (state machine)** | `run-tests`: `classify_delivery` errore→failed+retry; `db-tests`: missing_configuration→pending. Rete reale non colpita. |
| 14 | API secret | mai in HTML/JS/log/Git/REST | **PASS** | `run-tests`: sanitizer preserva/rimuove; secret scan pulito. |
| 15 | Form con email/telefono | non entrano nel payload/coda GA4 | **PASS** | `run-tests`: denylist PII; serializzazione priva di PII. |
| 16 | Utente loggato con esclusione | nessun tracking | **IMPLEMENTED / NOT RUNTIME TESTED** | gate `ati_disable_logged_in` in REST/bridge/handler; serve WP reale. |
| 17 | Aggiornamento da versione precedente | impostazioni preservate, migrazione una volta | **IMPLEMENTED / NOT RUNTIME TESTED** | `maybe_migrate` idempotente, `add_option` non sovrascrive; serve WP reale. |
| 18 | Disinstallazione | nessun dato indesiderato | **IMPLEMENTED / NOT RUNTIME TESTED** | `uninstall.php` rimuove opzioni+tabelle+cron; serve WP reale. |
| 19 | Due processi elaborano la coda | nessun doppio invio | **PASS** | `db-tests`: claim atomico, solo 1 worker (rows_affected==1). |
| 20 | Elemento stuck dopo crash | recuperato dopo timeout | **PASS** | `db-tests`: `reclaim_stuck` processing→pending. |

## Matrice hardening richiesta (obiettivo 5)

| Scenario | Esito | Evidenza |
|----------|-------|----------|
| client_id mancante → non sent | **PASS** | `run-tests` classify_delivery + `db-tests` deliver → discarded. |
| evento discarded con reason_code | **PASS** | `db-tests`: reason_code=missing_client_id; `discarded_reasons()`. |
| errore HTTP → failed e retry | **PASS** | `run-tests`: classify_delivery(http_500)→failed+retry; MAX→failed terminale. |
| evento valido → sent | **PASS (state machine)** | `run-tests`: classify_delivery(ok)→sent. Invio HTTP reale non eseguito. |
| stesso evento → una sola consegna | **PASS** | `db-tests`: dedup UNIQUE/INSERT IGNORE. |
| record processing scaduto → recuperato | **PASS** | `db-tests`: reclaim_stuck. |
| secret assente → nessun invio e stato corretto | **PASS** | `run-tests` + `db-tests`: send→missing_configuration; mai sent. |
| consenso analytics negato → discarded/blocked | **PASS** | `run-tests`: track→no_consent (nessun invio/coda). |
| bridge timeout → nessuna identità inventata | **IMPLEMENTED / NOT RUNTIME TESTED** | logica bridge (waitForGtag + fallback '') verificata; non eseguita in browser. Lato server: client_id vuoto → discarded (PASS). |
| parsing cookie invalido → fallback controllato | **PASS** | `run-tests`: `_ga`/`_ga_*` malformati → stringa vuota. |

## Semantica di `sent` (limite documentato)

`sent` = richiesta HTTP realmente eseguita verso `/mp/collect` con risposta 2xx. L'endpoint
*collect* NON valida semanticamente il payload (risponde 204 anche con parametri errati):
la validazione semantica usa `/debug/mp/collect` (pulsante **Testa configurazione GA4**),
obbligatorio nella configurazione iniziale. Un evento senza `client_id` reale non è mai
`sent`: diventa `discarded` (reason_code=`missing_client_id`).

## Test NON eseguiti end-to-end e come eseguirli

I seguenti richiedono un WordPress reale con plugin attivo e credenziali GA4 di test
(assenti in questa sessione). **Non** sono segnati PASS. Checklist ripetibile:

1. **Attivazione + migrazione + tabella** (Test 17):
   - Copiare/symlink il plugin in `wp-content/plugins/quick-tracking-integration/`.
   - `wp plugin activate quick-tracking-integration` (WP-CLI) oppure attivare da admin.
   - Verifica tabella: `wp db query "SHOW TABLES LIKE '%ati_event_queue%'"`.
   - Verifica versione schema: `wp option get ati_ga4_schema_version` → `2`.
   - Riattivare: la migrazione non deve ripetersi (idempotente).
2. **Secret mascherato** (Test 14): aprire *Impostazioni → GA4 Server-Side*; il campo
   API Secret non deve mostrare il valore; salvare a vuoto non deve cancellarlo.
   Preferire `define('ATI_GA4_API_SECRET', ...)` in `wp-config.php`.
3. **Consenso analytics** (Test 2/3): impostare cookie `CookieConsent=...statistics:true`
   e verificare invio; con `statistics:false` verificare `no_consent`.
4. **REST** (Test 7/8/16): `POST` a `/wp-json/ati/v1/ga4-lead` con token valido; evento
   fuori allowlist → 422; con utente loggato escluso → `disabled`.
5. **Coda via WP-Cron** (Test 13): `wp cron event run ati_ga4_cron_tick`; osservare le
   transizioni di stato in *GA4 Server-Side → Coda eventi*.
6. **Validation endpoint** (Test 12): impostare un API secret di **test** via costante
   (mai in chat/terminale/Git), poi *Testa configurazione GA4* e leggere i
   `validationMessages`.
7. **Provider** (Test 5): installare Elementor Pro / Fluent Forms, inviare un form reale,
   verificare **un solo** `generate_lead` accodato con i parametri di classificazione.
8. **Disinstallazione** (Test 18): `wp plugin uninstall quick-tracking-integration`;
   verificare assenza di opzioni `ati_*` e della tabella coda.

Payload REST sintetico senza PII per il Test 7/8:
```json
{ "event": "generate_lead", "token": "<token-first-party>",
  "client_id": "1234567890.1234567890", "session_id": "1700000000",
  "page_location": "https://<sito>/grazie",
  "params": { "business_area": "acquisizione_immobili", "service_type": "nuda_proprieta" } }
```
Expected: `{"status":"queued"}`; con `"event":"non_ammesso"` → HTTP 422 `event_not_allowed`.
