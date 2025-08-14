<?php
use PHPUnit\Framework\TestCase;

define('ABSPATH', __DIR__);
require_once __DIR__ . '/../includes/class-porkbun-client.php';

class BackoffTest extends TestCase {
    public function testExponentialBackoffGrows() {
        $client = new class('key', 'secret') extends \PorkPress\SSL\Porkbun_Client {
            public function exposeDelay($attempt) { return $this->calculate_backoff($attempt); }
            protected function jitter(float $base): float { return $base; }
            protected function sleep(float $seconds): void {}
            protected function perform_http_request(string $url, array $payload, string $method): array {
                return ['status' => 200, 'body' => '{"status":"SUCCESS"}'];
            }
        };
        $d1 = $client->exposeDelay(1);
        $d2 = $client->exposeDelay(2);
        $d3 = $client->exposeDelay(3);
        $this->assertEquals(2, $d1);
        $this->assertEquals(4, $d2);
        $this->assertEquals(8, $d3);
    }

    public function testJitterWithinBounds() {
        $client = new class('key', 'secret') extends \PorkPress\SSL\Porkbun_Client {
            public function exposeDelay($attempt) { return $this->calculate_backoff($attempt); }
            protected function jitter(float $base): float { return 0; }
        };
        $d1 = $client->exposeDelay(1);
        $this->assertEquals(1, $d1);
    }
}
