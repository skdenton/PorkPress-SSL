<?php
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        if ( ! function_exists( 'absint' ) ) {
            function absint( $n ) { return abs( intval( $n ) ); }
        }
        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
        $GLOBALS['porkpress_site_options'] = array();
        $GLOBALS['porkpress_events'] = array();
        if ( ! function_exists( 'get_site_option' ) ) {
            function get_site_option( $key, $default = null ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
        }
        if ( ! function_exists( 'update_site_option' ) ) {
            function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
        }
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            function wp_next_scheduled( $hook ) { return $GLOBALS['porkpress_events'][ $hook ] ?? false; }
        }
        if ( ! function_exists( 'wp_schedule_single_event' ) ) {
            function wp_schedule_single_event( $timestamp, $hook ) { $GLOBALS['porkpress_events'][ $hook ] = $timestamp; }
        }
        if ( ! function_exists( 'wp_unschedule_event' ) ) {
            function wp_unschedule_event( $timestamp, $hook ) { unset( $GLOBALS['porkpress_events'][ $hook ] ); }
        }
        if ( ! function_exists( 'wp_json_encode' ) ) {
            function wp_json_encode( $d ) { return json_encode( $d ); }
        }
        if ( ! function_exists( 'wp_mkdir_p' ) ) {
            function wp_mkdir_p( $dir ) { if ( ! is_dir( $dir ) ) mkdir( $dir, 0777, true ); }
        }
        if ( ! function_exists( 'current_time' ) ) {
            function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
        }
        if ( ! function_exists( 'get_current_user_id' ) ) {
            function get_current_user_id() { return 0; }
        }
        if ( ! function_exists( 'network_admin_url' ) ) {
            function network_admin_url( $p = '' ) { return ''; }
        }
        if ( ! function_exists( 'esc_url' ) ) {
            function esc_url( $u ) { return $u; }
        }
        if ( ! function_exists( 'esc_html__' ) ) {
            function esc_html__( $t, $d = null ) { return $t; }
        }
        if ( ! function_exists( 'esc_attr' ) ) {
            function esc_attr( $t ) { return $t; }
        }
        if ( ! function_exists( 'wp_kses_post' ) ) {
            function wp_kses_post( $t ) { return $t; }
        }
        if ( ! function_exists( 'add_action' ) ) {
            function add_action( $h, $c ) {}
        }
        if ( ! function_exists( 'current_user_can' ) ) {
            function current_user_can( $c ) { return true; }
        }
        if ( ! function_exists( '__' ) ) {
            function __( $t, $d = null ) { return $t; }
        }
        if ( ! function_exists( 'wp_mail' ) ) {
            function wp_mail( $to, $sub, $msg ) {}
        }
        $GLOBALS['wpdb'] = new class { public $base_prefix = 'wp_'; public function insert($t,$d,$f=null){} };
        require_once __DIR__ . '/../includes/class-logger.php';
        require_once __DIR__ . '/../includes/class-notifier.php';
        require_once __DIR__ . '/../includes/class-certbot-helper.php';
        require_once __DIR__ . '/../includes/class-renewal-service.php';
    }

    public function testCreatesManifestFromExistingCertificate() {
        $cert_root = sys_get_temp_dir() . '/porkpress-migrate-cert';
        $state_root = sys_get_temp_dir() . '/porkpress-migrate-state';
        update_site_option( 'porkpress_ssl_cert_root', $cert_root );
        update_site_option( 'porkpress_ssl_state_root', $state_root );
        update_site_option( 'porkpress_ssl_cert_name', 'adynton.com' );
        $live_dir = $cert_root . '/live/adynton.com';
        if ( ! is_dir( $live_dir ) ) { mkdir( $live_dir, 0777, true ); }
        $cert_path = $live_dir . '/cert.pem';
        $key_path  = $live_dir . '/privkey.pem';
        $cmd = 'openssl req -x509 -nodes -newkey rsa:2048 -keyout ' . escapeshellarg( $key_path ) . ' -out ' . escapeshellarg( $cert_path ) . ' -days 1 -subj "/CN=example.com"';
        exec( $cmd );
        copy( $cert_path, $live_dir . '/fullchain.pem' );
        copy( $cert_path, $live_dir . '/chain.pem' );
        if ( file_exists( $state_root . '/manifest.json' ) ) { unlink( $state_root . '/manifest.json' ); }
        \PorkPress\SSL\Renewal_Service::maybe_schedule();
        $this->assertFileExists( $state_root . '/manifest.json' );
        $manifest = json_decode( file_get_contents( $state_root . '/manifest.json' ), true );
        $this->assertEquals( 'adynton.com', $manifest['cert_name'] );
        $this->assertContains( 'example.com', $manifest['domains'] );
    }
}
