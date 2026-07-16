<?php
/**
 * Tag inserter functions for Quick Tracking Integration plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Controlla se il consenso marketing è stato dato dall'utente
 * 
 * Verifica il cookie di consenso (es. cmplz_marketing) che viene settato da Complianz
 * o altri plugin di gestione cookie GDPR.
 * 
 * @return bool True se il consenso è stato dato, False altrimenti
 */
function ati_has_marketing_consent() {
    // Cache il risultato per l'intera durata della richiesta (evita iterazioni ripetute su $_COOKIE)
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    // DEBUG: Log controllo consenso
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === CONTROLLO CONSENSO MARKETING ===' );
        error_log( '[ATI DEBUG] Cookie disponibili: ' . json_encode( $_COOKIE ) );
    }

    $cookie_name = trim( (string) get_option( 'ati_consent_cookie_name', '' ) );

    // 1. Cookie custom configurato nelle impostazioni (opzionale, valore atteso: allow)
    if ( '' !== $cookie_name && isset( $_COOKIE[ $cookie_name ] ) ) {
        $consent_value = $_COOKIE[ $cookie_name ];
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] Cookie ' . $cookie_name . ' trovato: ' . $consent_value );
            error_log( '[ATI DEBUG] Consenso valido: ' . ( $consent_value === 'allow' ? 'SI' : 'NO' ) );
        }
        if ( $consent_value === 'allow' ) {
            return $cached = true;
        }
    }

    // 2. Complianz: cmplz_marketing=allow
    if ( isset( $_COOKIE['cmplz_marketing'] ) && 'allow' === $_COOKIE['cmplz_marketing'] ) {
        return $cached = true;
    }

    // 3. iubenda: consenso globale oppure purpose 5 (Marketing)
    foreach ( $_COOKIE as $name => $value ) {
        if ( preg_match( '/^_iub_cs-\d+$/', $name ) ) {
            $data = json_decode( urldecode( $value ), true );
            if ( is_array( $data ) && (
                ( isset( $data['consent'] ) && true === $data['consent'] )
                || ( isset( $data['purposes'][5] ) && true === $data['purposes'][5] )
            ) ) {
                return $cached = true;
            }
        }
    }

    // 4. Cookiebot: CookieConsent con marketing:true
    if ( isset( $_COOKIE['CookieConsent'] ) ) {
        if ( strpos( urldecode( $_COOKIE['CookieConsent'] ), 'marketing:true' ) !== false ) {
            return $cached = true;
        }
    }

    // 5. OneTrust: OptanonConsent con groups C0004:1 (C0004 = Targeting/Pubblicità, :1 = consenso accordato)
    // Usa regex per estrarre il parametro 'groups' in modo sicuro, senza parse_str
    if ( isset( $_COOKIE['OptanonConsent'] ) ) {
        if ( preg_match( '/(?:^|&)groups=([^&]*)/', urldecode( $_COOKIE['OptanonConsent'] ), $ot_match ) ) {
            if ( strpos( $ot_match[1], 'C0004:1' ) !== false ) {
                return $cached = true;
            }
        }
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Nessun consenso marketing trovato' );
    }
    // Se il cookie non esiste, assumiamo nessun consenso per sicurezza GDPR
    return $cached = false;
}

/**
 * Output direct tracking tags when Google Tag Manager is disabled.
 *
 * Handles direct GA4 and consent-gated Facebook Pixel tags for the AJAX loader.
 */
function ati_output_tags() {
    // DEBUG: Log inizio funzione
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === INIZIO ati_output_tags() ===' );
    }
    
    // STEP 1: Controllo se il tracking è disabilitato per utenti loggati
    $disable_logged_in = get_option( 'ati_disable_logged_in', false );
    
    // DEBUG: Log controllo utenti loggati
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Disable logged in: ' . ( $disable_logged_in ? 'SI' : 'NO' ) );
        error_log( '[ATI DEBUG] Utente loggato: ' . ( is_user_logged_in() ? 'SI' : 'NO' ) );
    }
    
    if ( $disable_logged_in && is_user_logged_in() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] USCITA: Tracking disabilitato per utenti loggati' );
        }
        return;
    }

    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    // STEP 2: Controllo consenso marketing GDPR
    $has_marketing_consent = ati_has_marketing_consent();
    
    // DEBUG: Log consenso marketing
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Consenso marketing ottenuto: ' . ( $has_marketing_consent ? 'SI' : 'NO' ) );
    }

    // STEP 3: Gestione Facebook Pixel basata sul consenso
    $fb_enabled  = get_option( 'ati_enable_fb', false );
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );

    // DEBUG: Log impostazioni Facebook
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Facebook abilitato: ' . ( $fb_enabled ? 'SI' : 'NO' ) );
        error_log( '[ATI DEBUG] Facebook Pixel ID: "' . $fb_pixel_id . '"' );
        error_log( '[ATI DEBUG] Facebook Pixel ID vuoto: ' . ( empty( $fb_pixel_id ) ? 'SI' : 'NO' ) );
    }

    if ( ! $gtm_enabled && $fb_enabled && ! empty( $fb_pixel_id ) ) {
        if ( $has_marketing_consent ) {
            // ✅ Consenso dato: Carica Facebook Pixel completo (client-side)
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[ATI DEBUG] ✅ CARICAMENTO Facebook Pixel - consenso dato' );
                error_log( '[ATI DEBUG] File da includere: ' . plugin_dir_path( __FILE__ ) . 'facebook-pixel.php' );
                error_log( '[ATI DEBUG] File esiste: ' . ( file_exists( plugin_dir_path( __FILE__ ) . 'facebook-pixel.php' ) ? 'SI' : 'NO' ) );
            }
            
            $facebook_pixel_file = plugin_dir_path( __FILE__ ) . 'facebook-pixel.php';
            if ( file_exists( $facebook_pixel_file ) ) {
                require_once $facebook_pixel_file;
                
                // Verifica che la funzione esista prima di chiamarla
                if ( function_exists( 'ati_output_facebook_pixel' ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[ATI DEBUG] ✅ Chiamata ati_output_facebook_pixel()' );
                    }
                    ati_output_facebook_pixel();
                } else {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[ATI DEBUG] ❌ Funzione ati_output_facebook_pixel() NON trovata' );
                    }
                }
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[ATI DEBUG] ❌ File facebook-pixel.php NON trovato' );
                }
            }
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[ATI DEBUG] ✅ Facebook Pixel caricato con successo' );
            }
        } else {
            // ⚠️ Nessun consenso: Solo tracking server-side (senza cookie/pixel)
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[ATI DEBUG] ⚠️ Facebook Pixel NON caricato - nessun consenso marketing' );
            }
            // Il JavaScript invierà comunque eventi al server per il server-side tracking
        }
    } else {
        // DEBUG: Log perché Facebook non viene caricato
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( ! $fb_enabled ) {
                error_log( '[ATI DEBUG] ❌ Facebook NON caricato - funzione disabilitata' );
            }
            if ( empty( $fb_pixel_id ) ) {
                error_log( '[ATI DEBUG] ❌ Facebook NON caricato - Pixel ID vuoto' );
            }
        }
    }

    $ga_enabled = get_option( 'ati_enable_ga4', false );
    $ga_id      = trim( get_option( 'ati_ga4_id', '' ) );

    if ( ! $gtm_enabled && $ga_enabled && ! empty( $ga_id ) ) {
        ?>
        <!-- Google Analytics 4 -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga_id ); ?>"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo esc_js( $ga_id ); ?>');
        </script>
        <!-- End Google Analytics 4 -->
        <?php
    }

    // DEBUG: Log fine funzione
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === FINE ati_output_tags() ===' );
    }
}
/**
 * Output the Google Tag Manager container in the document head.
 */
function ati_output_gtm_head() {
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        return;
    }

    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    $gtm_id      = trim( get_option( 'ati_gtm_id', '' ) );

    if ( ! $gtm_enabled || empty( $gtm_id ) ) {
        return;
    }
    ?>
    <!-- Google Consent Mode defaults -->
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
        'ad_storage': 'denied',
        'analytics_storage': 'denied',
        'ad_user_data': 'denied',
        'ad_personalization': 'denied'
    });
    </script>
    <!-- End Google Consent Mode defaults -->
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?php echo esc_js( $gtm_id ); ?>');</script>
    <!-- End Google Tag Manager -->
    <?php
}
add_action( 'wp_head', 'ati_output_gtm_head', 1 );

/**
 * Output Google Tag Manager noscript after <body> tag.
 */
function ati_output_gtm_noscript() {
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        return;
    }

    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    $gtm_id      = trim( get_option( 'ati_gtm_id', '' ) );

    if ( ! $gtm_enabled || empty( $gtm_id ) ) {
        return;
    }

    echo "<!-- Google Tag Manager (noscript) -->\n";
    echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm_id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
    echo "<!-- End Google Tag Manager (noscript) -->\n";
}
add_action( 'wp_body_open', 'ati_output_gtm_noscript' );

/**
 * AJAX handler per ricaricare Facebook Pixel quando cambia il consenso
 */
function ati_ajax_reload_facebook_pixel() {
  if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[ATI DEBUG] === INIZIO ati_ajax_reload_facebook_pixel() ===' );
  }

    // Verifica nonce per sicurezza
  $nonce = $_POST['nonce'] ?? '';
  if ( ! wp_verify_nonce( $nonce, 'ati_reload_pixel' ) ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
      error_log( '[ATI DEBUG] ❌ Nonce non valido in ati_ajax_reload_facebook_pixel()' );
      error_log( '[ATI DEBUG] Nonce ricevuto (prefix): ' . substr( sanitize_text_field( $nonce ), 0, 12 ) );
    }
        wp_die( 'Nonce verification failed' );
    }

  if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[ATI DEBUG] ✅ Nonce valido in ati_ajax_reload_facebook_pixel()' );
  }
    
    // NUOVO: Blocca completamente se utenti loggati sono disabilitati
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        $response = array(
            'success' => false,
            'has_consent' => false,
            'pixel_id' => '',
            'message' => 'Tracking disabilitato per utenti loggati'
        );
        wp_send_json( $response );
    }

    if ( get_option( 'ati_enable_gtm', false ) ) {
        wp_send_json(
            array(
                'success'     => false,
                'has_consent' => false,
                'pixel_id'    => '',
                'message'     => 'Facebook Pixel is managed by Google Tag Manager'
            )
        );
    }
    
    // Controlla se il consenso marketing è attivo
    $has_marketing_consent = ati_has_marketing_consent();
    
    $response = array(
        'success' => false,
        'has_consent' => $has_marketing_consent,
        'pixel_id' => '',
        'message' => ''
    );
    
    // Se c'è consenso e Facebook è abilitato, restituisci il Pixel ID
    $fb_enabled  = get_option( 'ati_enable_fb', false );
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );
    
    if ( $has_marketing_consent && $fb_enabled && ! empty( $fb_pixel_id ) ) {
        $response['success'] = true;
        $response['pixel_id'] = $fb_pixel_id;
        $response['message'] = 'Facebook Pixel can be loaded';
    } else {
        $response['message'] = 'Facebook Pixel should not be loaded';
    }
    
    wp_send_json( $response );
}
add_action( 'wp_ajax_ati_reload_facebook_pixel', 'ati_ajax_reload_facebook_pixel' );
add_action( 'wp_ajax_nopriv_ati_reload_facebook_pixel', 'ati_ajax_reload_facebook_pixel' );

/**
 * AJAX handler per caricare dinamicamente i tag di tracking
 */
function ati_ajax_load_tracking_tags() {
  if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[ATI DEBUG] === RICHIESTA ati_ajax_load_tracking_tags() ricevuta ===' );
  }

    // Verifica nonce per sicurezza
  $nonce = $_POST['nonce'] ?? '';
  if ( ! wp_verify_nonce( $nonce, 'ati_load_tags' ) ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
      error_log( '[ATI DEBUG] ❌ Nonce non valido in ati_ajax_load_tracking_tags()' );
      error_log( '[ATI DEBUG] Nonce ricevuto (prefix): ' . substr( sanitize_text_field( $nonce ), 0, 12 ) );
    }
        wp_die( 'Nonce verification failed' );
    }

  if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( '[ATI DEBUG] ✅ Nonce valido in ati_ajax_load_tracking_tags()' );
  }
    
    // NUOVO: Blocca completamente se utenti loggati sono disabilitati
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        $response = array(
            'success' => false,
            'tags_html' => '',
            'message' => 'Tracking disabilitato per utenti loggati'
        );
        wp_send_json( $response );
    }
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === INIZIO ati_ajax_load_tracking_tags() ===' );
    }

    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    if ( $gtm_enabled ) {
        wp_send_json(
            array(
                'success'   => true,
                'tags_html' => '',
                'message'   => 'Tracking tags are being managed by Google Tag Manager'
            )
        );
    }

    $has_marketing_consent = ati_has_marketing_consent();
    $fb_enabled  = get_option( 'ati_enable_fb', false );
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );
    $ga_enabled  = get_option( 'ati_enable_ga4', false );
    $ga_id       = trim( get_option( 'ati_ga4_id', '' ) );

    $should_render_direct_tags = ( $ga_enabled && ! empty( $ga_id ) )
        || ( $fb_enabled && ! empty( $fb_pixel_id ) && $has_marketing_consent );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Consenso marketing: ' . ( $has_marketing_consent ? 'SI' : 'NO' ) );
        error_log( '[ATI DEBUG] FB abilitato: ' . ( $fb_enabled ? 'SI' : 'NO' ) . ' | Pixel ID: ' . ( $fb_pixel_id ? 'OK' : 'VUOTO' ) );
        error_log( '[ATI DEBUG] GA4 abilitato: ' . ( $ga_enabled ? 'SI' : 'NO' ) . ' | GA ID: ' . ( $ga_id ? 'OK' : 'VUOTO' ) );
    }

    // Capture exclusively the output of direct tags.
    ob_start();
    ati_output_tags();
    $tags_output = ob_get_clean();

    $message = 'Tags loaded successfully';
    if ( ! $should_render_direct_tags || empty( trim( $tags_output ) ) ) {
        $reasons = array();
        if ( ! $has_marketing_consent ) {
            $reasons[] = 'consenso marketing non presente';
        }
        if ( $fb_enabled && empty( $fb_pixel_id ) ) {
            $reasons[] = 'Pixel ID vuoto';
        }
        if ( $fb_enabled && ! $has_marketing_consent ) {
            $reasons[] = 'Pixel bloccato dal consenso';
        }
        if ( $ga_enabled && empty( $ga_id ) ) {
            $reasons[] = 'GA4 ID vuoto';
        }
        if ( ! $fb_enabled && ! $ga_enabled ) {
            $reasons[] = 'nessun tag abilitato';
        }
        $message = $reasons
            ? 'Nessun tag caricato: ' . implode( ', ', array_unique( $reasons ) )
            : 'Nessun tag caricato';
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] ' . $message );
        }
    }
    
    $response = array(
        'success' => true,
        'tags_html' => $tags_output,
        'message' => $message
    );
    
    wp_send_json( $response );
}
add_action( 'wp_ajax_ati_load_tracking_tags', 'ati_ajax_load_tracking_tags' );
add_action( 'wp_ajax_nopriv_ati_load_tracking_tags', 'ati_ajax_load_tracking_tags' );


add_action( 'wp_footer', 'fst_inline_tracking_js', 100 );
function fst_inline_tracking_js() { 
    // Controllo server-side: se utenti loggati sono disabilitati, non caricare nemmeno JavaScript
    if ( get_option( 'ati_disable_logged_in', false ) && is_user_logged_in() ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] Tracking JS non caricato - utente loggato e tracking disabilitato' );
        }
        echo '<!-- Tracking disabilitato per utenti loggati -->';
        return;
    }
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Tracking JS caricato nel footer' );
    }
    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    ?>
<script>
// ========================================
// VARIABILI GLOBALI E SETUP
// ========================================

window.fstAjaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

(function () {
  const endpoint = '<?php echo esc_js( home_url( '/wp-json/fst/v1/event' ) ); ?>';
  const ajaxUrl = window.fstAjaxUrl;
  const isTagManagerEnabled = <?php echo $gtm_enabled ? 'true' : 'false'; ?>;
  window.consentCookieName = '<?php echo esc_js( trim( (string) get_option( 'ati_consent_cookie_name', '' ) ) ); ?>';
  
  // ========================================
  // CARICAMENTO DINAMICO TAG DI TRACKING
  // ========================================
  
  // Dynamically load direct tags (GA4 and Facebook Pixel if consent).
  function loadTrackingTags() {
    if (isTagManagerEnabled) {
      return;
    }

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🔄 Caricamento dinamico tag di tracking...');
<?php endif; ?>
    
    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'ati_load_tracking_tags',
        nonce: '<?php echo wp_create_nonce( "ati_load_tags" ); ?>'
      })
    }).then(response => response.json())
      .then(data => {
        if (data.success && data.tags_html) {
          // Inserisce i tag nell'head
          const tempDiv = document.createElement('div');
          tempDiv.innerHTML = data.tags_html;
          
          // Sposta tutti gli script e link nell'head
          const scripts = tempDiv.querySelectorAll('script');
          const links = tempDiv.querySelectorAll('link');
          
          scripts.forEach(script => {
            const newScript = document.createElement('script');
            if (script.src) {
              newScript.src = script.src;
              newScript.async = script.async;
            } else {
              newScript.textContent = script.textContent;
            }
            document.head.appendChild(newScript);
          });
          
          links.forEach(link => {
            document.head.appendChild(link.cloneNode(true));
          });
          
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
          console.log('[FST] ✅ Tag di tracking caricati dinamicamente');
<?php endif; ?>
        } else {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
          console.log('[FST] ⚠️ Nessun tag da caricare:', data.message);
<?php endif; ?>
        }
      })
      .catch(err => {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.error('[FST] ❌ Errore caricamento tag:', err);
<?php endif; ?>
      });
  }
  
  // Direct tags are only necessary when GTM does not handle tracking.
  if (!isTagManagerEnabled) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', loadTrackingTags);
    } else {
      loadTrackingTags();
    }
  }
  
  // ========================================
  // CONTROLLO CONSENSO COOKIE GDPR
  // ========================================
  
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
  if (window.consentCookieName) {
    console.log('[FST DEBUG] Controllo cookie custom:', window.consentCookieName);
    const customConsentCookie = document.cookie.split('; ').find(row => row.startsWith(window.consentCookieName + '='));
    console.log('[FST DEBUG] Cookie custom:', customConsentCookie || 'non trovato');
  } else {
    console.log('[FST DEBUG] Cookie custom non configurato: verrà usato il rilevamento automatico CMP');
  }
<?php endif; ?>

  window.marketingConsent = (function() {
    // 1. Cookie custom configurato nelle impostazioni (opzionale)
    if (window.consentCookieName) {
      var custom = document.cookie.match(new RegExp('(?:^|; )' + window.consentCookieName + '=([^;]+)'));
      if (custom && decodeURIComponent(custom[1]) === 'allow') return true;
    }

    // 2. Complianz
    var cm = document.cookie.match(/(?:^|; )cmplz_marketing=([^;]+)/);
    if (cm && decodeURIComponent(cm[1]) === 'allow') return true;

    // 3. iubenda: consenso globale oppure purpose 5 (Marketing)
    var iub = document.cookie.match(/(?:^|; )_iub_cs-\d+=([^;]+)/);
    if (iub) { try { var d = JSON.parse(decodeURIComponent(iub[1])); if (d && (d.consent === true || (d.purposes && d.purposes[5] === true))) return true; } catch(e) {} }

    // 4. Cookiebot: CookieConsent con marketing:true
    var cb = document.cookie.match(/(?:^|; )CookieConsent=([^;]+)/);
    if (cb) { try { if (decodeURIComponent(cb[1]).indexOf('marketing:true') !== -1) return true; } catch(e) {} }

    // 5. OneTrust: OptanonConsent con groups C0004:1 (C0004 = Targeting/Pubblicità, :1 = consenso accordato)
    var ot = document.cookie.match(/(?:^|; )OptanonConsent=([^;]+)/);
    if (ot) { try { var g = new URLSearchParams(decodeURIComponent(ot[1])).get('groups') || ''; if (g.indexOf('C0004:1') !== -1) return true; } catch(e) {} }

    return false;
  })();
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
  console.log('[FST] Consenso marketing:', window.marketingConsent ? '✅ Consentito' : '❌ Non consentito');
<?php endif; ?>
  
  // ========================================
  // FUNZIONI HELPER EVENT ID E EXTERNAL ID
  // ========================================
  function getEventId() {
    const m = document.cookie.match(/(?:^|; )fst_ev_id=([^;]+)/);
    if (m) return decodeURIComponent(m[1]);
    const id = 'evt_' + Date.now() + '_' + Math.random().toString(36).slice(2);
    document.cookie = 'fst_ev_id=' + encodeURIComponent(id) +
      '; path=/; max-age=3600; SameSite=Lax';
    return id;
  }
  
  function clearEventId() {
    document.cookie = 'fst_ev_id=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
  }
  
  // Genera o recupera external_id persistente (come lato server).
  // Il cookie fst_uid viene scritto SOLO dopo che l'utente ha espresso consenso marketing.
  // Prima del consenso viene mantenuto un UUID temporaneo in memoria (window._fstTempUid).
  function getExternalId() {
    const cookieName = 'fst_uid';
    const match = document.cookie.match(new RegExp('(?:^|; )' + cookieName + '=([^;]+)'));
    if (match) {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST UID] ✅ Cookie fst_uid gia presente:', decodeURIComponent(match[1]));
<?php endif; ?>
      return decodeURIComponent(match[1]);
    }

    // Usa o crea UUID temporaneo (non ancora persistito nel cookie)
    if (!window._fstTempUid) {
      window._fstTempUid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
      });
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST UID] 🆕 UUID temporaneo creato:', window._fstTempUid);
<?php endif; ?>
    }

    // Persiste il cookie solo dopo il consenso marketing
    if (window.marketingConsent) {
      const expires = new Date(Date.now() + 63072000 * 1000).toUTCString();
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST UID] 🍪 Tentativo scrittura cookie fst_uid. consent=true, expires=', expires);
<?php endif; ?>
      document.cookie = cookieName + '=' + encodeURIComponent(window._fstTempUid) + '; expires=' + expires + '; path=/; SameSite=Lax';

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      const persisted = document.cookie.match(/(?:^|; )fst_uid=([^;]+)/);
      if (persisted) {
        console.log('[FST UID] ✅ Cookie fst_uid scritto correttamente:', decodeURIComponent(persisted[1]));
      } else {
        console.warn('[FST UID] ❌ Cookie fst_uid NON trovato dopo la scrittura');
      }
<?php endif; ?>
    } else {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST UID] ⏸️ Cookie fst_uid non persistito: consenso marketing assente');
<?php endif; ?>
    }

    return window._fstTempUid;
  }

  // Esposto globalmente: permette agli handler di consenso (script 2) di persistere fst_uid
  window.fstPersistUid = function() {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST UID] 🔄 fstPersistUid() invocata');
<?php endif; ?>
    const uid = getExternalId();
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    const hasCookie = /(?:^|; )fst_uid=/.test(document.cookie);
    console.log('[FST UID] 📌 Stato dopo fstPersistUid(): uid=' + uid + ', cookiePresente=' + (hasCookie ? 'SI' : 'NO'));
<?php endif; ?>
    return uid;
  };
  
  // ========================================
  // FUNZIONI HELPER PER FBCLID
  // ========================================
  
  // Estrae fbclid dall'URL se presente
  function getFbclidFromUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('fbclid');
  }
  
  // Estrae fbclid dal cookie _fbc se presente
  function getFbclidFromCookie() {
    const fbcCookie = document.cookie.match(/(?:^|; )_fbc=([^;]+)/);
    if (fbcCookie && fbcCookie[1]) {
      // Il cookie _fbc ha formato: fb.1.timestamp.fbclid
      const parts = fbcCookie[1].split('.');
      if (parts.length >= 4) {
        return parts.slice(3).join('.'); // Prende tutto dopo il timestamp
      }
    }
    return null;
  }
  
  // Ottiene fbclid dalla migliore fonte disponibile
  function getFbclid() {
    // Prima prova dal cookie (precedente)
    const cookieFbclid = getFbclidFromCookie();
    if (cookieFbclid) return cookieFbclid;

    // Poi prova dall'URL (più recente)
    const urlFbclid = getFbclidFromUrl();
    if (urlFbclid) return urlFbclid;
    
    return null;
  }
  
  // ========================================
  // INVIO EVENTI (SERVER + FACEBOOK SE CONSENTITO)
  // ========================================

  // Lista degli eventi standard Facebook Pixel (usano fbq('track', ...)).
  // Tutti gli altri eventi personalizzati usano fbq('trackCustom', ...).
  const FB_STANDARD_EVENTS = [
    'PageView','AddPaymentInfo','AddToCart','AddToWishlist','CompleteRegistration',
    'Contact','CustomizeProduct','Donate','FindLocation','InitiateCheckout','Lead',
    'Purchase','Schedule','Search','StartTrial','SubmitApplication','Subscribe','ViewContent'
  ];

  function sendEvent(payload) {
    payload.eventID = getEventId();
    
    // Aggiunge fbclid se disponibile
    const fbclid = getFbclid();
    if (fbclid) {
      payload.fbclid = fbclid;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] 📘 FBCLID trovato:', fbclid);
<?php endif; ?>
    }
    
    // SEMPRE invia al server (per server-side tracking)
    fetch(endpoint, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).finally(clearEventId);
    
    // NUOVO: Se Facebook Pixel è attivo (consenso marketing + pixel inizializzato),
    // invia anche a Facebook con stesso eventID per deduplica
    if (!isTagManagerEnabled && window.marketingConsent && window.fbq && payload.type) {
      let fbEventName = payload.type;
      let fbParams = payload.customData || {};
      
      // Aggiunge external_id ai parametri per tracciabilità cross-platform
      const externalId = getExternalId();
      fbParams.external_id = externalId;

      // NUOVO: Se disponibili (form Lead), passa email/telefono per l'Advanced Matching.
      // fbq hasha automaticamente questi valori lato client prima dell'invio a Facebook.
      if (payload.email || payload.phone) {
        const fbUserData = {};
        if (payload.email) fbUserData.em = payload.email;
        if (payload.phone) fbUserData.ph = payload.phone;
        fbq('set', 'userData', fbUserData);
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] 📘 Advanced Matching userData impostato:', fbUserData);
<?php endif; ?>
      }

      // Mappa i tipi di eventi per Facebook Pixel standard
      if (payload.type === 'ButtonClick') {
        // Per i click sui bottoni, usiamo evento personalizzato
        fbEventName = 'ButtonClick';
      } else if (payload.type === 'FormStart') {
        fbEventName = 'FormStart';
      } else if (payload.type === 'Lead') {
        fbEventName = 'Lead';
      }
      
      // Facebook Pixel distingue eventi standard (track) da eventi personalizzati (trackCustom).
      // Lead, PageView, ViewContent ecc. sono standard; tutti gli altri usano trackCustom.
      const fbMethod = FB_STANDARD_EVENTS.indexOf(fbEventName) !== -1 ? 'track' : 'trackCustom';

      // ========================================
      // DEBUG DETTAGLIATO FACEBOOK PIXEL
      // ========================================
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.group('[FST DEBUG] 📘 Facebook Pixel Event Details');
      console.log('🎯 Event Name:', fbEventName, '| Metodo:', fbMethod);
      console.log('🆔 Event ID:', payload.eventID);
      console.log('👤 External ID:', externalId);
      console.log('📊 Parameters:', fbParams);
      console.log('📄 Payload originale:', payload);
      console.log('🌐 URL:', window.location.href);
      console.log('⏰ Timestamp:', new Date().toISOString());
      console.groupEnd();
<?php endif; ?>
      
      // Invia a Facebook Pixel con eventID per deduplica server/client
      fbq(fbMethod, fbEventName, fbParams, {eventID: payload.eventID});
      
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] 📘 Facebook Pixel event:', fbEventName, 'ID:', payload.eventID, 'External ID:', externalId);
<?php endif; ?>
    } else if (!isTagManagerEnabled && !window.fbq) {
      // Se fbq non è definito, significa che il Pixel non è stato caricato
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.warn('[FST] ⚠️ Facebook Pixel non caricato - consenso mancante o script non inizializzato');
<?php endif; ?>
    } else {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.warn('[FST] ⚠️ Facebook Pixel non inviato - nessun consenso marketing o evento non tracciato');
<?php endif; ?>
    }
    
    return payload.eventID;
  }
  
  // ========================================
  // PAGEVIEW TRACKING
  // ========================================
  const pageViewID = getEventId();
  window.fstLastPageViewId = pageViewID; // Salva l'ID per uso futuro
  window.fstFbPageViewSent = false; // Flag per evitare invii multipli
  
  
  // Prepara dati per PageView
  const pageViewData = {
    action: 'fst_pageview',
    event_id: pageViewID,
    page_url: window.location.href,
    page_title: document.title
  };
  
  // Aggiunge fbclid se disponibile
  const fbclid = getFbclid();
  if (fbclid) {
    pageViewData.fbclid = fbclid;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🖥️ PageView con FBCLID:', fbclid);
<?php endif; ?>
  }
  
  // Invia PageView al server via AJAX (sempre, anche senza consenso)
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
  console.log('[FST] 🖥️ Invio PageView al server:', ajaxUrl);
  console.log('[FST] 🖥️ Event ID:', pageViewID);
  console.log('[FST] 🖥️ Page URL:', window.location.href);
  console.log('[FST] 🖥️ Page Title:', document.title);
  console.log('[FST] 🖥️ Data payload:', pageViewData);
<?php endif; ?>
  
  fetch(ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(pageViewData)
  }).then(response => {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🖥️ Server response status:', response.status);
<?php endif; ?>
    return response.text();
  })
    .then(msg => {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] 🖥️ Server PageView response:', msg);
<?php endif; ?>
    })
    .catch(err => {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.error('[FST] ⚠️ Errore server PageView:', err);
<?php endif; ?>
    });

  // Invia PageView a Facebook Pixel se il consenso è dato e lo script è già caricato
  if (!isTagManagerEnabled && window.marketingConsent && window.fbq && !window.fstFbPageViewSent) {
    <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 📘 Invia PageView a Facebook Pixel (script già presente)');
    <?php endif; ?>
    fbq('track', 'PageView', {}, {eventID: pageViewID});
    window.fstFbPageViewSent = true; // Segna come inviato
  } else if (!isTagManagerEnabled && !window.fbq && window.marketingConsent) {
   // Se fbq non è definito, significa che il Pixel non è stato caricato, ritentiamo fino a 5 tentativi ogni 500ms
    let attempts = 0;
    const maxAttempts = 5;
    const interval = setInterval(() => {
      if (window.fbq) {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] 📘 Facebook Pixel caricato, invio PageView');
<?php endif; ?>
        fbq('track', 'PageView', {}, {eventID: pageViewID});
        // log dettagli se WP_DEBUG è true
        <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('🎯 Page View Event ID:', pageViewID);
        console.log('🌐 URL:', window.location.href);
        console.log('📄 Page Title:', document.title);
        <?php endif; ?>
        
        clearInterval(interval);
      } else if (++attempts === maxAttempts) {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.warn('[FST] ⚠️ Facebook Pixel non caricato dopo 5 tentativi');
<?php endif; ?>
        clearInterval(interval);
      }
    }, 1000);
  } else {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.warn('[FST] ⚠️ Facebook Pixel PageView non inviato - nessun consenso marketing');
<?php endif; ?>
  }
  
  clearEventId();

  // ========================================
  // EVENT LISTENERS PER INTERAZIONI
  // ========================================
  
  /* BUTTON CLICK */
  document.addEventListener('click', e => {
    const btn = e.target.closest('button, a');
    if (!btn) return;
    
    // ========================================
    // ESCLUSIONI BUTTON CLICK
    // ========================================
    
    // Esclude bottoni di submit dei form (già tracciati come Lead)
    if (btn.type === 'submit' || btn.getAttribute('type') === 'submit') {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] ButtonClick saltato - bottone submit (sarà tracciato come Lead):', btn);
<?php endif; ?>
      return;
    }
    
    // Esclude bottoni dentro form che hanno classi di submit
    if (btn.closest('form') && (
        btn.classList.contains('breakdance-form-button__submit') ||
        btn.classList.contains('submit') ||
        btn.classList.contains('form-submit') ||
        btn.querySelector('.button-atom__text')?.textContent.includes('Richiedi')
    )) {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] ButtonClick saltato - bottone submit form (sarà tracciato come Lead):', btn);
<?php endif; ?>
      return;
    }
    
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] ButtonClick', btn);
<?php endif; ?>
    sendEvent({
      type: 'ButtonClick',
      label: btn.id || btn.textContent.trim().slice(0,80),
      page: window.location.href,
      customData: { button_text: btn.textContent.trim().slice(0,80) }
    });
  });

  /* FORM START */
  document.addEventListener('focusin', e => {
    const form = e.target.closest('form');
    if (!form || form.fstStarted) return;
    form.fstStarted = true;
    window.fstFormStarted = true; // Segnale per deepPlus
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] FormStart', form);
<?php endif; ?>
    sendEvent({
      type: 'FormStart',
      label: form.id || form.action || 'generic',
      page: window.location.href,
      customData: { form_name: form.id || 'unnamed' }
    });
    tryDeepPlus(); // Verifica se deepPlus può scattare ora
  });

  /* FORM SUBMIT */
  // Estrae email e telefono dal form inviato, per l'Advanced Matching lato Facebook
  // e per l'hashing lato server (già gestito da fst_rest_event_handler per Lead/FormSubmit).
  function getFormContact(form) {
    const emailField = form.querySelector('input[type="email"], input[name*="email" i], input[id*="email" i]');
    const phoneField = form.querySelector('input[type="tel"], input[name*="phone" i], input[name*="tel" i], input[id*="phone" i], input[id*="tel" i]');
    const contact = {};
    if (emailField && emailField.value.trim()) {
      contact.email = emailField.value.trim().toLowerCase();
    }
    if (phoneField && phoneField.value.trim()) {
      contact.phone = phoneField.value.replace(/\D+/g, '');
    }
    return contact;
  }

  document.addEventListener('submit', e => {
    const form = e.target;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] FormSubmit', form);
<?php endif; ?>
    const leadPayload = {
      type: 'Lead',
      label: form.id || form.action || 'generic',
      page: window.location.href,
      customData: { form_name: form.id || 'unnamed' }
    };
    Object.assign(leadPayload, getFormContact(form));
    sendEvent(leadPayload);
  });
  
  // ========================================
  // SCROLL DEPTH + TEMPO DI VISUALIZZAZIONE
  // ========================================
  // deepInterest: scatta quando l'utente ha scorso >= 60% della pagina
  //               E ha trascorso almeno 90 secondi sulla stessa pagina.
  // deepPlus:     scatta quando deepInterest è già avvenuto
  //               E l'utente ha iniziato a compilare un form (FormStart).

  window.fstFormStarted  = window.fstFormStarted  || false;

  var _fstScrollPct60  = false;
  var _fstTime90s      = false;
  var _fstDeepInterest = false;
  var _fstDeepPlus     = false;

  function tryDeepInterest() {
    if (_fstDeepInterest || !_fstScrollPct60 || !_fstTime90s) return;
    _fstDeepInterest = true;
    window.fstDeepInterest = true;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🎯 deepInterest: scroll 60% + 90 secondi raggiunti');
<?php endif; ?>
    sendEvent({
      type: 'deepInterest',
      label: 'scroll60_time90s',
      page: window.location.href,
      customData: { scroll_pct: 60, time_on_page_sec: 90 }
    });
    tryDeepPlus();
  }

  function tryDeepPlus() {
    if (_fstDeepPlus || !_fstDeepInterest || !window.fstFormStarted) return;
    _fstDeepPlus = true;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🎯 deepPlus: deepInterest + formStart entrambi raggiunti');
<?php endif; ?>
    sendEvent({
      type: 'deepPlus',
      label: 'deepInterest_formStart',
      page: window.location.href,
      customData: { scroll_pct: 60, time_on_page_sec: 90 }
    });
  }

  // Timer: 90 secondi sulla pagina
  setTimeout(function() {
    _fstTime90s = true;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] ⏱️ 90 secondi trascorsi sulla pagina');
<?php endif; ?>
    tryDeepInterest();
  }, 90000);

  // Scroll depth: rilevamento scroll >= 60%.
  // Pagine brevi (docHeight <= 0): l'intera pagina è visibile senza scorrere,
  // quindi il 60% è già raggiunto; si segna subito così deepInterest dipende solo dal timer.
  (function() {
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    if (docHeight <= 0) {
      _fstScrollPct60 = true;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] 📜 Pagina corta: scroll 60% considerato raggiunto');
<?php endif; ?>
      return; // tryDeepInterest verrà chiamata dal setTimeout
    }
    function onScroll() {
      if (_fstScrollPct60) {
        window.removeEventListener('scroll', onScroll);
        return;
      }
      var scrolled = window.scrollY || window.pageYOffset;
      if ((scrolled / docHeight) * 100 >= 60) {
        _fstScrollPct60 = true;
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] 📜 Scroll 60% raggiunto');
<?php endif; ?>
        window.removeEventListener('scroll', onScroll);
        tryDeepInterest();
      }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
  })();

  // ========================================
  // INIZIALIZZAZIONE LISTENER CONSENSO
  // ========================================
})();
</script>
<script data-category="marketing">
(function(){
  const isTagManagerEnabled = <?php echo $gtm_enabled ? 'true' : 'false'; ?>;
  const consentCookie = window.consentCookieName || '';

  function getCookieValue(name){
    if (!name) return null;
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]+)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function findCookieName(pattern){
    const row = document.cookie.split('; ').find(function(cookie){
      return pattern.test(cookie.split('=')[0]);
    });
    return row ? row.split('=')[0] : null;
  }

  function identifyCMPs(){
    const scripts = Array.prototype.map.call(document.scripts, function(script){
      return script.src || '';
    });
    const detected = [];

    function add(name, evidence){
      if (!detected.some(function(item){ return item.name === name; })) {
        detected.push({ name: name, evidence: evidence });
      }
    }

    const iubendaCookie = findCookieName(/^_iub_cs-\d+$/);
    if (window._iub || iubendaCookie || scripts.some(function(src){ return /iubenda\.com/i.test(src); })) {
      add('iubenda', {
        global: !!window._iub,
        apiReady: !!(window._iub && window._iub.cs && window._iub.cs.api),
        cookie: iubendaCookie,
        script: scripts.find(function(src){ return /iubenda\.com/i.test(src); }) || null
      });
    }

    if (typeof window.cmplz_has_consent === 'function' || getCookieValue('cmplz_marketing') !== null || scripts.some(function(src){ return /complianz/i.test(src); })) {
      add('complianz', {
        apiReady: typeof window.cmplz_has_consent === 'function',
        cookie: getCookieValue('cmplz_marketing') !== null ? 'cmplz_marketing' : null
      });
    }

    if (window.Cookiebot || getCookieValue('CookieConsent') !== null || scripts.some(function(src){ return /cookiebot|consent\.cookiebot/i.test(src); })) {
      add('cookiebot', {
        apiReady: !!window.Cookiebot,
        cookie: getCookieValue('CookieConsent') !== null ? 'CookieConsent' : null
      });
    }

    if (window.OneTrust || typeof window.OnetrustActiveGroups === 'string' || getCookieValue('OptanonConsent') !== null || scripts.some(function(src){ return /onetrust|cookielaw/i.test(src); })) {
      add('onetrust', {
        apiReady: !!window.OneTrust,
        activeGroups: window.OnetrustActiveGroups || null,
        cookie: getCookieValue('OptanonConsent') !== null ? 'OptanonConsent' : null
      });
    }

    if (consentCookie && getCookieValue(consentCookie) !== null) {
      add('custom', { cookie: consentCookie });
    }

    window.fstDetectedCMPs = detected;
    return detected;
  }

  function readIubendaPreferences(){
    try {
      if (window._iub && window._iub.cs && window._iub.cs.api && typeof window._iub.cs.api.getPreferences === 'function') {
        return window._iub.cs.api.getPreferences();
      }
    } catch(e) {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.warn('[FST CMP] iubenda getPreferences() ha generato un errore:', e);
<?php endif; ?>
    }
    return null;
  }

  function iubendaMarketingConsent(preferences){
    if (!preferences || typeof preferences !== 'object') return null;
    const purposes = preferences.purposes && typeof preferences.purposes === 'object'
      ? preferences.purposes
      : preferences;
    if (purposes[5] === true || purposes['5'] === true || purposes.adv === true) return true;
    if (preferences.consent === true && !preferences.purposes) return true;
    if (preferences.consent === false) return false;
    if (preferences.purposes && Object.prototype.hasOwnProperty.call(purposes, '5')) return false;
    return null;
  }

  function logCMPDetection(context){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    const cmps = identifyCMPs();
    const iubendaPreferences = readIubendaPreferences();
    console.group('[FST CMP] Identificazione CMP [' + context + ']');
    console.log('CMP rilevati:', cmps.length ? cmps : 'nessuno');
    console.log('Cookie visibili:', document.cookie ? document.cookie.split('; ').map(function(row){ return row.split('=')[0]; }) : []);
    if (cmps.some(function(cmp){ return cmp.name === 'iubenda'; })) {
      console.log('iubenda preferences:', iubendaPreferences);
      console.log('iubenda marketing calcolato:', iubendaMarketingConsent(iubendaPreferences));
    }
    console.log('Consenso marketing finale:', hasMarketingConsent());
    console.groupEnd();
<?php else : ?>
    identifyCMPs();
<?php endif; ?>
  }

  function logConsentSnapshot(context){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    const consentValue = getCookieValue(consentCookie);
    const uidValue = getCookieValue('fst_uid');
    console.log('[FST CONSENT] 📋 Snapshot [' + context + ']', {
      marketingConsent: !!window.marketingConsent,
      consentCookieName: consentCookie,
      consentCookieValue: consentValue,
      fstUidPresent: !!uidValue,
      fstUidValue: uidValue,
      fbqLoaded: !!window.fbq,
      page: window.location.href,
      ts: new Date().toISOString()
    });
<?php endif; ?>
  }

  function hasMarketingConsent(){
    // 1. Cookie custom configurato nelle impostazioni (opzionale)
    if (consentCookie) {
      var match = document.cookie.match(new RegExp('(?:^|; )' + consentCookie + '=([^;]+)'));
      if (match && decodeURIComponent(match[1]) === 'allow') return true;
    }

    // 2. Complianz
    var cm = document.cookie.match(/(?:^|; )cmplz_marketing=([^;]+)/);
    if (cm && decodeURIComponent(cm[1]) === 'allow') return true;

    // Preferisce le API ufficiali quando il CMP è disponibile nel browser.
    if (typeof window.cmplz_has_consent === 'function') {
      try { if (window.cmplz_has_consent('marketing')) return true; } catch(e) {}
    }

    // 3. iubenda: consenso globale oppure purpose 5 (Marketing)
    var iubendaApiConsent = iubendaMarketingConsent(readIubendaPreferences());
    if (iubendaApiConsent !== null) return iubendaApiConsent;
    var iub = document.cookie.match(/(?:^|; )_iub_cs-\d+=([^;]+)/);
    if (iub) { try { var d = JSON.parse(decodeURIComponent(iub[1])); var iubCookieConsent = iubendaMarketingConsent(d); if (iubCookieConsent !== null) return iubCookieConsent; } catch(e) {} }

    // 4. Cookiebot: CookieConsent con marketing:true
    if (window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent.marketing === true) return true;
    var cb = document.cookie.match(/(?:^|; )CookieConsent=([^;]+)/);
    if (cb) { try { if (decodeURIComponent(cb[1]).indexOf('marketing:true') !== -1) return true; } catch(e) {} }

    // 5. OneTrust: OptanonConsent con groups C0004:1 (C0004 = Targeting/Pubblicità, :1 = consenso accordato)
    if (typeof window.OnetrustActiveGroups === 'string' && /(?:^|,)C0004(?:,|$)/.test(window.OnetrustActiveGroups)) return true;
    var ot = document.cookie.match(/(?:^|; )OptanonConsent=([^;]+)/);
    if (ot) { try { var g = new URLSearchParams(decodeURIComponent(ot[1])).get('groups') || ''; if (g.indexOf('C0004:1') !== -1) return true; } catch(e) {} }

    return false;
  }
  window.hasMarketingConsent = hasMarketingConsent;

  const fbPixelScriptUrl = '<?php echo esc_js( plugin_dir_url( __FILE__ ) . '../assets/js/facebook-pixel.js' ); ?>';

  function loadFacebookPixelDynamically(){
    if(isTagManagerEnabled){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] ⏸️ Facebook Pixel diretto non caricato: gestito da Tag Manager');
<?php endif; ?>
      return;
    }
    if(window.fbq){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] ✅ Facebook Pixel già caricato');
<?php endif; ?>
      return;
    }

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST] 🔄 Verifica consenso via AJAX...');
<?php endif; ?>
    fetch(window.fstAjaxUrl, {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({
        action:'ati_reload_facebook_pixel',
        nonce:'<?php echo wp_create_nonce( "ati_reload_pixel" ); ?>'
      })
    }).then(r=>r.json()).then(data=>{
      if(data.success && data.pixel_id){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] 🔄 Caricamento dinamico Facebook Pixel ID:', data.pixel_id);
<?php endif; ?>
        window.atiFbPixelId = data.pixel_id;
        window.atiFbPixelDebug = <?php echo defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false'; ?>;
        const s = document.createElement('script');
        s.defer = true;
        s.src = fbPixelScriptUrl;
        s.onload = function(){
          // Una volta caricato lo script, invia l'evento PageView se non è già stato fatto
          if (window.fbq && !window.fstFbPageViewSent) {
            const pageViewID = window.fstLastPageViewId;
            if (pageViewID) {
              <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
              console.log('[FST] 📘 Invia PageView a Facebook Pixel (caricamento dinamico)', { eventID: pageViewID });
              <?php endif; ?>
              window.fbq('track', 'PageView', {}, { eventID: pageViewID });
              window.fstFbPageViewSent = true; // Segna come inviato
            }
          }
        };
        document.head.appendChild(s);
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] ✅ Facebook Pixel caricato dinamicamente');
<?php endif; ?>
      }else{
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] ⚠️ Facebook Pixel non caricato:', data.message);
<?php endif; ?>
      }
    }).catch(err=>{
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.error('[FST] ❌ Errore caricamento Facebook Pixel:', err);
<?php endif; ?>
    });
  }

  function removeFacebookPixel(){
    if(isTagManagerEnabled){
      return;
    }
    if(window.fbq){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.log('[FST] 🔄 Rimozione Facebook Pixel...');
<?php endif; ?>
      window.fbq = function(){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] ⚠️ Facebook Pixel disabilitato - consenso revocato');
<?php endif; ?>
      };
    }
  }

  let currentConsent = hasMarketingConsent();

  function syncConsentState(context){
    // I CMP aggiornano cookie/dataLayer in momenti leggermente diversi: ogni segnale
    // deve rileggere la sorgente di verità e non dedurre lo stato dal nome evento.
    const newConsent = hasMarketingConsent();
    const changed = newConsent !== currentConsent;
    currentConsent = newConsent;
    window.marketingConsent = newConsent;

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST CONSENT] Sincronizzazione [' + context + ']:', newConsent);
<?php endif; ?>

    if(newConsent){
      window.fstPersistUid && window.fstPersistUid();
      loadFacebookPixelDynamically();
    }else{
      removeFacebookPixel();
    }

<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    if(changed) logConsentSnapshot('consent-change-' + context);
<?php endif; ?>
  }

  function syncConsentAfterCmpUpdate(context){
    // Lascia terminare al CMP la scrittura del cookie prima di rileggerlo.
    window.setTimeout(function(){ syncConsentState(context); }, 0);
  }

  function setupConsentListener(){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    logConsentSnapshot('setup-start');
<?php endif; ?>
    setInterval(()=>{
      const newConsent = hasMarketingConsent();
      if(newConsent !== currentConsent){
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        console.log('[FST] 🔄 Cambio consenso rilevato:', currentConsent, '->', newConsent);
        logConsentSnapshot('consent-change-detected-before-update');
<?php endif; ?>
        syncConsentState('cookie-poll');
      }
    },500);
  }

  // ========================================
  // LISTENER CONSENSO MULTI-BANNER
  // ========================================

  // Complianz e iubenda pubblicano gli eventi d'integrazione GTM nel dataLayer,
  // non come CustomEvent DOM. Intercetta sia accettazione sia rifiuto/preferenze.
  window.dataLayer = window.dataLayer || [];
  (function(dataLayer){
    const originalPush = dataLayer.push;
    dataLayer.push = function(){
      const result = originalPush.apply(dataLayer, arguments);
      Array.prototype.forEach.call(arguments, function(item){
        const eventName = item && item.event;
        if (typeof eventName !== 'string') return;
        if (/^iubenda_(?:consent_|preference_)/.test(eventName) || eventName === 'cmplz_event_marketing') {
          syncConsentAfterCmpUpdate('dataLayer-' + eventName);
        }
      });
      return result;
    };
  })(window.dataLayer);

  // Complianz espone anche questo hook DOM ufficiale quando abilita una categoria.
  document.addEventListener('cmplz_enable_category', function(event) {
    const category = event && event.detail ? event.detail.category : null;
    if (!category || category === 'marketing') {
      syncConsentAfterCmpUpdate('cmplz_enable_category');
    }
  });

  // Compatibilità con il bridge iubenda incluso nel progetto. Lo stato non viene
  // preso da event.detail: viene sempre riletto tramite API/cookie iubenda.
  document.addEventListener('fstMarketingConsentAccepted', function() {
    syncConsentAfterCmpUpdate('iubenda-bridge-accepted');
  });
  document.addEventListener('fstMarketingConsentRevoked', function() {
    syncConsentAfterCmpUpdate('iubenda-bridge-revoked');
  });
  document.addEventListener('fstIubendaPreferencesChanged', function() {
    syncConsentAfterCmpUpdate('iubenda-bridge-preferences-changed');
  });

  // Cookiebot
  window.addEventListener('CookiebotOnAccept', function() {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST CONSENT] ✅ Evento CookiebotOnAccept ricevuto');
<?php endif; ?>
    syncConsentAfterCmpUpdate('CookiebotOnAccept');
  });

  window.addEventListener('CookiebotOnDecline', function() {
    syncConsentAfterCmpUpdate('CookiebotOnDecline');
  });

  // OneTrust
  window.addEventListener('OneTrustGroupsUpdated', function() {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST CONSENT] 🔄 Evento OneTrustGroupsUpdated ricevuto');
<?php endif; ?>
    syncConsentAfterCmpUpdate('OneTrustGroupsUpdated');
  });

  // Evento custom configurato nelle impostazioni
  <?php $custom_event = get_option( 'ati_consent_custom_event', '' ); ?>
  <?php if ( ! empty( $custom_event ) ) : ?>
  document.addEventListener('<?php echo esc_js( $custom_event ); ?>', function() {
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.log('[FST CONSENT] ✅ Evento custom <?php echo esc_js( $custom_event ); ?> ricevuto');
<?php endif; ?>
    syncConsentAfterCmpUpdate('custom-<?php echo esc_js( $custom_event ); ?>');
  });
  <?php endif; ?>

  window.marketingConsent = hasMarketingConsent();
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
  logConsentSnapshot('initial-marketing-consent');
<?php endif; ?>

  logCMPDetection('iniziale');
  // Alcuni CMP sono asincroni. Ripete solo l'identificazione durante l'avvio,
  // mentre il controllo del consenso continua nel polling ordinario.
  let cmpDetectionAttempts = 0;
  const cmpDetectionTimer = window.setInterval(function(){
    cmpDetectionAttempts++;
    const previous = JSON.stringify(window.fstDetectedCMPs || []);
    const detected = identifyCMPs();
    if (JSON.stringify(detected) !== previous) logCMPDetection('asincrona-' + cmpDetectionAttempts);
    const allDetectedReady = detected.length > 0 && detected.every(function(cmp){
      return cmp.name === 'custom' || cmp.evidence.apiReady || cmp.evidence.cookie;
    });
    if (allDetectedReady || cmpDetectionAttempts >= 10) window.clearInterval(cmpDetectionTimer);
  }, 1000);
  
  // TRIGGER 1 DISABILITATO: Non caricare automaticamente Facebook Pixel al caricamento pagina
  // Il pixel verrà caricato solo tramite eventi di cambio consenso o eventi Complianz
  // if(window.marketingConsent){
  //   loadFacebookPixelDynamically();
  // }
  
  setupConsentListener();
})();
</script>
<?php } ?>
