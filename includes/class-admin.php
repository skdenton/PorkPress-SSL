<?php
/**
 * Admin functionality for PorkPress SSL.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {
        /**
         * Initialize hooks.
         */
        public function init() {
                add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
                add_action( 'admin_menu', array( $this, 'register_site_menu' ) );
        }

        /**
         * Register the network admin menu.
         */
        public function register_network_menu() {
                add_menu_page(
                        __( 'PorkPress SSL', 'porkpress-ssl' ),
                        __( 'PorkPress SSL', 'porkpress-ssl' ),
                        \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS,
                        'porkpress-ssl',
                        array( $this, 'render_network_page' )
                );
        }

        /**
         * Register the site admin menu.
         */
        public function register_site_menu() {
                add_menu_page(
                        __( 'Request Domain', 'porkpress-ssl' ),
                        __( 'Request Domain', 'porkpress-ssl' ),
                        \PORKPRESS_SSL_CAP_REQUEST_DOMAIN,
                        'porkpress-ssl-request',
                        array( $this, 'render_site_page' )
                );
        }

        /**
         * Render the network plugin page.
         */
        public function render_network_page() {
                if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'porkpress-ssl' ) );
                }

                $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PorkPress SSL', 'porkpress-ssl' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'porkpress-ssl' ),
			'domains'   => __( 'Domains', 'porkpress-ssl' ),
			'settings'  => __( 'Settings', 'porkpress-ssl' ),
			'logs'      => __( 'Logs', 'porkpress-ssl' ),
		);

		foreach ( $tabs as $tab => $label ) {
			$class = ( $active_tab === $tab ) ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%3$s">%2$s</a>',
				esc_url( add_query_arg( 'tab', $tab, network_admin_url( 'admin.php?page=porkpress-ssl' ) ) ),
				esc_html( $label ),
				esc_attr( $class )
			);
		}

echo '</h2>';

switch ( $active_tab ) {
case 'domains':
echo '<p>' . esc_html__( 'Domains content coming soon.', 'porkpress-ssl' ) . '</p>';
break;
case 'settings':
$this->render_settings_tab();
break;
case 'logs':
echo '<p>' . esc_html__( 'Logs content coming soon.', 'porkpress-ssl' ) . '</p>';
break;
case 'dashboard':
default:
echo '<p>' . esc_html__( 'Dashboard content coming soon.', 'porkpress-ssl' ) . '</p>';
break;
}

echo '</div>';
}

/**
 * Render the settings tab for the network admin page.
 */
public function render_settings_tab() {
$api_key_locked    = defined( 'PORKPRESS_API_KEY' );
$api_secret_locked = defined( 'PORKPRESS_API_SECRET' );

if ( isset( $_POST['porkpress_ssl_settings_nonce'] ) ) {
check_admin_referer( 'porkpress_ssl_settings', 'porkpress_ssl_settings_nonce' );

if ( ! $api_key_locked && isset( $_POST['porkpress_api_key'] ) ) {
update_site_option( 'porkpress_ssl_api_key', sanitize_text_field( wp_unslash( $_POST['porkpress_api_key'] ) ) );
}

if ( ! $api_secret_locked && isset( $_POST['porkpress_api_secret'] ) ) {
update_site_option( 'porkpress_ssl_api_secret', sanitize_text_field( wp_unslash( $_POST['porkpress_api_secret'] ) ) );
}

$staging = isset( $_POST['porkpress_le_staging'] ) ? 1 : 0;
update_site_option( 'porkpress_ssl_le_staging', $staging );

$renew_window = isset( $_POST['porkpress_renew_window'] ) ? absint( wp_unslash( $_POST['porkpress_renew_window'] ) ) : 0;
update_site_option( 'porkpress_ssl_renew_window', $renew_window );

$txt_timeout = isset( $_POST['porkpress_txt_timeout'] ) ? absint( wp_unslash( $_POST['porkpress_txt_timeout'] ) ) : 0;
update_site_option( 'porkpress_ssl_txt_timeout', $txt_timeout );

$txt_interval = isset( $_POST['porkpress_txt_interval'] ) ? absint( wp_unslash( $_POST['porkpress_txt_interval'] ) ) : 0;
update_site_option( 'porkpress_ssl_txt_interval', $txt_interval );

echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'porkpress-ssl' ) . '</p></div>';
}

$api_key    = $api_key_locked ? PORKPRESS_API_KEY : get_site_option( 'porkpress_ssl_api_key', '' );
$api_secret = $api_secret_locked ? PORKPRESS_API_SECRET : get_site_option( 'porkpress_ssl_api_secret', '' );
$staging    = (bool) get_site_option( 'porkpress_ssl_le_staging', 0 );
$renew_window = absint( get_site_option( 'porkpress_ssl_renew_window', 30 ) );
$txt_timeout  = absint( get_site_option( 'porkpress_ssl_txt_timeout', 600 ) );
$txt_interval = absint( get_site_option( 'porkpress_ssl_txt_interval', 30 ) );

echo '<form method="post">';
wp_nonce_field( 'porkpress_ssl_settings', 'porkpress_ssl_settings_nonce' );
echo '<table class="form-table" role="presentation">';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_api_key">' . esc_html__( 'Porkbun API Key', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_api_key" type="text" id="porkpress_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text"' . ( $api_key_locked ? ' readonly' : '' ) . ' /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_api_secret">' . esc_html__( 'Porkbun API Secret', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_api_secret" type="text" id="porkpress_api_secret" value="' . esc_attr( $api_secret ) . '" class="regular-text"' . ( $api_secret_locked ? ' readonly' : '' ) . ' /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row">' . esc_html__( 'Use Let\'s Encrypt Staging', 'porkpress-ssl' ) . '</th>';
echo '<td><label><input name="porkpress_le_staging" type="checkbox" value="1"' . checked( $staging, true, false ) . ' /> ' . esc_html__( 'Enable staging', 'porkpress-ssl' ) . '</label></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_renew_window">' . esc_html__( 'Renewal Window (days)', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_renew_window" type="number" id="porkpress_renew_window" value="' . esc_attr( $renew_window ) . '" class="small-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_txt_timeout">' . esc_html__( 'TXT Record Wait Timeout (seconds)', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_txt_timeout" type="number" id="porkpress_txt_timeout" value="' . esc_attr( $txt_timeout ) . '" class="small-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_txt_interval">' . esc_html__( 'TXT Record Wait Interval (seconds)', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_txt_interval" type="number" id="porkpress_txt_interval" value="' . esc_attr( $txt_interval ) . '" class="small-text" /></td>';
echo '</tr>';
echo '</table>';
submit_button();
echo '</form>';
}

/**
 * Render the site plugin page.
 */
public function render_site_page() {
                if ( ! current_user_can( \PORKPRESS_SSL_CAP_REQUEST_DOMAIN ) ) {
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'porkpress-ssl' ) );
                }

                echo '<div class="wrap">';
                echo '<h1>' . esc_html__( 'Request Domain', 'porkpress-ssl' ) . '</h1>';
                echo '<p>' . esc_html__( 'Domain request form coming soon.', 'porkpress-ssl' ) . '</p>';
                echo '</div>';
        }
}
