<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
class NotifierTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ );
        }

        $GLOBALS['porkpress_site_options'] = array( 'admin_email' => 'admin@example.com' );
        $GLOBALS['porkpress_emails']       = array();

        function get_site_option( $key, $default = array() ) {
            return $GLOBALS['porkpress_site_options'][ $key ] ?? $default;
        }
        function update_site_option( $key, $value ) {
            $GLOBALS['porkpress_site_options'][ $key ] = $value;
        }
        function delete_site_option( $key ) {
            unset( $GLOBALS['porkpress_site_options'][ $key ] );
        }
        function network_admin_url( $path = '' ) {
            return 'https://example.com/' . $path;
        }
        function wp_mail( $to, $subject, $message ) {
            $GLOBALS['porkpress_emails'][] = compact( 'to', 'subject', 'message' );
            return true;
        }
        function add_action( $hook, $callback ) {}
        function current_user_can( $cap ) { return true; }
        function __( $text, $domain = null ) { return $text; }
        function esc_html__( $text, $domain = null ) { return $text; }
        function esc_url( $url ) { return $url; }
        function esc_attr( $text ) { return $text; }
        function wp_kses_post( $text ) { return $text; }

        require_once __DIR__ . '/../includes/class-notifier.php';
    }

    public function testNotifyStoresNoticeAndEmail() {
        \PorkPress\SSL\Notifier::notify( 'success', 'Subject', 'Body' );
        $notices = get_site_option( \PorkPress\SSL\Notifier::OPTION );
        $this->assertCount( 1, $notices );
        $this->assertSame( 'success', $notices[0]['type'] );
        $this->assertStringContainsString( 'View logs', $notices[0]['message'] );
        $this->assertNotEmpty( $GLOBALS['porkpress_emails'] );
    }
}
