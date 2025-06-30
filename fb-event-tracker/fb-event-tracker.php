<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Tutto il resto del tuo codice qui rimane identico (già perfetto)


function fb_event_tracker_add_menu() {
    add_menu_page(
        'FB Event Tracker',
        'FB Event Tracker',
        'manage_options',
        'fb-event-tracker',
        'fb_event_tracker_settings_page'
    );
}
add_action('admin_menu', 'fb_event_tracker_add_menu');

function fb_event_tracker_settings_page() {
    ?>
    <div class="wrap">
        <h1>FB Event Tracker</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('fb_event_tracker_settings');
            do_settings_sections('fb_event_tracker');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function fb_event_tracker_settings_init() {
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_token',
        array('sanitize_callback' => 'sanitize_text_field')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_pixel_id',
        array('sanitize_callback' => 'sanitize_text_field')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_destination',
        array('sanitize_callback' => 'sanitize_text_field')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_ga4_enabled',
        array('sanitize_callback' => 'absint')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_ga4_id',
        array('sanitize_callback' => 'sanitize_text_field')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_gtm_enabled',
        array('sanitize_callback' => 'absint')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_gtm_id',
        array('sanitize_callback' => 'sanitize_text_field')
    );
    register_setting(
        'fb_event_tracker_settings',
        'fb_event_tracker_exclude_logged',
        array('sanitize_callback' => 'absint')
    );

    add_settings_section(
        'fb_event_tracker_section',
        __('Settings', 'fb-event-tracker'),
        '__return_false',
        'fb_event_tracker'
    );

    add_settings_field(
        'fb_event_tracker_token',
        __('API Token', 'fb-event-tracker'),
        'fb_event_tracker_token_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_pixel_id',
        __('Pixel ID', 'fb-event-tracker'),
        'fb_event_tracker_pixel_id_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_destination',
        __('Invia eventi a', 'fb-event-tracker'),
        'fb_event_tracker_destination_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_ga4_enabled',
        __('Abilita GA4', 'fb-event-tracker'),
        'fb_event_tracker_ga4_enabled_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_ga4_id',
        __('GA4 Measurement ID', 'fb-event-tracker'),
        'fb_event_tracker_ga4_id_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_gtm_enabled',
        __('Abilita GTM', 'fb-event-tracker'),
        'fb_event_tracker_gtm_enabled_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_gtm_id',
        __('GTM ID', 'fb-event-tracker'),
        'fb_event_tracker_gtm_id_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );

    add_settings_field(
        'fb_event_tracker_exclude_logged',
        __('Escludi utenti loggati', 'fb-event-tracker'),
        'fb_event_tracker_exclude_logged_render',
        'fb_event_tracker',
        'fb_event_tracker_section'
    );
}
add_action('admin_init', 'fb_event_tracker_settings_init');

function fb_event_tracker_token_render() {
    $value = esc_attr(get_option('fb_event_tracker_token', ''));
    echo '<input type="text" name="fb_event_tracker_token" value="' . $value . '" class="regular-text">';
}

function fb_event_tracker_pixel_id_render() {
    $value = esc_attr(get_option('fb_event_tracker_pixel_id', ''));
    echo '<input type="text" name="fb_event_tracker_pixel_id" value="' . $value . '" class="regular-text">';
}

function fb_event_tracker_destination_render() {
    $value = esc_attr(get_option('fb_event_tracker_destination', 'facebook'));
    ?>
    <select name="fb_event_tracker_destination">
        <option value="facebook" <?php selected($value, 'facebook'); ?>>Facebook</option>
        <option value="tag_manager" <?php selected($value, 'tag_manager'); ?>>Tag Manager</option>
    </select>
    <?php
}

function fb_event_tracker_ga4_enabled_render() {
    $value = get_option('fb_event_tracker_ga4_enabled', 0);
    echo '<input type="checkbox" name="fb_event_tracker_ga4_enabled" value="1"' . checked(1, $value, false) . ' />';
}

function fb_event_tracker_ga4_id_render() {
    $value = esc_attr(get_option('fb_event_tracker_ga4_id', ''));
    echo '<input type="text" name="fb_event_tracker_ga4_id" value="' . $value . '" class="regular-text">';
}

function fb_event_tracker_gtm_enabled_render() {
    $value = get_option('fb_event_tracker_gtm_enabled', 0);
    echo '<input type="checkbox" name="fb_event_tracker_gtm_enabled" value="1"' . checked(1, $value, false) . ' />';
}

function fb_event_tracker_gtm_id_render() {
    $value = esc_attr(get_option('fb_event_tracker_gtm_id', ''));
    echo '<input type="text" name="fb_event_tracker_gtm_id" value="' . $value . '" class="regular-text">';
}

function fb_event_tracker_exclude_logged_render() {
    $value = get_option('fb_event_tracker_exclude_logged', 0);
    echo '<input type="checkbox" name="fb_event_tracker_exclude_logged" value="1"' . checked(1, $value, false) . ' />';
}

function fb_event_tracker_send_event($event_name, $event_data = array()) {
    $token      = get_option('fb_event_tracker_token');
    $pixel_id   = get_option('fb_event_tracker_pixel_id');
    $dest       = get_option('fb_event_tracker_destination', 'facebook');

    if (!$token || !$pixel_id) {
        return;
    }

    $payload = array(
        'event_name'       => $event_name,
        'event_time'       => time(),
        'event_source_url' => home_url($_SERVER['REQUEST_URI']),
        'action_source'    => 'website',
    );

    if ($dest === 'tag_manager') {
        echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode(array_merge($payload, $event_data)) . ');</script>';
        return;
    }

    $url = sprintf('https://graph.facebook.com/v15.0/%s/events?access_token=%s', $pixel_id, $token);

    $payload = array(
        'data' => array(
            array_merge(
                $payload,
                $event_data
            )
        )
    );

    wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($payload),
        'blocking' => false,
    ));
}

function fb_event_tracker_page_view() {
    fb_event_tracker_send_event('PageView');
}

function fb_event_tracker_maybe_hook_page_view() {
    $token    = get_option('fb_event_tracker_token');
    $pixel_id = get_option('fb_event_tracker_pixel_id');

    if ($token && $pixel_id) {
        add_action('wp_footer', 'fb_event_tracker_page_view');
    }
}
add_action('init', 'fb_event_tracker_maybe_hook_page_view');

function fb_event_tracker_output_ga4() {
    if (is_admin()) {
        return;
    }
    if (get_option('fb_event_tracker_exclude_logged') && is_user_logged_in()) {
        return;
    }
    $enabled = get_option('fb_event_tracker_ga4_enabled');
    $id      = trim(get_option('fb_event_tracker_ga4_id'));
    if ($enabled && $id) {
        $id_esc = esc_attr($id);
        echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id_esc}\"></script>\n";
        echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id_esc}');gtag('event','page_view');</script>\n";
    }
}
add_action('wp_head', 'fb_event_tracker_output_ga4');

function fb_event_tracker_output_gtm_head() {
    if (is_admin()) {
        return;
    }
    if (get_option('fb_event_tracker_exclude_logged') && is_user_logged_in()) {
        return;
    }
    $enabled = get_option('fb_event_tracker_gtm_enabled');
    $id      = trim(get_option('fb_event_tracker_gtm_id'));
    if ($enabled && $id) {
        $id_esc = esc_attr($id);
        ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'? '&l='+l : '';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo $id_esc; ?>');</script>
<!-- End Google Tag Manager -->
        <?php
    }
}
add_action('wp_head', 'fb_event_tracker_output_gtm_head');

function fb_event_tracker_output_gtm_body() {
    if (is_admin()) {
        return;
    }
    if (get_option('fb_event_tracker_exclude_logged') && is_user_logged_in()) {
        return;
    }
    $enabled = get_option('fb_event_tracker_gtm_enabled');
    $id      = trim(get_option('fb_event_tracker_gtm_id'));
    if ($enabled && $id) {
        $id_esc = esc_attr($id);
        echo "<!-- Google Tag Manager (noscript) -->\n";
        echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$id_esc}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
        echo "<!-- End Google Tag Manager (noscript) -->\n";
    }
}
add_action('wp_body_open', 'fb_event_tracker_output_gtm_body');
