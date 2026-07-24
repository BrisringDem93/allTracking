<?php
/**
 * ATI_GA4_Client_Context — Recupero del contesto identità GA4 lato server.
 *
 * Il client_id e il session_id "reali" sono generati dal Google Tag nel browser
 * e persistiti nei cookie first-party (_ga e _ga_<stream>). Questi cookie vengono
 * inviati con la richiesta (submit del form / chiamata REST), quindi il server può
 * ricostruire l'identità GA4 reale SENZA generare UUID casuali.
 *
 * Il bridge JavaScript resta il metodo autoritativo (gtag('get', ...)); questi
 * valori da cookie sono il fallback robusto quando il bridge non è presente.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estrattore contesto GA4 dai cookie.
 */
class ATI_GA4_Client_Context {

	/**
	 * Ricostruisce client_id e session_id dai cookie GA4.
	 *
	 * @return array{client_id:string,session_id:string}
	 */
	public static function from_cookies() {
		return array(
			'client_id'  => self::client_id_from_ga_cookie(),
			'session_id' => self::session_id_from_ga_cookie(),
		);
	}

	/**
	 * client_id dal cookie _ga (formato GA1.1.XXXXXXXXXX.YYYYYYYYYY -> "XXXXXXXXXX.YYYYYYYYYY").
	 *
	 * @return string
	 */
	public static function client_id_from_ga_cookie() {
		if ( ! isset( $_COOKIE['_ga'] ) ) {
			return '';
		}
		$parts = explode( '.', (string) $_COOKIE['_ga'] );
		if ( count( $parts ) < 4 ) {
			return '';
		}
		$p2 = preg_replace( '/[^0-9]/', '', $parts[2] );
		$p3 = preg_replace( '/[^0-9]/', '', $parts[3] );
		if ( '' === $p2 || '' === $p3 ) {
			return '';
		}
		return $p2 . '.' . $p3;
	}

	/**
	 * session_id dal cookie di sessione _ga_<stream> (formato GS1.1.<session_id>.<...>).
	 * In GA4 il timestamp di inizio sessione funge da session_id nel Measurement Protocol.
	 *
	 * @return string
	 */
	public static function session_id_from_ga_cookie() {
		foreach ( $_COOKIE as $name => $value ) {
			if ( 0 !== strpos( (string) $name, '_ga_' ) ) {
				continue;
			}
			// GS1.1.<sessionId>.<sessionNumber>.<engaged>.<ts>...
			if ( preg_match( '/^GS\d+\.\d+\.(\d+)\./', (string) $value, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}
}
