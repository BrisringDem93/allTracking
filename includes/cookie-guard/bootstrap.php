<?php
/**
 * Bootstrap del sottosistema "Blocco cookie".
 *
 * Modulo indipendente: se disattivato (modalità `off`, che è il default) non
 * registra output sul front-end e non modifica in alcun modo il comportamento
 * esistente del plugin.
 *
 * @package QuickTrackingIntegration\CookieGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ati_cg_dir = __DIR__ . '/';

require_once $ati_cg_dir . 'class-ati-cookie-rules.php';
require_once $ati_cg_dir . 'class-ati-cookie-consent.php';
require_once $ati_cg_dir . 'class-ati-cookie-guard.php';

if ( is_admin() ) {
	require_once $ati_cg_dir . 'class-ati-cookie-guard-admin.php';
}

/**
 * Inizializzazione del blocco cookie.
 *
 * @return void
 */
function ati_cookie_guard_bootstrap() {
	if ( is_admin() && class_exists( 'ATI_Cookie_Guard_Admin' ) ) {
		ATI_Cookie_Guard_Admin::boot();
	}

	// Il guard va stampato il prima possibile dentro <head>: priorità 0, quindi
	// prima del container GTM (priorità 1) e di ogni script accodato.
	add_action( 'wp_head', array( 'ATI_Cookie_Guard', 'print_guard' ), 0 );

	// Pulizia server-side (opt-in): prima di qualunque output.
	add_action( 'init', array( 'ATI_Cookie_Guard', 'server_cleanup' ), 1 );
}
add_action( 'plugins_loaded', 'ati_cookie_guard_bootstrap', 21 );
