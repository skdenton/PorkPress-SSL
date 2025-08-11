#!/usr/bin/env php
<?php
/**
 * Certbot hook for managing Porkbun DNS TXT records.
 */

use PorkPress\SSL\Logger;
use PorkPress\SSL\Porkbun_Client;
use PorkPress\SSL\Porkbun_Client_Error;

// Attempt to locate and load WordPress.
$possible = [];
if ( getenv('WP_LOAD_PATH') ) {
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
    fwrite(STDERR, "Unable to locate wp-load.php. Set WP_LOAD_PATH.\n");
    exit(1);
}

// Ensure plugin classes are loaded.
if ( ! class_exists(Porkbun_Client::class) ) {
    // The plugin should be active, but bail if not.
    fwrite(STDERR, "PorkPress SSL plugin not loaded.\n");
    exit(1);
}

$action = $argv[1] ?? $args[0] ?? '';
$action = strtolower($action);
if ( ! in_array($action, ['add', 'auth', 'del', 'cleanup'], true) ) {
    fwrite(STDERR, "Usage: porkbun-hook.php <add|del>\n");
    exit(1);
}

$domain = getenv('CERTBOT_DOMAIN');
$validation = getenv('CERTBOT_VALIDATION');
if ( ! $domain || ( 'add' === $action || 'auth' === $action ) && ! $validation ) {
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
    $result = $client->createTxtRecord($zone, $record_name, $validation, 600);
    if ( $result instanceof Porkbun_Client_Error ) {
        Logger::error('certbot_hook', ['action' => 'add', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name], $result->message);
        fwrite(STDERR, "{$result->message}\n");
        exit(1);
    }
    Logger::info('certbot_hook', ['action' => 'add', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name], 'success');
    exit(0);
}

// Deletion path.
$records = $client->getRecords($zone);
if ( $records instanceof Porkbun_Client_Error ) {
    Logger::error('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name], $records->message);
    fwrite(STDERR, "{$records->message}\n");
    exit(1);
}
$deleted = false;
foreach ( $records['records'] ?? [] as $rec ) {
    if ( 'TXT' === ($rec['type'] ?? '') && $rec['name'] === $record_name && ($validation ? $rec['content'] === $validation : true) ) {
        $del = $client->deleteRecord($zone, (int) $rec['id']);
        if ( $del instanceof Porkbun_Client_Error ) {
            Logger::error('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name, 'id' => $rec['id']], $del->message);
            fwrite(STDERR, "{$del->message}\n");
            exit(1);
        }
        $deleted = true;
    }
}
if ( $deleted ) {
    Logger::info('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name], 'success');
} else {
    Logger::warn('certbot_hook', ['action' => 'del', 'domain' => $domain, 'zone' => $zone, 'name' => $record_name], 'record_not_found');
}

exit(0);
