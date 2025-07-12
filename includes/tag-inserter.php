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
 * Verifica il cookie cmplz_marketing che viene settato da Complianz
 * o altri plugin di gestione cookie GDPR.
 * 
 * @return bool True se il consenso è stato dato, False altrimenti
 */
function ati_has_marketing_consent() {
    // DEBUG: Log controllo consenso
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === CONTROLLO CONSENSO MARKETING ===' );
        error_log( '[ATI DEBUG] Cookie disponibili: ' . json_encode( $_COOKIE ) );
    }
    
    // Controlla il cookie cmplz_marketing
    if ( isset( $_COOKIE['cmplz_marketing'] ) ) {
        $consent_value = $_COOKIE['cmplz_marketing'];
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ATI DEBUG] Cookie cmplz_marketing trovato: ' . $consent_value );
            error_log( '[ATI DEBUG] Consenso valido: ' . ( $consent_value === 'allow' ? 'SI' : 'NO' ) );
        }
        return $consent_value === 'allow';
    }
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] Cookie cmplz_marketing NON trovato' );
    }
    // Se il cookie non esiste, assumiamo nessun consenso per sicurezza GDPR
    return false;
}

/**
 * Output tracking tags in the site head.
 * 
 * Controlla i consensi cookie prima di inserire gli script di tracking.
 * Se cmplz_marketing != 'allow', inserisce solo il tracking server-side.
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

    if ( $fb_enabled && ! empty( $fb_pixel_id ) ) {
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

    if ( $ga_enabled && ! empty( $ga_id ) ) {
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

    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    $gtm_id      = trim( get_option( 'ati_gtm_id', '' ) );

    if ( $gtm_enabled && ! empty( $gtm_id ) ) {
        ?>
        <!-- Google Tag Manager -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?php echo esc_js( $gtm_id ); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php
    }
    
    // DEBUG: Log fine funzione
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[ATI DEBUG] === FINE ati_output_tags() ===' );
    }
}
add_action( 'wp_head', 'ati_output_tags' );

/**
 * Output Google Tag Manager noscript after <body> tag.
 */
function ati_output_gtm_noscript() {
    $gtm_enabled = get_option( 'ati_enable_gtm', false );
    $gtm_id      = trim( get_option( 'ati_gtm_id', '' ) );

    if ( $gtm_enabled && ! empty( $gtm_id ) ) {
        echo "<!-- Google Tag Manager (noscript) -->\n";
        echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm_id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        echo "<!-- End Google Tag Manager (noscript) -->\n";
    }
}
add_action( 'wp_body_open', 'ati_output_gtm_noscript' );


add_action( 'wp_footer', 'fst_inline_tracking_js', 100 );
function fst_inline_tracking_js() { ?>
<script>
// ========================================
// VARIABILI GLOBALI E SETUP
// ========================================
window.fstAjaxUrl = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

(function () {
  const endpoint = '<?php echo esc_js( home_url( '/wp-json/fst/v1/event' ) ); ?>';
  const ajaxUrl = window.fstAjaxUrl;
  
  // ========================================
  // CONTROLLO CONSENSO COOKIE GDPR
  // ========================================
  
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
  // Blocco di debug per il cookie Complianz (visibile solo con WP_DEBUG attivo)
  console.log('[FST DEBUG] Controllo cookie Complianz...');
  const complianzCookie = document.cookie.split('; ').find(row => row.startsWith('cmplz_marketing='));
  if (complianzCookie) {
    console.log('[FST DEBUG] Cookie cmplz_marketing trovato:', complianzCookie);
    console.log('[FST DEBUG] Valore del cookie:', complianzCookie.split('=')[1]);
  } else {
    console.log('[FST DEBUG] Cookie cmplz_marketing non trovato.');
  }
<?php endif; ?>

  function hasMarketingConsent() {
    const match = document.cookie.match(/(?:^|; )cmplz_marketing=([^;]+)/);
    return match && match[1] === 'allow';
  }
  
  const marketingConsent = hasMarketingConsent();
  console.log('[FST] Consenso marketing:', marketingConsent ? '✅ Consentito' : '❌ Non consentito');
  
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
  
  // Genera o recupera external_id persistente (come lato server)
  function getExternalId() {
    const cookieName = 'fst_uid';
    const match = document.cookie.match(new RegExp('(?:^|; )' + cookieName + '=([^;]+)'));
    if (match) return decodeURIComponent(match[1]);
    
    // Se non esiste, genera UUID4 simile al server PHP
    const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      const r = Math.random() * 16 | 0;
      const v = c == 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
    
    // Salva cookie per 2 anni (come lato server)
    const expires = new Date(Date.now() + 63072000 * 1000).toUTCString();
    document.cookie = cookieName + '=' + encodeURIComponent(uuid) + '; expires=' + expires + '; path=/; SameSite=Lax';
    
    return uuid;
  }
  
  // ========================================
  // INVIO EVENTI (SERVER + FACEBOOK SE CONSENTITO)
  // ========================================
  function sendEvent(payload) {
    payload.eventID = getEventId();
    
    // SEMPRE invia al server (per server-side tracking)
    fetch(endpoint, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).finally(clearEventId);
    
    // NUOVO: Se Facebook Pixel è attivo (consenso marketing + pixel inizializzato),
    // invia anche a Facebook con stesso eventID per deduplica
    if (marketingConsent && window.fbq && payload.type) {
      let fbEventName = payload.type;
      let fbParams = payload.customData || {};
      
      // Aggiunge external_id ai parametri per tracciabilità cross-platform
      const externalId = getExternalId();
      fbParams.external_id = externalId;
      
      // Mappa i tipi di eventi per Facebook Pixel standard
      if (payload.type === 'ButtonClick') {
        // Per i click sui bottoni, usiamo evento personalizzato
        fbEventName = 'ButtonClick';
      } else if (payload.type === 'FormStart') {
        fbEventName = 'FormStart';
      } else if (payload.type === 'Lead') {
        fbEventName = 'Lead';
      }
      
      // ========================================
      // DEBUG DETTAGLIATO FACEBOOK PIXEL
      // ========================================
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
      console.group('[FST DEBUG] 📘 Facebook Pixel Event Details');
      console.log('🎯 Event Name:', fbEventName);
      console.log('🆔 Event ID:', payload.eventID);
      console.log('👤 External ID:', externalId);
      console.log('📊 Parameters:', fbParams);
      console.log('📄 Payload originale:', payload);
      console.log('🌐 URL:', window.location.href);
      console.log('⏰ Timestamp:', new Date().toISOString());
      console.groupEnd();
<?php endif; ?>
      
      // Invia a Facebook Pixel con eventID per deduplica server/client
      fbq('track', fbEventName, fbParams, {eventID: payload.eventID});
      
      console.log('[FST] 📘 Facebook Pixel event:', fbEventName, 'ID:', payload.eventID, 'External ID:', externalId);
    }
    
    return payload.eventID;
  }
  
  // ========================================
  // PAGEVIEW TRACKING
  // ========================================
  const pageViewID = getEventId();
  
  // Invia PageView al server via AJAX (sempre, anche senza consenso)
  console.log('[FST] 🖥️ Invio PageView al server:', ajaxUrl);
  console.log('[FST] 🖥️ Event ID:', pageViewID);
  console.log('[FST] 🖥️ Page URL:', window.location.href);
  console.log('[FST] 🖥️ Page Title:', document.title);
  
  fetch(ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      action: 'fst_pageview',
      event_id: pageViewID,
      page_url: window.location.href,
      page_title: document.title
    })
  }).then(response => {
    console.log('[FST] 🖥️ Server response status:', response.status);
    return response.text();
  })
    .then(msg => {
      console.log('[FST] 🖥️ Server PageView response:', msg);
    })
    .catch(err => {
      console.error('[FST] ⚠️ Errore server PageView:', err);
    });
  
// SOLO se c'è consenso: invia anche a Facebook Pixel client-side
if (marketingConsent) {
  // Funzione per aspettare che fbq sia disponibile
  function waitForFbq(callback, maxRetries = 20, retryCount = 0) {
    if (window.fbq && typeof window.fbq === 'function') {
      callback();
    } else if (retryCount < maxRetries) {
      setTimeout(() => waitForFbq(callback, maxRetries, retryCount + 1), 100);
    } else {
      console.log('[FST] ⚠️ Facebook Pixel (fbq) non disponibile dopo', maxRetries, 'tentativi');
    }
  }
  
  // Aspetta che fbq sia disponibile e poi invia PageView
  waitForFbq(() => {
    const externalId = getExternalId();
    const pageViewParams = {
      external_id: externalId
    };
    
    // ========================================
    // DEBUG DETTAGLIATO PAGEVIEW FACEBOOK PIXEL
    // ========================================
<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
    console.group('[FST DEBUG] 📘 Facebook Pixel PageView Details');
    console.log('🎯 Event Name: PageView');
    console.log('🆔 Event ID:', pageViewID);
    console.log('👤 External ID:', externalId);
    console.log('📊 Parameters:', pageViewParams);
    console.log('🌐 URL:', window.location.href);
    console.log('📄 Page Title:', document.title);
    console.log('⏰ Timestamp:', new Date().toISOString());
    console.groupEnd();
<?php endif; ?>
    
    fbq('track', 'PageView', pageViewParams, {eventID: pageViewID});
    console.log('[FST] 📘 Facebook Pixel PageView ID:', pageViewID, 'External ID:', externalId);
  });
} else {
  console.log('[FST] ⚠️ PageView Facebook Pixel saltato - nessun consenso marketing');
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
      console.log('[FST] ButtonClick saltato - bottone submit (sarà tracciato come Lead):', btn);
      return;
    }
    
    // Esclude bottoni dentro form che hanno classi di submit
    if (btn.closest('form') && (
        btn.classList.contains('breakdance-form-button__submit') ||
        btn.classList.contains('submit') ||
        btn.classList.contains('form-submit') ||
        btn.querySelector('.button-atom__text')?.textContent.includes('Richiedi')
    )) {
      console.log('[FST] ButtonClick saltato - bottone submit form (sarà tracciato come Lead):', btn);
      return;
    }
    
    console.log('[FST] ButtonClick', btn);
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
    console.log('[FST] FormStart', form);
    sendEvent({
      type: 'FormStart',
      label: form.id || form.action || 'generic',
      page: window.location.href,
      customData: { form_name: form.id || 'unnamed' }
    });
  });

  /* FORM SUBMIT */
  document.addEventListener('submit', e => {
    const form = e.target;
    console.log('[FST] FormSubmit', form);
    sendEvent({
      type: 'Lead',
      label: form.id || form.action || 'generic',
      page: window.location.href,
      customData: { form_name: form.id || 'unnamed' }
    });
  });
})();
</script>
<?php } ?>