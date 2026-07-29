# Quick Tracking Integration

Un plugin WordPress che consente di installare rapidamente Facebook Pixel, Google Analytics 4 e Google Tag Manager senza toccare il codice.

Versione: 0.11.0

## GA4 "server-side first" (conversioni confermate)

Dalla 0.8.0 il plugin adotta un'architettura GA4 **server-side first**: il Google Tag /
GTM resta lato browser per page_view, session_start, first_visit, user_engagement,
attribuzione, sessioni, client_id, session_id, Consent Mode e misurazione avanzata; il
backend invia via GA4 Measurement Protocol **solo gli eventi di business confermati**
(priorità `generate_lead`), dopo la conferma reale del provider del form.

Punti chiave:

- Il semplice evento DOM `submit` **non** è più una conversione GA4 confermata.
- `client_id`/`session_id` sono quelli **reali** del Google Tag (nessun UUID casuale).
- Consenso **analytics separato** dal marketing; GA4 MP dipende dal consenso analytics.
- Coda asincrona affidabile, deduplica interna, endpoint EU/Global, endpoint di
  validazione, API Secret mai esposto.
- Meta Pixel / Meta Conversions API / n8n **restano invariati**.

Configurazione: **Impostazioni → Tracking Integration → tab "GA4 Server-Side"**. Guida completa in
`docs/ga4-configuration.md`; audit e razionale in `docs/ga4-server-side-audit.md`;
matrice di test in `docs/ga4-test-matrix.md`. Le nuove funzionalità sono **disattivate di
default** dopo l'aggiornamento e vanno abilitate dall'amministratore.

## Campi hidden compilati automaticamente nei form

Se un form contiene input hidden con nomi riconosciuti, il plugin li compila prima
dell'invio: il provider del form li salva e li inoltra a CRM / n8n / Meta CAPI.
Basta aggiungerli al form, senza altro codice:

```html
<input type="hidden" name="fbclid" value="">
<input type="hidden" name="gclid" value="">
<input type="hidden" name="fbc" value="">
<input type="hidden" name="fbp" value="">
```

Nomi riconosciuti: `fbclid`, `gclid`, `fbc`, `fbp`, `gbraid`, `wbraid`, `msclkid`,
`ttclid`, `twclid`, `li_fat_id`, `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term`, `utm_content`, `external_id`.

Il campo viene riconosciuto anche quando il form builder altera il `name`:

- `form_fields[fbclid]` (Elementor: si usa l'ultima parentesi);
- id `form-field-fbclid` o `field-fbclid`;
- classe CSS `ati-field-fbclid` (utile con WPForms/Gravity, che generano `name` numerici);
- attributo `data-ati-field="fbclid"` (match esplicito, ha la precedenza).

Da dove arrivano i valori:

| Campo | Sorgenti, in ordine di precedenza |
| --- | --- |
| `fbclid` | parametro URL → coda del cookie `_fbc` → click id memorizzato |
| `gclid` | parametro URL → coda del cookie `_gcl_aw` → click id memorizzato |
| `fbc` | cookie `_fbc` → costruito come `fb.<sub>.<timestamp-ms>.<fbclid>` |
| `fbp` | **solo** cookie `_fbp` del Pixel (mai generato) |
| `external_id` | cookie `fst_uid` (pseudonimo del plugin) |
| `utm_*`, altri click id | parametro URL → valore memorizzato |

### `fbc`: chi lo genera e come arriva al form

`fbp` viene **solo letto** dal cookie del Pixel: non è generabile senza falsare il
match con Meta. `fbc` invece viene ricostruito da `fbclid` quando il cookie manca:

1. **Lato server, al caricamento della pagina** (`fst_capture_fbclid_from_url()`,
   hook `template_redirect`): se l'URL contiene `fbclid`, `_fbc` non esiste **e c'è il
   consenso marketing**, il valore viene costruito da `fst_build_fbc_from_fbclid()` e il
   cookie `_fbc` viene scritto **prima del primo byte di HTML**. Quando lo script compila
   il form, il cookie c'è già: il campo hidden riceve lo **stesso valore** che finirà
   nella Conversions API.
2. **Lato server, sugli eventi** (`fst_build_user_data()`): stessa funzione, per i casi in
   cui il `fbclid` arriva dal frontend e non dall'URL.
3. **Lato client** (`form-fields.js`): se il cookie manca — perché non c'è consenso, o
   perché la pagina arriva da una cache full-page — il valore viene costruito nel browser
   **solo per riempire il campo**, senza scrivere nulla nello storage.

Il formato è quello ufficiale Meta `fb.<subdomain-index>.<creation-time>.<fbclid>`, con
`creation-time` in **millisecondi** (dalla 0.11.0: prima lato server erano secondi).

Regole di sicurezza:

- **Nessun identificatore viene inventato**: se il valore non è disponibile il campo
  resta vuoto. L'unica costruzione ammessa è `fbc`, come descritto sopra.
- I campi **già valorizzati** dal sito o dall'utente non vengono sovrascritti; i campi
  hidden non riconosciuti (`_wpnonce`, `redirect_to`, ...) non vengono toccati.
- **Nessuno storage senza consenso marketing.** Con il consenso i click id vengono
  memorizzati (cookie `fst_clid` 90 giorni + `sessionStorage`) e restano disponibili
  nelle pagine successive; senza consenso non viene né scritto né letto nulla, quindi
  i campi si compilano con quanto è nell'URL della pagina corrente — che è il caso
  tipico, perché il form sta sulla landing raggiunta dall'annuncio. Stessa regola già
  applicata a `fst_uid`.
- La compilazione viene ripetuta sui form inseriti dopo il caricamento (AJAX, popup,
  multistep), al cambio di consenso e in fase di *capture* del `submit`: i cookie
  `_fbp`/`_fbc`, che compaiono solo dopo l'accettazione del banner, finiscono comunque
  nell'invio.

La funzione è attiva di default e si disattiva da **Impostazioni → Tracking Integration
→ Generale → "Campi hidden nei form"**. Con `WP_DEBUG` attivo la console mostra quali
campi vengono compilati. Test: `node tests/form-fields-tests.js`.

## Installazione

1. Copia la cartella del plugin nella directory `wp-content/plugins` del tuo sito WordPress.
2. Attiva il plugin dal pannello di amministrazione.
3. Apri la pagina **Tracking Integration** nelle impostazioni e inserisci gli ID richiesti.

## Rilevamento del consenso marketing

Il listener principale è la funzione JavaScript `setupConsentListener()` in
`includes/tag-inserter.php`. Ogni 500 ms richiama `hasMarketingConsent()` e
confronta il risultato con lo stato precedente. Se rileva un cambiamento:

- all'accettazione aggiorna `window.marketingConsent`, persiste `fst_uid` e
  prova a caricare Facebook Pixel tramite AJAX;
- alla revoca aggiorna lo stato e disabilita le successive chiamate dirette a
  Facebook Pixel.

In aggiunta al controllo periodico, il plugin reagisce ai segnali ufficiali dei
CMP supportati e, per ciascuno, rilegge sempre i cookie per determinare sia
l'accettazione sia la revoca:

- Complianz: evento `cmplz_event_marketing` nel `dataLayer`; la revoca è
  intercettata anche dal controllo periodico del cookie;
- iubenda: eventi `iubenda_consent_*` e `iubenda_preference_*` nel `dataLayer`,
  inclusi `iubenda_consent_given` e `iubenda_consent_rejected`;
- Cookiebot: `CookiebotOnAccept` e `CookiebotOnDecline` su `window`;
- OneTrust: `OneTrustGroupsUpdated` su `window`.

All'avvio `identifyCMPs()` identifica i CMP tramite oggetti JavaScript, cookie e
script caricati. Per iubenda il controllo del consenso preferisce
`_iub.cs.api.getPreferences()` e usa `_iub_cs-*` come fallback. Per Complianz,
Cookiebot e OneTrust vengono usate, quando disponibili, anche le rispettive API
client-side. Con `WP_DEBUG` attivo, la console mostra i CMP rilevati, le prove
usate per identificarli, i nomi dei cookie visibili, le preferenze iubenda e il
risultato finale del controllo marketing.

Se **Nome cookie consenso** è vuoto, il plugin usa il rilevamento automatico dei
formati più diffusi già supportati: `cmplz_marketing` di Complianz, `_iub_cs-*`
di iubenda, `CookieConsent` di Cookiebot e `OptanonConsent` di OneTrust. Il campo
serve soltanto per aggiungere un cookie custom, il cui valore di consenso deve
essere `allow`. Lo stesso controllo è eseguito lato PHP da
`ati_has_marketing_consent()`: in assenza di un consenso valido il Pixel
client-side non viene restituito dall'endpoint AJAX.

Nota: la revoca impedisce nuovi eventi tramite la funzione `fbq` usata dal
plugin, ma non rimuove dal DOM uno script Meta già scaricato. Per una revoca
rigorosa è opportuno configurare anche il CMP affinché blocchi lo script per la
categoria marketing o ricarichi la pagina.

## Evento JavaScript custom per il consenso

L'impostazione **Evento JS consenso custom** accetta il nome di un evento
JavaScript, ad esempio `myConsentChanged`. Il plugin registra il listener sul
`document`; il banner custom può quindi notificare un cambio così:

```js
// Prima aggiorna il cookie usato dal plugin.
document.cookie = 'cmplz_marketing=allow; path=/; SameSite=Lax';

// Poi notifica il cambio di consenso.
document.dispatchEvent(new CustomEvent('myConsentChanged'));
```

Quando riceve l'evento, il plugin rilegge il cookie e aggiorna
`window.marketingConsent`. In caso di consenso concesso:

1. persiste l'identificatore `fst_uid`;
2. richiede via AJAX il caricamento di Facebook Pixel.

In caso di revoca disabilita invece le chiamate successive al Pixel. Se
`WP_DEBUG` è attivo, entrambi i cambi vengono scritti nella console del browser.

Il contenuto di `event.detail` non viene letto: il cookie è la sorgente di
verità. Prima del dispatch il CMP deve quindi aggiornare un cookie riconosciuto
o il cookie custom configurato. Con valore `allow` il consenso viene concesso;
se il cookie viene rimosso o assume un altro valore, viene revocato. Anche senza
evento custom, il controllo periodico rileva il cambio entro circa 500 ms.

L'evento deve essere emesso dopo che il codice del plugin ha registrato il
listener. Se il banner può emetterlo molto presto, eseguire il dispatch dopo
`DOMContentLoaded` oppure assicurarsi che lo script del plugin sia già stato
caricato.

Il plugin aggiungerà automaticamente i tag nel `<head>` del sito secondo le opzioni selezionate. Rimuovendo il plugin verranno cancellate tutte le impostazioni salvate.
