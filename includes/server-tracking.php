<?php
/**
 * Server-Side Tracking Handler
 * 
 * Questo file gestisce il tracciamento server-side per eventi Facebook e GA4.
 * Il flusso funziona così:
 * 1. JavaScript genera un event_id univoco sul client
 * 2. JavaScript invia l'evento sia a Facebook che al server
 * 3. Il server riceve l'evento con lo stesso event_id per evitare duplicazioni
 * 
 * ========================================
 * ARCHITETTURA DEL SISTEMA
 * ========================================
 * 
 * FRONTEND (JavaScript):
 * - Genera event_id univoci (formato: evt_timestamp_random)
 * - Invia a Facebook Pixel direttamente
 * - Invia al server WordPress via AJAX/REST
 * 
 * BACKEND (questo file):
 * - Riceve eventi con event_id dal frontend
 * - Costruisce user_data (IP, cookies, pseudonimi)
 * - Inoltra a n8n per Facebook Conversions API
 * - Inoltra a GA4 via Measurement Protocol (opzionale)
 * 
 * ENDPOINT DISPONIBILI:
 * - AJAX: wp-admin/admin-ajax.php?action=fst_pageview (solo PageView)
 * - REST: /wp-json/fst/v1/event (eventi personalizzati)
 * 
 * FUNZIONI PRINCIPALI:
 * - fst_ajax_pageview_handler(): Gestisce PageView via AJAX
 * - fst_rest_event_handler(): Gestisce eventi personalizzati via REST
 * - fst_build_user_data(): Costruisce dati utente per Facebook
 * - fst_get_uid(): Genera/recupera pseudonimo utente persistente
 * - fst_send_to_n8n(): Invia a webhook n8n
 * - fst_send_to_ga4(): Invia a Google Analytics 4
 * 
 * ========================================
 * DEDUPLICA EVENTI
 * ========================================
 * 
 * Il sistema evita eventi duplicati usando event_id condivisi:
 * 1. JavaScript genera: evt_1735939200_abc123def
 * 2. Facebook riceve: PageView con eventID = evt_1735939200_abc123def  
 * 3. Server riceve: stesso event_id e lo passa a n8n
 * 4. n8n invia a Facebook API con stesso event_id
 * 5. Facebook deduplica automaticamente usando l'ID
 * 
 * Risultato: 1 solo evento contato, massima precisione.
 * 
 * @package QuickTrackingIntegration
 * @version 1.1
 * @author Francesco de Minicis
 */

// Sicurezza: impedisce accesso diretto al file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ========================================
// SEZIONE 1: AJAX HANDLER PER PAGEVIEW
// ========================================

/**
 * Registra gli endpoint AJAX per il tracciamento PageView
 * 
 * wp_ajax_fst_pageview = per utenti autenticati
 * wp_ajax_nopriv_fst_pageview = per utenti non autenticati (visitatori anonimi)
 * 
 * Questo sostituisce il vecchio approccio template_redirect che tracciava
 * automaticamente ogni caricamento pagina lato server.
 * Ora il JavaScript controlla quando tracciare e genera l'event_id.
 */
add_action( 'wp_ajax_fst_pageview', 'fst_ajax_pageview_handler' );
add_action( 'wp_ajax_nopriv_fst_pageview', 'fst_ajax_pageview_handler' );

// Test per verificare che gli hook siano registrati
if ( WP_DEBUG ) {
    error_log( '[FST] 🔧 Hook AJAX registrati per fst_pageview' );
}

/**
 * Gestisce le richieste AJAX per il tracciamento PageView
 * 
 * Riceve dal JavaScript:
 * - event_id: ID univoco generato dal client per evitare duplicazioni
 * - page_url: URL della pagina corrente
 * - page_title: Titolo della pagina
 * 
 * Flusso:
 * 1. Valida i dati ricevuti
 * 2. Costruisce l'evento con user_data (cookie Meta, IP, etc.)
 * 3. Invia a n8n (se configurato)
 * 4. Invia a GA4 server-side (se abilitato)
 * 5. Restituisce conferma con event_id
 * 
 * @since 1.1
 * @return void Termina con wp_die() e messaggio di conferma
 */

function fst_ajax_pageview_handler() {
    // STEP 0: Controllo utenti loggati - blocca tutto se disabilitato
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        if ( WP_DEBUG ) {
            error_log( '[FST] ❌ PageView AJAX bloccato - utente loggato' );
        }
        wp_die( 'Tracking disabilitato per utenti loggati' );
    }
    
    // STEP 1: Log di debug per verificare che la funzione venga chiamata
    if ( WP_DEBUG ) {
        error_log( '[FST] AJAX PageView handler chiamato' );
        // PII-safe: non logghiamo i dati POST completi.
    }
    
    // STEP 2: Sanitizza e valida i dati ricevuti dal JavaScript
    $event_id = sanitize_text_field( $_POST['event_id'] ?? '' );     // ID univoco generato dal client
    $page_url = esc_url_raw( $_POST['page_url'] ?? '' );            // URL della pagina (es: https://sito.com/pagina)
    $page_title = sanitize_text_field( $_POST['page_title'] ?? '' ); // Titolo della pagina (es: "Homepage - Il mio sito")
    $fbclid = sanitize_text_field( $_POST['fbclid'] ?? '' );         // Facebook Click ID se presente
    
    // STEP 3: Verifica che l'event_id sia presente (obbligatorio per evitare duplicazioni)
    if ( ! $event_id ) {
        if ( WP_DEBUG ) {
            error_log( '[FST] ❌ Event ID mancante' );
        }
        wp_die( 'Event ID richiesto', 400 );
    }

    // STEP 3: Costruisce l'oggetto evento nel formato standard per Facebook Conversions API
    $event = [
        'event_name'       => 'PageView',                 // Nome evento Facebook
        'event_time'       => time(),                     // Timestamp Unix corrente
        'event_source_url' => $page_url,                 // URL sorgente evento
        'action_source'    => 'website',                 // Tipo sorgente (sempre "website")
        'event_id'         => $event_id,                 // ID per deduplica client/server
        'user_data'        => fst_build_user_data( $fbclid ),     // Dati utente (IP, cookies, etc.)
        'custom_data'      => [
            'page_title' => $page_title,                 // Titolo pagina personalizzato
            'triggered_by' => 'javascript'               // Indicatore che proviene da JS
        ]
    ];
    
    // DEBUG: Log dell'evento completo prima dell'invio
    if ( WP_DEBUG ) {
        error_log( '[FST] Evento PageView pronto (id: ' . $event_id . ')' );
    }
    
    // NUOVO: Aggiunge fbclid ai custom_data se presente
    if ( ! empty( $fbclid ) ) {
        $event['custom_data']['fbclid'] = $fbclid;
        if ( WP_DEBUG ) {
            error_log( '[FST] 📘 PageView con FBCLID: ' . $fbclid );
        }
    }

    // STEP 4: Log di debug (se WP_DEBUG è attivo)
    if ( WP_DEBUG ) {
        error_log( '[FST] 🎯 PageView da JavaScript: ID ' . $event_id );
    }

    // STEP 5: Invia l'evento al webhook n8n (se configurato nelle impostazioni)
    fst_send_to_n8n( [ 'data' => [ $event ] ] );
    
    // STEP 6: PageView server-side GA4 (MODALITÀ AVANZATA, OFF di default).
    // Il Google Tag invia già page_view dal browser: per evitare duplicazioni,
    // il page_view server-side parte solo se l'amministratore lo abilita
    // esplicitamente (ati_ga4_server_pageview) oltre al legacy ati_enable_ga4_server.
    if ( get_option( 'ati_enable_ga4_server' ) === '1'
        && class_exists( 'ATI_GA4_Config' ) && ATI_GA4_Config::server_pageview_enabled() ) {
        fst_send_to_ga4( 'page_view', [ 'page_title' => $page_title ], $page_url );
    }

    // STEP 7: Termina l'esecuzione AJAX con messaggio di conferma
    wp_die( 'PageView tracciato: ' . $event_id );
}

// ========================================
// SEZIONE 2: REST API PER ALTRI EVENTI
// ========================================

/**
 * Registra endpoint REST API per eventi personalizzati
 * 
 * Endpoint: /wp-json/fst/v1/event
 * Metodo: POST
 * 
 * Gestisce eventi come:
 * - ButtonClick (clic su pulsanti)
 * - FormStart (inizio compilazione form)
 * - FormSubmit (invio form)
 * 
 * Permette a JavaScript di inviare eventi personalizzati con maggiore flessibilità
 * rispetto agli endpoint AJAX (che sono limitati a PageView).
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'fst/v1', '/event', [
        'methods'             => 'POST',                    // Solo richieste POST
        'callback'            => 'fst_rest_event_handler', // Funzione che gestisce la richiesta
        'permission_callback' => '__return_true',          // Permette accesso a tutti (pubblico)
    ] );
} );

/**
 * Gestisce richieste REST API per eventi personalizzati
 * 
 * Parametri attesi nel body della richiesta POST:
 * - type: tipo evento (ButtonClick, FormStart, FormSubmit, etc.)
 * - label: etichetta descrittiva dell'evento
 * - page: URL della pagina dove avviene l'evento
 * - eventID: ID univoco generato dal JavaScript (OBBLIGATORIO)
 * - email: email utente (solo per FormSubmit, verrà hashata)
 * - phone: telefono utente (solo per FormSubmit, verrà hashato)
 * 
 * @param WP_REST_Request $req Oggetto richiesta REST di WordPress
 * @return WP_REST_Response Risposta JSON con status e event_id
 * @since 1.1
 */

function fst_rest_event_handler( WP_REST_Request $req ) {
    // STEP 0: Controllo utenti loggati - blocca tutto se disabilitato
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        if ( WP_DEBUG ) {
            error_log( '[FST] ❌ REST Event bloccato - utente loggato' );
        }
        return rest_ensure_response( [
            'status' => 'disabled',
            'message' => 'Tracking disabilitato per utenti loggati'
        ] );
    }
    
    // STEP 1: Estrae e sanitizza i parametri base della richiesta
    $type    = sanitize_text_field( $req->get_param( 'type' ) );  // Tipo evento (ButtonClick, FormStart, etc.)
    $label   = sanitize_text_field( $req->get_param( 'label' ) ); // Etichetta descrittiva (es: "Download PDF")
    $page    = esc_url_raw( $req->get_param( 'page' ) );          // URL pagina sorgente
    $fbclid  = sanitize_text_field( $req->get_param( 'fbclid' ) ?? '' ); // Facebook Click ID se presente
    
    // STEP 2: Verifica presenza obbligatoria dell'event_id (per deduplica)
    // A differenza del vecchio sistema, NON generiamo più event_id lato server
    $event_id = $req->get_param( 'eventID' );
    if ( ! $event_id ) {
        return rest_ensure_response( [
            'status' => 'error',
            'message' => 'eventID richiesto dal client'
        ] );
    }
    $event_id = sanitize_text_field( $event_id );

    // STEP 3: Costruisce l'evento base nel formato Facebook Conversions API
    $event = [
        'event_name'       => $type,                     // Nome evento personalizzato
        'event_time'       => time(),                   // Timestamp corrente
        'event_source_url' => $page,                    // URL sorgente
        'action_source'    => 'website',               // Sempre "website"
        'event_id'         => $event_id,               // ID per deduplica client/server
        'custom_data'      => [ 'label' => $label ],   // Dati personalizzati
        'user_data'        => fst_build_user_data( $fbclid ),   // Dati utente (IP, cookie, etc.)
    ];
    
    // NUOVO: Aggiunge fbclid ai custom_data se presente
    if ( ! empty( $fbclid ) ) {
        $event['custom_data']['fbclid'] = $fbclid;
        if ( WP_DEBUG ) {
            error_log( '[FST] 📘 ' . $type . ' con FBCLID: ' . $fbclid );
        }
    }

    // STEP 4: Gestione speciale per FormSubmit - hasha dati sensibili
    // Email e telefono vengono hashati per privacy ma rimangono tracciabili
    if ( $type === 'FormSubmit' || $type === 'Lead' ) {
        // Hash SHA256 dell'email (se fornita)
        if ( $email = $req->get_param( 'email' ) ) {
            $event['user_data']['em'] = hash( 'sha256', strtolower( trim( $email ) ) );
        }
        // Hash SHA256 del telefono (se fornito, solo numeri)
        if ( $phone = $req->get_param( 'phone' ) ) {
            $clean = preg_replace( '/\D+/', '', $phone ); // Rimuove tutto tranne i numeri
            $event['user_data']['ph'] = hash( 'sha256', $clean );
        }
    }

    // STEP 5: Log di debug per monitoraggio
    if ( WP_DEBUG ) {
        error_log( '[FST] ' . $type . ' da JavaScript: ID ' . $event_id );
        // PII-safe: non logghiamo l'evento completo (può contenere user_data).
    }

    // STEP 6: Invia a n8n (webhook personalizzato)
    fst_send_to_n8n( [ 'data' => [ $event ] ] );
    
    // STEP 7: GA4 server-side legacy (Measurement Protocol).
    //
    // IMPORTANTE (server-side first): quando la nuova pipeline "eventi confermati"
    // è attiva, il submit generico NON deve più generare generate_lead. In quel caso
    // Lead/FormSubmit vengono ESCLUSI da questo percorso legacy: il generate_lead
    // parte solo dopo la conferma reale del provider (ati_track_confirmed_event).
    if ( get_option( 'ati_enable_ga4_server' ) === '1' ) {
        // Mappa i nomi degli eventi ai nomi ufficiali GA4 (Measurement Protocol)
        $ga4_name_map = [
            'FormStart'    => 'form_start',       // Evento raccomandato GA4 per inizio form
            'deepInterest' => 'deep_interest',    // Evento personalizzato
            'deepPlus'     => 'deep_plus',        // Evento personalizzato
        ];

        // SERVER-SIDE FIRST: il submit DOM generico NON è una conversione confermata.
        // Lead/FormSubmit non generano MAI generate_lead da questo percorso legacy.
        // Il generate_lead parte esclusivamente dalla pipeline "eventi confermati"
        // (ati_track_confirmed_event) dopo la conferma reale del provider.
        $is_unconfirmed_lead = in_array( $type, [ 'Lead', 'FormSubmit' ], true );

        if ( ! $is_unconfirmed_lead ) {
            $ga4_event_name = isset( $ga4_name_map[ $type ] )
                ? $ga4_name_map[ $type ]
                // Fallback camelCase → snake_case.
                : strtolower( preg_replace( '/([A-Z])/', '_$1', lcfirst( $type ) ) );
            fst_send_to_ga4( $ga4_event_name, [ 'label' => $label ], $page );
        }
    }

    // STEP 8: Restituisce risposta JSON di successo
    return rest_ensure_response( [ 'status' => 'ok', 'event_id' => $event_id ] );
}

// ========================================
// SEZIONE 3: FUNZIONI HELPER
// ========================================

/**
 * Costruisce l'oggetto user_data per Facebook Conversions API
 * 
 * Raccoglie tutti i dati disponibili sull'utente per migliorare il matching
 * e l'attribuzione degli eventi. Include:
 * 
 * - external_id: Pseudonimo persistente generato internamente
 * - fbp: Cookie Facebook Pixel (_fbp) se presente
 * - fbc: Cookie Facebook Click (_fbc) se presente, o costruito da fbclid
 * - client_user_agent: User Agent del browser
 * - client_ip_address: Indirizzo IP del visitatore
 * 
 * Tutti i dati sono anonimi o pseudonimi per rispettare la privacy.
 * 
 * @param string $fbclid Facebook Click ID dal frontend (opzionale)
 * @return array Dati utente formattati per Facebook API
 * @since 1.1
 */
function fst_build_user_data( $fbclid = '' ) {
    // DEBUG: Log di tutti i cookie disponibili
    if ( WP_DEBUG ) {
        error_log( '[FST] Costruzione user_data (cookie non loggati per privacy)' );
    }
    
    // Gestione cookie _fbp con log dettagliato
    $fbp_value = null;
    if ( isset( $_COOKIE['_fbp'] ) ) {
        $fbp_value = sanitize_text_field( $_COOKIE['_fbp'] );
        if ( WP_DEBUG ) {
            error_log( '[FST] Cookie _fbp presente' ); // Valore non loggato (identificatore).
        }
    } else {
        if ( WP_DEBUG ) {
            error_log( '[FST] Cookie _fbp assente' );
        }
    }
    
    $user_data = [
        // Pseudonimo utente persistente (generato internamente, non invasivo)
        'external_id'       => fst_get_uid(),
        
        // Cookie Facebook Pixel (se l'utente ha visitato una pagina con FB Pixel)
        'fbp'               => $fbp_value,
        
        // User Agent del browser (per device matching)
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        
        // Indirizzo IP del visitatore (per geo matching)
        'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    
    // GESTIONE INTELLIGENTE DEL COOKIE _fbc
    // Prima prova a usare il cookie _fbc esistente
    if ( isset( $_COOKIE['_fbc'] ) ) {
        $user_data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );
        if ( WP_DEBUG ) {
            error_log( '[FST] _fbc cookie presente' ); // Valore non loggato.
        }
    } elseif ( ! empty( $fbclid ) ) {
        // Se non c'è cookie _fbc ma abbiamo fbclid, costruisce il valore manualmente.
        $fbc_value        = fst_build_fbc_from_fbclid( $fbclid );
        $user_data['fbc'] = $fbc_value;

        if ( WP_DEBUG ) {
            error_log( '[FST] _fbc costruito da FBCLID' ); // Valore non loggato.
        }

        // Persiste il cookie: le richieste successive (e i campi hidden dei form)
        // useranno lo STESSO valore inviato alla Conversions API.
        fst_persist_fbc_cookie( $fbc_value );

    } else {
        $user_data['fbc'] = null;
        if ( WP_DEBUG ) {
            error_log( '[FST] 📘 Nessun _fbc o FBCLID disponibile' );
        }
    }
    
    // DEBUG: Log finale dei dati user_data costruiti
    if ( WP_DEBUG ) {
        // PII-safe: logghiamo solo la PRESENZA dei campi, mai i valori (external_id, fbp, fbc, IP, UA).
        error_log( '[FST] User data costruiti: ' . wp_json_encode( array(
            'external_id' => ! empty( $user_data['external_id'] ),
            'fbp'         => ! empty( $user_data['fbp'] ),
            'fbc'         => ! empty( $user_data['fbc'] ),
            'ip'          => ! empty( $user_data['client_ip_address'] ),
            'ua'          => ! empty( $user_data['client_user_agent'] ),
        ) ) );
    }
    
    return $user_data;
}

/**
 * Costruisce il valore del cookie _fbc a partire da un fbclid.
 *
 * Formato ufficiale Meta: fb.{subdomain-index}.{creation-time}.{fbclid}
 * dove creation-time è il tempo UNIX in MILLISECONDI (come lo scrive il Pixel).
 *
 * @param string $fbclid Facebook Click ID.
 * @return string Valore _fbc, stringa vuota se il fbclid è vuoto.
 * @since 0.11.0
 */
function fst_build_fbc_from_fbclid( $fbclid ) {
    $fbclid = trim( (string) $fbclid );
    if ( '' === $fbclid ) {
        return '';
    }

    // Indice sottodominio (0 = 'com', 1 = 'example.com', 2 = 'www.example.com').
    $subdomain_index = 1; // Default: cookie impostato su example.com.

    // Il referrer permette di riconoscere i domini fb*.example.com / m.example.com.
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if ( ! empty( $referrer ) ) {
        $referrer_parts = wp_parse_url( $referrer );
        if ( ! empty( $referrer_parts['host'] ) ) {
            if ( preg_match( '/^fb(\d+)\./', $referrer_parts['host'], $matches ) ) {
                $subdomain_index = (int) $matches[1];
            } elseif ( strpos( $referrer_parts['host'], 'm.' ) === 0 ) {
                $subdomain_index = 0; // 'm' considerato dominio base.
            }
        }
    }

    // Millisecondi: è ciò che specifica Meta e ciò che scrive il Pixel.
    $creation_time = (int) round( microtime( true ) * 1000 );

    return "fb.{$subdomain_index}.{$creation_time}.{$fbclid}";
}

/**
 * Persiste il cookie _fbc (90 giorni), SOLO con consenso marketing.
 *
 * Senza consenso non viene scritto nulla sul browser: il valore `fbc` continua ad
 * arrivare al form perché assets/js/form-fields.js lo ricostruisce dal `fbclid`
 * presente nell'URL, senza toccare cookie o storage. Stessa regola già applicata
 * a `fst_uid` da fst_get_uid().
 *
 * Il cookie NON è httponly: deve restare leggibile dal Pixel e dallo script che
 * compila i campi hidden dei form. $_COOKIE viene aggiornato solo quando il
 * cookie viene davvero inviato, così rispecchia sempre lo stato del browser.
 *
 * @param string $fbc_value Valore _fbc da persistere.
 * @return bool True se il cookie è stato inviato.
 * @since 0.11.0
 */
function fst_persist_fbc_cookie( $fbc_value ) {
    $fbc_value = trim( (string) $fbc_value );
    if ( '' === $fbc_value ) {
        return false;
    }

    // GDPR: nessun cookie senza consenso marketing, in nessun caso.
    if ( ! ati_has_marketing_consent() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[FST] Cookie _fbc NON impostato: consenso marketing assente' );
        }
        return false;
    }

    if ( headers_sent() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[FST] Cookie _fbc non impostato: header gia inviati' );
        }
        return false;
    }

    setcookie( '_fbc', $fbc_value, time() + 7776000, '/', '', false, false ); // 90 giorni.
    $_COOKIE['_fbc'] = $fbc_value;
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[FST] Cookie _fbc impostato' );
    }
    return true;
}

/**
 * Cattura il fbclid dall'URL e persiste _fbc PRIMA che la pagina venga generata.
 *
 * Senza questo hook il cookie nasceva solo al ritorno della chiamata AJAX/REST di
 * tracciamento: i campi hidden dei form compilati prima di quel momento restavano
 * senza `fbc`, e il valore poteva differire da quello inviato alla CAPI.
 * Agganciato a `template_redirect`: gli header non sono ancora stati inviati,
 * quindi il cookie è già disponibile al primo byte di HTML.
 *
 * La scrittura avviene solo con consenso marketing (vedi fst_persist_fbc_cookie()).
 * Senza consenso il campo `fbc` del form viene comunque compilato, ricostruito
 * lato client dal `fbclid` dell'URL: nessuno storage coinvolto.
 *
 * @return void
 * @since 0.11.0
 */
function fst_capture_fbclid_from_url() {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        return;
    }
    // Cookie già presente (Pixel o visita precedente): è la fonte di verità.
    if ( isset( $_COOKIE['_fbc'] ) && '' !== trim( (string) $_COOKIE['_fbc'] ) ) {
        return;
    }

    $fbclid = isset( $_GET['fbclid'] ) ? sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    if ( '' === $fbclid ) {
        return;
    }

    fst_persist_fbc_cookie( fst_build_fbc_from_fbclid( $fbclid ) );
}
add_action( 'template_redirect', 'fst_capture_fbclid_from_url' );

/**
 * Genera o recupera pseudonimo utente persistente
 *
 * Crea un identificatore unico per ogni visitatore che persiste tra le sessioni
 * ma rimane anonimo. Utilizza:
 * 
 * - Cookie 'fst_uid' con durata 2 anni
 * - UUID4 generato da WordPress (sicuro e univoco)  
 * - Non traccia dati personali, solo un codice casuale
 * 
 * Questo permette di:
 * - Collegare eventi della stessa persona
 * - Migliorare l'attribuzione Facebook
 * - Rispettare la privacy (nessun dato identificabile)
 * 
 * @return string Pseudonimo utente (es: "a1b2c3d4-e5f6-7890-abcd-ef1234567890")
 * @since 1.1
 */
function fst_get_uid() {
    $cookie = 'fst_uid';
    
    // Se il cookie esiste già, restituisce il valore esistente
    if ( isset( $_COOKIE[ $cookie ] ) ) {
        return sanitize_text_field( $_COOKIE[ $cookie ] );
    }
    
    // Senza consenso marketing non creiamo né persistiamo alcun identificatore (GDPR).
    // tag-inserter.php è caricato prima di server-tracking.php in plugin.php,
    // quindi ati_has_marketing_consent() è sempre disponibile.
    if ( ! ati_has_marketing_consent() ) {
        return '';
    }

    // Genera nuovo UUID4 unico e lo salva nel cookie per 2 anni
    $uid = wp_generate_uuid4();
    setcookie( $cookie, $uid, time() + 63072000, COOKIEPATH, COOKIE_DOMAIN );
    return $uid;
}

// ========================================
// SEZIONE 4: FUNZIONI DI INVIO DATI
// ========================================

/**
 * Invia eventi al webhook n8n con debug dettagliato
 * 
 * n8n è una piattaforma di automazione che può ricevere gli eventi
 * e inoltrarli a Facebook Conversions API, CRM, database, etc.
 * 
 * Processo:
 * 1. Verifica che l'endpoint sia configurato
 * 2. Aggiunge autenticazione (se configurata)
 * 3. Codifica il payload in JSON
 * 4. Invia richiesta POST con timeout 5 secondi
 * 5. Logga tutto in dettaglio per debugging
 * 
 * Configurazione richiesta (nelle impostazioni WordPress):
 * - ati_server_endpoint: URL del webhook n8n
 * - ati_server_auth_key: Nome header autenticazione (opzionale)
 * - ati_server_auth_value: Valore header autenticazione (opzionale)
 * - ati_meta_dataset_id: Pixel/Dataset ID Meta incluso nel payload (opzionale,
 *   fallback su ati_fb_pixel_id)
 * - ati_meta_capi_token: Access token Meta CAPI incluso nel payload (opzionale)
 *
 * @param array $payload Dati evento formattati per Facebook API
 * @return void
 * @since 1.1
 */
function fst_send_to_n8n( array $payload ) {
    // STEP 1: Verifica che l'endpoint n8n sia configurato
    $endpoint = trim( get_option( 'ati_server_endpoint', '' ) );
    if ( ! $endpoint ) {
        return; // Se non configurato, esce silenziosamente
    }

    // STEP 1b: Credenziali Meta CAPI opzionali. Se configurate viaggiano nel
    // payload (accanto a "data", stesso formato del body della Graph API):
    // il workflow n8n le legge dalla richiesta invece di tenerle hardcodate.
    $dataset_id = trim( (string) get_option( 'ati_meta_dataset_id', '' ) );
    if ( '' === $dataset_id ) {
        $dataset_id = trim( (string) get_option( 'ati_fb_pixel_id', '' ) ); // Fallback: pixel client-side.
    }
    $capi_token = trim( (string) get_option( 'ati_meta_capi_token', '' ) );
    if ( '' !== $dataset_id ) {
        $payload['pixel_id'] = $dataset_id;
    }
    if ( '' !== $capi_token ) {
        $payload['access_token'] = $capi_token;
    }

    // STEP 2: Configura autenticazione (se necessaria)
    $auth_key = trim( get_option( 'ati_server_auth_key', '' ) );   // Nome header (es: "X-API-Key")
    $auth_val = trim( get_option( 'ati_server_auth_value', '' ) ); // Valore header (es: "abc123")
    $headers  = [ 'Content-Type' => 'application/json' ];
    
    // Aggiunge header di autenticazione se configurato
    if ( $auth_key && $auth_val ) {
        $headers[ $auth_key ] = $auth_val;
    }
    
    // STEP 3: Codifica il payload in JSON
    $body = wp_json_encode( $payload );
    
    // STEP 4: Log dettagliato per debugging (solo se WP_DEBUG è attivo)
    if ( WP_DEBUG ) {
        error_log( '[FST] Invio a n8n - ' . $payload['data'][0]['event_name'] . ' ID: ' . $payload['data'][0]['event_id'] );
        // PII-safe: endpoint e payload completi (che contengono user_data) non vengono loggati.
    }
    
    // STEP 5: Invia richiesta POST al webhook n8n
    $response = wp_remote_post( $endpoint, [
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 5, // Timeout 5 secondi per evitare blocchi
    ] );
    
    // STEP 6: Gestione errori di connessione
    if ( is_wp_error( $response ) ) {
        error_log( '[FST] ✖ WP_Error n8n: ' . $response->get_error_message() );
        return;
    }
    
    // STEP 7: Analizza la risposta del server
    $code = wp_remote_retrieve_response_code( $response );
    $res_body = wp_remote_retrieve_body( $response );
    
    // Log della risposta per monitoring
    if ( WP_DEBUG ) {
        error_log( '[FST] Risposta n8n: HTTP ' . $code );
    }
    
    // Logga errori HTTP (se diverso da 200 OK)
    if ( $code !== 200 ) {
        error_log( '[FST] ERRORE n8n: HTTP ' . $code );
    }
}

/**
 * Invia eventi a Google Analytics 4 via Measurement Protocol
 * 
 * Questa funzione è opzionale e invia gli stessi eventi anche a GA4
 * oltre che a Facebook. Utilizza il Measurement Protocol di GA4 che
 * permette di inviare eventi direttamente dal server.
 * 
 * Vantaggi del server-side GA4:
 * - Eventi non bloccabili da adblocker
 * - Maggiore precisione dei dati
 * - Supporta eventi offline/delayed
 * 
 * Configurazione richiesta:
 * - ati_ga4_id: ID Google Analytics (es: "G-XXXXXXXXXX")
 * - ati_ga4_api_secret: Secret per Measurement Protocol
 * - ati_enable_ga4_server: deve essere "1" per abilitare
 * 
 * NOTA (server-side first): questa funzione è il percorso GA4 LEGACY, usato solo
 * per il PageView server-side avanzato e per gli eventi non-lead quando il vecchio
 * invio è attivo. Le conversioni confermate (generate_lead) passano invece dalla
 * nuova pipeline (ati_track_confirmed_event -> coda -> ATI_GA4_Adapter).
 *
 * Il client_id/session_id sono quelli REALI del Google Tag (cookie _ga/_ga_*),
 * NON viene più generato un UUID casuale per richiesta. Include ora anche
 * timestamp_micros, session_id ed engagement_time_msec.
 *
 * @param string $eventName Nome evento GA4 (es: "page_view", "button_click")
 * @param array  $params    Parametri personalizzati dell'evento
 * @param string $page_url  URL pagina sorgente (opzionale)
 * @return void
 * @since 1.1
 */
function fst_send_to_ga4( string $eventName, array $params = [], string $page_url = '' ) {
    // Richiede il sottosistema GA4 (centralizza config, endpoint, client context).
    if ( ! class_exists( 'ATI_GA4_Config' ) || ! class_exists( 'ATI_GA4_Adapter' ) ) {
        return;
    }
    if ( ! ATI_GA4_Config::is_ready() ) {
        return; // Measurement ID + API secret non configurati.
    }

    // client_id/session_id reali dal Google Tag (cookie first-party). Nessun UUID casuale.
    $ctx = ATI_GA4_Client_Context::from_cookies();
    if ( '' === $ctx['client_id'] ) {
        // Attribuzione degradata esplicita: senza client_id reale non si inventa un ID.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[FST] GA4 legacy: client_id reale assente, evento non inviato (' . sanitize_key( $eventName ) . ')' );
        }
        return;
    }

    $event = ATI_Event::from_array( array(
        'event_name'           => $eventName,
        'event_timestamp_micros' => (int) round( microtime( true ) * 1000000 ),
        'client_id'            => $ctx['client_id'],
        'session_id'           => $ctx['session_id'],
        'engagement_time_msec' => 1,
        'page_location'        => $page_url,
        'source'               => 'server_pageview',
        'params'               => $params,
    ) );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[FST] GA4 legacy invio: ' . sanitize_key( $eventName ) );
    }

    ATI_GA4_Adapter::send( $event );
}
?>