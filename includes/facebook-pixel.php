<?php
/**
 * Facebook Pixel implementation for Quick Tracking Integration plugin.
 * file: includes/facebook-pixel.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Output Facebook Pixel code.
 * 
 * Questa funzione viene chiamata SOLO se l'utente ha dato il consenso marketing.
 * Si limita a inizializzare il Facebook Pixel senza tracciare PageView
 * (il PageView viene gestito dal JavaScript unificato in tag-inserter.php).
 */
function ati_output_facebook_pixel() {
    // DEBUG: Log chiamata funzione
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === CHIAMATA ati_output_facebook_pixel() ===' );
    }
    
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );
    
    // DEBUG: Log Pixel ID
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Facebook Pixel ID nel function: "' . $fb_pixel_id . '"' );
    }
    
    // Verifica che l'ID pixel sia configurato
    if ( empty( $fb_pixel_id ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] ❌ Facebook Pixel ID VUOTO - uscita' );
        }
        echo "<!-- Facebook Pixel: ID non configurato -->\n";
        return;
    }
    
    // DEBUG: Log output del pixel
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] ✅ Generazione output Facebook Pixel per ID: ' . $fb_pixel_id );
    }

    $script_url = plugin_dir_url( __FILE__ ) . '../assets/js/facebook-pixel.js';
    ?>
    <!-- Facebook Pixel Code (caricato solo con consenso) -->
    <script>
    window.atiFbPixelId = '<?php echo esc_js( $fb_pixel_id ); ?>';
    window.atiFbPixelDebug = <?php echo defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false'; ?>;
    </script>
    <script defer src="<?php echo esc_url( $script_url ); ?>"></script>
    <!-- End Facebook Pixel Code -->
    <?php
}
