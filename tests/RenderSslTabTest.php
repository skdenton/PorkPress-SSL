<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class RenderSslTabTest extends TestCase {
    public function testRendersCertificatesTable() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        if ( ! defined( 'PORKPRESS_SSL_VERSION' ) ) {
            define( 'PORKPRESS_SSL_VERSION', '1.0.0' );
        }
        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
        eval(<<<'CODE'
namespace PorkPress\SSL;
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function current_user_can( $cap ) { return true; }
function date_i18n( $format, $timestamp ) { return date( 'Y-m-d', $timestamp ); }
function get_option( $key ) { return 'Y-m-d'; }
CODE
        );
        require_once __DIR__ . '/../includes/class-admin.php';
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $admin = new \PorkPress\SSL\Admin();
        ob_start();
        $admin->render_ssl_tab();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Certificate Name', $output );
        $this->assertStringContainsString( 'Domains', $output );
        $this->assertStringContainsString( 'Expiration', $output );
        $this->assertStringContainsString( 'No certificates found.', $output );
    }
}
