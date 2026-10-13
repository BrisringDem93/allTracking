<?php
/**
 * Test dell'external_id lato server: fst_get_uid() (includes/server-tracking.php).
 *
 * Esegui: php tests/external-id-tests.php
 *
 * Tutto l'output viene trattenuto fino alla fine (ob_start): in CLI headers_sent()
 * diventa vero al primo byte inviato, e la generazione dell'id richiede di poter
 * ancora scrivere il cookie. Il lato browser è coperto da tests/external-id-tests.js.
 *
 * @package QuickTrackingIntegration\Tests
 */

ob_start();

require __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/server-tracking.php';

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

$uuid_re = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

// -------------------------------------------------------------------------
section( 'Cookie fst_uid presente: è l\'unica fonte' );
$GLOBALS['__ati_marketing_consent'] = true;
$_COOKIE = array( 'fst_uid' => 'uid-del-browser' );
ok( 'uid-del-browser' === fst_get_uid(), 'il server usa l\'id arrivato con il cookie' );
ok( 'uid-del-browser' === fst_get_uid(), 'e non ne genera un altro alla chiamata successiva' );
$user_data = fst_build_user_data();
ok( 'uid-del-browser' === $user_data['external_id'], 'user_data.external_id = cookie del browser' );

// -------------------------------------------------------------------------
section( 'Nessun cookie e nessun consenso: nessun id' );
$GLOBALS['__ati_marketing_consent'] = false;
$_COOKIE = array();
ok( '' === fst_get_uid(), 'senza consenso non viene generato nulla' );
ok( ! isset( $_COOKIE['fst_uid'] ), 'senza consenso $_COOKIE resta intatto' );

// -------------------------------------------------------------------------
section( 'Ripiego: consenso presente ma richiesta senza cookie' );
$GLOBALS['__ati_marketing_consent'] = true;
$_COOKIE = array();
ok( false === headers_sent(), 'precondizione: header non ancora inviati' );
$first = fst_get_uid();
ok( 1 === preg_match( $uuid_re, $first ), 'viene generato un UUID4 -> ' . $first );
ok( isset( $_COOKIE['fst_uid'] ) && $first === $_COOKIE['fst_uid'], 'l\'id generato è visibile al resto della richiesta' );
ok( $first === fst_get_uid(), 'seconda chiamata nella stessa richiesta: stesso id' );
$one = fst_build_user_data();
$two = fst_build_user_data();
ok( $first === $one['external_id'] && $first === $two['external_id'], 'due eventi nella stessa richiesta: stesso external_id' );

// Cookie presente ma vuoto: non è un id valido, si ricade nel ripiego.
$_COOKIE = array( 'fst_uid' => '' );
$from_empty = fst_get_uid();
ok( 1 === preg_match( $uuid_re, $from_empty ), 'cookie fst_uid vuoto -> id generato, non stringa vuota' );

// -------------------------------------------------------------------------
// Da qui in poi l'output parte davvero: headers_sent() diventa vero.
ob_end_flush();
flush();

section( 'Header già inviati: nessun id che non si possa persistere' );
$GLOBALS['__ati_marketing_consent'] = true;
$_COOKIE = array();
ok( true === headers_sent(), 'precondizione: header già inviati' );
ok( '' === fst_get_uid(), 'nessun id usa-e-getta (cambierebbe a ogni richiesta)' );
ok( ! isset( $_COOKIE['fst_uid'] ), '$_COOKIE resta intatto' );
$_COOKIE = array( 'fst_uid' => 'uid-del-browser' );
ok( 'uid-del-browser' === fst_get_uid(), 'il cookie esistente si legge comunque' );

$_COOKIE = array();
$GLOBALS['__ati_marketing_consent'] = false;

// -------------------------------------------------------------------------
echo "\n---------------------------------------\n";
echo "RISULTATO: {$GLOBALS['__pass']} PASS / {$GLOBALS['__fail']} FAIL\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
