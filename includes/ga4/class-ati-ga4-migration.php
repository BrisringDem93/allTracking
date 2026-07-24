<?php
/**
 * ATI_GA4_Migration — Migrazione schema versionata, idempotente e non distruttiva.
 *
 * Preserva tutte le impostazioni esistenti. Le nuove funzionalità server-confirmed
 * restano DISABILITATE di default dopo l'aggiornamento.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestore migrazioni.
 */
class ATI_GA4_Migration {

	// v2: aggiunge la colonna reason_code alla tabella coda (dbDelta ALTER idempotente).
	const SCHEMA_VERSION = '2';
	const VERSION_OPTION = 'ati_ga4_schema_version';

	/**
	 * Default sicuri delle nuove opzioni (mai sovrascrive valori esistenti).
	 *
	 * @return array<string,string>
	 */
	public static function default_options() {
		return array(
			'ati_ga4_confirmed_enabled'     => '0',   // Server-confirmed OFF di default.
			'ati_ga4_server_pageview'       => '0',   // PageView server-side OFF di default.
			'ati_ga4_region'                => 'eu',  // Endpoint regionale EU di default.
			'ati_ga4_analytics_consent_mode' => 'auto',
			'ati_ga4_missing_cid_policy'    => 'queue',
			'ati_ga4_dedup_ttl'             => (string) DAY_IN_SECONDS,
			'ati_ga4_enable_submit_attempt' => '0',
			'ati_analytics_cookie_name'     => '',
			'ati_class_business_area'       => '',
			'ati_class_service_type'        => '',
			'ati_class_audience_type'       => '',
			'ati_class_site_section'        => '',
		);
	}

	/**
	 * Esegue la migrazione se necessario (idempotente).
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		$current = get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $current, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Applica lo schema e i default (una sola volta per versione).
	 *
	 * @return void
	 */
	public static function run() {
		// Tabella coda.
		if ( class_exists( 'ATI_Event_Queue' ) ) {
			ATI_Event_Queue::install_table();
		}

		// Default non distruttivi: add_option NON sovrascrive valori esistenti.
		foreach ( self::default_options() as $key => $value ) {
			add_option( $key, $value );
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );

		/**
		 * Migrazione GA4 completata.
		 *
		 * @param string $version Versione schema applicata.
		 */
		do_action( 'ati_ga4_migrated', self::SCHEMA_VERSION );
	}

	/**
	 * Hook di attivazione del plugin.
	 *
	 * @return void
	 */
	public static function on_activate() {
		self::run();
	}
}
