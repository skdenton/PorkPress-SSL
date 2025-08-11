<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class ApacheDeployTest extends TestCase {
    public function testDeploymentSymlinksAndReloads() {
        if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
        if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
        if ( ! function_exists( 'current_time' ) ) { function current_time( $t = '' ) { return date( 'Y-m-d H:i:s' ); } }
        if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 0; } }
        $GLOBALS['wpdb'] = new class { public $base_prefix = 'wp_'; public function insert( $t, $d, $f = null ) {} };

        eval(<<<'CODE'
namespace PorkPress\SSL;
$GLOBALS['porkpress_site_options'] = array();
function get_site_option( $key, $default = null ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
$GLOBALS['mock_fs'] = array( 'glob'=>array(), 'mkdir'=>array(), 'symlink'=>array(), 'copy'=>array(), 'unlink'=>array(), 'exists'=>array() );
function glob( $pattern ) { return $GLOBALS['mock_fs']['glob']; }
function is_dir( $dir ) { return in_array( $dir, $GLOBALS['mock_fs']['mkdir'], true ); }
function wp_mkdir_p( $dir ) { $GLOBALS['mock_fs']['mkdir'][] = $dir; return true; }
function file_exists( $path ) { return in_array( $path, $GLOBALS['mock_fs']['exists'], true ); }
function unlink( $path ) { $GLOBALS['mock_fs']['unlink'][] = $path; return true; }
function symlink( $src, $dest ) { $GLOBALS['mock_fs']['symlink'][] = array( $src, $dest ); return true; }
function copy( $src, $dest ) { $GLOBALS['mock_fs']['copy'][] = array( $src, $dest ); return true; }
function error_get_last() { return array( 'message' => 'mock' ); }
CODE);
        require_once __DIR__ . '/../includes/class-logger.php';
        require_once __DIR__ . '/../includes/class-renewal-service.php';

        $GLOBALS['porkpress_site_options'] = array(
            'porkpress_ssl_apache_reload' => 1,
            'porkpress_ssl_apache_reload_cmd' => 'reloadcmd',
            'porkpress_ssl_cert_root' => '/certroot',
        );
        $GLOBALS['mock_fs']['glob'] = array( '/etc/apache2/sites-available/site.conf' );
        $executed = array();
        \PorkPress\SSL\Renewal_Service::$runner = function( $cmd ) use ( &$executed ) {
            $executed[] = $cmd;
            return array( 'code' => 0, 'output' => '' );
        };
        $ref = new \ReflectionMethod( \PorkPress\SSL\Renewal_Service::class, 'deploy_to_apache' );
        $ref->setAccessible( true );
        $ref->invoke( null, 'test' );
        $expectedDir = '/etc/apache2/sites-available/site';
        $this->assertContains( $expectedDir, $GLOBALS['mock_fs']['mkdir'] );
        $this->assertEquals(
            array(
                array( '/certroot/live/test/fullchain.pem', $expectedDir . '/fullchain.pem' ),
                array( '/certroot/live/test/privkey.pem', $expectedDir . '/privkey.pem' ),
            ),
            $GLOBALS['mock_fs']['symlink']
        );
        $this->assertEquals( array( 'reloadcmd' ), $executed );
    }
}
