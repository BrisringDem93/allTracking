# allTracking

Un semplice plugin WordPress per tracciare gli eventi e inviarli alla Facebook Graph API.

## Installazione

1. Copia la cartella `fb-event-tracker` all'interno della directory `wp-content/plugins` del tuo sito WordPress.
2. Attiva il plugin dal pannello di amministrazione di WordPress.
3. Dal menu "FB Event Tracker" inserisci il token API e l'ID del pixel forniti da Facebook.
4. Scegli se inviare gli eventi direttamente a Facebook o al tuo Tag Manager.
5. Abilita Google Analytics 4 e/o Google Tag Manager e inserisci gli ID corrispondenti.

Il plugin, una volta configurato, invierà automaticamente un evento `PageView` ad ogni caricamento di pagina (solo se token e Pixel ID sono impostati). Se abilitato, verrà eseguito anche il tracciamento tramite GA4 e GTM.
