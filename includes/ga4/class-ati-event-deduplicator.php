<?php
/**
 * ATI_Event_Deduplicator — Deduplica interna WordPress.
 *
 * Chiave: event_id + event_name + destination. TTL configurabile (default 24h).
 * L'atomicità è garantita dal vincolo UNIQUE della tabella coda; questa classe
 * fornisce il controllo esplicito e la semantica del TTL.
 *
 * NOTA: event_id è trattato come parametro diagnostico e chiave interna del
 * plugin, NON come meccanismo nativo di deduplica GA4 (che non esiste come per Meta).
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deduplicatore.
 */
class ATI_Event_Deduplicator {

	/**
	 * L'evento è già stato visto (accodato/inviato) entro il TTL?
	 *
	 * @param string $event_id    Event id.
	 * @param string $event_name  Nome evento.
	 * @param string $destination Destinazione.
	 * @return bool
	 */
	public static function is_duplicate( $event_id, $event_name, $destination = 'ga4' ) {
		global $wpdb;

		if ( '' === (string) $event_id ) {
			return false; // Senza event_id non si può deduplicare per chiave.
		}

		$table     = ATI_Event_Queue::table();
		$dedup_key = ATI_Event_Queue::dedup_key( $event_id, $event_name, $destination );
		$ttl       = ATI_GA4_Config::dedup_ttl();
		$since     = gmdate( 'Y-m-d H:i:s', time() - $ttl );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE dedup_key=%s AND created_at >= %s LIMIT 1",
				$dedup_key,
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL

		return ! empty( $found );
	}
}
