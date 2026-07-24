<?php
/**
 * ATI_Event — Modello evento interno neutrale.
 *
 * Neutrale rispetto a GA4/Meta/n8n. NON deve mai contenere PII
 * (email, telefono, nome, indirizzo, testo libero, hash di dati personali).
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object che rappresenta un evento di business normalizzato.
 */
class ATI_Event {

	/** @var string Identificatore diagnostico/chiave interna del plugin (evt_...). */
	public $event_id = '';

	/** @var string Nome evento neutrale (es. generate_lead). */
	public $event_name = '';

	/** @var int Timestamp in microsecondi (GA4 timestamp_micros). */
	public $event_timestamp_micros = 0;

	/**
	 * Origine dell'evento.
	 * server_confirmed | client_bridge | server_pageview | manual
	 *
	 * @var string
	 */
	public $source = 'server_confirmed';

	/** @var string */
	public $page_location = '';

	/** @var string */
	public $page_title = '';

	/** @var string */
	public $page_referrer = '';

	/** @var string client_id GA4 reale (dal Google Tag) quando disponibile. */
	public $client_id = '';

	/** @var string session_id GA4 reale (dal Google Tag) quando disponibile. */
	public $session_id = '';

	/** @var int Tempo di engagement in ms (GA4 engagement_time_msec). */
	public $engagement_time_msec = 1;

	/**
	 * Stato del consenso rilevato.
	 *
	 * @var array{analytics:bool,marketing:bool}
	 */
	public $consent = array(
		'analytics' => false,
		'marketing' => false,
	);

	/**
	 * Parametri commerciali (solo slug/valori non-PII).
	 *
	 * @var array<string,scalar>
	 */
	public $params = array();

	/**
	 * Stato dell'invio, per destinazione. Es. array('ga4' => 'pending').
	 *
	 * @var array<string,string>
	 */
	public $delivery = array();

	/**
	 * Flag: attribuzione degradata (client_id/session_id non disponibili).
	 *
	 * @var bool
	 */
	public $degraded_attribution = false;

	/**
	 * Crea un evento a partire da un array associativo (idempotente e difensivo).
	 *
	 * @param array $data Dati grezzi.
	 * @return ATI_Event
	 */
	public static function from_array( array $data ) {
		$event = new self();

		$event->event_id               = isset( $data['event_id'] ) ? (string) $data['event_id'] : '';
		$event->event_name             = isset( $data['event_name'] ) ? (string) $data['event_name'] : '';
		$event->event_timestamp_micros = isset( $data['event_timestamp_micros'] ) ? (int) $data['event_timestamp_micros'] : 0;
		$event->source                 = isset( $data['source'] ) ? (string) $data['source'] : 'server_confirmed';
		$event->page_location          = isset( $data['page_location'] ) ? (string) $data['page_location'] : '';
		$event->page_title             = isset( $data['page_title'] ) ? (string) $data['page_title'] : '';
		$event->page_referrer          = isset( $data['page_referrer'] ) ? (string) $data['page_referrer'] : '';
		$event->client_id              = isset( $data['client_id'] ) ? (string) $data['client_id'] : '';
		$event->session_id             = isset( $data['session_id'] ) ? (string) $data['session_id'] : '';

		if ( isset( $data['engagement_time_msec'] ) ) {
			$event->engagement_time_msec = max( 1, (int) $data['engagement_time_msec'] );
		}
		if ( isset( $data['consent'] ) && is_array( $data['consent'] ) ) {
			$event->consent['analytics'] = ! empty( $data['consent']['analytics'] );
			$event->consent['marketing'] = ! empty( $data['consent']['marketing'] );
		}
		if ( isset( $data['params'] ) && is_array( $data['params'] ) ) {
			$event->params = $data['params'];
		}
		if ( isset( $data['degraded_attribution'] ) ) {
			$event->degraded_attribution = (bool) $data['degraded_attribution'];
		}

		return $event;
	}

	/**
	 * Serializza in array (per coda/log). Non contiene PII per costruzione.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'event_id'               => $this->event_id,
			'event_name'             => $this->event_name,
			'event_timestamp_micros' => $this->event_timestamp_micros,
			'source'                 => $this->source,
			'page_location'          => $this->page_location,
			'page_title'             => $this->page_title,
			'page_referrer'          => $this->page_referrer,
			'client_id'              => $this->client_id,
			'session_id'             => $this->session_id,
			'engagement_time_msec'   => $this->engagement_time_msec,
			'consent'                => $this->consent,
			'params'                 => $this->params,
			'degraded_attribution'   => $this->degraded_attribution,
		);
	}
}
