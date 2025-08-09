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
                                $this->render_domains_tab();
                                break;
                        case 'settings':
                                $this->render_settings_tab();
                                break;
                        case 'logs':
                                $this->render_logs_tab();
                                break;
                        case 'dashboard':
                        default:
                                echo '<p>' . esc_html__( 'Dashboard content coming soon.', 'porkpress-ssl' ) . '</p>';
                                break;
                }

                echo '</div>';
        }

        /**
         * Render the domains tab for the network admin page.
         */
        public function render_domains_tab() {
                if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                        return;
                }

                $search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
                $status       = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
                $expiry_window = isset( $_GET['expiry'] ) ? absint( wp_unslash( $_GET['expiry'] ) ) : 0;

                $service = new Domain_Service();
                if ( ! $service->has_credentials() ) {
                        printf(
                                '<div class="error"><p>%s</p></div>',
                                esc_html__( 'Porkbun API credentials are missing. Please configure them in the Settings tab.', 'porkpress-ssl' )
                        );
                        return;
                }

                $result = $service->list_domains();
                if ( $result instanceof Porkbun_Client_Error ) {
                        $message = $result->message;
                        if ( $result->status ) {
                                $message = sprintf( 'HTTP %d: %s', $result->status, $message );
                        }
                        printf( '<div class="error"><p>%s</p></div>', esc_html( $message ) );
                        return;
                }

                $domains = $result['domains'] ?? array();
                $domains = array_filter(
                        $domains,
                        function ( $domain ) use ( $search, $status, $expiry_window ) {
                                $name = $domain['domain'] ?? $domain['name'] ?? '';
                                if ( $search && false === stripos( $name, $search ) ) {
                                        return false;
                                }

                                $dns_status = $domain['status'] ?? $domain['dnsstatus'] ?? '';
                                if ( $status && 0 !== strcasecmp( $dns_status, $status ) ) {
                                        return false;
                                }

                                if ( $expiry_window > 0 ) {
                                        $expiry = $domain['expiry'] ?? $domain['expiration'] ?? $domain['exdate'] ?? '';
                                        $time   = strtotime( $expiry );
                                        if ( $time && $time - time() > $expiry_window * DAY_IN_SECONDS ) {
                                                return false;
                                        }
                                }

                                return true;
                        }
                );

                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="porkpress-ssl" />';
                echo '<input type="hidden" name="tab" value="domains" />';
                echo '<p class="search-box">';
                echo '<label class="screen-reader-text" for="domain-search-input">' . esc_html__( 'Search domains', 'porkpress-ssl' ) . '</label>';
                echo '<input type="search" id="domain-search-input" name="s" value="' . esc_attr( $search ) . '" />';
                submit_button( __( 'Search Domains', 'porkpress-ssl' ), '', '', false, array( 'id' => 'search-submit' ) );
                echo '</p>';
                echo '<p class="filter-box">';
                echo '<label for="status-filter">' . esc_html__( 'Status', 'porkpress-ssl' ) . '</label> ';
                echo '<input type="text" id="status-filter" name="status" value="' . esc_attr( $status ) . '" /> ';
                echo '<label for="expiry-filter">' . esc_html__( 'Expiry within (days)', 'porkpress-ssl' ) . '</label> ';
                echo '<input type="number" id="expiry-filter" class="small-text" name="expiry" value="' . esc_attr( $expiry_window ) . '" min="0" /> ';
                submit_button( __( 'Filter', 'porkpress-ssl' ), '', '', false );
                echo '</p>';
                echo '</form>';

                echo '<table class="widefat fixed striped">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__( 'Name', 'porkpress-ssl' ) . '</th>';
                echo '<th>' . esc_html__( 'Type', 'porkpress-ssl' ) . '</th>';
                echo '<th>' . esc_html__( 'Expiry', 'porkpress-ssl' ) . '</th>';
                echo '<th>' . esc_html__( 'DNS Status', 'porkpress-ssl' ) . '</th>';
                echo '</tr></thead><tbody>';

                if ( empty( $domains ) ) {
                        echo '<tr><td colspan="4">' . esc_html__( 'No domains found.', 'porkpress-ssl' ) . '</td></tr>';
                } else {
                        foreach ( $domains as $domain ) {
                                $name       = $domain['domain'] ?? $domain['name'] ?? '';
                                $type       = $domain['type'] ?? '';
                                $expiry     = $domain['expiry'] ?? $domain['expiration'] ?? $domain['exdate'] ?? '';
                                $dns_status = $domain['status'] ?? $domain['dnsstatus'] ?? '';

                                echo '<tr>';
                                echo '<td>' . esc_html( $name ) . '</td>';
                                echo '<td>' . esc_html( $type ) . '</td>';
                                echo '<td>' . esc_html( $expiry ) . '</td>';
                                echo '<td>' . esc_html( $dns_status ) . '</td>';
                                echo '</tr>';
                        }
                }

                echo '</tbody></table>';
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

// Log the settings update without exposing sensitive values.
Logger::info(
    'update_settings',
    array(
        'api_key_changed'    => ! $api_key_locked && isset( $_POST['porkpress_api_key'] ),
        'api_secret_changed' => ! $api_secret_locked && isset( $_POST['porkpress_api_secret'] ),
        'le_staging'         => (bool) $staging,
        'renew_window'       => $renew_window,
        'txt_timeout'        => $txt_timeout,
        'txt_interval'       => $txt_interval,
    ),
    'Settings saved'
);

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
 * Render the logs tab for the network admin page.
 */
        public function render_logs_tab() {
                if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                        return;
                }

                global $wpdb;
                $table_name = $wpdb->prefix . 'porkpress_logs';

                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
                        printf( '<div class="error"><p>%s</p></div>', esc_html__( 'Logs table does not exist.', 'porkpress-ssl' ) );
                        return;
                }

                $severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';

                if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
                        $logs = Logger::get_logs( array( 'severity' => $severity, 'limit' => 0 ) );
                        header( 'Content-Type: text/csv' );
                        header( 'Content-Disposition: attachment; filename="porkpress-logs.csv"' );
                        $fh = fopen( 'php://output', 'w' );
                        fputcsv( $fh, array( 'time', 'user', 'action', 'context', 'result', 'severity' ) );
                        foreach ( $logs as $log ) {
                                $user = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
                                fputcsv( $fh, array( $log['time'], $user ? $user->user_login : '', $log['action'], $log['context'], $log['result'], $log['severity'] ) );
                        }
                        exit;
                }

                $logs = Logger::get_logs( array( 'severity' => $severity ) );

                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="porkpress-ssl" />';
                echo '<input type="hidden" name="tab" value="logs" />';
                echo '<select name="severity">';
                echo '<option value="">' . esc_html__( 'All Severities', 'porkpress-ssl' ) . '</option>';
                foreach ( array( 'info', 'warn', 'error' ) as $sev ) {
                        echo '<option value="' . esc_attr( $sev ) . '"' . selected( $severity, $sev, false ) . '>' . esc_html( ucfirst( $sev ) ) . '</option>';
                }
                echo '</select> ';
                submit_button( __( 'Filter', 'porkpress-ssl' ), 'secondary', '', false );
                echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'export' => 'csv' ) ) ) . '">' . esc_html__( 'Export CSV', 'porkpress-ssl' ) . '</a>';
                echo '</form>';

                echo '<table class="widefat fixed">';
                echo '<thead><tr><th>' . esc_html__( 'Time', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'User', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Action', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Context', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Result', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Severity', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';

                if ( empty( $logs ) ) {
                        echo '<tr><td colspan="6">' . esc_html__( 'No logs found.', 'porkpress-ssl' ) . '</td></tr>';
                } else {
                        foreach ( $logs as $log ) {
                                $user = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
                                echo '<tr>';
                                echo '<td>' . esc_html( $log['time'] ) . '</td>';
                                echo '<td>' . esc_html( $user ? $user->user_login : '' ) . '</td>';
                                echo '<td>' . esc_html( $log['action'] ) . '</td>';
                                echo '<td><code>' . esc_html( $log['context'] ) . '</code></td>';
                                echo '<td>' . esc_html( $log['result'] ) . '</td>';
                                echo '<td>' . esc_html( $log['severity'] ) . '</td>';
                                echo '</tr>';
                        }
                }

                echo '</tbody></table>';
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
