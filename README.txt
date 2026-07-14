# Quick Tracking Integration

Un plugin WordPress che consente di installare rapidamente Facebook Pixel, Google Analytics 4 e Google Tag Manager senza toccare il codice.

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
