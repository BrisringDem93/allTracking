# Changelog — @alltracking/astro

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/). Versioni del solo pacchetto Astro, indipendenti da quelle del plugin WordPress (vedi `../CHANGELOG.md`).

## [0.2.2] - 2026-11-24 — Audit: form con ClientRouter, ricevute, guard e editor

### Corretto
- **Form POST persi con `<ClientRouter />`**: annullando `astro:before-preparation`, Astro ripiegava su `location.href`, cioè un GET senza corpo verso l'action. Lead e form nativi/Astro Actions non arrivavano al server. Ora ogni form viene marcato `data-astro-reload` prima del ClientRouter e il browser esegue l'invio nativo (`runtime.ts`).
- **Consenso mai confermato con orologio del visitatore indietro**: la ricevuta veniva scartata se `createdAt` del server superava anche di pochi millisecondi l'ora locale. Ora c'è una tolleranza di 24 ore e la data salvata è limitata all'ora locale (`consent-client.ts`).
- **Ricevute di rifiuto inventate da Cookiebot/iubenda**: un evento CMP (anche «Accept») arrivato con un consenso nativo in attesa o non confermato registrava un rifiuto e scartava il consenso in corso. Ora viene degradato solo un consenso confermato che la CMP nega.
- **Avviso «Registrazione non confermata» invisibile**: veniva scritto nel dialog già chiuso e cancellato al primo focus. Ora sta fuori dal dialog, resta visibile e viene annunciato fino al salvataggio successivo (`ConsentPanel.astro`, `consent-ui.ts`).
- **Dialog preferenze**: focus, storage o il controllo iubenda resettavano gli interruttori non salvati; «Salva» poteva quindi ri-concedere un consenso appena revocato.
- **Storage pieno**: con `setItem` fallito ma `removeItem` riuscito, il primo focus cancellava il consenso confermato in memoria.
- **Guard alla partenza**: su `pagehide` (e prima ancora su `astro:before-preparation`) il setter nativo veniva ripristinato, quindi le scritture di listener `pagehide`/`unload` passavano senza consenso. Ora il guard resta installato con il consenso congelato e senza pulizie (`early.ts`).
- **Runtime «morto» dopo una navigazione annullata che non scarica la pagina** (download, 204, Stop): lo smontaggio ora avviene solo su `pagehide`.
- **Cookie consentiti cancellati dopo un cambio di pathname via `history`**: la pulizia ora richiede che il documento sia ancora attivo.
- **Scritture «in scadenza» con data non valida** (es. `expires=0`): `Date.parse` le considerava passate e le lasciava passare, mentre i parser cookie le memorizzano come cookie di sessione. Per i nomi bloccati ora viene forzato `Max-Age=0`; vale l'ultimo `Max-Age` valido. I nomi vuoti (`=_ga=…`) sono valutati come il browser li espone.
- **`stats()` disattivava le pulizie** quando un altro script sostituiva `document.cookie`: ora è in sola lettura.
- **`TrackingHead` su pagine on-demand**: leggeva `early.inline.js` dal disco con `import.meta.resolve` a ogni render, quindi falliva su output bundled/serverless/edge. Ora il bundle è incluso come stringa a build time (`?raw`) tramite il nuovo `head.ts`, senza `node:*`.
- **Validazione percorsi con backtracking esponenziale** (`options.ts`): un percorso errato di ~35 caratteri bloccava `astro build` per minuti. La regex ora è lineare e la lunghezza viene controllata prima.
- **Attribuzione**: i campi hidden duplicavano controlli applicativi `select`/`textarea`/`form="id"` o aggiunti dopo; `fbc` derivato cambiava timestamp a ogni chiamata in presenza di un `_fbc` vecchio; email, URL e marker segreti codificati due volte passavano il filtro campagne.
- **Diagnostica**: guard `off` (default) segnalato come guasto («!»/ATTENZIONE); valore di un cookie senza nome mostrato come nome; CMP assente/non valido mostrato come «bridge»; il polling di 1 s cancellava la selezione del testo; dopo un trascinamento touch il tocco successivo su un pulsante veniva ignorato.
- **Editor impostazioni**: allowlist «personalizzata» precompilata con i 54 pattern protetti; `settingsPath` esportabili ma poi rifiutati da `allTracking()`; campo durata vuoto esportato come 0 (pulizie temporizzate disattivate); errori in inglese con indice da 0; tester bloccato da errori estranei agli ID; focus perso con «Su»/«Elimina»; editor inattivo dopo un ripristino dalla BFCache.
- **Test su Windows**: path Windows passati al compilatore Astro e `npm` invocato senza `npm.cmd` (solo harness di test).

### Test
- `tests/regressions.test.mjs` più casi in `consent-ui`, `settings`, `runtime`, `startup` e fixture (ClientRouter reale con form POST). 120 test in totale; i 17 nuovi o aggiornati falliscono sul codice 0.2.1.
