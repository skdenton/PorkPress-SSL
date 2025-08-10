<?php
/**
 * Logging utility.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

/**
 * Get the logs table name.
 *
 * @return string
 */
public static function get_table_name() {
global $wpdb;
return $wpdb->base_prefix . 'porkpress_logs';
}

/**
 * Create the logs table if it does not exist.
 */
public static function create_table() {
global $wpdb;
$table_name      = self::get_table_name();
$charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
time datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL,
action varchar(191) NOT NULL,
context longtext NULL,
result varchar(191) NOT NULL,
severity varchar(20) NOT NULL,
PRIMARY KEY  (id),
KEY time (time),
KEY severity (severity)
) {$charset_collate};";

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );
}

/**
 * Insert a log entry.
 *
 * @param string $action   Action name.
 * @param array  $context  Context data.
 * @param string $result   Result message.
 * @param string $severity Log severity.
 */
public static function log( $action, $context = array(), $result = '', $severity = 'info' ) {
global $wpdb;
$wpdb->insert(
self::get_table_name(),
array(
'time'     => current_time( 'mysql' ),
'user_id'  => get_current_user_id(),
'action'   => $action,
'context'  => wp_json_encode( $context ),
'result'   => $result,
'severity' => $severity,
)
);
}

/**
 * Convenience info logger.
 *
 * @param string $action  Action name.
 * @param array  $context Context data.
 * @param string $result  Result message.
 */
public static function info( $action, $context = array(), $result = '' ) {
self::log( $action, $context, $result, 'info' );
}

/**
 * Convenience warning logger.
 *
 * @param string $action  Action name.
 * @param array  $context Context data.
 * @param string $result  Result message.
 */
public static function warn( $action, $context = array(), $result = '' ) {
self::log( $action, $context, $result, 'warn' );
}

/**
 * Convenience error logger.
 *
 * @param string $action  Action name.
 * @param array  $context Context data.
 * @param string $result  Result message.
 */
public static function error( $action, $context = array(), $result = '' ) {
self::log( $action, $context, $result, 'error' );
}

/**
 * Retrieve log entries.
 *
 * @param array $args Query arguments.
 *
 * @return array
 */
public static function get_logs( $args = array() ) {
global $wpdb;
$defaults = array(
'severity' => '',
'limit'    => 100,
);
$args   = wp_parse_args( $args, $defaults );
$where  = '1=1';
$params = array();
if ( ! empty( $args['severity'] ) ) {
$where    .= ' AND severity = %s';
$params[] = $args['severity'];
}

$limit_clause = $args['limit'] > 0 ? $wpdb->prepare( ' LIMIT %d', $args['limit'] ) : '';
$sql          = 'SELECT * FROM ' . self::get_table_name() . " WHERE {$where} ORDER BY time DESC{$limit_clause}";
if ( $params ) {
$sql = $wpdb->prepare( $sql, $params );
}

return $wpdb->get_results( $sql, ARRAY_A );
}

/**
 * Redact sensitive fields from a context JSON string.
 *
 * @param string $context_json JSON-encoded context.
 * @param bool   $encode       Whether to return JSON string. If false, returns array.
 *
 * @return string|array
 */
public static function sanitize_context( string $context_json, bool $encode = true ) {
$data = json_decode( $context_json, true );
if ( ! is_array( $data ) ) {
return $encode ? $context_json : $data;
}

$secrets = array( 'api_key', 'api_secret', 'key', 'secret', 'password' );
foreach ( $secrets as $secret ) {
if ( array_key_exists( $secret, $data ) ) {
unset( $data[ $secret ] );
}
}

if ( ! $encode ) {
return $data;
}

$encode_fn = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
return $encode_fn( $data );
}
}
