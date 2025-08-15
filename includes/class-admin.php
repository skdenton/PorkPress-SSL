<?php
/**
 * Admin functionality for PorkPress SSL.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

require_once __DIR__ . '/class-runner.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */

class Admin {
        /** Option key for stored domain requests. */
        private const REQUESTS_OPTION = 'porkpress_ssl_domain_requests';

        /**
         * Initialize hooks.
         */
        public function init() {
                add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
                add_action( 'network_admin_menu', array( $this, 'register_site_alias_page' ) );
               add_action( 'admin_menu', array( $this, 'register_site_menu' ) );
               add_action( 'wp_ajax_porkpress_ssl_bulk_action', array( $this, 'handle_bulk_action' ) );
               add_action( 'wp_ajax_porkpress_dns_add', array( $this, 'handle_dns_add' ) );
               add_action( 'wp_ajax_porkpress_dns_edit', array( $this, 'handle_dns_edit' ) );
               add_action( 'wp_ajax_porkpress_dns_delete', array( $this, 'handle_dns_delete' ) );
               add_action( 'wp_ajax_porkpress_dns_retrieve', array( $this, 'handle_dns_retrieve' ) );
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
                       'sites'     => __( 'Sites', 'porkpress-ssl' ),
                       'domains'   => __( 'Domains', 'porkpress-ssl' ),
                       'requests'  => __( 'Requests', 'porkpress-ssl' ),
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
                       case 'sites':
                               $this->render_sites_tab();
                               break;
                       case 'domains':
                               $this->render_domains_tab();
                               break;
                       case 'requests':
                               $this->render_requests_tab();
                               break;
                       case 'settings':
                               $this->render_settings_tab();
                               break;
                       case 'logs':
                               $this->render_logs_tab();
                               break;
                       case 'dashboard':
                       default:
                               $this->render_dashboard_tab();
                               break;
               }

                echo '</div>';
        }

        /**
         * Render the dashboard tab for the network admin page.
         */
        public function render_dashboard_tab() {
                if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                        return;
                }

               $service         = new Domain_Service();
               $domains         = $service->list_domains();
               $total_domains   = 0;
               $upcoming_expiry = 0;
               $last_refresh    = $service->get_last_refresh();
               if ( $domains instanceof Porkbun_Client_Error ) {
                       echo '<div class="notice notice-error"><p>' . esc_html( $domains->message ) . '</p></div>';
               } elseif ( ! empty( $domains['root_domains'] ) ) {
                       $total_domains = count( $domains['root_domains'] );
                       $threshold     = time() + 30 * DAY_IN_SECONDS;
                       foreach ( $domains['root_domains'] as $domain ) {
                               if ( ! empty( $domain['expiry'] ) ) {
                                       $expiry = strtotime( $domain['expiry'] );
                                       if ( $expiry && $expiry <= $threshold ) {
                                               $upcoming_expiry++;
                                       }
                               }
                       }
               }

      $mapped       = count( $service->get_aliases() );

       $reconciler   = new Reconciler( $service );
       $drift        = $reconciler->reconcile_all( false );
       $drift_count  = count( $drift['missing_aliases'] ) + count( $drift['stray_aliases'] ) + count( $drift['disabled_sites'] );

       global $wpdb;
       $table         = Logger::get_table_name();
       $ssl_log       = $wpdb->get_row( $wpdb->prepare( "SELECT time, result FROM {$table} WHERE action = %s ORDER BY time DESC LIMIT 1", 'issue_certificate' ), ARRAY_A );
       $ssl_status    = $ssl_log ? $ssl_log['time'] . ' (' . $ssl_log['result'] . ')' : __( 'Never', 'porkpress-ssl' );
       $reconcile_log = $wpdb->get_row( $wpdb->prepare( "SELECT time, result FROM {$table} WHERE action = %s ORDER BY time DESC LIMIT 1", 'reconcile' ), ARRAY_A );
       $reconcile_stat = $reconcile_log ? $reconcile_log['time'] . ' (' . $reconcile_log['result'] . ')' : __( 'Never', 'porkpress-ssl' );

       $cred_status = $service->has_credentials()
               ? __( 'Configured', 'porkpress-ssl' )
               : __( 'Missing', 'porkpress-ssl' );

       $apache_cmd = Renewal_Service::get_apache_reload_cmd();

       echo '<div style="display:flex; flex-wrap:wrap; gap:1em;">';
       $cards = array(
               __( 'API Credentials', 'porkpress-ssl' )              => $cred_status,
               __( 'Total Porkbun Domains', 'porkpress-ssl' )        => number_format_i18n( $total_domains ),
               __( 'Mapped Domains', 'porkpress-ssl' )              => number_format_i18n( $mapped ),
               __( 'Drift Alerts', 'porkpress-ssl' )               => number_format_i18n( $drift_count ),
               __( 'Upcoming Expiries (≤30 days)', 'porkpress-ssl' ) => number_format_i18n( $upcoming_expiry ),
               __( 'Last SSL Run Status', 'porkpress-ssl' )        => $ssl_status,
               __( 'Last Reconcile', 'porkpress-ssl' )            => $reconcile_stat,
               __( 'Last Domain Refresh', 'porkpress-ssl' )       => $last_refresh ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_refresh ) : __( 'Never', 'porkpress-ssl' ),
               __( 'Apache Reload Command', 'porkpress-ssl' )      => $apache_cmd ? $apache_cmd : __( 'Not found', 'porkpress-ssl' ),
               __( 'Command Runner', 'porkpress-ssl' )            => Runner::describe(),
       );
                foreach ( $cards as $label => $value ) {
                        echo '<div class="card" style="flex:1 1 200px;"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( $value ) . '</p></div>';
                }
                echo '</div>';

               $aliases = $service->get_aliases();
               $issues  = array();
               foreach ( $aliases as $alias ) {
                       $domain  = $alias['domain'];
                       $site_id = isset( $alias['site_id'] ) ? (int) $alias['site_id'] : 0;

                       $skip = function_exists( 'apply_filters' )
                               ? apply_filters( 'porkpress_ssl_skip_dns_check', false, $domain, $site_id )
                               : false;
                       if ( $skip ) {
                               continue;
                       }

                       $check = $service->check_dns_health( $domain );
                       if ( $check instanceof \WP_Error ) {
                               $issues[] = $check->get_error_message();
                       }
               }

               if ( $issues ) {
                       echo '<div class="notice notice-warning"><p>' . esc_html__( 'DNS mismatches detected. Update the following domains to point to this network:', 'porkpress-ssl' ) . '</p><ul>';
                       foreach ( $issues as $msg ) {
                               echo '<li>' . esc_html( $msg ) . '</li>';
                       }
                       echo '</ul></div>';
               }

               $remediations = get_site_option( 'porkpress_ssl_apache_snippets', array() );
               if ( $remediations ) {
                       echo '<div class="notice notice-warning"><p>' . esc_html__( 'Apache vhosts need manual SSL directive updates:', 'porkpress-ssl' ) . '</p><ul>';
                       foreach ( $remediations as $file => $info ) {
                               $reason = $info['reason'] ?? '';
                               if ( 'disabled' === $reason ) {
                                       $reason = __( 'disabled', 'porkpress-ssl' );
                               } elseif ( 'unwritable' === $reason ) {
                                       $reason = __( 'unwritable', 'porkpress-ssl' );
                               }
                               $label = $file . ( $reason ? ' (' . $reason . ')' : '' );
                               echo '<li><strong>' . esc_html( $label ) . '</strong><pre>' . esc_html( $info['snippet'] ) . '</pre></li>';
                       }
                       echo '</ul></div>';
               }
       }

       /**
        * Render the sites tab for the network admin page.
        */
       public function render_sites_tab() {
               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       return;
               }

               $service = new Domain_Service();
               $notice  = '';
               $class   = 'notice-success';

               if ( isset( $_POST['porkpress_create_site_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_create_site_nonce'] ), 'porkpress_create_site' ) ) {
                       $subdomain = isset( $_POST['new_site_subdomain'] ) ? sanitize_title_with_dashes( wp_unslash( $_POST['new_site_subdomain'] ) ) : '';
                       $root      = isset( $_POST['new_site_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_domain'] ) ) : '';
                       $domain    = $root;
                       if ( $subdomain ) {
                               $domain = $subdomain . '.' . $root;
                       }
                       $title     = isset( $_POST['new_site_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_title'] ) ) : '';
                       $email     = isset( $_POST['new_site_email'] ) ? sanitize_email( wp_unslash( $_POST['new_site_email'] ) ) : '';
                       $template  = isset( $_POST['new_site_template'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_template'] ) ) : '';
                       $language  = isset( $_POST['new_site_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_lang'] ) ) : '';

                       $result = $service->create_site( $domain, $title, $email, $template );
                       if ( is_wp_error( $result ) ) {
                               $class  = 'notice-error';
                               $notice = $result->get_error_message();
                       } else {
                               if ( $language ) {
                                       update_blog_option( (int) $result, 'WPLANG', $language );
                               }
                               $notice = __( 'Site created.', 'porkpress-ssl' );
                       }
               }

               echo '<div class="wrap">';

               if ( $notice ) {
                       printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $notice ) );
               }

               $sites = get_sites( array( 'number' => 0 ) );
               echo '<table class="widefat fixed striped">';
               echo '<thead><tr><th>' . esc_html__( 'ID', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Site', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Primary Domain', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Actions', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';

               if ( empty( $sites ) ) {
                       echo '<tr><td colspan="4">' . esc_html__( 'No sites found.', 'porkpress-ssl' ) . '</td></tr>';
               } else {
                       foreach ( $sites as $site ) {
                               $site_id = (int) $site->blog_id;
                               $name    = get_blog_option( $site_id, 'blogname' );
                               $aliases = $service->get_aliases( $site_id );
                               $primary = '';
                               foreach ( $aliases as $alias ) {
                                       if ( ! empty( $alias['is_primary'] ) ) {
                                               $primary = $alias['domain'];
                                               break;
                                       }
                               }
                               if ( ! $primary ) {
                                       $primary = (string) get_site_meta( $site_id, 'porkpress_domain', true );
                               }
                               $site_url   = get_site_url( $site_id );
                               $site_host  = $site_url ? wp_parse_url( $site_url, PHP_URL_HOST ) : '';
                               if ( ! $primary && $site_host ) {
                                       $primary = $site_host;
                               }
                               $manage_url = network_admin_url( 'admin.php?page=porkpress-site-aliases&id=' . $site_id );
                               echo '<tr>';
                               echo '<td>' . esc_html( $site_id ) . '</td>';
                               echo '<td>' . esc_html( $name ) . '</td>';
                               echo '<td>';
                               if ( $primary ) {
                                       echo esc_html( $primary );
                                       if ( $site_url ) {
                                               echo '<br /><a href="' . esc_url( $site_url ) . '">' . esc_html( $site_url ) . '</a>';
                                       }
                               } else {
                                       echo '&mdash;';
                               }
                               echo '</td>';
                               echo '<td><a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Manage Domains', 'porkpress-ssl' ) . '</a></td>';
                               echo '</tr>';
                       }
               }

               echo '</tbody></table>';

               $domain_list = $service->list_domains();
               $available   = array();
               if ( ! ( $domain_list instanceof Porkbun_Client_Error ) && ! empty( $domain_list['domains'] ) ) {
                       $mapped = wp_list_pluck( $service->get_aliases(), 'domain' );
                       foreach ( $domain_list['domains'] as $info ) {
                               $root = $info['domain'] ?? $info['name'] ?? '';
                               if ( $root && ! in_array( $root, $mapped, true ) ) {
                                       $available[] = $root;
                               }
                       }
               }

               echo '<h2>' . esc_html__( 'Add New Site', 'porkpress-ssl' ) . '</h2>';
               echo '<form method="post">';
               wp_nonce_field( 'porkpress_create_site', 'porkpress_create_site_nonce' );
               echo '<table class="form-table" role="presentation">';
               echo '<tr><th scope="row"><label for="new-site-subdomain">' . esc_html__( 'Site Address (URL)', 'porkpress-ssl' ) . '</label></th><td>';
               echo '<input name="new_site_subdomain" type="text" id="new-site-subdomain" class="regular-text" pattern="[a-z0-9-]+" />';
               if ( $available ) {
                       echo '<span>.</span><select name="new_site_domain" id="new-site-domain">';
                       foreach ( $available as $domain ) {
                               echo '<option value="' . esc_attr( $domain ) . '">' . esc_html( $domain ) . '</option>';
                       }
                       echo '</select>';
               } else {
                       echo '<input name="new_site_domain" type="text" id="new-site-domain" class="regular-text" required />';
               }
               echo '<p class="description">' . esc_html__( 'Only lowercase letters (a-z), numbers, and hyphens are allowed.', 'porkpress-ssl' ) . '</p>';
               echo '</td></tr>';
               echo '<tr><th scope="row"><label for="new-site-title">' . esc_html__( 'Site Title', 'porkpress-ssl' ) . '</label></th><td><input name="new_site_title" type="text" id="new-site-title" class="regular-text" required /></td></tr>';
               echo '<tr><th scope="row"><label for="new-site-lang">' . esc_html__( 'Site Language', 'porkpress-ssl' ) . '</label></th><td>';
               if ( function_exists( 'wp_dropdown_languages' ) ) {
                       wp_dropdown_languages(
                               array(
                                       'name'                         => 'new_site_lang',
                                       'id'                           => 'new-site-lang',
                                       'selected'                     => 'en_US',
                                       'show_available_translations'  => false,
                               )
                       );
               } else {
                       echo '<input name="new_site_lang" type="text" id="new-site-lang" class="regular-text" value="en_US" />';
               }
               echo '</td></tr>';
               echo '<tr><th scope="row"><label for="new-site-email">' . esc_html__( 'Admin Email', 'porkpress-ssl' ) . '</label></th><td><input name="new_site_email" type="email" id="new-site-email" class="regular-text" required /></td></tr>';
               echo '<tr><th scope="row"><label for="new-site-template">' . esc_html__( 'Template', 'porkpress-ssl' ) . '</label></th><td><input name="new_site_template" type="text" id="new-site-template" class="regular-text" /></td></tr>';
               echo '</table>';
               submit_button( __( 'Create Site', 'porkpress-ssl' ) );
               echo '</form>';
               echo '</div>';
       }

       /**
        * Add primary domain column to the sites list table.
        *
        * @param array $columns Existing columns.
        * @return array
        */
       public function add_primary_domain_column( array $columns ): array {
               $columns['primary_domain'] = __( 'Primary Domain', 'porkpress-ssl' );
               return $columns;
       }

       /**
        * Render primary domain column content.
        *
        * @param string $column  Column name.
        * @param int    $site_id Site ID.
        */
       public function manage_primary_domain_column( string $column, int $site_id ) {
               if ( 'primary_domain' !== $column ) {
                       return;
               }

               $service = new Domain_Service();
               $aliases = $service->get_aliases( $site_id );
               $primary = '';
               foreach ( $aliases as $alias ) {
                       if ( ! empty( $alias['is_primary'] ) ) {
                               $primary = $alias['domain'];
                               break;
                       }
               }

               $url = network_admin_url( 'admin.php?page=porkpress-site-aliases&id=' . $site_id );
               if ( $primary ) {
                       printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $primary ) );
               } else {
                       printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Manage Aliases', 'porkpress-ssl' ) );
               }
       }

        /**
         * Render the domains tab for the network admin page.
         */
       public function render_domains_tab() {
               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       return;
               }

               if ( isset( $_GET['domain'] ) ) {
                       $this->render_domain_details( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) );
                       return;
               }

               if ( isset( $_GET['export'] ) ) {
                       $export  = sanitize_key( wp_unslash( $_GET['export'] ) );
                       if ( in_array( $export, array( 'mapping-csv', 'mapping-json' ), true ) ) {
                               $svc     = new Domain_Service();
                               $aliases = $svc->get_aliases();
                               foreach ( $aliases as &$alias ) {
                                       $site               = get_site( (int) $alias['site_id'] );
                                       $alias['site_name'] = $site ? get_blog_option( $site->id, 'blogname' ) : '';
                               }
                               unset( $alias );
                               if ( 'mapping-csv' === $export ) {
                                       header( 'Content-Type: text/csv' );
                                       header( 'Content-Disposition: attachment; filename="porkpress-mapping.csv"' );
                                       $fh = fopen( 'php://output', 'w' );
                                       fputcsv( $fh, array( 'site_id', 'site_name', 'domain', 'is_primary', 'status' ) );
                                       foreach ( $aliases as $alias ) {
                                               fputcsv( $fh, array( $alias['site_id'], $alias['site_name'], $alias['domain'], $alias['is_primary'], $alias['status'] ) );
                                       }
                                       exit;
                               }
                               if ( 'mapping-json' === $export ) {
                                       header( 'Content-Type: application/json' );
                                       header( 'Content-Disposition: attachment; filename="porkpress-mapping.json"' );
                                       $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
                                       echo $encode( $aliases );
                                       exit;
                               }
                       }
               }

               $search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
               $status       = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
               $expiry_window = isset( $_GET['expiry'] ) ? absint( wp_unslash( $_GET['expiry'] ) ) : 0;

             $service = new Domain_Service();

               if ( isset( $_POST['porkpress_ssl_clear_refresh_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_clear_refresh_nonce'] ), 'porkpress_ssl_clear_refresh' ) ) {
                       $service->clear_domain_cache();
                       $refresh = $service->refresh_domains();
                       if ( $refresh instanceof Porkbun_Client_Error ) {
                               echo '<div class="error"><p>' . esc_html( $refresh->message ) . '</p></div>';
                       } else {
                               echo '<div class="updated"><p>' . esc_html__( 'Domain cache cleared and list refreshed.', 'porkpress-ssl' ) . '</p></div>';
                       }
               } elseif ( isset( $_POST['porkpress_ssl_refresh_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_refresh_nonce'] ), 'porkpress_ssl_refresh' ) ) {
                       $refresh = $service->refresh_domains();
                       if ( $refresh instanceof Porkbun_Client_Error ) {
                               echo '<div class="error"><p>' . esc_html( $refresh->message ) . '</p></div>';
                       } else {
                               echo '<div class="updated"><p>' . esc_html__( 'Domain list refreshed.', 'porkpress-ssl' ) . '</p></div>';
                       }
               }

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

               $has_creds = $service->has_credentials();

               if ( $has_creds && isset( $_POST['porkpress_ssl_reconcile_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_reconcile_nonce'] ), 'porkpress_ssl_reconcile' ) ) {
                       $reconciler = new Reconciler( $service );
                       $reconciler->reconcile_all();
                       echo '<div class="updated"><p>' . esc_html__( 'Reconciliation complete.', 'porkpress-ssl' ) . '</p></div>';
               }

               if ( $has_creds && isset( $_POST['porkpress_ssl_issue_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['porkpress_ssl_issue_nonce'] ), 'porkpress_ssl_issue' ) ) {
                       SSL_Service::run_queue();
                       echo '<div class="updated"><p>' . esc_html__( 'Issuance tasks processed.', 'porkpress-ssl' ) . '</p></div>';
               }

               echo '<div class="porkpress-domain-buttons" style="display:flex;gap:1em;flex-wrap:wrap;">';
               echo '<form method="post" style="margin:0;">';
               wp_nonce_field( 'porkpress_ssl_simulate', 'porkpress_ssl_simulate_nonce' );
               submit_button( __( 'Simulate', 'porkpress-ssl' ), 'secondary', 'simulate_now', false );
               echo '</form>';

               if ( $has_creds ) {
                       echo '<form method="post" style="margin:0;">';
                       wp_nonce_field( 'porkpress_ssl_reconcile', 'porkpress_ssl_reconcile_nonce' );
                       submit_button( __( 'Reconcile Now', 'porkpress-ssl' ), 'secondary', 'reconcile_now', false );
                       echo '</form>';

                       echo '<form method="post" style="margin:0;">';
                       wp_nonce_field( 'porkpress_ssl_issue', 'porkpress_ssl_issue_nonce' );
                       submit_button( __( 'Run Now', 'porkpress-ssl' ), 'secondary', 'issue_now', false );
                       echo '</form>';

                       echo '<form method="post" style="margin:0;">';
                       wp_nonce_field( 'porkpress_ssl_refresh', 'porkpress_ssl_refresh_nonce' );
                       submit_button( __( 'Refresh Domains', 'porkpress-ssl' ), 'secondary', 'refresh_domains', false );
                       echo '</form>';

                       echo '<form method="post" style="margin:0;">';
                       wp_nonce_field( 'porkpress_ssl_clear_refresh', 'porkpress_ssl_clear_refresh_nonce' );
                       submit_button( __( 'Clear Cache and Refresh', 'porkpress-ssl' ), 'delete', 'clear_refresh', false );
                       echo '</form>';
               }
               echo '</div>';

               if ( $simulate_steps ) {
                       echo $simulate_steps; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
               }

               if ( ! $has_creds ) {
                       printf( '<div class="error"><p>%s</p></div>', esc_html__( 'Porkbun API credentials are missing. Please configure them in the Settings tab.', 'porkpress-ssl' ) );
                       return;
               }

               $last_refresh = $service->get_last_refresh();
               if ( $last_refresh ) {
                       echo '<p>' . sprintf( esc_html__( 'Last refresh: %s', 'porkpress-ssl' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_refresh ) ) ) . '</p>';
               }

               $prod_server_ip = get_site_option( 'porkpress_ssl_prod_server_ip', '' );
               $dev_server_ip  = get_site_option( 'porkpress_ssl_dev_server_ip', '' );

               $aliases   = $service->get_aliases();
               $alias_map = array();
               foreach ( $aliases as $alias ) {
                       $alias_map[ strtolower( $alias['domain'] ) ] = $alias;
               }

               $site_list  = get_sites( array( 'number' => 0 ) );
               $site_hosts = array();
               $parse      = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
               foreach ( $site_list as $s ) {
                       $host = $parse( get_site_url( (int) $s->blog_id ), PHP_URL_HOST );
                       if ( $host ) {
                               $site_hosts[ strtolower( $host ) ] = $s;
                       }
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
               $known   = array();
               foreach ( $domains as $d ) {
                       $known[ strtolower( $d['domain'] ?? $d['name'] ?? '' ) ] = true;
               }
               foreach ( $alias_map as $name => $alias ) {
                       if ( ! isset( $known[ $name ] ) ) {
                               $domains[]       = array( 'domain' => $alias['domain'] );
                               $known[ $name ] = true;
                       }
               }
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
               echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'export' => 'mapping-csv' ) ) ) . '">' . esc_html__( 'Export Mapping CSV', 'porkpress-ssl' ) . '</a> ';
               echo '<a class="button" href="' . esc_url( add_query_arg( array( 'export' => 'mapping-json' ) ) ) . '">' . esc_html__( 'Export Mapping JSON', 'porkpress-ssl' ) . '</a></p>';

               wp_enqueue_script(
                       'porkpress-domain-bulk',
                       set_url_scheme( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/domain-bulk.js', 'https' ),
                       array( 'jquery', 'wp-i18n' ),
                       PORKPRESS_SSL_VERSION,
                       true
               );
               wp_set_script_translations( 'porkpress-domain-bulk', 'porkpress-ssl', plugin_dir_path( dirname( __FILE__ ) ) . 'languages' );
               wp_localize_script( 'porkpress-domain-bulk', 'porkpressBulk', array(
                       'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                       'nonce'   => wp_create_nonce( 'porkpress_ssl_bulk_action' ),
               ) );
               wp_enqueue_script(
                       'porkpress-domain-dns',
                       set_url_scheme( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/domain-dns.js', 'https' ),
                       array( 'jquery' ),
                       PORKPRESS_SSL_VERSION,
                       true
               );

               echo '<form id="porkpress-domain-actions" method="post">';
               echo '<table class="widefat fixed striped">';
               echo '<thead><tr>';
               echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all" /></td>';
               echo '<th>' . esc_html__( 'Name', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Site', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Server', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Expiry', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'DNS Status', 'porkpress-ssl' ) . '</th>';
               echo '</tr></thead><tbody>';

                if ( empty( $domains ) ) {
               echo '<tr><td colspan="6">' . esc_html__( 'No domains found.', 'porkpress-ssl' ) . '</td></tr>';
                } else {
                       foreach ( $domains as $domain ) {
                               $name       = $domain['domain'] ?? $domain['name'] ?? '';
                               $expiry     = $domain['expiry'] ?? $domain['expiration'] ?? $domain['exdate'] ?? '';
                               $dns_status = $domain['status'] ?? $domain['dnsstatus'] ?? '';
                               $records    = $domain['dns'] ?? array();

                               echo '<tr>';
                               echo '<th scope="row" class="check-column"><input type="checkbox" name="domains[]" value="' . esc_attr( $name ) . '" /></th>';
                               $toggle = empty( $records ) ? '' : '<button type="button" class="porkpress-dns-toggle dashicons dashicons-arrow-right" aria-expanded="false"></button> ';
                               $link   = esc_url( add_query_arg( array( 'domain' => $name ) ) );
                               echo '<td>' . $toggle . '<a href="' . $link . '">' . esc_html( $name ) . '</a></td>';
                              $site_cell = '&mdash;';
                              $alias     = $service->get_aliases( null, $name );
                              if ( ! empty( $alias ) ) {
                                      $site_id = (int) $alias[0]['site_id'];
                                      $site    = get_site( $site_id );
                                      if ( $site ) {
                                              $site_name = get_blog_option( $site_id, 'blogname' );
                                              $site_url  = network_admin_url( 'site-info.php?id=' . $site_id );
                                              $site_cell = sprintf( "<a href='%s'>%s</a>", esc_url( $site_url ), esc_html( $site_name ) );
                                      }
                              } elseif ( ! empty( $records ) ) {
                                      foreach ( $records as $rec ) {
                                              $target = strtolower( $rec['content'] ?? '' );
                                              if ( isset( $site_hosts[ $target ] ) ) {
                                                      $site     = $site_hosts[ $target ];
                                                      $site_id  = (int) $site->blog_id;
                                                      $site_name = get_blog_option( $site_id, 'blogname' );
                                                      $site_url  = network_admin_url( 'site-info.php?id=' . $site_id );
                                                      $site_cell = sprintf( "<a href='%s'>%s</a>", esc_url( $site_url ), esc_html( $site_name ) );
                                                      break;
                                              }
                                      }
                              }
                               echo '<td>' . $site_cell . '</td>';

                               $server = 'N/A';
                               foreach ( $records as $record ) {
                                   if ( 'A' === $record['type'] ) {
                                       if ( ! empty( $prod_server_ip ) && $record['content'] === $prod_server_ip ) {
                                           $server = 'Prod';
                                           break;
                                       }
                                       if ( ! empty( $dev_server_ip ) && $record['content'] === $dev_server_ip ) {
                                           $server = 'Dev';
                                           break;
                                       }
                                   }
                               }
                               echo '<td>' . esc_html( $server ) . '</td>';

                               echo '<td>' . esc_html( $expiry ) . '</td>';
                               echo '<td>' . esc_html( $dns_status ) . '</td>';
                               echo '</tr>';

                               if ( ! empty( $records ) ) {
                                       echo '<tr class="porkpress-dns-details" style="display:none;"><td colspan="6">';
                                       echo '<table class="widefat">';
                                       echo '<thead><tr><th>' . esc_html__( 'Type', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Name', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Content', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';
                                       foreach ( $records as $rec ) {
                                               $type    = $rec['type'] ?? '';
                                               $rname   = $rec['name'] ?? '';
                                               $content = $rec['content'] ?? '';
                                               echo '<tr><td>' . esc_html( $type ) . '</td><td>' . esc_html( $rname ) . '</td><td>' . esc_html( $content ) . '</td></tr>';
                                       }
                                       echo '</tbody></table>';
                                       echo '</td></tr>';
                               }
                       }
               }

               echo '</tbody></table>';
               echo '<div class="tablenav bottom">';
               echo '<div class="alignleft actions bulkactions">';
               echo '<select name="bulk_action"><option value="">' . esc_html__( 'Bulk actions', 'porkpress-ssl' ) . '</option>';
               echo '<option value="attach">' . esc_html__( 'Attach to site', 'porkpress-ssl' ) . '</option>';
               echo '<option value="detach">' . esc_html__( 'Detach from site', 'porkpress-ssl' ) . '</option>';
               echo '</select> ';
               echo '<input type="text" name="site_name" class="regular-text" list="porkpress-site-list" placeholder="' . esc_attr__( 'Site Name', 'porkpress-ssl' ) . '" /> ';
               echo '<datalist id="porkpress-site-list">';
               foreach ( $site_list as $s ) {
                       $label = get_blog_option( (int) $s->blog_id, 'blogname' );
                       echo '<option value="' . esc_attr( $label ) . '"></option>';
               }
               echo '</datalist>';
               submit_button( __( 'Apply', 'porkpress-ssl' ), 'secondary', 'apply', false );
               echo '</div>';
               echo '<div id="porkpress-domain-progress" class="alignleft actions"></div>';
               echo '</div>';
               echo '</form>';
       }

       /**
        * Render detailed information for a single domain.
        *
        * @param string $domain Domain name.
        */
       private function render_domain_details( string $domain ) {
               $service = new Domain_Service();
               $data    = $this->get_domain_info( $service, $domain );

               echo '<h2>' . esc_html( $domain ) . '</h2>';
               if ( empty( $data ) ) {
                       echo '<p>' . esc_html__( 'Domain details not found in cache.', 'porkpress-ssl' ) . '</p>';
                       echo '<p><a href="' . esc_url( remove_query_arg( 'domain' ) ) . '">&larr; ' . esc_html__( 'Back to Domains', 'porkpress-ssl' ) . '</a></p>';
                       return;
               }

               wp_enqueue_script(
                       'porkpress-domain-dns-details',
                       set_url_scheme( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/domain-details.js', 'https' ),
                       array( 'jquery', 'wp-i18n' ),
                       PORKPRESS_SSL_VERSION,
                       true
               );
               wp_set_script_translations( 'porkpress-domain-dns-details', 'porkpress-ssl', plugin_dir_path( dirname( __FILE__ ) ) . 'languages' );
               wp_localize_script( 'porkpress-domain-dns-details', 'porkpressDNS', array(
                       'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                       'nonce'   => wp_create_nonce( 'porkpress_dns_action' ),
                       'domain'  => $domain,
               ) );

               $status = $data['status'] ?? $data['dnsstatus'] ?? '';
               $expiry = $data['expiry'] ?? $data['expiration'] ?? $data['exdate'] ?? '';
               echo '<table class="widefat"><tbody>';
               if ( $status ) {
                       echo '<tr><th>' . esc_html__( 'Status', 'porkpress-ssl' ) . '</th><td>' . esc_html( $status ) . '</td></tr>';
               }
               if ( $expiry ) {
                       echo '<tr><th>' . esc_html__( 'Expiry', 'porkpress-ssl' ) . '</th><td>' . esc_html( $expiry ) . '</td></tr>';
               }
               echo '</tbody></table>';

               if ( ! empty( $data['nameservers'] ) && is_array( $data['nameservers'] ) ) {
                       echo '<h3>' . esc_html__( 'Nameservers', 'porkpress-ssl' ) . '</h3><ul>';
                       foreach ( $data['nameservers'] as $ns ) {
                               echo '<li>' . esc_html( $ns ) . '</li>';
                       }
                       echo '</ul>';
               }

               $records = $data['dns'] ?? array();
               echo '<h3>' . esc_html__( 'DNS Records', 'porkpress-ssl' ) . '</h3>';
               echo '<table class="widefat" id="porkpress-dns-records"><thead><tr><th>' . esc_html__( 'Type', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Name', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Content', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'TTL', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Actions', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';
               foreach ( $records as $rec ) {
                       $type    = $rec['type'] ?? '';
                       $rname   = $rec['name'] ?? '';
                       $content = $rec['content'] ?? '';
                       $ttl     = isset( $rec['ttl'] ) ? (int) $rec['ttl'] : 300;
                       $rid     = isset( $rec['id'] ) ? (int) $rec['id'] : 0;
                       echo '<tr data-id="' . esc_attr( $rid ) . '"><td><input type="text" class="dns-type" value="' . esc_attr( $type ) . '" /></td><td><input type="text" class="dns-name" value="' . esc_attr( $rname ) . '" /></td><td><input type="text" class="dns-content" value="' . esc_attr( $content ) . '" /></td><td><input type="number" class="dns-ttl" value="' . esc_attr( $ttl ) . '" /></td><td><button class="button dns-update">' . esc_html__( 'Update', 'porkpress-ssl' ) . '</button> <button class="button dns-delete">' . esc_html__( 'Delete', 'porkpress-ssl' ) . '</button></td></tr>';
               }
               echo '<tr class="dns-add"><td><input type="text" class="dns-type" /></td><td><input type="text" class="dns-name" /></td><td><input type="text" class="dns-content" /></td><td><input type="number" class="dns-ttl" value="300" /></td><td><button class="button dns-add-btn">' . esc_html__( 'Add', 'porkpress-ssl' ) . '</button></td></tr>';
               echo '</tbody></table>';

               echo '<p><a href="' . esc_url( remove_query_arg( 'domain' ) ) . '">&larr; ' . esc_html__( 'Back to Domains', 'porkpress-ssl' ) . '</a></p>';
       }

       /**
        * Retrieve domain information from the cached domain list.
        *
        * @param Domain_Service $service Domain service instance.
        * @param string         $domain  Domain name.
        * @return array Domain data or empty array if not found.
        */
       private function get_domain_info( Domain_Service $service, string $domain ): array {
               $data   = array();
               $result = $service->list_domains();
               if ( ! ( $result instanceof Porkbun_Client_Error ) ) {
                       foreach ( $result['domains'] ?? array() as $info ) {
                               $name = $info['domain'] ?? $info['name'] ?? '';
                               if ( 0 === strcasecmp( $name, $domain ) ) {
                                       $data = $info;
                                       break;
                               }
                       }
               }
               return is_array( $data ) ? $data : array();
       }

       /**
        * AJAX handler to retrieve DNS records for a domain.
        */
       public function handle_dns_retrieve() {
               check_ajax_referer( 'porkpress_dns_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $service = new Domain_Service();
               $result  = $service->get_dns_records( $domain );

               if ( $result instanceof Porkbun_Client_Error || is_wp_error( $result ) ) {
                       $message = $result instanceof Porkbun_Client_Error ? $result->message : $result->get_error_message();
                       wp_send_json_error( $message );
               }

               wp_send_json_success( array( 'records' => $result ) );
       }

       /**
        * AJAX handler to add a DNS record.
        */
       public function handle_dns_add() {
               check_ajax_referer( 'porkpress_dns_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $type    = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
               $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
               $content = isset( $_POST['content'] ) ? sanitize_text_field( wp_unslash( $_POST['content'] ) ) : '';
               $ttl     = isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 300;

               $service = new Domain_Service();
               $result  = $service->add_dns_record( $domain, $type, $name, $content, $ttl );

               if ( $result instanceof Porkbun_Client_Error || is_wp_error( $result ) ) {
                       $message = $result instanceof Porkbun_Client_Error ? $result->message : $result->get_error_message();
                       wp_send_json_error( $message );
               }

               $records = $this->get_domain_info( $service, $domain )['dns'] ?? array();
               wp_send_json_success( array( 'records' => $records ) );
       }

       /**
        * AJAX handler to edit a DNS record.
        */
       public function handle_dns_edit() {
               check_ajax_referer( 'porkpress_dns_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $record_id = isset( $_POST['record_id'] ) ? (int) $_POST['record_id'] : 0;
               $type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
               $name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
               $content   = isset( $_POST['content'] ) ? sanitize_text_field( wp_unslash( $_POST['content'] ) ) : '';
               $ttl       = isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 300;

               $service = new Domain_Service();
               $result  = $service->update_dns_record( $domain, $record_id, $type, $name, $content, $ttl );

               if ( $result instanceof Porkbun_Client_Error || is_wp_error( $result ) ) {
                       $message = $result instanceof Porkbun_Client_Error ? $result->message : $result->get_error_message();
                       wp_send_json_error( $message );
               }

               $records = $this->get_domain_info( $service, $domain )['dns'] ?? array();
               wp_send_json_success( array( 'records' => $records ) );
       }

       /**
        * AJAX handler to delete a DNS record.
        */
       public function handle_dns_delete() {
               check_ajax_referer( 'porkpress_dns_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $record_id = isset( $_POST['record_id'] ) ? (int) $_POST['record_id'] : 0;

               $service = new Domain_Service();
               $result  = $service->delete_dns_record( $domain, $record_id );

               if ( $result instanceof Porkbun_Client_Error || is_wp_error( $result ) ) {
                       $message = $result instanceof Porkbun_Client_Error ? $result->message : $result->get_error_message();
                       wp_send_json_error( $message );
               }

               $records = $this->get_domain_info( $service, $domain )['dns'] ?? array();
               wp_send_json_success( array( 'records' => $records ) );
       }

       /**
        * AJAX handler for domain bulk actions.
        */
       public function handle_bulk_action() {
               check_ajax_referer( 'porkpress_ssl_bulk_action', 'nonce' );

               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       wp_send_json_error( 'no_permission' );
               }

               $domain    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
               $action    = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
               $site_name = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '';
               $title     = isset( $_POST['new_site_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_title'] ) ) : '';
               $email     = isset( $_POST['new_site_email'] ) ? sanitize_email( wp_unslash( $_POST['new_site_email'] ) ) : '';
               $template  = isset( $_POST['new_site_template'] ) ? sanitize_text_field( wp_unslash( $_POST['new_site_template'] ) ) : '';
               $override  = isset( $_POST['override'] ) ? sanitize_text_field( wp_unslash( $_POST['override'] ) ) : '';

               $site_id = 0;
               if ( $site_name ) {
                       $sites = get_sites(
                               array(
                                       'search' => $site_name,
                                       'number' => 1,
                               )
                       );
                       $total = get_sites(
                               array(
                                       'search' => $site_name,
                                       'count'  => true,
                               )
                       );

                       if ( 0 === (int) $total ) {
                               wp_send_json_error( 'site_not_found' );
                       } elseif ( 1 < (int) $total ) {
                               wp_send_json_error( 'multiple_sites_found' );
                       }

                       $site_id = (int) $sites[0]->blog_id;
               }

               $service = new Domain_Service();

               switch ( $action ) {
                       case 'attach':
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
        * Render the requests tab for the network admin page.
        */
       public function render_requests_tab() {
               if ( ! current_user_can( \PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS ) ) {
                       return;
               }

               if ( isset( $_POST['request_id'], $_POST['ppssl_action'] ) && check_admin_referer( 'porkpress_ssl_request_action' ) ) {
                       $requests = get_site_option( self::REQUESTS_OPTION, array() );
                       foreach ( $requests as $index => $req ) {
                               if ( $req['id'] === sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) ) {
                                       if ( 'approve' === sanitize_key( wp_unslash( $_POST['ppssl_action'] ) ) ) {
                                               $service = new Domain_Service();
                                               $service->add_alias( (int) $req['site_id'], $req['domain'] );
                                       }
                                       unset( $requests[ $index ] );
                                       update_site_option( self::REQUESTS_OPTION, array_values( $requests ) );
                                       break;
                               }
                       }
               }

               $requests = get_site_option( self::REQUESTS_OPTION, array() );

               echo '<h2>' . esc_html__( 'Pending Domain Requests', 'porkpress-ssl' ) . '</h2>';

               if ( empty( $requests ) ) {
                       echo '<p>' . esc_html__( 'No pending requests.', 'porkpress-ssl' ) . '</p>';
                       return;
               }

               echo '<table class="widefat"><thead><tr>';
               echo '<th>' . esc_html__( 'Site', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Domain', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Justification', 'porkpress-ssl' ) . '</th>';
               echo '<th>' . esc_html__( 'Actions', 'porkpress-ssl' ) . '</th>';
               echo '</tr></thead><tbody>';

               foreach ( $requests as $req ) {
                       $site      = get_site( (int) $req['site_id'] );
                       $site_name = $site ? get_blog_option( $site->id, 'blogname' ) : $req['site_id'];

                       echo '<tr>';
                       echo '<td>' . esc_html( $site_name ) . '</td>';
                       echo '<td>' . esc_html( $req['domain'] ) . '</td>';
                       echo '<td>' . esc_html( $req['justification'] ) . '</td>';
                       echo '<td>';
                       echo '<form method="post" style="display:inline">';
                       wp_nonce_field( 'porkpress_ssl_request_action' );
                       echo '<input type="hidden" name="request_id" value="' . esc_attr( $req['id'] ) . '" />';
                       echo '<input type="hidden" name="ppssl_action" value="approve" />';
                       echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Approve', 'porkpress-ssl' ) . '" />';
                       echo '</form> ';
                       echo '<form method="post" style="display:inline">';
                       wp_nonce_field( 'porkpress_ssl_request_action' );
                       echo '<input type="hidden" name="request_id" value="' . esc_attr( $req['id'] ) . '" />';
                       echo '<input type="hidden" name="ppssl_action" value="deny" />';
                       echo '<input type="submit" class="button" value="' . esc_attr__( 'Deny', 'porkpress-ssl' ) . '" />';
                       echo '</form>';
                       echo '</td>';
                       echo '</tr>';
               }

               echo '</tbody></table>';
       }

       /**
        * Render the settings tab for the network admin page.
        */
       public function render_settings_tab() {
$api_key_locked    = defined( 'PORKPRESS_API_KEY' );
$api_secret_locked = defined( 'PORKPRESS_API_SECRET' );
$cert_name_locked  = defined( 'PORKPRESS_CERT_NAME' );
$cert_root_locked  = defined( 'PORKPRESS_CERT_ROOT' );
$state_root_locked = defined( 'PORKPRESS_STATE_ROOT' );

        if ( isset( $_POST['porkpress_adopt_cert'] ) && isset( $_POST['porkpress_adopt_cert_name'] ) ) {
            check_admin_referer( 'porkpress_adopt_cert' );
            if ( ! $cert_name_locked ) {
                update_site_option( 'porkpress_ssl_cert_name', sanitize_text_field( wp_unslash( $_POST['porkpress_adopt_cert_name'] ) ) );
                echo '<div class="updated"><p>' . esc_html__( 'Certificate lineage adopted.', 'porkpress-ssl' ) . '</p></div>';
            }
        }

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
$raw_api_timeout = isset( $_POST['porkpress_api_timeout'] ) ? absint( wp_unslash( $_POST['porkpress_api_timeout'] ) ) : 0;
$api_timeout     = max( 1, $raw_api_timeout );
update_site_option( 'porkpress_ssl_api_timeout', $api_timeout );

$raw_txt_timeout = isset( $_POST['porkpress_txt_timeout'] ) ? absint( wp_unslash( $_POST['porkpress_txt_timeout'] ) ) : 0;
$txt_timeout     = max( 1, $raw_txt_timeout );
update_site_option( 'porkpress_ssl_txt_timeout', $txt_timeout );

$raw_txt_interval = isset( $_POST['porkpress_txt_interval'] ) ? absint( wp_unslash( $_POST['porkpress_txt_interval'] ) ) : 0;
$txt_interval     = max( 1, $raw_txt_interval );
update_site_option( 'porkpress_ssl_txt_interval', $txt_interval );

if ( ! $cert_name_locked && isset( $_POST['porkpress_cert_name'] ) ) {
update_site_option( 'porkpress_ssl_cert_name', sanitize_text_field( wp_unslash( $_POST['porkpress_cert_name'] ) ) );
}
if ( ! $cert_root_locked && isset( $_POST['porkpress_cert_root'] ) ) {
update_site_option( 'porkpress_ssl_cert_root', sanitize_text_field( wp_unslash( $_POST['porkpress_cert_root'] ) ) );
}
if ( ! $state_root_locked && isset( $_POST['porkpress_state_root'] ) ) {
update_site_option( 'porkpress_ssl_state_root', sanitize_text_field( wp_unslash( $_POST['porkpress_state_root'] ) ) );
}

$network_wildcard = isset( $_POST['porkpress_network_wildcard'] ) ? 1 : 0;
update_site_option( 'porkpress_ssl_network_wildcard', $network_wildcard );

if ( isset( $_POST['porkpress_ipv4'] ) ) {
    update_site_option( 'porkpress_ssl_ipv4_override', sanitize_text_field( wp_unslash( $_POST['porkpress_ipv4'] ) ) );
}
if ( isset( $_POST['porkpress_ipv6'] ) ) {
    update_site_option( 'porkpress_ssl_ipv6_override', sanitize_text_field( wp_unslash( $_POST['porkpress_ipv6'] ) ) );
}

            if ( isset( $_POST['porkpress_prod_server'] ) ) {
                update_site_option( 'porkpress_ssl_prod_server_ip', sanitize_text_field( wp_unslash( $_POST['porkpress_prod_server'] ) ) );
            }
            if ( isset( $_POST['porkpress_dev_server'] ) ) {
                update_site_option( 'porkpress_ssl_dev_server_ip', sanitize_text_field( wp_unslash( $_POST['porkpress_dev_server'] ) ) );
            }

$cert_name = get_site_option( 'porkpress_ssl_cert_name', defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network' );
$cert_root = get_site_option( 'porkpress_ssl_cert_root', defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt' );
$state_root = get_site_option( 'porkpress_ssl_state_root', defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl' );
$network_wildcard = (bool) get_site_option( 'porkpress_ssl_network_wildcard', 0 );

            $auto_reconcile = isset( $_POST['porkpress_auto_reconcile'] ) ? 1 : 0;
            update_site_option( 'porkpress_ssl_auto_reconcile', $auto_reconcile );

            $dry_run = isset( $_POST['porkpress_dry_run'] ) ? 1 : 0;
            update_site_option( 'porkpress_ssl_dry_run', $dry_run );

            $apache_reload = isset( $_POST['porkpress_apache_reload'] ) ? 1 : 0;
            update_site_option( 'porkpress_ssl_apache_reload', $apache_reload );

            if ( isset( $_POST['porkpress_apache_reload_cmd'] ) ) {
                update_site_option( 'porkpress_ssl_apache_reload_cmd', sanitize_text_field( wp_unslash( $_POST['porkpress_apache_reload_cmd'] ) ) );
            }

// Log the settings update without exposing sensitive values.
            Logger::info(
                'update_settings',
                array(
                    'api_key_changed'    => ! $api_key_locked && isset( $_POST['porkpress_api_key'] ),
                    'api_secret_changed' => ! $api_secret_locked && isset( $_POST['porkpress_api_secret'] ),
                    'le_staging'         => (bool) $staging,
                    'renew_window'       => $renew_window,
                    'api_timeout'        => $api_timeout,
                    'txt_timeout'        => $txt_timeout,
                    'txt_interval'       => $txt_interval,
                    'ipv4_override'      => $ipv4_override,
                    'ipv6_override'      => $ipv6_override,
                    'auto_reconcile'     => (bool) $auto_reconcile,
                    'dry_run'            => (bool) $dry_run,
                    'network_wildcard'   => (bool) $network_wildcard,
                    'apache_reload'      => (bool) $apache_reload,
                    'cert_name'          => $cert_name,
                    'cert_root'          => $cert_root,
                    'state_root'         => $state_root,
                ),
                'Settings saved'
            );

            \PorkPress\SSL\Renewal_Service::maybe_schedule( true );

            if ( $txt_timeout !== $raw_txt_timeout || $txt_interval !== $raw_txt_interval ) {
                    echo '<div class="notice notice-warning"><p>' . esc_html__( 'TXT record wait values must be at least 1 second. Submitted values have been adjusted.', 'porkpress-ssl' ) . '</p></div>';
            }

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'porkpress-ssl' ) . '</p></div>';
}

$api_key    = $api_key_locked ? PORKPRESS_API_KEY : get_site_option( 'porkpress_ssl_api_key', '' );
$api_secret = $api_secret_locked ? PORKPRESS_API_SECRET : get_site_option( 'porkpress_ssl_api_secret', '' );
$staging    = (bool) get_site_option( 'porkpress_ssl_le_staging', 0 );
$renew_window = absint( get_site_option( 'porkpress_ssl_renew_window', 30 ) );
$api_timeout  = max( 1, absint( get_site_option( 'porkpress_ssl_api_timeout', 20 ) ) );
$txt_timeout  = max( 1, absint( get_site_option( 'porkpress_ssl_txt_timeout', 600 ) ) );
$txt_interval = max( 1, absint( get_site_option( 'porkpress_ssl_txt_interval', 30 ) ) );
$ipv4_override = get_site_option( 'porkpress_ssl_ipv4_override', '' );
$ipv6_override = get_site_option( 'porkpress_ssl_ipv6_override', '' );
               $prod_server_ip = get_site_option( 'porkpress_ssl_prod_server_ip', '' );
               $dev_server_ip  = get_site_option( 'porkpress_ssl_dev_server_ip', '' );
$cert_name = $cert_name_locked ? PORKPRESS_CERT_NAME : get_site_option( 'porkpress_ssl_cert_name', defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network' );
$cert_root = $cert_root_locked ? PORKPRESS_CERT_ROOT : get_site_option( 'porkpress_ssl_cert_root', defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt' );
$state_root = $state_root_locked ? PORKPRESS_STATE_ROOT : get_site_option( 'porkpress_ssl_state_root', defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl' );
$network_wildcard = (bool) get_site_option( 'porkpress_ssl_network_wildcard', 0 );
        $auto_reconcile = (bool) get_site_option( 'porkpress_ssl_auto_reconcile', 1 );
        $dry_run        = (bool) get_site_option( 'porkpress_ssl_dry_run', 0 );
        $apache_reload  = (bool) get_site_option( 'porkpress_ssl_apache_reload', 1 );
        $apache_cmd     = Renewal_Service::get_apache_reload_cmd();
        $certbot_certs = Certbot_Helper::list_certificates();
        if ( ! empty( $certbot_certs ) && ! $cert_name_locked ) {
                $network_hosts = array();
                if ( function_exists( 'network_home_url' ) ) {
                        $parse = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
                        $host  = $parse( network_home_url(), PHP_URL_HOST );
                        if ( $host ) {
                                $network_hosts[] = strtolower( $host );
                        }
                }
                if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
                        $network_hosts[] = strtolower( DOMAIN_CURRENT_SITE );
                }
                $network_hosts = array_unique( array_filter( $network_hosts ) );

                echo '<h2>' . esc_html__( 'Adopt Existing Certificate', 'porkpress-ssl' ) . '</h2>';
                echo '<p>' . esc_html__( 'Select a certificate lineage to reuse for future requests.', 'porkpress-ssl' ) . '</p>';
                echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Certificate Name', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Domains', 'porkpress-ssl' ) . '</th><th></th></tr></thead><tbody>';
                foreach ( $certbot_certs as $name => $info ) {
                        $domains  = $info['domains'];
                        $is_rec   = ! empty( array_intersect( $network_hosts, array_map( 'strtolower', $domains ) ) );
                        echo '<tr>';
                        echo '<td>' . esc_html( $name ) . ( $is_rec ? ' <strong>' . esc_html__( '(recommended)', 'porkpress-ssl' ) . '</strong>' : '' ) . '</td>';
                        echo '<td>' . esc_html( implode( ', ', $domains ) ) . '</td>';
                        echo '<td><form method="post" style="margin:0;">';
                        wp_nonce_field( 'porkpress_adopt_cert' );
                        echo '<input type="hidden" name="porkpress_adopt_cert_name" value="' . esc_attr( $name ) . '" />';
                        submit_button( __( 'Adopt', 'porkpress-ssl' ), 'secondary', 'porkpress_adopt_cert', false );
                        echo '</form></td>';
                        echo '</tr>';
                }
                echo '</tbody></table>';
        }

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
echo '<th scope="row"><label for="porkpress_api_timeout">' . esc_html__( 'Porkbun API Timeout (seconds)', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_api_timeout" type="number" id="porkpress_api_timeout" value="' . esc_attr( $api_timeout ) . '" class="small-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_cert_name">' . esc_html__( 'Certificate Name', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_cert_name" type="text" id="porkpress_cert_name" value="' . esc_attr( $cert_name ) . '" class="regular-text"' . ( $cert_name_locked ? ' readonly' : '' ) . ' /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_cert_root">' . esc_html__( 'Certificate Root', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_cert_root" type="text" id="porkpress_cert_root" value="' . esc_attr( $cert_root ) . '" class="regular-text"' . ( $cert_root_locked ? ' readonly' : '' ) . ' /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_state_root">' . esc_html__( 'State Root', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_state_root" type="text" id="porkpress_state_root" value="' . esc_attr( $state_root ) . '" class="regular-text"' . ( $state_root_locked ? ' readonly' : '' ) . ' /></td>';
echo '</tr>';
echo '<tr>';
$base_domain = defined( 'DOMAIN_CURRENT_SITE' ) ? DOMAIN_CURRENT_SITE : '';
echo '<th scope="row">' . esc_html__( 'Network Wildcard', 'porkpress-ssl' ) . '</th>';
echo '<td><label><input name="porkpress_network_wildcard" type="checkbox" value="1"' . checked( $network_wildcard, true, false ) . ' /> ' . esc_html__( 'Include base domain and wildcard', 'porkpress-ssl' ) . ( $base_domain ? ' (' . esc_html( $base_domain ) . ', *.' . esc_html( $base_domain ) . ')' : '' ) . '</label></td>';
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
echo '<th scope="row"><label for="porkpress_ipv4">' . esc_html__( 'Network IPv4 Override', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_ipv4" type="text" id="porkpress_ipv4" value="' . esc_attr( $ipv4_override ) . '" class="regular-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_ipv6">' . esc_html__( 'Network IPv6 Override', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_ipv6" type="text" id="porkpress_ipv6" value="' . esc_attr( $ipv6_override ) . '" class="regular-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_prod_server">' . esc_html__( 'Production Server', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_prod_server" type="text" id="porkpress_prod_server" value="' . esc_attr( $prod_server ) . '" class="regular-text" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th scope="row"><label for="porkpress_dev_server">' . esc_html__( 'Development Server', 'porkpress-ssl' ) . '</label></th>';
echo '<td><input name="porkpress_dev_server" type="text" id="porkpress_dev_server" value="' . esc_attr( $dev_server ) . '" class="regular-text" /></td>';
echo '</tr>';
echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Automatic Reconciliation', 'porkpress-ssl' ) . '</th>';
        echo '<td><label><input name="porkpress_auto_reconcile" type="checkbox" value="1"' . checked( $auto_reconcile, true, false ) . ' /> ' . esc_html__( 'Enable automatic drift remediation', 'porkpress-ssl' ) . '</label></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Dry Run Mode', 'porkpress-ssl' ) . '</th>';
        echo '<td><label><input name="porkpress_dry_run" type="checkbox" value="1"' . checked( $dry_run, true, false ) . ' /> ' . esc_html__( 'Enable dry-run mode', 'porkpress-ssl' ) . '</label></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Reload Apache', 'porkpress-ssl' ) . '</th>';
        echo '<td><label><input name="porkpress_apache_reload" type="checkbox" value="1"' . checked( $apache_reload, true, false ) . ' /> ' . esc_html__( 'Copy certs and reload Apache after renewal', 'porkpress-ssl' ) . '</label></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="porkpress_apache_reload_cmd">' . esc_html__( 'Apache Reload Command', 'porkpress-ssl' ) . '</label></th>';
        echo '<td><input name="porkpress_apache_reload_cmd" type="text" id="porkpress_apache_reload_cmd" value="' . esc_attr( $apache_cmd ) . '" class="regular-text" /></td>';
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

               if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
                        printf( '<div class="error"><p>%s</p></div>', esc_html__( 'Logs table does not exist.', 'porkpress-ssl' ) );
                        return;
                }

                $severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';

               if ( isset( $_GET['export'] ) ) {
                       $export = sanitize_key( wp_unslash( $_GET['export'] ) );
                       $logs   = Logger::get_logs( array( 'severity' => $severity, 'limit' => 0 ) );
                       foreach ( $logs as &$log ) {
                               $user        = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
                               $log['user'] = $user ? $user->user_login : '';
                               $log['context'] = Logger::sanitize_context( $log['context'], false );
                               unset( $log['user_id'] );
                       }
                       if ( 'csv' === $export ) {
                               header( 'Content-Type: text/csv' );
                               header( 'Content-Disposition: attachment; filename="porkpress-logs.csv"' );
                               $fh     = fopen( 'php://output', 'w' );
                               fputcsv( $fh, array( 'time', 'user', 'action', 'context', 'result', 'severity' ) );
                               $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
                               foreach ( $logs as $log ) {
                                       fputcsv( $fh, array( $log['time'], $log['user'], $log['action'], $encode( $log['context'] ), $log['result'], $log['severity'] ) );
                               }
                               exit;
                       }
                       if ( 'json' === $export ) {
                               header( 'Content-Type: application/json' );
                               header( 'Content-Disposition: attachment; filename="porkpress-logs.json"' );
                               $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
                               echo $encode( $logs );
                               exit;
                       }
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
               echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'export' => 'json' ) ) ) . '">' . esc_html__( 'Export JSON', 'porkpress-ssl' ) . '</a>';
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
                               echo '<td><code>' . esc_html( Logger::sanitize_context( $log['context'] ) ) . '</code></td>';
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

               $domain_list = $service->list_domains();
               $available   = array();
               if ( ! ( $domain_list instanceof Porkbun_Client_Error ) && ! empty( $domain_list['domains'] ) ) {
                       $mapped = wp_list_pluck( $service->get_aliases(), 'domain' );
                       foreach ( $domain_list['domains'] as $info ) {
                               $root = $info['domain'] ?? $info['name'] ?? '';
                               if ( $root && ! in_array( $root, $mapped, true ) ) {
                                       $available[] = $root;
                               }
                       }
               }

               if ( isset( $_POST['porkpress_add_alias'] ) ) {
                       check_admin_referer( 'porkpress_add_alias' );
                       $domain = sanitize_text_field( wp_unslash( $_POST['alias_domain'] ) );
                       if ( $domain && in_array( $domain, $available, true ) ) {
                               $is_primary = empty( $service->get_aliases( $site_id ) );
                               $service->add_alias( $site_id, $domain, $is_primary );
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'added', $redirect ) );
                               exit;
                       }

                       if ( $domain && ! empty( $service->get_aliases( null, $domain ) ) ) {
                               wp_safe_redirect( add_query_arg( 'pp_msg', 'exists', $redirect ) );
                               exit;
                       }

                       wp_safe_redirect( add_query_arg( 'pp_msg', 'invalid', $redirect ) );
                       exit;
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
               // Build shard map for display.
               $all_aliases = $service->get_aliases();
               $external   = array_filter(
                       $all_aliases,
                       fn( $a ) => ! $service->is_internal_subdomain( (int) $a['site_id'], $a['domain'] )
               );
               $domain_map = array();
               if ( ! empty( $external ) ) {
                       $groups = \PorkPress\SSL\SSL_Service::shard_domains( array_map( fn( $a ) => $a['domain'], $external ) );
                       foreach ( $groups as $idx => $names ) {
                               foreach ( $names as $n ) {
                                       $domain_map[ $n ] = 'porkpress-shard-' . $idx;
                               }
                       }
               }

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
                               case 'invalid':
                                       $text = __( 'Invalid domain selected.', 'porkpress-ssl' );
                                       break;
                       }
                       if ( $text ) {
                               printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $text ) );
                       }
               }

               echo '<table class="widefat fixed striped">';
               echo '<thead><tr><th>' . esc_html__( 'Domain', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Shard', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Primary', 'porkpress-ssl' ) . '</th><th>' . esc_html__( 'Actions', 'porkpress-ssl' ) . '</th></tr></thead><tbody>';

               if ( empty( $aliases ) ) {
                       echo '<tr><td colspan="3">' . esc_html__( 'No aliases found.', 'porkpress-ssl' ) . '</td></tr>';
               } else {
                       foreach ( $aliases as $alias ) {
                               echo '<tr>';
                               echo '<td>' . esc_html( $alias['domain'] ) . '</td>';
                               $shard = isset( $domain_map[ $alias['domain'] ] ) ? $domain_map[ $alias['domain'] ] : esc_html__( 'internal', 'porkpress-ssl' );
                               echo '<td>' . esc_html( $shard ) . '</td>';
                               echo '<td>' . ( $alias['is_primary'] ? '&#10003;' : '' ) . '</td>';
                               echo '<td class="porkpress-alias-actions">';
                               if ( ! $alias['is_primary'] ) {
                                       $primary_url = wp_nonce_url( add_query_arg( 'make_primary', rawurlencode( $alias['domain'] ), $redirect ), 'porkpress_make_primary_' . $alias['domain'] );
                                       $delete_url  = wp_nonce_url( add_query_arg( 'delete_alias', rawurlencode( $alias['domain'] ), $redirect ), 'porkpress_delete_alias_' . $alias['domain'] );
                                       echo '<a href="' . esc_url( $primary_url ) . '" data-action="set-primary">' . esc_html__( 'Set Primary', 'porkpress-ssl' ) . '</a> | ';
                                       echo '<a href="' . esc_url( $delete_url ) . '" data-action="remove">' . esc_html__( 'Remove', 'porkpress-ssl' ) . '</a>';
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
               echo '<input type="text" name="alias_domain" class="regular-text" list="porkpress-domain-list" /> ';
               echo '<datalist id="porkpress-domain-list">';
               foreach ( $available as $domain ) {
                       echo '<option value="' . esc_attr( $domain ) . '"></option>';
               }
               echo '</datalist>';
               submit_button( __( 'Add', 'porkpress-ssl' ), 'secondary', 'porkpress_add_alias', false );
               echo '<p class="description">' . esc_html__( 'Only domains not currently mapped are listed.', 'porkpress-ssl' ) . '</p>';
               echo '</form>';

               wp_enqueue_script(
                       'porkpress-site-aliases',
                       set_url_scheme( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/site-aliases.js', 'https' ),
                       array( 'jquery', 'wp-i18n' ),
                       PORKPRESS_SSL_VERSION,
                       true
               );
               wp_set_script_translations( 'porkpress-site-aliases', 'porkpress-ssl', plugin_dir_path( dirname( __FILE__ ) ) . 'languages' );

               echo '</div>';
       }

/**
 * Render the site plugin page.
 */
public function render_site_page() {
        if ( ! current_user_can( \PORKPRESS_SSL_CAP_REQUEST_DOMAIN ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'porkpress-ssl' ) );
        }

        $service        = new Domain_Service();
        $submitted      = false;
        $available      = false;
        $domain        = '';
        $justification = '';
        $error         = '';

        if ( isset( $_POST['porkpress_ssl_domain'], $_POST['porkpress_ssl_justification'] ) && check_admin_referer( 'porkpress_ssl_request_domain' ) ) {
                $domain        = sanitize_text_field( wp_unslash( $_POST['porkpress_ssl_domain'] ) );
                $justification = sanitize_textarea_field( wp_unslash( $_POST['porkpress_ssl_justification'] ) );

                if ( isset( $_POST['porkpress_ssl_confirm'] ) ) {
                        $requests   = get_site_option( self::REQUESTS_OPTION, array() );
                        $requests[] = array(
                                'id'            => wp_generate_uuid4(),
                                'site_id'       => get_current_blog_id(),
                                'domain'        => $domain,
                                'justification' => $justification,
                        );
                        update_site_option( self::REQUESTS_OPTION, $requests );

                        $site    = get_site( get_current_blog_id() );
                        $subject = __( 'New Domain Request', 'porkpress-ssl' );
                        $message = sprintf(
                                __( 'Site %1$s requested domain %2$s. Justification: %3$s', 'porkpress-ssl' ),
                                $site ? get_blog_option( $site->id, 'blogname' ) : get_current_blog_id(),
                                $domain,
                                $justification
                        );
                        Notifier::notify( 'warning', $subject, $message );
                        $submitted = true;
                } else {
                        $check = $service->check_domain( $domain );
                        if ( $check instanceof Porkbun_Client_Error ) {
                                $error = $check->message;
                        } elseif ( isset( $check['response']['avail'] ) && 'yes' === strtolower( $check['response']['avail'] ) ) {
                                $available = true;
                        } else {
                                $error = __( 'Domain is not available.', 'porkpress-ssl' );
                        }
                }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Request Domain', 'porkpress-ssl' ) . '</h1>';
        if ( $submitted ) {
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Request submitted.', 'porkpress-ssl' ) . '</p></div>';
        } elseif ( $error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }

        if ( $available && ! $submitted ) {
                $purchase_url = 'https://porkbun.com/checkout/search?q=' . rawurlencode( $domain );
                echo '<p>' . sprintf( esc_html__( 'Domain available. Complete the purchase at Porkbun then click %s to continue.', 'porkpress-ssl' ), '<a href="' . esc_url( $purchase_url ) . '" target="_blank">' . esc_html__( 'Purchase', 'porkpress-ssl' ) . '</a>' ) . '</p>';
                echo '<form method="post">';
                wp_nonce_field( 'porkpress_ssl_request_domain' );
                echo '<input type="hidden" name="porkpress_ssl_domain" value="' . esc_attr( $domain ) . '" />';
                echo '<input type="hidden" name="porkpress_ssl_justification" value="' . esc_attr( $justification ) . '" />';
                echo '<input type="hidden" name="porkpress_ssl_confirm" value="1" />';
                submit_button( __( "I've purchased", 'porkpress-ssl' ) );
                echo '</form>';
        } elseif ( ! $submitted ) {
                echo '<form method="post">';
                wp_nonce_field( 'porkpress_ssl_request_domain' );
                echo '<table class="form-table" role="presentation">';
                echo '<tr><th scope="row"><label for="porkpress-ssl-domain">' . esc_html__( 'Desired Domain', 'porkpress-ssl' ) . '</label></th><td><input name="porkpress_ssl_domain" type="text" id="porkpress-ssl-domain" class="regular-text" value="' . esc_attr( $domain ) . '" required></td></tr>';
                echo '<tr><th scope="row"><label for="porkpress-ssl-justification">' . esc_html__( 'Justification', 'porkpress-ssl' ) . '</label></th><td><textarea name="porkpress_ssl_justification" id="porkpress-ssl-justification" class="large-text" rows="5" required>' . esc_textarea( $justification ) . '</textarea></td></tr>';
                echo '</table>';
                submit_button( __( 'Check Availability', 'porkpress-ssl' ) );
                echo '</form>';
        }
        echo '</div>';
}
}
