<?php

// Adjust the path to wp-load.php according to your WordPress installation.
require_once __DIR__ . '/wp-load.php';

// Load the plugin file directly in case it's not activated via WordPress.
require_once __DIR__ . '/fb-event-tracker/fb-event-tracker.php';

