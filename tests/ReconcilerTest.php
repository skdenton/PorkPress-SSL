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
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-reconciler.php';
require_once __DIR__ . '/DomainServiceTest.php'; // For MockWpdb class.

class ReconcilerTest extends TestCase {
    public function testArchivesSiteWhenPrimaryDomainMissing() {
        global $wpdb, $updated_sites;
        $wpdb = new MockWpdb();
        $updated_sites = [];

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function listDomains( int $page = 1, int $per_page = 100 ) {
                // Return empty list so domain is treated as missing.
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
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
}

