<?php
/**
 * Plugin Name: Quick Tracking Integration
 * Description: Inserisce automaticamente Facebook Pixel, Google Analytics 4 e Google Tag Manager con una semplice configurazione.
 * Version: 0.7.5
 * Author: Francesco de Minicis
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include plugin files
require_once plugin_dir_path( __FILE__ ) . 'includes/tag-inserter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings-page.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/server-tracking.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/db_cookies.php';

// Hook for creating the database table on plugin activation
register_activation_hook( __FILE__, 'fst_create_cookie_table' );
