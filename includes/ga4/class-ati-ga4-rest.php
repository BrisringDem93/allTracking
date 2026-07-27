<?php
/**
 * ATI_GA4_REST — Endpoint REST first-party per eventi GA4 confermati (bridge).
 *
 * Riceve dal bridge JavaScript il contesto GA4 (client_id/session_id/page) e i
 * parametri commerciali di un evento confermato. È accessibile ai visitatori
 * anonimi ma fortemente ristretto: schema, allowlist eventi/parametri, limiti di
 * dimensione/lunghezza, validazione hostname, rate limiting, token first-party
 * temporaneo, deduplica. Non registra PII né segreti nei log.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint REST GA4.
 */
class ATI_GA4_REST {

	const NAMESPACE       = 'ati/v1';
	const ROUTE           = '/ga4-lead';
	const MAX_BODY_BYTES  = 4096;
	const MAX_VALUE_LEN   = 200;
	const TOKEN_TTL       = 3600; // 1h.
	const RATE_LIMIT      = 30;   // Richieste per finestra.
	const RATE_WINDOW     = 300;  // 5 minuti.

	/**
	 * Registra la route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => self::args_schema(),
			)
		);
	}

	/**
	 * Schema REST degli argomenti (tipi + sanitizzazione).
	 *
	 * @return array
	 */
	public static function args_schema() {
		return array(
			'event'        => array(
				'type'              => 'string',
				'required'         => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'token'        => array(
				'type'              => 'string',
				'required'         => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event_id'     => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'client_id'    => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'session_id'   => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page_location' => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'page_title'   => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page_referrer' => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'engagement_time_msec' => array(
				'type'              => 'integer',
				'required'         => false,
				'sanitize_callback' => 'absint',
			),
			'form_id'      => array(
				'type'              => 'string',
				'required'         => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'params'       => array(
				'type'     => 'object',
				'required' => false,
			),
		);
	}

	/**
	 * Permission callback: rate limiting + verifica dimensione body.
	 * (La verifica del token avviene nell'handler per restituire messaggi coerenti.)
	 *
	 * @param WP_REST_Request $req Richiesta.
	 * @return bool|WP_Error
	 */
	public static function permission( $req ) {
		$body = $req->get_body();
		if ( strlen( (string) $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'ati_body_too_large', 'Payload too large', array( 'status' => 413 ) );
		}
		if ( self::is_rate_limited() ) {
			return new WP_Error( 'ati_rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Handler principale.
	 *
	 * @param WP_REST_Request $req Richiesta.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $req ) {
		// Utenti loggati esclusi se l'opzione è attiva.
		if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
			return self::response( 'disabled', 'tracking_disabled_logged_in', 200 );
		}

		// Token first-party.
		if ( ! self::verify_token( (string) $req->get_param( 'token' ) ) ) {
			return self::response( 'error', 'invalid_token', 403 );
		}

		// Allowlist eventi.
		$event = sanitize_key( (string) $req->get_param( 'event' ) );
		if ( ! in_array( $event, self::allowed_events(), true ) ) {
			return self::response( 'error', 'event_not_allowed', 422 );
		}

		// Validazione hostname del page_location.
		$page_location = (string) $req->get_param( 'page_location' );
		if ( '' !== $page_location && ! self::is_same_host( $page_location ) ) {
			return self::response( 'error', 'invalid_page_location', 422 );
		}

		// Parametri commerciali: solo allowlist (il normalizer applica anche la denylist PII).
		$raw_params = $req->get_param( 'params' );
		$params     = is_array( $raw_params ) ? self::truncate_values( $raw_params ) : array();

		// Contesto: preferisce i valori del bridge, con fallback ai cookie GA.
		$cookie_ctx = ATI_GA4_Client_Context::from_cookies();
		$client_id  = (string) $req->get_param( 'client_id' );
		$session_id = (string) $req->get_param( 'session_id' );

		$context = array(
			'event_id'             => (string) $req->get_param( 'event_id' ),
			'client_id'            => '' !== $client_id ? $client_id : $cookie_ctx['client_id'],
			'session_id'           => '' !== $session_id ? $session_id : $cookie_ctx['session_id'],
			'page_location'        => $page_location,
			'page_title'           => (string) $req->get_param( 'page_title' ),
			'page_referrer'        => (string) $req->get_param( 'page_referrer' ),
			'engagement_time_msec' => (int) $req->get_param( 'engagement_time_msec' ),
			'form_id'              => (string) $req->get_param( 'form_id' ),
			'provider'             => 'bridge',
			'source'               => 'client_bridge',
		);

		if ( function_exists( 'ati_ga4_log' ) ) {
			ati_ga4_log(
				'rest_event_received',
				array(
					'event'   => $event,
					'has_cid' => $context['client_id'] ? 1 : 0,
					'has_sid' => $context['session_id'] ? 1 : 0,
				)
			);
		}

		$outcome = ati_track_confirmed_event( $event, $params, $context );

		self::bump_rate_counter();

		return self::response( $outcome['status'], $outcome['reason'], 200 );
	}

	/**
	 * Eventi ammessi dall'endpoint.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_events() {
		$events = array( 'generate_lead' );
		if ( '1' === get_option( 'ati_ga4_enable_submit_attempt', '0' ) ) {
			$events[] = 'form_submit_attempt';
		}
		return (array) apply_filters( 'ati_ga4_rest_allowed_events', $events );
	}

	/**
	 * Tronca i valori dei parametri per limitarne la lunghezza.
	 *
	 * @param array $params Parametri.
	 * @return array
	 */
	protected static function truncate_values( array $params ) {
		$out = array();
		foreach ( $params as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$out[ (string) $k ] = substr( (string) $v, 0, self::MAX_VALUE_LEN );
			}
		}
		return $out;
	}

	/**
	 * Verifica che l'URL abbia lo stesso host del sito.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected static function is_same_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host && $home && strtolower( $host ) === strtolower( $home );
	}

	/**
	 * Genera un token first-party temporaneo (HMAC di una scadenza).
	 *
	 * @return string
	 */
	public static function issue_token() {
		$expiry = time() + self::TOKEN_TTL;
		$sig    = hash_hmac( 'sha256', 'ga4|' . $expiry, self::token_salt() );
		return $expiry . '.' . $sig;
	}

	/**
	 * Verifica un token first-party.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	protected static function verify_token( $token ) {
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}
		list( $expiry, $sig ) = explode( '.', $token, 2 );
		$expiry = (int) $expiry;
		if ( $expiry < time() ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', 'ga4|' . $expiry, self::token_salt() );
		return hash_equals( $expected, (string) $sig );
	}

	/**
	 * Salt server-side per la firma dei token (auto-generato, mai esposto).
	 *
	 * @return string
	 */
	protected static function token_salt() {
		$salt = get_option( 'ati_ga4_token_salt', '' );
		if ( '' === $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'ati_ga4_token_salt', $salt, false );
		}
		return (string) $salt;
	}

	/**
	 * Rate limit per IP (IP mai memorizzato in chiaro: solo hash).
	 *
	 * @return bool
	 */
	protected static function is_rate_limited() {
		$count = (int) get_transient( self::rate_key() );
		return $count >= self::RATE_LIMIT;
	}

	/**
	 * Incrementa il contatore di rate limiting.
	 *
	 * @return void
	 */
	protected static function bump_rate_counter() {
		$key   = self::rate_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	/**
	 * Chiave transient basata su hash dell'IP (nessun IP in chiaro).
	 *
	 * @return string
	 */
	protected static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
		return 'ati_ga4_rl_' . substr( hash_hmac( 'sha256', $ip, self::token_salt() ), 0, 24 );
	}

	/**
	 * Risposta REST coerente.
	 *
	 * @param string $status Stato.
	 * @param string $reason Motivo.
	 * @param int    $code   HTTP code.
	 * @return WP_REST_Response
	 */
	protected static function response( $status, $reason, $code ) {
		return new WP_REST_Response(
			array(
				'status' => $status,
				'reason' => $reason,
			),
			$code
		);
	}
}
