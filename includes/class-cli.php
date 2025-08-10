<?php
/**
 * WP-CLI commands for PorkPress SSL.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Formatter;

/**
 * Manage SSL certificates via WP-CLI.
 */
class CLI extends WP_CLI_Command {
        /**
         * Issue a certificate for a set of domains.
         *
         * ## OPTIONS
         *
         * --domains=<domains>
         * : Comma-separated list of domains.
         *
         * [--staging]
         * : Use Let's Encrypt staging environment.
         *
         * [--cert-name=<name>]
         * : Certificate name to use. Defaults to "porkpress-network".
         *
         * ## EXAMPLES
         *
         *     wp porkpress ssl:issue --domains="example.com,www.example.com"
         *
         * @when after_wp_load
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Associative arguments.
         */
        public function issue( $args, $assoc_args ) {
                if ( empty( $assoc_args['domains'] ) ) {
                        WP_CLI::error( '--domains is required.' );
                }
                $domains   = array_filter( array_map( 'trim', explode( ',', $assoc_args['domains'] ) ) );
                $cert_name = $assoc_args['cert-name'] ?? 'porkpress-network';
                $staging   = isset( $assoc_args['staging'] );
                $this->run_certbot( $domains, $cert_name, $staging, false );
        }

        /**
         * Renew the certificate for all domains in the manifest.
         *
         * ## OPTIONS
         *
         * [--staging]
         * : Use Let's Encrypt staging environment.
         *
         * [--cert-name=<name>]
         * : Certificate name to use. Defaults to "porkpress-network".
         *
         * ## EXAMPLES
         *
         *     wp porkpress ssl:renew-all
         *
         * @when after_wp_load
         *
         * @param array $args       Positional arguments.
         * @param array $assoc_args Associative arguments.
         */
        public function renew_all( $args, $assoc_args ) {
                $state_root    = defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl';
                $manifest_path = rtrim( $state_root, '/\\' ) . '/manifest.json';
                if ( ! file_exists( $manifest_path ) ) {
                        WP_CLI::error( 'Manifest not found. Issue a certificate first.' );
                }
                $manifest = json_decode( file_get_contents( $manifest_path ), true );
                if ( empty( $manifest['domains'] ) || ! is_array( $manifest['domains'] ) ) {
                        WP_CLI::error( 'Manifest does not contain domains.' );
                }
                $domains   = $manifest['domains'];
                $cert_name = $assoc_args['cert-name'] ?? ( $manifest['cert_name'] ?? 'porkpress-network' );
                $staging   = isset( $assoc_args['staging'] );
                $this->run_certbot( $domains, $cert_name, $staging, true );
        }

        /**
         * Invoke certbot and write manifest.
         *
         * @param array  $domains    Domains to include.
         * @param string $cert_name  Certificate lineage name.
         * @param bool   $staging    Whether to use staging environment.
         * @param bool   $renewal    Force renewal of existing certificate.
         */
        protected function run_certbot( array $domains, string $cert_name, bool $staging, bool $renewal ) {
                $cert_root  = defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt';
                $state_root = defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl';

                if ( ! is_dir( $state_root ) ) {
                        wp_mkdir_p( $state_root );
                }
                if ( ! is_dir( $cert_root ) ) {
                        wp_mkdir_p( $cert_root );
                }

                $cmd = Certbot_Helper::build_command( $domains, $cert_name, $staging, $renewal );

                $result = WP_CLI::launch( $cmd, false, true );
                if ( 0 !== $result->return_code ) {
                        WP_CLI::error( 'certbot failed: ' . $result->stderr );
                }

                $live_dir = rtrim( $cert_root, '/\\' ) . '/live/' . $cert_name;
                $paths    = [
                        'fullchain' => $live_dir . '/fullchain.pem',
                        'privkey'   => $live_dir . '/privkey.pem',
                        'chain'     => $live_dir . '/chain.pem',
                        'cert'      => $live_dir . '/cert.pem',
                ];

                $issued_at = $expires_at = null;
                if ( file_exists( $paths['cert'] ) ) {
                        $cert_data = openssl_x509_parse( file_get_contents( $paths['cert'] ) );
                        if ( $cert_data ) {
                                $issued_at  = gmdate( 'c', $cert_data['validFrom_time_t'] );
                                $expires_at = gmdate( 'c', $cert_data['validTo_time_t'] );
                        }
                }

                $manifest = [
                        'cert_name'  => $cert_name,
                        'domains'    => array_values( $domains ),
                        'issued_at'  => $issued_at,
                        'expires_at' => $expires_at,
                        'paths'      => $paths,
                ];

                file_put_contents( rtrim( $state_root, '/\\' ) . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

                WP_CLI::success( 'Certificate stored.' );
        }

       /**
        * Export logs.
        *
        * ## OPTIONS
        *
        * [--format=<format>]
        * : Output format: csv or json. Default json.
        *
        * [--severity=<level>]
        * : Filter by severity.
        *
        * [--limit=<number>]
        * : Number of entries to include. Default 100. Use 0 for all.
        *
        * ## EXAMPLES
        *
        *     wp porkpress ssl export-logs --format=csv > logs.csv
        *
        * @when after_wp_load
        *
        * @param array $args       Positional arguments.
        * @param array $assoc_args Associative arguments.
        */
       public function export_logs( $args, $assoc_args ) {
               $format   = $assoc_args['format'] ?? 'json';
               $severity = $assoc_args['severity'] ?? '';
               $limit    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 100;

               $logs = Logger::get_logs( array( 'severity' => $severity, 'limit' => $limit ) );
               foreach ( $logs as &$log ) {
                       $user        = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
                       $log['user'] = $user ? $user->user_login : '';
                       $log['context'] = Logger::sanitize_context( $log['context'], false );
               }

               $fields = array( 'time', 'user', 'action', 'context', 'result', 'severity' );
               if ( 'csv' === $format ) {
                       $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
                       foreach ( $logs as &$log ) {
                               $log['context'] = $encode( $log['context'] );
                       }
               }

               $assoc_args['format'] = $format;
               $formatter = new Formatter( $assoc_args, $fields );
               $formatter->display_items( $logs );
       }

       /**
        * Export domain alias mapping.
        *
        * ## OPTIONS
        *
        * [--format=<format>]
        * : Output format: csv or json. Default json.
        *
        * ## EXAMPLES
        *
        *     wp porkpress ssl export-mapping --format=csv > mapping.csv
        *
        * @when after_wp_load
        *
        * @param array $args       Positional arguments.
        * @param array $assoc_args Associative arguments.
        */
       public function export_mapping( $args, $assoc_args ) {
               $format  = $assoc_args['format'] ?? 'json';
               $service = new Domain_Service();
               $aliases = $service->get_aliases();

               $fields = array( 'site_id', 'domain', 'is_primary', 'status' );
               $assoc_args['format'] = $format;
               $formatter = new Formatter( $assoc_args, $fields );
               $formatter->display_items( $aliases );
       }
}
