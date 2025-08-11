<?php
namespace {
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class ApacheDeployTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        $GLOBALS['site_opts'] = array(
            'porkpress_ssl_apache_reload'     => 1,
            'porkpress_ssl_apache_reload_cmd' => 'reloadcmd',
            'porkpress_ssl_cert_root'         => '/certroot',
        );
        $GLOBALS['files'] = array(
            '/certroot/live/test/fullchain.pem' => true,
            '/certroot/live/test/privkey.pem'   => true,
        );
        $GLOBALS['dirs']          = array();
        $GLOBALS['symlink_calls'] = array();
        $GLOBALS['copy_calls']    = array();
        $GLOBALS['symlink_ok']    = false;
        $GLOBALS['copy_ok']       = true;

        $code = <<<'EOC'
namespace PorkPress\SSL {
    function glob( $pattern ) { return array( '/etc/apache2/sites-available/test.conf' ); }
    function is_dir( $dir ) { return $GLOBALS['dirs'][ $dir ] ?? false; }
    function wp_mkdir_p( $dir ) { $GLOBALS['dirs'][ $dir ] = true; return true; }
    function file_exists( $path ) { return $GLOBALS['files'][ $path ] ?? false; }
    function unlink( $path ) { $GLOBALS['unlinked'][] = $path; return true; }
    function symlink( $src, $dest ) { $GLOBALS['symlink_calls'][] = array( $src, $dest ); return $GLOBALS['symlink_ok'] ?? true; }
    function copy( $src, $dest ) { $GLOBALS['copy_calls'][] = array( $src, $dest ); return $GLOBALS['copy_ok'] ?? true; }
    function get_site_option( $key, $default = null ) { return $GLOBALS['site_opts'][ $key ] ?? $default; }
    function wp_json_encode( $d ) { return json_encode( $d ); }
    function current_time( $type ) { return 'now'; }
    function get_current_user_id() { return 0; }
}
EOC;
        eval( $code );

        $GLOBALS['wpdb'] = new class {
            public $base_prefix = 'wp_';
            public function get_charset_collate() { return ''; }
            public function insert( $table, $data ) { $GLOBALS['logger_inserts'][] = array( $table, $data ); }
        };
        require_once __DIR__ . '/../includes/class-logger.php';
        require_once __DIR__ . '/../includes/class-renewal-service.php';
    }

    public function testDeployToApacheCopiesAndReloads() {
        \PorkPress\SSL\Renewal_Service::$runner = function ( $cmd ) {
            $GLOBALS['exec_cmd'] = $cmd;
            return array( 'code' => 0, 'output' => '' );
        };

        $ref = new \ReflectionMethod( \PorkPress\SSL\Renewal_Service::class, 'deploy_to_apache' );
        $ref->setAccessible( true );
        $ref->invoke( null, 'test' );

        $this->assertNotEmpty( $GLOBALS['symlink_calls'] );
        $this->assertNotEmpty( $GLOBALS['copy_calls'] );
        $this->assertSame( 'reloadcmd', $GLOBALS['exec_cmd'] );
    }
}
}
