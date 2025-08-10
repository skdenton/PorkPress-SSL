<?php
/**
 * Sunrise drop-in loader for PorkPress SSL.
 *
 * Copy this file to wp-content/sunrise.php and add
 * define('SUNRISE', true); to wp-config.php.
 *
 * @package PorkPress\\SSL
 */

if ( defined( 'WP_PLUGIN_DIR' ) ) {
    $loader = WP_PLUGIN_DIR . '/porkpress-ssl/includes/sunrise-loader.php';
    if ( file_exists( $loader ) ) {
        require $loader;
    }
}
