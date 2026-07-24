<?php
/**
 * ATI_GA4_Endpoints — Costruzione centralizzata degli endpoint GA4 MP.
 *
 * Unico punto in cui vengono costruiti gli URL. Nessun URL GA4 hardcoded
 * sparso in altri file.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fabbrica degli endpoint di raccolta e validazione.
 */
class ATI_GA4_Endpoints {

	const HOST_GLOBAL = 'https://www.google-analytics.com';
	const HOST_EU     = 'https://region1.google-analytics.com';

	/**
	 * Ritorna la regione configurata: 'eu' (default) o 'global'.
	 *
	 * @return string
	 */
	public static function region() {
		$region = get_option( 'ati_ga4_region', 'eu' );
		return ( 'global' === $region ) ? 'global' : 'eu';
	}

	/**
	 * Host in base alla regione.
	 *
	 * @return string
	 */
	protected static function host() {
		return ( 'global' === self::region() ) ? self::HOST_GLOBAL : self::HOST_EU;
	}

	/**
	 * URL endpoint di raccolta (produzione).
	 *
	 * @param string $measurement_id Measurement ID.
	 * @param string $api_secret     API secret.
	 * @return string
	 */
	public static function collect_url( $measurement_id, $api_secret ) {
		return self::host() . '/mp/collect?measurement_id=' . rawurlencode( $measurement_id )
			. '&api_secret=' . rawurlencode( $api_secret );
	}

	/**
	 * URL endpoint di validazione/debug.
	 *
	 * @param string $measurement_id Measurement ID.
	 * @param string $api_secret     API secret.
	 * @return string
	 */
	public static function validation_url( $measurement_id, $api_secret ) {
		return self::host() . '/debug/mp/collect?measurement_id=' . rawurlencode( $measurement_id )
			. '&api_secret=' . rawurlencode( $api_secret );
	}
}
