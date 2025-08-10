<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'get_site_option' ) ) {
    $GLOBALS['porkpress_site_options'] = array();
    function get_site_option( $key, $default = array() ) {
        return $GLOBALS['porkpress_site_options'][ $key ] ?? $default;
    }
    function update_site_option( $key, $value ) {
        $GLOBALS['porkpress_site_options'][ $key ] = $value;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( $timestamp, $hook ) {
        $GLOBALS['porkpress_scheduled'][] = $hook;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 0;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

class DummyWpdb {
    public $base_prefix = 'wp_';
    public function insert( $table, $data, $format = null ) {}
}

require_once __DIR__ . '/../includes/class-ssl-service.php';
require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-logger.php';

class SSLServiceTest extends TestCase {
    protected function setUp(): void {
        global $wpdb;
        $wpdb = new DummyWpdb();
    }

    public function testQueueAndRun() {
        \PorkPress\SSL\SSL_Service::clear_queue();
        \PorkPress\SSL\SSL_Service::queue_issuance( 1 );
        \PorkPress\SSL\SSL_Service::queue_issuance( 2 );

        $this->assertSame( [ 1, 2 ], \PorkPress\SSL\SSL_Service::get_queue() );

        $domains = new class extends \PorkPress\SSL\Domain_Service {
            public array $seen = array();
            public function __construct() {}
            public function get_aliases( ?int $site_id = null, ?string $domain = null ): array {
                $this->seen[] = $site_id;
                return array( array( 'domain' => 'example.com' ) );
            }
        };

        \PorkPress\SSL\SSL_Service::run_queue( $domains );

        $this->assertSame( [ 1, 2 ], $domains->seen );
        $this->assertSame( [], \PorkPress\SSL\SSL_Service::get_queue() );
    }
}
