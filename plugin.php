<?php
/**
 * Plugin Name: Quick Tracking Integration
 * Description: Inserisce automaticamente Facebook Pixel, Google Analytics 4 e Google Tag Manager con una semplice configurazione.
 * Version: 1.1
 * Author: Francesco de Minicis
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include plugin files
require_once plugin_dir_path( __FILE__ ) . 'includes/tag-inserter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings-page.php';
