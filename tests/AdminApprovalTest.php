<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class AdminApprovalTest extends TestCase {
    public function testApproveDomainRequestCallsAddAliasAndRemovesRequest() {
        if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        eval(<<<'CODE'
namespace PorkPress\SSL;
class Domain_Service {
    public static $instances = [];
    public $added = [];
    public function __construct() { self::$instances[] = $this; }
    public function add_alias( int $site_id, string $domain ) { $this->added[] = [$site_id, $domain]; }
}
function get_site_option( $key, $default = [] ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
function check_admin_referer( $action ) { return true; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function sanitize_key( $v ) { return $v; }
function wp_nonce_field( $a ) {}
function get_site( $id ) { return (object)['id'=>$id]; }
function get_blog_option( $id, $k ) { return 'Site '.$id; }
function esc_html__( $t, $d=null ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_attr__( $t, $d=null ) { return $t; }
function esc_attr( $t ) { return $t; }
function current_user_can( $cap ) { return true; }
CODE);
        require_once __DIR__ . '/../includes/class-admin.php';

        $GLOBALS['porkpress_site_options'] = array(
            'porkpress_ssl_domain_requests' => array(
                array(
                    'id' => 'req1',
                    'site_id' => 123,
                    'domain' => 'example.com',
                    'justification' => 'test'
                ),
            ),
        );

        $_POST = array(
            'request_id' => 'req1',
            'ppssl_action' => 'approve',
        );
        $admin = new \PorkPress\SSL\Admin();
        ob_start();
        $admin->render_requests_tab();
        ob_end_clean();

        $this->assertNotEmpty( \PorkPress\SSL\Domain_Service::$instances );
        $service = \PorkPress\SSL\Domain_Service::$instances[0];
        $this->assertSame( [ [123, 'example.com'] ], $service->added );
        $this->assertSame( array(), $GLOBALS['porkpress_site_options']['porkpress_ssl_domain_requests'] );
        $_POST = array();
    }
}
