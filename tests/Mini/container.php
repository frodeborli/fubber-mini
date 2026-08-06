<?php
/**
 * Test PSR-11 Container Implementation in Mini
 *
 * Tests: addService(), get(), has() methods and Lifetime behaviors
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Lifetime;
use mini\Test;

$test = new class extends Test {

    /**
     * Exception raised by a duplicate addService() call made while still in the
     * Bootstrap phase. Captured in setUp() because after bootstrap() every
     * addService() call throws regardless of duplication, which would make the
     * assertion pass for the wrong reason.
     */
    private ?\Throwable $duplicateRegistrationError = null;

    protected function setUp(): void
    {
        $mini = Mini::$mini;

        // Register test services BEFORE bootstrap (while in Bootstrap phase)
        $mini->addService('test.singleton', Lifetime::Singleton, fn() => new \stdClass());
        $mini->addService('test.transient', Lifetime::Transient, fn() => new \stdClass());
        $mini->addService('test.scoped', Lifetime::Scoped, fn() => new \stdClass());
        $mini->addService('test.with-mini', Lifetime::Singleton, fn() => Mini::$mini);

        try {
            $mini->addService('test.singleton', Lifetime::Singleton, fn() => new \stdClass());
        } catch (\Throwable $e) {
            $this->duplicateRegistrationError = $e;
        }

        // The harness bootstraps after setUp() (required for Scoped services)
    }

    public function testAddServiceThrowsOnDuplicateRegistration(): void
    {
        $this->assertNotNull(
            $this->duplicateRegistrationError,
            'Duplicate addService() during Bootstrap phase should throw'
        );
        $this->assertInstanceOf(\LogicException::class, $this->duplicateRegistrationError);
    }

    public function testAddServiceThrowsAfterBootstrap(): void
    {
        $this->assertThrows(
            fn() => Mini::$mini->addService('test.new', Lifetime::Singleton, fn() => new \stdClass()),
            \LogicException::class
        );
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $singleton1 = Mini::$mini->get('test.singleton');
        $singleton2 = Mini::$mini->get('test.singleton');
        $this->assertTrue($singleton1 === $singleton2, 'Singleton should return same instance');
    }

    public function testTransientReturnsNewInstanceEachTime(): void
    {
        $transient1 = Mini::$mini->get('test.transient');
        $transient2 = Mini::$mini->get('test.transient');
        $this->assertFalse($transient1 === $transient2, 'Transient should return different instances');
    }

    public function testScopedReturnsSameInstanceWithinRequest(): void
    {
        $scoped1 = Mini::$mini->get('test.scoped');
        $scoped2 = Mini::$mini->get('test.scoped');
        $this->assertTrue($scoped1 === $scoped2, 'Scoped should return same instance in same request');
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $this->assertTrue(Mini::$mini->has('test.singleton'));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $this->assertFalse(Mini::$mini->has('nonexistent.service'));
    }

    public function testGetThrowsNotFoundExceptionForUnregisteredService(): void
    {
        $this->assertThrows(
            fn() => Mini::$mini->get('nonexistent.service'),
            \Psr\Container\NotFoundExceptionInterface::class
        );
    }

    public function testFactoryClosureCanAccessMiniInstance(): void
    {
        $this->assertTrue(Mini::$mini->get('test.with-mini') === Mini::$mini);
    }
};

exit($test->run());
