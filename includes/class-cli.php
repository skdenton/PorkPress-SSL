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
                $cert_name = $assoc_args['cert-name'] ?? get_site_option(
                        'porkpress_ssl_cert_name',
                        defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
                );
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
                $state_root    = get_site_option(
                        'porkpress_ssl_state_root',
                        defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl'
                );
                $manifest_path = rtrim( $state_root, '/\\' ) . '/manifest.json';
                if ( ! file_exists( $manifest_path ) ) {
                        WP_CLI::error( 'Manifest not found. Issue a certificate first.' );
                }
                $manifest = json_decode( file_get_contents( $manifest_path ), true );
                if ( empty( $manifest['domains'] ) || ! is_array( $manifest['domains'] ) ) {
                        WP_CLI::error( 'Manifest does not contain domains.' );
                }
                $domains   = $manifest['domains'];
                $cert_name = $assoc_args['cert-name'] ?? ( $manifest['cert_name'] ?? get_site_option(
                        'porkpress_ssl_cert_name',
                        defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
                ) );
$staging   = isset( $assoc_args['staging'] );
$this->run_certbot( $domains, $cert_name, $staging, true );
}

/**
 * Check system dependencies and environment.
 *
 * ## OPTIONS
 *
 * [--config-dir=<path>]
 * : Certbot configuration directory. Defaults to /etc/letsencrypt.
 *
 * [--logs-dir=<path>]
 * : Certbot logs directory. Defaults to /var/log/letsencrypt.
 *
 * [--work-dir=<path>]
 * : Certbot work directory. Defaults to /var/lib/letsencrypt.
 *
 * ## EXAMPLES
 *
 * wp porkpress ssl:health
 *
 * @when after_wp_load
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 */
public function health( $args, $assoc_args ) {
$config_dir = $assoc_args['config-dir'] ?? '/etc/letsencrypt';
$logs_dir   = $assoc_args['logs-dir'] ?? '/var/log/letsencrypt';
$work_dir   = $assoc_args['work-dir'] ?? '/var/lib/letsencrypt';

$lines  = array();
$failed = false;

$which        = Runner::run( 'command -v certbot 2>/dev/null', 'certbot' );
$certbot_path = trim( $which['output'] );
if ( $certbot_path ) {
$lines[] = 'certbot: OK (' . $certbot_path . ')';
} else {
$lines[] = 'certbot: not found';
$failed  = true;
}

if ( is_dir( $config_dir ) && is_readable( $config_dir ) ) {
$lines[] = 'config-dir: OK (' . $config_dir . ')';
} else {
$lines[] = 'config-dir: unreadable (' . $config_dir . ')';
$failed  = true;
}
if ( is_dir( $logs_dir ) && is_writable( $logs_dir ) ) {
$lines[] = 'logs-dir: OK (' . $logs_dir . ')';
} else {
$lines[] = 'logs-dir: not writable (' . $logs_dir . ')';
$failed  = true;
}
if ( is_dir( $work_dir ) && is_writable( $work_dir ) ) {
$lines[] = 'work-dir: OK (' . $work_dir . ')';
} else {
$lines[] = 'work-dir: not writable (' . $work_dir . ')';
$failed  = true;
}

if ( $certbot_path ) {
$cmd = 'certbot certificates';
if ( $config_dir ) {
$cmd .= ' --config-dir ' . escapeshellarg( $config_dir );
}
if ( $work_dir ) {
$cmd .= ' --work-dir ' . escapeshellarg( $work_dir );
}
if ( $logs_dir ) {
$cmd .= ' --logs-dir ' . escapeshellarg( $logs_dir );
}
$result = Runner::run( $cmd . ' 2>/dev/null', 'certbot' );
$parsed = array();
if ( 0 === $result['code'] ) {
$parsed = Certbot_Helper::parse_certificates_output( $result['output'] );
}
if ( $parsed ) {
$parts = array();
foreach ( $parsed as $name => $info ) {
$parts[] = $name . ' (' . implode( ', ', $info['domains'] ) . ')';
}
$lines[] = 'lineages: ' . implode( '; ', $parts );
} else {
$lines[] = 'lineages: none';
}
} else {
$lines[] = 'lineages: certbot unavailable';
}

$apache_cmd = Renewal_Service::get_apache_reload_cmd();
$lines[]    = 'apache-reload: ' . ( $apache_cmd ? $apache_cmd : 'not detected' );

$dig = trim( Runner::run( 'command -v dig 2>/dev/null' )['output'] );
if ( $dig ) {
$lines[] = 'dig: OK (' . $dig . ')';
} else {
$lines[] = 'dig: not found';
$failed  = true;
}

if ( function_exists( 'dns_get_record' ) ) {
$lines[] = 'dns extension: available';
} else {
$lines[] = 'dns extension: missing';
$failed  = true;
}

foreach ( $lines as $line ) {
WP_CLI::log( $line );
}

if ( $failed ) {
WP_CLI::error( 'Health check failed.' );
}

WP_CLI::success( 'All checks passed.' );
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
                $cert_root  = get_site_option(
                        'porkpress_ssl_cert_root',
                        defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt'
                );
                $state_root = get_site_option(
                        'porkpress_ssl_state_root',
                        defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl'
                );

                if ( ! is_dir( $state_root ) ) {
                        wp_mkdir_p( $state_root );
                }
                if ( ! is_dir( $cert_root ) ) {
                        wp_mkdir_p( $cert_root );
                }

                // If a certificate with this lineage already exists, include all
                // of its current SANs to ensure we modify the existing
                // certificate rather than inadvertently creating a new
                // lineage or dropping names.
                $existing = Certbot_Helper::list_certificates();
                if ( isset( $existing[ $cert_name ]['domains'] ) ) {
                        $domains = array_values( array_unique( array_merge( $existing[ $cert_name ]['domains'], $domains ) ) );
                }

                $cmd = Certbot_Helper::build_command( $domains, $cert_name, $staging, $renewal );

                $result = WP_CLI::launch( $cmd, false, true );
                if ( 0 !== $result->return_code ) {
                        WP_CLI::error( 'certbot failed: ' . $result->stderr );
                }

                if ( ! Renewal_Service::write_manifest( $domains, $cert_name ) ) {
                        WP_CLI::error( 'Failed to write manifest.' );
                }

                if ( ! Renewal_Service::deploy_to_apache( $cert_name ) ) {
                        $err = Renewal_Service::$last_reload['output'] ?? '';
                        WP_CLI::error( 'Deployment failed: ' . $err );
                }

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
