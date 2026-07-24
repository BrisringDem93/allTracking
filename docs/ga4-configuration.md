# GA4 Server-Side — Guida alla configurazione

Questa guida descrive come configurare e abilitare la pipeline GA4 "server-side first"
per le conversioni confermate (`generate_lead`). Il Google Tag / GTM resta lato browser
per page_view, sessioni, client_id, session_id, Consent Mode e misurazione avanzata.

## 1. Configurazione nel pannello WordPress

Pagina: **Impostazioni → GA4 Server-Side** (`options-general.php?page=ati-ga4-settings`).

1. **Measurement ID (server)** — normalmente lo stesso `G-XXXXXXXXXX` del Google Tag web.
   Se lasciato vuoto viene usato il Measurement ID client.
2. **API Secret** — creato in GA4: *Amministrazione → Flussi di dati → (stream) →
   Measurement Protocol API secrets*. Preferibilmente definito come costante in
   `wp-config.php`:
   ```php
   define( 'ATI_GA4_API_SECRET', 'il_tuo_secret' );
   ```
   In alternativa incollalo nel campo (mascherato, mai mostrato né inviato al browser).
   Lasciando il campo vuoto il valore salvato **non** viene cancellato.
3. **Regione endpoint** — `EU` (region1, default) o `Global`.
4. **Consenso analytics** — `Auto` rileva la categoria *statistiche* del CMP (distinta
   dal marketing). Non viene mai dedotto dal consenso marketing.
5. **Classificazione del progetto** — valori globali (slug): `business_area`,
   `service_type`, `audience_type`, `site_section`.
6. **Mapping form** — per ogni form: provider, form_id, form_name e override di
   classificazione. Priorità: mapping form → filtri WordPress → globale → fallback
   (`not_set`).
7. **Testa configurazione GA4** — invia un evento fittizio all'endpoint di
   validazione/debug (non crea conversioni, non invia PII, non mostra il secret) e
   mostra warning/errori.
8. **Abilita "Eventi confermati"** — SOLO dopo aver completato i passi 1–7.

### Ordine di abilitazione richiesto
La pipeline server-confirmed va abilitata solo dopo aver: configurato il Measurement ID,
configurato l'API Secret, verificato il consenso analytics, configurato almeno un form,
ed eseguito con successo il test di validazione.

## 2. Configurazione richiesta in GA4

- Un flusso di dati Web con Google Tag installato sul sito.
- Un **Measurement Protocol API secret** per quel flusso.
- (Consigliato) segnare `generate_lead` come **evento chiave** (key event).
  NON segnare `form_submit_attempt` come evento chiave: è solo un tentativo.

## 3. Dimensioni personalizzate da creare in GA4

Il plugin invia i parametri ma **non** può creare le dimensioni nella proprietà GA4.
Crea manualmente in *Amministrazione → Definizioni personalizzate* dimensioni
**event-scoped** per:

| Parametro | Ambito |
|-----------|--------|
| `business_area` | event |
| `service_type` | event |
| `audience_type` | event |
| `site_section` | event |
| `form_id` | event |
| `form_name` | event |

## 4. Provider form

| Provider | Stato | Meccanismo |
|----------|-------|------------|
| Elementor Pro Forms | Supportato | hook `elementor_pro/forms/new_record` (server, post-submission) |
| Fluent Forms | Supportato | hook `fluentform/submission_inserted` (server, post-inserimento) |
| HTML classici | Via API/bridge | `window.atiGa4.trackConfirmedLead()` dopo risposta AJAX positiva, o `ati_track_confirmed_event()` |
| Breakdance | **Non ancora supportato** | nessun hook PHP server-side ufficiale verificato; usare bridge/API manuale |

## 5. Integrazione manuale

Server-side (dopo il salvataggio riuscito del lead):
```php
do_action( 'ati_confirmed_lead', 'mio_provider', 'form-123', array(), array(
    'page_location' => home_url( '/grazie' ),
) );
// oppure:
ati_track_confirmed_event( 'generate_lead', array(), array( 'provider' => 'mio_provider', 'form_id' => 'form-123' ) );
```

Client-side (dopo risposta AJAX positiva del form):
```js
window.atiGa4 && window.atiGa4.trackConfirmedLead({}, { form_id: 'form-123' });
```

## 6. Hook e filtri pubblici

| Nome | Tipo | Scopo |
|------|------|-------|
| `ati_confirmed_lead` | action | Notifica un lead confermato dai provider |
| `ati_form_providers` | filter | Aggiunge/rimuove provider form |
| `ati_ga4_event_params` | filter | Modifica i parametri dell'evento GA4 |
| `ati_ga4_allowed_params` | filter | Estende la allowlist dei parametri commerciali |
| `ati_project_classification` | filter | Modifica la classificazione risolta |
| `ati_event_allowed` | filter | Blocca un evento prima dell'invio |
| `ati_has_analytics_consent` | filter | Override del consenso analytics rilevato |
| `ati_iubenda_analytics_purpose` | filter | Indice purpose iubenda per l'analytics |
| `ati_ga4_rest_allowed_events` | filter | Allowlist eventi dell'endpoint REST |
| `ati_event_queued` | action | Reagisce a un evento accodato |
| `ati_event_sent` | action | Reagisce a un evento inviato |
| `ati_event_failed` | action | Reagisce a un evento fallito |
| `ati_event_discarded` | action | Reagisce a un evento scartato (con reason_code) |
| `ati_event_deduplicated` | action | Reagisce a un evento deduplicato |
| `ati_ga4_migrated` | action | Migrazione schema completata |

## 6-bis. Consenso analytics con iubenda (dettaglio)

Il consenso **analytics** (categoria *statistiche/misurazione*) è distinto dal marketing
e governa GA4 Measurement Protocol. Per iubenda, lato server:

- **Sorgente letta**: i cookie `_iub_cs-<id>` (JSON). Viene letto il campo
  `purposes[<n>]`. Nessun contenuto del cookie viene mostrato nel pannello.
- **Purpose predefinito**: `4` (Measurement) — best-effort. iubenda può numerare i
  purpose diversamente a seconda della configurazione della privacy policy.
- **Come si configura il purpose**: filtro WordPress
  ```php
  add_filter( 'ati_iubenda_analytics_purpose', function () { return 4; } );
  ```
  In alternativa si può forzare il consenso analytics con il filtro
  `ati_has_analytics_consent` (sconsigliato) o impostando la modalità su *Auto*.
- **Cookie assente**: nessun purpose leggibile ⇒ consenso analytics = **false**
  (comportamento sicuro, GA4 MP non invia).
- **Consenso negato**: `purposes[<n>] !== true` ⇒ analytics = **false**.
- **Revoca**: alla revoca iubenda riscrive `_iub_cs-*`; alla richiesta successiva il
  purpose non risulta più `true` ⇒ analytics = **false**. (Lato server ogni richiesta
  rilegge il cookie: non c'è stato memorizzato.)
- **Fallback formato non riconosciuto**: se il JSON non è decodificabile o manca
  `purposes`, il rilevamento ritorna **false** senza inventare un consenso.

Lato browser, il rilevamento del consenso **marketing** (per Meta) resta gestito da
`hasMarketingConsent()` in `tag-inserter.php` (invariato). La diagnostica del pannello
GA4 mostra: provider rilevati, stato analytics, modalità e purpose iubenda configurato,
**senza** mostrare i cookie.

## 6-ter. Semantica dello stato `sent` (limite noto)

Un record diventa `sent` **solo** quando: il payload è stato costruito, la richiesta HTTP
è stata realmente eseguita, non c'è errore di trasporto, e la risposta è coerente con la
policy dell'adapter (HTTP 2xx dell'endpoint *collect*).

**Limite documentato**: l'endpoint di raccolta GA4 (`/mp/collect`) restituisce 2xx (di
norma 204) **senza validare semanticamente** il payload (nomi evento/parametri). Perciò
`sent` significa "richiesta accettata a livello di trasporto", non "evento semanticamente
valido in GA4". Per la validazione semantica si usa l'endpoint di **debug/validazione**
(`/debug/mp/collect`), che resta **obbligatorio** nella configurazione iniziale tramite
il pulsante *Testa configurazione GA4*.

Un evento privo di `client_id` reale **non** viene mai inviato né marcato `sent`: viene
`discarded` con `reason_code=missing_client_id` e conteggiato nella diagnostica coda.

## 7. Sicurezza dell'API Secret

L'API Secret non compare mai in: HTML, JavaScript, risposte REST, log, report
diagnostici, commit, file di esempio. È letto dalla costante `ATI_GA4_API_SECRET`
(prioritaria) o dall'opzione WordPress. Nel pannello è mascherato; per rimuoverlo usa
l'apposita casella "Rimuovi il secret salvato".

## 8. Migrazione e disinstallazione

- La migrazione (`ATI_GA4_Migration`) è **idempotente e versionata**
  (`ati_ga4_schema_version`), non distruttiva: crea la tabella coda e imposta i default
  (con `add_option`, mai sovrascrivendo valori esistenti). Le funzionalità
  server-confirmed restano **OFF** dopo l'aggiornamento.
- La disinstallazione (`uninstall.php`) rimuove tutte le opzioni del plugin, la tabella
  `wp_ati_event_queue`, la tabella `wp_fst_user_cookies` e gli eventi cron.

## 9. Coda e affidabilità

L'invio a GA4 è asincrono (coda `wp_ati_event_queue`, worker WP-Cron o Action Scheduler
se presente). Ogni record ha: id, event_id, event_name, destination, payload sanitizzato
(no PII), status (`pending`/`processing`/`sent`/`failed`/`discarded`), attempts,
timestamps, next_attempt_at, last_error. Retry con backoff progressivo, recupero degli
elementi bloccati in `processing`, claim atomico anti-concorrenza, cleanup automatico.
Diagnostica, retry manuale ed eliminazione dei falliti nella pagina GA4.
