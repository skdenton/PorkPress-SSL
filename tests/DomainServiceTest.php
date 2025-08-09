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
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

class MockWpdb {
    public $data = [];
    public $base_prefix = 'wp_';

    public function get_charset_collate() {
        return '';
    }

    public function prepare( $query, $args ) {
        if ( ! is_array( $args ) ) {
            $args = func_get_args();
            array_shift( $args );
        }
        $args = array_map( function ( $a ) {
            return is_int( $a ) ? $a : "'{$a}'";
        }, $args );

        return vsprintf( $query, $args );
    }

    private function table_from_sql( $sql ) {
        if ( preg_match( '/FROM\s+(\w+)/', $sql, $m ) ) {
            return $m[1];
        }
        return '';
    }

    public function insert( $table, $data, $format ) {
        $this->data[ $table ][] = $data;
        return 1;
    }

    public function get_results( $sql, $output ) {
        $table = $this->table_from_sql( $sql );
        $rows  = $this->data[ $table ] ?? [];
        if ( preg_match( '/site_id\s*=\s*(\d+)/', $sql, $m ) ) {
            $site_id = (int) $m[1];
            $rows    = array_filter( $rows, fn( $r ) => $r['site_id'] == $site_id );
        }
        if ( preg_match( "/domain\s*=\s*'([^']+)'/", $sql, $m ) ) {
            $domain = $m[1];
            $rows   = array_filter( $rows, fn( $r ) => $r['domain'] == $domain );
        }
        return array_values( $rows );
    }

    public function update( $table, $data, $where, $formats, $where_formats ) {
        foreach ( $this->data[ $table ] as &$row ) {
            if ( $row['site_id'] == $where['site_id'] && $row['domain'] == $where['domain'] ) {
                foreach ( $data as $k => $v ) {
                    $row[ $k ] = $v;
                }
                return 1;
            }
        }
        return false;
    }

    public function delete( $table, $where, $where_formats ) {
        foreach ( $this->data[ $table ] as $i => $row ) {
            if ( $row['site_id'] == $where['site_id'] && $row['domain'] == $where['domain'] ) {
                unset( $this->data[ $table ][ $i ] );
                $this->data[ $table ] = array_values( $this->data[ $table ] );
                return 1;
            }
        }
        return false;
    }
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
}

