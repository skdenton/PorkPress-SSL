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
                add_action( 'network_admin_menu', array( $this, 'register_site_alias_page' ) );
                add_action( 'admin_menu', array( $this, 'register_site_menu' ) );
               add_action( 'wp_ajax_porkpress_ssl_bulk_action', array( $this, 'handle_bulk_action' ) );
               add_action( 'admin_notices', array( $this, 'sunrise_notice' ) );
               add_action( 'network_admin_notices', array( $this, 'sunrise_notice' ) );
               add_filter( 'network_edit_site_nav_links', array( $this, 'add_site_nav_link' ) );
        }

       /**
        * Display a notice if SUNRISE is not enabled.
        */
       public function sunrise_notice() {
               if ( ! is_multisite() || defined( 'SUNRISE' ) || ! current_user_can( 'manage_network' ) ) {
                       return;
               }

               printf(
                       '<div class="notice notice-warning"><p>%s</p></div>',
                       esc_html__( "Add define('SUNRISE', true); to wp-config.php to enable domain aliasing.", 'porkpress-ssl' )
               );
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

               $simulate_steps = '';
               if ( isset( $_POST['porkpress_ssl_simulate_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_simulate_nonce'] ), 'porkpress_ssl_simulate' ) ) {
                       $dry_service = new Domain_Service( null, true );
                       $reconciler  = new Reconciler( $dry_service );
                       $drift       = $reconciler->reconcile_all( false );
                       $steps       = array();
                       foreach ( $drift['missing_aliases'] as $item ) {
                               $steps[] = sprintf( __( 'Would add alias %1$s to site %2$d', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                       }
                       foreach ( $drift['stray_aliases'] as $item ) {
                               $steps[] = sprintf( __( 'Would remove alias %1$s from site %2$d', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                       }
                       foreach ( $drift['disabled_sites'] as $item ) {
                               $steps[] = sprintf( __( 'Would unarchive site %2$d (domain %1$s)', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                       }
                       $simulate_steps  = '<div class="notice notice-info"><p>' . esc_html__( 'Simulation results (no changes applied):', 'porkpress-ssl' ) . '</p>';
                       if ( empty( $steps ) ) {
                               $simulate_steps .= '<p>' . esc_html__( 'No actions required.', 'porkpress-ssl' ) . '</p>';
                       } else {
                               $simulate_steps .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $steps ) ) . '</li></ul>';
                       }
                       $simulate_steps .= '</div>';
               }

               echo '<form method="post" style="margin-bottom:1em;">';
               wp_nonce_field( 'porkpress_ssl_simulate', 'porkpress_ssl_simulate_nonce' );
               submit_button( __( 'Simulate', 'porkpress-ssl' ), 'secondary', 'simulate_now', false );
               echo '</form>';

               if ( $simulate_steps ) {
                       echo $simulate_steps; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
               }

               if ( ! $service->has_credentials() ) {
                       printf(
                               '<div class="error"><p>%s</p></div>',
                               esc_html__( 'Porkbun API credentials are missing. Please configure them in the Settings tab.', 'porkpress-ssl' )
                       );
                       return;
               }

               if ( isset( $_POST['porkpress_ssl_reconcile_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_reconcile_nonce'] ), 'porkpress_ssl_reconcile' ) ) {
                       $reconciler = new Reconciler( $service );
                       $reconciler->reconcile_all();
                       echo '<div class="updated"><p>' . esc_html__( 'Reconciliation complete.', 'porkpress-ssl' ) . '</p></div>';
               }

               echo '<form method="post" style="margin-bottom:1em;">';
               wp_nonce_field( 'porkpress_ssl_reconcile', 'porkpress_ssl_reconcile_nonce' );
               submit_button( __( 'Reconcile Now', 'porkpress-ssl' ), 'secondary', 'reconcile_now', false );
               echo '</form>';

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

               wp_enqueue_script( 'porkpress-domain-bulk', plugins_url( '../assets/domain-bulk.js', __FILE__ ), array( 'jquery' ), PORKPRESS_SSL_VERSION, true );
               wp_localize_script( 'porkpress-domain-bulk', 'porkpressBulk', array(
                       'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                       'nonce'   => wp_create_nonce( 'porkpress_ssl_bulk_action' ),
               ) );

               echo '<form id="porkpress-domain-actions" method="post">';
               echo '<table class="widefat fixed striped">';
               echo '<thead><tr>';
               echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all" /></td>';
               echo '<th>' . esc_html__( 'Name', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Expiry', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'DNS Status', 'porkpress-ssl' ) . '</th>';
               echo '</tr></thead><tbody>';

                if ( empty( $domains ) ) {
               echo '<tr><td colspan="4">' . esc_html__( 'No domains found.', 'porkpress-ssl' ) . '</td></tr>';
                } else {
                        foreach ( $domains as $domain ) {
                               $name       = $domain['domain'] ?? $domain['name'] ?? '';
                               $expiry     = $domain['expiry'] ?? $domain['expiration'] ?? $domain['exdate'] ?? '';
                               $dns_status = $domain['status'] ?? $domain['dnsstatus'] ?? '';

                               echo '<tr>';
                               echo '<th scope="row" class="check-column"><input type="checkbox" name="domains[]" value="' . esc_attr( $name ) . '" /></th>';
                               echo '<td>' . esc_html( $name ) . '</td>';
                               echo '<td>' . esc_html( $expiry ) . '</td>';
                               echo '<td>' . esc_html( $dns_status ) . '</td>';
                               echo '</tr>';
                       }
               }

               echo '</tbody></table>';
               echo '<div class="tablenav bottom">';
               echo '<div class="alignleft actions bulkactions">';
               echo '<select name="bulk_action"><option value="">' . esc_html__( 'Bulk actions', 'porkpress-ssl' ) . '</option>';
               echo '<option value="attach">' . esc_html__( 'Attach to site', 'porkpress-ssl' ) . '</option>';
               echo '<option value="detach">' . esc_html__( 'Detach from site', 'porkpress-ssl' ) . '</option>';
               echo '</select> ';
               echo '<input type="number" name="site_id" class="small-text" placeholder="' . esc_attr__( 'Site ID', 'porkpress-ssl' ) . '" /> ';
               submit_button( __( 'Apply', 'porkpress-ssl' ), 'secondary', 'apply', false );
               echo '</div>';
               echo '<div id="porkpress-domain-progress" class="alignleft actions"></div>';
               echo '</div>';
               echo '</form>';
       }

       /**
        * AJAX handler for domain bulk actions.
        */
       public function handle_bulk_action() {
               check_ajax_referer( 'porkpress_ssl_bulk_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $action   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
               $site_id  = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
               $title    = isset( $_POST['new_site_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_title'] ) ) : '';
               $email    = isset( $_POST['new_site_email'] ) ? sanitize_email( wp_unslash( $_POST['new_site_email'] ) ) : '';
               $template = isset( $_POST['new_site_template'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_template'] ) ) : '';
               $override = isset( $_POST['override'] ) ? sanitize_text_field( wp_unslash( $_POST['override'] ) ) : '';

               $service = new Domain_Service();

               switch ( $action ) {
                       case 'attach':
                               if ( 'CONFIRM' !== strtoupper( $override ) ) {
                                       $check = $service->check_dns_health( $domain );
                                       if ( is_wp_error( $check ) ) {
                                               wp_send_json_error( $check->get_error_message() );
                                       }
                               }

                               if ( $site_id > 0 ) {
                                       $result = $service->attach_to_site( $domain, $site_id );
                               } else {
                                       $result  = $service->create_site( $domain, $title, $email, $template );
                                       $site_id = is_wp_error( $result ) ? 0 : (int) $result;
                               }
                               break;
                       case 'detach':
                               $result = $service->detach_from_site( $domain, 'CONFIRM' === strtoupper( $override ) );
                               break;
                       default:
                               wp_send_json_error( 'unknown_action' );
               }

               if ( $result instanceof Porkbun_Client_Error ) {
                       Logger::error( $action, array( 'domain' => $domain ), $result->message );
                       wp_send_json_error( $result->message );
               }

               if ( is_wp_error( $result ) ) {
                       Logger::error( $action, array( 'domain' => $domain ), $result->get_error_message() );
                       wp_send_json_error( $result->get_error_message() );
               }

               Logger::info( $action, array( 'domain' => $domain, 'site_id' => $site_id ), 'success' );

               wp_send_json_success( $result );
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

            $auto_reconcile = isset( $_POST['porkpress_auto_reconcile'] ) ? 1 : 0;
            update_site_option( 'porkpress_ssl_auto_reconcile', $auto_reconcile );

            $dry_run = isset( $_POST['porkpress_dry_run'] ) ? 1 : 0;
            update_site_option( 'porkpress_ssl_dry_run', $dry_run );

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
                    'auto_reconcile'     => (bool) $auto_reconcile,
                    'dry_run'            => (bool) $dry_run,
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
        $auto_reconcile = (bool) get_site_option( 'porkpress_ssl_auto_reconcile', 1 );
        $dry_run        = (bool) get_site_option( 'porkpress_ssl_dry_run', 0 );

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
echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Automatic Reconciliation', 'porkpress-ssl' ) . '</th>';
        echo '<td><label><input name="porkpress_auto_reconcile" type="checkbox" value="1"' . checked( $auto_reconcile, true, false ) . ' /> ' . esc_html__( 'Enable automatic drift remediation', 'porkpress-ssl' ) . '</label></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Dry Run Mode', 'porkpress-ssl' ) . '</th>';
        echo '<td><label><input name="porkpress_dry_run" type="checkbox" value="1"' . checked( $dry_run, true, false ) . ' /> ' . esc_html__( 'Enable dry-run mode', 'porkpress-ssl' ) . '</label></td>';
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
        * Register the site alias management page.
        */
       public function register_site_alias_page() {
               add_submenu_page(
                       null,
                       __( 'Domain Aliases', 'porkpress-ssl' ),
                       __( 'Domain Aliases', 'porkpress-ssl' ),
                       \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS,
                       'porkpress-site-aliases',
                       array( $this, 'render_site_alias_page' )
               );
       }

       /**
        * Add Domains tab to the site edit screen.
        *
        * @param array $links Existing links.
        *
        * @return array
        */
       public function add_site_nav_link( array $links ): array {
               $links['porkpress-site-aliases'] = array(
                       'label' => __( 'Domains', 'porkpress-ssl' ),
                       'url'   => 'admin.php?page=porkpress-site-aliases',
                       'cap'   => \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS,
               );

               return $links;
       }

       /**
        * Render the site alias management page.
        */
       public function render_site_alias_page() {
               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_die( esc_html__( 'You do not have permission to access this page.', 'porkpress-ssl' ) );
               }

               $site_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
               if ( ! $site_id ) {
                       wp_die( esc_html__( 'Invalid site ID.', 'porkpress-ssl' ) );
               }

               $service = new Domain_Service();

               $redirect = add_query_arg(
                       array(
                               'page' => 'porkpress-site-aliases',
                               'id'   => $site_id,
                       ),
                       network_admin_url( 'admin.php' )
               );

               if ( isset( $_POST['porkpress_add_alias'] ) ) {
                       check_admin_referer( 'porkpress_add_alias' );
                       $domain = sanitize_text_field( wp_unslash( $_POST['alias_domain'] ) );
                       if ( $domain && empty( $service->get_aliases( null, $domain ) ) ) {
                               $is_primary = empty( $service->get_aliases( $site_id ) );
                               $service->add_alias( $site_id, $domain, $is_primary );
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'added', $redirect ) );
                               exit;
                       } else {
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'exists', $redirect ) );
                               exit;
                       }
               }

               if ( isset( $_GET['make_primary'] ) ) {
                       $domain = sanitize_text_field( wp_unslash( $_GET['make_primary'] ) );
                       check_admin_referer( 'porkpress_make_primary_' . $domain );
                       if ( 'CONFIRM' !== ( $_GET['confirm'] ?? '' ) ) {
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'confirm', $redirect ) );
                               exit;
                       }
                       $service->set_primary_alias( $site_id, $domain );
                       wp_safe_redirect( add_query_arg( 'pp_msg', 'primary', $redirect ) );
                       exit;
               }

               if ( isset( $_GET['delete_alias'] ) ) {
                       $domain = sanitize_text_field( wp_unslash( $_GET['delete_alias'] ) );
                       check_admin_referer( 'porkpress_delete_alias_' . $domain );
                       if ( 'CONFIRM' !== ( $_GET['confirm'] ?? '' ) ) {
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'confirm', $redirect ) );
                               exit;
                       }
                       $aliases   = $service->get_aliases( $site_id );
                       $can_delete = true;
                       foreach ( $aliases as $alias ) {
                               if ( $alias['domain'] === $domain ) {
                                       if ( $alias['is_primary'] ) {
                                               $can_delete = false;
                                       }
                               }
                       }
                       if ( $can_delete ) {
                               $service->delete_alias( $site_id, $domain );
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'deleted', $redirect ) );
                               exit;
                       } else {
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'nodelete', $redirect ) );
                               exit;
                       }
               }

               $message = isset( $_GET['pp_msg'] ) ? sanitize_key( wp_unslash( $_GET['pp_msg'] ) ) : '';

               $simulate_steps = '';
               if ( isset( $_POST['porkpress_ssl_simulate_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_simulate_nonce'] ), 'porkpress_ssl_simulate' ) ) {
                       $dry_service = new Domain_Service( null, true );
                       $reconciler  = new Reconciler( $dry_service );
                       $drift       = $reconciler->reconcile_all( false );
                       $steps       = array();
                       foreach ( $drift['missing_aliases'] as $item ) {
                               if ( (int) $item['site_id'] === $site_id ) {
                                       $steps[] = sprintf( __( 'Would add alias %1$s to site %2$d', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                               }
                       }
                       foreach ( $drift['stray_aliases'] as $item ) {
                               if ( (int) $item['site_id'] === $site_id ) {
                                       $steps[] = sprintf( __( 'Would remove alias %1$s from site %2$d', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                               }
                       }
                       foreach ( $drift['disabled_sites'] as $item ) {
                               if ( (int) $item['site_id'] === $site_id ) {
                                       $steps[] = sprintf( __( 'Would unarchive site %2$d (domain %1$s)', 'porkpress-ssl' ), $item['domain'], $item['site_id'] );
                               }
                       }
                       $simulate_steps  = '<div class="notice notice-info"><p>' . esc_html__( 'Simulation results (no changes applied):', 'porkpress-ssl' ) . '</p>';
                       if ( empty( $steps ) ) {
                               $simulate_steps .= '<p>' . esc_html__( 'No actions required.', 'porkpress-ssl' ) . '</p>';
                       } else {
                               $simulate_steps .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $steps ) ) . '</li></ul>';
                       }
                       $simulate_steps .= '</div>';
               }

               $aliases = $service->get_aliases( $site_id );

               echo '<div class="wrap">';
               echo '<h1>' . esc_html__( 'Domain Aliases', 'porkpress-ssl' ) . '</h1>';

               echo '<form method="post" style="margin-bottom:1em;">';
               wp_nonce_field( 'porkpress_ssl_simulate', 'porkpress_ssl_simulate_nonce' );
               submit_button( __( 'Simulate', 'porkpress-ssl' ), 'secondary', 'simulate_now', false );
               echo '</form>';

               if ( $simulate_steps ) {
                       echo $simulate_steps; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
               }

               if ( $message ) {
                       $text = '';
                       switch ( $message ) {
                               case 'added':
                                       $text = __( 'Alias added.', 'porkpress-ssl' );
                                       break;
                               case 'deleted':
                                       $text = __( 'Alias removed.', 'porkpress-ssl' );
                                       break;
                               case 'primary':
                                       $text = __( 'Primary alias updated.', 'porkpress-ssl' );
                                       break;
                               case 'exists':
                                       $text = __( 'Alias already exists.', 'porkpress-ssl' );
                                       break;
                               case 'nodelete':
                                       $text = __( 'Cannot remove the primary alias.', 'porkpress-ssl' );
                                       break;
                               case 'confirm':
                                       $text = __( 'Action cancelled. Type CONFIRM to proceed.', 'porkpress-ssl' );
                                       break;
                       }
                       if ( $text ) {
                               printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $text ) );
                       }
               }

               echo '<table class="widefat fixed striped">';
               echo '<thead><tr><th>' . esc_html__( 'Domain', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Primary', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Actions', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';

               if ( empty( $aliases ) ) {
                       echo '<tr><td colspan="3">' . esc_html__( 'No aliases found.', 'porkpress-ssl' ) . '</td></tr>';
               } else {
                       foreach ( $aliases as $alias ) {
                               echo '<tr>';
                               echo '<td>' . esc_html( $alias['domain'] ) . '</td>';
                               echo '<td>' . ( $alias['is_primary'] ? '&#10003;' : '' ) . '</td>';
                               echo '<td>';
                               if ( ! $alias['is_primary'] ) {
                                       $primary_url = wp_nonce_url( add_query_arg( 'make_primary', rawurlencode( $alias['domain'] ), $redirect ), 'porkpress_make_primary_' . $alias['domain'] );
                                       $delete_url  = wp_nonce_url( add_query_arg( 'delete_alias', rawurlencode( $alias['domain'] ), $redirect ), 'porkpress_delete_alias_' . $alias['domain'] );
                                       $prompt = esc_js( __( 'Type CONFIRM to proceed:', 'porkpress-ssl' ) );
                                       echo '<a href="#" onclick="var c=prompt(\'' . $prompt . '\'); if(c===\'CONFIRM\'){window.location.href=\'' . esc_url( add_query_arg( 'confirm', 'CONFIRM', $primary_url ) ) . '\';} return false;">' . esc_html__( 'Set Primary', 'porkpress-ssl' ) . '</a> | ';
                                       echo '<a href="#" onclick="var c=prompt(\'' . $prompt . '\'); if(c===\'CONFIRM\'){window.location.href=\'' . esc_url( add_query_arg( 'confirm', 'CONFIRM', $delete_url ) ) . '\';} return false;">' . esc_html__( 'Remove', 'porkpress-ssl' ) . '</a>';
                               } else {
                                       echo '&#8212;';
                               }
                               echo '</td>';
                               echo '</tr>';
                       }
               }

               echo '</tbody></table>';

               echo '<h2>' . esc_html__( 'Add Alias', 'porkpress-ssl' ) . '</h2>';
               echo '<form method="post">';
               wp_nonce_field( 'porkpress_add_alias' );
               echo '<input type="text" name="alias_domain" class="regular-text" /> ';
               submit_button( __( 'Add', 'porkpress-ssl' ), 'secondary', 'porkpress_add_alias', false );
               echo '</form>';
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
