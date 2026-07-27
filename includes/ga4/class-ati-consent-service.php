<?php
/**
 * ATI_Consent_Service — Separazione esplicita del consenso analytics/marketing.
 *
 * Il consenso analytics governa GA4 Measurement Protocol.
 * Il consenso marketing resta quello già usato da Meta/n8n (invariato).
 *
 * IMPORTANTE: il consenso marketing NON viene mai usato automaticamente come
 * consenso analytics. Sono categorie distinte con semantica CMP diversa.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servizio di consenso estendibile tramite adapter CMP.
 */
class ATI_Consent_Service {

	/**
	 * Consenso analytics (lato server, dai cookie CMP).
	 *
	 * @return bool
	 */
	public static function has_analytics_consent() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$mode = get_option( 'ati_ga4_analytics_consent_mode', 'auto' );

		if ( 'always' === $mode ) {
			/**
			 * Consenso analytics rilevato (override).
			 *
			 * @param bool $consent Valore rilevato.
			 * @param string $mode Modalità configurata.
			 */
			return $cached = (bool) apply_filters( 'ati_has_analytics_consent', true, $mode );
		}

		$consent = self::detect_analytics_from_cmps();

		return $cached = (bool) apply_filters( 'ati_has_analytics_consent', $consent, $mode );
	}

	/**
	 * Consenso marketing. Delega alla funzione esistente del plugin per non
	 * alterare il comportamento di Meta/n8n.
	 *
	 * @return bool
	 */
	public static function has_marketing_consent() {
		$consent = function_exists( 'ati_has_marketing_consent' )
			? (bool) ati_has_marketing_consent()
			: false;

		return (bool) apply_filters( 'ati_has_marketing_consent_ga4_view', $consent );
	}

	/**
	 * Diagnostica consenso PII-free per il pannello admin.
	 *
	 * NON espone il contenuto dei cookie: solo presenza del provider, stato
	 * analytics rilevato, purpose iubenda configurato ed esito.
	 *
	 * @return array{providers:array<int,string>,analytics:bool,iubenda_purpose:int,mode:string}
	 */
	public static function diagnostics() {
		$providers = array();

		foreach ( $_COOKIE as $name => $value ) {
			if ( preg_match( '/^_iub_cs-\d+$/', (string) $name ) ) {
				$providers[] = 'iubenda';
				break;
			}
		}
		if ( isset( $_COOKIE['cmplz_statistics'] ) || isset( $_COOKIE['cmplz_marketing'] ) ) {
			$providers[] = 'complianz';
		}
		if ( isset( $_COOKIE['CookieConsent'] ) ) {
			$providers[] = 'cookiebot';
		}
		if ( isset( $_COOKIE['OptanonConsent'] ) ) {
			$providers[] = 'onetrust';
		}
		$custom = trim( (string) get_option( 'ati_analytics_cookie_name', '' ) );
		if ( '' !== $custom && isset( $_COOKIE[ $custom ] ) ) {
			$providers[] = 'custom';
		}

		return array(
			'providers'       => array_values( array_unique( $providers ) ),
			'analytics'       => self::detect_analytics_from_cmps(),
			'iubenda_purpose' => (int) apply_filters( 'ati_iubenda_analytics_purpose', 4 ),
			'mode'            => (string) get_option( 'ati_ga4_analytics_consent_mode', 'auto' ),
		);
	}

	/**
	 * Normalizza un valore di cookie letto da $_COOKIE in contesto WordPress.
	 *
	 * WordPress applica magic-quotes ai superglobali: le virgolette dei JSON dei CMP
	 * arrivano escapate. wp_unslash() (o stripslashes come fallback fuori da WP) le
	 * ripristina, così json_decode()/strpos() funzionano.
	 *
	 * @param string $value Valore grezzo del cookie.
	 * @return string
	 */
	protected static function clean_cookie( $value ) {
		return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : stripslashes( $value );
	}

	/**
	 * Rileva il consenso analytics dai cookie dei CMP supportati.
	 *
	 * Segnali (categoria "statistiche/analytics", distinta da marketing):
	 * - Cookie custom analytics (opzione ati_analytics_cookie_name, valore "allow")
	 * - Complianz: cmplz_statistics=allow
	 * - iubenda: purpose 4 (Measurement) — best-effort, filtrabile
	 * - Cookiebot: CookieConsent con statistics:true
	 * - OneTrust: OptanonConsent groups C0002:1 (Performance/Analytics)
	 *
	 * @return bool
	 */
	protected static function detect_analytics_from_cmps() {
		// Cookie custom analytics opzionale.
		$custom = trim( (string) get_option( 'ati_analytics_cookie_name', '' ) );
		if ( '' !== $custom && isset( $_COOKIE[ $custom ] ) && 'allow' === $_COOKIE[ $custom ] ) {
			return true;
		}

		// Complianz.
		if ( isset( $_COOKIE['cmplz_statistics'] ) && 'allow' === $_COOKIE['cmplz_statistics'] ) {
			return true;
		}

		// iubenda: purpose "Measurement". L'indice è filtrabile perché varia tra configurazioni.
		// IMPORTANTE: WordPress applica magic-quotes a $_COOKIE, quindi il JSON iubenda
		// arriva con le virgolette escapate. Serve wp_unslash() prima di json_decode(),
		// altrimenti il parsing fallisce e il consenso non viene rilevato.
		$iub_purpose = (int) apply_filters( 'ati_iubenda_analytics_purpose', 4 );
		$iub_names   = array( $iub_purpose, (string) $iub_purpose );
		foreach ( $_COOKIE as $name => $value ) {
			// iubenda usa sia nomi tutti-numerici sia con prefisso (es. _iub_cs-s4597678).
			if ( 0 === strpos( (string) $name, '_iub_cs-' ) ) {
				$data = json_decode( self::clean_cookie( (string) $value ), true );
				if ( is_array( $data ) && isset( $data['purposes'] ) && is_array( $data['purposes'] ) ) {
					foreach ( $iub_names as $key ) {
						if ( isset( $data['purposes'][ $key ] ) && true === $data['purposes'][ $key ] ) {
							return true;
						}
					}
				}
			}
		}

		// Cookiebot.
		if ( isset( $_COOKIE['CookieConsent'] ) && false !== strpos( self::clean_cookie( (string) $_COOKIE['CookieConsent'] ), 'statistics:true' ) ) {
			return true;
		}

		// OneTrust: C0002 = Performance/Analytics.
		if ( isset( $_COOKIE['OptanonConsent'] ) && preg_match( '/(?:^|&)groups=([^&]*)/', self::clean_cookie( (string) $_COOKIE['OptanonConsent'] ), $m ) ) {
			if ( false !== strpos( $m[1], 'C0002:1' ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ati_has_analytics_consent' ) ) {
	/**
	 * Wrapper pubblico: consenso analytics.
	 *
	 * @return bool
	 */
	function ati_has_analytics_consent() {
		return ATI_Consent_Service::has_analytics_consent();
	}
}
