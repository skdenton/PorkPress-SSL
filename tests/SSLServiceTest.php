<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'get_site_option' ) ) {
    $GLOBALS['porkpress_site_options'] = array();
    function get_site_option( $key, $default = array() ) {
        return $GLOBALS['porkpress_site_options'][ $key ] ?? $default;
    }
}
if ( ! function_exists( 'update_site_option' ) ) {
    function update_site_option( $key, $value ) {
        $GLOBALS['porkpress_site_options'][ $key ] = $value;
    }
}

if ( ! function_exists( 'network_admin_url' ) ) {
    function network_admin_url( $path = '' ) { return 'https://example.com/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message ) {}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) { if ( ! is_dir( $dir ) ) { return mkdir( $dir, 0777, true ); } return true; }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( $timestamp, $hook ) {
        $GLOBALS['porkpress_scheduled'][] = $hook;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 0;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

class DummyWpdb {
    public $base_prefix = 'wp_';
    public function insert( $table, $data, $format = null ) {}
}

require_once __DIR__ . '/../includes/class-ssl-service.php';
require_once __DIR__ . '/../includes/class-domain-service.php';
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-notifier.php';
require_once __DIR__ . '/../includes/class-certbot-helper.php';
require_once __DIR__ . '/../includes/class-renewal-service.php';

/**
 * @runTestsInSeparateProcesses
 */
class SSLServiceTest extends TestCase {
    protected function setUp(): void {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $GLOBALS['porkpress_site_options'] = array();

        $state_root = sys_get_temp_dir() . '/porkpress-test-state';
        if ( ! defined( 'PORKPRESS_STATE_ROOT' ) ) {
            define( 'PORKPRESS_STATE_ROOT', $state_root );
        }
        if ( ! is_dir( PORKPRESS_STATE_ROOT ) ) {
            mkdir( PORKPRESS_STATE_ROOT, 0777, true );
        }

        $cert_root = sys_get_temp_dir() . '/porkpress-test-cert';
        if ( ! defined( 'PORKPRESS_CERT_ROOT' ) ) {
            define( 'PORKPRESS_CERT_ROOT', $cert_root );
        }
        if ( ! is_dir( PORKPRESS_CERT_ROOT ) ) {
            mkdir( PORKPRESS_CERT_ROOT, 0777, true );
        }

        if ( ! is_dir( PORKPRESS_CERT_ROOT . '/live/porkpress-network' ) ) {
            mkdir( PORKPRESS_CERT_ROOT . '/live/porkpress-network', 0777, true );
        }
        file_put_contents( PORKPRESS_CERT_ROOT . '/live/porkpress-network/fullchain.pem', 'full' );
        file_put_contents( PORKPRESS_CERT_ROOT . '/live/porkpress-network/privkey.pem', 'key' );
        file_put_contents( PORKPRESS_CERT_ROOT . '/live/porkpress-network/cert.pem', 'cert' );

        @mkdir('/etc/apache2/sites-available', 0777, true);
        @mkdir('/etc/apache2/sites-enabled', 0777, true);
        foreach ( glob('/etc/apache2/sites-available/*.conf') as $f ) { @unlink( $f ); }
        foreach ( glob('/etc/apache2/sites-enabled/*.conf') as $f ) { @unlink( $f ); }
        file_put_contents('/etc/apache2/sites-available/porkpress-network.conf', "SSLCertificateFile /old/fullchain.pem\nSSLCertificateKeyFile /old/privkey.pem\n");
        symlink('/etc/apache2/sites-available/porkpress-network.conf', '/etc/apache2/sites-enabled/porkpress-network.conf');
    }

    public function testQueueAndRun() {
        \PorkPress\SSL\SSL_Service::clear_queue();
        \PorkPress\SSL\SSL_Service::queue_issuance( 1 );
        \PorkPress\SSL\SSL_Service::queue_issuance( 2 );

        $this->assertSame( [ 1, 2 ], \PorkPress\SSL\SSL_Service::get_queue() );

        $domains = new class extends \PorkPress\SSL\Domain_Service {
            public array $seen = array();
            public function __construct() {}
            public function get_aliases( ?int $site_id = null, ?string $domain = null ): array {
                $this->seen[] = $site_id;
                if ( null === $site_id ) {
                    return array(
                        array( 'domain' => 'example.com', 'site_id' => 1 ),
                        array( 'domain' => 'www.example.com', 'site_id' => 1 ),
                        array( 'domain' => 'foo.com', 'site_id' => 2 ),
                        array( 'domain' => 'internal.example.com', 'site_id' => 3 ),
                    );
                }
                if ( 1 === $site_id ) {
                    return array(
                        array( 'domain' => 'example.com', 'site_id' => 1 ),
                        array( 'domain' => 'www.example.com', 'site_id' => 1 ),
                    );
                }
                if ( 2 === $site_id ) {
                    return array(
                        array( 'domain' => 'foo.com', 'site_id' => 2 ),
                        array( 'domain' => 'www.example.com', 'site_id' => 1 ),
                    );
                }
                return array();
            }
            public function is_internal_subdomain( int $site_id, string $domain ): bool {
                return 'internal.example.com' === $domain;
            }
        };

        $commands = array();
        \PorkPress\SSL\Renewal_Service::$runner = function( $cmd ) use ( &$commands ) {
            $commands[] = $cmd;
            return array( 'code' => 0, 'output' => 'ok' );
        };

        \PorkPress\SSL\SSL_Service::run_queue( $domains );

        $expected_domains = array( 'example.com', 'www.example.com', 'foo.com' );
        $expected_cmd     = \PorkPress\SSL\Renewal_Service::build_certbot_command( $expected_domains, 'porkpress-shard-1', false, false );
        $this->assertSame( $expected_cmd, $commands[0] );

        $manifest = json_decode( file_get_contents( PORKPRESS_STATE_ROOT . '/manifest.json' ), true );
        $this->assertSame( $expected_domains, $manifest['domains'] );

        $notices = get_site_option( \PorkPress\SSL\Notifier::OPTION );
        $this->assertSame( 'success', $notices[0]['type'] );

        $this->assertSame( [ null, 1, 2 ], $domains->seen );
        $this->assertSame( [], \PorkPress\SSL\SSL_Service::get_queue() );
    }

    public function testShardDomainsRespectsLimit() {
        $domains = array();
        for ( $i = 0; $i < 200; $i++ ) {
            $domains[] = "d{$i}.example.com";
        }

        $shards = \PorkPress\SSL\SSL_Service::shard_domains( $domains );
        foreach ( $shards as $list ) {
            $this->assertLessThanOrEqual( 90, count( $list ) );
        }

        // Ensure deterministic assignment for a sample.
        $map = array();
        foreach ( $shards as $idx => $list ) {
            foreach ( $list as $name ) {
                $map[ $name ] = $idx;
            }
        }
        $sample = \PorkPress\SSL\SSL_Service::shard_domains( $domains );
        $map2   = array();
        foreach ( $sample as $idx => $list ) {
            foreach ( $list as $name ) {
                $map2[ $name ] = $idx;
            }
        }
        $this->assertSame( $map, $map2 );
    }
}
