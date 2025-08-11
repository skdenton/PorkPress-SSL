<?php
namespace {
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class AdminTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        if ( ! class_exists( '\\PorkPress\\SSL\\Domain_Service' ) ) {
            eval( 'namespace PorkPress\\SSL; class Domain_Service { public static $added = array(); public function add_alias( int $site_id, string $domain ) { self::$added[] = array( $site_id, $domain ); } }' );
        }
        $GLOBALS['porkpress_site_options'] = array();
        $_POST = array();
        if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return true; } }
        if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action ) { return true; } }
        if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); } }
        if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $str ) { return $str; } }
        if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $str ) { return $str; } }
        if ( ! function_exists( 'get_site_option' ) ) { function get_site_option( $key, $default = array() ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; } }
        if ( ! function_exists( 'update_site_option' ) ) { function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; } }
        if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = null ) { return $t; } }
        if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return $t; } }
        if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return $t; } }
        if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return $t; } }
        if ( ! function_exists( 'get_site' ) ) { function get_site( $id ) { return (object) array( 'id' => $id ); } }
        if ( ! function_exists( 'get_blog_option' ) ) { function get_blog_option( $id, $key ) { return 'Site ' . $id; } }
        if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action ) { echo '<nonce />'; } }
    }

    public function testApproveDomainRequestAddsAliasAndRemovesRequest() {
        require_once __DIR__ . '/../includes/class-admin.php';

        $GLOBALS['porkpress_site_options']['porkpress_ssl_domain_requests'] = array(
            array( 'id' => 'req1', 'site_id' => 1, 'domain' => 'example.com', 'justification' => 'test' ),
        );
        $_POST['request_id']  = 'req1';
        $_POST['ppssl_action'] = 'approve';

        $admin = new \PorkPress\SSL\Admin();
        $admin->render_requests_tab();

        $this->assertEmpty( get_site_option( 'porkpress_ssl_domain_requests', array() ) );
        $this->assertSame( array( array( 1, 'example.com' ) ), \PorkPress\SSL\Domain_Service::$added );
    }
}
}
