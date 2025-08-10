<?php
/**
 * Certificate renewal scheduler.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Handles scheduling and execution of certificate renewals.
 */
class Renewal_Service {
/** Cron hook name. */
public const CRON_HOOK = 'porkpress_ssl_run_renewal';

/** Option key for tracking attempts. */
private const OPTION_ATTEMPTS = 'porkpress_ssl_renew_attempts';

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
$cert_name = $manifest['cert_name'] ?? 'porkpress-network';
$staging   = (bool) get_site_option( 'porkpress_ssl_le_staging', 0 );

$cmd    = self::build_certbot_command( $manifest['domains'], $cert_name, $staging, true );
$result = self::execute( $cmd );
if ( 0 !== $result['code'] ) {
Logger::error( 'renew_certificate', array( 'attempt' => $attempt, 'output' => $result['output'] ), 'certbot failed' );
update_site_option( self::OPTION_ATTEMPTS, $attempt );
if ( $attempt <= self::MAX_RETRIES ) {
$delay = self::calculate_backoff( $attempt );
wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
}
return;
}

self::write_manifest( $manifest['domains'], $cert_name );
Logger::info( 'renew_certificate', array( 'attempt' => $attempt ), 'success' );
update_site_option( self::OPTION_ATTEMPTS, 0 );
self::maybe_schedule( true );
}

/**
 * Execute a shell command.
 *
 * @param string $cmd Command to run.
 * @return array{code:int,output:string}
 */
protected static function execute( string $cmd ): array {
if ( is_callable( self::$runner ) ) {
return call_user_func( self::$runner, $cmd );
}
$output = array();
$code   = 0;
exec( $cmd . ' 2>&1', $output, $code );
return array(
'code'   => $code,
'output' => implode( "\n", $output ),
);
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

/**
 * Write updated manifest based on certificate on disk.
 *
 * @param array  $domains   Domains included.
 * @param string $cert_name Certificate name.
 */
protected static function write_manifest( array $domains, string $cert_name ): void {
$cert_root  = defined( 'PORKPRESS_CERT_ROOT' ) ? PORKPRESS_CERT_ROOT : '/etc/letsencrypt';
$state_root = defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl';
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
file_put_contents( rtrim( $state_root, '/\\' ) . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

/**
 * Retrieve manifest data.
 */
protected static function get_manifest(): ?array {
$state_root    = defined( 'PORKPRESS_STATE_ROOT' ) ? PORKPRESS_STATE_ROOT : '/var/lib/porkpress-ssl';
$manifest_path = rtrim( $state_root, '/\\' ) . '/manifest.json';
if ( ! file_exists( $manifest_path ) ) {
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
