<?php
/**
 * ATI_Cookie_Guard — Applicazione del blocco cookie.
 *
 * Due livelli, entrambi opzionali e disattivati di default:
 *
 * 1. BROWSER (principale): `assets/js/cookie-guard.js` viene stampato inline in
 *    `wp_head` a priorità 0 — prima di GTM (priorità 1) e di qualunque script
 *    accodato — perché deve sostituire il setter di `document.cookie` PRIMA che
 *    un pixel possa scrivere. Per questo non usa wp_enqueue_script (che stampa
 *    troppo tardi).
 * 2. SERVER (opzionale): cancella via `Set-Cookie` scaduto i cookie già presenti
 *    nella richiesta che violano le regole. Utile per i cookie scritti da header
 *    HTTP, che il JavaScript non può intercettare.
 *
 * @package QuickTrackingIntegration\CookieGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforcement del blocco cookie.
 */
class ATI_Cookie_Guard {

	/**
	 * Modalità: nessun intervento.
	 */
	const MODE_OFF = 'off';

	/**
	 * Modalità: rileva e logga in console, senza bloccare né cancellare.
	 */
	const MODE_MONITOR = 'monitor';

	/**
	 * Modalità: blocca e cancella.
	 */
	const MODE_ENFORCE = 'enforce';

	/**
	 * Modalità predefinita: il blocco è ATTIVO appena il plugin è installato, con le
	 * regole predefinite (ATI_Cookie_Rules::default_rules()). Si disattiva dal tab
	 * "Blocco Cookie" scegliendo un'altra modalità.
	 */
	const MODE_DEFAULT = self::MODE_ENFORCE;

	/**
	 * Modalità configurata.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = (string) get_option( 'ati_cg_mode', self::MODE_DEFAULT );
		return in_array( $mode, array( self::MODE_OFF, self::MODE_MONITOR, self::MODE_ENFORCE ), true ) ? $mode : self::MODE_DEFAULT;
	}

	/**
	 * Etichette delle modalità.
	 *
	 * @return array<string,string>
	 */
	public static function mode_labels() {
		return array(
			self::MODE_OFF     => 'Disattivato — nessun intervento sui cookie',
			self::MODE_MONITOR => 'Monitoraggio — rileva e logga in console, non blocca nulla',
			self::MODE_ENFORCE => 'Attivo — blocca la scrittura e cancella i cookie non consentiti',
		);
	}

	/**
	 * Il guard deve agire in questa richiesta?
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( self::MODE_OFF === self::mode() ) {
			return false;
		}
		if ( '1' === (string) get_option( 'ati_cg_skip_logged_in', '1' ) && is_user_logged_in() ) {
			return false;
		}
		if ( ! ATI_Cookie_Rules::active_rules() ) {
			return false;
		}

		/**
		 * Consente di disattivare il blocco cookie su specifiche richieste.
		 *
		 * @param bool $active Stato calcolato.
		 */
		return (bool) apply_filters( 'ati_cookie_guard_active', true );
	}

	/**
	 * Configurazione passata allo script (front-end o admin).
	 *
	 * @param array $overrides Sovrascritture (es. mode/expose per l'anteprima admin).
	 * @return array
	 */
	public static function script_config( $overrides = array() ) {
		$events = array();
		foreach ( array( get_option( 'ati_consent_custom_event', '' ), get_option( 'ati_cg_consent_event', '' ) ) as $event ) {
			$event = trim( (string) $event );
			if ( '' !== $event && ! in_array( $event, $events, true ) ) {
				$events[] = $event;
			}
		}

		$config = array(
			'enabled'       => true,
			'mode'          => self::mode(),
			'expose'        => false,
			'debug'         => ( '1' === (string) get_option( 'ati_cg_debug', '0' ) ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'rules'         => array_values( ATI_Cookie_Rules::active_rules() ),
			'allowlist'     => array_values( ATI_Cookie_Rules::allowlist_patterns() ),
			'cmp'           => ATI_Cookie_Consent::configured_provider(),
			'customCookies' => ATI_Cookie_Consent::custom_cookies(),
			'iubPurposes'   => ATI_Cookie_Consent::iubenda_purposes(),
			'consentEvents' => $events,
			'sweepInterval' => max( 250, (int) get_option( 'ati_cg_sweep_interval', 2000 ) ),
			'sweepDuration' => max( 0, (int) get_option( 'ati_cg_sweep_duration', 60000 ) ),
			'v'             => defined( 'ATI_PLUGIN_VERSION' ) ? ATI_PLUGIN_VERSION : '',
		);

		if ( is_array( $overrides ) ) {
			$config = array_merge( $config, $overrides );
		}

		/**
		 * Filtra la configurazione del blocco cookie inviata al browser.
		 *
		 * @param array $config Configurazione.
		 */
		return (array) apply_filters( 'ati_cookie_guard_script_config', $config );
	}

	/**
	 * Percorso del file JS del guard.
	 *
	 * @return string
	 */
	public static function script_path() {
		return dirname( __DIR__, 2 ) . '/assets/js/cookie-guard.js';
	}

	/**
	 * URL del file JS del guard.
	 *
	 * @return string
	 */
	public static function script_url() {
		return plugins_url( 'assets/js/cookie-guard.js', dirname( __DIR__, 2 ) . '/plugin.php' );
	}

	/**
	 * Sorgente del guard (letta una sola volta per richiesta).
	 *
	 * @return string Codice JS, stringa vuota se il file non è leggibile.
	 */
	public static function script_source() {
		static $source = null;
		if ( null !== $source ) {
			return $source;
		}
		$path   = self::script_path();
		$source = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $source;
	}

	/**
	 * Stampa il guard inline il più presto possibile dentro <head>.
	 *
	 * @return void
	 */
	public static function print_guard() {
		if ( ! self::is_active() ) {
			return;
		}
		$source = self::script_source();
		if ( '' === $source ) {
			return;
		}

		echo "<!-- Quick Tracking Integration: blocco cookie -->\n";
		echo '<script id="ati-cookie-guard">' . "\n";
		echo 'window.atiCookieGuard = ' . wp_json_encode( self::script_config() ) . ";\n";
		// Sorgente del plugin, non input utente: nessun escape (romperebbe il JS).
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput
		echo "\n</script>\n";
	}

	/**
	 * Pulizia lato server: invalida i cookie della richiesta che violano le regole.
	 *
	 * Intercetta anche i cookie scritti da header HTTP (che il JS non vede). Deve
	 * girare prima di qualunque output, quindi è agganciata a `init`.
	 *
	 * @return void
	 */
	public static function server_cleanup() {
		if ( self::MODE_ENFORCE !== self::mode() ) {
			return;
		}
		if ( '1' !== (string) get_option( 'ati_cg_server_cleanup', '0' ) ) {
			return;
		}
		if ( ! self::is_active() || is_admin() || headers_sent() ) {
			return;
		}
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return;
		}

		$consent   = ATI_Cookie_Consent::state();
		$rules     = ATI_Cookie_Rules::active_rules();
		$allowlist = ATI_Cookie_Rules::allowlist_patterns();
		$domains   = self::cookie_domains();

		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			$eval = ATI_Cookie_Rules::evaluate( $name, '', $consent, $rules, $allowlist );
			if ( ! ATI_Cookie_Rules::should_delete( $eval ) ) {
				continue;
			}

			// Il dominio esatto non è noto lato server: si invalida su tutte le varianti.
			foreach ( $domains as $domain ) {
				if ( '' === $domain ) {
					setcookie( $name, '', time() - DAY_IN_SECONDS, '/' );
					continue;
				}
				setcookie( $name, '', time() - DAY_IN_SECONDS, '/', $domain );
			}
			unset( $_COOKIE[ $name ] );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[ATI COOKIE GUARD] Cookie cancellato lato server: ' . $name );
			}
		}
	}

	/**
	 * Varianti di dominio su cui tentare la cancellazione di un cookie.
	 *
	 * @return array<int,string> '' = host-only, poi host e domini padre fino al dominio registrabile.
	 */
	public static function cookie_domains() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$host = preg_replace( '/:\d+$/', '', (string) $host );
		$host = strtolower( trim( $host, '.' ) );

		$domains = array( '' );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $domains;
		}

		$parts = explode( '.', $host );
		$count = count( $parts );
		// Dal dominio completo fino a quello registrabile (ultime due etichette).
		for ( $i = 0; $i <= $count - 2; $i++ ) {
			$candidate = implode( '.', array_slice( $parts, $i ) );
			$domains[] = '.' . $candidate;
		}

		return array_values( array_unique( $domains ) );
	}
}
