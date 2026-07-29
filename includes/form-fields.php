<?php
/**
 * Compilazione automatica dei campi hidden nei form.
 *
 * Registra lo script front-end che, se un form contiene input hidden con nomi noti
 * (fbclid, gclid, fbc, fbp, utm_*, external_id, ...), li compila con i valori
 * realmente disponibili (URL, cookie _fbc/_fbp/_gcl_aw/fst_uid, persistenza di
 * sessione). Nessun identificatore viene inventato.
 *
 * @package QuickTrackingIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * La compilazione dei campi hidden è attiva?
 *
 * Attiva di default; l'amministratore può disattivarla dal tab "Generale".
 *
 * @return bool
 */
function ati_form_fields_enabled() {
    return '1' === (string) get_option( 'ati_enable_form_fields', '1' );
}

/**
 * Enqueue dello script di compilazione dei campi hidden.
 *
 * @return void
 */
function ati_form_fields_enqueue() {
    if ( ! ati_form_fields_enabled() ) {
        return;
    }
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        return;
    }

    $handle = 'ati-form-fields';
    // __DIR__ = includes ; la root del plugin è un livello sopra.
    $src = plugins_url( 'assets/js/form-fields.js', dirname( __DIR__ ) . '/plugin.php' );

    // Versione = versione plugin: cache-busting a ogni aggiornamento.
    $asset_ver = defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '1.0.0';
    wp_register_script( $handle, $src, array(), $asset_ver, true );

    $config = array(
        'enabled' => true,
        'debug'   => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
        // Evento JS del consenso custom: dopo l'accettazione i cookie _fbp/_fbc
        // compaiono e i campi vanno ricompilati.
        'consentEvent' => trim( (string) get_option( 'ati_consent_custom_event', '' ) ),
        'v'       => $asset_ver,
    );

    wp_add_inline_script(
        $handle,
        'window.atiFormFields = ' . wp_json_encode( $config ) . ';',
        'before'
    );
    wp_enqueue_script( $handle );
}
add_action( 'wp_enqueue_scripts', 'ati_form_fields_enqueue' );
