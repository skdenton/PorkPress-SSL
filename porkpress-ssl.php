<?php
/**
 * Plugin Name:       PorkPress SSL
 * Description:       Manage SSL certificates via Porkbun.
 * Version:           0.4.0
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

const PORKPRESS_SSL_VERSION = '0.4.0';
const PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS = 'manage_network_domains';
const PORKPRESS_SSL_CAP_REQUEST_DOMAIN       = 'request_domain';

if ( ! defined( 'PORKPRESS_CERT_ROOT' ) ) {
        define( 'PORKPRESS_CERT_ROOT', '/etc/letsencrypt' );
}
if ( ! defined( 'PORKPRESS_STATE_ROOT' ) ) {
        define( 'PORKPRESS_STATE_ROOT', '/var/lib/porkpress-ssl' );
}
if ( ! defined( 'PORKPRESS_CERT_NAME' ) ) {
        define( 'PORKPRESS_CERT_NAME', 'porkpress-network' );
}

require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-porkbun-client.php';
require_once __DIR__ . '/includes/class-porkbun-client-dryrun.php';
require_once __DIR__ . '/includes/class-domain-service.php';
require_once __DIR__ . '/includes/class-ssl-service.php';
require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-reconciler.php';
require_once __DIR__ . '/includes/class-txt-propagation-waiter.php';
require_once __DIR__ . '/includes/class-certbot-helper.php';
require_once __DIR__ . '/includes/class-renewal-service.php';
require_once __DIR__ . '/includes/class-notifier.php';
require_once __DIR__ . '/includes/class-runner.php';

\PorkPress\SSL\Renewal_Service::$runner = array( \PorkPress\SSL\Runner::class, 'run' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once __DIR__ . '/includes/class-cli.php';
        \WP_CLI::add_command( 'porkpress ssl', \PorkPress\SSL\CLI::class );
}

/**
 * Activation hook callback.
 */
function porkpress_ssl_activate() {
        \PorkPress\SSL\Logger::create_table();
       \PorkPress\SSL\Domain_Service::create_alias_table();
       if ( ! wp_next_scheduled( 'porkpress_ssl_reconcile' ) ) {
               wp_schedule_event( time(), 'daily', 'porkpress_ssl_reconcile' );
       }
       \PorkPress\SSL\Renewal_Service::maybe_schedule();
        $errors   = array();
        $warnings = array();

       // Verify certbot command.
       $certbot_cmd = get_site_option( 'porkpress_ssl_certbot_cmd', 'certbot' );
       if ( ! \PorkPress\SSL\Runner::command_exists( $certbot_cmd ) ) {
               $errors[] = __( 'Certbot is required but could not be found.', 'porkpress-ssl' );
               \PorkPress\SSL\Logger::error( 'activation_check', array( 'check' => 'certbot' ), 'missing' );
       }

       // Verify dig command.
       if ( ! \PorkPress\SSL\Runner::command_exists( 'dig' ) ) {
               $errors[] = __( 'dig is required but could not be found.', 'porkpress-ssl' );
               \PorkPress\SSL\Logger::error( 'activation_check', array( 'check' => 'dig' ), 'missing' );
       }

        // Ensure required directories are writable.
        foreach ( array( PORKPRESS_CERT_ROOT, PORKPRESS_STATE_ROOT ) as $dir ) {
                if ( ! is_writable( $dir ) ) {
                        $errors[] = sprintf( __( '%s is not writable.', 'porkpress-ssl' ), $dir );
                        \PorkPress\SSL\Logger::error( 'activation_check', array( 'path' => $dir ), 'not_writable' );
                }
        }

        // Detect Apache reload command.
        $apache_cmd = \PorkPress\SSL\Renewal_Service::get_apache_reload_cmd();
        if ( '' === $apache_cmd ) {
                $warnings[] = __( 'No Apache reload command detected; automatic Apache reloads may fail.', 'porkpress-ssl' );
                \PorkPress\SSL\Logger::warn( 'activation_check', array( 'check' => 'apache_reload_cmd' ), 'missing' );
        }

        if ( $errors ) {
                \PorkPress\SSL\Notifier::notify(
                        'error',
                        __( 'PorkPress SSL activation checks failed', 'porkpress-ssl' ),
                        implode( ' ', $errors )
                );
        }

        if ( $warnings ) {
                \PorkPress\SSL\Notifier::notify(
                        'warning',
                        __( 'PorkPress SSL activation warnings', 'porkpress-ssl' ),
                        implode( ' ', $warnings )
                );
        }
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
       $timestamp = wp_next_scheduled( 'porkpress_ssl_reconcile' );
       if ( $timestamp ) {
               wp_unschedule_event( $timestamp, 'porkpress_ssl_reconcile' );
       }
       $renew = wp_next_scheduled( \PorkPress\SSL\Renewal_Service::CRON_HOOK );
       if ( $renew ) {
               wp_unschedule_event( $renew, \PorkPress\SSL\Renewal_Service::CRON_HOOK );
       }
}
register_deactivation_hook( __FILE__, 'porkpress_ssl_deactivate' );

/**
 * Initialize the plugin.
 */
function porkpress_ssl_init() {
        global $wpdb;

	load_plugin_textdomain( 'porkpress-ssl', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

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
      \PorkPress\SSL\Notifier::register();
      \PorkPress\SSL\Renewal_Service::maybe_schedule();

     if ( is_network_admin() && isset( $_GET['page'] ) && 'porkpress-ssl' === sanitize_key( $_GET['page'] ) ) {
             add_filter(
                     'get_site_icon_url',
                     function ( $url ) {
                             return set_url_scheme( $url, 'https' );
                     }
               );
      }
}
add_action( 'plugins_loaded', 'porkpress_ssl_init' );

add_action( 'porkpress_ssl_reconcile', function () {
       $reconciler = new \PorkPress\SSL\Reconciler();
       $apply      = (bool) get_site_option( 'porkpress_ssl_auto_reconcile', 1 );
       $drift      = $reconciler->reconcile_all( $apply );
       if ( array_filter( $drift ) ) {
               \PorkPress\SSL\Logger::info(
                       'reconcile',
                       array(
                               'auto'  => $apply,
                               'drift' => $drift,
                       ),
                       $apply ? 'changes applied' : 'drift detected'
               );
       }
} );

add_action( 'porkpress_ssl_run_issuance', array( '\\PorkPress\\SSL\\SSL_Service', 'run_queue' ) );
add_action( \PorkPress\SSL\Renewal_Service::CRON_HOOK, array( '\\PorkPress\\SSL\\Renewal_Service', 'run' ) );

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

/**
 * Record a domain alias when a new site is created.
 *
 * @param int    $blog_id  Site ID.
 * @param int    $user_id  User ID.
 * @param string $domain   Site domain.
 * @param string $path     Site path.
 * @param int    $site_id  Network ID.
 * @param array  $meta     Meta data for the site.
 */
function porkpress_ssl_handle_new_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
       $service = new \PorkPress\SSL\Domain_Service();
       $service->add_alias( (int) $blog_id, $domain, true, 'active' );
}
add_action( 'wpmu_new_blog', 'porkpress_ssl_handle_new_blog', 10, 6 );

/**
 * Remove domain aliases when a site is deleted.
 *
 * @param int  $blog_id Site ID.
 * @param bool $drop    Whether the site's tables should be dropped.
 */
function porkpress_ssl_handle_delete_blog( $blog_id, $drop ) {
       $service = new \PorkPress\SSL\Domain_Service();
       foreach ( $service->get_aliases( (int) $blog_id ) as $alias ) {
               $service->delete_alias( (int) $blog_id, $alias['domain'] );
       }
}
add_action( 'wpmu_delete_blog', 'porkpress_ssl_handle_delete_blog', 10, 2 );

/**
 * Flag aliases as archived when a site is archived.
 *
 * @param int $blog_id Site ID.
 */
function porkpress_ssl_handle_archive_blog( $blog_id ) {
       $service = new \PorkPress\SSL\Domain_Service();
       foreach ( $service->get_aliases( (int) $blog_id ) as $alias ) {
               $service->update_alias( (int) $blog_id, $alias['domain'], array( 'status' => 'archived' ) );
       }
}
add_action( 'archive_blog', 'porkpress_ssl_handle_archive_blog' );

/**
 * Restore alias status when a site is unarchived.
 *
 * @param int $blog_id Site ID.
 */
function porkpress_ssl_handle_unarchive_blog( $blog_id ) {
       $service = new \PorkPress\SSL\Domain_Service();
       foreach ( $service->get_aliases( (int) $blog_id ) as $alias ) {
               $service->update_alias( (int) $blog_id, $alias['domain'], array( 'status' => 'active' ) );
       }
}
add_action( 'unarchive_blog', 'porkpress_ssl_handle_unarchive_blog' );
