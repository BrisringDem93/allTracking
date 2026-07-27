<?php
/**
 * Bootstrap del sottosistema GA4 server-side.
 *
 * Carica i componenti, esegue la migrazione, registra cron, provider form,
 * endpoint REST, admin e il bridge client-side.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Protezione anti doppio-caricamento: se una copia duplicata del plugin include di
// nuovo il bootstrap, si evita il fatal da ridichiarazione di classi/funzioni GA4.
if ( defined( 'ATI_GA4_BOOTSTRAP_LOADED' ) ) {
	return;
}
define( 'ATI_GA4_BOOTSTRAP_LOADED', 1 );

$ati_ga4_dir = __DIR__ . '/';

require_once $ati_ga4_dir . 'class-ati-event.php';
require_once $ati_ga4_dir . 'class-ati-consent-service.php';
require_once $ati_ga4_dir . 'class-ati-ga4-config.php';
require_once $ati_ga4_dir . 'class-ati-ga4-endpoints.php';
require_once $ati_ga4_dir . 'class-ati-project-classification.php';
require_once $ati_ga4_dir . 'class-ati-ga4-client-context.php';
require_once $ati_ga4_dir . 'class-ati-event-normalizer.php';
require_once $ati_ga4_dir . 'class-ati-ga4-adapter.php';
require_once $ati_ga4_dir . 'class-ati-event-deduplicator.php';
require_once $ati_ga4_dir . 'class-ati-event-queue.php';
require_once $ati_ga4_dir . 'class-ati-form-provider.php';
require_once $ati_ga4_dir . 'class-ati-ga4-rest.php';
require_once $ati_ga4_dir . 'class-ati-ga4-migration.php';
require_once $ati_ga4_dir . 'functions.php';

if ( is_admin() ) {
	require_once $ati_ga4_dir . 'class-ati-ga4-admin.php';
}

/**
 * Inizializzazione principale.
 */
function ati_ga4_bootstrap() {
	// Migrazione idempotente.
	ATI_GA4_Migration::maybe_migrate();

	// Provider form (registra solo quelli disponibili con hook ufficiali).
	ATI_Form_Provider_Registry::boot();

	// Admin.
	if ( is_admin() && class_exists( 'ATI_GA4_Admin' ) ) {
		ATI_GA4_Admin::boot();
	}
}
add_action( 'plugins_loaded', 'ati_ga4_bootstrap', 20 );

/**
 * Hook pubblico: converte un lead confermato in evento GA4.
 *
 * @param string $provider Provider.
 * @param string $form_id  Form id.
 * @param array  $params   Parametri (no PII).
 * @param array  $context  Contesto.
 */
function ati_ga4_on_confirmed_lead( $provider, $form_id, $params = array(), $context = array() ) {
	$context = is_array( $context ) ? $context : array();
	$params  = is_array( $params ) ? $params : array();
	$context['provider'] = $provider;
	$context['form_id']  = $form_id;
	ati_track_confirmed_event( 'generate_lead', $params, $context );
}
add_action( 'ati_confirmed_lead', 'ati_ga4_on_confirmed_lead', 10, 4 );

// REST.
add_action(
	'rest_api_init',
	function () {
		ATI_GA4_REST::register_routes();
	}
);

// Worker coda (WP-Cron / Action Scheduler).
add_action( ATI_Event_Queue::CRON_HOOK, array( 'ATI_Event_Queue', 'process' ) );

// Fallback ricorrente WP-Cron (senza dipendere da Action Scheduler).
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'ati_ga4_cron_tick' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'ati_ga4_cron_tick' );
		}
	}
);
add_action( 'ati_ga4_cron_tick', array( 'ATI_Event_Queue', 'process' ) );

/**
 * Enqueue del bridge client-side quando la pipeline confermata è attiva.
 *
 * @return void
 */
function ati_ga4_enqueue_bridge() {
	if ( ! ATI_GA4_Config::confirmed_enabled() ) {
		return;
	}
	if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
		return;
	}

	$handle = 'ati-ga4-bridge';
	// __DIR__ = includes/ga4 ; la root del plugin è due livelli sopra.
	$src    = plugins_url( 'assets/js/ga4-bridge.js', dirname( __DIR__, 2 ) . '/plugin.php' );

	// Versione = versione plugin: forza il cache-busting del bridge a ogni aggiornamento.
	$asset_ver = defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '1.0.0';
	wp_register_script( $handle, $src, array(), $asset_ver, true );

	$config = array(
		'endpoint'      => rest_url( ATI_GA4_REST::NAMESPACE . ATI_GA4_REST::ROUTE ),
		'token'         => ATI_GA4_REST::issue_token(),
		'measurementId' => ATI_GA4_Config::measurement_id(),
		'enabled'       => true,
		'submitAttempt' => ( '1' === get_option( 'ati_ga4_enable_submit_attempt', '0' ) ),
		// Modalità "submit": il bridge invia generate_lead all'invio di un qualsiasi form.
		'leadOnSubmit'  => ( 'submit' === ATI_GA4_Config::lead_trigger() ),
		// Evento JS personalizzato per i form custom (invio riuscito).
		'customSuccessEvent' => ATI_GA4_Config::custom_success_event(),
		'debug'         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		// Versione del PHP in esecuzione: visibile in "Visualizza sorgente" per
		// verificare che il sito NON stia servendo codice vecchio dalla cache.
		'v'             => defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '',
	);

	wp_add_inline_script(
		$handle,
		'window.atiGa4Bridge = ' . wp_json_encode( $config ) . ';',
		'before'
	);
	wp_enqueue_script( $handle );
}
add_action( 'wp_enqueue_scripts', 'ati_ga4_enqueue_bridge' );
