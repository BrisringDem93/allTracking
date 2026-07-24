<?php
/**
 * Disinstallazione plugin Quick Tracking Integration.
 *
 * Rimuove TUTTE le opzioni registrate (legacy + GA4 server-side), la tabella della
 * coda eventi GA4 e gli eventi cron. La tabella dei cookie utente e le opzioni Meta/n8n
 * vengono anch'esse rimosse. Regole documentate in docs/ga4-configuration.md.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	// Legacy / Meta / n8n / client tags.
	'ati_fb_pixel_id',
	'ati_ga4_id',
	'ati_gtm_id',
	'ati_enable_fb',
	'ati_enable_ga4',
	'ati_enable_gtm',
	'ati_disable_logged_in',
	'ati_consent_cookie_name',
	'ati_consent_custom_event',
	'ati_server_endpoint',
	'ati_server_auth_key',
	'ati_server_auth_value',
	// GA4 server-side.
	'ati_ga4_server_id',
	'ati_enable_ga4_server',
	'ati_ga4_api_secret',
	'ati_ga4_confirmed_enabled',
	'ati_ga4_server_pageview',
	'ati_ga4_region',
	'ati_ga4_analytics_consent_mode',
	'ati_ga4_missing_cid_policy',
	'ati_ga4_dedup_ttl',
	'ati_ga4_enable_submit_attempt',
	'ati_ga4_form_map',
	'ati_ga4_token_salt',
	'ati_ga4_schema_version',
	'ati_analytics_cookie_name',
	'ati_class_business_area',
	'ati_class_service_type',
	'ati_class_audience_type',
	'ati_class_site_section',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Rimuove le tabelle personalizzate del plugin.
$tables = array(
	$wpdb->prefix . 'ati_event_queue',
	$wpdb->prefix . 'fst_user_cookies',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
}

// Rimuove gli eventi cron pianificati.
wp_clear_scheduled_hook( 'ati_ga4_cron_tick' );
wp_clear_scheduled_hook( 'ati_ga4_process_queue' );
