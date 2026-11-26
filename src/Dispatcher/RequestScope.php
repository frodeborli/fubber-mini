<?php

namespace mini\Dispatcher;

use Closure;
use Fiber;
use mini\Contracts\RequestScopeInterface;
use mini\Mini;
use mini\Phase;

/**
 * The default {@see RequestScopeInterface}: adapts to the runtime it finds.
 *
 * - Where the `Scope` class exists (the PHP Scope RFC; ext-scope, or its polyfill
 *   fiber-interop/scope, which phasync and Swerve use), `Scope::getCurrent()`, unless that is the
 *   root scope: Swerve runs each request in a Scope of its own, and coroutines a request starts
 *   inherit it. The root scope is the process's, shared by every plain Fiber that runs in no
 *   Scope of its own.
 * - In another Fiber, the Fiber.
 * - Otherwise one object per `run()`: the process handles one request at a time (PHP-FPM, and
 *   workers such as RoadRunner and FrankenPHP), so a request's Scoped services are gone when
 *   it ends, and the next request does not inherit them. Outside `run()` it is the Mini instance.
 *
 * Neither Scope nor phasync is a dependency: Scope is only asked when it is loaded.
 */
final class RequestScope implements RequestScopeInterface
{
    private ?object $sequential = null;

    public function current(): object
    {
        // (the root scope is the whole process's: not a request's, so it does not count)
        if (\class_exists(\Scope::class, false) && ($scope = \Scope::getCurrent()) !== \Scope::getRootScope()) {
            return $scope;
        }
        if (null !== ($fiber = Fiber::getCurrent())) {
            return $fiber;
        }
        if (Mini::$mini->phase->getCurrentState() !== Phase::Ready) {
            throw new \LogicException(
                'Cannot get request scope outside of Ready phase. ' .
                'Current phase: ' . Mini::$mini->phase
            );
        }

        return $this->sequential ?? Mini::$mini;
    }

    public function run(Closure $fn): mixed
    {
        if (null !== $this->sequential || Fiber::getCurrent() !== null) {
            return $fn();
        }
        $this->sequential = new \stdClass();
        try {
            return $fn();
        } finally {
            $this->sequential = null;
        }
    }
}
