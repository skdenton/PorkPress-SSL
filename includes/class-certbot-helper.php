<?php
/**
 * Helper utilities for building certbot commands.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

/**
 * Utility class for constructing certbot command strings.
 */
class Certbot_Helper {
    /**
     * Build the certbot command.
     *
     * @param array  $domains  Domains to include.
     * @param string $cert_name Certificate lineage name.
     * @param bool   $staging  Whether to use staging.
     * @param bool   $renewal  Force renewal.
     * @return string
     */
    public static function build_command( array $domains, string $cert_name, bool $staging, bool $renewal = false ): string {
        $cert_root  = defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt';
        $state_root = defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl';
        $auth_hook    = dirname( __DIR__ ) . '/bin/porkbun-add-txt.sh';
        $cleanup_hook = dirname( __DIR__ ) . '/bin/porkbun-del-txt.sh';
        $cmd = 'certbot certonly --manual --non-interactive --agree-tos --manual-public-ip-logging-ok --preferred-challenges dns';
        $cmd .= ' --manual-auth-hook ' . escapeshellarg( $auth_hook );
        $cmd .= ' --manual-cleanup-hook ' . escapeshellarg( $cleanup_hook );
        $cmd .= ' --cert-name ' . escapeshellarg( $cert_name );
        $cmd .= ' --config-dir ' . escapeshellarg( $cert_root );
        $cmd .= ' --work-dir ' . escapeshellarg( $state_root );
        $cmd .= ' --logs-dir ' . escapeshellarg( $state_root );
        if ( $renewal ) {
            $cmd .= ' --force-renewal';
        }
        if ( $staging ) {
            $cmd .= ' --test-cert';
        }
        foreach ( $domains as $domain ) {
            $cmd .= ' -d ' . escapeshellarg( $domain );
        }
        return $cmd;
    }
}
