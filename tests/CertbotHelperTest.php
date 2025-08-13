<?php
use PHPUnit\Framework\TestCase;

class CertbotHelperTest extends TestCase {
    public function testBuildCommandIncludesFlagsAndDomains() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $cmd = \PorkPress\SSL\Certbot_Helper::build_command( [ 'example.com', 'www.example.com' ], 'test', true, true );

        $this->assertStringContainsString( '--test-cert', $cmd );
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
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $output = "Found the following certs:\n  Certificate Name: example.com\n    Serial Number: 123\n    Key Type: RSA\n    Domains: example.com www.example.com\n    Expiry Date: 2024-01-01\n  Certificate Name: other.org\n    Serial Number: 456\n    Key Type: RSA\n    Domains: other.org\n    Expiry Date: 2024-01-01\n";

        $parsed = \PorkPress\SSL\Certbot_Helper::parse_certificates_output( $output );

        $this->assertArrayHasKey( 'example.com', $parsed );
        $this->assertSame( [ 'example.com', 'www.example.com' ], $parsed['example.com']['domains'] );
        $this->assertArrayHasKey( 'other.org', $parsed );
        $this->assertSame( [ 'other.org' ], $parsed['other.org']['domains'] );
    }
}
