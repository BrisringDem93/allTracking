<?php
/**
 * Settings page for Quick Tracking Integration plugin.
 *
 * Pagina admin UNICA a tab (una sola voce di menu):
 * - Generale: pixel/tag client-side, consenso, stato.
 * - Server-Side (n8n & Meta): endpoint n8n, autenticazione, credenziali Meta CAPI.
 * - GA4 Server-Side: delegata a ATI_GA4_Admin (Measurement Protocol, coda, test).
 *
 * Ogni tab ha il PROPRIO form e il PROPRIO settings group: options.php azzera le
 * opzioni del gruppo assenti dal POST, quindi opzioni di tab diversi non devono
 * mai condividere lo stesso gruppo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Register plugin settings.
 */
function ati_register_settings() {
    // --- Tab "Generale" (gruppo ati_settings) ---
    register_setting( 'ati_settings', 'ati_fb_pixel_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_ga4_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_gtm_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_fb', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_ga4', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_gtm', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_disable_logged_in', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_settings', 'ati_enable_form_fields', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '1' ) );
    register_setting( 'ati_settings', 'ati_consent_cookie_name', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
    register_setting( 'ati_settings', 'ati_consent_custom_event', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );

    // --- Tab "Server-Side (n8n & Meta)" (gruppo ati_server_settings) ---
    register_setting( 'ati_server_settings', 'ati_server_endpoint', array( 'sanitize_callback' => 'esc_url_raw' ) );
    register_setting( 'ati_server_settings', 'ati_server_auth_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_server_settings', 'ati_server_auth_value', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ati_server_settings', 'ati_meta_dataset_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    // Token CAPI: sanitizer che preserva il valore quando il campo è vuoto/mascherato
    // (stessa logica dell'API Secret GA4). Il valore non viene mai renderizzato.
    register_setting( 'ati_server_settings', 'ati_meta_capi_token', array( 'sanitize_callback' => 'ati_sanitize_meta_capi_token' ) );

    // NOTA: il tab GA4 Server-Side usa il gruppo ati_ga4_settings, registrato in
    // ATI_GA4_Admin. L'API Secret non viene mai renderizzato in HTML.
}
add_action( 'admin_init', 'ati_register_settings' );

/**
 * Sanitizza il token Meta CAPI senza mai cancellarlo per un campo vuoto.
 * - Campo vuoto o mascherato: conserva il valore esistente.
 * - Checkbox "rimuovi": cancella esplicitamente.
 *
 * @param string $value Valore inviato.
 * @return string
 */
function ati_sanitize_meta_capi_token( $value ) {
    $existing = (string) get_option( 'ati_meta_capi_token', '' );

    // Rimozione esplicita.
    if ( ! empty( $_POST['ati_meta_remove_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        return '';
    }

    $value = trim( (string) $value );

    // Vuoto o mascherato: mantieni il valore esistente.
    if ( '' === $value || false !== strpos( $value, '•' ) || '********' === $value ) {
        return $existing;
    }

    return sanitize_text_field( $value );
}

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
 * Tab disponibili della pagina settings.
 *
 * @return array slug => etichetta.
 */
function ati_settings_tabs() {
    return array(
        'general' => __( 'Generale', 'ati' ),
        'server'  => __( 'Server-Side (n8n & Meta)', 'ati' ),
        'ga4'     => __( 'GA4 Server-Side', 'ati' ),
        'cookies' => __( 'Blocco Cookie', 'ati' ),
    );
}

/**
 * URL di un tab della pagina settings.
 *
 * @param string $tab Slug tab.
 * @return string
 */
function ati_settings_tab_url( $tab ) {
    return admin_url( 'options-general.php?page=ati-settings&tab=' . rawurlencode( $tab ) );
}

/**
 * Render plugin settings page (dispatcher a tab).
 */
function ati_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $tabs    = ati_settings_tabs();
    $current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
    if ( ! isset( $tabs[ $current ] ) ) {
        $current = 'general';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Tracking Integration', 'ati' ); ?></h1>
        <nav class="nav-tab-wrapper">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="<?php echo esc_url( ati_settings_tab_url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
        switch ( $current ) {
            case 'server':
                ati_render_server_tab();
                break;
            case 'ga4':
                if ( class_exists( 'ATI_GA4_Admin' ) ) {
                    ATI_GA4_Admin::render_tab();
                } else {
                    echo '<p>' . esc_html__( 'Sottosistema GA4 non caricato.', 'ati' ) . '</p>';
                }
                break;
            case 'cookies':
                if ( class_exists( 'ATI_Cookie_Guard_Admin' ) ) {
                    ATI_Cookie_Guard_Admin::render_tab();
                } else {
                    echo '<p>' . esc_html__( 'Sottosistema blocco cookie non caricato.', 'ati' ) . '</p>';
                }
                break;
            default:
                ati_render_general_tab();
                break;
        }
        ?>
    </div>
    <?php
}

/**
 * Tab "Generale": tag client-side e consenso.
 */
function ati_render_general_tab() {
    ?>
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
                <th scope="row">GA4 Server-Side</th>
                <td>
                    <p class="description">
                        L'API Secret e il tracking server-side GA4 (Measurement Protocol, conversioni confermate, regione, classificazione, coda, test) si configurano nel tab
                        <a href="<?php echo esc_url( ati_settings_tab_url( 'ga4' ) ); ?>"><strong>GA4 Server-Side</strong></a>.
                        L'API Secret non viene mai mostrato in questa pagina.
                    </p>
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
                        <label><input type="checkbox" name="ati_enable_gtm" value="1" <?php checked( get_option( 'ati_enable_gtm', false ), '1' ); ?> /> <?php esc_html_e( 'Attiva Google Tag Manager', 'ati' ); ?></label>
                        <div class="notice notice-warning inline"><p><?php esc_html_e( 'Attenzione: attivando Google Tag Manager, il plugin non caricherà né invierà eventi a GA4 o Facebook Pixel lato client. Configura questi tag direttamente nel container GTM per evitare duplicazioni.', 'ati' ); ?></p></div>
                        <label><input type="checkbox" name="ati_disable_logged_in" value="1" <?php checked( get_option( 'ati_disable_logged_in', false ), '1' ); ?> /> <?php esc_html_e( 'Disattiva per utenti loggati', 'ati' ); ?></label>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Campi hidden nei form', 'ati' ); ?></th>
                <td>
                    <label><input type="checkbox" name="ati_enable_form_fields" value="1" <?php checked( get_option( 'ati_enable_form_fields', '1' ), '1' ); ?> /> <?php esc_html_e( 'Compila automaticamente i campi hidden riconosciuti', 'ati' ); ?></label>
                    <p class="description">
                        Se un form contiene input hidden con questi <code>name</code>, il plugin li compila prima dell'invio:<br />
                        <code>fbclid</code>, <code>gclid</code>, <code>fbc</code>, <code>fbp</code>, <code>gbraid</code>, <code>wbraid</code>,
                        <code>msclkid</code>, <code>ttclid</code>, <code>twclid</code>, <code>li_fat_id</code>,
                        <code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>, <code>utm_term</code>, <code>utm_content</code>, <code>external_id</code>.<br />
                        Riconosce anche i nomi con parentesi (es. Elementor <code>form_fields[fbclid]</code>), l'id <code>form-field-fbclid</code>,
                        la classe <code>ati-field-fbclid</code> e l'attributo <code>data-ati-field="fbclid"</code>.
                        I campi già valorizzati non vengono sovrascritti; se un valore non è disponibile il campo resta vuoto
                        (<code>fbp</code> richiede il cookie <code>_fbp</code> del Pixel, quindi il consenso marketing).
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ati_consent_cookie_name">Nome cookie consenso</label></th>
                <td>
                    <input name="ati_consent_cookie_name" type="text" id="ati_consent_cookie_name" value="<?php echo esc_attr( get_option( 'ati_consent_cookie_name', '' ) ); ?>" class="regular-text" placeholder="opzionale, es: mio_cookie_marketing" />
                    <p class="description">Cookie custom opzionale (valore atteso: "allow"). Se vuoto, il plugin rileva automaticamente Complianz, iubenda, Cookiebot e OneTrust.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ati_consent_custom_event">Evento JS consenso custom</label></th>
                <td>
                    <input name="ati_consent_custom_event" type="text" id="ati_consent_custom_event" value="<?php echo esc_attr( get_option( 'ati_consent_custom_event', '' ) ); ?>" class="regular-text" placeholder="es: myConsentAccepted" />
                    <p class="description">Evento JavaScript personalizzato che indica il consenso marketing. Lascia vuoto per usare solo il rilevamento automatico dei banner.</p>
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
                            const customCookie = '<?php echo esc_js( trim( (string) get_option( 'ati_consent_cookie_name', '' ) ) ); ?>';
                            function cookie(name) {
                                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                const match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]+)'));
                                return match ? decodeURIComponent(match[1]) : null;
                            }
                            let hasConsent = customCookie !== '' && cookie(customCookie) === 'allow';
                            hasConsent = hasConsent || cookie('cmplz_marketing') === 'allow';
                            try {
                                const iubendaMatch = document.cookie.match(/(?:^|; )_iub_cs-[\w-]+=([^;]+)/);
                                const iubenda = iubendaMatch ? JSON.parse(decodeURIComponent(iubendaMatch[1])) : null;
                                hasConsent = hasConsent || !!(iubenda && (iubenda.consent === true || (iubenda.purposes && iubenda.purposes[5] === true)));
                            } catch (e) {}
                            hasConsent = hasConsent || (cookie('CookieConsent') || '').indexOf('marketing:true') !== -1;
                            try {
                                const groups = new URLSearchParams(cookie('OptanonConsent') || '').get('groups') || '';
                                hasConsent = hasConsent || groups.indexOf('C0004:1') !== -1;
                            } catch (e) {}
                            document.getElementById('consent-status').innerHTML = hasConsent ?
                                '<span style="color: green;">✅ Consenso marketing attivo</span>' :
                                '<span style="color: orange;">⚠️ Consenso marketing non rilevato</span>';
                        })();
                        </script>
                    </span>
                    <p class="description">
                        Il plugin rileva automaticamente i cookie dei CMP supportati<?php $ati_custom_cookie = trim( (string) get_option( 'ati_consent_cookie_name', '' ) ); if ( '' !== $ati_custom_cookie ) : ?> e il cookie custom <code><?php echo esc_html( $ati_custom_cookie ); ?></code><?php endif; ?>.
                        Solo con consenso marketing valido vengono caricati i pixel client-side.
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Tab "Server-Side (n8n & Meta)": endpoint n8n e credenziali Meta CAPI.
 */
function ati_render_server_tab() {
    $token_set = '' !== trim( (string) get_option( 'ati_meta_capi_token', '' ) );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'ati_server_settings' ); ?>

        <h2><?php esc_html_e( 'Endpoint n8n', 'ati' ); ?></h2>
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
        </table>

        <h2><?php esc_html_e( 'Credenziali Meta (Conversions API)', 'ati' ); ?></h2>
        <p class="description">
            Se impostate, vengono incluse nel payload inviato a n8n come <code>pixel_id</code> e <code>access_token</code>
            (accanto a <code>data</code>): il workflow n8n le legge dalla richiesta e non deve più tenerle hardcodate.
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ati_meta_dataset_id">Meta Pixel / Dataset ID</label></th>
                <td>
                    <input name="ati_meta_dataset_id" type="text" id="ati_meta_dataset_id" value="<?php echo esc_attr( get_option( 'ati_meta_dataset_id', '' ) ); ?>" class="regular-text code" placeholder="es: 123456789012345" />
                    <p class="description">ID del dataset/pixel usato dalla Conversions API. Se vuoto, viene usato il Facebook Pixel ID del tab <a href="<?php echo esc_url( ati_settings_tab_url( 'general' ) ); ?>">Generale</a>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ati_meta_capi_token">Access Token CAPI</label></th>
                <td>
                    <input name="ati_meta_capi_token" type="password" id="ati_meta_capi_token" value="" autocomplete="new-password" class="regular-text code" placeholder="<?php echo $token_set ? '••••••••  (lascia vuoto per non modificare)' : 'incolla il token'; ?>" />
                    <p class="description">
                        Stato: <?php echo $token_set ? '<strong>impostato</strong>' : '<strong>non impostato</strong>'; ?>.
                        Il valore non viene mai mostrato né inviato al browser. Lascia vuoto per conservarlo.
                    </p>
                    <?php if ( $token_set ) : ?>
                        <label><input type="checkbox" name="ati_meta_remove_token" value="1" /> Rimuovi il token salvato</label>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <h2><?php esc_html_e( 'Endpoint disponibili', 'ati' ); ?></h2>
    <p><strong>AJAX PageView:</strong> <code><?php echo esc_html( admin_url( 'admin-ajax.php' ) ); ?>?action=fst_pageview</code></p>
    <p><strong>REST Eventi:</strong> <code><?php echo esc_html( home_url( '/wp-json/fst/v1/event' ) ); ?></code></p>
    <p class="description">Questi endpoint ricevono gli eventi dal JavaScript per il server-side tracking.</p>
    <?php
}
