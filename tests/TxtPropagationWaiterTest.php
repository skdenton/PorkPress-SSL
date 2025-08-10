<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-txt-propagation-waiter.php';

class TxtPropagationWaiterTest extends TestCase {
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
}
