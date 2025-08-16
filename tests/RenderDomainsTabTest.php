<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class RenderDomainsTabTest extends TestCase {
    public function testMappedDomainDisplaysBlogNameAndServer() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        if ( ! defined( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS' ) ) {
            define( 'PORKPRESS_SSL_CAP_MANAGE_NETWORK_DOMAINS', 'manage_network' );
        }
        if ( ! defined( 'PORKPRESS_SSL_VERSION' ) ) {
            define( 'PORKPRESS_SSL_VERSION', '1.0.0' );
        }
        eval(<<<'CODE'
namespace PorkPress\SSL;
class Domain_Service {
    public static $aliases = [];
    public static $domains = [];
    public static $servers = [];
    public function has_credentials() { return true; }
    public function get_aliases( ?int $site_id = null, ?string $domain = null ): array {
        if ( null !== $domain ) {
            $domain = strtolower( $domain );
            if ( isset( self::$aliases[ $domain ] ) ) {
                $row = self::$aliases[ $domain ];
                $row += self::$servers[ $domain ] ?? array( 'prod_server_ip' => '', 'dev_server_ip' => '' );
                return array( $row );
            }
            return array();
        }
        $out = array();
        foreach ( self::$aliases as $d => $row ) {
            $row += self::$servers[ $d ] ?? array( 'prod_server_ip' => '', 'dev_server_ip' => '' );
            $out[] = $row;
        }
        return $out;
    }
    public function list_domains() {
        return array( 'domains' => array_map(
            function ( $d ) {
                if ( is_array( $d ) ) {
                    return $d;
                }
                return array( 'domain' => $d, 'dns' => array() );
            },
            self::$domains
        ) );
    }
    public function get_last_refresh() { return 0; }
}
function get_sites( $args ) { return array(); }
function get_site( $id ) { return (object) array( 'blog_id' => $id ); }
function get_blog_option( $id, $key ) { return 'Blog ' . $id; }
function network_admin_url( $path = '' ) { return 'http://example.org/' . $path; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_url( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function submit_button( $text, $type = '', $name = '', $wrap = true ) {}
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function sanitize_text_field( $v ) { return $v; }
function sanitize_key( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return (int) $v; }
function current_user_can( $cap ) { return true; }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {}
function wp_set_script_translations( $handle, $domain = 'default', $path = null ) {}
function wp_localize_script( $handle, $object_name, $l10n ) {}
function admin_url( $path = '', $scheme = 'admin' ) { return 'http://example.org/' . $path; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function set_url_scheme( $url, $scheme = null ) { return $url; }
function plugin_dir_url( $file ) { return 'http://example.org/'; }
function plugin_dir_path( $file ) { return '/'; }
function wp_list_pluck( $list, $field ) { return array(); }
CODE
        );
        require_once __DIR__ . '/../includes/class-admin.php';

        \PorkPress\SSL\Domain_Service::$domains = array(
            array(
                'domain' => 'example.com',
                'dns'    => array(
                    array( 'type' => 'A', 'content' => '203.0.113.10' ),
                ),
            ),
            array(
                'domain' => 'nomatch.com',
                'dns'    => array(
                    array( 'type' => 'A', 'content' => '203.0.113.20' ),
                ),
            ),
        );
        \PorkPress\SSL\Domain_Service::$aliases = array(
            'example.com' => array( 'domain' => 'example.com', 'site_id' => 123 ),
        );
        \PorkPress\SSL\Domain_Service::$servers = array(
            'example.com' => array( 'prod_server_ip' => '203.0.113.10', 'dev_server_ip' => '' ),
        );

        $admin = new \PorkPress\SSL\Admin();
        ob_start();
        $admin->render_domains_tab();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Blog 123', $output );

        $dom = new \DOMDocument();
        @$dom->loadHTML( $output );
        $rows    = $dom->getElementsByTagName( 'tr' );
        $servers = array();
        foreach ( $rows as $tr ) {
            $links = $tr->getElementsByTagName( 'a' );
            if ( $links->length ) {
                $domain = trim( $links->item( 0 )->nodeValue );
                $tds    = $tr->getElementsByTagName( 'td' );
                if ( $tds->length >= 5 ) {
                    $servers[ $domain ] = trim( $tds->item( 4 )->nodeValue );
                }
            }
        }
        $this->assertSame( 'Prod', $servers['example.com'] );
        $this->assertSame( '', $servers['nomatch.com'] );
    }
}
