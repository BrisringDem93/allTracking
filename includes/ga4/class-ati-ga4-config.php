<?php
/**
 * ATI_GA4_Config — Accesso centralizzato a configurazione e segreti GA4.
 *
 * Il segreto API viene letto preferibilmente dalla costante ATI_GA4_API_SECRET
 * (definibile in wp-config.php), con fallback sicuro sulle opzioni WordPress.
 * Il valore non viene mai restituito al browser né loggato.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurazione GA4.
 */
class ATI_GA4_Config {

	/**
	 * Measurement ID per il Measurement Protocol.
	 * Usa l'ID server-side se impostato, altrimenti riusa quello del Google Tag
	 * (comportamento normale: stesso ID del flusso web).
	 *
	 * @return string
	 */
	public static function measurement_id() {
		$server_id = trim( (string) get_option( 'ati_ga4_server_id', '' ) );
		if ( '' !== $server_id ) {
			return $server_id;
		}
		return trim( (string) get_option( 'ati_ga4_id', '' ) );
	}

	/**
	 * API secret (costante prioritaria, poi opzione). Mai esposto.
	 *
	 * @return string
	 */
	public static function api_secret() {
		if ( defined( 'ATI_GA4_API_SECRET' ) && '' !== (string) ATI_GA4_API_SECRET ) {
			return (string) ATI_GA4_API_SECRET;
		}
		return trim( (string) get_option( 'ati_ga4_api_secret', '' ) );
	}

	/**
	 * Il segreto proviene da costante? (usato per messaggi admin, senza mostrare il valore).
	 *
	 * @return bool
	 */
	public static function secret_is_constant() {
		return defined( 'ATI_GA4_API_SECRET' ) && '' !== (string) ATI_GA4_API_SECRET;
	}

	/**
	 * È configurato un segreto (costante o opzione)?
	 *
	 * @return bool
	 */
	public static function has_secret() {
		return '' !== self::api_secret();
	}

	/**
	 * La nuova pipeline server-confirmed è abilitata dall'amministratore?
	 *
	 * @return bool
	 */
	public static function confirmed_enabled() {
		return '1' === get_option( 'ati_ga4_confirmed_enabled', '0' );
	}

	/**
	 * PageView server-side (modalità avanzata) abilitato?
	 *
	 * @return bool
	 */
	public static function server_pageview_enabled() {
		return '1' === get_option( 'ati_ga4_server_pageview', '0' );
	}

	/**
	 * debug_mode sugli invii di produzione (SOLO test: rende gli eventi server-side
	 * visibili in GA4 DebugView). Spento di default.
	 *
	 * @return bool
	 */
	public static function debug_mode_enabled() {
		return '1' === get_option( 'ati_ga4_debug_mode', '0' );
	}

	/**
	 * Trigger del lead confermato:
	 * - 'server' (default): solo hook PHP ufficiali dei provider (Elementor/Fluent recenti);
	 * - 'submit': il bridge client-side invia generate_lead all'invio del form (funziona
	 *   con tutti i form, incluse versioni non supportate; possibili falsi positivi su
	 *   invii non riusciti).
	 *
	 * @return string
	 */
	public static function lead_trigger() {
		$mode = get_option( 'ati_ga4_lead_trigger', 'server' );
		return ( 'submit' === $mode ) ? 'submit' : 'server';
	}

	/**
	 * Nome di un evento JS personalizzato che segnala l'invio riuscito di un form
	 * custom. Se impostato, il bridge (GA4) e il percorso Meta/n8n vi si agganciano.
	 * Il form deve emettere: document.dispatchEvent(new CustomEvent(nome, {detail:{form_id, email, phone}})).
	 *
	 * @return string
	 */
	public static function custom_success_event() {
		return trim( (string) get_option( 'ati_ga4_custom_success_event', '' ) );
	}

	/**
	 * Politica quando manca il client_id: 'queue' (default) o 'discard'.
	 *
	 * @return string
	 */
	public static function missing_client_id_policy() {
		$policy = get_option( 'ati_ga4_missing_cid_policy', 'queue' );
		return ( 'discard' === $policy ) ? 'discard' : 'queue';
	}

	/**
	 * TTL deduplica in secondi (default 24h).
	 *
	 * @return int
	 */
	public static function dedup_ttl() {
		$ttl = (int) get_option( 'ati_ga4_dedup_ttl', DAY_IN_SECONDS );
		return $ttl > 0 ? $ttl : DAY_IN_SECONDS;
	}

	/**
	 * Configurazione minima pronta per l'invio server-side?
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return '' !== self::measurement_id() && self::has_secret();
	}
}
