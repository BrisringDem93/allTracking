<?php
/**
 * Bootstrap del widget di debug del front-end.
 *
 * Modulo di sola diagnostica: gli hook vengono registrati sempre, ma non producono
 * alcun output se il widget non è visibile (serve un utente loggato con i permessi
 * di amministrazione e `WP_DEBUG` attivo). Per i visitatori il modulo è inerte.
 *
 * @package QuickTrackingIntegration\DebugBar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ati-debug-expectations.php';
require_once __DIR__ . '/class-ati-debug-bar.php';

/**
 * Inizializzazione del widget di debug.
 *
 * Priorità 22: dopo il sottosistema GA4 (20) e il blocco cookie (21), così le
 * classi interrogate dalla fotografia diagnostica sono già disponibili.
 *
 * @return void
 */
function ati_debug_bar_bootstrap() {
	if ( is_admin() ) {
		return;
	}
	ATI_Debug_Bar::boot();
}
add_action( 'plugins_loaded', 'ati_debug_bar_bootstrap', 22 );
