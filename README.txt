# Quick Tracking Integration

Un plugin WordPress che consente di installare rapidamente Facebook Pixel, Google Analytics 4 e Google Tag Manager senza toccare il codice.

Versione: 0.13.0

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

## Blocco dei cookie senza consenso

Tab **Impostazioni → Tracking Integration → "Blocco Cookie"**. La funzione impedisce la
scrittura dei cookie non consentiti e cancella quelli già presenti, in base a regole
**granulari per categoria di consenso**.

È **attiva out of the box**: il plugin parte con 22 regole predefinite che bloccano i
cookie dei tracker più diffusi quando manca il consenso della loro categoria. Non serve
configurare nulla, e con il consenso concesso il sito si comporta esattamente come prima.

Bloccati di default (in assenza del consenso corrispondente):

| Cookie | Categoria |
| --- | --- |
| `_ga`, `_ga_*` (es. `_ga_0RVDVFM24W`), `_gid`, `_gat*`, `__utm*` | analytics |
| `_hj*` (Hotjar), `_clck`/`_clsk` (Clarity), `_pk_*` (Matomo), `_ym_*` (Yandex) | analytics |
| `_fbp`, `_fbc` (Meta Pixel) | marketing |
| `_gcl_*`, `_gac_*`, `IDE`, `test_cookie` (Google Ads / DoubleClick) | marketing |
| `_uet*` (Microsoft Ads), `li_fat_id`/`bcookie`/`lidc` (LinkedIn) | marketing |
| `_tt*` (TikTok), `_pin_*` (Pinterest), `personalization_id` (X), `_scid*` (Snapchat) | marketing |

**Come togliere o disattivare i blocchi**, dal tab:

- disattiva la **singola regola** togliendo la spunta «Attiva» e salva;
- **elimina** una regola svuotando il campo «Valore» e salva;
- «Disattiva tutte» / «Elimina tutte» agiscono sull'intero set;
- «Ripristina configurazione predefinita» riporta tutto allo stato iniziale;
- il menu **Modalità → Disattivato** spegne l'intera funzione conservando le regole.

Finché non salvi, le regole sono un *default virtuale* (l'opzione non esiste ancora nel
database); al primo salvataggio diventano tue. Chi elimina tutte le regole e salva non se
le vede riapparire.

### Come funziona

Il guard (`assets/js/cookie-guard.js`) viene stampato inline in `wp_head` a **priorità 0**
— prima del container GTM e di qualunque script accodato — e sostituisce il setter di
`document.cookie`. Prima di ogni scrittura valuta nome e dominio del cookie contro le
regole e lo stato di consenso; se la categoria non è consentita, la scrittura viene
scartata. In parallelo una passata periodica cancella i cookie già presenti che violano
le regole (utile per i cookie scritti prima dell'installazione del guard o da script
caricati in ritardo).

Il consenso viene **rivalutato a ogni passata e a ogni evento di consenso**: appena
l'utente accetta una categoria, i cookie corrispondenti tornano a passare senza ricaricare
la pagina.

### Modalità

| Modalità | Comportamento |
| --- | --- |
| `enforce` (default) | Blocca la scrittura e cancella i cookie non consentiti |
| `monitor` | Scrive in console cosa bloccherebbe/cancellerebbe, senza toccare nulla |
| `off` | Nessun intervento, nessun output sul front-end (le regole restano salvate) |

Se qualcosa non torna, passa a **monitor**: la console mostra esattamente cosa verrebbe
bloccato, così puoi verificare prima di riattivare.

**Attenzione al rilevamento del consenso.** Se il sito non ha un CMP riconosciuto
(Complianz, iubenda, Cookiebot, OneTrust) o un cookie di consenso personalizzato, il
consenso risulta sempre assente e i cookie di analytics e marketing vengono bloccati
*sempre*, anche per chi accetta. Il tab lo segnala in rosso e la tabella dei consensi
mostra affiancati lo stato visto dal server e quello visto dal browser.

### Regole

Ogni regola è: *categoria* + *operatore* + *valore* + *azione*. Il cookie viene bloccato
**solo quando manca il consenso della categoria indicata**.

| Operatore | Esempio |
| --- | --- |
| è esattamente | `_fbp` |
| contiene | `analytics` |
| inizia con | `_ga` → `_ga`, `_ga_ABC123`, `_gali` |
| finisce con | `_id` → `visitor_id`, `user_id` |
| inizia con … e finisce con … | `_pk_` + `.1` → `_pk_id.1` |
| pattern (`*` e `?`) | `_hj*`, `_cl?k`, `*_uet*` |
| regex | `^_ga(_[A-Z0-9]+)?$` (senza delimitatori) |
| appartiene al dominio | `doubleclick.net` (include i sottodomini) |

Categorie: `marketing`, `analytics` (statistiche), `preferences` (funzionali) e `always`
(blacklist: blocca sempre, indipendentemente dal consenso). Azioni: blocca la scrittura,
cancella se presente, oppure entrambe. Le regole sono valutate in ordine: vince la prima
che blocca. Alle regole predefinite se ne possono aggiungere quante se ne vogliono.

### Sicurezza: cosa non viene mai toccato

Un'allowlist **non modificabile** protegge i cookie che romperebbero il sito o
cancellerebbero la scelta di consenso: sessione WordPress (`wordpress*`, `wp-*`, `wp_*`),
`PHPSESSID`, WooCommerce, i cookie dei CMP (`cmplz_*`, `_iub_cs-*`, `CookieConsent*`,
`OptanonConsent`, `cookielawinfo-*`, `cky-*`, …) e quelli del plugin (`fst_*`, `ati_*`).
Si può estendere con un'allowlist personalizzata (un pattern per riga, con `*` e `?`), che
ha la precedenza su qualunque regola. Inoltre, di default il blocco **non si applica agli
utenti loggati** e le **cancellazioni di cookie non vengono mai bloccate** (altrimenti
nessuno potrebbe più rimuovere un cookie).

### Consenso granulare e CMP

Il pannello mostra, affiancati, i consensi visti dal **server** (cookie della richiesta) e
quelli visti dal **browser in tempo reale**, per le tre categorie non necessarie. Sotto,
l'elenco dei cookie presenti con l'esito che ciascuno avrebbe, i cookie ricevuti dal
server (inclusi gli `HttpOnly`) e un **tester** in cui digitare un nome di cookie per
vedere subito quale regola lo colpisce.

Il rilevamento supporta Complianz, iubenda, Cookiebot e OneTrust, con opzione per forzare
un CMP specifico quando sul sito ne convivono più di uno. Gli indici dei purpose iubenda
(3/4/5 di default) sono configurabili. I cookie di consenso personalizzati di marketing e
analytics sono quelli già impostati nei tab **Generale** e **GA4 Server-Side**: il blocco
cookie li riusa senza duplicarli; solo la categoria "preferenze" ha un campo proprio.

### Limiti

- I cookie di **terze parti impostati via header HTTP** (iframe YouTube, DoubleClick, …)
  non sono intercettabili da JavaScript: vanno bloccati non caricando lo script/iframe.
- I cookie `HttpOnly` non sono accessibili da JavaScript: solo la **pulizia lato server**
  (opzionale) può rimuoverli.
- `localStorage` / `sessionStorage` non sono cookie e non sono gestiti.
- Il blocco non sostituisce un CMP: fa rispettare le scelte che il banner ha già raccolto.

Nota: il plugin può agire solo sui cookie del **proprio dominio**. I cookie che il browser
mostra per altri domini (`.leadconnectorhq.com`, `.protocollodeminicis.com`, …) sono di
altri siti e nessuno script di questo sito può leggerli o cancellarli.

Test: `php tests/cookie-guard-tests.php` (motore di regole PHP) e
`node tests/cookie-guard-tests.js` (guard nel browser, con DOM simulato).

## Widget di debug sul front-end

Dalla 0.13.0, con `WP_DEBUG` attivo, chi è **loggato come amministratore** vede sul sito un
pannello richiudibile in basso a destra (`🍪 Tracking debug`) che risponde alla domanda
«questo cookie ci dovrebbe essere o no?» senza aprire i DevTools. Il badge sul pulsante
conta i problemi rilevati. I visitatori non lo vedono **mai**: non compare per gli utenti
anonimi, né in admin, AJAX, REST, cron, feed o embed.

| Scheda | Cosa mostra |
| --- | --- |
| **Consenso** | Consenso per categoria visto dal server e dal browser in tempo reale, CMP configurato e CMP realmente rilevati, valore dei cookie di consenso personalizzati |
| **Cookie** | Ogni cookie leggibile da JavaScript con l'esito che ha con le regole attive, categoria e regola; in coda i cookie ricevuti **solo dal server** (`HttpOnly`, terze parti) |
| **Attesi** | Esito **atteso** per ciascun cookie del plugin (`deve esserci` / `non deve esserci` / `dipende`) confrontato con la realtà, con la motivazione |
| **Blocco** | Modalità del blocco cookie, se sta davvero agendo su questa richiesta, regole attive, e i contatori reali di scritture bloccate e cookie cancellati |
| **Tag & GA4** | Tag configurati, cosa è realmente caricato (`dataLayer`, `gtag`, `fbq`, bridge GA4), pipeline GA4 server-side (`client_id`/`session_id`, coda, worker), n8n e Meta CAPI come sola presenza |

Un cookie **presente che non dovrebbe esserci** viene segnalato come errore; uno **atteso e
assente** come avviso. «dipende» significa che entrambi gli esiti sono legittimi (il cookie
lo decide il container GTM, manca un `fbclid`, l'evento non è ancora stato inviato).

Il pulsante **Copia JSON** mette negli appunti la fotografia completa (server + browser),
pronta da incollare in un ticket.

Il widget è di **sola lettura**: non scrive cookie, non invia eventi, non modifica il
tracking. Non contiene segreti — API Secret GA4, token CAPI, header di autenticazione e
path del webhook n8n non vengono mai inviati al browser, solo il flag «impostato» — e il
server non manda alcun valore di cookie, solo i nomi.

Gli esiti sono calcolati dallo **stesso** `assets/js/cookie-guard.js` che gira sul sito
(caricato con `mode=off` quando il blocco non è attivo sulla richiesta, quindi senza
installare nulla): non possono divergere dal comportamento reale. Attenzione però al caso
tipico — il blocco è escluso per gli utenti loggati, quindi su quella pagina *non sta
bloccando nulla* e gli esiti sono una simulazione: il pannello lo dichiara e invita a
verificare in navigazione anonima.

Controllo: **Impostazioni → Tracking Integration → Generale → «Widget di debug
(front-end)»** con `Automatico` (default, solo con `WP_DEBUG`), `Sempre` o `Mai`. In
`wp-config.php` la costante `ATI_DEBUG_BAR` ha la precedenza su tutto:

```php
define( 'ATI_DEBUG_BAR', true );  // forza il widget anche senza WP_DEBUG
define( 'ATI_DEBUG_BAR', false ); // lo spegne in ogni caso
```

Per mostrarlo a qualunque utente loggato (per default serve `manage_options`):

```php
add_filter( 'ati_debug_bar_capability', function () { return 'read'; } );
```

Dalla console del browser le stesse informazioni sono interrogabili con
`atiDebugBarApi` (sola lettura): `.cookies()`, `.consent()`, `.evaluate('_ga')`,
`.compare(riga, presenti)`, `.problems(presenti)`.

Test: `php tests/debug-bar-tests.php` (attese e diagnostica, logica pura) e
`node tests/debug-bar-tests.js` (confronto atteso/reale nel browser, DOM simulato).

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
