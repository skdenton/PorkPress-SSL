<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return $str;
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 0;
    }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time() {
        return date( 'Y-m-d H:i:s' );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/helpers/MockWpdb.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-ssl-service.php';

class DomainServiceTest extends TestCase {
    public function testListDomainsMapsTypeAndExpiry() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
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
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
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

    public function testListDomainsCachesResult() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'a.com' ] ] ];
                }
                if ( 2 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [] ];
                }
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $service->list_domains();
        $service->list_domains();

        $this->assertSame( 2, $mock->calls );
    }

    public function testListDomainsRetrievesMultiplePages() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'one.com' ] ] ];
                }
                if ( 2 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'two.com' ] ] ];
                }
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $result  = $service->list_domains( 1, 1 );
        $domains = array_column( $result['domains'], 'domain' );

        $this->assertSame( [ 'one.com', 'two.com' ], $domains );
        $this->assertSame( 3, $mock->calls );
    }

    public function testListDomainsErrorsOnDuplicateResponses() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'dup.com' ] ] ];
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $result = $service->list_domains();
        $this->assertInstanceOf( \PorkPress\SSL\Porkbun_Client_Error::class, $result );
        $this->assertSame( 'duplicate_page', $result->code );
        $this->assertSame( 2, $mock->calls );
    }

    public function testIsDomainActiveUsesSingleEndpoint() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public string $last_domain = '';
            public function __construct() {}
            public function getDomain( string $domain ) {
                $this->last_domain = $domain;
                return [ 'status' => 'SUCCESS', 'domain' => [ 'status' => 'ACTIVE' ] ];
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $this->assertTrue( $service->is_domain_active( 'example.com' ) );
        $this->assertSame( 'example.com', $mock->last_domain );
    }

    public function testAliasCrud() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
        };

        $table = $service::get_alias_table_name();

        $this->assertTrue( $service->add_alias( 1, 'example.com', true, 'active' ) );

        $aliases = $service->get_aliases( 1 );
        $this->assertCount( 1, $aliases );
        $this->assertSame( 'example.com', $aliases[0]['domain'] );
        $this->assertSame( 1, $aliases[0]['is_primary'] );

        $service->update_alias( 1, 'example.com', [ 'status' => 'inactive', 'is_primary' => false ] );
        $alias = $service->get_aliases( 1 )[0];
        $this->assertSame( 'inactive', $alias['status'] );
        $this->assertSame( 0, $alias['is_primary'] );

        $service->delete_alias( 1, 'example.com' );
        $this->assertCount( 0, $service->get_aliases( 1 ) );
    }

    public function testAttachToSiteCreatesARecord() {
        global $wpdb;
        $wpdb = new MockWpdb();

        if ( ! function_exists( 'update_site_meta' ) ) {
            function update_site_meta( $site_id, $key, $value ) {}
        }

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $args = array();
            public function __construct() {}
            public function createARecord( string $domain, string $name, string $content, int $ttl = 300 ) {
                $this->args = func_get_args();
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function add_alias( int $site_id, string $domain, bool $is_primary = false, string $status = '' ): bool { return true; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
        };

        $this->assertTrue( $service->attach_to_site( 'example.com', 1, 600 ) );
        $this->assertSame( ['example.com', '', '1.1.1.1', 600], $client->args );
    }

    public function testAttachToSiteReturnsErrorOnApiFailure() {
        global $wpdb;
        $wpdb = new MockWpdb();

        if ( ! function_exists( 'update_site_meta' ) ) {
            function update_site_meta( $site_id, $key, $value ) {}
        }

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function createARecord( string $domain, string $name, string $content, int $ttl = 300 ) {
                return new \PorkPress\SSL\Porkbun_Client_Error( 'err', 'fail' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function add_alias( int $site_id, string $domain, bool $is_primary = false, string $status = '' ): bool { return true; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
        };

        $result = $service->attach_to_site( 'example.com', 1 );
        $this->assertInstanceOf( \PorkPress\SSL\Porkbun_Client_Error::class, $result );
        $logs = $wpdb->data['wp_porkpress_logs'] ?? array();
        $this->assertNotEmpty( $logs );
        $log = end( $logs );
        $this->assertSame( 'create_a_record', $log['action'] );
    }

    public function testSetPrimaryAlias() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
        };

        $service->add_alias( 1, 'one.com', true );
        $service->add_alias( 1, 'two.com', false );

        $service->set_primary_alias( 1, 'two.com' );
        $aliases = $service->get_aliases( 1 );
        $map     = array_column( $aliases, 'is_primary', 'domain' );
        $this->assertSame( 1, $map['two.com'] );
        $this->assertSame( 0, $map['one.com'] );
    }
}

