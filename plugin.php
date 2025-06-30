<?php
/**
 * Plugin Name: All  Event Tracker
 * Description: Traccia gli eventi del sito e li invia a Facebook Graph API e integra Google Analytics 4 e Google Tag Manager.
 * Version: 0.2
 * Author: Francesco de Minicis
 */

// Sicurezza
if (!defined('ABSPATH')) {
    exit;
}

// Includi la logica del plugin
require_once plugin_dir_path(__FILE__) . 'fb-event-tracker/fb-event-tracker.php';
