<?php
/**
 * ATI_Cookie_Consent — Stato di consenso GRANULARE per categoria.
 *
 * Espone lo stato delle tre categorie non necessarie (preferenze, statistiche,
 * marketing) leggendo i cookie del CMP attivo.
 *
 * Marketing e analytics NON vengono re-implementati: si delega alle funzioni già
 * usate dal resto del plugin (`ati_has_marketing_consent()` e
 * `ATI_Consent_Service::has_analytics_consent()`), così il blocco cookie non può
 * divergere dal comportamento dei tag. Solo la categoria "preferenze", che il
 * plugin non usava, è rilevata qui.
 *
 * @package QuickTrackingIntegration\CookieGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rilevamento consenso per categoria.
 */
class ATI_Cookie_Consent {

	/**
	 * CMP supportati per il rilevamento.
	 *
	 * @return array<string,string> slug => etichetta.
	 */
	public static function providers() {
		return array(
			'auto'      => 'Rilevamento automatico (consigliato)',
			'complianz' => 'Complianz',
			'iubenda'   => 'iubenda',
			'cookiebot' => 'Cookiebot',
			'onetrust'  => 'OneTrust',
			'custom'    => 'Solo cookie personalizzati (valore atteso: allow)',
		);
	}

	/**
	 * CMP configurato.
	 *
	 * @return string
	 */
	public static function configured_provider() {
		$cmp = (string) get_option( 'ati_cg_cmp', 'auto' );
		return array_key_exists( $cmp, self::providers() ) ? $cmp : 'auto';
	}

	/**
	 * Indici dei purpose iubenda per categoria (configurabili: variano tra installazioni).
	 *
	 * @return array<string,int>
	 */
	public static function iubenda_purposes() {
		return array(
			'preferences' => max( 1, (int) get_option( 'ati_cg_iub_preferences', 3 ) ),
			'analytics'   => max( 1, (int) get_option( 'ati_cg_iub_analytics', 4 ) ),
			'marketing'   => max( 1, (int) get_option( 'ati_cg_iub_marketing', 5 ) ),
		);
	}

	/**
	 * Nomi dei cookie di consenso personalizzati, per categoria.
	 *
	 * Riusa le opzioni già esistenti per marketing e analytics.
	 *
	 * @return array<string,string>
	 */
	public static function custom_cookies() {
		return array(
			'marketing'   => trim( (string) get_option( 'ati_consent_cookie_name', '' ) ),
			'analytics'   => trim( (string) get_option( 'ati_analytics_cookie_name', '' ) ),
			'preferences' => trim( (string) get_option( 'ati_cg_preferences_cookie_name', '' ) ),
		);
	}

	/**
	 * Stato di consenso corrente, lato server (cookie della richiesta).
	 *
	 * @return array{marketing:bool,analytics:bool,preferences:bool,necessary:bool,provider:string,providers:array}
	 */
	public static function state() {
		$marketing = function_exists( 'ati_has_marketing_consent' ) ? (bool) ati_has_marketing_consent() : false;
		$analytics = function_exists( 'ati_has_analytics_consent' ) ? (bool) ati_has_analytics_consent() : false;

		$state = array(
			'necessary'   => true,
			'preferences' => self::detect_preferences(),
			'analytics'   => $analytics,
			'marketing'   => $marketing,
			'provider'    => self::configured_provider(),
			'providers'   => self::detected_providers(),
		);

		/**
		 * Filtra lo stato di consenso granulare usato dal blocco cookie.
		 *
		 * @param array $state Stato rilevato.
		 */
		return (array) apply_filters( 'ati_cookie_guard_consent_state', $state );
	}

	/**
	 * CMP realmente presenti nei cookie della richiesta.
	 *
	 * @return array<int,string>
	 */
	public static function detected_providers() {
		$found = array();

		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, '_iub_cs-' ) ) {
				$found[] = 'iubenda';
				break;
			}
		}
		if ( isset( $_COOKIE['cmplz_statistics'] ) || isset( $_COOKIE['cmplz_marketing'] ) || isset( $_COOKIE['cmplz_preferences'] ) || isset( $_COOKIE['cmplz_consent_status'] ) ) {
			$found[] = 'complianz';
		}
		if ( isset( $_COOKIE['CookieConsent'] ) ) {
			$found[] = 'cookiebot';
		}
		if ( isset( $_COOKIE['OptanonConsent'] ) ) {
			$found[] = 'onetrust';
		}
		foreach ( self::custom_cookies() as $cookie ) {
			if ( '' !== $cookie && isset( $_COOKIE[ $cookie ] ) ) {
				$found[] = 'custom';
				break;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Consenso "preferenze / funzionali".
	 *
	 * @return bool
	 */
	protected static function detect_preferences() {
		$provider = self::configured_provider();
		$custom   = self::custom_cookies();

		// Cookie personalizzato (vale sempre, anche con CMP forzato su "custom").
		if ( '' !== $custom['preferences'] && isset( $_COOKIE[ $custom['preferences'] ] ) && 'allow' === self::clean( $_COOKIE[ $custom['preferences'] ] ) ) {
			return true;
		}
		if ( 'custom' === $provider ) {
			return false;
		}

		// Complianz.
		if ( self::provider_allowed( $provider, 'complianz' ) ) {
			if ( isset( $_COOKIE['cmplz_preferences'] ) && 'allow' === self::clean( $_COOKIE['cmplz_preferences'] ) ) {
				return true;
			}
		}

		// iubenda.
		if ( self::provider_allowed( $provider, 'iubenda' ) ) {
			$purposes = self::iubenda_purposes();
			$idx      = $purposes['preferences'];
			foreach ( (array) $_COOKIE as $name => $value ) {
				if ( 0 !== strpos( (string) $name, '_iub_cs-' ) ) {
					continue;
				}
				$data = json_decode( self::clean( (string) $value ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				if ( isset( $data['consent'] ) && true === $data['consent'] ) {
					return true;
				}
				if ( isset( $data['purposes'] ) && is_array( $data['purposes'] ) ) {
					foreach ( array( $idx, (string) $idx ) as $key ) {
						if ( isset( $data['purposes'][ $key ] ) && true === $data['purposes'][ $key ] ) {
							return true;
						}
					}
				}
			}
		}

		// Cookiebot.
		if ( self::provider_allowed( $provider, 'cookiebot' ) && isset( $_COOKIE['CookieConsent'] ) ) {
			if ( false !== strpos( self::clean( (string) $_COOKIE['CookieConsent'] ), 'preferences:true' ) ) {
				return true;
			}
		}

		// OneTrust: C0003 = Functional.
		if ( self::provider_allowed( $provider, 'onetrust' ) && isset( $_COOKIE['OptanonConsent'] ) ) {
			if ( preg_match( '/(?:^|&)groups=([^&]*)/', self::clean( (string) $_COOKIE['OptanonConsent'] ), $m ) ) {
				if ( false !== strpos( $m[1], 'C0003:1' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Il provider è utilizzabile con la configurazione corrente?
	 *
	 * @param string $configured CMP configurato.
	 * @param string $candidate  CMP da valutare.
	 * @return bool
	 */
	protected static function provider_allowed( $configured, $candidate ) {
		return 'auto' === $configured || $configured === $candidate;
	}

	/**
	 * Normalizza un valore di $_COOKIE (WordPress applica magic-quotes ai superglobali).
	 *
	 * @param string $value Valore grezzo.
	 * @return string
	 */
	protected static function clean( $value ) {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $value ) : stripslashes( (string) $value );
		// I CMP salvano JSON url-encoded: decodifica best-effort, senza rompere il testo semplice.
		if ( false !== strpos( $value, '%' ) ) {
			$decoded = rawurldecode( $value );
			if ( '' !== $decoded ) {
				$value = $decoded;
			}
		}
		return $value;
	}
}
