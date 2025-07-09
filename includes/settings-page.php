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
    register_setting( 'ati_settings', 'ati_ga4_server_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_ga4_api_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_gtm_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_fb', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_ga4', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_gtm', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_disable_logged_in', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_server_endpoint', array( 'sanitize_callback' => 'esc_url_raw' ) );
    register_setting( 'ati_settings', 'ati_server_auth_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_server_auth_value', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_ga4_server', array( 'sanitize_callback' => 'sanitize_text_field' ) );
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
                    <td>
                        <input name="ati_ga4_id" type="text" id="ati_ga4_id" value="<?php echo esc_attr( get_option( 'ati_ga4_id', '' ) ); ?>" class="regular-text" />
                        <p class="description">Es: G-XXXXXXXXXX (per tracking client-side)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_ga4_api_secret">GA4 API Secret</label></th>
                    <td>
                        <input name="ati_ga4_api_secret" type="password" id="ati_ga4_api_secret" value="<?php echo esc_attr( get_option( 'ati_ga4_api_secret', '' ) ); ?>" class="regular-text" />
                        <p class="description">Necessario solo per tracking server-side via Measurement Protocol</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_gtm_id">Google Tag Manager ID</label></th>
                    <td><input name="ati_gtm_id" type="text" id="ati_gtm_id" value="<?php echo esc_attr( get_option( 'ati_gtm_id', '' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">&nbsp;</th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span>Opzioni di attivazione</span></legend>
                            <label><input type="checkbox" name="ati_enable_fb" value="1" <?php checked( get_option( 'ati_enable_fb', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva Facebook Pixel', 'ati' ); ?></label><br />
                            <label><input type="checkbox" name="ati_enable_ga4" value="1" <?php checked( get_option( 'ati_enable_ga4', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva GA4 (client-side)', 'ati' ); ?></label><br />
                            <label><input type="checkbox" name="ati_enable_gtm" value="1" <?php checked( get_option( 'ati_enable_gtm', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva Google Tag Manager', 'ati' ); ?></label><br />
                            <label><input type="checkbox" name="ati_disable_logged_in" value="1" <?php checked( get_option( 'ati_disable_logged_in', false ), '1' ); ?> /> <?php esc_html_e( 'Disattiva per utenti loggati', 'ati' ); ?></label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Impostazioni Server-Side Tracking', 'ati' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ati_server_endpoint">Endpoint server (es. n8n)</label></th>
                    <td>
                        <input name="ati_server_endpoint" type="url" id="ati_server_endpoint" value="<?php echo esc_attr( get_option( 'ati_server_endpoint', '' ) ); ?>" class="regular-text code" />
                        <p class="description">URL del webhook per inviare eventi (es: https://n8n.esempio.com/webhook/facebook)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_server_auth_key">Chiave Header Autenticazione</label></th>
                    <td>
                        <input name="ati_server_auth_key" type="text" id="ati_server_auth_key" value="<?php echo esc_attr( get_option( 'ati_server_auth_key', '' ) ); ?>" class="regular-text code" />
                        <p class="description">Nome header per autenticazione (es: X-API-Key, Authorization)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_server_auth_value">Valore Header Autenticazione</label></th>
                    <td>
                        <input name="ati_server_auth_value" type="password" id="ati_server_auth_value" value="<?php echo esc_attr( get_option( 'ati_server_auth_value', '' ) ); ?>" class="regular-text code" />
                        <p class="description">Valore dell'header di autenticazione (token, chiave API, etc.)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ati_ga4_server_id">GA4 Measurement ID (Server-side)</label></th>
                    <td>
                        <input name="ati_ga4_server_id" type="text" id="ati_ga4_server_id" value="<?php echo esc_attr( get_option( 'ati_ga4_server_id', '' ) ); ?>" class="regular-text" />
                        <p class="description">ID GA4 specifico per Measurement Protocol server-side (può essere diverso da quello client-side)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">&nbsp;</th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span>Opzioni server-side</span></legend>
                            <label>
                                <input type="checkbox" name="ati_enable_ga4_server" value="1" <?php checked( get_option( 'ati_enable_ga4_server', false ), '1' ); ?> />
                                <?php esc_html_e( 'Invia eventi a GA4 anche da server (Measurement Protocol)', 'ati' ); ?>
                            </label>
                            <p class="description">Richiede GA4 Server ID e API Secret configurati sopra. Migliora la precisione dei dati GA4.</p>
                        </fieldset>
                    </td>
                </tr>


            </table>

            <h2><?php esc_html_e( 'Informazioni di stato', 'ati' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Stato consenso cookie</th>
                    <td>
                        <span id="consent-status">
                            <script>
                            (function(){
                                const match = document.cookie.match(/(?:^|; )cmplz_marketing=([^;]+)/);
                                const hasConsent = match && match[1] === 'allow';
                                document.getElementById('consent-status').innerHTML = hasConsent ? 
                                    '<span style="color: green;">✅ Consenso marketing attivo</span>' : 
                                    '<span style="color: orange;">⚠️ Consenso marketing non rilevato</span>';
                            })();
                            </script>
                        </span>
                        <p class="description">
                            Il plugin rispetta il cookie <code>cmplz_marketing</code>. 
                            Solo con consenso = "allow" vengono caricati i pixel client-side.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Endpoint disponibili</th>
                    <td>
                        <p><strong>AJAX PageView:</strong> <code><?php echo esc_html( admin_url('admin-ajax.php') ); ?>?action=fst_pageview</code></p>
                        <p><strong>REST Eventi:</strong> <code><?php echo esc_html( home_url('/wp-json/fst/v1/event') ); ?></code></p>
                        <p class="description">Questi endpoint ricevono gli eventi dal JavaScript per il server-side tracking.</p>
                    </td>
                </tr>


            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
