<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class ReloadCommandTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        $GLOBALS['porkpress_site_options'] = array();
        if ( ! function_exists( 'get_site_option' ) ) {
            function get_site_option( $k, $d = null ) { return $GLOBALS['porkpress_site_options'][ $k ] ?? $d; }
            function update_site_option( $k, $v ) { $GLOBALS['porkpress_site_options'][ $k ] = $v; }
        }
        require_once __DIR__ . '/../includes/class-renewal-service.php';
    }

    public function testDetectsFallbackApachectl() {
        $bin = sys_get_temp_dir() . '/pp-bin';
        @mkdir( $bin, 0777, true );
        foreach ( array( 'systemctl', 'apache2ctl', 'service', 'apachectl' ) as $c ) { @unlink( "$bin/$c" ); }
        file_put_contents( "$bin/apachectl", "#!/bin/sh\nexit 0;" );
        chmod( "$bin/apachectl", 0755 );
        $orig = getenv( 'PATH' );
        putenv( "PATH=$bin" );
        $cmd = \PorkPress\SSL\Renewal_Service::get_apache_reload_cmd();
        $this->assertEquals( 'apachectl -k graceful', $cmd );
        putenv( "PATH=$orig" );
    }
}
