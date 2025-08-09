<?php
/**
 * Plugin Name:       PorkPress SSL
 * Description:       Manage SSL certificates via Porkbun.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Network:           true
 * Author:            PorkPress
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       porkpress-ssl
 * Domain Path:       /languages
 *
 * @package PorkPress\SSL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PORKPRESS_SSL_VERSION = '0.1.0';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-porkbun-client.php';
require_once __DIR__ . '/includes/class-domain-service.php';
require_once __DIR__ . '/includes/class-ssl-service.php';
require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-reconciler.php';

/**
 * Activation hook callback.
 */
function porkpress_ssl_activate() {
	// Placeholder for activation logic.
}
register_activation_hook( __FILE__, 'porkpress_ssl_activate' );

/**
 * Deactivation hook callback.
 */
function porkpress_ssl_deactivate() {
	// Placeholder for deactivation logic.
}
register_deactivation_hook( __FILE__, 'porkpress_ssl_deactivate' );

/**
 * Initialize the plugin.
 */
function porkpress_ssl_init() {
	$admin = new \PorkPress\SSL\Admin();
	$admin->init();
}
add_action( 'plugins_loaded', 'porkpress_ssl_init' );
