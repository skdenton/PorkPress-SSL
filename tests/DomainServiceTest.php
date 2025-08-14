<?php
namespace PorkPress\SSL {
    function gethostbynamel( $host ) { return array( '1.1.1.1' ); }
    function dns_get_record( $domain, $type ) { return $GLOBALS['dns_records'][ $domain ] ?? array(); }
}

namespace {
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'network_home_url' ) ) {
    function network_home_url() { return 'https://expected.test'; }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url() { return 'https://expected.test'; }
}
if ( ! defined( 'DOMAIN_CURRENT_SITE' ) ) {
    define( 'DOMAIN_CURRENT_SITE', 'expected.test' );
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
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

if ( ! function_exists( 'get_site_option' ) ) {
    $GLOBALS['porkpress_site_options'] = array();
    $GLOBALS['porkpress_emails']       = array();
    function get_site_option( $key, $default = '' ) {
        return $GLOBALS['porkpress_site_options'][ $key ] ?? $default;
    }
}
$GLOBALS['dns_records'] = array();
if ( ! function_exists( 'update_site_option' ) ) {
    function update_site_option( $key, $value ) {
        $GLOBALS['porkpress_site_options'][ $key ] = $value;
    }
}
if ( ! function_exists( 'network_admin_url' ) ) {
    function network_admin_url( $path = '' ) {
        return 'https://example.com/' . $path;
    }
}
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message ) {
        $GLOBALS['porkpress_emails'][] = compact( 'to', 'subject', 'message' );
        return true;
    }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return $url;
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = null ) {
        return $text;
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return $text;
    }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( 'porkpress_ssl_skip_dns_check' === $tag ) {
            global $porkpress_skip_dns_check;
            return $porkpress_skip_dns_check ?? $value;
        }
        if ( 'porkpress_ssl_txt_propagation_resolvers' === $tag ) {
            global $porkpress_txt_resolver_filter;
            return $porkpress_txt_resolver_filter ?? $value;
        }
        if ( 'porkpress_ssl_add_www_cname' === $tag ) {
            global $porkpress_add_www_cname;
            return $porkpress_add_www_cname ?? $value;
        }
        return $value;
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $message;
        public function __construct( $code = '', $message = '' ) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}

if ( ! function_exists( 'update_site_meta' ) ) {
    function update_site_meta( $site_id, $key, $value ) {}
}

require_once __DIR__ . '/helpers/MockWpdb.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-porkbun-client.php';
require_once __DIR__ . '/../includes/class-ssl-service.php';
require_once __DIR__ . '/../includes/class-notifier.php';

class DomainServiceTest extends TestCase {
    public function testListDomainsMapsTypeAndExpiry() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
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
            public function get_records( string $domain ) { return [ 'records' => [] ]; }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $service->refresh_domains();
        $result = $service->list_domains();
        $domain = $result['root_domains'][0];

        $this->assertArrayHasKey( 'type', $domain );
        $this->assertArrayHasKey( 'expiry', $domain );
        $this->assertSame( 'com', $domain['type'] );
        $this->assertSame( '2024-01-01', $domain['expiry'] );
    }

    public function testListDomainsCachesResult() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'a.com' ] ] ];
                }
                if ( 2 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [] ];
                }
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
            public function get_records( string $domain ) { return [ 'records' => [] ]; }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $service->refresh_domains();
        $service->list_domains();
        $service->list_domains();

        $this->assertSame( 2, $mock->calls );
    }

    public function testListDomainsRetrievesMultiplePages() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'one.com' ] ] ];
                }
                if ( 2 === $this->calls ) {
                    return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'two.com' ] ] ];
                }
                return [ 'status' => 'SUCCESS', 'domains' => [] ];
            }
            public function get_records( string $domain ) { return [ 'records' => [] ]; }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $service->refresh_domains( 1, 1 );
        $result  = $service->list_domains();
        $domains = array_column( $result['domains'], 'domain' );

        $this->assertSame( [ 'one.com', 'two.com' ], $domains );
        $this->assertSame( 3, $mock->calls );
    }

    public function testListDomainsErrorsOnDuplicateResponses() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                return [ 'status' => 'SUCCESS', 'domains' => [ [ 'domain' => 'dup.com' ] ] ];
            }
            public function get_records( string $domain ) { return [ 'records' => [] ]; }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $result = $service->refresh_domains();
        $this->assertInstanceOf( \PorkPress\SSL\Porkbun_Client_Error::class, $result );
        $this->assertSame( 'duplicate_page', $result->code );
        $this->assertSame( 2, $mock->calls );
    }

    public function testIsDomainActiveUsesSingleEndpoint() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public string $last_domain = '';
            public function __construct() {}
            public function get_domain_details( string $domain ) {
                $this->last_domain = $domain;
                return array( 'domain' => $domain, 'status' => 'ACTIVE' );
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

    public function testIsDomainActiveLogsAndReturnsFalseOnError() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function get_domain_details( string $domain ) {
                return new \PorkPress\SSL\Porkbun_Client_Error( 'fail', 'error' );
            }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) {
                $this->client = $client;
                $this->missing_credentials = false;
            }
        };

        $this->assertFalse( $service->is_domain_active( 'example.com' ) );
        $table = \PorkPress\SSL\Logger::get_table_name();
        $this->assertNotEmpty( $wpdb->data[ $table ] );
        $log = $wpdb->data[ $table ][0];
        $this->assertSame( 'get_domain_details', $log['action'] );
        $this->assertSame( 'error', $log['result'] );
        $ctx = json_decode( $log['context'], true );
        $this->assertSame( 'example.com', $ctx['domain'] );
    }

    public function testAliasCrud() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
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

    public function testAddAliasRejectsInvalidDomain() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
        };

        $result = $service->add_alias( 1, 'bad_domain', true, 'active' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertCount( 0, $service->get_aliases( 1 ) );
    }

    public function testAddAliasDuplicateFails() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
        };

        $this->assertTrue( $service->add_alias( 1, 'example.com', true, 'active' ) );
        $result = $service->add_alias( 2, 'example.com', true, 'active' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertCount( 1, $service->get_aliases() );
    }

    public function testDnsFailureRollsBackInsert() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) {
                return new \PorkPress\SSL\Porkbun_Client_Error( 'err', 'fail' );
            }
        };

        $result = $service->add_alias( 1, 'example.com', true, 'active' );
        $this->assertInstanceOf( \PorkPress\SSL\Porkbun_Client_Error::class, $result );
        $this->assertCount( 0, $service->get_aliases( 1 ) );
    }

    public function testCreateRecordAddsWwwCnameForApex() {
        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function retrieve_by_name_type( string $domain, string $name, string $type ) { return array( 'records' => array() ); }
            public function edit_by_name_type( string $domain, string $name, string $type, string $content, ?int $ttl = null ) { return array( 'status' => 'SUCCESS' ); }
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) { return array( 'status' => 'SUCCESS' ); }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public array $names = array();
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function ensure_dns_record( string $domain, string $content, int $ttl, string $type, int $site_id, ?string $name_override = null ) {
                $this->names[] = $name_override ?? '';
                return true;
            }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return ''; }
            public function call_create( string $domain ) { return $this->create_a_record( $domain, 1, 600 ); }
        };

        $service->call_create( 'example.com' );
        $this->assertContains( 'www', $service->names );
    }

    public function testCreateRecordDoesNotAddWwwCnameForSubdomainByDefault() {
        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function retrieve_by_name_type( string $domain, string $name, string $type ) { return array( 'records' => array() ); }
            public function edit_by_name_type( string $domain, string $name, string $type, string $content, ?int $ttl = null ) { return array( 'status' => 'SUCCESS' ); }
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) { return array( 'status' => 'SUCCESS' ); }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public array $names = array();
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function ensure_dns_record( string $domain, string $content, int $ttl, string $type, int $site_id, ?string $name_override = null ) {
                $this->names[] = $name_override ?? '';
                return true;
            }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return ''; }
            public function call_create( string $domain ) { return $this->create_a_record( $domain, 1, 600 ); }
        };

        $service->call_create( 'sub.example.com' );
        $this->assertNotContains( 'www.sub', $service->names );
    }

    public function testCreateRecordAddsWwwCnameForSubdomainWhenFilterEnabled() {
        global $porkpress_add_www_cname;
        $porkpress_add_www_cname = true;

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $args = array();
            public function __construct() {}
            public function retrieve_by_name_type( string $domain, string $name, string $type ) { throw new \Exception(); }
            public function edit_by_name_type( string $domain, string $name, string $type, string $content, ?int $ttl = null ) { return array( 'status' => 'SUCCESS' ); }
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                $this->args[] = func_get_args();
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return ''; }
            public function call_create( string $domain ) { return $this->create_a_record( $domain, 1, 600 ); }
        };

        $service->call_create( 'sub.example.com' );

        $porkpress_add_www_cname = null;

        $this->assertSame(
            array(
                array( 'example.com', 'sub', '1.1.1.1', 600, 'A' ),
                array( 'example.com', 'www.sub', 'sub.example.com', 600, 'CNAME' ),
            ),
            $client->args
        );
    }

    public function testAttachToSiteCreatesARecordApex() {
        global $wpdb;
        $wpdb = new MockWpdb();

        if ( ! function_exists( 'update_site_meta' ) ) {
            function update_site_meta( $site_id, $key, $value ) {}
        }

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $args = array();
            public function __construct() {}
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                $this->args = func_get_args();
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function check_dns_health( string $domain ) { return true; }
        };

        $this->assertTrue( $service->attach_to_site( 'example.com', 1, 600 ) );
        $this->assertSame( ['example.com', '', '1.1.1.1', 600, 'A'], $client->args );
    }

    public function testAttachToSiteCreatesARecordSubdomain() {
        global $wpdb;
        $wpdb = new MockWpdb();

        if ( ! function_exists( 'update_site_meta' ) ) {
            function update_site_meta( $site_id, $key, $value ) {}
        }

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $args = array();
            public function __construct() {}
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                $this->args = func_get_args();
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function check_dns_health( string $domain ) { return true; }
        };

        $this->assertTrue( $service->attach_to_site( 'sub.example.com', 1, 600 ) );
        $this->assertSame( ['example.com', 'sub', '1.1.1.1', 600, 'A'], $client->args );
    }

    public function testAttachToSiteReturnsErrorOnApiFailure() {
        global $wpdb;
        $wpdb = new MockWpdb();

        if ( ! function_exists( 'update_site_meta' ) ) {
            function update_site_meta( $site_id, $key, $value ) {}
        }

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                return new \PorkPress\SSL\Porkbun_Client_Error( 'err', 'fail' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function check_dns_health( string $domain ) { return true; }
        };

        $result = $service->attach_to_site( 'example.com', 1 );
        $this->assertInstanceOf( \PorkPress\SSL\Porkbun_Client_Error::class, $result );
        $this->assertCount( 0, $service->get_aliases( 1 ) );
    }

    public function testAttachToSiteReturnsErrorOnDnsMismatch() {
        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() { $this->missing_credentials = false; }
            public function check_dns_health( string $domain ) { return new WP_Error( 'dns', 'bad' ); }
        };

        $result = $service->attach_to_site( 'example.com', 1 );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function testAttachToSiteBypassesDnsCheckWithFilter() {
        global $porkpress_skip_dns_check;
        $porkpress_skip_dns_check = true;

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public function __construct() {}
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) { return array( 'status' => 'SUCCESS' ); }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            public function check_dns_health( string $domain ) { return new WP_Error( 'dns', 'bad' ); }
        };

        $this->assertTrue( $service->attach_to_site( 'example.com', 1 ) );
        unset( $porkpress_skip_dns_check );
    }

    public function testSetPrimaryAlias() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
        };

        $service->add_alias( 1, 'one.com', true );
        $service->add_alias( 1, 'two.com', false );

        $service->set_primary_alias( 1, 'two.com' );
        $aliases = $service->get_aliases( 1 );
        $map     = array_column( $aliases, 'is_primary', 'domain' );
        $this->assertSame( 1, $map['two.com'] );
        $this->assertSame( 0, $map['one.com'] );
    }

    public function testDeleteAliasRemovesARecord() {
        global $wpdb;
        $wpdb = new MockWpdb();

        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $deleted = array();
            public function __construct() {}
            public function get_records( string $domain ) {
                return array( 'records' => array(
                    array( 'id' => 1, 'type' => 'A', 'content' => '1.1.1.1', 'name' => '' ),
                    array( 'id' => 2, 'type' => 'AAAA', 'content' => '::1', 'name' => '' ),
                ) );
            }
            public function delete_record( string $domain, int $record_id ) {
                $this->deleted[] = $record_id;
                return array( 'status' => 'SUCCESS' );
            }
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return '::1'; }
        };

        $service->add_alias( 1, 'example.com', true, 'active' );
        $service->delete_alias( 1, 'example.com' );

        $this->assertSame( array( 1, 2 ), $client->deleted );
    }

    public function testAddAliasQueuesWildcardForSubdomain() {
        global $wpdb;
        $wpdb = new MockWpdb();
        update_site_option( 'porkpress_ssl_network_wildcard', 1 );
        \PorkPress\SSL\SSL_Service::clear_queue();

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() {}
            protected function create_a_record( string $domain, int $site_id, int $ttl ) { return true; }
            protected function delete_a_record( string $domain, int $site_id ) { return true; }
        };

        $service->add_alias( 2, 'sub.expected.test', true, 'active' );

        $this->assertSame( array( 0 ), \PorkPress\SSL\SSL_Service::get_queue() );

        \PorkPress\SSL\SSL_Service::clear_queue();
        update_site_option( 'porkpress_ssl_network_wildcard', 0 );
    }

    public function testCheckDnsHealthFailsWhenNoARecord() {
        global $dns_records;
        $dns_records = array(
            'expected.test' => array(),
            'domain.test'   => array(
                array( 'type' => 'AAAA', 'ipv6' => '::1' ),
            ),
        );

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() { $this->missing_credentials = false; }
        };

        $result = $service->check_dns_health( 'domain.test' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function testConstructorNotifiesOnMissingCredentials() {
        $GLOBALS['porkpress_site_options'] = array();
        $GLOBALS['porkpress_emails']       = array();

        new \PorkPress\SSL\Domain_Service();

        $notices = get_site_option( \PorkPress\SSL\Notifier::OPTION, array() );
        $this->assertNotEmpty( $notices );
        $this->assertSame( 'error', $notices[0]['type'] );
    }

    public function testCreateARecordSendsIPv4andIPv6() {
        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $calls = [];
            public function __construct() {}
            public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
                $this->calls[] = [ $domain, $name, $content, $ttl, $type ];
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return '::1'; }
            public function call_create( $domain, $site_id, $ttl ) { return $this->create_a_record( $domain, $site_id, $ttl ); }
        };

        $service->call_create( 'domain.test', 1, 600 );

        $this->assertEquals(
            [
                [ 'domain.test', '', '1.1.1.1', 600, 'A' ],
                [ 'domain.test', '', '::1', 600, 'AAAA' ],
            ],
            $client->calls
        );
    }

    public function testDeleteARecordRemovesMatchingRecords() {
        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $deleted = [];
            public function __construct() {}
            public function get_records( string $domain ) {
                return array( 'records' => array(
                    array( 'id' => 1, 'name' => '', 'type' => 'A', 'content' => '1.1.1.1' ),
                    array( 'id' => 2, 'name' => '', 'type' => 'AAAA', 'content' => '::1' ),
                    array( 'id' => 3, 'name' => '', 'type' => 'A', 'content' => '2.2.2.2' ),
                ) );
            }
            public function delete_record( string $domain, int $record_id ) {
                $this->deleted[] = [ $domain, $record_id ];
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return '::1'; }
            public function call_delete( $domain, $site_id ) { return $this->delete_a_record( $domain, $site_id ); }
        };

        $service->call_delete( 'domain.test', 2 );

        $this->assertEquals(
            [
                [ 'domain.test', 1 ],
                [ 'domain.test', 2 ],
            ],
            $client->deleted
        );
    }

    public function testDeleteARecordForSubdomainRemovesOnlyMatchingEntries() {
        $client = new class extends \PorkPress\SSL\Porkbun_Client {
            public array $deleted = [];
            public array $domains = [];
            public function __construct() {}
            public function get_records( string $domain ) {
                $this->domains[] = $domain;
                return array( 'records' => array(
                    array( 'id' => 1, 'name' => 'sub', 'type' => 'A', 'content' => '1.1.1.1' ),
                    array( 'id' => 2, 'name' => 'sub', 'type' => 'AAAA', 'content' => '::1' ),
                    array( 'id' => 3, 'name' => 'sub', 'type' => 'A', 'content' => '2.2.2.2' ),
                    array( 'id' => 4, 'name' => '', 'type' => 'A', 'content' => '1.1.1.1' ),
                    array( 'id' => 5, 'name' => 'foo', 'type' => 'A', 'content' => '1.1.1.1' ),
                ) );
            }
            public function delete_record( string $domain, int $record_id ) {
                $this->deleted[] = [ $domain, $record_id ];
                return array( 'status' => 'SUCCESS' );
            }
        };

        $service = new class( $client ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
            protected function get_network_ip(): string { return '1.1.1.1'; }
            protected function get_network_ipv6(): string { return '::1'; }
            public function call_delete( $domain, $site_id ) { return $this->delete_a_record( $domain, $site_id ); }
        };

        $service->call_delete( 'sub.example.com', 2 );

        $this->assertSame( array( 'example.com' ), $client->domains );
        $this->assertEquals(
            [
                [ 'example.com', 1 ],
                [ 'example.com', 2 ],
            ],
            $client->deleted
        );
    }

    public function testListDomainsIncludesSubdomains() {
        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return array( 'status' => 'SUCCESS', 'domains' => array( array( 'domain' => 'adynton.net', 'status' => 'ACTIVE' ) ) );
                }
                return array( 'status' => 'SUCCESS', 'domains' => array() );
            }
            public function get_records( string $domain ) {
                return array( 'records' => array( array( 'type' => 'A', 'name' => 'dev', 'content' => '1.2.3.4' ) ) );
            }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $service->refresh_domains();
        $result  = $service->list_domains();
        $domains = array_column( $result['domains'], 'domain' );

        $this->assertContains( 'adynton.net', $domains );
        $this->assertContains( 'dev.adynton.net', $domains );
    }

    public function testRefreshDomainsIncludesDnsRecords() {
        $GLOBALS['porkpress_site_options'] = array();

        $mock = new class extends \PorkPress\SSL\Porkbun_Client {
            public int $calls = 0;
            public function __construct() {}
            public function list_domains( int $page = 1, int $per_page = 100 ) {
                $this->calls++;
                if ( 1 === $this->calls ) {
                    return array( 'status' => 'SUCCESS', 'domains' => array( array( 'domain' => 'example.com', 'status' => 'ACTIVE' ) ) );
                }
                return array( 'status' => 'SUCCESS', 'domains' => array() );
            }
            public function get_records( string $domain ) {
                return array( 'records' => array( array( 'type' => 'CNAME', 'name' => '@', 'content' => 'target.test' ) ) );
            }
            public function get_nameservers( string $domain ) { return array( 'status' => 'SUCCESS', 'ns' => array() ); }
        };

        $service = new class( $mock ) extends \PorkPress\SSL\Domain_Service {
            public function __construct( $client ) { $this->client = $client; $this->missing_credentials = false; }
        };

        $service->refresh_domains();
        $result = $service->list_domains();
        $domain = $result['root_domains'][0];

        $this->assertArrayHasKey( 'dns', $domain );
        $this->assertSame( 'CNAME', $domain['dns'][0]['type'] );
        $this->assertSame( 'target.test', $domain['dns'][0]['content'] );
    }

    public function testCheckDnsHealthOkWithMultipleExpectedIps() {
        global $dns_records;
        $dns_records = array(
            'domain.test' => array(
                array( 'type' => 'A', 'ip' => '2.2.2.2' ),
            ),
        );
        update_site_option( 'porkpress_ssl_ipv4_override', '1.1.1.1 2.2.2.2' );

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() { $this->missing_credentials = false; }
        };

        $result = $service->check_dns_health( 'domain.test' );
        $this->assertTrue( $result );

        update_site_option( 'porkpress_ssl_ipv4_override', '' );
    }

    public function testCheckDnsHealthUsesDigFallbackWhenDnsGetRecordMissing() {
        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() { $this->missing_credentials = false; }
            protected function has_dns_get_record(): bool { return false; }
            protected function dig_dns_records( string $domain ): array {
                return array( array( 'type' => 'A', 'ip' => '1.1.1.1' ) );
            }
        };

        $result = $service->check_dns_health( 'domain.test' );
        $this->assertTrue( $result );
    }

    public function testDnsPropagationNoticeAfterTimeout() {
        global $dns_records, $wpdb;
        $wpdb = new \MockWpdb();
        $dns_records = array(
            'domain.test' => array(
                array( 'type' => 'A', 'ip' => '2.2.2.2' ),
            ),
        );
        update_site_option( 'porkpress_ssl_ipv4_override', '1.1.1.1' );
        update_site_option( 'porkpress_ssl_dns_timeout', 1 );

        $service = new class extends \PorkPress\SSL\Domain_Service {
            public function __construct() { $this->missing_credentials = false; }
        };

        $result = $service->check_dns_health( 'domain.test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $failures = get_site_option( 'porkpress_ssl_dns_propagation', array() );
        $this->assertArrayHasKey( 'domain.test', $failures );

        $failures['domain.test'] = time() - 5;
        update_site_option( 'porkpress_ssl_dns_propagation', $failures );

        $result = $service->check_dns_health( 'domain.test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $notices = get_site_option( \PorkPress\SSL\Notifier::OPTION, array() );
        $this->assertNotEmpty( $notices );

        update_site_option( 'porkpress_ssl_ipv4_override', '' );
        update_site_option( 'porkpress_ssl_dns_timeout', 900 );
        update_site_option( 'porkpress_ssl_dns_propagation', array() );
        update_site_option( \PorkPress\SSL\Notifier::OPTION, array() );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['porkpress_skip_dns_check'] );
        $GLOBALS['dns_records'] = array();
        update_site_option( \PorkPress\SSL\Notifier::OPTION, array() );
        update_site_option( 'porkpress_ssl_dns_propagation', array() );
    }
}

}

