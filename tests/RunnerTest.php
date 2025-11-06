<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-runner.php';

class RunnerTest extends TestCase {
    protected function tearDown(): void {
        $this->resetRunnerMethod();
        parent::tearDown();
    }

    private function resetRunnerMethod(): void {
        $prop = new \ReflectionProperty(\PorkPress\SSL\Runner::class, 'method');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testCommandExistsReturnsFalseWhenRunnerInFallbackMode(): void {
        $prop = new \ReflectionProperty(\PorkPress\SSL\Runner::class, 'method');
        $prop->setAccessible(true);
        $prop->setValue(null, 'wpcli');

        $this->assertFalse(\PorkPress\SSL\Runner::command_exists('php'));
    }

    public function testCommandExistsReturnsFalseOnNonZeroExitCode(): void {
        $this->resetRunnerMethod();

        $this->assertFalse(\PorkPress\SSL\Runner::command_exists('this-command-should-not-exist'));
    }
}
