<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class RenderSettingsTabTest extends TestCase {
    public function testPostingIpvOverridesUpdatesOptions() {
        if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        if ( ! defined( 'PORKPRESS_SSL_VERSION' ) ) {
            define( 'PORKPRESS_SSL_VERSION', '1.0.0' );
        }
        eval(<<<'CODE'
namespace PorkPress\SSL;
class Logger { public static function info( ...$args ) {} }
class Renewal_Service {
    public static function maybe_schedule( $force = false ) {}
    public static function get_apache_reload_cmd() { return ''; }
}
class Certbot_Helper { public static function list_certificates() { return array(); } }
function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
function get_site_option( $key, $default = '' ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
function check_admin_referer( $action, $name = '' ) { return true; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function absint( $v ) { return (int) $v; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_attr( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function checked( $checked, $current = true, $echo = false ) { return ''; }
function wp_nonce_field( $action, $name = '', $referer = true, $echo = true ) {}
function submit_button( $text = null, $type = '', $name = '', $wrap = true ) {}
function current_user_can( $cap ) { return true; }
CODE
        );
        require_once __DIR__ . '/../includes/class-admin.php';

        $GLOBALS['porkpress_site_options'] = array();

        $_POST = array(
            'porkpress_ssl_settings_nonce' => 'nonce',
            'porkpress_ipv4' => '1.2.3.4',
            'porkpress_ipv6' => '2001:db8::1',
        );

        $admin = new \PorkPress\SSL\Admin();
        ob_start();
        $admin->render_settings_tab();
        ob_end_clean();

        $this->assertSame( '1.2.3.4', $GLOBALS['porkpress_site_options']['porkpress_ssl_ipv4_override'] );
        $this->assertSame( '2001:db8::1', $GLOBALS['porkpress_site_options']['porkpress_ssl_ipv6_override'] );

        $_POST = array();
        ob_start();
        $admin->render_settings_tab();
        $output = ob_get_clean();
        $this->assertStringContainsString( 'value="1.2.3.4"', $output );
        $this->assertStringContainsString( 'value="2001:db8::1"', $output );
    }

    public function testPostingServerIpsReappear() {
        if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        if ( ! defined( 'PORKPRESS_SSL_VERSION' ) ) {
            define( 'PORKPRESS_SSL_VERSION', '1.0.0' );
        }
        eval(<<<'CODE'
namespace PorkPress\SSL;
class Logger { public static function info( ...$args ) {} }
class Renewal_Service {
    public static function maybe_schedule( $force = false ) {}
    public static function get_apache_reload_cmd() { return ''; }
}
class Certbot_Helper { public static function list_certificates() { return array(); } }
function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
function get_site_option( $key, $default = '' ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
function check_admin_referer( $action, $name = '' ) { return true; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function absint( $v ) { return (int) $v; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_attr( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function checked( $checked, $current = true, $echo = false ) { return ''; }
function wp_nonce_field( $action, $name = '', $referer = true, $echo = true ) {}
function submit_button( $text = null, $type = '', $name = '', $wrap = true ) {}
function current_user_can( $cap ) { return true; }
CODE
        );
        require_once __DIR__ . '/../includes/class-admin.php';

        $GLOBALS['porkpress_site_options'] = array();

        $_POST = array(
            'porkpress_ssl_settings_nonce' => 'nonce',
            'porkpress_prod_server' => '10.0.0.1',
            'porkpress_dev_server'  => '10.0.0.2',
        );

        $admin = new \PorkPress\SSL\Admin();
        ob_start();
        $admin->render_settings_tab();
        ob_end_clean();

        $this->assertSame( '10.0.0.1', $GLOBALS['porkpress_site_options']['porkpress_ssl_prod_server_ip'] );
        $this->assertSame( '10.0.0.2', $GLOBALS['porkpress_site_options']['porkpress_ssl_dev_server_ip'] );

        $_POST = array();
        ob_start();
        $admin->render_settings_tab();
        $output = ob_get_clean();
        $this->assertStringContainsString( 'value="10.0.0.1"', $output );
        $this->assertStringContainsString( 'value="10.0.0.2"', $output );
    }
}
