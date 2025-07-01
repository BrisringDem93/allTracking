<?php
/**
 * Tracciamento server-side per Pixel e GA4 (Measurement Protocol)
 * Eventi: PageView, ButtonClick, FormStart, FormSubmit
 * File name: server-tracking.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. PAGEVIEW – spedito dal server ad ogni page load
add_action( 'template_redirect', 'fst_track_pageview', 1 );
function fst_track_pageview() {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }

    $event = [
        'event_name'        => 'PageView',
        'event_time'        => time(),
        'event_source_url'  => home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ) ),
        'action_source'     => 'website',
        'event_id'          => uniqid( 'pv_', true ),
        'user_data'         => [
            'client_user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'client_ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
    ];

    fst_send_to_n8n( $event );

    if ( get_option( 'ati_enable_ga4_server' ) === '1' ) {
        fst_send_to_ga4( 'page_view', [] );
    }
}

// 2. REST API – per eventi JS (button click, form start, form submit)
add_action( 'rest_api_init', function () {
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
    $eventID = uniqid( $type . '_', true );

    $event = [
        'event_name'       => $type,
        'event_time'       => time(),
        'event_source_url' => $page,
        'action_source'    => 'website',
        'event_id'         => $eventID,
        'custom_data'      => [ 'label' => $label ],
        'user_data'        => [
            'client_user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'client_ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
    ];

    fst_send_to_n8n( $event );

    if ( get_option( 'ati_enable_ga4_server' ) === '1' ) {
        fst_send_to_ga4( strtolower( $type ), [ 'label' => $label ] );
    }

    return rest_ensure_response( [ 'status' => 'ok', 'event_id' => $eventID ] );
}

// 3. Invio a n8n
function fst_send_to_n8n( array $payload ) {
    $endpoint = get_option( 'ati_server_endpoint', '' );
    if ( empty( $endpoint ) ) return;

    $headers = [ 'Content-Type' => 'application/json' ];
    $auth_key = get_option( 'ati_server_auth_key', '' );
    $auth_val = get_option( 'ati_server_auth_value', '' );

    if ( $auth_key && $auth_val ) {
        $headers[ $auth_key ] = $auth_val;
    }

    $res = wp_remote_post( $endpoint, [
        'headers' => $headers,
        'body'    => wp_json_encode( $payload ),
        'timeout' => 5,
    ] );

    if ( is_wp_error( $res ) ) {
        error_log( '[FST] Errore invio tracking: ' . $res->get_error_message() );
    }
}

// 4. Invio a GA4 server-side via Measurement Protocol
function fst_send_to_ga4( string $eventName, array $params = [] ) {
    $measurement_id = trim( get_option( 'ati_ga4_id', '' ) );
    $api_secret     = trim( get_option( 'ati_ga4_api_secret', '' ) );

    if ( empty( $measurement_id ) || empty( $api_secret ) ) {
        return;
    }

    $endpoint = 'https://www.google-analytics.com/mp/collect?measurement_id=' .
                 rawurlencode( $measurement_id ) . '&api_secret=' . rawurlencode( $api_secret );

    $body = [
        'client_id' => fst_get_client_id(),
        'events'    => [
            [
                'name'   => $eventName,
                'params' => $params,
            ]
        ]
    ];

    wp_remote_post( $endpoint, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 5,
    ] );
}

// 5. Gestione client_id per GA4 server-side
function fst_get_client_id() {
    $cookie = 'fst_ga4_id';
    if ( isset( $_COOKIE[ $cookie ] ) ) {
        return sanitize_text_field( $_COOKIE[ $cookie ] );
    }

    $new = uniqid() . '.' . time();
    setcookie( $cookie, $new, time() + 63072000, COOKIEPATH, COOKIE_DOMAIN );
    return $new;
}