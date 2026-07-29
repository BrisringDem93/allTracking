<?php
/**
 * Stub minimi delle funzioni WordPress per eseguire i test delle funzioni pure
 * del sottosistema GA4 SENZA un'installazione WordPress completa.
 *
 * NON è un ambiente WordPress: copre solo ciò che serve alle classi pure testate
 * (event model, normalizer, classification, consent detection, endpoints, adapter
 * payload, dedup key, secret sanitizer). I test che richiedono DB/HTTP/WP reale
 * sono marcati NOT RUN nella matrice.
 *
 * @package QuickTrackingIntegration\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
// In WordPress WP_DEBUG è sempre definita (wp_initial_constants); qui serve per
// poter caricare includes/server-tracking.php, che la legge senza defined().
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}
if ( ! defined( 'PHP_INT_MAX' ) ) {
	// noop.
}

$GLOBALS['__ati_opts'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__ati_opts'] ) ? $GLOBALS['__ati_opts'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__ati_opts'][ $key ] = $value;
	return true;
}
function add_option( $key, $value = '', $d = '', $a = 'yes' ) {
	if ( ! array_key_exists( $key, $GLOBALS['__ati_opts'] ) ) {
		$GLOBALS['__ati_opts'][ $key ] = $value;
	}
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__ati_opts'][ $key ] );
	return true;
}

function apply_filters( $tag, $value ) {
	return $value;
}
function add_filter() {
	return true;
}
function add_action() {
	return true;
}
function do_action() {
	return true;
}

function sanitize_text_field( $s ) {
	$s = is_scalar( $s ) ? (string) $s : '';
	$s = wp_strip_all_tags( $s );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $s ) );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function esc_url_raw( $url ) {
	return trim( (string) $url );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_rand( $min = 0, $max = 2147483647 ) {
	return mt_rand( $min, $max );
}
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function is_user_logged_in() {
	return false;
}

/**
 * Consenso marketing: in WordPress è definita da includes/tag-inserter.php, caricato
 * prima di server-tracking.php. Qui è pilotabile dai test via $GLOBALS.
 */
$GLOBALS['__ati_marketing_consent'] = false;
function ati_has_marketing_consent() {
	return (bool) $GLOBALS['__ati_marketing_consent'];
}
