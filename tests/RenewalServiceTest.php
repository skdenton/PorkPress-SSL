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
        function wp_mkdir_p($dir){ if(!is_dir($dir)) mkdir($dir,0777,true); }
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
        require_once __DIR__ . '/../includes/class-renewal-service.php';

        $root = sys_get_temp_dir() . '/porkpress-test';
        define('PORKPRESS_STATE_ROOT', $root);
        if (!is_dir($root)) { mkdir($root,0777,true); }
        file_put_contents($root . '/manifest.json', json_encode([
            'cert_name' => 'test',
            'domains'   => ['example.com'],
            'expires_at'=> gmdate('c', time() + 10*DAY_IN_SECONDS)
        ]));
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
        $this->assertStringContainsString('--test-cert', $cmd);
    }
}
