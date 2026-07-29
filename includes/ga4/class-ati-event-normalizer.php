<?php
/**
 * ATI_Event_Normalizer — Costruisce il modello evento neutrale.
 *
 * Applica classificazione commerciale, calcola i campi GA4 (timestamp_micros,
 * engagement_time_msec), rileva il consenso e — soprattutto — rimuove ogni PII
 * tramite una allowlist rigida di parametri.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizzatore eventi.
 */
class ATI_Event_Normalizer {

	/**
	 * Parametri commerciali ammessi nel payload GA4. Tutto il resto è scartato.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_params() {
		$allowed = array(
			'business_area',
			'service_type',
			'audience_type',
			'site_section',
			'form_id',
			'form_name',
		);
		/**
		 * Filtra la allowlist dei parametri commerciali.
		 *
		 * @param array $allowed Chiavi ammesse.
		 */
		return (array) apply_filters( 'ati_ga4_allowed_params', $allowed );
	}

	/**
	 * Chiavi note come PII: rimosse sempre, anche se aggiunte via filtro.
	 *
	 * @return array<int,string>
	 */
	public static function pii_denylist() {
		return array(
			'email', 'em', 'e_mail', 'mail',
			'phone', 'ph', 'tel', 'telephone', 'mobile',
			'first_name', 'last_name', 'fn', 'ln', 'name', 'nome', 'cognome',
			'address', 'indirizzo', 'city', 'zip', 'cap',
			'message', 'messaggio', 'note', 'notes', 'text',
			'fbp', 'fbc', 'external_id', 'ip', 'client_ip_address', 'user_agent',
		);
	}

	/**
	 * Normalizza input grezzo di un lead confermato in un ATI_Event.
	 *
	 * @param string $event_name Nome evento neutrale (es. generate_lead).
	 * @param array  $params     Parametri grezzi (possono contenere PII: verranno rimossi).
	 * @param array  $context    Contesto: client_id, session_id, page_*, provider, form_id, ecc.
	 * @return ATI_Event
	 */
	public static function normalize( $event_name, array $params = array(), array $context = array() ) {
		$event = new ATI_Event();

		$event->event_name = self::sanitize_event_name( $event_name );
		$event->source     = isset( $context['source'] ) ? sanitize_key( $context['source'] ) : 'server_confirmed';

		// event_id: usa quello fornito o generane uno diagnostico.
		$event->event_id = isset( $context['event_id'] ) && '' !== (string) $context['event_id']
			? self::sanitize_event_id( (string) $context['event_id'] )
			: self::generate_event_id();

		// Timestamp micros.
		$event->event_timestamp_micros = isset( $context['event_timestamp_micros'] ) && (int) $context['event_timestamp_micros'] > 0
			? (int) $context['event_timestamp_micros']
			: (int) round( microtime( true ) * 1000000 );

		// Identità analytics (client_id/session_id reali dal Google Tag).
		$event->client_id  = isset( $context['client_id'] ) ? self::sanitize_id( (string) $context['client_id'] ) : '';
		$event->session_id = isset( $context['session_id'] ) ? self::sanitize_id( (string) $context['session_id'] ) : '';

		$event->engagement_time_msec = isset( $context['engagement_time_msec'] )
			? max( 1, (int) $context['engagement_time_msec'] )
			: 1;

		// Contesto pagina.
		$event->page_location = isset( $context['page_location'] ) ? esc_url_raw( (string) $context['page_location'] ) : '';
		$event->page_title    = isset( $context['page_title'] ) ? sanitize_text_field( (string) $context['page_title'] ) : '';
		$event->page_referrer = isset( $context['page_referrer'] ) ? esc_url_raw( (string) $context['page_referrer'] ) : '';

		// Consenso.
		$event->consent['analytics'] = ATI_Consent_Service::has_analytics_consent();
		$event->consent['marketing'] = ATI_Consent_Service::has_marketing_consent();

		// Attribuzione degradata se manca il client_id reale.
		$event->degraded_attribution = ( '' === $event->client_id );

		// Classificazione commerciale.
		$provider = isset( $context['provider'] ) ? (string) $context['provider'] : '';
		$form_id  = isset( $context['form_id'] ) ? (string) $context['form_id'] : '';
		$classification = ATI_Project_Classification::resolve( $provider, $form_id, $context );

		// Parametri: allowlist + rimozione PII.
		$merged = array_merge( $classification, self::filter_params( $params ) );
		$event->params = self::filter_params( $merged );

		return $event;
	}

	/**
	 * Applica allowlist e denylist PII ai parametri.
	 *
	 * @param array $params Parametri grezzi.
	 * @return array<string,scalar>
	 */
	public static function filter_params( array $params ) {
		$allowed = array_map( 'strtolower', self::allowed_params() );
		$deny    = array_map( 'strtolower', self::pii_denylist() );
		$clean   = array();

		foreach ( $params as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( in_array( $key, $deny, true ) ) {
				continue; // Mai PII.
			}
			if ( ! in_array( $key, $allowed, true ) ) {
				continue; // Fuori allowlist: scartato.
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$clean[ $key ] = ATI_Project_Classification::sanitize_slug( (string) $value );
		}

		return $clean;
	}

	/**
	 * Nomi evento GA4: snake_case, [a-z0-9_], max 40 char.
	 *
	 * @param string $name Nome.
	 * @return string
	 */
	public static function sanitize_event_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name );
		$name = trim( (string) $name, '_' );
		if ( strlen( $name ) > 40 ) {
			$name = substr( $name, 0, 40 );
		}
		return '' === $name ? 'custom_event' : $name;
	}

	/**
	 * Sanifica event_id diagnostico.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function sanitize_event_id( $id ) {
		$id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
		return substr( (string) $id, 0, 100 );
	}

	/**
	 * Sanifica client_id/session_id (cifre, punto, trattino).
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		$id = preg_replace( '/[^0-9A-Za-z._\-]/', '', (string) $id );
		return substr( (string) $id, 0, 100 );
	}

	/**
	 * Genera un event_id diagnostico interno.
	 *
	 * @return string
	 */
	public static function generate_event_id() {
		return 'evt_' . time() . '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}
}
