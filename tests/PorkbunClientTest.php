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

require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-porkbun-client-dryrun.php';

class PorkbunClientTest extends TestCase {
    public function test_builds_endpoints_and_payloads() {
        $client = new \PorkPress\SSL\Porkbun_Client_DryRun( 'key', 'secret' );

        $client->ping();
        $last = end( $client->plan );
        $this->assertSame( 'ping', $last['endpoint'] );

        $client->get_pricing( 'com' );
        $last = end( $client->plan );
        $this->assertSame( 'pricing/get/com', $last['endpoint'] );

        $client->update_nameservers( 'example.com', array( 'ns1.example.com', 'ns2.example.com' ) );
        $last = end( $client->plan );
        $this->assertSame( 'domain/updateNs/example.com', $last['endpoint'] );
        $this->assertSame( array( 'ns1.example.com', 'ns2.example.com' ), $last['payload']['ns'] );

        $client->add_url_forward( 'example.com', 'www', 'https://dest', '301', true );
        $last = end( $client->plan );
        $this->assertSame( 'domain/addUrlForward/example.com', $last['endpoint'] );
        $this->assertSame( 'www', $last['payload']['name'] );

        $client->get_url_forwarding( 'example.com' );
        $last = end( $client->plan );
        $this->assertSame( 'domain/getUrlForwarding/example.com', $last['endpoint'] );

        $client->delete_url_forward( 'example.com', 42 );
        $last = end( $client->plan );
        $this->assertSame( 'domain/deleteUrlForward/example.com/42', $last['endpoint'] );

        $client->create_glue_record( 'example.com', 'ns1', array( '1.2.3.4' ) );
        $last = end( $client->plan );
        $this->assertSame( 'domain/createGlue/example.com', $last['endpoint'] );
        $this->assertSame( array( 'hostname' => 'ns1', 'ips' => array( '1.2.3.4' ) ), $last['payload'] );

        $client->update_glue_record( 'example.com', 'ns1', array( '1.2.3.5' ) );
        $last = end( $client->plan );
        $this->assertSame( 'domain/updateGlue/example.com/ns1', $last['endpoint'] );

        $client->delete_glue_record( 'example.com', 'ns1' );
        $last = end( $client->plan );
        $this->assertSame( 'domain/deleteGlue/example.com/ns1', $last['endpoint'] );

        $client->get_glue_records( 'example.com' );
        $last = end( $client->plan );
        $this->assertSame( 'domain/getGlue/example.com', $last['endpoint'] );

        $client->delete_by_name_type( 'example.com', 'www', 'A' );
        $last = end( $client->plan );
        $this->assertSame( 'dns/deleteByNameType/example.com/A/www', $last['endpoint'] );

        $client->create_dnssec( 'example.com', '1', '2', '3', 'abcd' );
        $last = end( $client->plan );
        $this->assertSame( 'dnssec/create/example.com', $last['endpoint'] );

        $client->retrieve_dnssec( 'example.com' );
        $last = end( $client->plan );
        $this->assertSame( 'dnssec/retrieve/example.com', $last['endpoint'] );

        $client->delete_dnssec( 'example.com' );
        $last = end( $client->plan );
        $this->assertSame( 'dnssec/delete/example.com', $last['endpoint'] );

        $client->retrieve_ssl_bundle( 'example.com' );
        $last = end( $client->plan );
        $this->assertSame( 'ssl/retrieve/example.com', $last['endpoint'] );

        $client->get_record( 'example.com', 5 );
        $last = end( $client->plan );
        $this->assertSame( 'dns/retrieve/example.com/5', $last['endpoint'] );
        $this->assertSame( array(), $last['payload'] );

        $client->create_record( 'example.com', 'a', 'www', '1.2.3.4', 600, 10 );
        $last = end( $client->plan );
        $this->assertSame( 'dns/create/example.com', $last['endpoint'] );
        $this->assertSame(
            array(
                'type'    => 'A',
                'name'    => 'www',
                'content' => '1.2.3.4',
                'ttl'     => 600,
                'prio'    => 10,
            ),
            $last['payload']
        );

        $client->edit_record( 'example.com', 5, 'aaaa', 'www', '1.2.3.5', 600, 20 );
        $last = end( $client->plan );
        $this->assertSame( 'dns/edit/example.com/5', $last['endpoint'] );
        $this->assertSame(
            array(
                'type'    => 'AAAA',
                'name'    => 'www',
                'content' => '1.2.3.5',
                'ttl'     => 600,
                'prio'    => 20,
            ),
            $last['payload']
        );

        $client->delete_record( 'example.com', 5 );
        $last = end( $client->plan );
        $this->assertSame( 'dns/delete/example.com/5', $last['endpoint'] );
        $this->assertSame( array(), $last['payload'] );
    }
}
