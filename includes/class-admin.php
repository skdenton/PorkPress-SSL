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
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the network admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'PorkPress SSL', 'porkpress-ssl' ),
			__( 'PorkPress SSL', 'porkpress-ssl' ),
			'manage_network',
			'porkpress-ssl',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the plugin page.
	 */
	public function render_page() {
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
}
