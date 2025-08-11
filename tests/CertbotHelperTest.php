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
        $this->assertStringContainsString( "-d 'example.com'", $cmd );
        $this->assertStringContainsString( "-d 'www.example.com'", $cmd );
    }

    public function testBuildCommandHandlesWildcardDomain() {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }
        require_once __DIR__ . '/../includes/class-certbot-helper.php';

        $cmd = \PorkPress\SSL\Certbot_Helper::build_command( [ 'example.com', '*.example.com' ], 'test', false );

        $this->assertStringContainsString( "-d '*.example.com'", $cmd );
        $this->assertStringContainsString( "-d 'example.com'", $cmd );
    }
}
