<?php
/**
 * API pubblica del sottosistema GA4 server-side.
 *
 * Punto d'ingresso stabile per gli eventi confermati e helper documentati.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ati_track_confirmed_event' ) ) {
	/**
	 * Traccia un evento di business confermato verso GA4 (server-side first).
	 *
	 * Pipeline: normalizza -> verifica consenso analytics -> deduplica -> accoda.
	 * L'invio effettivo avviene in modo asincrono (coda), senza bloccare il lead.
	 *
	 * @param string $event_name Nome evento neutrale (es. 'generate_lead').
	 * @param array  $params     Parametri commerciali (eventuali PII vengono rimosse).
	 * @param array  $context    Contesto: client_id, session_id, page_location, page_title,
	 *                           page_referrer, engagement_time_msec, event_id, provider, form_id, source.
	 * @return array{status:string,event_id:string,reason:string}
	 */
	function ati_track_confirmed_event( $event_name, array $params = array(), array $context = array() ) {
		$result = array(
			'status'   => 'skipped',
			'event_id' => '',
			'reason'   => '',
		);

		// La pipeline server-confirmed deve essere abilitata dall'amministratore.
		if ( ! ATI_GA4_Config::confirmed_enabled() ) {
			$result['reason'] = 'pipeline_disabled';
			return $result;
		}

		if ( ! ATI_GA4_Config::is_ready() ) {
			$result['reason'] = 'not_configured';
			ati_ga4_log( 'confirmed_event_not_configured', array( 'event' => sanitize_key( $event_name ) ) );
			return $result;
		}

		$event               = ATI_Event_Normalizer::normalize( $event_name, $params, $context );
		$result['event_id']  = $event->event_id;

		/**
		 * Consente di bloccare un evento prima dell'invio.
		 *
		 * @param bool      $allowed Consentito.
		 * @param ATI_Event $event   Evento.
		 */
		$allowed = apply_filters( 'ati_event_allowed', true, $event );
		if ( ! $allowed ) {
			$result['status'] = 'blocked';
			$result['reason'] = 'filtered';
			return $result;
		}

		// Consenso analytics obbligatorio per GA4 Measurement Protocol.
		if ( empty( $event->consent['analytics'] ) ) {
			$result['status'] = 'no_consent';
			$result['reason'] = 'analytics_consent_missing';
			ati_ga4_log( 'confirmed_event_no_analytics_consent', array( 'event' => $event->event_name ) );
			return $result;
		}

		// Attribuzione degradata (client_id assente): comportamento configurabile.
		if ( $event->degraded_attribution ) {
			ati_ga4_log(
				'confirmed_event_degraded_attribution',
				array(
					'event'  => $event->event_name,
					'policy' => ATI_GA4_Config::missing_client_id_policy(),
				)
			);
			if ( 'discard' === ATI_GA4_Config::missing_client_id_policy() ) {
				$result['status'] = 'discarded';
				$result['reason'] = 'missing_client_id';
				return $result;
			}
			// Politica 'queue': si accoda comunque, senza inventare un client_id casuale.
			// GA4 MP scarterà l'evento senza client_id, ma l'evento resta tracciato in coda
			// per diagnosi, senza degradare l'attribuzione con ID fittizi.
		}

		// Deduplica esplicita (backstop: UNIQUE key in coda).
		if ( ATI_Event_Deduplicator::is_duplicate( $event->event_id, $event->event_name, ATI_GA4_Adapter::DESTINATION ) ) {
			$result['status'] = 'duplicate';
			$result['reason'] = 'already_seen';
			return $result;
		}

		$enqueued = ATI_Event_Queue::enqueue( $event, ATI_GA4_Adapter::DESTINATION );
		if ( false === $enqueued ) {
			$result['status'] = 'duplicate';
			$result['reason'] = 'already_queued';
			return $result;
		}

		$result['status'] = 'queued';
		return $result;
	}
}

if ( ! function_exists( 'ati_ga4_log' ) ) {
	/**
	 * Logging diagnostico minimizzato e privo di PII.
	 *
	 * Registra solo con WP_DEBUG attivo. Non logga mai payload completi, cookie,
	 * IP, user agent, email, telefono, hash o segreti.
	 *
	 * @param string $code Codice diagnostico breve.
	 * @param array  $meta Metadati non-PII (chiavi/valori brevi).
	 * @return void
	 */
	function ati_ga4_log( $code, array $meta = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$safe = array();
		foreach ( $meta as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$safe[ sanitize_key( $k ) ] = substr( (string) $v, 0, 64 );
			}
		}
		error_log( '[ATI GA4] ' . sanitize_key( $code ) . ' ' . wp_json_encode( $safe ) );
	}
}
