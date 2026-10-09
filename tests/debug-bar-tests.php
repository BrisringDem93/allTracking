<?php
/**
 * Test delle FUNZIONI PURE del widget di debug (cookie attesi e diagnostica).
 *
 * Esegui: php tests/debug-bar-tests.php
 *
 * Copre: derivazione del cookie di sessione GA4 dal Measurement ID, attese per
 * ciascun cookie in funzione di tag attivi/consenso/GTM/tracking disattivato per
 * gli utenti loggati, righe dei cookie di consenso personalizzati, lettura della
 * configurazione dalle opzioni e segnalazioni diagnostiche.
 *
 * Il rendering (assets/js/debug-bar.js) e la fotografia che dipende da WordPress
 * (ATI_Debug_Bar) non sono coperti qui: richiedono DOM e WP reale.
 *
 * @package QuickTrackingIntegration\Tests
 */

require __DIR__ . '/wp-stubs.php';

// Definite in includes/ga4/ e includes/tag-inserter.php quando WordPress è caricato:
// qui servono perché ATI_Cookie_Consent::state() le interroga.
if ( ! function_exists( 'ati_has_analytics_consent' ) ) {
	$GLOBALS['__ati_analytics_consent'] = false;
	function ati_has_analytics_consent() {
		return (bool) $GLOBALS['__ati_analytics_consent'];
	}
}

require dirname( __DIR__ ) . '/includes/cookie-guard/class-ati-cookie-rules.php';
require dirname( __DIR__ ) . '/includes/cookie-guard/class-ati-cookie-consent.php';
require dirname( __DIR__ ) . '/includes/debug-bar/class-ati-debug-expectations.php';

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

/**
 * Configurazione completa a partire dai soli campi rilevanti per il caso di test.
 *
 * @param array $overrides Campi da sovrascrivere.
 * @return array
 */
function cfg( $overrides = array() ) {
	return array_merge(
		array(
			'gtm'            => array( 'enabled' => false, 'id' => '' ),
			'ga4'            => array( 'enabled' => false, 'id' => '' ),
			'fb'             => array( 'enabled' => false, 'id' => '' ),
			'form_fields'    => true,
			'tracking_off'   => false,
			'fbclid_in_url'  => false,
			'custom_cookies' => array( 'marketing' => '', 'analytics' => '', 'preferences' => '' ),
		),
		$overrides
	);
}

/**
 * Riga delle attese per un dato nome di cookie.
 *
 * @param array  $rows Righe.
 * @param string $name Nome cookie.
 * @return array|null
 */
function row( $rows, $name ) {
	foreach ( $rows as $r ) {
		if ( $r['name'] === $name ) {
			return $r;
		}
	}
	return null;
}

/**
 * Esito atteso per un cookie, o stringa vuota se la riga non esiste.
 *
 * @param array  $rows Righe.
 * @param string $name Nome cookie.
 * @return string
 */
function expected( $rows, $name ) {
	$r = row( $rows, $name );
	return null === $r ? '' : $r['expected'];
}

$none = array( 'marketing' => false, 'analytics' => false, 'preferences' => false );
$all  = array( 'marketing' => true, 'analytics' => true, 'preferences' => true );

// -------------------------------------------------------------------------
section( 'Cookie di sessione GA4 derivato dal Measurement ID' );

ok( '_ga_ABC1234567' === ATI_Debug_Expectations::ga_session_cookie( 'G-ABC1234567' ), 'G-ABC1234567 -> _ga_ABC1234567' );
ok( '_ga_0RVDVFM24W' === ATI_Debug_Expectations::ga_session_cookie( ' g-0rvdvfm24w ' ), 'spazi e minuscole normalizzati' );
ok( '' === ATI_Debug_Expectations::ga_session_cookie( 'UA-12345-1' ), 'ID Universal Analytics -> nessun cookie derivabile' );
ok( '' === ATI_Debug_Expectations::ga_session_cookie( '' ), 'ID vuoto -> nessun cookie derivabile' );
ok( '' === ATI_Debug_Expectations::ga_session_cookie( 'G-ABC_123' ), 'ID con caratteri non ammessi -> nessun cookie derivabile' );

// -------------------------------------------------------------------------
section( 'GA4 client-side: attese secondo tag e consenso' );

$ga_on = cfg( array( 'ga4' => array( 'enabled' => true, 'id' => 'G-ABC1234567' ) ) );

$rows = ATI_Debug_Expectations::rows( $ga_on, $all );
ok( 'yes' === expected( $rows, '_ga' ), 'GA4 attivo + consenso statistiche -> _ga deve esserci' );
ok( 'yes' === expected( $rows, '_ga_ABC1234567' ), 'anche il cookie di sessione derivato deve esserci' );

$rows = ATI_Debug_Expectations::rows( $ga_on, $none );
ok( 'no' === expected( $rows, '_ga' ), 'GA4 attivo senza consenso statistiche -> _ga NON deve esserci' );
ok( 'no' === expected( $rows, '_ga_ABC1234567' ), 'cookie di sessione GA4 senza consenso -> non deve esserci' );

// Solo consenso marketing: non concede analytics (categorie distinte).
$rows = ATI_Debug_Expectations::rows( $ga_on, array( 'marketing' => true, 'analytics' => false, 'preferences' => false ) );
ok( 'no' === expected( $rows, '_ga' ), 'consenso marketing da solo NON legittima _ga' );

// Measurement ID non riconosciuto: il nome esatto non è deducibile.
$rows = ATI_Debug_Expectations::rows( cfg( array( 'ga4' => array( 'enabled' => true, 'id' => 'UA-1' ) ) ), $all );
ok( null !== row( $rows, '_ga_' ) && 'prefix' === row( $rows, '_ga_' )['match'], 'ID non GA4 -> riga con match per prefisso _ga_' );

// GA4 non attivo nel plugin: il cookie può esserci per altri strumenti.
$rows = ATI_Debug_Expectations::rows( cfg(), $all );
ok( 'maybe' === expected( $rows, '_ga' ), 'GA4 non attivo nel plugin -> esito "dipende", non un errore' );

// ID mancante = tag non stampato.
$rows = ATI_Debug_Expectations::rows( cfg( array( 'ga4' => array( 'enabled' => true, 'id' => '' ) ) ), $all );
ok( 'maybe' === expected( $rows, '_ga' ), 'GA4 spuntato ma senza Measurement ID -> non conta come attivo' );

// -------------------------------------------------------------------------
section( 'GTM attivo: il plugin non carica i tag' );

$gtm = cfg(
	array(
		'gtm' => array( 'enabled' => true, 'id' => 'GTM-XYZ' ),
		'ga4' => array( 'enabled' => true, 'id' => 'G-ABC1234567' ),
		'fb'  => array( 'enabled' => true, 'id' => '123456789012345' ),
	)
);
$rows = ATI_Debug_Expectations::rows( $gtm, $none );
ok( 'maybe' === expected( $rows, '_ga' ), 'con GTM i cookie GA4 dipendono dal container' );
ok( 'maybe' === expected( $rows, '_fbp' ), 'con GTM il cookie del Pixel dipende dal container' );

// -------------------------------------------------------------------------
section( 'Meta Pixel e cookie _fbc' );

$fb_on = cfg( array( 'fb' => array( 'enabled' => true, 'id' => '123456789012345' ) ) );

ok( 'yes' === expected( ATI_Debug_Expectations::rows( $fb_on, $all ), '_fbp' ), 'Pixel attivo + consenso marketing -> _fbp deve esserci' );
ok( 'no' === expected( ATI_Debug_Expectations::rows( $fb_on, $none ), '_fbp' ), 'Pixel attivo senza consenso -> _fbp NON deve esserci' );

// _fbc non dipende dal Pixel: lo scrive il plugin quando arriva un fbclid.
ok( 'no' === expected( ATI_Debug_Expectations::rows( cfg(), $none ), '_fbc' ), 'senza consenso marketing -> _fbc NON deve esserci' );
ok( 'maybe' === expected( ATI_Debug_Expectations::rows( cfg(), $all ), '_fbc' ), 'con consenso ma senza fbclid -> _fbc può mancare' );
ok( 'yes' === expected( ATI_Debug_Expectations::rows( cfg( array( 'fbclid_in_url' => true ) ), $all ), '_fbc' ), 'fbclid nell\'URL + consenso -> _fbc deve esserci' );
ok( 'no' === expected( ATI_Debug_Expectations::rows( cfg( array( 'fbclid_in_url' => true ) ), $none ), '_fbc' ), 'fbclid nell\'URL senza consenso -> nessun cookie' );

// -------------------------------------------------------------------------
section( 'Cookie del plugin (fst_uid, fst_clid)' );

ok( 'no' === expected( ATI_Debug_Expectations::rows( cfg(), $none ), 'fst_uid' ), 'nessuno storage senza consenso marketing -> fst_uid non deve esserci' );
ok( 'maybe' === expected( ATI_Debug_Expectations::rows( cfg(), $all ), 'fst_uid' ), 'con consenso fst_uid nasce al primo evento -> "dipende"' );
ok( 'no' === expected( ATI_Debug_Expectations::rows( cfg(), $none ), 'fst_clid' ), 'fst_clid senza consenso -> non deve esserci' );
ok( 'maybe' === expected( ATI_Debug_Expectations::rows( cfg(), $all ), 'fst_clid' ), 'fst_clid con consenso -> dipende dai click id ricevuti' );
ok( 'no' === expected( ATI_Debug_Expectations::rows( cfg( array( 'form_fields' => false ) ), $all ), 'fst_clid' ), 'campi hidden disattivati -> fst_clid non deve esserci' );

// -------------------------------------------------------------------------
section( 'Tracking disattivato per gli utenti loggati' );

$off  = cfg(
	array(
		'tracking_off'  => true,
		'ga4'           => array( 'enabled' => true, 'id' => 'G-ABC1234567' ),
		'fb'            => array( 'enabled' => true, 'id' => '123456789012345' ),
		'fbclid_in_url' => true,
	)
);
$rows = ATI_Debug_Expectations::rows( $off, $all );
foreach ( array( '_ga', '_ga_ABC1234567', '_fbp', '_fbc', 'fst_uid', 'fst_clid' ) as $name ) {
	ok( 'no' === expected( $rows, $name ), "tracking spento per l'utente: $name non deve esserci" );
}

// -------------------------------------------------------------------------
section( 'Cookie di consenso personalizzati' );

$rows = ATI_Debug_Expectations::rows( cfg(), $all );
ok( null === row( $rows, 'mio_consenso' ), 'nessuna riga per cookie di consenso non configurati' );

$rows = ATI_Debug_Expectations::rows(
	cfg( array( 'custom_cookies' => array( 'marketing' => 'mio_consenso', 'analytics' => '', 'preferences' => '' ) ) ),
	$all
);
$custom = row( $rows, 'mio_consenso' );
ok( null !== $custom, 'cookie di consenso personalizzato presente tra le attese' );
ok( 'maybe' === $custom['expected'], 'il cookie del CMP è sempre "dipende": lo scrive il banner, non il plugin' );

// -------------------------------------------------------------------------
section( 'config(): lettura dalle opzioni' );

$GLOBALS['__ati_opts'] = array(
	'ati_enable_ga4'         => '1',
	'ati_ga4_id'             => 'G-TEST123',
	'ati_enable_fb'          => '1',
	'ati_fb_pixel_id'        => '999',
	'ati_enable_gtm'         => '',
	'ati_gtm_id'             => 'GTM-NOTUSED',
	'ati_enable_form_fields' => '1',
	'ati_disable_logged_in'  => '1',
);
$GLOBALS['__ati_logged_in'] = false;
$_GET                       = array();

$c = ATI_Debug_Expectations::config();
ok( true === $c['ga4']['enabled'] && 'G-TEST123' === $c['ga4']['id'], 'GA4 attivo con ID letto dalle opzioni' );
ok( true === $c['fb']['enabled'], 'Pixel attivo con ID presente' );
ok( false === $c['gtm']['enabled'], 'GTM con casella vuota -> non attivo anche se l\'ID è presente' );
ok( false === $c['tracking_off'], '«disattiva per utenti loggati» non si applica a un visitatore anonimo' );

$GLOBALS['__ati_logged_in'] = true;
$c                          = ATI_Debug_Expectations::config();
ok( true === $c['tracking_off'], 'utente loggato + opzione attiva -> tracking spento in questa pagina' );

$_GET = array( 'fbclid' => 'IwAR0test' );
$c    = ATI_Debug_Expectations::config();
ok( true === $c['fbclid_in_url'], 'fbclid nell\'URL rilevato' );
$_GET = array( 'fbclid' => '   ' );
$c    = ATI_Debug_Expectations::config();
ok( false === $c['fbclid_in_url'], 'fbclid vuoto non conta' );
$_GET = array();

// I nomi dei cookie di consenso arrivano dalle opzioni già esistenti.
$GLOBALS['__ati_opts']['ati_consent_cookie_name']   = 'mio_marketing';
$GLOBALS['__ati_opts']['ati_analytics_cookie_name'] = 'mio_analytics';
$c                                                 = ATI_Debug_Expectations::config();
ok( 'mio_marketing' === $c['custom_cookies']['marketing'] && 'mio_analytics' === $c['custom_cookies']['analytics'], 'cookie di consenso riusati dalle opzioni esistenti' );

// -------------------------------------------------------------------------
section( 'Diagnostica: segnalazioni' );

/**
 * Livelli delle segnalazioni che contengono un testo.
 *
 * @param array  $notices Segnalazioni.
 * @param string $needle  Testo da cercare.
 * @return string Livello trovato, stringa vuota se assente.
 */
function level_of( $notices, $needle ) {
	foreach ( $notices as $n ) {
		if ( false !== strpos( $n['text'], $needle ) ) {
			return $n['level'];
		}
	}
	return '';
}

$guard = array(
	'mode'           => 'enforce',
	'rules'          => 22,
	'skip_logged_in' => true,
	'server_cleanup' => false,
	'providers'      => array(),
);

$GLOBALS['__ati_logged_in'] = true;
$notices                    = ATI_Debug_Expectations::notices( cfg(), $none, $guard );
ok( 'error' === level_of( $notices, 'Nessun CMP rilevato' ), 'nessun CMP rilevato -> errore (il consenso risulta sempre assente)' );
ok( 'warn' === level_of( $notices, 'escluso per gli utenti loggati' ), 'guard escluso per i loggati -> avviso: quello che vedi non è quello che vede un visitatore' );

$notices = ATI_Debug_Expectations::notices( cfg( array( 'tracking_off' => true ) ), $none, $guard );
ok( 'warn' === level_of( $notices, 'Disattiva per utenti loggati' ), 'tracking spento per i loggati -> avviso' );

$notices = ATI_Debug_Expectations::notices( cfg(), $all, array_merge( $guard, array( 'rules' => 0, 'providers' => array( 'complianz' ) ) ) );
ok( 'warn' === level_of( $notices, 'nessuna regola attiva' ), 'modalità attiva senza regole -> avviso' );
ok( '' === level_of( $notices, 'Nessun CMP rilevato' ), 'CMP rilevato -> nessun errore sul consenso' );

$notices = ATI_Debug_Expectations::notices( cfg(), $all, array_merge( $guard, array( 'mode' => 'off', 'providers' => array( 'complianz' ) ) ) );
ok( 'info' === level_of( $notices, 'Blocco cookie disattivato' ), 'blocco disattivato -> informativa' );
ok( '' === level_of( $notices, 'escluso per gli utenti loggati' ), 'con blocco disattivato non si parla di esclusione utenti loggati' );

// GA4 server-side.
$ga4_broken = array(
	'confirmed'      => true,
	'ready'          => false,
	'measurement_id' => '',
	'has_secret'     => false,
	'queue'          => array( 'pending' => 0, 'failed' => 3, 'sent' => 0, 'discarded' => 0 ),
);
$notices = ATI_Debug_Expectations::notices( cfg(), $all, $guard, $ga4_broken );
ok( 'error' === level_of( $notices, 'configurazione incompleta' ), 'pipeline GA4 attiva ma incompleta -> errore' );
ok( false !== strpos( implode( ' ', array_column( $notices, 'text' ) ), 'Measurement ID, API Secret' ), 'l\'errore elenca cosa manca' );
ok( 'warn' === level_of( $notices, 'in stato failed' ), 'eventi failed in coda -> avviso' );

$ga4_ok  = array( 'confirmed' => true, 'ready' => true, 'measurement_id' => 'G-X', 'has_secret' => true, 'queue' => array( 'failed' => 0 ) );
$notices = ATI_Debug_Expectations::notices( cfg(), $none, $guard, $ga4_ok );
ok( '' === level_of( $notices, 'configurazione incompleta' ), 'configurazione GA4 completa -> nessun errore' );
ok( 'info' === level_of( $notices, 'no_consent' ), 'consenso statistiche assente con pipeline attiva -> informativa' );

$notices = ATI_Debug_Expectations::notices(
	cfg(
		array(
			'gtm' => array( 'enabled' => true, 'id' => 'GTM-X' ),
			'ga4' => array( 'enabled' => true, 'id' => 'G-X' ),
		)
	),
	$all,
	$guard
);
ok( 'info' === level_of( $notices, 'GTM è attivo' ), 'GTM + tag client-side spuntati -> informativa sulla duplicazione' );

$GLOBALS['__ati_logged_in'] = false;

// -------------------------------------------------------------------------
echo "\n---------------------------------------\n";
echo "RISULTATO: {$GLOBALS['__pass']} PASS / {$GLOBALS['__fail']} FAIL\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
