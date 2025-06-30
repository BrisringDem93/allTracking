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
