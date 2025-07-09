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
    // Controlla il cookie cmplz_marketing
    if ( isset( $_COOKIE['cmplz_marketing'] ) ) {
        return $_COOKIE['cmplz_marketing'] === 'allow';
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
    // STEP 1: Controllo se il tracking è disabilitato per utenti loggati
    $disable_logged_in = get_option( 'ati_disable_logged_in', false );
    if ( $disable_logged_in && is_user_logged_in() ) {
        return;
    }

    // STEP 2: Controllo consenso marketing GDPR
    $has_marketing_consent = ati_has_marketing_consent();

    // STEP 3: Gestione Facebook Pixel basata sul consenso
    $fb_enabled  = get_option( 'ati_enable_fb', false );
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );

    if ( $fb_enabled && ! empty( $fb_pixel_id ) ) {
        if ( $has_marketing_consent ) {
            // ✅ Consenso dato: Carica Facebook Pixel completo (client-side)
            require_once plugin_dir_path( __FILE__ ) . 'facebook-pixel.php';
            ati_output_facebook_pixel();
        } else {
            // ⚠️ Nessun consenso: Solo tracking server-side (senza cookie/pixel)
            // Il JavaScript invierà comunque eventi al server per il server-side tracking
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
  function hasMarketingConsent() {
    const match = document.cookie.match(/(?:^|; )cmplz_marketing=([^;]+)/);
    return match && match[1] === 'allow';
  }
  
  const marketingConsent = hasMarketingConsent();
  console.log('[FST] Consenso marketing:', marketingConsent ? '✅ Consentito' : '❌ Non consentito');
  
  // ========================================
  // FUNZIONI HELPER EVENT ID
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
  if (marketingConsent && window.fbq) {
    fbq('track', 'PageView', {}, {eventID: pageViewID});
    console.log('[FST] 📘 Facebook Pixel PageView ID:', pageViewID);
  }
  
  clearEventId();

  // ========================================
  // EVENT LISTENERS PER INTERAZIONI
  // ========================================
  
  /* BUTTON CLICK */
  document.addEventListener('click', e => {
    const btn = e.target.closest('button, a');
    if (!btn) return;
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
<?php }
