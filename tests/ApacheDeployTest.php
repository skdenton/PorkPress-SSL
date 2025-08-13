<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class ApacheDeployTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        if ( ! function_exists( 'get_site_option' ) ) {
            $GLOBALS['porkpress_site_options'] = array();
            function get_site_option( $key, $default = null ) { return $GLOBALS['porkpress_site_options'][ $key ] ?? $default; }
            function update_site_option( $key, $value ) { $GLOBALS['porkpress_site_options'][ $key ] = $value; }
        }
        if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
        if ( ! class_exists( '\\PorkPress\\SSL\\Notifier' ) ) {
            eval('namespace PorkPress\\SSL; class Notifier { const OPTION = "porkpress_ssl_notices"; public static function notify(string $t, string $s, string $m): void {} }');
        }
        eval('namespace PorkPress\\SSL; function is_writable($p){ if($p==="/etc/apache2/sites-available/ro.conf") return false; return \is_writable($p); }');
        if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $sub, $msg ) {} }
        if ( ! function_exists( 'network_admin_url' ) ) { function network_admin_url( $p = '' ) { return $p; } }
        if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }
        if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = null ) { return $t; } }
        if ( ! function_exists( '__' ) ) { function __( $t, $d = null ) { return $t; } }
        $GLOBALS['wpdb'] = new class { public $base_prefix = 'wp_'; public function insert( $t, $d, $f = null ) {} };
        if ( ! function_exists( 'current_time' ) ) { function current_time( $t = '' ) { return date( 'Y-m-d H:i:s' ); } }
        if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 0; } }

        // Prepare Apache directories and vhosts.
        @mkdir( '/etc/apache2/sites-available', 0777, true );
        @mkdir( '/etc/apache2/sites-enabled', 0777, true );
        foreach ( glob( '/etc/apache2/sites-available/*.conf' ) as $f ) { @unlink( $f ); }
        foreach ( glob( '/etc/apache2/sites-enabled/*.conf' ) as $f ) { @unlink( $f ); }

        file_put_contents( '/etc/apache2/sites-available/site.conf', "SSLCertificateFile /old/fullchain.pem\nSSLCertificateKeyFile /old/privkey.pem\n" );
        @unlink( '/etc/apache2/sites-enabled/site.conf' );
        symlink( '/etc/apache2/sites-available/site.conf', '/etc/apache2/sites-enabled/site.conf' );

        file_put_contents( '/etc/apache2/sites-available/ro.conf', "SSLCertificateFile /old/fullchain.pem\nSSLCertificateKeyFile /old/privkey.pem\n" );
        chmod( '/etc/apache2/sites-available/ro.conf', 0444 );
        @unlink( '/etc/apache2/sites-enabled/ro.conf' );
        symlink( '/etc/apache2/sites-available/ro.conf', '/etc/apache2/sites-enabled/ro.conf' );

        file_put_contents( '/etc/apache2/sites-available/disabled.conf', "SSLCertificateFile /old/fullchain.pem\nSSLCertificateKeyFile /old/privkey.pem\n" );

        $GLOBALS['porkpress_site_options'] = array(
            'porkpress_ssl_apache_reload' => 1,
            'porkpress_ssl_apache_reload_cmd' => 'reloadcmd',
            'porkpress_ssl_cert_root' => '/certroot',
        );
    }

    public function testDeploymentUpdatesAndReportsIssues() {
        require_once __DIR__ . '/../includes/class-logger.php';
        require_once __DIR__ . '/../includes/class-renewal-service.php';

        $executed = array();
        \PorkPress\SSL\Renewal_Service::$runner = function( $cmd ) use ( &$executed ) {
            $executed[] = $cmd;
            return array( 'code' => 0, 'output' => '' );
        };

        $ref = new \ReflectionMethod( \PorkPress\SSL\Renewal_Service::class, 'deploy_to_apache' );
        $ref->setAccessible( true );
        $result = $ref->invoke( null, 'test' );
        $this->assertFalse( $result );

        $contents = file_get_contents( '/etc/apache2/sites-available/site.conf' );
        $this->assertStringContainsString( '/certroot/live/test/fullchain.pem', $contents );
        $this->assertStringContainsString( '/certroot/live/test/privkey.pem', $contents );

        $ro_contents = file_get_contents( '/etc/apache2/sites-available/ro.conf' );
        $this->assertStringNotContainsString( '/certroot/live/test/fullchain.pem', $ro_contents );

        $snips = get_site_option( 'porkpress_ssl_apache_snippets' );
        $this->assertArrayHasKey( '/etc/apache2/sites-available/ro.conf', $snips );
        $this->assertArrayHasKey( '/etc/apache2/sites-available/disabled.conf', $snips );

        $this->assertEquals( array( 'reloadcmd' ), $executed );
    }
}
