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
    fbq('init', '<?php echo esc_js( $fb_pixel_id ); ?>', {
        autoConfig: false,    // Disabilita il tracciamento automatico
        debug: <?php echo defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false'; ?>
    });
    
    // Disabilita esplicitamente il tracciamento automatico degli eventi
    fbq('set', 'autoConfig', false, '<?php echo esc_js( $fb_pixel_id ); ?>');
    
    console.log('[FST] 📘 Facebook Pixel inizializzato (consenso OK) - ID: <?php echo esc_js( $fb_pixel_id ); ?>');
    console.log('[FST] 📘 Tracciamento automatico DISABILITATO - solo eventi manuali con eventID');
    
    // ========================================
    // OVERRIDE fbq PER CONTROLLO eventID OBBLIGATORIO
    // ========================================
    // Salva la funzione originale di Facebook
    window.original_fbq = window.fbq;
    
    // Crea wrapper che controlla la presenza di eventID
    window.fbq = function(action, event, params, options) {
        if (action === 'track' && event !== 'PageView') {
            // Per tutti gli eventi tranne PageView, verifica che ci sia eventID
            if (!options || !options.eventID) {
                console.warn('[FST] ⚠️ Evento Facebook bloccato - manca eventID:', event, params);
                return; // Blocca l'invio se manca eventID
            }
        } else if (action === 'track' && event === 'PageView') {
            // Anche per PageView verifica eventID (opzionale ma consigliato)
            if (!options || !options.eventID) {
                console.warn('[FST] ⚠️ PageView Facebook senza eventID - potrebbe causare duplicati');
            }
        }
        
        // Se tutto OK, chiama la funzione originale
        return window.original_fbq.apply(this, arguments);
    };
    
    // Copia le proprietà della funzione originale
    for (let prop in window.original_fbq) {
        if (window.original_fbq.hasOwnProperty(prop)) {
            window.fbq[prop] = window.original_fbq[prop];
        }
    }
    
    console.log('[FST] 📘 Facebook Pixel wrapper installato - eventID obbligatorio per tutti gli eventi');
    
    <?php
    // DEBUG: Log completamento output
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] ✅ Facebook Pixel script output completato con autoConfig=false e wrapper eventID' );
    }
    ?>
    </script>

    <!-- Fallback noscript per browser senza JavaScript -->
    <!-- NOTA: Rimosso il PageView automatico dal noscript per evitare eventi duplicati -->
    <!-- Il PageView viene gestito manualmente dal JavaScript con eventID -->
    <noscript>
        <!-- Facebook Pixel noscript - solo per caricamento senza eventi automatici -->
    </noscript>
    <!-- End Facebook Pixel Code -->
    <?php
}
