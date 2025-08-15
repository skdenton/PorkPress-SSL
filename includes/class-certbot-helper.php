<?php
/**
 * Helper utilities for building certbot commands.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

require_once __DIR__ . '/class-runner.php';

/**
 * Utility class for constructing certbot command strings.
 */
class Certbot_Helper {
    /**
     * Build the certbot command.
     *
     * @param array  $domains  Domains to include.
     * @param string $cert_name Certificate lineage name.
     * @param bool   $staging  Whether to use staging. Uses Certbot's `--staging` flag
     *                        to avoid rate limits during testing. See
     *                        https://eff-certbot.readthedocs.io/en/stable/using.html#staging
     * @param bool   $renewal  Force renewal.
     * @return string
     */
    public static function build_command( array $domains, string $cert_name, bool $staging, bool $renewal = false ): string {
        $cert_root = function_exists( '\\get_site_option' ) ? \get_site_option(
                'porkpress_ssl_cert_root',
                defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : ''
        ) : ( defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '' );
        $work_dir = function_exists( '\\get_site_option' ) ? \get_site_option(
                'porkpress_ssl_work_dir',
                defined( 'PORKPRESS_WORK_DIR' ) ? PORKPRESS_WORK_DIR : ''
        ) : ( defined( 'PORKPRESS_WORK_DIR' ) ? PORKPRESS_WORK_DIR : '' );
        $logs_dir = function_exists( '\\get_site_option' ) ? \get_site_option(
                'porkpress_ssl_logs_dir',
                defined( 'PORKPRESS_LOGS_DIR' ) ? PORKPRESS_LOGS_DIR : ''
        ) : ( defined( 'PORKPRESS_LOGS_DIR' ) ? PORKPRESS_LOGS_DIR : '' );
        $hook = dirname( __DIR__ ) . '/bin/porkpress-hook.php';
        $certbot_cmd = function_exists( '\\get_site_option' ) ? \get_site_option( 'porkpress_ssl_certbot_cmd', 'certbot' ) : 'certbot';
        $cmd         = escapeshellcmd( $certbot_cmd ) . ' certonly --manual --non-interactive --agree-tos --manual-public-ip-logging-ok --preferred-challenges dns';
        $cmd .= ' --manual-auth-hook ' . escapeshellarg( $hook . ' add' );
        $cmd .= ' --manual-cleanup-hook ' . escapeshellarg( $hook . ' del' );
        $cmd .= ' --deploy-hook ' . escapeshellarg( $hook . ' deploy' );
        $cmd .= ' --cert-name ' . escapeshellarg( $cert_name );
        if ( $cert_root ) {
            $cmd .= ' --config-dir ' . escapeshellarg( $cert_root );
        }
        if ( $work_dir ) {
            $cmd .= ' --work-dir ' . escapeshellarg( $work_dir );
        }
        if ( $logs_dir ) {
            $cmd .= ' --logs-dir ' . escapeshellarg( $logs_dir );
        }
        if ( $renewal ) {
            $cmd .= ' --force-renewal';
        }
        if ( $staging ) {
            $cmd .= ' --staging';
        }
        foreach ( $domains as $domain ) {
            $domain = strtolower( $domain );
            if ( 0 === strpos( $domain, '*.') ) {
                $domain = '*.' . preg_replace( '/[^a-z0-9.-]/', '', substr( $domain, 2 ) );
            } else {
                $domain = preg_replace( '/[^a-z0-9.-]/', '', $domain );
            }
            $cmd .= ' -d ' . escapeshellarg( $domain );
        }
        return $cmd;
    }

    /**
     * List existing certbot certificate lineages.
     *
     * Invokes `certbot certificates` and parses the output into an associative
     * array keyed by lineage name. Each entry contains a `domains` array of
     * SANs present in that certificate.
     *
     * @return array<string, array{domains: array<int, string>, expiry: string, status: string}>
     */
    public static function list_certificates(): array {
        $cmd    = 'certbot certificates';
        $result = Runner::run( $cmd . ' 2>/dev/null', 'certbot' );
        $user   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

        if ( 0 !== $result['code'] ) {
            Logger::error(
                'certbot_list',
                array(
                    'cmd'    => $cmd,
                    'output' => $result['output'],
                    'user_id' => $user,
                ),
                'failed'
            );
            Notifier::notify(
                'error',
                function_exists( '__' ) ? __( 'Certbot list failed', 'porkpress-ssl' ) : 'Certbot list failed',
                function_exists( '__' ) ? __( 'Unable to list certificates.', 'porkpress-ssl' ) : 'Unable to list certificates.'
            );
            return array();
        }

        $output = trim( $result['output'] );
        if ( '' === $output ) {
            Logger::warn(
                'certbot_list',
                array(
                    'cmd'     => $cmd,
                    'user_id' => $user,
                ),
                'empty_output'
            );
            Notifier::notify(
                'warning',
                function_exists( '__' ) ? __( 'No certificates returned', 'porkpress-ssl' ) : 'No certificates returned',
                function_exists( '__' ) ? __( 'Certbot produced no output.', 'porkpress-ssl' ) : 'Certbot produced no output.'
            );
            return array();
        }

        try {
            $certs = self::parse_certificates_output( $output );
            Logger::info(
                'certbot_list',
                array(
                    'cmd'     => $cmd,
                    'count'   => count( $certs ),
                    'user_id' => $user,
                ),
                'success'
            );
            return $certs;
        } catch ( \Throwable $e ) {
            Logger::warn(
                'certbot_list',
                array(
                    'cmd'     => $cmd,
                    'output'  => $output,
                    'user_id' => $user,
                ),
                'parse_failed'
            );
            Notifier::notify(
                'warning',
                function_exists( '__' ) ? __( 'Certificate parsing failed', 'porkpress-ssl' ) : 'Certificate parsing failed',
                function_exists( '__' ) ? __( 'Could not parse certificate list.', 'porkpress-ssl' ) : 'Could not parse certificate list.'
            );
            return array();
        }
    }

    /**
     * Parse `certbot certificates` output.
     *
     * @param string $output Raw output from certbot.
     * @return array<string, array{domains: array<int, string>, expiry: string, status: string}>
     */
    public static function parse_certificates_output( string $output ): array {
        $certs   = array();
        $current = null;
        foreach ( preg_split( '/\r?\n/', $output ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^Certificate Name:\s*(\S+)/', $line, $m ) ) {
                $current             = $m[1];
                $certs[ $current ] = array(
                    'domains' => array(),
                    'expiry'  => '',
                    'status'  => '',
                );
                continue;
            }
            if ( $current && preg_match( '/^Domains:\s*(.+)$/', $line, $m ) ) {
                $domains                       = preg_split( '/\s+/', trim( $m[1] ) );
                $certs[ $current ]['domains'] = is_array( $domains ) ? $domains : array();
                continue;
            }
            if ( $current && preg_match( '/^Expiry Date:\s*(.+)$/', $line, $m ) ) {
                // Strip anything after "(VALID" which certbot appends.
                $expiry = trim( preg_split( '/\s+\(VALID/i', $m[1] )[0] );
                $certs[ $current ]['expiry'] = $expiry;

                $ts   = strtotime( $expiry );
                $now  = time();
                $days = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
                if ( false !== $ts ) {
                    if ( $ts < $now ) {
                        $status = 'Expired';
                    } elseif ( $ts <= $now + 30 * $days ) {
                        $status = 'Expiring Soon';
                    } else {
                        $status = 'Valid';
                    }
                    $certs[ $current ]['status'] = $status;
                }
            }
        }

        return $certs;
    }
}
