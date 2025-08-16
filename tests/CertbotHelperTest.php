<?php
use PHPUnit\Framework\TestCase;

class CertbotHelperTest extends TestCase {
    public function testBuildCommandIncludesFlagsAndDomains() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $cmd = \PorkPress\SSL\Certbot_Helper::build_command( [ 'example.com', 'www.example.com' ], 'test', true, true );

        $this->assertStringContainsString( '--staging', $cmd );
        $this->assertStringContainsString( '--force-renewal', $cmd );
        $this->assertStringContainsString( '--deploy-hook', $cmd );
        $this->assertStringContainsString( "-d 'example.com'", $cmd );
        $this->assertStringContainsString( "-d 'www.example.com'", $cmd );
    }

    public function testBuildCommandAllowsWildcardDomains() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $cmd = \PorkPress\SSL\Certbot_Helper::build_command( [ '*.example.com' ], 'test', false, false );

        $this->assertStringContainsString( "-d '*.example.com'", $cmd );
    }

    public function testParseCertificatesOutput() {
        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $valid   = date( 'Y-m-d', time() + 40 * DAY_IN_SECONDS );
        $soon    = date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
        $expired = date( 'Y-m-d', time() - DAY_IN_SECONDS );

        $output = "Found the following certs:\n"
            . "  Certificate Name: valid.com\n    Serial Number: 123\n    Key Type: RSA\n    Domains: valid.com www.valid.com\n    Expiry Date: $valid\n"
            . "  Certificate Name: soon.com\n    Serial Number: 456\n    Key Type: RSA\n    Domains: soon.com\n    Expiry Date: $soon\n"
            . "  Certificate Name: expired.com\n    Serial Number: 789\n    Key Type: RSA\n    Domains: expired.com\n    Expiry Date: $expired\n";

        $parsed = \PorkPress\SSL\Certbot_Helper::parse_certificates_output( $output );

        $this->assertSame( $valid, $parsed['valid.com']['expiry'] );
        $this->assertSame( 'Valid', $parsed['valid.com']['status'] );

        $this->assertSame( $soon, $parsed['soon.com']['expiry'] );
        $this->assertSame( 'Expiring Soon', $parsed['soon.com']['status'] );

        $this->assertSame( $expired, $parsed['expired.com']['expiry'] );
        $this->assertSame( 'Expired', $parsed['expired.com']['status'] );

        $this->assertSame( [ 'valid.com', 'www.valid.com' ], $parsed['valid.com']['domains'] );
        $this->assertSame( [ 'soon.com' ], $parsed['soon.com']['domains'] );
        $this->assertSame( [ 'expired.com' ], $parsed['expired.com']['domains'] );
    }

    /**
     * Ensure list_certificates uses custom certbot path.
     *
     * @runInSeparateProcess
     */
    public function testListCertificatesUsesCustomPath() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }

        $script = tempnam( sys_get_temp_dir(), 'certbot' );
        $content  = "#!/bin/sh\n";
        $content .= "echo 'Found the following certs:'\n";
        $content .= "echo '  Certificate Name: custom.com'\n";
        $content .= "echo '    Domains: custom.com'\n";
        $content .= "echo '    Expiry Date: 2030-01-01'\n";
        file_put_contents( $script, $content );
        chmod( $script, 0700 );

        // Shim WordPress get_site_option.
        $GLOBALS['pp_ssl_site_options'] = array( 'porkpress_ssl_certbot_cmd' => $script );
        if ( ! function_exists( 'get_site_option' ) ) {
            function get_site_option( $key, $default = false ) {
                return $GLOBALS['pp_ssl_site_options'][ $key ] ?? $default;
            }
        }

        if ( ! class_exists( '\\PorkPress\\SSL\\Logger' ) ) {
            eval( 'namespace PorkPress\\SSL; class Logger { public static function error(...$args){} public static function warn(...$args){} public static function info(...$args){} }' );
        }
        if ( ! class_exists( '\\PorkPress\\SSL\\Notifier' ) ) {
            eval( 'namespace PorkPress\\SSL; class Notifier { public static function notify(...$args){} }' );
        }

        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $certs = \PorkPress\SSL\Certbot_Helper::list_certificates();

        unlink( $script );

        $this->assertArrayHasKey( 'custom.com', $certs );
        $this->assertSame( array( 'custom.com' ), $certs['custom.com']['domains'] );
    }

    /**
     * Ensure a notice is emitted when certbot list fails.
     *
     * @runInSeparateProcess
     */
    public function testListCertificatesNotifiesOnFailure() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }

        if ( ! class_exists( '\\PorkPress\\SSL\\Logger', false ) ) {
            eval( 'namespace PorkPress\\SSL; class Logger { public static function error(...$args){} public static function warn(...$args){} public static function info(...$args){} }' );
        }
        if ( ! class_exists( '\\PorkPress\\SSL\\Notifier', false ) ) {
            eval( 'namespace PorkPress\\SSL; class Notifier { public static $last = null; public static function notify(...$args){ self::$last = $args; } }' );
        }

        $script = tempnam( sys_get_temp_dir(), 'certbot' );
        $content  = "#!/bin/sh\n";
        $content .= "echo boom >&2\n";
        $content .= "exit 1\n";
        file_put_contents( $script, $content );
        chmod( $script, 0700 );

        $GLOBALS['pp_ssl_site_options'] = array( 'porkpress_ssl_certbot_cmd' => $script );
        if ( ! function_exists( 'get_site_option' ) ) {
            function get_site_option( $key, $default = false ) {
                return $GLOBALS['pp_ssl_site_options'][ $key ] ?? $default;
            }
        }

        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        \PorkPress\SSL\Certbot_Helper::list_certificates();

        unlink( $script );

        $this->assertNotNull( \PorkPress\SSL\Notifier::$last );
        $this->assertSame( 'error', \PorkPress\SSL\Notifier::$last[0] );
        $this->assertStringContainsString( 'boom', \PorkPress\SSL\Notifier::$last[2] );
    }
}
