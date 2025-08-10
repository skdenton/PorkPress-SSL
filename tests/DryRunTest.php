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
if ( ! function_exists( 'get_site_option' ) ) {
    function get_site_option( $key, $default = '' ) {
        return $default;
    }
}
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

require_once __DIR__ . '/DomainServiceTest.php'; // For MockWpdb.
require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-porkbun-client-dryrun.php';
require_once __DIR__ . '/../includes/class-domain-service.php';

class DryRunTest extends TestCase {
    public function testDryRunRecordsPlan() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new \PorkPress\SSL\Domain_Service( null, true );
        $result  = $service->list_domains();
        $plan    = $service->get_plan();

        $this->assertSame( 'SUCCESS', $result['status'] );
        $this->assertNotEmpty( $plan );
        $this->assertSame( 'domain/listAll', $plan[0]['endpoint'] );
    }
}
