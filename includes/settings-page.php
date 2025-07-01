<?php
/**
 * Settings page for Quick Tracking Integration plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Register plugin settings.
 */
function ati_register_settings() {
    register_setting( 'ati_settings', 'ati_fb_pixel_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_ga4_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_gtm_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_fb', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_ga4', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_gtm', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_disable_logged_in', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_server_endpoint', array( 'sanitize_callback' => 'esc_url_raw' ) );
    register_setting( 'ati_settings', 'ati_server_auth_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_server_auth_value', array( 'sanitize_callback' => 'sanitize_text_field' ) );


}
add_action( 'admin_init', 'ati_register_settings' );

/**
 * Add menu item in WordPress admin.
 */
function ati_add_admin_menu() {
    if ( current_user_can( 'manage_options' ) ) {
        add_options_page( 'Tracking Integration', 'Tracking Integration', 'manage_options', 'ati-settings', 'ati_settings_page' );
    }
}
add_action( 'admin_menu', 'ati_add_admin_menu' );

/**
 * Render plugin settings page.
 */
function ati_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tracking Integration', 'ati' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'ati_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ati_fb_pixel_id">Facebook Pixel ID</label></th>
                    <td><input name="ati_fb_pixel_id" type="password" id="ati_fb_pixel_id" value="<?php echo esc_attr( get_option( 'ati_fb_pixel_id', '' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_ga4_id">GA4 Measurement ID</label></th>
                    <td><input name="ati_ga4_id" type="password" id="ati_ga4_id" value="<?php echo esc_attr( get_option( 'ati_ga4_id', '' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_gtm_id">Google Tag Manager ID</label></th>
                    <td><input name="ati_gtm_id" type="text" id="ati_gtm_id" value="<?php echo esc_attr( get_option( 'ati_gtm_id', '' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">&nbsp;</th>
                    <td>
                        <label><input type="checkbox" name="ati_enable_fb" value="1" <?php checked( get_option( 'ati_enable_fb', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva Facebook Pixel', 'ati' ); ?></label><br />
                        <label><input type="checkbox" name="ati_enable_ga4" value="1" <?php checked( get_option( 'ati_enable_ga4', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva GA4', 'ati' ); ?></label><br />
                        <label><input type="checkbox" name="ati_enable_gtm" value="1" <?php checked( get_option( 'ati_enable_gtm', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva Google Tag Manager', 'ati' ); ?></label><br />
                        <label><input type="checkbox" name="ati_disable_logged_in" value="1" <?php checked( get_option( 'ati_disable_logged_in', false ), '1' ); ?> /> <?php esc_html_e( 'Disattiva per utenti loggati', 'ati' ); ?></label>
                    </td>
                </tr>
                <tr>
    <th scope="row"><label for="ati_server_endpoint">Endpoint server (es. n8n)</label></th>
    <td><input name="ati_server_endpoint" type="url" id="ati_server_endpoint" value="<?php echo esc_attr( get_option( 'ati_server_endpoint', '' ) ); ?>" class="regular-text code" /></td>
</tr>
<tr>
    <th scope="row"><label for="ati_auth_header_name">Chiave Header Autenticazione</label></th>
    <td><input name="ati_auth_header_name" type="text" id="ati_auth_header_name" value="<?php echo esc_attr( get_option( 'ati_auth_header_name', '' ) ); ?>" class="regular-text code" /></td>
</tr>
<tr>
    <th scope="row"><label for="ati_auth_header_value">Valore Header Autenticazione</label></th>
    <td><input name="ati_auth_header_value" type="password" id="ati_auth_header_value" value="<?php echo esc_attr( get_option( 'ati_auth_header_value', '' ) ); ?>" class="regular-text code" /></td>
</tr>


            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
