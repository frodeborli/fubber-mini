<?php
/**
 * Test Mini::set() method using class-based test pattern
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Mini;
use mini\Lifetime;
use mini\Test;

$test = new class extends Test {

    private \stdClass $mockService;

    protected function setUp(): void
    {
        // Pre-bootstrap setup: set a service instance
        $this->mockService = new \stdClass();
        $this->mockService->name = 'TestService';
        Mini::$mini->set('test.bootstrap-set', $this->mockService);

        // Register a lazy service for shadowing test
        Mini::$mini->addService('test.lazy', Lifetime::Singleton, fn() => new \stdClass());

        \mini\bootstrap();
    }

    public function testSetWorkedDuringBootstrapPhase(): void
    {
        $this->assertTrue(Mini::$mini->has('test.bootstrap-set'));
        $this->assertSame($this->mockService, Mini::$mini->get('test.bootstrap-set'));
    }

    public function testSetAutoRegistersServiceDefinition(): void
    {
        $this->assertTrue(Mini::$mini->has('test.bootstrap-set'));
    }

    public function testSetServicesBehaveAsSingletons(): void
    {
        $r1 = Mini::$mini->get('test.bootstrap-set');
        $r2 = Mini::$mini->get('test.bootstrap-set');

        $this->assertSame($r1, $r2);
    }

    public function testSetDuringReadyPhaseTriggersWarning(): void
    {
        $warningTriggered = false;

        set_error_handler(function($errno, $errstr) use (&$warningTriggered) {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'Ready phase')) {
                $warningTriggered = true;
                return true;
            }
            return false;
        });

        Mini::$mini->set('test.ready-set', new \stdClass());
        restore_error_handler();

        $this->assertTrue($warningTriggered, 'Expected E_USER_WARNING mentioning Ready phase');
    }

    public function testSetThrowsWhenShadowingInstantiatedService(): void
    {
        // test.bootstrap-set was already retrieved in earlier tests
        $this->assertThrows(
            fn() => Mini::$mini->set('test.bootstrap-set', new \stdClass()),
            \LogicException::class
        );
    }

    public function testSetCanShadowRegisteredButNotInstantiatedService(): void
    {
        // test.lazy was registered in setUp but never retrieved
        // Suppress warning since we're in Ready phase
        @Mini::$mini->set('test.lazy', new \stdClass());
        // If we got here without exception, it worked
    }
};

exit($test->run());
