# allTracking

- **WordPress:** il plugin esistente resta nella radice, senza modifiche alla sua struttura. Installazione e configurazione: [README.txt](README.txt).
- **Astro:** integrazione riutilizzabile separata, runtime consenso/attribuzione/conversioni confermate, popup diagnostico, guard cookie e editor impostazioni con esportazione JSON; istruzioni backend: [astro/README.md](astro/README.md).

I due pacchetti non vanno installati insieme sullo stesso frontend. Il pacchetto Astro è mantenuto nel branch dedicato `feat/astro-latest-tracking`: non è pubblicato su npm e non installa automaticamente un backend, un CRM o un CMS. La versione WordPress resta quella della base `main`, senza modifiche.
