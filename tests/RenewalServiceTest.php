<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class RenewalServiceTest extends TestCase {
    protected function setUp(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__);
        }
        if (!function_exists('absint')) {
            function absint($n){ return abs(intval($n)); }
        }
        if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
        if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

        $GLOBALS['porkpress_site_options'] = array();
        $GLOBALS['porkpress_events'] = array();
        $GLOBALS['porkpress_scheduled'] = array();

        function get_site_option($key, $default = null){ return $GLOBALS['porkpress_site_options'][$key] ?? $default; }
        function update_site_option($key, $value){ $GLOBALS['porkpress_site_options'][$key] = $value; }
        function delete_site_option($key){ unset($GLOBALS['porkpress_site_options'][$key]); }
        function wp_next_scheduled($hook){ return $GLOBALS['porkpress_events'][$hook] ?? false; }
        function wp_schedule_single_event($timestamp, $hook){ $GLOBALS['porkpress_events'][$hook] = $timestamp; $GLOBALS['porkpress_scheduled'][] = $hook; }
        function wp_unschedule_event($timestamp, $hook){ unset($GLOBALS['porkpress_events'][$hook]); }
        function wp_json_encode($d){ return json_encode($d); }
        function wp_mkdir_p($dir){ if(!is_dir($dir)) return mkdir($dir,0777,true); return true; }
        function current_time($t){ return gmdate('Y-m-d H:i:s'); }
        function get_current_user_id(){ return 0; }
        function network_admin_url($p=''){ return 'https://example.com/'.$p; }
        function esc_url($u){ return $u; }
        function esc_html__($t,$d=null){ return $t; }
        function esc_attr($t){ return $t; }
        function wp_kses_post($t){ return $t; }
        function add_action($h,$c){}
        function current_user_can($c){ return true; }
        function __($t,$d=null){ return $t; }
        function wp_mail($to,$sub,$msg){ $GLOBALS['porkpress_mails'][] = compact('to','sub','msg'); }

        $GLOBALS['wpdb'] = new class { public $base_prefix = 'wp_'; public function insert($t,$d,$f=null){} };

        require_once __DIR__ . '/../includes/class-logger.php';
        require_once __DIR__ . '/../includes/class-notifier.php';
        require_once __DIR__ . '/../includes/class-certbot-helper.php';
        require_once __DIR__ . '/../includes/class-renewal-service.php';

        $root = sys_get_temp_dir() . '/porkpress-test';
        define('PORKPRESS_STATE_ROOT', $root);
        if (!is_dir($root)) { mkdir($root,0777,true); }
        file_put_contents($root . '/manifest.json', json_encode([
            'cert_name' => 'test',
            'domains'   => ['example.com'],
            'expires_at'=> gmdate('c', time() + 10*DAY_IN_SECONDS)
        ]));

        $cert_root = sys_get_temp_dir() . '/porkpress-cert';
        define('PORKPRESS_CERT_ROOT', $cert_root);
        if (!is_dir($cert_root . '/live/test')) { mkdir($cert_root . '/live/test',0777,true); }
        file_put_contents($cert_root . '/live/test/fullchain.pem', 'full');
        file_put_contents($cert_root . '/live/test/privkey.pem', 'key');
        file_put_contents($cert_root . '/live/test/cert.pem', 'cert');

        @mkdir('/etc/apache2/sites-available', 0777, true);
        @mkdir('/etc/apache2/sites-enabled', 0777, true);
        foreach ( glob('/etc/apache2/sites-available/*.conf') as $f ) { @unlink( $f ); }
        foreach ( glob('/etc/apache2/sites-enabled/*.conf') as $f ) { @unlink( $f ); }
        file_put_contents('/etc/apache2/sites-available/test.conf', "SSLCertificateFile /old/fullchain.pem\nSSLCertificateKeyFile /old/privkey.pem\n");
        symlink('/etc/apache2/sites-available/test.conf', '/etc/apache2/sites-enabled/test.conf');
    }

    public function testSchedulesBasedOnExpiry() {
        update_site_option('porkpress_ssl_renew_window', 5);
        \PorkPress\SSL\Renewal_Service::maybe_schedule();
        $ts = wp_next_scheduled( \PorkPress\SSL\Renewal_Service::CRON_HOOK );
        $this->assertGreaterThanOrEqual(time(), $ts);
    }

    public function testBackoffOnFailure() {
        \PorkPress\SSL\Renewal_Service::$runner = function($cmd){ return ['code'=>1,'output'=>'err']; };
        \PorkPress\SSL\Renewal_Service::run();
        $delay1 = wp_next_scheduled( \PorkPress\SSL\Renewal_Service::CRON_HOOK ) - time();
        $this->assertEquals(HOUR_IN_SECONDS, $delay1);

        \PorkPress\SSL\Renewal_Service::run();
        $delay2 = wp_next_scheduled( \PorkPress\SSL\Renewal_Service::CRON_HOOK ) - time();
        $this->assertEquals(2*HOUR_IN_SECONDS, $delay2);
    }

    public function testStagingAddsFlag() {
        $cmd = \PorkPress\SSL\Renewal_Service::build_certbot_command(['example.com'], 'test', true, true);
        $this->assertStringContainsString('--staging', $cmd);
    }

    public function testBuildCertbotCommandAddsNetworkWildcard() {
        update_site_option('porkpress_ssl_network_wildcard', 1);
        if ( ! defined('DOMAIN_CURRENT_SITE') ) {
            define('DOMAIN_CURRENT_SITE', 'example.com');
        }
        $cmd = \PorkPress\SSL\Renewal_Service::build_certbot_command(['sub.example.com'], 'cert', false, false);
        $this->assertStringContainsString("-d 'example.com'", $cmd);
        $this->assertStringContainsString("-d '*.example.com'", $cmd);
        $this->assertStringNotContainsString("-d 'sub.example.com'", $cmd);
    }

    public function testReloadsApacheAndUpdatesVhosts() {
        update_site_option('porkpress_ssl_apache_reload', 1);
        update_site_option('porkpress_ssl_apache_reload_cmd', 'reloadcmd');
        $commands = [];
        \PorkPress\SSL\Renewal_Service::$runner = function($cmd) use (&$commands){ $commands[] = $cmd; return ['code'=>0,'output'=>'']; };
        \PorkPress\SSL\Renewal_Service::run();
        $this->assertContains('reloadcmd', $commands);
        $contents = file_get_contents('/etc/apache2/sites-available/test.conf');
        $this->assertStringContainsString(PORKPRESS_CERT_ROOT . '/live/test/fullchain.pem', $contents);
    }

    public function testNoReloadWhenDisabled() {
        update_site_option('porkpress_ssl_apache_reload', 0);
        $commands = [];
        \PorkPress\SSL\Renewal_Service::$runner = function($cmd) use (&$commands){ $commands[] = $cmd; return ['code'=>0,'output'=>'']; };
        \PorkPress\SSL\Renewal_Service::run();
        $this->assertCount(1, $commands);
        $contents = file_get_contents('/etc/apache2/sites-available/test.conf');
        $this->assertStringNotContainsString(PORKPRESS_CERT_ROOT . '/live/test/fullchain.pem', $contents);
    }
}
