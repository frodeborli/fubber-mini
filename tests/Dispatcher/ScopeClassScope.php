<?php
/**
 * Where the Scope class exists (the PHP Scope RFC, or fiber-interop/scope), a request's Scope is
 * its request scope: Swerve runs every request in a Scope of its own
 *
 * The invariants: a Scoped service is one instance within a Scope, and coroutines (Fibers) that
 * run inside it share it; another Scope has its own. The root scope does not count: plain
 * Fibers outside any Scope are still told apart by their Fiber.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Lifetime;
use mini\Mini;
use mini\Test;

$test = new class extends Test {

    protected function canRun(): bool
    {
        return \class_exists(\Scope::class);
    }

    protected function skipReason(): string
    {
        return 'no Scope class (fiber-interop/scope, a dev dependency through phasync)';
    }

    protected function setUp(): void
    {
        Mini::$mini->addService('test.scoped', Lifetime::Scoped, fn () => new \stdClass());
        \mini\bootstrap();
    }

    public function testEachScopeHasItsOwnScopedServices(): void
    {
        $a = (new \Scope())->run(fn () => [Mini::$mini->get('test.scoped'), Mini::$mini->get('test.scoped')]);
        $b = (new \Scope())->run(fn () => Mini::$mini->get('test.scoped'));
        $this->assertSame($a[0], $a[1]);
        $this->assertTrue($a[0] !== $b, 'two Scopes shared a Scoped service');
    }

    public function testFibersInsideAScopeShareItsServices(): void
    {
        [$outer, $inner] = (new \Scope())->run(function () {
            $outer = Mini::$mini->get('test.scoped');
            $f = new \Fiber(fn () => Mini::$mini->get('test.scoped'));
            $f->start();

            return [$outer, $f->getReturn()];
        });
        $this->assertSame($outer, $inner);
    }

    public function testPlainFibersOutsideAnyScopeAreToldApart(): void
    {
        $a = new \Fiber(fn () => Mini::$mini->get('test.scoped'));
        $b = new \Fiber(fn () => Mini::$mini->get('test.scoped'));
        $a->start();
        $b->start();
        $this->assertTrue($a->getReturn() !== $b->getReturn(), 'two plain Fibers shared a Scoped service');
    }
};

exit($test->run());
