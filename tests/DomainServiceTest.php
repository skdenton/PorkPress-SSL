<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-porkbun-client.php';

class DomainServiceTest extends TestCase {
    public function testListDomainsMapsTypeAndExpiry() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                return [
                    'status'  => 'SUCCESS',
                    'domains' => [
                        [
                            'domain'     => 'example.com',
                            'status'     => 'ACTIVE',
                            'tld'        => 'com',
                            'expireDate' => '2024-01-01',
                        ],
                    ],
                ];
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $result = $service->list_domains();
        $domain = $result['domains'][0];

        $this->assertArrayHasKey( 'type', $domain );
        $this->assertArrayHasKey( 'expiry', $domain );
        $this->assertSame( 'com', $domain['type'] );
        $this->assertSame( '2024-01-01', $domain['expiry'] );
    }
}

