<?php
/**
 * server-tracking.php
 * Tracciamento server-side per Meta Pixel (CAPI) e GA4 (Measurement Protocol)
 * Eventi: PageView, ButtonClick, FormStart, FormSubmit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. PAGEVIEW – ricevuto dal client via REST usa stesso eventID per deduplicazione
// Rimuoviamo il template_redirect diretto per PageView
// Ora la PageView viene inviata dal client JS come REST call a fst/v1/event

    // Genera o recupera eventID univoco e imposta cookie
    $event_id = fst_get_event_id();

    $event = [
        'event_name'       => 'PageView',
        'event_time'       => time(),
        'event_source_url' => home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ) ),
        'action_source'    => 'website',
        'event_id'         => $event_id,
        'user_data'        => fst_build_user_data(),
    ];

    if ( WP_DEBUG ) {
        error_log( '[FST] PageView event: ' . wp_json_encode( $event ) );
    }

    fst_send_to_n8n( [ 'data' => [ $event ] ] );
    if ( get_option( 'ati_enable_ga4_server' ) === '1' ) {
        fst_send_to_ga4( 'page_view', [] );
    }
}

// 2. REST API per eventi custom dal browser (ButtonClick, FormStart, FormSubmit)
add_action( 'rest_api_init', function() {
    register_rest_route( 'fst/v1', '/event', [
        'methods'             => 'POST',
        'callback'            => 'fst_rest_event_handler',
        'permission_callback' => '__return_true',
    ] );
} );
function fst_rest_event_handler( WP_REST_Request $req ) {
    $type    = sanitize_text_field( $req->get_param( 'type' ) );
    $label   = sanitize_text_field( $req->get_param( 'label' ) );
    $page    = esc_url_raw( $req->get_param( 'page' ) );
    // Usa eventID dal client se presente, altrimenti cookie/fallback
    $event_id = $req->get_param( 'eventID' )
        ? sanitize_text_field( $req->get_param( 'eventID' ) )
        : fst_get_event_id();

    $event = [
        'event_name'       => $type,
        'event_time'       => time(),
        'event_source_url' => $page,
        'action_source'    => 'website',
        'event_id'         => $event_id,
        'custom_data'      => [ 'label' => $label ],
        'user_data'        => fst_build_user_data(),
    ];

    // Aggiungi hash dati sensibili su FormSubmit
    if ( $type === 'FormSubmit' ) {
        if ( $email = $req->get_param( 'email' ) ) {
            $event['user_data']['em'] = hash( 'sha256', strtolower( trim( $email ) ) );
        }
        if ( $phone = $req->get_param( 'phone' ) ) {
            $clean = preg_replace( '/\D+/', '', $phone );
            $event['user_data']['ph'] = hash( 'sha256', $clean );
        }
    }

    if ( WP_DEBUG ) {
        error_log( '[FST] REST event: ' . wp_json_encode( $event ) );
    }

    fst_send_to_n8n( [ 'data' => [ $event ] ] );
    if ( get_option( 'ati_enable_ga4_server' ) === '1' ) {
        fst_send_to_ga4( strtolower( $type ), [ 'label' => $label ] );
    }

    return rest_ensure_response( [ 'status' => 'ok', 'event_id' => $event_id ] );
}

// 3. Costruisce user_data con pseudonimo interno e cookie Meta
function fst_build_user_data() {
    return [
        'external_id'       => fst_get_uid(),
        'fbp'               => isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( $_COOKIE['_fbp'] ) : null,
        'fbc'               => isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( $_COOKIE['_fbc'] ) : null,
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
}

// 4a. Genera o recupera pseudonimo utente persistente
function fst_get_uid() {
    $cookie = 'fst_uid';
    if ( isset( $_COOKIE[ $cookie ] ) ) {
        return sanitize_text_field( $_COOKIE[ $cookie ] );
    }
    $uid = wp_generate_uuid4();
    setcookie( $cookie, $uid, time() + 63072000, COOKIEPATH, COOKIE_DOMAIN );
    return $uid;
}
// 4b. Recupera o genera eventID persistente per deduplicazione
function fst_get_event_id() {
    $cookie = 'fst_ev_id';
    if ( isset( $_COOKIE[ $cookie ] ) ) {
        return sanitize_text_field( $_COOKIE[ $cookie ] );
    }
    $eid = 'evt_' . time() . '_' . wp_generate_uuid4();
    setcookie( $cookie, $eid, time() + 3600, COOKIEPATH, COOKIE_DOMAIN );
    return $eid;
}

// 5. Invio a n8n con debug dettagliato
function fst_send_to_n8n( array $payload ) {
    $endpoint = trim( get_option( 'ati_server_endpoint', '' ) );
    if ( ! $endpoint ) {
        return;
    }
    $auth_key = trim( get_option( 'ati_server_auth_key', '' ) );
    $auth_val = trim( get_option( 'ati_server_auth_value', '' ) );
    $headers  = [ 'Content-Type' => 'application/json' ];
    if ( $auth_key && $auth_val ) {
        $headers[ $auth_key ] = $auth_val;
    }
    $body = wp_json_encode( $payload );
    if ( WP_DEBUG ) {
        error_log( '[FST] ► Invio a n8n – DETTAGLI' );
        error_log( '[FST]   Endpoint : ' . $endpoint );
        error_log( '[FST]   Headers  : ' . print_r( $headers, true ) );
        error_log( '[FST]   Payload  : ' . $body );
    }
    $response = wp_remote_post( $endpoint, [
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 5,
    ] );
    if ( is_wp_error( $response ) ) {
        error_log( '[FST] ✖ WP_Error n8n: ' . $response->get_error_message() );
        return;
    }
    $code       = wp_remote_retrieve_response_code( $response );
    $res_body   = wp_remote_retrieve_body( $response );
    $res_headers= wp_remote_retrieve_headers( $response );
    if ( WP_DEBUG ) {
        error_log( '[FST] ◄ Risposta n8n – codice ' . $code );
        error_log( '[FST]   Response headers: ' . print_r( $res_headers, true ) );
        error_log( '[FST]   Response body   : ' . $res_body );
    }
    if ( $code !== 200 ) {
        error_log( '[FST] ERRORE n8n: HTTP ' . $code );
    }
}

// 6. Invio a GA4 via Measurement Protocol (opzionale)
function fst_send_to_ga4( string $eventName, array $params = [] ) {
    $measurement_id = trim( get_option( 'ati_ga4_id', '' ) );
    $api_secret     = trim( get_option( 'ati_ga4_api_secret', '' ) );
    if ( empty( $measurement_id ) || empty( $api_secret ) ) {
        return;
    }
    $endpoint = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode( $measurement_id ) . '&api_secret=' . rawurlencode( $api_secret );
    $body = [
        'client_id' => fst_get_uid(),
        'events'    => [ [ 'name' => $eventName, 'params' => $params ] ],
    ];
    if ( WP_DEBUG ) {
        error_log( '[FST] ▶️ Inviato a GA4: ' . wp_json_encode( $body ) );
    }
    wp_remote_post( $endpoint, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 5,
    ] );
}
