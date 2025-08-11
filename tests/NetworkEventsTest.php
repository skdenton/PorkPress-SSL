<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return $str;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ) {}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( ...$args ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( ...$args ) {}
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
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/helpers/MockWpdb.php';
require_once __DIR__ . '/../porkpress-ssl.php';

class NetworkEventsTest extends TestCase {
    protected $service;

    protected function setUp(): void {
        global $wpdb;
        $wpdb = new MockWpdb();
        $this->service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
        };
    }

    public function testArchiveAndRestore() {
        $this->service->add_alias( 1, 'example.com', true, 'active' );

        porkpress_ssl_handle_archive_blog( 1 );
        $alias = $this->service->get_aliases( 1 )[0];
        $this->assertSame( 'archived', $alias['status'] );

        porkpress_ssl_handle_unarchive_blog( 1 );
        $alias = $this->service->get_aliases( 1 )[0];
        $this->assertSame( 'active', $alias['status'] );
    }

    public function testDeleteRemovesAliases() {
        $this->service->add_alias( 1, 'example.com', true, 'active' );

        porkpress_ssl_handle_delete_blog( 1, true );
        $this->assertCount( 0, $this->service->get_aliases( 1 ) );
    }
}
