<?php
/**
 * Test Mini::set() method for service instance injection
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Lifetime;
use mini\Test;

$test = new class extends Test {

    private \stdClass $mockService;

    /** Observations made while still in the Bootstrap phase (harness bootstraps after setUp()) */
    private bool $bootstrapPhaseHas;
    private mixed $bootstrapPhaseGet;
    private mixed $bootstrapPhaseRetrieved1;
    private mixed $bootstrapPhaseRetrieved2;

    protected function setUp(): void
    {
        $mini = Mini::$mini;

        // set() during Bootstrap phase works without warning
        $this->mockService = new \stdClass();
        $this->mockService->name = 'MockService';
        $mini->set('test.mock', $this->mockService);

        $this->bootstrapPhaseHas = $mini->has('test.mock');
        $this->bootstrapPhaseGet = $mini->get('test.mock');

        // Subsequent get() calls must return the same instance (singleton behavior)
        $this->bootstrapPhaseRetrieved1 = $mini->get('test.mock');
        $this->bootstrapPhaseRetrieved2 = $mini->get('test.mock');

        // Register a lazy service before bootstrap (shadowed in the last test)
        $mini->addService('test.lazy', Lifetime::Singleton, fn() => new \stdClass());
    }

    public function testSetWorksDuringBootstrapPhase(): void
    {
        $this->assertTrue($this->bootstrapPhaseHas, 'Service should be registered');
        $this->assertSame($this->mockService, $this->bootstrapPhaseGet, 'Should return the exact instance');
    }

    public function testSetAutoRegistersServiceDefinition(): void
    {
        $this->assertTrue(Mini::$mini->has('test.mock'), 'has() should return true for set() services');
    }

    public function testSetServicesBehaveAsSingletons(): void
    {
        $this->assertTrue(
            $this->bootstrapPhaseRetrieved1 === $this->bootstrapPhaseRetrieved2,
            'Should return same instance'
        );
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

        $readyService = new \stdClass();
        Mini::$mini->set('test.ready', $readyService);
        restore_error_handler();

        $this->assertTrue($warningTriggered, 'Warning should be triggered during Ready phase');
        $this->assertSame($readyService, Mini::$mini->get('test.ready'), 'Service should still be set');
    }

    public function testSetThrowsWhenShadowingInstantiatedService(): void
    {
        $this->assertThrows(
            fn() => Mini::$mini->set('test.mock', new \stdClass()),
            \LogicException::class,
            'Should throw when shadowing instantiated service'
        );
    }

    public function testSetCanShadowRegisteredButNotInstantiatedService(): void
    {
        // test.lazy was registered before bootstrap but never retrieved.
        // Suppress the Ready-phase warning for this test.
        @Mini::$mini->set('test.lazy', new \stdClass());
        $this->assertTrue(Mini::$mini->has('test.lazy'));
    }
};

exit($test->run());
