<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        return '2023-01-01 00:00:00';
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 1;
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

class LoggerMockWpdb {
    public $data = array();
    public $base_prefix = 'wp_';

    public function insert( $table, $data, $format = null ) {
        $this->data[ $table ][] = $data;
        return 1;
    }
}

require_once __DIR__ . '/../includes/class-logger.php';

class LoggerTest extends TestCase {
    public function test_sanitize_context_removes_secrets() {
        $ctx = json_encode( array( 'foo' => 'bar', 'api_key' => 'secret', 'password' => 'p' ) );
        $sanitized = \PorkPress\SSL\Logger::sanitize_context( $ctx );
        $data = json_decode( $sanitized, true );
        $this->assertSame( 'bar', $data['foo'] );
        $this->assertArrayNotHasKey( 'api_key', $data );
        $this->assertArrayNotHasKey( 'password', $data );
    }

    public function test_log_redacts_secrets() {
        global $wpdb;
        $wpdb = new LoggerMockWpdb();

        \PorkPress\SSL\Logger::log( 'action', array( 'foo' => 'bar', 'api_key' => 'secret', 'password' => 'p' ) );

        $table = \PorkPress\SSL\Logger::get_table_name();
        $row   = $wpdb->data[ $table ][0];
        $ctx   = json_decode( $row['context'], true );

        $this->assertSame( 'bar', $ctx['foo'] );
        $this->assertArrayNotHasKey( 'api_key', $ctx );
        $this->assertArrayNotHasKey( 'password', $ctx );
    }
}
