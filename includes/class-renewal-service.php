<?php
/**
 * Certificate renewal scheduler.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

require_once __DIR__ . '/class-runner.php';

defined( 'ABSPATH' ) || exit;

/**
 * Handles scheduling and execution of certificate renewals.
 */
class Renewal_Service {
/** Cron hook name. */
public const CRON_HOOK = 'porkpress_ssl_run_renewal';

/** Option key for tracking attempts. */
private const OPTION_ATTEMPTS = 'porkpress_ssl_renew_attempts';

/** Option key for pending expiry notification. */
private const OPTION_EXPIRY_NOTIFIED = 'porkpress_ssl_expiry_notified';

/** Maximum number of retries. */
private const MAX_RETRIES = 5;

/** Base delay in seconds for backoff. */
private const BASE_DELAY = 3600; // 1 hour.

/**
 * Command runner callback.
 *
 * @var callable|null
 */
public static $runner = null;

    /** Last reload command result. */
    public static $last_reload = array( 'code' => 0, 'output' => '' );

/**
 * Maybe schedule the renewal cron event based on certificate expiry.
 *
 * @param bool $force Reschedule even if already scheduled.
 */
public static function maybe_schedule( bool $force = false ): void {
$manifest = self::get_manifest();
if ( ! $manifest || empty( $manifest['expires_at'] ) ) {
return;
}

$renew_window = absint( get_site_option( 'porkpress_ssl_renew_window', 30 ) );
$timestamp    = strtotime( $manifest['expires_at'] ) - $renew_window * DAY_IN_SECONDS;
        if ( $timestamp <= time() ) {
        $timestamp = time();
        }

        $existing = wp_next_scheduled( self::CRON_HOOK );
        if ( $force || ! $existing || $existing !== $timestamp ) {
        if ( $existing ) {
        wp_unschedule_event( $existing, self::CRON_HOOK );
        }
        wp_schedule_single_event( $timestamp, self::CRON_HOOK );
        update_site_option( self::OPTION_ATTEMPTS, 0 );
        }

        $expires_at_ts = strtotime( $manifest['expires_at'] );
        if ( $expires_at_ts - time() <= $renew_window * DAY_IN_SECONDS ) {
        if ( get_site_option( self::OPTION_EXPIRY_NOTIFIED ) !== $manifest['expires_at'] ) {
        \PorkPress\SSL\Notifier::notify(
        'warning',
        __( 'SSL certificate expiring soon', 'porkpress-ssl' ),
        sprintf( __( 'Certificate will expire on %s.', 'porkpress-ssl' ), gmdate( 'Y-m-d', $expires_at_ts ) )
        );
        update_site_option( self::OPTION_EXPIRY_NOTIFIED, $manifest['expires_at'] );
        }
        }
        }

/**
 * Execute the renewal process.
 */
public static function run(): void {
$manifest = self::get_manifest();
if ( ! $manifest || empty( $manifest['domains'] ) ) {
return;
}

$attempt   = absint( get_site_option( self::OPTION_ATTEMPTS, 0 ) ) + 1;
$cert_name = $manifest['cert_name'] ?? get_site_option(
'porkpress_ssl_cert_name',
defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
);
$staging   = (bool) get_site_option( 'porkpress_ssl_le_staging', 0 );

$cmd    = self::build_certbot_command( $manifest['domains'], $cert_name, $staging, true );
$result = self::execute( $cmd, 'certbot' );
        if ( 0 !== $result['code'] ) {
        Logger::error( 'renew_certificate', array( 'attempt' => $attempt, 'output' => $result['output'] ), 'certbot failed' );
        update_site_option( self::OPTION_ATTEMPTS, $attempt );
        \PorkPress\SSL\Notifier::notify( 'error', __( 'SSL renewal failed', 'porkpress-ssl' ), __( 'Certbot failed during renewal.', 'porkpress-ssl' ) );
        if ( $attempt <= self::MAX_RETRIES ) {
        $delay = self::calculate_backoff( $attempt );
        wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
        }
        return;
        }

        $manifest_ok = self::write_manifest( $manifest['domains'], $cert_name );
        $deploy_ok   = self::deploy_to_apache( $cert_name );
        if ( ! $manifest_ok || ! $deploy_ok ) {
            Logger::error( 'renew_certificate', array( 'attempt' => $attempt ), 'post-deploy failed' );
            return;
        }

        Logger::info( 'renew_certificate', array( 'attempt' => $attempt ), 'success' );
        update_site_option( self::OPTION_ATTEMPTS, 0 );
        update_site_option( self::OPTION_EXPIRY_NOTIFIED, $manifest['expires_at'] ?? '' );
        \PorkPress\SSL\Notifier::notify( 'success', __( 'SSL certificate renewed', 'porkpress-ssl' ), __( 'Certificate renewal completed successfully.', 'porkpress-ssl' ) );
        self::maybe_schedule( true );
        }

/**
 * Execute a shell command.
 *
 * @param string $cmd Command to run.
 * @return array{code:int,output:string}
 */
protected static function execute( string $cmd, string $context = '' ): array {
    if ( is_callable( self::$runner ) ) {
        return call_user_func( self::$runner, $cmd, $context );
    }
    return Runner::run( $cmd, $context );
}

    /**
     * Retrieve the configured Apache reload command or detect one.
     */
    public static function get_apache_reload_cmd(): string {
        $cmd = get_site_option( 'porkpress_ssl_apache_reload_cmd', '' );
        if ( '' === $cmd || 'apachectl -k reload' === $cmd ) {
            $detected = self::detect_apache_reload_cmd();
            if ( $detected ) {
                $cmd = $detected;
                update_site_option( 'porkpress_ssl_apache_reload_cmd', $cmd );
            }
        }
        return $cmd;
    }

    /**
     * Detect the best available Apache reload command.
     */
    protected static function detect_apache_reload_cmd(): string {
        if ( file_exists( '/etc/debian_version' ) && self::command_exists( 'systemctl' ) ) {
            return 'systemctl reload apache2';
        }
        if ( self::command_exists( 'apache2ctl' ) ) {
            return 'apache2ctl -k graceful';
        }
        if ( self::command_exists( 'service' ) ) {
            return 'service apache2 reload';
        }
        if ( self::command_exists( 'apachectl' ) ) {
            return 'apachectl -k graceful';
        }
        return '';
    }

    /**
     * Check if a command exists in PATH.
     */
    protected static function command_exists( string $cmd ): bool {
        return Runner::command_exists( $cmd );
    }

/**
 * Build the certbot command.
 *
 * @param array  $domains  Domains to include.
 * @param string $cert_name Certificate lineage name.
 * @param bool   $staging  Whether to use staging.
 * @param bool   $renewal  Force renewal.
 * @return string
 */
public static function build_certbot_command( array $domains, string $cert_name, bool $staging, bool $renewal = false ): string {
if ( function_exists( '\\get_site_option' ) && \get_site_option( 'porkpress_ssl_network_wildcard', 0 ) && defined( 'DOMAIN_CURRENT_SITE' ) ) {
    $base     = DOMAIN_CURRENT_SITE;
    $suffix   = '.' . $base;
    $wildcard = '*.' . $base;
    $domains  = array_filter(
        $domains,
        static function ( $d ) use ( $suffix ) {
            return substr( $d, -strlen( $suffix ) ) !== $suffix;
        }
    );
    $domains[] = $base;
    $domains[] = $wildcard;
    $domains   = array_values( array_unique( $domains ) );
}
return Certbot_Helper::build_command( $domains, $cert_name, $staging, $renewal );
}

/**
 * Write updated manifest based on certificate on disk.
 *
 * @param array  $domains   Domains included.
 * @param string $cert_name Certificate name.
 */
    public static function write_manifest( array $domains, string $cert_name ): bool {
$cert_root  = get_site_option(
'porkpress_ssl_cert_root',
defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt'
);
$state_root = get_site_option(
'porkpress_ssl_state_root',
defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl'
);
$live_dir = rtrim( $cert_root, '/\\' ) . '/live/' . $cert_name;
$paths    = array(
'fullchain' => $live_dir . '/fullchain.pem',
'privkey'   => $live_dir . '/privkey.pem',
'chain'     => $live_dir . '/chain.pem',
'cert'      => $live_dir . '/cert.pem',
);
$issued_at = $expires_at = null;
if ( file_exists( $paths['cert'] ) ) {
$cert_data = openssl_x509_parse( file_get_contents( $paths['cert'] ) );
if ( $cert_data ) {
$issued_at  = gmdate( 'c', $cert_data['validFrom_time_t'] );
$expires_at = gmdate( 'c', $cert_data['validTo_time_t'] );
}
}
$manifest = array(
'cert_name'  => $cert_name,
'domains'    => array_values( $domains ),
'issued_at'  => $issued_at,
'expires_at' => $expires_at,
'paths'      => $paths,
);
    if ( ! is_dir( $state_root ) ) {
        wp_mkdir_p( $state_root );
    }
    $path  = rtrim( $state_root, '/\\' ) . '/manifest.json';
    $bytes = @file_put_contents( $path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    if ( false === $bytes ) {
        $err = error_get_last();
        Logger::error( 'write_manifest', array( 'path' => $path, 'error' => $err['message'] ?? '' ), 'write failed' );
        \PorkPress\SSL\Notifier::notify(
            'error',
            __( 'SSL manifest write failed', 'porkpress-ssl' ),
            sprintf( __( 'Could not write manifest to %s: %s', 'porkpress-ssl' ), $path, $err['message'] ?? '' )
        );
        return false;
    }
    return true;
    }

    /**
     * Copy or symlink certificate files into Apache vhost directories and reload Apache.
     *
     * @param string $cert_name Certificate lineage name.
     */
    public static function deploy_to_apache( string $cert_name ): bool {
        $enabled = (bool) get_site_option( 'porkpress_ssl_apache_reload', 1 );
        if ( ! $enabled ) {
            return true;
        }

        $cert_root = get_site_option(
            'porkpress_ssl_cert_root',
            defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt'
        );
        $live_dir   = rtrim( $cert_root, '/\\' ) . '/live/' . $cert_name;
        $fullchain  = $live_dir . '/fullchain.pem';
        $privkey    = $live_dir . '/privkey.pem';
        $directives = "SSLCertificateFile {$fullchain}\nSSLCertificateKeyFile {$privkey}";

        $snippets      = array();
        $enabled_paths = array();
        $ok            = true;

        $links = glob( '/etc/apache2/sites-enabled/*.conf' ) ?: array();
        foreach ( $links as $link ) {
            $target = realpath( $link );
            if ( false === $target ) {
                continue;
            }
            $enabled_paths[ $target ] = true;

            $contents = @file_get_contents( $target );
            if ( false === $contents ) {
                $snippets[ $target ] = array(
                    'enabled' => true,
                    'reason'  => 'unreadable',
                    'snippet' => $directives,
                );
                $ok = false;
                continue;
            }

            $modified = false;
            $patterns = array(
                'SSLCertificateFile'     => $fullchain,
                'SSLCertificateKeyFile'  => $privkey,
            );

            foreach ( $patterns as $directive => $desired ) {
                $regex = '/^\s*' . $directive . '\s+(\S+)/mi';
                if ( preg_match( $regex, $contents, $m ) ) {
                    if ( $m[1] !== $desired ) {
                        $contents = preg_replace( $regex, $directive . ' ' . $desired, $contents, 1 );
                        $modified = true;
                    }
                } else {
                    $contents .= "\n{$directive} {$desired}\n";
                    $modified = true;
                }
            }

            if ( $modified ) {
                if ( is_writable( $target ) && false !== @file_put_contents( $target, $contents ) ) {
                    // success
                } else {
                    $snippets[ $target ] = array(
                        'enabled' => true,
                        'reason'  => 'unwritable',
                        'snippet' => $directives,
                    );
                    Logger::warn( 'apache_deploy', array( 'vhost' => $target ), 'unwritable' );
                    $ok = false;
                }
            }
        }

        // Detect disabled vhosts.
        $available = glob( '/etc/apache2/sites-available/*.conf' ) ?: array();
        foreach ( $available as $conf ) {
            if ( isset( $enabled_paths[ $conf ] ) ) {
                continue;
            }
            $snippets[ $conf ] = array(
                'enabled' => false,
                'reason'  => 'disabled',
                'snippet' => $directives,
            );
            Logger::warn( 'apache_deploy', array( 'vhost' => $conf ), 'disabled' );
            $ok = false;
        }

        update_site_option( 'porkpress_ssl_apache_snippets', $snippets );

        $cmd = self::get_apache_reload_cmd();
        if ( '' === $cmd ) {
            self::$last_reload = array( 'code' => 127, 'output' => '' );
            Logger::error( 'apache_reload', array( 'cmd' => '' ), 'not_found' );
            $ok = false;
        } else {
            $result = self::execute( $cmd, 'apache' );
            self::$last_reload = $result;
            if ( 0 !== $result['code'] ) {
                $context = array(
                    'cmd'    => $cmd,
                    'code'   => $result['code'],
                    'output' => $result['output'],
                );
                if ( preg_match( '/permission/i', $result['output'] ) ) {
                    Logger::error( 'apache_reload', $context, 'permission denied' );
                } else {
                    Logger::error( 'apache_reload', $context, 'failed' );
                }
                $ok = false;
            } else {
                Logger::info( 'apache_reload', array( 'cmd' => $cmd ), 'success' );
            }
        }

        if ( ! $ok ) {
            $msg = '';
            if ( self::$last_reload['output'] ) {
                $clean = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( self::$last_reload['output'] ) : trim( strip_tags( self::$last_reload['output'] ) );
                $msg   = sprintf( __( 'Apache reload failed: %s', 'porkpress-ssl' ), $clean );
            } else {
                $msg = __( 'Apache reload or file copy failed during certificate deployment.', 'porkpress-ssl' );
            }
            \PorkPress\SSL\Notifier::notify( 'error', __( 'SSL deploy failed', 'porkpress-ssl' ), $msg );
        }

        return $ok;
    }

/**
 * Retrieve manifest data.
 */
protected static function get_manifest(): ?array {
$state_root    = get_site_option(
'porkpress_ssl_state_root',
defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl'
);
$manifest_path = rtrim( $state_root, '/\\' ) . '/manifest.json';
if ( ! file_exists( $manifest_path ) ) {
$cert_name = get_site_option(
'porkpress_ssl_cert_name',
defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
);
$cert_root = get_site_option(
'porkpress_ssl_cert_root',
defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt'
);
$live_dir  = rtrim( $cert_root, '/\\' ) . '/live/' . $cert_name;
$cert_file = $live_dir . '/cert.pem';
if ( file_exists( $cert_file ) ) {
$cert_data = openssl_x509_parse( file_get_contents( $cert_file ) );
if ( $cert_data ) {
$issued_at  = gmdate( 'c', $cert_data['validFrom_time_t'] );
$expires_at = gmdate( 'c', $cert_data['validTo_time_t'] );
$domains    = array();
if ( ! empty( $cert_data['extensions']['subjectAltName'] ) ) {
$sans = explode( ',', $cert_data['extensions']['subjectAltName'] );
foreach ( $sans as $san ) {
$san = trim( $san );
if ( 0 === strpos( $san, 'DNS:' ) ) {
$domains[] = substr( $san, 4 );
}
}
} elseif ( ! empty( $cert_data['subject']['CN'] ) ) {
$domains[] = $cert_data['subject']['CN'];
}
$paths = array(
'fullchain' => $live_dir . '/fullchain.pem',
'privkey'   => $live_dir . '/privkey.pem',
'chain'     => $live_dir . '/chain.pem',
'cert'      => $cert_file,
);
if ( ! is_dir( $state_root ) ) {
if ( function_exists( 'wp_mkdir_p' ) ) {
        $created = wp_mkdir_p( $state_root );
    } else {
        $mode    = function_exists( 'apply_filters' )
            ? (int) apply_filters( 'porkpress_ssl_state_dir_mode', 0755 )
            : 0755;
        $created = @mkdir( $state_root, $mode, true );
    }
    if ( ! $created ) {
        $err = error_get_last();
        Logger::error( 'mkdir_state_root', array( 'path' => $state_root, 'error' => $err['message'] ?? '' ), 'mkdir failed' );
        \PorkPress\SSL\Notifier::notify(
            'error',
            __( 'SSL state directory creation failed', 'porkpress-ssl' ),
            sprintf( __( 'Could not create directory %s: %s', 'porkpress-ssl' ), $state_root, $err['message'] ?? '' )
        );
        return null;
    }
}
$manifest = array(
'cert_name'  => $cert_name,
'domains'    => array_values( array_unique( $domains ) ),
'issued_at'  => $issued_at,
'expires_at' => $expires_at,
'paths'      => $paths,
);
        $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
        $bytes  = @file_put_contents( $manifest_path, $encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        if ( false === $bytes ) {
            $err = error_get_last();
            Logger::error( 'write_manifest', array( 'path' => $manifest_path, 'error' => $err['message'] ?? '' ), 'write failed' );
            \PorkPress\SSL\Notifier::notify(
                'error',
                __( 'SSL manifest write failed', 'porkpress-ssl' ),
                sprintf( __( 'Could not write manifest to %s: %s', 'porkpress-ssl' ), $manifest_path, $err['message'] ?? '' )
            );
            return null;
        }
        return $manifest;
}
}
return null;
}
$manifest = json_decode( file_get_contents( $manifest_path ), true );
return is_array( $manifest ) ? $manifest : null;
}

/**
 * Calculate exponential backoff.
 *
 * @param int $attempt Attempt number starting from 1.
 * @return int Seconds to delay.
 */
protected static function calculate_backoff( int $attempt ): int {
return (int) ( self::BASE_DELAY * pow( 2, $attempt - 1 ) );
}
}
