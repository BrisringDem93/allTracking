<?php
/**
 * Tag inserter functions for Quick Tracking Integration plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Output tracking tags in the site head.
 */
function ati_output_tags() {
    $disable_logged_in = get_option( 'ati_disable_logged_in', false );

    if ( $disable_logged_in && is_user_logged_in() ) {
        return;
    }

    $fb_enabled  = get_option( 'ati_enable_fb', false );
    $fb_pixel_id = trim( get_option( 'ati_fb_pixel_id', '' ) );

    if ( $fb_enabled && ! empty( $fb_pixel_id ) ) {
        ?>
        <!-- Facebook Pixel Code -->
        <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod ?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js( $fb_pixel_id ); ?>');
        fbq('track', 'PageView');
        </script>
        <noscript>
            <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $fb_pixel_id ); ?>&ev=PageView&noscript=1" />
        </noscript>
        <!-- End Facebook Pixel Code -->
        <?php
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
