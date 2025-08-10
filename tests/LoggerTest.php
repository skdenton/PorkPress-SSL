<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../includes/class-logger.php';

class LoggerTest extends TestCase {
    public function test_sanitize_context_removes_secrets() {
        $ctx = json_encode( array( 'foo' => 'bar', 'api_key' => 'secret', 'password' => 'p' ) );
        $sanitized = \PorkPress\SSL\Logger::sanitize_context( $ctx );
        $data = json_decode( $sanitized, true );
        $this->assertSame( 'bar', $data['foo'] );
        $this->assertArrayNotHasKey( 'api_key', $data );
        $this->assertArrayNotHasKey( 'password', $data );
    }
}
