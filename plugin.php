<?php
/**
 * Plugin Name: All  Event Tracker
 * Description: Traccia gli eventi del sito e li invia a Facebook Graph API.
 * Version: 0.1
 * Author: Francesco de Minicis
 */

// Sicurezza
if (!defined('ABSPATH')) {
    exit;
}

// Includi la logica del plugin
require_once plugin_dir_path(__FILE__) . 'fb-event-tracker/fb-event-tracker.php';
