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
if ( ! function_exists( 'update_blog_status' ) ) {
    function update_blog_status( $site_id, $pref, $value ) {
        global $updated_sites;
        $updated_sites[ $site_id ][ $pref ] = $value;
    }
}
if ( ! function_exists( 'get_sites' ) ) {
    function get_sites( $args = array() ) {
        global $mock_sites;
        $sites = array();
        foreach ( $mock_sites as $id => $data ) {
            $sites[] = (object) array(
                'blog_id'  => $id,
                'archived' => $data['archived'],
            );
        }
        return $sites;
    }
}
if ( ! function_exists( 'get_site_meta' ) ) {
    function get_site_meta( $site_id, $key, $single = true ) {
        global $site_meta;
        return $site_meta[ $site_id ][ $key ] ?? '';
    }
}
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-reconciler.php';
require_once __DIR__ . '/helpers/MockWpdb.php';

class ReconcilerTest extends TestCase {
    public function testArchivesSiteWhenPrimaryDomainMissing() {
        global $wpdb, $updated_sites;
        $wpdb = new MockWpdb();
        $updated_sites = [];

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
            public function getDomain( string $domain ) {
                return [ 'status' => 'SUCCESS', 'domain' => [ 'status' => 'INACTIVE' ] ];
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
        };

        // Add aliases to the mock table.
        $service->add_alias( 1, 'gone.com', true );
        $service->add_alias( 1, 'alias.com', false );

        $reconciler = new \PorkPress\SSL\Reconciler( $service );
        $result = $reconciler->reconcile_site( 1 );

        $this->assertTrue( $result );
        $this->assertSame( 1, $updated_sites[1]['archived'] );
        $this->assertCount( 0, $service->get_aliases( 1 ) );
    }

    public function testReconcileAll() {
        global $wpdb, $updated_sites, $mock_sites, $site_meta;
        $wpdb         = new MockWpdb();
        $updated_sites = array();
        $mock_sites    = array(
            1 => array( 'archived' => 0 ),
            2 => array( 'archived' => 1 ),
            3 => array( 'archived' => 0 ),
        );
        $site_meta = array(
            1 => array( 'porkpress_domain' => 'existing.com' ),
            2 => array( 'porkpress_domain' => 'extra.com' ),
            3 => array( 'porkpress_domain' => 'stray.com' ),
        );

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                if ( $page > 1 ) {
                    return array( 'status' => 'SUCCESS', 'domains' => array() );
                }

                return array(
                    'status'  => 'SUCCESS',
                    'domains' => array(
                        array( 'domain' => 'existing.com', 'status' => 'ACTIVE' ),
                        array( 'domain' => 'extra.com', 'status' => 'ACTIVE' ),
                    ),
                );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client              = $client;
                $this->missing_credentials = false;
            }
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
        };

        $service->add_alias( 1, 'existing.com', true );
        $service->add_alias( 3, 'stray.com', true );

        $reconciler = new \PorkPress\SSL\Reconciler( $service );
        $result     = $reconciler->reconcile_all();

        $this->assertCount( 1, $service->get_aliases( 2 ) );
        $this->assertSame( 'extra.com', $service->get_aliases( 2 )[0]['domain'] );
        $this->assertCount( 0, $service->get_aliases( 3 ) );
        $this->assertSame( 0, $updated_sites[2]['archived'] );
        $this->assertNotEmpty( $result['missing_aliases'] );
        $this->assertNotEmpty( $result['stray_aliases'] );
        $this->assertNotEmpty( $result['disabled_sites'] );
    }

    public function testReconcileAllWithoutApplyingChanges() {
        global $wpdb, $updated_sites, $mock_sites, $site_meta;
        $wpdb         = new MockWpdb();
        $updated_sites = array();
        $mock_sites    = array(
            1 => array( 'archived' => 0 ),
            2 => array( 'archived' => 1 ),
            3 => array( 'archived' => 0 ),
        );
        $site_meta = array(
            1 => array( 'porkpress_domain' => 'existing.com' ),
            2 => array( 'porkpress_domain' => 'extra.com' ),
            3 => array( 'porkpress_domain' => 'stray.com' ),
        );

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                if ( $page > 1 ) {
                    return array( 'status' => 'SUCCESS', 'domains' => array() );
                }

                return array(
                    'status'  => 'SUCCESS',
                    'domains' => array(
                        array( 'domain' => 'existing.com', 'status' => 'ACTIVE' ),
                        array( 'domain' => 'extra.com', 'status' => 'ACTIVE' ),
                    ),
                );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client              = $client;
                $this->missing_credentials = false;
            }
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
        };

        $service->add_alias( 1, 'existing.com', true );
        $service->add_alias( 3, 'stray.com', true );

        $reconciler = new \PorkPress\SSL\Reconciler( $service );
        $result     = $reconciler->reconcile_all( false );

        // No changes should have been applied.
        $this->assertCount( 0, $service->get_aliases( 2 ) );
        $this->assertCount( 1, $service->get_aliases( 3 ) );
        $this->assertArrayNotHasKey( 2, $updated_sites );

        // Drift should still be reported.
        $this->assertNotEmpty( $result['missing_aliases'] );
        $this->assertNotEmpty( $result['stray_aliases'] );
        $this->assertNotEmpty( $result['disabled_sites'] );
    }
}

