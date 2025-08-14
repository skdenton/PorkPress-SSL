#!/usr/bin/env php
<?php
/**
 * Certbot hook for managing Porkbun DNS TXT records.
 */

use PorkPress\SSL\Logger;
use PorkPress\SSL\Porkbun_Client;
use PorkPress\SSL\Porkbun_Client_Error;
use PorkPress\SSL\TXT_Propagation_Waiter;
use PorkPress\SSL\Renewal_Service;

// Parse CLI options (--wp-root and --config).
$opts = [];
$args = [];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (0 === strpos($arg, '--')) {
        $eq = strpos($arg, '=');
        $key = $eq ? substr($arg, 2, $eq - 2) : substr($arg, 2);
        $val = $eq ? substr($arg, $eq + 1) : ($argv[++$i] ?? '');
        $opts[$key] = $val;
    } else {
        $args[] = $arg;
    }
}

// Load environment overrides from config file.
$config_path = $opts['config'] ?? getenv('PORKPRESS_SSL_CONFIG') ?? '/etc/default/porkpress-ssl';
if (is_string($config_path) && file_exists($config_path)) {
    foreach (file($config_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*#/', $line)) {
            continue;
        }
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m)) {
            $key = $m[1];
            $val = trim($m[2], "'\"");
            if ('' === getenv($key)) {
                putenv($key . '=' . $val);
            }
        }
    }
}

if (!empty($opts['wp-root'])) {
    putenv('WP_ROOT=' . $opts['wp-root']);
}

// Attempt to locate and load WordPress.
$possible = [];
if (getenv('WP_ROOT')) {
    $possible[] = rtrim(getenv('WP_ROOT'), '/\\') . '/wp-load.php';
}
if (getenv('WP_LOAD_PATH')) {
    $possible[] = rtrim(getenv('WP_LOAD_PATH'), '/\\') . '/wp-load.php';
}
$dir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    $possible[] = $dir . '/wp-load.php';
    $dir = dirname($dir);
}
$wp_loaded = false;
foreach ($possible as $path) {
    if ($path && file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}
if ( ! $wp_loaded ) {
    fwrite(STDERR, "Unable to locate wp-load.php. Set WP_ROOT or WP_LOAD_PATH.\n");
    exit(1);
}

// Ensure plugin classes are loaded.
if ( ! class_exists(Porkbun_Client::class) ) {
    // The plugin should be active, but bail if not.
    fwrite(STDERR, "PorkPress SSL plugin not loaded.\n");
    exit(1);
}

$action = strtolower($args[0] ?? '');
if ( ! in_array($action, ['add', 'auth', 'del', 'cleanup', 'deploy', 'renew'], true) ) {
    fwrite(STDERR, "Usage: porkpress-hook.php [--wp-root=<path>] <add|del|deploy|renew>\n");
    exit(1);
}

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');
$token = getenv('CERTBOT_TOKEN');
if (('add' === $action || 'auth' === $action) && ( !$domain || !$validation )) {
    fwrite(STDERR, "CERTBOT_DOMAIN or CERTBOT_VALIDATION missing.\n");
    exit(1);
}

// Determine base zone and record name.
$parts = explode('.', $domain);
if ( count($parts) < 2 ) {
    fwrite(STDERR, "Invalid domain: {$domain}\n");
    exit(1);
}
$zone = implode('.', array_slice($parts, -2));
$sub = implode('.', array_slice($parts, 0, -2));
$record_name = '_acme-challenge' . ($sub ? '.' . $sub : '');

// Fetch API credentials.
$api_key = getenv('PORKBUN_API_KEY');
$api_secret = getenv('PORKBUN_API_SECRET');
if ( ! $api_key || ! $api_secret ) {
    $api_key = defined('PORKPRESS_API_KEY') ? PORKPRESS_API_KEY : get_site_option('porkpress_ssl_api_key', '');
    $api_secret = defined('PORKPRESS_API_SECRET') ? PORKPRESS_API_SECRET : get_site_option('porkpress_ssl_api_secret', '');
}
if ( empty($api_key) || empty($api_secret) ) {
    Logger::error('certbot_hook', ['action' => $action, 'domain' => $domain], 'missing_api_credentials');
    fwrite(STDERR, "Missing API credentials.\n");
    exit(1);
}

$client = new Porkbun_Client($api_key, $api_secret);

if ( 'add' === $action || 'auth' === $action ) {
    $result = $client->create_txt_record($zone, $record_name, $validation, 600);
    if ( $result instanceof Porkbun_Client_Error ) {
        Logger::error('certbot_hook', ['action' => 'add', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'token' => $token], $result->message);
        fwrite(STDERR, "{$result->message}\n");
        exit(1);
    }
    $timeout   = max( 1, (int) get_site_option( 'porkpress_ssl_txt_timeout', 600 ) );
    $interval  = max( 1, (int) get_site_option( 'porkpress_ssl_txt_interval', 30 ) );
    $ns_result = $client->get_ns( $zone );
    $nameservers = array();
    if ( is_array( $ns_result ) && isset( $ns_result['ns'] ) && is_array( $ns_result['ns'] ) ) {
        $nameservers = $ns_result['ns'];
    }
    $waiter = new TXT_Propagation_Waiter( $nameservers );
    if ( ! $waiter->wait( $domain, $validation, $timeout, $interval ) ) {
        Logger::error('certbot_hook', ['action' => 'wait', 'domain' => $domain, 'zone' => $zone, 'servers' => $nameservers], 'timeout');
        fwrite(STDERR, "TXT record propagation timed out\n");
        exit(1);
    }
    Logger::info('certbot_hook', ['action' => 'add', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'token' => $token], 'success');
    exit(0);
}

if ( 'deploy' === $action || 'renew' === $action ) {
    $cert_name = getenv('CERTBOT_CERT_NAME') ?: get_site_option(
        'porkpress_ssl_cert_name',
        defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
    );
    $domains   = preg_split('/\s+/', trim(getenv('RENEWED_DOMAINS') ?: '')) ?: array();

    $ok_manifest = Renewal_Service::write_manifest( $domains, $cert_name );
    $ok_deploy   = Renewal_Service::deploy_to_apache( $cert_name );

    if ( $ok_manifest && $ok_deploy ) {
        Logger::info( 'certbot_hook', [
            'action'  => $action,
            'renewed' => getenv('RENEWED_DOMAINS') ?: '',
            'failed'  => getenv('FAILED_DOMAINS') ?: '',
        ], 'deploy success' );
        exit(0);
    }

    Logger::error( 'certbot_hook', [
        'action'  => $action,
        'renewed' => getenv('RENEWED_DOMAINS') ?: '',
        'failed'  => getenv('FAILED_DOMAINS') ?: '',
    ], 'deploy failed' );
    exit(1);
}

// Deletion path.
$records = $client->get_records($zone);
if ( $records instanceof Porkbun_Client_Error ) {
    Logger::error('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'token' => $token], $records->message);
    fwrite(STDERR, "{$records->message}\n");
    exit(1);
}
$deleted = false;
foreach ( $records['records'] ?? [] as $rec ) {
    if ( 'TXT' === ($rec['type'] ?? '') && $rec['name'] === $record_name && ($validation ? $rec['content'] === $validation : true) ) {
        $del = $client->delete_record($zone, (int) $rec['id']);
        if ( $del instanceof Porkbun_Client_Error ) {
            Logger::error('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'id' => $rec['id'], 'token' => $token], $del->message);
            fwrite(STDERR, "{$del->message}\n");
            exit(1);
        }
        $deleted = true;
    }
}
if ( $deleted ) {
    Logger::info('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'token' => $token], 'success');
} else {
    Logger::warn('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'token' => $token], 'record_not_found');
}

exit(0);
