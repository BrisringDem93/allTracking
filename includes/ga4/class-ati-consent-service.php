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
		$iub_purpose = (int) apply_filters( 'ati_iubenda_analytics_purpose', 4 );
		foreach ( $_COOKIE as $name => $value ) {
			if ( preg_match( '/^_iub_cs-\d+$/', (string) $name ) ) {
				$data = json_decode( urldecode( (string) $value ), true );
				if ( is_array( $data ) && isset( $data['purposes'][ $iub_purpose ] ) && true === $data['purposes'][ $iub_purpose ] ) {
					return true;
				}
			}
		}

		// Cookiebot.
		if ( isset( $_COOKIE['CookieConsent'] ) && false !== strpos( urldecode( (string) $_COOKIE['CookieConsent'] ), 'statistics:true' ) ) {
			return true;
		}

		// OneTrust: C0002 = Performance/Analytics.
		if ( isset( $_COOKIE['OptanonConsent'] ) && preg_match( '/(?:^|&)groups=([^&]*)/', urldecode( (string) $_COOKIE['OptanonConsent'] ), $m ) ) {
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
