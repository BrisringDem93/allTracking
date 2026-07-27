<?php
/**
 * Plugin Name: Quick Tracking Integration
 * Description: Inserisce automaticamente Facebook Pixel, Google Analytics 4 e Google Tag Manager con una semplice configurazione. GA4 "server-side first" per le conversioni confermate via Measurement Protocol.
 * Version: 0.9.0
 * Author: Francesco de Minicis
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Versione coerente disponibile a runtime.
if ( ! defined( 'ATI_PLUGIN_VERSION' ) ) {
    define( 'ATI_PLUGIN_VERSION', '0.9.0' );
}

// Include plugin files
require_once plugin_dir_path( __FILE__ ) . 'includes/tag-inserter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings-page.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/server-tracking.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/db_cookies.php';

// Sottosistema GA4 server-side (modello evento neutrale, consenso, coda, MP adapter).
require_once plugin_dir_path( __FILE__ ) . 'includes/ga4/bootstrap.php';

// Hook for creating the database table on plugin activation
register_activation_hook( __FILE__, 'fst_create_cookie_table' );

// Attivazione: crea la tabella coda GA4 ed esegue la migrazione idempotente.
register_activation_hook(
    __FILE__,
    function () {
        if ( class_exists( 'ATI_GA4_Migration' ) ) {
            ATI_GA4_Migration::on_activate();
        }
    }
);

// Disattivazione: rimuove gli eventi cron del worker coda.
register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'ati_ga4_cron_tick' );
        wp_clear_scheduled_hook( 'ati_ga4_process_queue' );
    }
);
