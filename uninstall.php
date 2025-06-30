<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$options = array(
    'ati_fb_pixel_id',
    'ati_ga4_id',
    'ati_gtm_id',
    'ati_enable_fb',
    'ati_enable_ga4',
    'ati_enable_gtm',
    'ati_disable_logged_in',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

