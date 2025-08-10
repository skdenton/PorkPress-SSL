<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( 'porkpress_ssl_txt_propagation_resolvers' === $tag ) {
            global $porkpress_txt_resolver_filter;
            if ( isset( $porkpress_txt_resolver_filter ) ) {
                return $porkpress_txt_resolver_filter;
            }
        }
        return $value;
    }
}

require_once __DIR__ . '/../includes/class-txt-propagation-waiter.php';

class TxtPropagationWaiterTest extends TestCase {
    protected function tearDown(): void {
        unset( $GLOBALS['porkpress_txt_resolver_filter'] );
    }
    public function testWaitReturnsTrueWhenRecordsFound() {
        $waiter = new class extends \PorkPress\SSL\TXT_Propagation_Waiter {
            protected function query_txt( string $name, string $resolver ): array {
                return array( 'token' );
            }
            protected function do_sleep( int $seconds ): void {}
        };
        $this->assertTrue( $waiter->wait( 'example.com', 'token', 1, 0 ) );
    }

    public function testWaitTimesOutWhenRecordsMissing() {
        $waiter = new class extends \PorkPress\SSL\TXT_Propagation_Waiter {
            protected function query_txt( string $name, string $resolver ): array {
                return array();
            }
            protected function do_sleep( int $seconds ): void {}
        };
        $this->assertFalse( $waiter->wait( 'example.com', 'token', 1, 0 ) );
    }

    public function testResolversFilterApplied() {
        $GLOBALS['porkpress_txt_resolver_filter'] = array( '9.9.9.9' );
        $waiter = new class extends \PorkPress\SSL\TXT_Propagation_Waiter {
            public function get_resolvers() { return $this->resolvers; }
        };
        $this->assertSame( array( '9.9.9.9' ), $waiter->get_resolvers() );
    }
}
