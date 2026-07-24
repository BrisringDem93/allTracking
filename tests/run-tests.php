<?php
/**
 * Test runner delle FUNZIONI PURE del sottosistema GA4.
 *
 * Esegui: php tests/run-tests.php
 *
 * Copre: modello evento, normalizer (allowlist + rimozione PII), classificazione
 * (priorità), rilevamento consenso analytics (distinto dal marketing), endpoint
 * EU/Global, payload GA4 (client_id/session_id/timestamp/engagement/params/event_id),
 * chiave di deduplica, sanitizzazione secret (non cancella se vuoto).
 *
 * @package QuickTrackingIntegration\Tests
 */

require __DIR__ . '/wp-stubs.php';

$base = dirname( __DIR__ ) . '/includes/ga4/';
require $base . 'class-ati-event.php';
require $base . 'class-ati-ga4-endpoints.php';
require $base . 'class-ati-project-classification.php';
require $base . 'class-ati-ga4-config.php';
require $base . 'class-ati-consent-service.php';
require $base . 'class-ati-ga4-client-context.php';
require $base . 'class-ati-event-normalizer.php';
require $base . 'class-ati-ga4-adapter.php';
require $base . 'class-ati-event-queue.php';
require $base . 'class-ati-event-deduplicator.php';
require $base . 'functions.php';
require $base . 'class-ati-ga4-admin.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  $msg\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  $msg\n";
	}
}
function section( $t ) {
	echo "\n== $t ==\n";
}

// -------------------------------------------------------------------------
section( 'Normalizer: allowlist parametri + rimozione PII (Test 8, 15)' );
$_COOKIE = array();
$event = ATI_Event_Normalizer::normalize(
	'generate_lead',
	array(
		'business_area' => 'Acquisizione Immobili',
		'service_type'  => 'nuda_proprieta',
		'email'         => 'mario.rossi@example.com',
		'phone'         => '+39 333 1234567',
		'message'       => 'testo libero riservato',
		'random_param'  => 'x',
	),
	array(
		'client_id'  => '1234567890.1234567890',
		'session_id' => '1699999999',
		'form_id'    => 'contatti',
		'provider'   => 'elementor',
	)
);
$p = $event->params;
ok( ! isset( $p['email'] ) && ! isset( $p['phone'] ) && ! isset( $p['message'] ), 'PII (email/phone/message) rimosse dai parametri' );
ok( ! isset( $p['random_param'] ), 'Parametro fuori allowlist scartato' );
ok( isset( $p['business_area'] ) && 'acquisizione_immobili' === $p['business_area'], 'business_area slugificato' );
ok( isset( $p['service_type'] ) && 'nuda_proprieta' === $p['service_type'], 'service_type mantenuto' );
ok( 'generate_lead' === $event->event_name, 'event_name = generate_lead' );
$json = wp_json_encode( $event->to_array() );
ok( false === strpos( $json, 'mario.rossi' ) && false === strpos( $json, '3331234567' ), 'Nessuna PII nella serializzazione evento (coda)' );

// -------------------------------------------------------------------------
section( 'Classificazione: priorità mapping > globale > fallback (Test 9)' );
$GLOBALS['__ati_opts']['ati_class_business_area'] = 'servizi_b2b';
$GLOBALS['__ati_opts']['ati_class_service_type']  = 'consulenza';
$GLOBALS['__ati_opts']['ati_ga4_form_map']        = array(
	array(
		'provider'     => 'elementor',
		'form_id'      => 'lead-nuda',
		'service_type' => 'nuda_proprieta',
		'site_section' => 'landing_nuda_proprieta',
		'enabled'      => 1,
	),
);
$res = ATI_Project_Classification::resolve( 'elementor', 'lead-nuda' );
ok( 'nuda_proprieta' === $res['service_type'], 'Mapping form vince sulla globale (service_type)' );
ok( 'servizi_b2b' === $res['business_area'], 'Globale usata quando manca nel mapping (business_area)' );
ok( 'not_set' === $res['audience_type'], 'Fallback documentato quando nessuna fonte (audience_type)' );
$res2 = ATI_Project_Classification::resolve( 'elementor', 'form-non-mappato' );
ok( 'consulenza' === $res2['service_type'], 'Form non mappato usa la globale' );
ok( 'not_set' === ATI_Project_Classification::sanitize_slug( '' ), 'sanitize_slug vuoto -> not_set' );
ok( strlen( ATI_Project_Classification::sanitize_slug( str_repeat( 'a', 300 ) ) ) <= 100, 'sanitize_slug rispetta lunghezza GA4' );

// -------------------------------------------------------------------------
section( 'Consenso analytics distinto dal marketing (Test 2, 3)' );
$reset_opts = function () {
	$GLOBALS['__ati_opts']['ati_analytics_cookie_name'] = '';
};
$reset_opts();
$detect = new ReflectionMethod( 'ATI_Consent_Service', 'detect_analytics_from_cmps' );
$detect->setAccessible( true );

// Solo marketing (Cookiebot marketing:true, statistics:false) => niente analytics.
$_COOKIE = array( 'CookieConsent' => 'stamp:x,necessary:true,preferences:false,statistics:false,marketing:true' );
ok( false === $detect->invoke( null ), 'Consenso marketing da solo NON concede analytics' );

// Statistics:true => analytics concesso.
$_COOKIE = array( 'CookieConsent' => 'stamp:x,statistics:true,marketing:false' );
ok( true === $detect->invoke( null ), 'Cookiebot statistics:true concede analytics' );

// Complianz statistiche.
$_COOKIE = array( 'cmplz_statistics' => 'allow', 'cmplz_marketing' => 'deny' );
ok( true === $detect->invoke( null ), 'Complianz cmplz_statistics=allow concede analytics' );

// OneTrust C0002 (analytics) vs C0004 (marketing).
$_COOKIE = array( 'OptanonConsent' => 'groups=C0001:1,C0002:1,C0004:0' );
ok( true === $detect->invoke( null ), 'OneTrust C0002:1 concede analytics' );
$_COOKIE = array( 'OptanonConsent' => 'groups=C0001:1,C0002:0,C0004:1' );
ok( false === $detect->invoke( null ), 'OneTrust solo marketing (C0004) NON concede analytics' );
$_COOKIE = array();

// -------------------------------------------------------------------------
section( 'Endpoint EU/Global, collect vs validation' );
$GLOBALS['__ati_opts']['ati_ga4_region'] = 'eu';
$eu = ATI_GA4_Endpoints::collect_url( 'G-TEST', 'SECRET' );
ok( 0 === strpos( $eu, 'https://region1.google-analytics.com/mp/collect' ), 'EU usa region1 collect' );
ok( false !== strpos( ATI_GA4_Endpoints::validation_url( 'G-TEST', 'SECRET' ), '/debug/mp/collect' ), 'EU validation usa /debug/mp/collect' );
$GLOBALS['__ati_opts']['ati_ga4_region'] = 'global';
ok( 0 === strpos( ATI_GA4_Endpoints::collect_url( 'G-TEST', 'S' ), 'https://www.google-analytics.com/mp/collect' ), 'Global usa host globale' );
$GLOBALS['__ati_opts']['ati_ga4_region'] = 'eu';

// -------------------------------------------------------------------------
section( 'Payload GA4 completo (Test 10)' );
$ev = ATI_Event::from_array( array(
	'event_name'             => 'generate_lead',
	'event_timestamp_micros' => 1700000000000000,
	'client_id'              => '111.222',
	'session_id'             => '1700000000',
	'engagement_time_msec'   => 1,
	'page_location'          => 'https://example.com/landing',
	'page_title'             => 'Landing',
	'params'                 => array( 'business_area' => 'acquisizione_immobili', 'form_id' => 'contatti' ),
	'event_id'               => 'evt_123_abc',
) );
$payload = ATI_GA4_Adapter::build_payload( $ev, false );
ok( '111.222' === $payload['client_id'], 'client_id presente nel payload' );
ok( 1700000000000000 === $payload['timestamp_micros'], 'timestamp_micros presente' );
$params = $payload['events'][0]['params'];
ok( '1700000000' === $params['session_id'], 'session_id presente' );
ok( isset( $params['engagement_time_msec'] ) && $params['engagement_time_msec'] >= 1, 'engagement_time_msec presente' );
ok( 'https://example.com/landing' === $params['page_location'], 'page_location presente' );
ok( 'acquisizione_immobili' === $params['business_area'], 'parametro commerciale presente' );
ok( 'evt_123_abc' === $params['event_id'], 'event_id come parametro diagnostico' );
ok( ! isset( $params['debug_mode'] ), 'debug_mode assente in produzione' );
$payload_dbg = ATI_GA4_Adapter::build_payload( $ev, true );
ok( isset( $payload_dbg['events'][0]['params']['debug_mode'] ), 'debug_mode presente solo in validazione' );

// client_id vuoto -> chiave rimossa (nessun UUID casuale).
$ev2 = ATI_Event::from_array( array( 'event_name' => 'generate_lead', 'client_id' => '' ) );
$pay2 = ATI_GA4_Adapter::build_payload( $ev2, false );
ok( ! isset( $pay2['client_id'] ), 'client_id assente: nessun UUID casuale iniettato (Test 11)' );

// -------------------------------------------------------------------------
section( 'client_id/session_id reali dai cookie (Test 11)' );
$_COOKIE = array(
	'_ga'        => 'GA1.1.1234567890.1600000000',
	'_ga_ABC123' => 'GS1.1.1699999999.3.1.1699999999.0.0.0',
);
$ctx = ATI_GA4_Client_Context::from_cookies();
ok( '1234567890.1600000000' === $ctx['client_id'], 'client_id ricostruito da cookie _ga' );
ok( '1699999999' === $ctx['session_id'], 'session_id ricostruito da cookie _ga_*' );
$_COOKIE = array();
$ctx0 = ATI_GA4_Client_Context::from_cookies();
ok( '' === $ctx0['client_id'] && '' === $ctx0['session_id'], 'Nessun cookie -> stringhe vuote (degrado esplicito)' );

// -------------------------------------------------------------------------
section( 'Chiave di deduplica (Test 6)' );
$k1 = ATI_Event_Queue_DedupKeyProxy( 'evt_A', 'generate_lead', 'ga4' );
$k2 = ATI_Event_Queue_DedupKeyProxy( 'evt_A', 'generate_lead', 'ga4' );
$k3 = ATI_Event_Queue_DedupKeyProxy( 'evt_B', 'generate_lead', 'ga4' );
ok( $k1 === $k2, 'Stessa tripla -> stessa dedup_key (un solo invio)' );
ok( $k1 !== $k3, 'event_id diverso -> dedup_key diversa' );

// -------------------------------------------------------------------------
section( 'Secret sanitizer: non cancella se vuoto/mascherato (Test 14)' );
$GLOBALS['__ati_opts']['ati_ga4_api_secret'] = 'REAL_SECRET_VALUE';
$_POST = array();
ok( 'REAL_SECRET_VALUE' === ATI_GA4_Admin::sanitize_api_secret( '' ), 'Campo vuoto conserva il secret esistente' );
ok( 'REAL_SECRET_VALUE' === ATI_GA4_Admin::sanitize_api_secret( '••••••••' ), 'Campo mascherato conserva il secret' );
ok( 'NUOVO' === ATI_GA4_Admin::sanitize_api_secret( 'NUOVO' ), 'Valore nuovo aggiorna il secret' );
$_POST = array( 'ati_ga4_remove_secret' => '1' );
ok( '' === ATI_GA4_Admin::sanitize_api_secret( '' ), 'Rimozione esplicita cancella il secret' );
$_POST = array();

// -------------------------------------------------------------------------
section( 'Macchina a stati consegna: classify_delivery (Test 1,3,4,5,7 hardening)' );
// client_id mancante -> discarded/missing_client_id, mai sent.
$d = ATI_Event_Queue::classify_delivery( false, null, 1 );
ok( 'discarded' === $d['status'] && 'missing_client_id' === $d['reason_code'], 'client_id mancante -> discarded (missing_client_id), non sent' );
ok( false === $d['retry'], 'discarded non è ritentabile' );
// invio ok -> sent.
$d = ATI_Event_Queue::classify_delivery( true, array( 'ok' => true, 'code' => 204 ), 1 );
ok( 'sent' === $d['status'], 'invio ok -> sent' );
// errore HTTP ritentabile -> failed + retry.
$d = ATI_Event_Queue::classify_delivery( true, array( 'ok' => false, 'error' => 'http_500', 'code' => 500 ), 1 );
ok( 'failed' === $d['status'] && true === $d['retry'] && 'delivery_error' === $d['reason_code'], 'errore HTTP -> failed con retry' );
ok( 'http_500' === $d['last_error'], 'last_error sanitizzato presente' );
// oltre MAX_ATTEMPTS -> failed terminale.
$d = ATI_Event_Queue::classify_delivery( true, array( 'ok' => false, 'error' => 'http_500' ), ATI_Event_Queue::MAX_ATTEMPTS );
ok( 'failed' === $d['status'] && false === $d['retry'], 'raggiunto MAX_ATTEMPTS -> failed terminale (no retry)' );
// configurazione mancante -> failed ritentabile, mai sent.
$d = ATI_Event_Queue::classify_delivery( true, array( 'ok' => false, 'error' => 'missing_configuration' ), 1 );
ok( 'failed' === $d['status'] && 'missing_configuration' === $d['reason_code'] && true === $d['retry'], 'configurazione mancante -> failed ritentabile, non sent' );

// -------------------------------------------------------------------------
section( 'Adapter send(): guardie senza rete (Test 11, 14)' );
$_COOKIE = array();
$evNoCid = ATI_Event::from_array( array( 'event_name' => 'generate_lead', 'client_id' => '' ) );
$r = ATI_GA4_Adapter::send( $evNoCid );
ok( false === $r['ok'] && 'missing_client_id' === $r['error'], 'send senza client_id: nessun invio, error missing_client_id' );
// client_id presente ma secret assente -> missing_configuration (nessuna rete).
$GLOBALS['__ati_opts']['ati_ga4_server_id']  = 'G-TEST';
$GLOBALS['__ati_opts']['ati_ga4_api_secret'] = '';
$evCid = ATI_Event::from_array( array( 'event_name' => 'generate_lead', 'client_id' => '111.222' ) );
$r = ATI_GA4_Adapter::send( $evCid );
ok( false === $r['ok'] && 'missing_configuration' === $r['error'], 'secret assente -> missing_configuration, nessun invio' );

// -------------------------------------------------------------------------
section( 'Track: consenso analytics negato -> no_consent (Test 2)' );
// Config pronta + pipeline attiva, ma nessun cookie analytics (consenso negato).
$GLOBALS['__ati_opts']['ati_ga4_server_id']         = 'G-TEST';
$GLOBALS['__ati_opts']['ati_ga4_api_secret']        = 'secret_presente';
$GLOBALS['__ati_opts']['ati_ga4_confirmed_enabled'] = '1';
$GLOBALS['__ati_opts']['ati_ga4_analytics_consent_mode'] = 'auto';
$_COOKIE = array(); // nessun consenso analytics.
$out = ati_track_confirmed_event( 'generate_lead', array( 'business_area' => 'x' ), array( 'client_id' => '111.222' ) );
ok( 'no_consent' === $out['status'] && 'analytics_consent_missing' === $out['reason'], 'consenso analytics negato -> no_consent (nessun invio/coda)' );

// Pipeline disattivata -> skipped.
$GLOBALS['__ati_opts']['ati_ga4_confirmed_enabled'] = '0';
$out = ati_track_confirmed_event( 'generate_lead', array(), array() );
ok( 'skipped' === $out['status'] && 'pipeline_disabled' === $out['reason'], 'pipeline OFF -> skipped' );

// -------------------------------------------------------------------------
section( 'Parsing cookie GA4 invalido -> fallback controllato (Test 11)' );
$_COOKIE = array( '_ga' => 'garbage' );
ok( '' === ATI_GA4_Client_Context::client_id_from_ga_cookie(), 'cookie _ga malformato -> client_id vuoto (nessuna identità inventata)' );
$_COOKIE = array( '_ga' => 'GA1.1.abc.def' );
ok( '' === ATI_GA4_Client_Context::client_id_from_ga_cookie(), 'cookie _ga con parti non numeriche -> vuoto' );
$_COOKIE = array( '_ga_XYZ' => 'INVALID_FORMAT' );
ok( '' === ATI_GA4_Client_Context::session_id_from_ga_cookie(), 'cookie sessione malformato -> session_id vuoto' );
$_COOKIE = array();

// -------------------------------------------------------------------------
echo "\n---------------------------------------\n";
echo "RISULTATO: {$GLOBALS['__pass']} PASS / {$GLOBALS['__fail']} FAIL\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );

/**
 * Proxy per esporre la logica di dedup_key senza caricare la classe coda (che
 * dipende da $wpdb). Replica ESATTAMENTE ATI_Event_Queue::dedup_key.
 */
function ATI_Event_Queue_DedupKeyProxy( $event_id, $event_name, $destination ) {
	return md5( $event_id . '|' . $event_name . '|' . $destination );
}
