<?php
/**
 * Uninstall WP Auto Featured Image.
 *
 * Removes the plugin's option. Featured images already set on posts are
 * left in place — they are ordinary post thumbnails, not plugin data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wafi_options' );
