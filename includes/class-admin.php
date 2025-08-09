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
				echo '<p>' . esc_html__( 'Settings content coming soon.', 'porkpress-ssl' ) . '</p>';
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
