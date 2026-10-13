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
 * - Under phasync (Swerve), the phasync context of the running coroutine: each request has one.
 * - In another Fiber, the Fiber.
 * - Otherwise one object per `run()`: the process handles one request at a time (PHP-FPM, and
 *   workers such as RoadRunner and FrankenPHP), so a request's Scoped services are gone when
 *   it ends, and the next request does not inherit them. Outside `run()` it is the Mini instance.
 *
 * phasync is not a dependency: it is only asked when it is loaded and running.
 */
final class RequestScope implements RequestScopeInterface
{
    private ?object $sequential = null;

    public function current(): object
    {
        if (\class_exists(\phasync::class, false) && \phasync::isRunning() && Fiber::getCurrent() !== null) {
            return \phasync::getContext();
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
