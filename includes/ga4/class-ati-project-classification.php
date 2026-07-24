<?php
/**
 * ATI_Project_Classification — Risoluzione della classificazione commerciale.
 *
 * Priorità dei valori:
 *   1. mapping specifico del form
 *   2. filtri WordPress
 *   3. configurazione globale
 *   4. fallback documentato
 *
 * Tutti i valori sono slug sanitizzati, senza PII, compatibili con GA4.
 *
 * @package QuickTrackingIntegration\GA4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classificazione del progetto e mapping per form.
 */
class ATI_Project_Classification {

	const KEYS      = array( 'business_area', 'service_type', 'audience_type', 'site_section' );
	const MAX_LEN   = 100; // Compatibile con i limiti dei parametri evento GA4.
	const FALLBACK  = 'not_set';

	/**
	 * Risolve i parametri commerciali per un dato provider/form.
	 *
	 * @param string $provider Provider del form (es. html, elementor).
	 * @param string $form_id  Identificativo del form.
	 * @param array  $context  Contesto aggiuntivo.
	 * @return array<string,string> Mappa chiave => slug.
	 */
	public static function resolve( $provider = '', $form_id = '', array $context = array() ) {
		$mapping = self::get_form_mapping( $provider, $form_id );
		$global  = self::global_defaults();

		$resolved = array();
		foreach ( self::KEYS as $key ) {
			$value = '';
			if ( isset( $mapping[ $key ] ) && '' !== (string) $mapping[ $key ] ) {
				$value = (string) $mapping[ $key ]; // 1. mapping form.
			} elseif ( isset( $global[ $key ] ) && '' !== (string) $global[ $key ] ) {
				$value = (string) $global[ $key ]; // 3. globale.
			} else {
				$value = self::FALLBACK; // 4. fallback.
			}
			$resolved[ $key ] = self::sanitize_slug( $value );
		}

		if ( '' !== (string) $form_id ) {
			$resolved['form_id'] = self::sanitize_slug( (string) $form_id );
		}
		if ( isset( $mapping['form_name'] ) && '' !== (string) $mapping['form_name'] ) {
			$resolved['form_name'] = self::sanitize_slug( (string) $mapping['form_name'] );
		} elseif ( isset( $context['form_name'] ) && '' !== (string) $context['form_name'] ) {
			$resolved['form_name'] = self::sanitize_slug( (string) $context['form_name'] );
		}

		/**
		 * Filtra la classificazione risolta (priorità 2).
		 *
		 * @param array  $resolved Mappa risolta.
		 * @param string $provider Provider.
		 * @param string $form_id  Form id.
		 * @param array  $context  Contesto.
		 */
		$resolved = apply_filters( 'ati_project_classification', $resolved, $provider, $form_id, $context );

		// Ri-sanifica dopo i filtri (difesa in profondità).
		foreach ( $resolved as $k => $v ) {
			$resolved[ $k ] = self::sanitize_slug( (string) $v );
		}

		return $resolved;
	}

	/**
	 * Valori globali dalle opzioni.
	 *
	 * @return array<string,string>
	 */
	public static function global_defaults() {
		return array(
			'business_area' => (string) get_option( 'ati_class_business_area', '' ),
			'service_type'  => (string) get_option( 'ati_class_service_type', '' ),
			'audience_type' => (string) get_option( 'ati_class_audience_type', '' ),
			'site_section'  => (string) get_option( 'ati_class_site_section', '' ),
		);
	}

	/**
	 * Recupera il mapping specifico di un form dalla tabella di mapping salvata.
	 *
	 * @param string $provider Provider.
	 * @param string $form_id  Form id.
	 * @return array<string,string>
	 */
	public static function get_form_mapping( $provider, $form_id ) {
		$map = get_option( 'ati_ga4_form_map', array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		foreach ( $map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( empty( $row['enabled'] ) ) {
				continue;
			}
			$row_provider = isset( $row['provider'] ) ? (string) $row['provider'] : '';
			$row_form_id  = isset( $row['form_id'] ) ? (string) $row['form_id'] : '';
			if ( $row_form_id !== (string) $form_id ) {
				continue;
			}
			if ( '' !== $row_provider && $row_provider !== (string) $provider ) {
				continue;
			}
			return $row;
		}
		return array();
	}

	/**
	 * Sanifica un valore come slug compatibile GA4 (no PII, lunghezza limitata).
	 *
	 * @param string $value Valore grezzo.
	 * @return string
	 */
	public static function sanitize_slug( $value ) {
		$value = strtolower( trim( (string) $value ) );
		// Consente lettere/cifre/underscore; sostituisce il resto con underscore.
		$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
		$value = trim( (string) $value, '_' );
		if ( '' === $value ) {
			return self::FALLBACK;
		}
		if ( strlen( $value ) > self::MAX_LEN ) {
			$value = substr( $value, 0, self::MAX_LEN );
			$value = trim( $value, '_' );
		}
		return $value;
	}
}
