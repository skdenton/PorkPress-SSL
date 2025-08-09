<?php
/**
 * Plugin Name:       PorkPress SSL
 * Description:       Manage SSL certificates via Porkbun.
 * Version:           0.1.8
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

const PORKPRESS_SSL_VERSION = '0.1.8';
const PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS = 'manage_network_domains';
const PORKPRESS_SSL_CAP_REQUEST_DOMAIN       = 'request_domain';
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
       \PorkPress\SSL\Logger::create_table();
       \PorkPress\SSL\Domain_Service::create_alias_table();
        // Grant request capability to site administrators on all sites.
        if ( is_multisite() ) {
                foreach ( get_sites() as $site ) {
                        switch_to_blog( $site->blog_id );
                        $role = get_role( 'administrator' );
                        if ( $role ) {
                                $role->add_cap( PORKPRESS_SSL_CAP_REQUEST_DOMAIN );
                        }
                        restore_current_blog();
                }
        } else {
                $role = get_role( 'administrator' );
                if ( $role ) {
                        $role->add_cap( PORKPRESS_SSL_CAP_REQUEST_DOMAIN );
                }
        }
}
register_activation_hook( __FILE__, 'porkpress_ssl_activate' );

/**
 * Deactivation hook callback.
 */
function porkpress_ssl_deactivate() {
        // Remove request capability from site administrators on all sites.
        if ( is_multisite() ) {
                foreach ( get_sites() as $site ) {
                        switch_to_blog( $site->blog_id );
                        $role = get_role( 'administrator' );
                        if ( $role ) {
                                $role->remove_cap( PORKPRESS_SSL_CAP_REQUEST_DOMAIN );
                        }
                        restore_current_blog();
                }
        } else {
                $role = get_role( 'administrator' );
                if ( $role ) {
                        $role->remove_cap( PORKPRESS_SSL_CAP_REQUEST_DOMAIN );
                }
        }
}
register_deactivation_hook( __FILE__, 'porkpress_ssl_deactivate' );

/**
 * Initialize the plugin.
 */
function porkpress_ssl_init() {
       global $wpdb;

       $table_name = \PorkPress\SSL\Logger::get_table_name();
       if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
               \PorkPress\SSL\Logger::create_table();
       }

       $alias_table = \PorkPress\SSL\Domain_Service::get_alias_table_name();
       if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $alias_table ) ) !== $alias_table ) {
               \PorkPress\SSL\Domain_Service::create_alias_table();
       }

        $admin = new \PorkPress\SSL\Admin();
       $admin->init();

       if ( is_network_admin() && isset( $_GET['page'] ) && 'porkpress-ssl' === $_GET['page'] ) {
               add_filter(
                       'get_site_icon_url',
                       function ( $url ) {
                               return set_url_scheme( $url, 'https' );
                       }
               );
       }
}
add_action( 'plugins_loaded', 'porkpress_ssl_init' );

/**
 * Map meta capabilities for the plugin.
 *
 * @param array  $caps    Primitive capabilities.
 * @param string $cap     Capability being checked.
 * @param int    $user_id User ID.
 *
 * @return array
 */
function porkpress_ssl_map_meta_cap( $caps, $cap, $user_id ) {
        if ( PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS === $cap ) {
                return user_can( $user_id, 'manage_network' ) ? array( 'manage_network' ) : array( 'do_not_allow' );
        }

        return $caps;
}
add_filter( 'map_meta_cap', 'porkpress_ssl_map_meta_cap', 10, 3 );
