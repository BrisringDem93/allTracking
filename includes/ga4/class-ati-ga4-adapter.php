<?php
/**
 * ATI_GA4_Adapter — Destination adapter per GA4 Measurement Protocol.
 *
 * Costruisce il payload GA4 completo a partire dal modello evento neutrale,
 * lo invia all'endpoint di raccolta oppure lo valida sull'endpoint di debug.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter GA4.
 */
class ATI_GA4_Adapter {

	const DESTINATION = 'ga4';

	/**
	 * Costruisce il payload GA4 Measurement Protocol da un ATI_Event.
	 *
	 * @param ATI_Event $event      Evento neutrale.
	 * @param bool      $debug_mode Aggiunge debug_mode (solo per test/validazione).
	 * @return array
	 */
	public static function build_payload( ATI_Event $event, $debug_mode = false ) {
		$params = array();

		// Parametri di sessione/engagement richiesti da GA4 MP.
		if ( '' !== $event->session_id ) {
			$params['session_id'] = $event->session_id;
		}
		$params['engagement_time_msec'] = max( 1, (int) $event->engagement_time_msec );

		// Contesto pagina.
		if ( '' !== $event->page_location ) {
			$params['page_location'] = $event->page_location;
		}
		if ( '' !== $event->page_title ) {
			$params['page_title'] = $event->page_title;
		}
		if ( '' !== $event->page_referrer ) {
			$params['page_referrer'] = $event->page_referrer;
		}

		// Parametri commerciali (già slug sanitizzati, no PII).
		foreach ( $event->params as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$params[ $k ] = $v;
			}
		}

		// event_id come parametro diagnostico / chiave interna del plugin.
		if ( '' !== $event->event_id ) {
			$params['event_id'] = $event->event_id;
		}

		if ( $debug_mode ) {
			$params['debug_mode'] = 1;
		}

		/**
		 * Filtra i parametri dell'evento GA4 prima dell'invio.
		 *
		 * @param array     $params Parametri.
		 * @param ATI_Event $event  Evento.
		 */
		$params = apply_filters( 'ati_ga4_event_params', $params, $event );

		$payload = array(
			'client_id'       => $event->client_id,
			'timestamp_micros' => (int) $event->event_timestamp_micros,
			'events'          => array(
				array(
					'name'   => $event->event_name,
					'params' => $params,
				),
			),
		);

		if ( '' === $payload['client_id'] ) {
			// Non deve accadere in produzione: il chiamante gestisce il degrado a monte.
			unset( $payload['client_id'] );
		}

		return $payload;
	}

	/**
	 * Invia l'evento all'endpoint di raccolta di produzione.
	 *
	 * @param ATI_Event $event Evento.
	 * @return array{ok:bool,code:int,error:string}
	 */
	public static function send( ATI_Event $event ) {
		// Guardia difensiva: senza client_id reale non si invia nulla e non si
		// inventa un'identità GA4. Il chiamante (coda) mappa questo caso a 'discarded'.
		if ( '' === (string) $event->client_id ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => 'missing_client_id',
			);
		}

		$measurement_id = ATI_GA4_Config::measurement_id();
		$api_secret     = ATI_GA4_Config::api_secret();

		if ( '' === $measurement_id || '' === $api_secret ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => 'missing_configuration',
			);
		}

		$url     = ATI_GA4_Endpoints::collect_url( $measurement_id, $api_secret );
		$payload = self::build_payload( $event, false );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 8,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => self::redact_error( $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// GA4 MP collect risponde 2xx (tipicamente 204) senza corpo.
		$ok = ( $code >= 200 && $code < 300 );

		return array(
			'ok'    => $ok,
			'code'  => $code,
			'error' => $ok ? '' : 'http_' . $code,
		);
	}

	/**
	 * Valida un evento sull'endpoint di debug GA4 (non crea conversioni).
	 *
	 * @param ATI_Event $event Evento.
	 * @return array{ok:bool,code:int,messages:array,error:string}
	 */
	public static function validate( ATI_Event $event ) {
		$measurement_id = ATI_GA4_Config::measurement_id();
		$api_secret     = ATI_GA4_Config::api_secret();

		if ( '' === $measurement_id || '' === $api_secret ) {
			return array(
				'ok'       => false,
				'code'     => 0,
				'messages' => array(),
				'error'    => 'missing_configuration',
			);
		}

		$url     = ATI_GA4_Endpoints::validation_url( $measurement_id, $api_secret );
		$payload = self::build_payload( $event, true );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 8,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'       => false,
				'code'     => 0,
				'messages' => array(),
				'error'    => self::redact_error( $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$messages = ( is_array( $body ) && isset( $body['validationMessages'] ) && is_array( $body['validationMessages'] ) )
			? $body['validationMessages']
			: array();

		return array(
			'ok'       => ( $code >= 200 && $code < 300 && empty( $messages ) ),
			'code'     => $code,
			'messages' => $messages,
			'error'    => '',
		);
	}

	/**
	 * Rimuove eventuali segreti/URL con secret dai messaggi di errore.
	 *
	 * @param string $message Messaggio.
	 * @return string
	 */
	protected static function redact_error( $message ) {
		$message = (string) $message;
		$message = preg_replace( '/api_secret=[^&\s]+/i', 'api_secret=***', $message );
		return substr( $message, 0, 300 );
	}
}
