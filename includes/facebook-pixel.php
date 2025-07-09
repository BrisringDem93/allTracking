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
    ?>
    <!-- Facebook Pixel Code (Solo inizializzazione) -->
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod ?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    
    // Inizializza il pixel con l'ID configurato
    fbq('init', '<?php echo esc_js( $fb_pixel_id ); ?>');
    
    console.log('[FST] 📘 Facebook Pixel inizializzato (consenso OK) - ID: <?php echo esc_js( $fb_pixel_id ); ?>');
    
    <?php
    // DEBUG: Log completamento output
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] ✅ Facebook Pixel script output completato' );
    }
    ?>
    </script>

    <!-- Fallback noscript per browser senza JavaScript -->
    <noscript>
        <img height="1" width="1" style="display:none" 
             src="https://www.facebook.com/tr?id=<?php echo esc_attr( $fb_pixel_id ); ?>&ev=PageView&noscript=1" />
    </noscript>
    <!-- End Facebook Pixel Code -->
    <?php
}
